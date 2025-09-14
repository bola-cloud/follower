<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\SystemHealthService;
use App\Services\HighVolumeProcessingService;
use App\Jobs\OptimizedActionBatchJob;

class PrepareHighVolumeCommand extends Command
{
    protected $signature = 'system:prepare-high-volume
                            {--workers=3 : Number of workers to start}
                            {--test-capacity=1000 : Test capacity to prepare for}
                            {--dry-run : Show what would be done without executing}';

    protected $description = 'Prepare the system for high-volume processing (500-1000 users per order)';

    public function handle()
    {
        $workers = (int) $this->option('workers');
        $testCapacity = (int) $this->option('test-capacity');
        $isDryRun = $this->option('dry-run');

        $this->info('🚀 Preparing system for high-volume processing...');
        $this->info("Target capacity: {$testCapacity} concurrent users");

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        }

        // Step 1: System Health Check
        $this->info('📊 Step 1: System Health Check');
        $healthService = app(SystemHealthService::class);
        $health = $healthService->getHealthStatus();

        $this->displayHealthStatus($health);

        if ($health['overall_status'] === 'critical') {
            $this->error('❌ System is in critical state. Please resolve issues before proceeding.');
            return 1;
        }

        // Step 2: Database Optimization
        $this->info('🗄️  Step 2: Database Optimization Check');
        $this->checkDatabaseOptimizations($isDryRun);

        // Step 3: Queue System Preparation
        $this->info('📋 Step 3: Queue System Preparation');
        $this->prepareQueueSystem($isDryRun);

        // Step 4: Redis Optimization
        $this->info('🔧 Step 4: Redis Configuration Check');
        $this->checkRedisConfiguration($isDryRun);

        // Step 5: Worker Management
        $this->info('👥 Step 5: Worker Preparation');
        $this->prepareWorkers($workers, $isDryRun);

        // Step 6: Test Configuration
        $this->info('🧪 Step 6: Test Environment Setup');
        $this->configureForTesting($testCapacity, $isDryRun);

        // Step 7: Final Verification
        $this->info('✅ Step 7: Final System Verification');
        $finalHealth = $healthService->getHealthStatus();
        $this->displayHealthStatus($finalHealth);

        if ($finalHealth['overall_status'] === 'healthy') {
            $this->info('🎉 System is ready for high-volume processing!');
            $this->displayReadinessReport($finalHealth, $testCapacity);
        } else {
            $this->warn('⚠️ System preparation completed with warnings. Monitor closely during testing.');
        }

