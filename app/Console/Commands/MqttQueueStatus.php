<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MqttQueueStatus extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mqtt:status {--show-items : Show sample queued items} {--items=10 : Number of queued items to show} {--log-lines=200 : Number of recent log lines to search}';

    /**
     * The console command description.
     */
    protected $description = 'Show status of MQTT action queue and related job state';

    public function handle()
    {
        $this->info('MQTT queue status summary');

        // Determine Redis key with prefix if any
        $prefix = config('database.redis.options.prefix') ?? '';
        $redisKey = $prefix . 'mqtt_actions_queue';

        try {
            $llen = Redis::llen($redisKey);
        } catch (\Exception $e) {
            $this->error('Failed to read Redis list: ' . $e->getMessage());
            return 1;
        }

        $this->line("Redis key: {$redisKey}");
        $this->line("Queue length (LLEN): {$llen}");

        $lockKey = (config('database.redis.options.prefix') ?? '') . 'action_queue_job_running';
        $lockExists = Cache::has('action_queue_job_running') || Redis::exists($lockKey);
        $this->line('ActionQueueJob lock present: ' . ($lockExists ? 'yes' : 'no'));

        // Show a small sample of queue items if requested
        if ($this->option('show-items') && $llen > 0) {
            $count = (int) $this->option('items');
            $end = $count - 1;
            $items = Redis::lrange($redisKey, 0, $end);
            $this->line("\nSample queued items (first {$count}):");
            foreach ($items as $i => $raw) {
                $decoded = @json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->line(sprintf("%d. order_id=%s user_id=%s status=%s", $i + 1, $decoded['order_id'] ?? 'N/A', $decoded['user_id'] ?? 'N/A', $decoded['status'] ?? 'N/A'));
                } else {
                    $this->line(($i + 1) . '. (invalid JSON) ' . substr($raw, 0, 200));
                }
            }
            $this->line('');
        }

        // Show failed jobs table summary if present
        if (Schema::hasTable('failed_jobs')) {
            try {
                $failedCount = DB::table('failed_jobs')->count();
                $this->line("Failed jobs count: {$failedCount}");
                $latest = DB::table('failed_jobs')->orderBy('id', 'desc')->limit(5)->get();
                if ($latest->isNotEmpty()) {
                    $this->line('\nRecent failed jobs:');
                    foreach ($latest as $f) {
                        $this->line('- id:' . $f->id . ' connection:' . ($f->connection ?? 'n/a') . ' queue:' . ($f->queue ?? 'n/a') . ' failed_at:' . ($f->failed_at ?? 'n/a'));
                        $this->line('  exception: ' . (isset($f->exception) ? substr($f->exception, 0, 200) : 'n/a'));
                    }
                }
            } catch (\Exception $e) {
                $this->error('Failed to query failed_jobs table: ' . $e->getMessage());
            }
        } else {
            $this->line('No failed_jobs table detected.');
        }

        // Read recent log lines and filter for MQTT or ActionQueueJob
        $logLines = (int) $this->option('log-lines');
        $logPath = base_path('storage/logs/laravel.log');

        if (file_exists($logPath)) {
            $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                $this->error('Unable to read laravel.log');
                return 1;
            }

            $tail = array_slice($lines, -$logLines);
            $matches = array_filter($tail, function ($l) {
                return stripos($l, 'MQTT_API') !== false || stripos($l, 'ActionQueueJob') !== false || stripos($l, 'mqtt') !== false;
            });

            $this->line('\nRecent log lines mentioning MQTT / ActionQueueJob:');
            if (empty($matches)) {
                $this->line('(no recent MQTT logs found in last ' . $logLines . ' lines)');
            } else {
                foreach (array_slice($matches, -20) as $m) { // Show last 20 matches
                    $this->line($m);
                }
            }
        } else {
            $this->line('Log file not found at ' . $logPath);
        }

        $this->line('\nHelpful next steps:');
        $this->line(' - Run `php artisan queue:work --queue=default --once` to process a single batch and re-run this command to see changes.');
        $this->line(' - Check supervisor / systemd for active queue workers on the server (e.g. `sudo supervisorctl status`).');
        $this->line(' - Use `php artisan mqtt:status --show-items --items=20` to inspect queue items.');

        return 0;
    }
}
