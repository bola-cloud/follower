<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Jobs\InsertAndPublishForActiveDashboardUsers;

class ResumeActiveOrders extends Command
{
    protected $signature = 'orders:resume-active {--once : Dispatch once and exit} {--daemon : Run continuously (for Supervisor)}';
    protected $description = 'Dispatch coordinator job to resume/publish orders for active dashboard users';

    public function handle()
    {
        $once = $this->option('once');
        $daemon = $this->option('daemon');

        if ($daemon) {
            $this->info('[ResumeActiveOrders] Starting daemon loop (Supervisor mode)');
            $redis = app('redis')->connection();
            $lockKey = env('COORDINATOR_DAEMON_LOCK_KEY', 'coordinator:daemon:lock');
            $lockTtl = (int) env('COORDINATOR_DAEMON_LOCK_TTL', 600);
            $sleepSeconds = (int) env('COORDINATOR_DAEMON_SLEEP_SECONDS', 5);
            $busySleep = (int) env('COORDINATOR_DAEMON_BUSY_SLEEP_SECONDS', 30);
            $highWatermark = (int) env('PUBLISH_QUEUE_HIGH_WATERMARK', 100000);
            $queueKey = env('PUBLISH_QUEUE_KEY', env('MQTT_QUEUE_KEY', 'mqtt:publish'));

            $backoff = 1;
            $maxBackoff = (int) env('COORDINATOR_DAEMON_MAX_BACKOFF', 300);

            while (true) {
                try {
                    // DB health check
                    try {
                        DB::select('select 1');
                    } catch (\Throwable $e) {
                        Log::warning('[ResumeActiveOrders::daemon] database unavailable, sleeping', ['error' => $e->getMessage()]);
                        sleep(max(5, $busySleep));
                        continue;
                    }

                    // Redis health and queue length
                    try {
                        $queueLen = (int) $redis->llen($queueKey);
                    } catch (\Throwable $e) {
                        Log::warning('[ResumeActiveOrders::daemon] redis unavailable, sleeping', ['error' => $e->getMessage()]);
                        sleep(max(5, $busySleep));
                        continue;
                    }

                    if ($queueLen > $highWatermark) {
                        Log::info('[ResumeActiveOrders::daemon] publish queue above high watermark, sleeping', ['len' => $queueLen, 'high_watermark' => $highWatermark]);
                        sleep($busySleep);
                        continue;
                    }

                    // Acquire short lock
                    $token = uniqid(getmypid() . '_', true);
                    $acquired = false;
                    try {
                        if ($redis->setnx($lockKey, $token)) {
                            $redis->expire($lockKey, $lockTtl);
                            $acquired = true;
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[ResumeActiveOrders::daemon] redis lock error', ['error' => $e->getMessage()]);
                        sleep($sleepSeconds);
                        continue;
                    }

                    if (! $acquired) {
                        Log::info('[ResumeActiveOrders::daemon] coordinator locked by another process, sleeping', ['lock_key' => $lockKey]);
                        sleep($sleepSeconds);
                        continue;
                    }

                    // Run the coordinator synchronously
                    try {
                        $this->info('[ResumeActiveOrders::daemon] invoking coordinator');
                        $job = new InsertAndPublishForActiveDashboardUsers();
                        $job->handle();
                        $backoff = 1;
                    } catch (\Throwable $e) {
                        Log::error('[ResumeActiveOrders::daemon] coordinator run failed', ['error' => $e->getMessage()]);
                        sleep(min($maxBackoff, $backoff));
                        $backoff = min($maxBackoff, $backoff * 2);
                    } finally {
                        // release lock if ours
                        try {
                            $current = $redis->get($lockKey);
                            if ($current === $token) {
                                $redis->del($lockKey);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('[ResumeActiveOrders::daemon] failed to release lock', ['error' => $e->getMessage()]);
                        }
                    }

                    sleep($sleepSeconds);
                } catch (\Throwable $outer) {
                    Log::error('[ResumeActiveOrders::daemon] unexpected error', ['error' => $outer->getMessage()]);
                    sleep(5);
                }
            }
        }

        // Default single-run behavior (backwards compatible)
        $this->info('[ResumeActiveOrders] Running InsertAndPublishForActiveDashboardUsers synchronously...');
        $job = new InsertAndPublishForActiveDashboardUsers();
        $job->handle();
        $this->info('[ResumeActiveOrders] Job completed.');
        return 0;
    }
}
