<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class AnalyzeQueuePerformance extends Command
{
    protected $signature = 'system:analyze-queue-performance
                            {--minutes=60 : Minutes of history to analyze}
                            {--queue= : Specific queue to analyze}
                            {--export : Export results to file}';

    protected $description = 'Analyze queue performance and identify bottlenecks';

    public function handle()
    {
        $minutes = $this->option('minutes');
        $specificQueue = $this->option('queue');

        $this->info("📈 Queue Performance Analysis (Last {$minutes} minutes)");
        $this->line('═══════════════════════════════════════════════════');

        $analysis = [
            'jobs_processed' => $this->getJobsProcessed($minutes),
            'processing_times' => $this->getProcessingTimes($minutes),
            'failure_rates' => $this->getFailureRates($minutes),
            'queue_sizes' => $this->getCurrentQueueSizes(),
            'database_impact' => $this->getDatabaseImpact($minutes),
            'batch_efficiency' => $this->getBatchEfficiency($minutes)
        ];

        $this->displayAnalysis($analysis, $specificQueue);

        if ($this->option('export')) {
            $this->exportAnalysis($analysis);
        }
    }

    private function getJobsProcessed($minutes)
    {
        // Analyze job processing by queue and time
        $jobsByQueue = DB::select("
            SELECT
                'actions' as queue_name,
                COUNT(*) as jobs_processed,
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_processing_time
            FROM actions
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND status IN ('done', 'external')

            UNION ALL

            SELECT
                'batch_operations' as queue_name,
                COUNT(*) / 500 as jobs_processed,  -- Estimate batch jobs
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_processing_time
            FROM actions
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND status = 'done'
            AND created_at = updated_at  -- Batch operations often have same timestamps
        ", [$minutes, $minutes]);

        return $jobsByQueue;
    }

    private function getProcessingTimes($minutes)
    {
        $times = DB::select("
            SELECT
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_time,
                MAX(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as max_time,
                MIN(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as min_time
            FROM actions
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY status
        ", [$minutes]);

        return $times;
    }

    private function getFailureRates($minutes)
    {
        $failures = DB::select("
            SELECT
                COUNT(*) as total_failed,
                exception,
                COUNT(*) / (SELECT COUNT(*) FROM failed_jobs WHERE failed_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)) * 100 as percentage
            FROM failed_jobs
            WHERE failed_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY exception
            ORDER BY total_failed DESC
            LIMIT 10
        ", [$minutes, $minutes]);

        $totalFailed = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subMinutes($minutes))
            ->count();

        return ['failures' => $failures, 'total_failed' => $totalFailed];
    }

    private function getCurrentQueueSizes()
    {
        $redis = Redis::connection('queue');
        $queues = ['high', 'optimized-actions', 'bulk', 'actions', 'default'];
        $sizes = [];

        foreach ($queues as $queue) {
            $sizes[$queue] = [
                'pending' => $redis->llen("queues:{$queue}"),
                'reserved' => $redis->llen("queues:{$queue}:reserved"),
                'delayed' => $redis->zcard("queues:{$queue}:delayed"),
            ];
        }

        return $sizes;
    }

    private function getDatabaseImpact($minutes)
    {
        $impact = DB::select("
            SELECT
                'connections_used' as metric,
                (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Threads_connected') as current_value,
                (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_VARIABLES WHERE VARIABLE_NAME='max_connections') as max_value

            UNION ALL

            SELECT
                'queries_per_minute' as metric,
                ROUND((SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Questions') /
                     (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Uptime') * 60) as current_value,
                NULL as max_value

            UNION ALL

            SELECT
                'slow_queries' as metric,
                (SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Slow_queries') as current_value,
                NULL as max_value
        ");

        return $impact;
    }

    private function getBatchEfficiency($minutes)
    {
        // Analyze batch processing efficiency
        $efficiency = DB::select("
            SELECT
                DATE(created_at) as date,
                HOUR(created_at) as hour,
                COUNT(*) as actions_created,
                COUNT(*) / 500 as estimated_batches,
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_completion_time
            FROM actions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            GROUP BY DATE(created_at), HOUR(created_at)
            ORDER BY date DESC, hour DESC
            LIMIT 24
        ", [$minutes]);

        return $efficiency;
    }

    private function displayAnalysis($analysis, $specificQueue = null)
    {
        // Jobs Processed Analysis
        $this->info('🎯 Jobs Processed by Queue:');
        $this->table(['Queue', 'Jobs Processed', 'Avg Processing Time (s)'],
            array_map(function($job) {
                return [
                    $job->queue_name,
                    number_format($job->jobs_processed),
                    round($job->avg_processing_time ?? 0, 2)
                ];
            }, $analysis['jobs_processed'])
        );

        // Processing Times
        $this->info('⏱️ Processing Times by Status:');
        $this->table(['Status', 'Count', 'Avg Time (s)', 'Max Time (s)', 'Min Time (s)'],
            array_map(function($time) {
                return [
                    $time->status,
                    number_format($time->count),
                    round($time->avg_time ?? 0, 2),
                    round($time->max_time ?? 0, 2),
                    round($time->min_time ?? 0, 2)
                ];
            }, $analysis['processing_times'])
        );

        // Current Queue Sizes
        $this->info('📊 Current Queue Sizes:');
        $queueTableData = [];
        foreach ($analysis['queue_sizes'] as $queue => $sizes) {
            if (!$specificQueue || $queue === $specificQueue) {
                $queueTableData[] = [
                    $queue,
                    $sizes['pending'],
                    $sizes['reserved'],
                    $sizes['delayed'],
                    $sizes['pending'] + $sizes['reserved'] + $sizes['delayed']
                ];
            }
        }
        $this->table(['Queue', 'Pending', 'Reserved', 'Delayed', 'Total'], $queueTableData);

        // Failure Analysis
        if ($analysis['failure_rates']['total_failed'] > 0) {
            $this->warn("⚠️ Total Failed Jobs: {$analysis['failure_rates']['total_failed']}");
            $this->table(['Exception', 'Count', 'Percentage'],
                array_map(function($failure) {
                    return [
                        substr($failure->exception ?? 'Unknown', 0, 50) . '...',
                        $failure->total_failed,
                        round($failure->percentage, 2) . '%'
                    ];
                }, array_slice($analysis['failure_rates']['failures'], 0, 5))
            );
        } else {
            $this->info('✅ No failed jobs in the analyzed period');
        }

        // Database Impact
        $this->info('🗄️ Database Impact:');
        $this->table(['Metric', 'Current Value', 'Max Value', 'Status'],
            array_map(function($impact) {
                $status = $this->getMetricStatus($impact->metric, $impact->current_value, $impact->max_value);
                return [
                    ucfirst(str_replace('_', ' ', $impact->metric)),
                    number_format($impact->current_value),
                    $impact->max_value ? number_format($impact->max_value) : 'N/A',
                    $status
                ];
            }, $analysis['database_impact'])
        );

        // Batch Efficiency
        if (!empty($analysis['batch_efficiency'])) {
            $this->info('⚡ Batch Processing Efficiency (Last 24 hours):');
            $this->table(['Date', 'Hour', 'Actions Created', 'Est. Batches', 'Avg Completion (s)'],
                array_slice(array_map(function($eff) {
                    return [
                        $eff->date,
                        sprintf('%02d:00', $eff->hour),
                        number_format($eff->actions_created),
                        number_format($eff->estimated_batches),
                        round($eff->avg_completion_time ?? 0, 2)
                    ];
                }, $analysis['batch_efficiency']), 0, 10)
            );
        }

        // Performance Recommendations
        $this->displayRecommendations($analysis);
    }

    private function getMetricStatus($metric, $current, $max = null)
    {
        switch ($metric) {
            case 'connections_used':
                if ($max && $current / $max > 0.8) return '❌ Critical';
                if ($max && $current / $max > 0.6) return '⚠️ High';
                return '✅ Good';

            case 'queries_per_minute':
                if ($current > 10000) return '❌ Very High';
                if ($current > 5000) return '⚠️ High';
                return '✅ Normal';

            case 'slow_queries':
                if ($current > 100) return '❌ Too Many';
                if ($current > 10) return '⚠️ Some Issues';
                return '✅ Good';

            default:
                return 'ℹ️ Info';
        }
    }

    private function displayRecommendations($analysis)
    {
        $this->info('💡 Performance Recommendations:');
        $this->line('─────────────────────────────────');

        $recommendations = [];

        // Check queue sizes
        foreach ($analysis['queue_sizes'] as $queue => $sizes) {
            $total = $sizes['pending'] + $sizes['reserved'];
            if ($total > 1000) {
                $recommendations[] = "⚠️ {$queue} queue has {$total} jobs - consider adding more workers";
            } elseif ($total > 100) {
                $recommendations[] = "ℹ️ {$queue} queue moderately busy ({$total} jobs) - monitor closely";
            }
        }

        // Check failure rates
        if ($analysis['failure_rates']['total_failed'] > 50) {
            $recommendations[] = '❌ High failure rate detected - investigate failed job patterns';
        }

        // Check database connections
        foreach ($analysis['database_impact'] as $impact) {
            if ($impact->metric === 'connections_used' && $impact->max_value) {
                $usage = ($impact->current_value / $impact->max_value) * 100;
                if ($usage > 80) {
                    $recommendations[] = "❌ Database connection usage at {$usage}% - optimize batch sizes";
                } elseif ($usage > 60) {
                    $recommendations[] = "⚠️ Database connection usage at {$usage}% - monitor closely";
                }
            }
        }

        // Check processing efficiency
        $totalActions = array_sum(array_map(function($job) { return $job->jobs_processed; }, $analysis['jobs_processed']));
        $avgProcessingTime = array_sum(array_map(function($job) { return $job->avg_processing_time ?? 0; }, $analysis['jobs_processed'])) / count($analysis['jobs_processed']);

        if ($avgProcessingTime > 60) {
            $recommendations[] = '⚠️ Average processing time is high - consider optimizing batch sizes';
        }

        if ($totalActions > 10000) {
            $recommendations[] = '✅ High throughput achieved - system performing well';
        }

        if (empty($recommendations)) {
            $recommendations[] = '✅ System performing optimally - no immediate action needed';
        }

        foreach ($recommendations as $recommendation) {
            $this->line($recommendation);
        }
    }

    private function exportAnalysis($analysis)
    {
        $filename = storage_path('logs/queue-analysis-' . now()->format('Y-m-d-H-i-s') . '.json');
        file_put_contents($filename, json_encode($analysis, JSON_PRETTY_PRINT));
        $this->info("📁 Analysis exported to: {$filename}");
    }
}
