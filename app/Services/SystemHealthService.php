<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Jobs\OptimizedActionBatchJob;

/**
 * System health monitoring for high-volume processing
 * Monitors DB connections, queue sizes, memory usage, and worker health
 */
class SystemHealthService
{
    private const HEALTH_CACHE_DURATION = 30; // seconds
    private const WARNING_THRESHOLDS = [
        'db_connection_percent' => 80,
        'queue_size' => 1000,
        'redis_memory_mb' => 1024,
        'failed_jobs' => 10
    ];

    private const CRITICAL_THRESHOLDS = [
        'db_connection_percent' => 95,
        'queue_size' => 2000,
        'redis_memory_mb' => 2048,
        'failed_jobs' => 50
    ];

    /**
     * Get comprehensive system health status
     */
    public function getHealthStatus(): array
    {
        $cacheKey = 'system_health_status';

        return Cache::remember($cacheKey, self::HEALTH_CACHE_DURATION, function() {
            $startTime = microtime(true);

            $health = [
                'timestamp' => now(),
                'overall_status' => 'healthy',
                'components' => [
                    'database' => $this->checkDatabaseHealth(),
                    'queues' => $this->checkQueueHealth(),
                    'redis' => $this->checkRedisHealth(),
                    'workers' => $this->checkWorkerHealth(),
                    'disk_space' => $this->checkDiskSpace()
                ],
                'performance' => [
                    'recent_throughput' => $this->getRecentThroughput(),
                    'average_response_time' => $this->getAverageResponseTime(),
                    'active_connections' => $this->getActiveConnections()
                ],
                'check_duration_ms' => round((microtime(true) - $startTime) * 1000, 2)
            ];

            // Determine overall status based on component health
            $health['overall_status'] = $this->determineOverallStatus($health['components']);

            return $health;
        });
    }

