<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SendMqttToUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;
    public $orderId;
    public $type;
    public $url;
    public $tries = 5;
    public $backoff = [1, 3, 5, 10, 30];

    public function __construct($userId, $orderId, $type, $url)
    {
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->url = $url;
    }

    public function handle()
    {
        // EMERGENCY: Ultra-conservative concurrency to prevent server crashes
        $lockKey = 'mqtt_concurrency_limit';
        $maxConcurrency = 3; // HARD LIMIT: Only 3 processes max

        // Use file-based locking as backup if Cache fails
        $lockFile = storage_path('mqtt_lock_count.txt');

        // Check current count from file (more reliable than Cache)
        $currentCount = 0;
        if (file_exists($lockFile)) {
            $currentCount = (int) file_get_contents($lockFile);
        }

        if ($currentCount >= $maxConcurrency) {
            // Too many processes, delay this job significantly
            $delay = 30 + rand(10, 30); // 30-60 second delay
            $this->release($delay);
            return;
        }

        // Increment counter in file
        file_put_contents($lockFile, $currentCount + 1, LOCK_EX);

        try {
            $payloadArray = [
                'user_id' => $this->userId,
                'url' => $this->url,
                'order_id' => $this->orderId,
                'type' => $this->type,
            ];

            $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedJson = escapeshellarg($json);
            $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

            // Execute and wait for completion (no background)
            $output = [];
            $returnCode = 0;
            exec("timeout 30 node {$scriptPath} {$escapedJson} 2>&1", $output, $returnCode);

            if ($returnCode !== 0) {
                throw new \Exception("MQTT script failed with code {$returnCode}: " . implode("\n", $output));
            }

            Log::info("MQTT job completed", [
                'order_id' => $this->orderId,
                'user_id' => $this->userId,
                'concurrent_processes' => $currentCount + 1
            ]);

        } catch (\Exception $e) {
            Log::error("MQTT Job failed: " . $e->getMessage(), [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts()
            ]);

            throw $e;
        } finally {
            // Always decrement the counter
            $newCount = max(0, $currentCount);
            file_put_contents($lockFile, $newCount, LOCK_EX);
        }
    }

    private function getOptimalConcurrency()
    {
        // EMERGENCY: Very conservative concurrency to prevent server overload
        $systemLoad = sys_getloadavg()[0] ?? 1.0;
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB

        // Much lower limits to prevent server crashes
        if ($systemLoad < 1.0 && $memoryUsage < 200) {
            return 15; // Very conservative mode
        } elseif ($systemLoad < 3.0 && $memoryUsage < 500) {
            return 10; // Safe mode
        } else {
            return 5; // Emergency mode
        }
    }    public function failed(\Throwable $exception)
    {
        Log::error("MQTT job permanently failed after {$this->tries} attempts", [
            'user_id' => $this->userId,
            'order_id' => $this->orderId,
            'error' => $exception->getMessage()
        ]);
    }
}
