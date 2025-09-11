<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Services\BatchDatabaseService;

class SystemHealthCheck extends Command
{
    protected $signature = 'system:health-check
                            {--batch-processing : Check batch processing system health}
                            {--database-connections : Check database connection health}
                            {--queue-performance : Check queue performance metrics}
                            {--full : Run all health checks}';

    protected $description = 'Comprehensive system health check for optimized batch processing';

    public function handle()
    {
        $this->info('🔍 System Health Check - ' . now()->format('Y-m-d H:i:s'));
        $this->newLine();

        if ($this->option('full')) {
            $this->checkBatchProcessing();
            $this->checkDatabaseConnections();
            $this->checkQueuePerformance();
        } else {
            if ($this->option('batch-processing')) {
                $this->checkBatchProcessing();
            }

            if ($this->option('database-connections')) {
                $this->checkDatabaseConnections();
            }

            if ($this->option('queue-performance')) {
                $this->checkQueuePerformance();
            }
        }

        if (!$this->option('batch-processing') && !$this->option('database-connections') &&
            !$this->option('queue-performance') && !$this->option('full')) {
            $this->error('Please specify a check type: --batch-processing, --database-connections, --queue-performance, or --full');
        }
    }

    private function checkBatchProcessing()
    {
        $this->info('📊 Batch Processing Health Check');
        $this->line('═══════════════════════════════════════');

        try {
            $batchService = app(BatchDatabaseService::class);
            $connectionHealth = $batchService->getConnectionHealth();

            // Check batch service availability
            $this->table(['Metric', 'Value', 'Status'], [
                ['Service Available', 'BatchDatabaseService', '✅ Active'],
                ['Connection Health', $connectionHealth['healthy'] ? 'Healthy' : 'Unhealthy', $connectionHealth['healthy'] ? '✅ Good' : '❌ Issue'],
                ['Active Connections', $connectionHealth['connections'] ?? 'N/A', $this->getConnectionStatus($connectionHealth['connections'] ?? 0)],
                ['Max Connections', $connectionHealth['max_connections'] ?? 'N/A', 'ℹ️ Info'],
                ['Usage Percentage', ($connectionHealth['usage_percent'] ?? 0) . '%', $this->getUsageStatus($connectionHealth['usage_percent'] ?? 0)],
            ]);

            // Check recent batch operations
            $recentBatches = DB::table('actions')
                ->select(DB::raw('COUNT(*) as total, status'))
                ->where('created_at', '>=', now()->subMinutes(30))
                ->groupBy('status')
                ->get();

            $this->info('Recent Batch Operations (Last 30 minutes):');
            foreach ($recentBatches as $batch) {
                $this->line("  {$batch->status}: {$batch->total} actions");
            }

        } catch (\Exception $e) {
            $this->error('❌ Batch Processing Check Failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function checkDatabaseConnections()
    {
        $this->info('🗄️ Database Connection Health Check');
        $this->line('═══════════════════════════════════════');

        try {
            // Get connection statistics
            $connectionStats = DB::select("SHOW STATUS LIKE '%connection%'");
            $processlist = DB::select("SELECT * FROM INFORMATION_SCHEMA.PROCESSLIST WHERE COMMAND != 'Sleep'");

            $stats = [];
            foreach ($connectionStats as $stat) {
                $stats[$stat->Variable_name] = $stat->Value;
            }

            $this->table(['Connection Metric', 'Current Value', 'Status'], [
                ['Threads Connected', $stats['Threads_connected'] ?? 'N/A', $this->getConnectionStatus($stats['Threads_connected'] ?? 0)],
                ['Connections', $stats['Connections'] ?? 'N/A', 'ℹ️ Total Since Start'],
                ['Max Used Connections', $stats['Max_used_connections'] ?? 'N/A', 'ℹ️ Peak Usage'],
                ['Active Queries', count($processlist), count($processlist) > 50 ? '⚠️ High' : '✅ Normal'],
                ['Aborted Connections', $stats['Aborted_connects'] ?? 'N/A', ($stats['Aborted_connects'] ?? 0) > 100 ? '⚠️ High' : '✅ Low'],
            ]);

            // Check for long-running queries
            $longQueries = DB::select("
                SELECT ID, USER, HOST, DB, COMMAND, TIME, LEFT(INFO, 50) as QUERY
                FROM INFORMATION_SCHEMA.PROCESSLIST
                WHERE TIME > 10 AND COMMAND != 'Sleep'
                ORDER BY TIME DESC
                LIMIT 10
            ");

            if (!empty($longQueries)) {
                $this->warn('⚠️ Long-running queries detected:');
                $this->table(['ID', 'User', 'Time (s)', 'Query Preview'],
                    array_map(function($query) {
                        return [$query->ID, $query->USER, $query->TIME, $query->QUERY ?? 'N/A'];
                    }, $longQueries)
                );
            } else {
                $this->info('✅ No long-running queries detected');
            }

        } catch (\Exception $e) {
            $this->error('❌ Database Connection Check Failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function checkQueuePerformance()
    {
        $this->info('🚀 Queue Performance Check');
        $this->line('═══════════════════════════════════════');

        try {
            $redis = Redis::connection('queue');

            $queues = ['high', 'optimized-actions', 'bulk', 'actions', 'default'];
            $queueData = [];

            foreach ($queues as $queue) {
                $size = $redis->llen("queues:{$queue}");
                $processing = $redis->llen("queues:{$queue}:reserved");
                $status = $this->getQueueStatus($size);

                $queueData[] = [
                    'Queue' => $queue,
                    'Pending Jobs' => $size,
                    'Processing' => $processing,
                    'Status' => $status
                ];
            }

            $this->table(['Queue', 'Pending Jobs', 'Processing', 'Status'], $queueData);

            // Check failed jobs
            $failedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>=', now()->subHours(24))
                ->count();

            $this->info("Failed Jobs (Last 24h): {$failedJobs}" . ($failedJobs > 10 ? ' ⚠️ High' : ' ✅ Low'));

            // Check job processing rates
            $recentJobs = DB::table('jobs')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->count();

            $completedJobs = DB::table('actions')
                ->where('updated_at', '>=', now()->subMinutes(30))
                ->where('status', 'done')
                ->count();

            $processingRate = $recentJobs > 0 ? round(($completedJobs / $recentJobs) * 100, 1) : 0;

            $this->info("Job Processing Rate (Last 30min): {$processingRate}% ({$completedJobs}/{$recentJobs})");

        } catch (\Exception $e) {
            $this->error('❌ Queue Performance Check Failed: ' . $e->getMessage());
        }

        $this->newLine();
    }

    private function getConnectionStatus($connections)
    {
        if ($connections < 20) return '✅ Excellent';
        if ($connections < 50) return '✅ Good';
        if ($connections < 100) return '⚠️ Moderate';
        return '❌ High';
    }

    private function getUsageStatus($percentage)
    {
        if ($percentage < 30) return '✅ Excellent';
        if ($percentage < 60) return '✅ Good';
        if ($percentage < 80) return '⚠️ Moderate';
        return '❌ Critical';
    }

    private function getQueueStatus($size)
    {
        if ($size == 0) return '✅ Empty';
        if ($size < 10) return '✅ Low';
        if ($size < 100) return '⚠️ Moderate';
        return '❌ High';
    }
}
