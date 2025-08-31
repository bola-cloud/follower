<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class BatchMqttPublishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $messages;
    public $tries = 3;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function handle()
    {
        $batchSize = count($this->messages);

        try {
            // Prepare batch data for Node.js script
            $batchData = json_encode($this->messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedData = escapeshellarg($batchData);
            $scriptPath = base_path('node_scripts/mqtt_batch_publisher.cjs');

            // Execute batch publishing
            $output = [];
            $returnCode = 0;
            exec("node {$scriptPath} {$escapedData} 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                Log::info("Batch MQTT publish successful", [
                    'batch_size' => $batchSize,
                    'messages' => array_column($this->messages, 'order_id')
                ]);
            } else {
                throw new \Exception("Batch publish failed with code {$returnCode}: " . implode("\n", $output));
            }

        } catch (\Exception $e) {
            Log::error("Batch MQTT publish failed: " . $e->getMessage(), [
                'batch_size' => $batchSize,
                'attempt' => $this->attempts()
            ]);

            throw $e;
        }
    }

    public static function addToBatch($userId, $orderId, $type, $url)
    {
        $message = [
            'user_id' => $userId,
            'url' => $url,
            'order_id' => $orderId,
            'type' => $type,
        ];

        $batchKey = 'mqtt_batch_queue';
        $batchSizeKey = 'mqtt_batch_size';
        $maxBatchSize = 50; // Process in batches of 50

        // Add message to batch
        Cache::put($batchKey, Cache::get($batchKey, []) + [uniqid() => $message], 300); // 5 min TTL
        $currentSize = Cache::increment($batchSizeKey);

        // Process batch when it reaches max size
        if ($currentSize >= $maxBatchSize) {
            self::processBatch();
        }

        // Set a timer to process incomplete batches
        Cache::put('mqtt_batch_timer', time(), 305);
    }

    public static function processBatch()
    {
        $batchKey = 'mqtt_batch_queue';
        $batchSizeKey = 'mqtt_batch_size';

        $messages = Cache::get($batchKey, []);

        if (!empty($messages)) {
            // Dispatch batch job
            self::dispatch(array_values($messages));

            // Clear batch
            Cache::forget($batchKey);
            Cache::forget($batchSizeKey);
        }
    }
}