        return 0;
    }

    private function displayHealthStatus(array $health): void
    {
        $statusEmoji = match($health['overall_status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '❌',
            default => '❓'
        };

        $this->line("  Overall Status: {$statusEmoji} {$health['overall_status']}");

        $components = [
            ['Component', 'Status', 'Details']
        ];

        foreach ($health['components'] as $name => $component) {
            $emoji = match($component['status']) {
                'healthy' => '✅',
                'warning' => '⚠️',
                'critical' => '❌',
                default => '❓'
            };

            $details = '';
            switch ($name) {
                case 'database':
                    $details = ($component['connection_percent'] ?? 0) . '% connections';
                    break;
                case 'queues':
                    $details = ($component['total_queue_size'] ?? 0) . ' queued jobs';
                    break;
                case 'redis':
                    $details = ($component['memory_used_mb'] ?? 0) . 'MB memory';
                    break;
                case 'workers':
                    $details = ($component['recent_jobs_processed'] ?? 0) . ' recent jobs';
                    break;
                case 'disk_space':
                    $details = ($component['used_percent'] ?? 0) . '% used';
                    break;
            }

            $components[] = [
                $name,
                "{$emoji} {$component['status']}",
                $details
            ];
        }

        $this->table($components[0], array_slice($components, 1));
    }

    private function checkDatabaseOptimizations(bool $isDryRun): void
    {
        try {
            // Check for required indexes
            $indexChecks = [
                'actions' => ['order_id', 'user_id', 'status'],
                'orders' => ['id', 'status'],
                'users' => ['id', 'type']
            ];

            foreach ($indexChecks as $table => $columns) {
                $this->checkTableIndexes($table, $columns, $isDryRun);
            }

            // Check database configuration
            $this->checkDatabaseConfig($isDryRun);

        } catch (\Exception $e) {
            $this->error("Database check failed: {$e->getMessage()}");
        }
    }

    private function checkTableIndexes(string $table, array $columns, bool $isDryRun): void
    {
        try {
            $indexes = collect(DB::select("SHOW INDEX FROM {$table}"))
                ->groupBy('Key_name')
                ->keys()
                ->toArray();

            foreach ($columns as $column) {
                $indexExists = collect($indexes)->contains(function ($index) use ($column) {
                    return str_contains(strtolower($index), strtolower($column));
                });

                if (!$indexExists) {
                    $this->warn("  ⚠️ Missing index on {$table}.{$column}");
                    if (!$isDryRun) {
                        $this->line("  Creating index...");
                        DB::statement("CREATE INDEX idx_{$table}_{$column} ON {$table} ({$column})");
                        $this->info("  ✅ Created index on {$table}.{$column}");
                    }
                } else {
                    $this->line("  ✅ Index exists on {$table}.{$column}");
                }
            }
        } catch (\Exception $e) {
            $this->warn("  Could not check indexes for {$table}: {$e->getMessage()}");
        }
    }

    private function checkDatabaseConfig(bool $isDryRun): void
    {
        try {
            $configs = [
                'max_connections' => 200,
                'innodb_buffer_pool_size' => '128M',
                'innodb_lock_wait_timeout' => 50,
            ];

            foreach ($configs as $config => $recommended) {
                $result = DB::select("SHOW VARIABLES LIKE '{$config}'");
                if (!empty($result)) {
                    $current = $result[0]->Value;
                    $this->line("  {$config}: {$current} (recommended: {$recommended})");
                }
            }
        } catch (\Exception $e) {
            $this->warn("  Could not check database configuration: {$e->getMessage()}");
        }
    }

    private function prepareQueueSystem(bool $isDryRun): void
    {
        try {
            // Clear old failed jobs
            $failedJobs = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(1))->count();
            if ($failedJobs > 0) {
                if (!$isDryRun) {
                    $deleted = DB::table('failed_jobs')->where('failed_at', '<', now()->subDays(1))->delete();
                    $this->info("  ✅ Cleaned up {$deleted} old failed jobs");
                } else {
                    $this->line("  Would clean up {$failedJobs} old failed jobs");
                }
            } else {
                $this->line("  ✅ No old failed jobs to clean");
            }

            // Check queue lengths
            $queues = [
                'high_volume_actions_queue',
                'mqtt_actions_queue',
                'queues:default',
                'queues:high-priority',
                'queues:optimized-actions'
            ];

            foreach ($queues as $queue) {
                try {
                    $length = Redis::llen($queue);
                    if ($length > 0) {
                        $this->line("  📋 {$queue}: {$length} jobs");
                    } else {
                        $this->line("  ✅ {$queue}: empty");
                    }
                } catch (\Exception $e) {
                    $this->warn("  ❓ Could not check {$queue}: {$e->getMessage()}");
                }
            }

        } catch (\Exception $e) {
            $this->error("Queue system check failed: {$e->getMessage()}");
        }
    }

    private function checkRedisConfiguration(bool $isDryRun): void
    {
        try {
            $info = Redis::info('memory');
            $memoryMB = round(($info['used_memory'] ?? 0) / 1024 / 1024, 2);
            $maxMemoryMB = isset($info['maxmemory']) && $info['maxmemory'] > 0 ?
                          round($info['maxmemory'] / 1024 / 1024, 2) : 'unlimited';

            $this->line("  📊 Memory usage: {$memoryMB}MB (max: {$maxMemoryMB})");

            // Test Redis performance
            $start = microtime(true);
            Redis::ping();
            $pingTime = round((microtime(true) - $start) * 1000, 2);

            if ($pingTime > 10) {
                $this->warn("  ⚠️ Redis ping time is high: {$pingTime}ms");
            } else {
                $this->line("  ✅ Redis ping time: {$pingTime}ms");
            }

        } catch (\Exception $e) {
            $this->error("Redis check failed: {$e->getMessage()}");
        }
    }

    private function prepareWorkers(int $workerCount, bool $isDryRun): void
    {
        if (!$isDryRun) {
            // Dispatch initial batch processing jobs
            for ($i = 0; $i < $workerCount; $i++) {
                OptimizedActionBatchJob::dispatch()
                    ->onQueue('high-priority')
                    ->delay(now()->addSeconds($i));
            }
            $this->info("  ✅ Dispatched {$workerCount} batch processing jobs");
        } else {
            $this->line("  Would dispatch {$workerCount} batch processing jobs");
        }

        // Show recommendations for persistent workers
        $this->line('  📝 For production, consider running persistent workers:');
        $this->line('    php artisan queue:work --queue=high-priority,optimized-actions,default --sleep=3 --tries=3');
    }

    private function configureForTesting(int $capacity, bool $isDryRun): void
    {
        $recommendedSettings = [
            'SIM_BATCH_SIZE' => min(200, $capacity / 5),
            'SIM_RATE_LIMIT' => min(500, $capacity / 2),
            'SIM_BATCH_DELAY_MS' => max(1000, 5000 - ($capacity / 200)),
            'SIM_DB_RECHECK_ATTEMPTS' => 5,
            'HIGH_VOLUME_PROCESSING_ENABLED' => 'true',
        ];

        $this->line('  📝 Recommended environment settings for testing:');
        foreach ($recommendedSettings as $key => $value) {
            $this->line("    {$key}={$value}");
        }

        if (!$isDryRun) {
            // Set configuration values in cache for runtime use
            foreach ($recommendedSettings as $key => $value) {
                cache()->put("test_config_{$key}", $value, 3600);
            }
            $this->info('  ✅ Test configuration cached for 1 hour');
        }
    }

    private function displayReadinessReport(array $health, int $capacity): void
    {
        $this->info('📊 System Readiness Report:');
        $this->line('');

        $dbConnections = $health['components']['database']['connections'] ?? 0;
        $maxConnections = $health['components']['database']['max_connections'] ?? 0;
        $availableConnections = max(0, $maxConnections - $dbConnections);

        $estimatedCapacity = min(
            $availableConnections * 10, // Estimate 10 users per connection
            2000 // Cap at 2000 for safety
        );

        $readyFor = min($capacity, $estimatedCapacity);

        $this->table(['Metric', 'Current', 'Estimated Capacity'], [
            ['Database Connections', "{$dbConnections}/{$maxConnections}", "{$availableConnections} available"],
            ['Queue Processing', 'Ready', 'Optimized batching enabled'],
            ['Redis Memory', $health['components']['redis']['memory_used_mb'] . 'MB', 'Sufficient for caching'],
            ['Estimated Capacity', $readyFor . ' users', $readyFor >= $capacity ? '✅ Ready' : '⚠️ Limited']
        ]);

        if ($readyFor < $capacity) {
            $this->warn("⚠️ System may handle up to {$readyFor} users reliably.");
            $this->warn("Consider starting with smaller tests and scaling up gradually.");
        }

        $this->line('');
        $this->info('🚀 Ready to run:');
        $this->line('  php artisan simulate:order --count=' . min($capacity, $readyFor));
        $this->line('');
        $this->info('📊 Monitor with:');
        $this->line('  curl -s http://localhost/api/health/system | jq');
        $this->line('  php artisan queue:scale-workers --dry-run');
    }
}
