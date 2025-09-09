<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use App\Services\BulkActionService;

class MonitorPerformance extends Command
{
    protected $signature = 'monitor:performance
                           {--interval=5 : Monitoring interval in seconds}
                           {--duration=60 : Total monitoring duration in seconds}';

    protected $description = 'Monitor MQTT processing performance in real-time';

    public function handle()
    {
        $interval = $this->option('interval');
        $duration = $this->option('duration');
        $iterations = $duration / $interval;

        $this->info("🔍 Starting performance monitoring for {$duration} seconds...");

        $previousStats = $this->getCurrentStats();

        for ($i = 0; $i < $iterations; $i++) {
            sleep($interval);

            $currentStats = $this->getCurrentStats();
            $this->displayStats($currentStats, $previousStats, $interval);

            $previousStats = $currentStats;
        }

        $this->info("✅ Monitoring completed");
    }

    private function getCurrentStats()
    {
        return [
            'timestamp' => now(),
            'actions_total' => DB::table('actions')->count(),
            'actions_done' => DB::table('actions')->where('status', 'done')->count(),
            'actions_pending' => DB::table('actions')->where('status', 'pending')->count(),
            'orders_active' => DB::table('orders')->where('status', 'active')->count(),
            'orders_completed' => DB::table('orders')->where('status', 'completed')->count(),
            'queue_size' => Redis::llen('mqtt_actions_queue') ?? 0,
            'queue_size_prefixed' => Redis::llen('laravel_database_mqtt_actions_queue') ?? 0,
            'bulk_pending' => app(BulkActionService::class)->getPendingCount(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg()[0] ?? 0,
            'processor_running' => Cache::has('high_performance_processor_running'),
            'action_queue_running' => Cache::has('action_queue_job_running')
        ];
    }

    private function displayStats($current, $previous, $interval)
    {
        $actionsDelta = $current['actions_total'] - $previous['actions_total'];
        $doneDelta = $current['actions_done'] - $previous['actions_done'];
        $actionsPerSecond = round($actionsDelta / $interval, 2);
        $donePerSecond = round($doneDelta / $interval, 2);

        $this->line("\n" . str_repeat("=", 80));
        $this->info("📊 Performance Stats - " . $current['timestamp']->format('H:i:s'));
        $this->line(str_repeat("=", 80));

        // Throughput metrics
        $this->table(
            ['Metric', 'Current', 'Change', 'Per Second'],
            [
                ['Total Actions', number_format($current['actions_total']), "+{$actionsDelta}", $actionsPerSecond],
                ['Completed Actions', number_format($current['actions_done']), "+{$doneDelta}", $donePerSecond],
                ['Pending Actions', number_format($current['actions_pending']), '', ''],
                ['Active Orders', number_format($current['orders_active']), '', ''],
                ['Completed Orders', number_format($current['orders_completed']), '', '']
            ]
        );

        // Queue status
        $this->info("\n🔄 Queue Status:");
        $this->table(
            ['Queue Type', 'Size', 'Status'],
            [
                ['Redis Queue (raw)', $current['queue_size'], ''],
                ['Redis Queue (prefixed)', $current['queue_size_prefixed'], ''],
                ['Bulk Service Buffer', $current['bulk_pending'], ''],
                ['High Performance Processor', '', $current['processor_running'] ? '✅ Running' : '❌ Stopped'],
                ['Action Queue Job', '', $current['action_queue_running'] ? '✅ Running' : '❌ Stopped']
            ]
        );

        // System metrics
        $memoryMB = round($current['memory_usage'] / 1024 / 1024, 2);
        $peakMemoryMB = round($current['peak_memory'] / 1024 / 1024, 2);

        $this->info("\n💻 System Resources:");
        $this->table(
            ['Resource', 'Value', 'Status'],
            [
                ['Memory Usage', "{$memoryMB} MB", $memoryMB > 1000 ? '⚠️ High' : '✅ OK'],
                ['Peak Memory', "{$peakMemoryMB} MB", ''],
                ['Load Average', round($current['load_average'], 2), $current['load_average'] > 3 ? '⚠️ High' : '✅ OK']
            ]
        );

        // Performance assessment
        if ($actionsPerSecond > 0) {
            if ($actionsPerSecond >= 100) {
                $this->info("🚀 Excellent throughput: {$actionsPerSecond} actions/second");
            } elseif ($actionsPerSecond >= 50) {
                $this->info("✅ Good throughput: {$actionsPerSecond} actions/second");
            } elseif ($actionsPerSecond >= 20) {
                $this->warn("⚠️ Moderate throughput: {$actionsPerSecond} actions/second");
            } else {
                $this->error("❌ Low throughput: {$actionsPerSecond} actions/second");
            }
        }
    }
}
