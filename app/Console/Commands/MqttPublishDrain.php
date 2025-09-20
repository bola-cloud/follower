<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Services\BatchDatabaseService;

class MqttPublishDrain extends Command
{
    protected $signature = 'mqtt:publish-drain {--limit=500} {--once=false}';
    protected $description = 'Drain mqtt_publish_queue and run mqtt_order_publisher.cjs for each item (with retries).';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $once = filter_var($this->option('once'), FILTER_VALIDATE_BOOLEAN);
        $queueKey = env('MQTT_PUBLISH_QUEUE', 'mqtt_publish_queue');
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        $this->info("Starting drain of {$queueKey} (limit={$limit})");

        $processed = 0;

        do {
            for ($i = 0; $i < $limit; $i++) {
                $raw = Redis::lpop($queueKey);
                if (!$raw) break;
                $item = json_decode($raw, true);
                if (!$item) continue;

                try {
                    // Skip invalid or busy items
                    if (empty($item['user_id']) || (!empty($item['status']) && $item['status'] === 'busy')) {
                        Log::info('[MqttPublishDrain] Skipping publish item with empty user_id or busy status', ['item' => $item]);
                        continue;
                    }

                    // If order exists and is paused, skip publishing for this order
                    if (!empty($item['order_id'])) {
                        try {
                            $order = \App\Models\Order::find($item['order_id']);
                            if ($order && isset($order->status) && $order->status === 'paused') {
                                Log::info('[MqttPublishDrain] Skipping publish because order is paused', ['order_id' => $item['order_id'], 'item' => $item]);
                                continue;
                            }
                        } catch (\Throwable $__e) {
                            Log::warning('[MqttPublishDrain] Could not verify order status before publish', ['order_id' => $item['order_id'], 'error' => $__e->getMessage()]);
                        }
                    }
                    $json = $item['json'] ?? json_encode([
                        'user_id' => $item['user_id'] ?? null,
                        'url' => $item['url'] ?? null,
                        'order_id' => $item['order_id'] ?? null,
                        'type' => $item['type'] ?? null,
                    ]);

                    // Enqueue to the persistent Node publisher (single long-lived MQTT connection)
                    $publisherQueue = env('MQTT_QUEUE_KEY', 'mqtt:publish');

                    $job = [
                        'topic' => "orders/{$item['user_id']}",
                        'payload' => $item['json'] ?? json_encode([
                            'user_id' => $item['user_id'] ?? null,
                            'url' => $item['url'] ?? null,
                            'order_id' => $item['order_id'] ?? null,
                            'type' => $item['type'] ?? null,
                        ]),
                        'qos' => 0,
                        'retain' => false,
                        'meta' => [
                            'attempts' => ($item['attempts'] ?? 0),
                            'enqueued_at' => time(),
                        ]
                    ];

                    // Push job to tail so persistent publisher (BRPOP) will process it
                    Redis::rpush($publisherQueue, json_encode($job));
                    Log::info('[MqttPublishDrain] Enqueued publish job to persistent publisher', ['publisher_queue' => $publisherQueue, 'job' => $job]);

                    $processed++;
                } catch (\Throwable $e) {
                    Log::error('[MqttPublishDrain] Exception while processing publish item', ['error' => $e->getMessage(), 'item' => $item]);
                }
            }

            if ($once) break;

            if ($processed === 0) {
                // little sleep to avoid busy loop
                usleep(200000); // 200ms
            }

        } while (true);

        $this->info('MqttPublishDrain completed. Processed: ' . $processed);
        return 0;
    }
}
