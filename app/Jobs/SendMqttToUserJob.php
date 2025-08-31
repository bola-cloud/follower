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
        // Smart concurrency limiting for thousands of jobs
        $lockKey = 'mqtt_concurrency_limit';
        $maxConcurrency = $this->getOptimalConcurrency();

        $currentCount = Cache::increment($lockKey);

        if ($currentCount > $maxConcurrency) {
            Cache::decrement($lockKey);
            // Smart retry with jitter to prevent thundering herd
            $delay = min(60, pow(2, $this->attempts() - 1)) + rand(1, 5);
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

            // Optimized execution for high throughput
            exec("node {$scriptPath} {$escapedJson} >/dev/null 2>&1 &");

            // Log milestones for monitoring
            if ($this->orderId % 500 === 0) {
                Log::info("MQTT processing milestone", [
                    'order_id' => $this->orderId,
                    'concurrency' => $currentCount,
                    'max_concurrency' => $maxConcurrency
                ]);
            }

        } catch (\Exception $e) {
            Log::error("MQTT Job failed: " . $e->getMessage(), [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts()
            ]);

            throw $e; // Let Laravel handle retry
        } finally {
            // Always decrement the counter
            Cache::decrement($lockKey);
        }
    }

    private function getOptimalConcurrency()
    {
        // Dynamic concurrency based on system resources
        $systemLoad = sys_getloadavg()[0] ?? 1.0;
        $memoryUsage = memory_get_usage(true) / 1024 / 1024; // MB

        // Scale concurrency based on system capacity
        if ($systemLoad < 2.0 && $memoryUsage < 500) {
            return 300; // High performance mode
        } elseif ($systemLoad < 5.0 && $memoryUsage < 1000) {
            return 150; // Balanced mode
        } else {
            return 75; // Conservative mode
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("MQTT job permanently failed after {$this->tries} attempts", [
            'user_id' => $this->userId,
            'order_id' => $this->orderId,
            'error' => $exception->getMessage()
        ]);
    }
}
