<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BatchDatabaseService;
use App\Jobs\OptimizedActionBatchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class SimulateOrder extends Command
{
    protected $signature = 'simulate:orders
                            {--count=100 : Number of users to simulate}
                            {--type=follow : Order type}
                            {--batch-size=500 : Batch size for processing}
                            {--monitor : Monitor system during simulation}';

    protected $description = 'Simulate order processing to test batch system performance';

    public function handle()
    {
        $count = (int) $this->option('count');
        $type = $this->option('type');
        $batchSize = (int) $this->option('batch-size');
        $monitor = $this->option('monitor');

        $this->info("🚀 Simulating order processing for {$count} users");
        $this->line('═══════════════════════════════════════════');

        $startTime = microtime(true);
        $initialConnections = $this->getCurrentConnections();

        // Create test order
        $order = DB::table('orders')->insertGetId([
            'user_id' => 1,
            'type' => $type,
            'target_url' => 'https://test-simulation-' . time() . '.com',
            'total_count' => $count,
            'done_count' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $this->info("📝 Created test order ID: {$order}");

        // Generate test user IDs
        $userIds = range(1, $count);
        $this->info("👥 Generated {$count} user IDs");

        // Start monitoring if requested
        if ($monitor) {
            $this->startMonitoring();
        }

        // Process in batches using optimized service
        $batchService = app(BatchDatabaseService::class);
        $batches = array_chunk($userIds, $batchSize);
        $totalProcessed = 0;

        $this->info("📦 Processing {$count} users in " . count($batches) . " batches of {$batchSize}");

        foreach ($batches as $index => $batchUserIds) {
            $batchStart = microtime(true);

            try {
                // Create actions using batch service
                $inserted = $batchService->createOrderActions(
                    (object)['id' => $order, 'type' => $type],
                    $batchUserIds
                );

                $totalProcessed += $inserted;
                $batchTime = round((microtime(true) - $batchStart) * 1000, 2);

                $this->line("  Batch " . ($index + 1) . ": {$inserted} actions created in {$batchTime}ms");

                // Queue batch job for processing
                OptimizedActionBatchJob::dispatch($order, $batchUserIds, [
                    'batch_id' => $index + 1,
                    'simulation' => true
                ])->onQueue('optimized-actions');

            } catch (\Exception $e) {
                $this->error("❌ Batch " . ($index + 1) . " failed: " . $e->getMessage());
            }

            // Small delay to prevent overwhelming the system
            if ($count > 1000) {
                usleep(100000); // 100ms delay for large simulations
            }
        }

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $finalConnections = $this->getCurrentConnections();
        $connectionDiff = $finalConnections - $initialConnections;

        // Display results
        $this->displayResults([
            'order_id' => $order,
            'total_users' => $count,
            'batches_created' => count($batches),
            'actions_created' => $totalProcessed,
            'total_time_ms' => $totalTime,
            'avg_time_per_batch' => round($totalTime / count($batches), 2),
            'actions_per_second' => round($totalProcessed / ($totalTime / 1000), 2),
            'initial_connections' => $initialConnections,
            'final_connections' => $finalConnections,
            'connection_diff' => $connectionDiff
        ]);

        // Monitor queue processing
        if ($monitor) {
            $this->monitorQueueProcessing($order);
        }

        // Cleanup option
        if ($this->confirm('Delete test order and actions?', true)) {
            $this->cleanup($order);
        }
    }

    private function getCurrentConnections()
    {
        try {
            $result = DB::select("SHOW STATUS LIKE 'Threads_connected'");
            return (int) $result[0]->Value;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function startMonitoring()
    {
        $this->info('📊 Monitoring enabled - will track system metrics during simulation');
        // This could be extended to log metrics to a file or external monitoring system
    }

    private function displayResults($results)
    {
        $this->newLine();
        $this->info('📈 Simulation Results');
        $this->line('═══════════════════════════════════════════');

        $this->table(['Metric', 'Value', 'Performance'], [
            ['Order ID', $results['order_id'], 'ℹ️ Test Order'],
            ['Total Users', number_format($results['total_users']), $this->getVolumeStatus($results['total_users'])],
            ['Batches Created', $results['batches_created'], 'ℹ️ Processing Units'],
            ['Actions Created', number_format($results['actions_created']), $this->getSuccessStatus($results['actions_created'], $results['total_users'])],
            ['Total Time', $results['total_time_ms'] . 'ms', $this->getTimeStatus($results['total_time_ms'], $results['total_users'])],
            ['Avg Time/Batch', $results['avg_time_per_batch'] . 'ms', $this->getBatchTimeStatus($results['avg_time_per_batch'])],
            ['Actions/Second', number_format($results['actions_per_second']), $this->getThroughputStatus($results['actions_per_second'])],
            ['Initial DB Connections', $results['initial_connections'], $this->getConnectionStatus($results['initial_connections'])],
            ['Final DB Connections', $results['final_connections'], $this->getConnectionStatus($results['final_connections'])],
            ['Connection Increase', '+' . $results['connection_diff'], $this->getConnectionDiffStatus($results['connection_diff'])],
        ]);

        // Performance Assessment
        $this->assessPerformance($results);
    }

    private function getVolumeStatus($count)
    {
        if ($count >= 5000) return '🔥 Extreme Load';
        if ($count >= 1000) return '⚡ High Load';
        if ($count >= 500) return '⚠️ Medium Load';
        return '✅ Light Load';
    }

    private function getSuccessStatus($created, $expected)
    {
        $percentage = $expected > 0 ? ($created / $expected) * 100 : 0;
        if ($percentage >= 99) return '✅ Perfect';
        if ($percentage >= 95) return '✅ Excellent';
        if ($percentage >= 90) return '⚠️ Good';
        return '❌ Issues';
    }

    private function getTimeStatus($timeMs, $userCount)
    {
        $timePerUser = $userCount > 0 ? $timeMs / $userCount : 0;
        if ($timePerUser < 1) return '✅ Excellent';
        if ($timePerUser < 5) return '✅ Good';
        if ($timePerUser < 10) return '⚠️ Acceptable';
        return '❌ Slow';
    }

    private function getBatchTimeStatus($timeMs)
    {
        if ($timeMs < 50) return '✅ Very Fast';
        if ($timeMs < 200) return '✅ Fast';
        if ($timeMs < 500) return '⚠️ Moderate';
        return '❌ Slow';
    }

    private function getThroughputStatus($actionsPerSec)
    {
        if ($actionsPerSec >= 1000) return '🚀 Excellent';
        if ($actionsPerSec >= 500) return '✅ Very Good';
        if ($actionsPerSec >= 100) return '✅ Good';
        if ($actionsPerSec >= 50) return '⚠️ Moderate';
        return '❌ Poor';
    }

    private function getConnectionStatus($connections)
    {
        if ($connections < 20) return '✅ Excellent';
        if ($connections < 50) return '✅ Good';
        if ($connections < 100) return '⚠️ High';
        return '❌ Critical';
    }

    private function getConnectionDiffStatus($diff)
    {
        if ($diff <= 5) return '✅ Minimal Impact';
        if ($diff <= 15) return '✅ Low Impact';
        if ($diff <= 30) return '⚠️ Moderate Impact';
        return '❌ High Impact';
    }

    private function assessPerformance($results)
    {
        $this->newLine();
        $this->info('🎯 Performance Assessment');
        $this->line('─────────────────────────────────');

        $score = 0;
        $maxScore = 6;
        $assessments = [];

        // Time efficiency (0-2 points)
        $timePerUser = $results['total_users'] > 0 ? $results['total_time_ms'] / $results['total_users'] : 0;
        if ($timePerUser < 1) {
            $score += 2;
            $assessments[] = '✅ Excellent processing speed';
        } elseif ($timePerUser < 5) {
            $score += 1;
            $assessments[] = '✅ Good processing speed';
        } else {
            $assessments[] = '⚠️ Processing speed could be improved';
        }

        // Success rate (0-2 points)
        $successRate = $results['total_users'] > 0 ? ($results['actions_created'] / $results['total_users']) * 100 : 0;
        if ($successRate >= 99) {
            $score += 2;
            $assessments[] = '✅ Perfect success rate';
        } elseif ($successRate >= 95) {
            $score += 1;
            $assessments[] = '✅ High success rate';
        } else {
            $assessments[] = '❌ Success rate needs improvement';
        }

        // Connection efficiency (0-2 points)
        if ($results['connection_diff'] <= 5) {
            $score += 2;
            $assessments[] = '✅ Excellent database connection management';
        } elseif ($results['connection_diff'] <= 15) {
            $score += 1;
            $assessments[] = '✅ Good database connection management';
        } else {
            $assessments[] = '⚠️ Database connections increased significantly';
        }

        // Overall grade
        $percentage = ($score / $maxScore) * 100;
        $grade = $this->getGrade($percentage);

        $this->line("Overall Score: {$score}/{$maxScore} ({$percentage}%) - Grade: {$grade}");
        $this->newLine();

        foreach ($assessments as $assessment) {
            $this->line($assessment);
        }
    }

    private function getGrade($percentage)
    {
        if ($percentage >= 90) return 'A+ (Excellent)';
        if ($percentage >= 80) return 'A (Very Good)';
        if ($percentage >= 70) return 'B (Good)';
        if ($percentage >= 60) return 'C (Acceptable)';
        return 'D (Needs Improvement)';
    }

    private function monitorQueueProcessing($orderId)
    {
        $this->info('👀 Monitoring queue processing for 60 seconds...');

        $startTime = time();
        $endTime = $startTime + 60;

        while (time() < $endTime) {
            $queueSizes = [
                'optimized-actions' => Queue::connection('redis')->size('optimized-actions'),
                'high' => Queue::connection('redis')->size('high'),
                'bulk' => Queue::connection('redis')->size('bulk')
            ];

            $processed = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('status', 'done')
                ->count();

            $this->line(sprintf(
                '[%s] Processed: %d | Queues - Opt: %d, High: %d, Bulk: %d',
                date('H:i:s'),
                $processed,
                $queueSizes['optimized-actions'],
                $queueSizes['high'],
                $queueSizes['bulk']
            ));

            sleep(5);
        }
    }

    private function cleanup($orderId)
    {
        DB::table('actions')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->delete();
        $this->info('🗑️ Test data cleaned up');
    }
}
