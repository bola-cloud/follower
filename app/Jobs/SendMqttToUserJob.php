<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;

class SendMqttToUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;
    public $orderId;
    public $type;
    public $url;
    public $tries = 2; // Quick failure for high throughput
    public $backoff = [1, 3]; // Fast backoff
    public $timeout = 30; // 30 second timeout

    public function __construct($userId, $orderId, $type, $url)
    {
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->url = $url;

        // 🚀 HIGH PRIORITY: Set high priority for immediate processing
        $this->onQueue('high');
    }

    public function handle()
    {
        // 🚀 ULTRA-FAST EXECUTION - No delays, no blocking operations
        $payloadArray = [
            'user_id' => $this->userId,
            'url' => $this->url,
            'order_id' => $this->orderId,
            'type' => $this->type,
        ];

        $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        // 🚀 ASYNCHRONOUS EXECUTION: Fire and forget for maximum speed
        $command = "node {$scriptPath} {$escapedJson} >> " . storage_path('logs/mqtt_output.log') . " 2>&1 &";
        exec($command);

        // Minimal logging only for errors
        if ($this->attempts() > 1) {
            Log::warning("MQTT retry", [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts()
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        // Only log permanent failures
        Log::error("MQTT job failed permanently", [
            'user_id' => $this->userId,
            'order_id' => $this->orderId,
            'error' => $exception->getMessage()
        ]);
    }
}
