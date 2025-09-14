<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\SystemHealthService;
use App\Jobs\OptimizedActionBatchJob;

class ScaleWorkersCommand extends Command
{
    protected $signature = 'queue:scale-workers {--dry-run : Show what would be done without executing}';
    protected $description = 'Automatically scale queue workers based on system load';

    public function __construct(
        private SystemHealthService $healthService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info('🔍 Checking system health and queue load...');

        $health = $this->healthService->getHealthStatus();
        $queueSize = $health['components']['queues']['total_queue_size'] ?? 0;
        $dbHealth = $health['components']['database']['status'] ?? 'unknown';

        $this->table(['Component', 'Status', 'Details'], [
            ['Database', $dbHealth, $health['components']['database']['connection_percent'] . '% connections used'],
            ['Queues', $health['components']['queues']['status'], $queueSize . ' total jobs'],
            ['Redis', $health['components']['redis']['status'], $health['components']['redis']['memory_used_mb'] . 'MB used'],
            ['Overall', $health['overall_status'], '']
        ]);

        if ($health['overall_status'] === 'critical') {
            $this->error('❌ System is in critical state. Cannot scale workers safely.');
            return 1;
        }

        $recommendations = $this->getScalingRecommendations($health, $queueSize);

        if (empty($recommendations['actions'])) {
            $this->info('✅ No scaling actions needed. System is running optimally.');
            return 0;
        }

        $this->info('📋 Recommended actions:');
        foreach ($recommendations['actions'] as $action) {
            $this->line("  • {$action['description']}");
        }

        if ($isDryRun) {
            $this->warn('🔍 Dry run mode - no actions will be executed');
            return 0;
        }

        if (!$this->confirm('Execute these scaling actions?')) {
            $this->info('❌ Scaling cancelled by user');
            return 0;
        }

        $this->executeScalingActions($recommendations['actions']);

        return 0;
    }

    private function getScalingRecommendations(array $health, int $queueSize): array
    {
        $actions = [];

        $dbPercent = $health['components']['database']['connection_percent'] ?? 0;
        $failedJobs = $health['components']['queues']['failed_jobs'] ?? 0;

        // High queue backlog
        if ($queueSize > 2000) {
            $actions[] = [
                'type' => 'scale_up',
                'description' => "High queue backlog ({$queueSize} jobs) - recommend starting 3-5 additional workers",
                'command' => 'start_workers',
                'count' => 5
            ];
        } elseif ($queueSize > 500) {
            $actions[] = [
                'type' => 'scale_up',
                'description' => "Moderate queue backlog ({$queueSize} jobs) - recommend starting 2-3 additional workers",
                'command' => 'start_workers',
                'count' => 3
            ];
        }

        // Database under pressure
        if ($dbPercent > 85) {
            $actions[] = [
                'type' => 'throttle',
                'description' => "Database connections high ({$dbPercent}%) - recommend reducing worker concurrency",
                'command' => 'reduce_concurrency'
            ];
        }

        // Failed jobs accumulation
        if ($failedJobs > 20) {
            $actions[] = [
                'type' => 'cleanup',
                'description' => "High failed jobs count ({$failedJobs}) - recommend reviewing and clearing failed jobs",
                'command' => 'cleanup_failed_jobs'
            ];
        }

        // Dispatch immediate batch processing if queues are full
        if ($queueSize > 1000) {
            $actions[] = [
                'type' => 'immediate_batch',
                'description' => "Dispatch immediate batch processing for queue drainage",
                'command' => 'dispatch_batch'
            ];
        }

        return [
            'actions' => $actions,
            'system_health' => $health['overall_status'],
            'queue_size' => $queueSize,
            'db_pressure' => $dbPercent
        ];
    }

    private function executeScalingActions(array $actions): void
    {
        foreach ($actions as $action) {
            $this->info("🚀 Executing: {$action['description']}");

            try {
                switch ($action['command']) {
                    case 'start_workers':
                        $this->startWorkers($action['count'] ?? 2);
                        break;

                    case 'reduce_concurrency':
                        $this->reduceConcurrency();
                        break;

                    case 'cleanup_failed_jobs':
                        $this->cleanupFailedJobs();
                        break;

                    case 'dispatch_batch':
                        $this->dispatchImmediateBatch();
                        break;

                    default:
                        $this->warn("⚠️ Unknown action: {$action['command']}");
                }

                $this->info("✅ Completed: {$action['description']}");

            } catch (\Exception $e) {
                $this->error("❌ Failed: {$action['description']} - {$e->getMessage()}");
                Log::error('Worker scaling action failed', [
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function startWorkers(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            // In production, you might use supervisor or systemd to start workers
            // For now, we'll dispatch additional batch jobs
            OptimizedActionBatchJob::dispatch()
                ->onQueue('high-priority')
                ->delay(now()->addSeconds($i * 2));
        }

        $this->line("  Started {$count} additional batch processing jobs");
    }

    private function reduceConcurrency(): void
    {
        // This would typically involve adjusting supervisor configuration
        // For now, we'll log the recommendation
        Log::info('Recommendation: Reduce worker concurrency due to high DB load');
        $this->line("  Logged recommendation to reduce worker concurrency");
    }

    private function cleanupFailedJobs(): void
    {
        $cleaned = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('failed_at', '<', now()->subHours(24))
            ->delete();

        $this->line("  Cleaned up {$cleaned} old failed jobs");
    }

    private function dispatchImmediateBatch(): void
    {
        OptimizedActionBatchJob::dispatch()->onQueue('high-priority');
        $this->line("  Dispatched immediate batch processing job");
    }
}
