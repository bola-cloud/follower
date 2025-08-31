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
        // Balanced concurrency: Safe but much faster than emergency mode
        $lockKey = 'mqtt_concurrency_limit';

        // Dynamic limits based on system load (optimized for speed)
        $systemLoad = sys_getloadavg()[0] ?? 1.0;
        if ($systemLoad > 25) {
            $maxConcurrency = 3;  // Ultra emergency mode
        } elseif ($systemLoad > 15) {
            $maxConcurrency = 8;  // Emergency mode
        } elseif ($systemLoad > 10) {
            $maxConcurrency = 15; // High load mode
        } elseif ($systemLoad > 5) {
            $maxConcurrency = 25; // Moderate load mode
        } else {
            $maxConcurrency = 40; // 🚀 Aggressive normal operation mode
        }

        // Use Redis for faster locking, with file backup
        $currentCount = Cache::increment($lockKey);

        if ($currentCount > $maxConcurrency) {
            Cache::decrement($lockKey);
            // 🚀 Faster retry delays based on system load
            if ($systemLoad < 5) {
                $delay = min(5, $this->attempts()) + rand(1, 2); // Very fast retry on low load
            } elseif ($systemLoad < 15) {
                $delay = min(10, $this->attempts() * 2) + rand(1, 3); // Moderate retry
            } else {
                $delay = min(20, $this->attempts() * 3) + rand(2, 5); // Conservative retry on high load
            }
            $this->release($delay);
            return;
        }

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

            // Background execution for speed, but with timeout protection
            $command = "timeout 45 node {$scriptPath} {$escapedJson} >/dev/null 2>&1 &";
            exec($command);

            // Log every 100 jobs instead of every job (reduce log spam)
            if ($this->orderId % 100 === 0) {
                Log::info("MQTT batch progress", [
                    'order_id' => $this->orderId,
                    'user_id' => $this->userId,
                    'concurrent_processes' => $currentCount,
                    'max_allowed' => $maxConcurrency,
                    'system_load' => $systemLoad
                ]);
            }

        } catch (\Exception $e) {
            Log::error("MQTT Job failed: " . $e->getMessage(), [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts(),
                'system_load' => $systemLoad
            ]);

            throw $e;
        } finally {
            // Always decrement the counter
            Cache::decrement($lockKey);
        }
    }    private function getOptimalConcurrency()
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