    /**
     * Check database connection health and performance
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $startTime = microtime(true);

            // Test basic connectivity
            DB::connection()->getPdo();
            $connectTime = microtime(true) - $startTime;

            // Get connection statistics
            $connections = DB::select("SHOW STATUS LIKE 'Threads_connected'")[0]->Value ?? 0;
            $maxConnections = DB::select("SHOW VARIABLES LIKE 'max_connections'")[0]->Value ?? 0;
            $connectionPercent = $maxConnections > 0 ? ($connections / $maxConnections) * 100 : 0;

            // Check for slow queries
            $slowQueries = DB::select("SHOW STATUS LIKE 'Slow_queries'")[0]->Value ?? 0;

            // Check table locks
            $tableLocks = DB::select("SHOW STATUS LIKE 'Table_locks_waited'")[0]->Value ?? 0;

            $status = 'healthy';
            if ($connectionPercent > self::CRITICAL_THRESHOLDS['db_connection_percent']) {
                $status = 'critical';
            } elseif ($connectionPercent > self::WARNING_THRESHOLDS['db_connection_percent']) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'connections' => (int)$connections,
                'max_connections' => (int)$maxConnections,
                'connection_percent' => round($connectionPercent, 1),
                'slow_queries' => (int)$slowQueries,
                'table_locks_waited' => (int)$tableLocks,
                'connection_time_ms' => round($connectTime * 1000, 2)
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'connections' => 0,
                'connection_percent' => 0
            ];
        }
    }

    /**
     * Check queue system health
     */
    private function checkQueueHealth(): array
    {
        try {
            $queues = [
                'high_volume_actions_queue' => Redis::llen('high_volume_actions_queue'),
                'mqtt_actions_queue' => Redis::llen('mqtt_actions_queue'),
                'default' => $this->getQueueLength('default'),
                'high-priority' => $this->getQueueLength('high-priority'),
                'optimized-actions' => $this->getQueueLength('optimized-actions')
            ];

            $totalQueueSize = array_sum($queues);
            $failedJobs = $this->getFailedJobsCount();

            $status = 'healthy';
            if ($totalQueueSize > self::CRITICAL_THRESHOLDS['queue_size'] ||
                $failedJobs > self::CRITICAL_THRESHOLDS['failed_jobs']) {
                $status = 'critical';
            } elseif ($totalQueueSize > self::WARNING_THRESHOLDS['queue_size'] ||
                     $failedJobs > self::WARNING_THRESHOLDS['failed_jobs']) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'queues' => $queues,
                'total_queue_size' => $totalQueueSize,
                'failed_jobs' => $failedJobs,
                'processing_rate' => $this->getQueueProcessingRate()
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'queues' => [],
                'total_queue_size' => 0
            ];
        }
    }

    /**
     * Check Redis health and memory usage
     */
    private function checkRedisHealth(): array
    {
        try {
            $startTime = microtime(true);
            Redis::ping();
            $pingTime = microtime(true) - $startTime;

            $info = Redis::info('memory');
            $memoryUsedMB = isset($info['used_memory']) ? round($info['used_memory'] / 1024 / 1024, 2) : 0;
            $memoryMaxMB = isset($info['maxmemory']) && $info['maxmemory'] > 0 ?
                          round($info['maxmemory'] / 1024 / 1024, 2) : null;

            $status = 'healthy';
            if ($memoryUsedMB > self::CRITICAL_THRESHOLDS['redis_memory_mb']) {
                $status = 'critical';
            } elseif ($memoryUsedMB > self::WARNING_THRESHOLDS['redis_memory_mb']) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'memory_used_mb' => $memoryUsedMB,
                'memory_max_mb' => $memoryMaxMB,
                'ping_time_ms' => round($pingTime * 1000, 2),
                'connected_clients' => Redis::info('clients')['connected_clients'] ?? 0
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'error' => $e->getMessage(),
                'memory_used_mb' => 0
            ];
        }
    }

    /**
     * Check worker processes health
     */
    private function checkWorkerHealth(): array
    {
        try {
            // This would require system-level monitoring
            // For now, we'll check Laravel queue worker status indirectly

            $recentJobsCount = $this->getRecentJobsCount();
            $lastJobProcessed = Cache::get('last_job_processed_at');

            $status = 'healthy';
            if (!$lastJobProcessed || now()->diffInMinutes($lastJobProcessed) > 5) {
                $status = $recentJobsCount > 0 ? 'warning' : 'healthy';
            }

            return [
                'status' => $status,
                'recent_jobs_processed' => $recentJobsCount,
                'last_job_processed_at' => $lastJobProcessed ? $lastJobProcessed->toISOString() : null,
                'estimated_workers_active' => $this->estimateActiveWorkers()
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'warning',
                'error' => $e->getMessage(),
                'recent_jobs_processed' => 0
            ];
        }
    }

    /**
     * Check available disk space
     */
    private function checkDiskSpace(): array
    {
        try {
            $bytes = disk_free_space('/');
            $total = disk_total_space('/');

            $freeMB = round($bytes / 1024 / 1024, 2);
            $totalMB = round($total / 1024 / 1024, 2);
            $usedPercent = round(($total - $bytes) / $total * 100, 1);

            $status = 'healthy';
            if ($usedPercent > 95) {
                $status = 'critical';
            } elseif ($usedPercent > 85) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'free_mb' => $freeMB,
                'total_mb' => $totalMB,
                'used_percent' => $usedPercent
            ];

        } catch (\Exception $e) {
            return [
                'status' => 'unknown',
                'error' => $e->getMessage(),
                'free_mb' => 0
            ];
        }
    }

    /**
     * Get queue length for Laravel queues
     */
    private function getQueueLength(string $queueName): int
    {
        try {
            return Redis::llen("queues:$queueName");
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get failed jobs count
     */
    private function getFailedJobsCount(): int
    {
        try {
            return DB::table('failed_jobs')->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get recent processing throughput
     */
    private function getRecentThroughput(): array
    {
        $cacheKey = 'processing_throughput_5min';
        $processed5min = Cache::get($cacheKey, 0);

        $cacheKey = 'processing_throughput_1hour';
        $processed1hour = Cache::get($cacheKey, 0);

        return [
            'per_minute_avg' => round($processed5min / 5, 1),
            'per_hour' => $processed1hour
        ];
    }

    /**
     * Get average response time
     */
    private function getAverageResponseTime(): float
    {
        return Cache::get('avg_response_time_ms', 0.0);
    }

    /**
     * Get active database connections
     */
    private function getActiveConnections(): int
    {
        try {
            $result = DB::select("SHOW STATUS LIKE 'Threads_connected'");
            return (int)($result[0]->Value ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get recent jobs processed count
     */
    private function getRecentJobsCount(): int
    {
        return Cache::get('recent_jobs_processed', 0);
    }

    /**
     * Estimate active workers based on job processing activity
     */
    private function estimateActiveWorkers(): int
    {
        $recentActivity = Cache::get('worker_activity_markers', []);
        $activeWorkers = 0;

        $cutoff = now()->subMinutes(2);
        foreach ($recentActivity as $timestamp) {
            if ($timestamp > $cutoff) {
                $activeWorkers++;
            }
        }

        return $activeWorkers;
    }

    /**
     * Get queue processing rate (jobs per minute)
     */
    private function getQueueProcessingRate(): float
    {
        return Cache::get('queue_processing_rate', 0.0);
    }

    /**
     * Determine overall system status
     */
    private function determineOverallStatus(array $components): string
    {
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($components as $component) {
            switch ($component['status']) {
                case 'critical':
                    $criticalCount++;
                    break;
                case 'warning':
                    $warningCount++;
                    break;
            }
        }

        if ($criticalCount > 0) {
            return 'critical';
        } elseif ($warningCount > 1) {
            return 'warning';
        } else {
            return 'healthy';
        }
    }

    /**
     * Log system health metrics for monitoring
     */
    public function logHealthMetrics(): void
    {
        $health = $this->getHealthStatus();

        Log::info('System health check', [
            'overall_status' => $health['overall_status'],
            'db_connections' => $health['components']['database']['connections'] ?? 0,
            'queue_size' => $health['components']['queues']['total_queue_size'] ?? 0,
            'redis_memory_mb' => $health['components']['redis']['memory_used_mb'] ?? 0,
            'failed_jobs' => $health['components']['queues']['failed_jobs'] ?? 0
        ]);

        // Store metrics for trend analysis
        $this->storeHealthMetrics($health);
    }

    /**
     * Store health metrics for trend analysis
     */
    private function storeHealthMetrics(array $health): void
    {
        $timestamp = now()->timestamp;
        $metricsKey = 'health_metrics_' . date('Y-m-d-H');

        $metrics = [
            'timestamp' => $timestamp,
            'db_connections' => $health['components']['database']['connections'] ?? 0,
            'queue_size' => $health['components']['queues']['total_queue_size'] ?? 0,
            'redis_memory_mb' => $health['components']['redis']['memory_used_mb'] ?? 0,
        ];

        // Store hourly metrics (keep last 24 hours)
        Redis::lpush($metricsKey, json_encode($metrics));
        Redis::ltrim($metricsKey, 0, 59); // Keep last 60 entries (1 hour at 1-minute intervals)
        Redis::expire($metricsKey, 86400); // 24 hours
    }

    /**
     * Get health metrics for the last 24 hours
     */
    public function getHealthMetricsHistory(): array
    {
        $history = [];
        $now = now();

        for ($i = 0; $i < 24; $i++) {
            $hour = $now->subHours($i);
            $metricsKey = 'health_metrics_' . $hour->format('Y-m-d-H');

            $hourlyMetrics = Redis::lrange($metricsKey, 0, -1);
            foreach ($hourlyMetrics as $metric) {
                $decoded = json_decode($metric, true);
                if ($decoded) {
                    $history[] = $decoded;
                }
            }
        }

        // Sort by timestamp (newest first)
        usort($history, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return array_slice($history, 0, 100); // Last 100 data points
    }

    /**
     * Check if system can handle additional load
     */
    public function canHandleAdditionalLoad(): bool
    {
        $health = $this->getHealthStatus();

        // Don't accept additional load if any component is critical
        foreach ($health['components'] as $component) {
            if ($component['status'] === 'critical') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get recommended batch size based on current system load
     */
    public function getRecommendedBatchSize(): int
    {
        $health = $this->getHealthStatus();

        $dbPercent = $health['components']['database']['connection_percent'] ?? 0;
        $queueSize = $health['components']['queues']['total_queue_size'] ?? 0;

        if ($dbPercent > 80 || $queueSize > 1500) {
            return 50; // Small batches under high load
        } elseif ($dbPercent > 60 || $queueSize > 500) {
            return 200; // Medium batches under moderate load
        } else {
            return 500; // Large batches under normal load
        }
    }
}
