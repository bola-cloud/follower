<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MqttLoadTest extends Command
{
    protected $signature = 'mqtt:load-test
                           {--concurrent=1000 : Number of concurrent requests}
                           {--order-id=9999 : Order ID for testing}
                           {--base-user-id=10000 : Starting user ID}
                           {--endpoint=https://127.0.0.1/api/mqtt/response : MQTT endpoint URL}
                           {--status=done : Status to send (done/external)}
                           {--delay=0 : Delay between requests in milliseconds}';

    protected $description = 'Load test MQTT response endpoint with concurrent requests';

    public function handle()
    {
        $concurrent = $this->option('concurrent');
        $orderId = $this->option('order-id');
        $baseUserId = $this->option('base-user-id');
        $endpoint = $this->option('endpoint');
        $status = $this->option('status');
        $delay = $this->option('delay');

        $this->info("🚀 Starting MQTT Load Test");
        $this->info("Concurrent requests: {$concurrent}");
        $this->info("Order ID: {$orderId}");
        $this->info("Endpoint: {$endpoint}");
        $this->info("Status: {$status}");

        // Record initial database state
        $initialCount = $this->getActionCount($orderId);
        $this->info("Initial action count for order {$orderId}: {$initialCount}");

        $startTime = microtime(true);
        $results = $this->sendConcurrentRequests($concurrent, $orderId, $baseUserId, $endpoint, $status, $delay);
        $endTime = microtime(true);

        $duration = $endTime - $startTime;
    $totalRequests = count($results);
    $successfulRequests = count(array_filter($results, fn($r) => $r['success']));
    $failedRequests = $totalRequests - $successfulRequests;

        // Wait a bit for async processing
        $this->info("Waiting 10 seconds for async processing...");
        sleep(10);

        // Check final database state
        $finalCount = $this->getActionCount($orderId);
    $recordedActions = $finalCount - $initialCount;

        // Display results
        $this->info("\n📊 Load Test Results:");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Duration', round($duration, 2) . ' seconds'],
                ['Total Requests', $totalRequests],
                ['Successful Requests', $successfulRequests],
                ['Failed Requests', $failedRequests],
                ['Success Rate', $totalRequests > 0 ? round(($successfulRequests / $totalRequests) * 100, 2) . '%' : '0%'],
                ['Requests per Second', $duration > 0 ? round($totalRequests / $duration, 2) : '0'],
                ['Average Response Time', $totalRequests > 0 ? round(array_sum(array_column($results, 'duration')) / $totalRequests, 4) . ' seconds' : '0 seconds'],
                ['Actions Recorded in DB', $recordedActions],
                ['Recording Success Rate', $successfulRequests > 0 ? round(($recordedActions / $successfulRequests) * 100, 2) . '%' : '0%']
            ]
        );

        // Response time distribution
        $responseTimes = array_column($results, 'duration');
        sort($responseTimes);
        if (count($responseTimes) === 0) {
            $p50 = $p95 = $p99 = 0.0;
            $minRt = 0.0;
            $maxRt = 0.0;
        } else {
            $p50 = $responseTimes[intval(count($responseTimes) * 0.5)];
            $p95 = $responseTimes[intval(count($responseTimes) * 0.95)];
            $p99 = $responseTimes[intval(count($responseTimes) * 0.99)];
            $minRt = min($responseTimes);
            $maxRt = max($responseTimes);
        }

        $this->info("\n⏱️ Response Time Distribution:");
        $this->table(
            ['Percentile', 'Time (seconds)'],
            [
                ['50th (Median)', round($p50, 4)],
                ['95th', round($p95, 4)],
                ['99th', round($p99, 4)],
                ['Min', round($minRt, 4)],
                ['Max', round($maxRt, 4)]
            ]
        );

        // Error analysis
        $errors = array_filter($results, fn($r) => !$r['success']);
        if (!empty($errors)) {
            $this->warn("\n❌ Error Analysis:");
            $errorCounts = [];
            foreach ($errors as $error) {
                $key = $error['error'] ?? 'Unknown';
                $errorCounts[$key] = ($errorCounts[$key] ?? 0) + 1;
            }

            foreach ($errorCounts as $error => $count) {
                $this->line("  {$error}: {$count} occurrences");
            }
        }

        // Performance assessment
        $this->assessPerformance($duration, $successfulRequests, $recordedActions, $p95);

        return 0;
    }

    private function sendConcurrentRequests($concurrent, $orderId, $baseUserId, $endpoint, $status, $delay)
    {
        $this->info("Sending {$concurrent} concurrent requests...");

        $processes = [];
        $results = [];

        // Use curl_multi for true concurrency
        $multiHandle = curl_multi_init();
        $curlHandles = [];

        for ($i = 0; $i < $concurrent; $i++) {
            $userId = $baseUserId + $i;
            $payload = json_encode([
                'order_id' => $orderId,
                'user_id' => $userId,
                'status' => $status
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$i] = $ch;

            if ($delay > 0) {
                usleep($delay * 1000); // Convert ms to microseconds
            }
        }

        // Execute all requests
        $running = null;
        do {
            curl_multi_exec($multiHandle, $running);
            curl_multi_select($multiHandle);
        } while ($running > 0);

        // Collect results
        for ($i = 0; $i < $concurrent; $i++) {
            $ch = $curlHandles[$i];
            $response = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);

            $results[$i] = [
                'user_id' => $baseUserId + $i,
                'success' => $info['http_code'] >= 200 && $info['http_code'] < 300,
                'http_code' => $info['http_code'],
                'duration' => $info['total_time'],
                'response' => $response,
                'error' => $error ?: null
            ];

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $results;
    }

    private function getActionCount($orderId)
    {
        return DB::table('actions')
            ->where('order_id', $orderId)
            ->count();
    }

    private function assessPerformance($duration, $successful, $recorded, $p95)
    {
        $this->info("\n🎯 Performance Assessment:");

    $rps = $duration > 0 ? ($successful / $duration) : 0;
        if ($rps >= 500) {
            $this->info("✅ Excellent throughput: {$rps} requests/second");
        } elseif ($rps >= 200) {
            $this->info("✅ Good throughput: {$rps} requests/second");
        } elseif ($rps >= 100) {
            $this->warn("⚠️ Moderate throughput: {$rps} requests/second");
        } else {
            $this->error("❌ Low throughput: {$rps} requests/second");
        }

        if ($p95 <= 0.1) {
            $this->info("✅ Excellent response times (95th percentile: {$p95}s)");
        } elseif ($p95 <= 0.5) {
            $this->info("✅ Good response times (95th percentile: {$p95}s)");
        } elseif ($p95 <= 1.0) {
            $this->warn("⚠️ Moderate response times (95th percentile: {$p95}s)");
        } else {
            $this->error("❌ Slow response times (95th percentile: {$p95}s)");
        }

    $recordingRate = $successful > 0 ? (($recorded / $successful) * 100) : 0;
        if ($recordingRate >= 95) {
            $this->info("✅ Excellent data integrity: {$recordingRate}% recorded");
        } elseif ($recordingRate >= 90) {
            $this->warn("⚠️ Good data integrity: {$recordingRate}% recorded");
        } else {
            $this->error("❌ Poor data integrity: {$recordingRate}% recorded");
        }
    }
}
