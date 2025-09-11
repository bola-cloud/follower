<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BatchDatabaseService;
use App\Services\DatabaseConnectionManager;
use App\Jobs\OptimizedActionBatchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateToBatchProcessing extends Command
{
    protected $signature = 'system:migrate-batch-processing {--dry-run : Show what would be changed without applying} {--batch-size=1000}';
    protected $description = 'Migrate to optimized batch processing system to reduce database connections';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');

        $this->info('🔄 Database Optimization Migration');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be applied');
            $this->newLine();
        }

        // Check current system health
        $this->checkSystemHealth();

        // Migrate pending actions to batch processing
        $this->migratePendingActions($batchSize, $dryRun);

        // Setup optimized queue workers
        $this->setupOptimizedWorkers($dryRun);

        return 0;
    }

    private function checkSystemHealth()
    {
        $this->info('📊 Current System Health:');

        $batchService = app(BatchDatabaseService::class);
        $health = $batchService->getConnectionHealth();

        $this->line("  MySQL Connections: {$health['connections']}/{$health['max_connections']}");
        $this->line("  Usage: {$health['usage_percent']}%");

        if (!$health['healthy']) {
            $this->error('  ⚠️  System is under high load!');
        } else {
            $this->info('  ✅ System health is good');
        }

        // Check pending actions count
        $pendingCount = DB::table('actions')->where('status', 'pending')->count();
        $this->line("  Pending Actions: {$pendingCount}");

        $this->newLine();
    }

    private function migratePendingActions(int $batchSize, bool $dryRun)
    {
        $this->info('🔄 Migrating Pending Actions to Batch Processing:');

        $pendingActions = DB::table('actions')
            ->where('status', 'pending')
            ->orderBy('created_at')
            ->limit($batchSize * 5) // Process up to 5 batches at once
            ->get(['id', 'order_id', 'user_id', 'type', 'status']);

        if ($pendingActions->isEmpty()) {
            $this->info('  No pending actions to migrate');
            return;
        }

        $this->line("  Found {$pendingActions->count()} pending actions");

        if (!$dryRun) {
            $chunks = $pendingActions->chunk($batchSize);
            $processed = 0;

            foreach ($chunks as $chunk) {
                $actionUpdates = $chunk->map(function ($action) {
                    return [
                        'order_id' => $action->order_id,
                        'user_id' => $action->user_id,
                        'status' => 'pending' // Keep as pending, just migrate to batch processing
                    ];
                })->toArray();

                // Dispatch to optimized batch job
                OptimizedActionBatchJob::dispatchBatch($actionUpdates);

                $processed += count($actionUpdates);
                $this->line("    Queued batch: {$processed} actions");
            }

            $this->info("  ✅ Migrated {$processed} actions to batch processing");
        } else {
            $this->line("  Would migrate {$pendingActions->count()} actions to batch queues");
        }

        $this->newLine();
    }

    private function setupOptimizedWorkers(bool $dryRun)
    {
        $this->info('⚙️  Optimized Queue Worker Setup:');

        $workerCommands = [
            'High Priority Actions' => 'nohup php artisan queue:work redis --queue=optimized-actions --tries=2 --timeout=120 --sleep=1 --memory=256 > storage/logs/queue-optimized-actions.log 2>&1 &',
            'MQTT Announcements' => 'nohup php artisan queue:work redis --queue=high --tries=2 --timeout=30 --sleep=1 --memory=128 > storage/logs/queue-high.log 2>&1 &',
            'Bulk Operations' => 'nohup php artisan queue:work redis --queue=bulk --tries=1 --timeout=300 --sleep=2 --memory=512 > storage/logs/queue-bulk.log 2>&1 &'
        ];

        foreach ($workerCommands as $name => $command) {
            $this->line("  {$name}:");
            $this->line("    {$command}");
        }

        $this->newLine();

        if (!$dryRun) {
            $this->comment('📋 Database Configuration Recommendations:');
            $this->displayDatabaseRecommendations();
        }
    }

    private function displayDatabaseRecommendations()
    {
        $recommendations = [
            'MySQL Configuration (my.cnf)' => [
                'max_connections = 200',
                'innodb_buffer_pool_size = 1G',
                'wait_timeout = 600',
                'interactive_timeout = 600',
                'innodb_lock_wait_timeout = 10'
            ],
            'Application Configuration (.env)' => [
                'DB_TIMEOUT=15',
                'QUEUE_CONNECTION=redis',
                'REDIS_QUEUE_DB=2'
            ]
        ];

        foreach ($recommendations as $category => $configs) {
            $this->line("  {$category}:");
            foreach ($configs as $config) {
                $this->line("    • {$config}");
            }
            $this->newLine();
        }

        $this->info('💡 Performance Tips:');
        $this->line('  • Start workers with Supervisor for auto-restart');
        $this->line('  • Monitor queue sizes: php artisan queue:size');
        $this->line('  • Check database connections: SHOW STATUS LIKE "Threads_connected"');
        $this->line('  • Use connection pooling for high-volume operations');

        $this->newLine();
    }
}
