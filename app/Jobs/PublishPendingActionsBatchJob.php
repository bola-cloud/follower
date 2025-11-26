<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishPendingActionsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;

    private $orderId;
    private $batchId;

    public function __construct(int $orderId, string $batchId = null)
    {
        $this->orderId = $orderId;
        $this->batchId = $batchId ?? ('publish_pending_' . time());
        // default queue
    }

    public function handle()
    {
        $order = Order::select('id', 'type', 'target_url', 'mediaId', 'userPk', 'status')->find($this->orderId);
        if (!$order) {
            Log::warning('[PublishPendingActionsBatchJob] Order not found', ['order_id' => $this->orderId]);
            return;
        }

        if ($order->status !== 'active') {
            Log::info('[PublishPendingActionsBatchJob] Order not active, skipping', ['order_id' => $order->id, 'status' => $order->status]);
            return;
        }

        // fetch pending user ids for this order
        $userIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->toArray();

        if (empty($userIds)) {
            Log::info('[PublishPendingActionsBatchJob] No pending actions to publish', ['order_id' => $order->id]);
            return;
        }

        $payload = [
            'url' => $order->target_url,
            'order_id' => $order->id,
            'type' => $order->type,
            'mediaId' => $order->mediaId ?? null,
            'userPk' => $order->userPk ?? null,
        ];

        $queueKey = env('MQTT_QUEUE_KEY', env('REDIS_QUEUE_KEY', 'mqtt:publish'));
        $chunkSize = (int) env('PING_BATCH_PROCESS_CHUNK_SIZE', 80);
        $chunks = array_chunk($userIds, max(1, $chunkSize));

        try {
            $redis = app('redis')->connection();
            $total = 0;
            foreach ($chunks as $chunk) {
                $jobs = [];
                $suppressed = 0;
                foreach ($chunk as $uid) {
                    $topic = "orders/{$uid}";
                    $jobArray = [
                        'topic' => $topic,
                        'payload' => $payload,
                        'qos' => 0,
                        'retain' => false,
                        'meta' => ['enqueued_at' => time(), 'batch_id' => $this->batchId]
                    ];

                    $encodedJob = json_encode($jobArray);

                    // Apply short-window dedupe (same key used by MqttPublisherRedis)
                    $dedupeTtl = (int) env('MQTT_RECENT_PUBLISH_TTL', 3);
                    $dedupeKey = 'mqtt:recent_publish:' . md5($topic . '|' . $encodedJob);
                    $shouldEnqueue = true;
                    try {
                        $wasSet = $redis->setnx($dedupeKey, time());
                        if ($wasSet) {
                            $redis->expire($dedupeKey, $dedupeTtl);
                        } else {
                            $shouldEnqueue = false;
                        }
                    } catch (\Throwable $e) {
                        // If dedupe check fails for any reason, fall back to enqueue (best-effort)
                        $shouldEnqueue = true;
                    }

                    if ($shouldEnqueue) {
                        $jobs[] = $encodedJob;
                    } else {
                        $suppressed++;
                    }
                }

                if (!empty($jobs)) {
                    $redis->pipeline(function ($pipe) use ($queueKey, $jobs) {
                        foreach ($jobs as $job) {
                            $pipe->rpush($queueKey, $job);
                        }
                    });
                }

                $total += count($jobs);
                if ($suppressed > 0) {
                    Log::info('[PublishPendingActionsBatchJob] suppressed duplicate pending publishes', ['order_id' => $order->id, 'suppressed' => $suppressed]);
                }
            }

            Log::info('[PublishPendingActionsBatchJob] published pending actions', ['order_id' => $order->id, 'published' => $total]);
        } catch (\Throwable $e) {
            Log::error('[PublishPendingActionsBatchJob] publish failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
