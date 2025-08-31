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

    public function __construct($userId, $orderId, $type, $url)
    {
        $this->userId = $userId;
        $this->orderId = $orderId;
        $this->type = $type;
        $this->url = $url;
    }

    public function handle()
    {
        $payloadArray = [
            'user_id' => $this->userId,
            'url' => $this->url,
            'order_id' => $this->orderId,
            'type' => $this->type,
        ];

        $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        // Use optimized async execution - no logging to improve performance
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start /B node {$scriptPath} {$escapedJson}", "r"));
        } else {
            // Linux: Use exec with & for true background execution
            exec("node {$scriptPath} {$escapedJson} >/dev/null 2>&1 &");
        }
    }
}
