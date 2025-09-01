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
    public $tries = 3; // Reduced tries for faster failure detection
    public $backoff = [2, 5, 10]; // Faster backoff

    public function __construct($userId, $orderId, $type, $url)
    {
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->url = $url;
    }

    public function handle()
    {
        // 🚀 IMMEDIATE EXECUTION - No delays, no concurrency limits for user activation
        $payloadArray = [
            'user_id' => $this->userId,
            'url' => $this->url,
            'order_id' => $this->orderId,
            'type' => $this->type,
        ];

        $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        // Execute immediately - synchronous for immediate delivery to active users
        $command = "node {$scriptPath} {$escapedJson} >> " . storage_path('logs/mqtt_output.log') . " 2>&1";
        exec($command);

        // Minimal logging for critical issues only
        if ($this->attempts() > 1) {
            Log::warning("MQTT job retry", [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts()
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error("MQTT job failed permanently", [
            'user_id' => $this->userId,
            'order_id' => $this->orderId,
            'error' => $exception->getMessage()
        ]);
    }
}
