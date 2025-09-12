<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class EmergencyHealthCheck extends Command
{
    protected $signature = 'system:emergency-health-check
                            {--connections : Show MySQL connection details}
                            {--queues : Show detailed queue information}
                            {--failed : Show recent failed jobs}
                            {--mqtt : Check MQTT-related issues}';

    protected $description = 'Emergency health check after critical fixes';

    public function handle()
    {
        $this->info('🏥 EMERGENCY SYSTEM HEALTH CHECK');
        $this->info('================================');

        $this->checkDatabaseHealth();
        $this->checkQueueHealth();
        $this->checkFailedJobs();

        if ($this->option('connections')) {
            $this->showConnectionDetails();
        }

        if ($this->option('queues')) {
            $this->showQueueDetails();
        }

        if ($this->option('failed')) {
            $this->showFailedJobDetails();
        }

        if ($this->option('mqtt')) {
            $this->checkMqttIssues();
        }

        $this->info('');
        $this->info('✅ Health check completed');
    }

    private function checkDatabaseHealth()
    {
        $this->info('');
        $this->info('🔍 DATABASE HEALTH');
        $this->info('------------------');

        try {
            // Check basic connectivity
            $startTime = microtime(true);
            DB::select('SELECT 1');
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("✅ Database connectivity: OK ({$responseTime}ms)");

            // Check active connections
            $connections = DB::select('SHOW PROCESSLIST');
            $activeConnections = count($connections);

            if ($activeConnections > 200) {
                $this->error("⚠️  High connection count: {$activeConnections}");
            } else {
                $this->info("✅ Active connections: {$activeConnections}");
            }

            // Check for sleeping connections
            $sleepingConnections = collect($connections)->where('Command', 'Sleep')->count();
            $this->info("💤 Sleeping connections: {$sleepingConnections}");

            // Check failed_jobs table
            $failedJobsCount = DB::table('failed_jobs')->count();
            if ($failedJobsCount > 0) {
                $this->warn("⚠️  Failed jobs in database: {$failedJobsCount}");
            } else {
                $this->info("✅ No failed jobs in database");
            }

        } catch (\Throwable $e) {
            $this->error("❌ Database health check failed: " . $e->getMessage());
        }
    }

    private function checkQueueHealth()
    {
        $this->info('');
        $this->info('🔍 QUEUE HEALTH');
        $this->info('---------------');

        try {
            // Check Redis connectivity
            $redisConnected = Redis::ping();
            if ($redisConnected) {
                $this->info("✅ Redis connectivity: OK");
            } else {
                $this->error("❌ Redis connectivity: FAILED");
                return;
            }

            // Check MQTT actions queue
            $mqttQueueLength = Redis::llen('mqtt_actions_queue');
            if ($mqttQueueLength > 1000) {
                $this->warn("⚠️  High MQTT actions queue: {$mqttQueueLength}");
            } else {
                $this->info("✅ MQTT actions queue: {$mqttQueueLength}");
            }

            // Check Laravel queues
            $queues = ['high', 'optimized-actions', 'actions', 'bulk', 'default'];
            foreach ($queues as $queue) {
                try {
                    $queueSize = Redis::llen("queues:{$queue}");
                    if ($queueSize > 100) {
                        $this->warn("⚠️  Queue '{$queue}': {$queueSize} jobs");
                    } else {
                        $this->info("✅ Queue '{$queue}': {$queueSize} jobs");
                    }
                } catch (\Exception $e) {
                    $this->warn("⚠️  Could not check queue '{$queue}': " . $e->getMessage());
                }
            }

        } catch (\Throwable $e) {
            $this->error("❌ Queue health check failed: " . $e->getMessage());
        }
    }

    private function checkFailedJobs()
    {
        $this->info('');
        $this->info('🔍 FAILED JOBS CHECK');
        $this->info('--------------------');

        try {
            $recentFailures = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHour())
                ->orderBy('failed_at', 'desc')
                ->limit(5)
                ->get(['connection', 'queue', 'failed_at', 'exception']);

            if ($recentFailures->isEmpty()) {
                $this->info("✅ No recent failed jobs (last hour)");
            } else {
                $this->warn("⚠️  Recent failures found:");
                foreach ($recentFailures as $failure) {
                    $this->line("   - {$failure->queue} queue at {$failure->failed_at}");
                }
            }

        } catch (\Throwable $e) {
            $this->error("❌ Failed jobs check failed: " . $e->getMessage());
        }
    }

    private function showConnectionDetails()
    {
        $this->info('');
        $this->info('🔍 DETAILED CONNECTION ANALYSIS');
        $this->info('-------------------------------');

        try {
            $connections = DB::select('SHOW PROCESSLIST');
            $analysis = collect($connections)->groupBy('Command')->map(function ($group) {
                return $group->count();
            })->sortDesc();

            foreach ($analysis as $command => $count) {
                $this->line("   {$command}: {$count}");
            }

            // Show long-running queries
            $longRunning = collect($connections)->where('Time', '>', 30);
            if ($longRunning->count() > 0) {
                $this->warn("⚠️  Long-running queries (>30s): " . $longRunning->count());
            }

        } catch (\Throwable $e) {
            $this->error("❌ Connection details failed: " . $e->getMessage());
        }
    }

    private function showQueueDetails()
    {
        $this->info('');
        $this->info('🔍 DETAILED QUEUE ANALYSIS');
        $this->info('--------------------------');

        try {
            // Show Redis memory usage
            $memoryInfo = Redis::info('memory');
            $this->info("Redis memory used: " . $memoryInfo['used_memory_human']);

            // Show job processing rate
            $redisStats = Redis::info('stats');
            $this->info("Total connections received: " . $redisStats['total_connections_received']);

        } catch (\Throwable $e) {
            $this->error("❌ Queue details failed: " . $e->getMessage());
        }
    }

    private function showFailedJobDetails()
    {
        $this->info('');
        $this->info('🔍 DETAILED FAILED JOBS ANALYSIS');
        $this->info('--------------------------------');

        try {
            $failures = DB::table('failed_jobs')
                ->select(DB::raw('DATE(failed_at) as date, COUNT(*) as count'))
                ->where('failed_at', '>=', now()->subDays(7))
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get();

            if ($failures->isEmpty()) {
                $this->info("✅ No failed jobs in the last 7 days");
            } else {
                $this->info("Failed jobs by day:");
                foreach ($failures as $failure) {
                    $this->line("   {$failure->date}: {$failure->count} failures");
                }
            }

        } catch (\Throwable $e) {
            $this->error("❌ Failed job details failed: " . $e->getMessage());
        }
    }

    private function checkMqttIssues()
    {
        $this->info('');
        $this->info('🔍 MQTT HEALTH CHECK');
        $this->info('--------------------');

        try {
            // Check if MQTT publisher timeouts are logged
            $logFile = storage_path('logs/laravel.log');
            if (file_exists($logFile)) {
                $timeoutCount = 0;
                $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach (array_slice($lines, -1000) as $line) {
                    if (strpos($line, 'Failed to publish order announcement') !== false) {
                        $timeoutCount++;
                    }
                }

                if ($timeoutCount > 10) {
                    $this->error("❌ High MQTT timeout count: {$timeoutCount} in last 1000 log lines");
                } else {
                    $this->info("✅ MQTT timeout count: {$timeoutCount} in last 1000 log lines");
                }
            }

        } catch (\Throwable $e) {
            $this->error("❌ MQTT check failed: " . $e->getMessage());
        }
    }
}
