<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class SendMqttToUserServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $userId;
    public $orderId;
    public $type;
    public $url;
    public $tries = 3;
    public $backoff = [5, 30, 60]; // Exponential backoff in seconds

    public function __construct($userId, $orderId, $type, $url)
    {
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->url = $url;
    }

    public function handle()
    {
        try {
            $jobData = [
                'user_id' => $this->userId,
                'url' => $this->url,
                'order_id' => $this->orderId,
                'type' => $this->type,
                'timestamp' => now()->toISOString(),
            ];

            // Push to Redis queue for the persistent MQTT service
            Redis::lpush('mqtt_queue', json_encode($jobData));

            Log::info("MQTT job queued for persistent service", [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'queue_length' => Redis::llen('mqtt_queue')
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to queue MQTT job: " . $e->getMessage(), [
                'user_id' => $this->userId,
                'order_id' => $this->orderId,
                'attempt' => $this->attempts()
            ]);

            throw $e; // Let Laravel handle retry logic
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
