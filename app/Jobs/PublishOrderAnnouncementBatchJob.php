<?php

namespace App\Jobs;

use App\Services\MqttPublisherRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * PublishOrderAnnouncementBatchJob
 * 
 * Handles chunked MQTT order announcements to avoid overwhelming the system
 * with thousands of individual publish jobs.
 * 
 * Instead of enqueuing 1000+ individual SendMqttToUserJob jobs, we chunk
 * users into batches of 80-200 and enqueue each chunk as a single job.
 * 
 * This dramatically reduces:
 * - Redis queue operations (1000 RPUSH → ~10 RPUSH)
 * - Job overhead (1000 job deserializations → ~10)
 * - Network round-trips to MQTT broker
 * 
 * Queue: 'high' (16 workers from supervisor config)
 * Timeout: 120 seconds (reasonable for 200 publishes @ ~500ms each)
 * Tries: 2 (retry once on failure)
 */
class PublishOrderAnnouncementBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Job timeout in seconds
     * 
     * @var int
     */
    public $timeout = 120;

    /**
     * Number of times job may be attempted
     * 
     * @var int
     */
    public $tries = 2;

    /**
     * Order ID
     * 
     * @var int
     */
    protected $orderId;

    /**
     * Order type (create/resume)
     * 
     * @var string
     */
    protected $orderType;

    /**
     * Target URL for the order
     * 
     * @var string
     */
    protected $targetUrl;

    /**
     * Array of user IDs to publish to
     * 
     * @var array
     */
    protected $userIds;

    /**
     * Batch identifier for tracking
     * 
     * @var string
     */
    protected $batchId;

    /**
     * Create a new job instance.
     *
     * @param int $orderId
     * @param string $orderType
     * @param string $targetUrl
     * @param array $userIds
     * @param string|null $batchId
     * @return void
     */
    public function __construct(
        int $orderId,
        string $orderType,
        string $targetUrl,
        array $userIds,
        ?string $batchId = null
    ) {
        $this->orderId = $orderId;
        $this->orderType = $orderType;
        $this->targetUrl = $targetUrl;
        $this->userIds = $userIds;
        $this->batchId = $batchId ?? uniqid('pub_', true);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $startTime = microtime(true);
        $userCount = count($this->userIds);

        Log::info('[PublishOrderAnnouncementBatchJob] Starting batch publish', [
            'batch_id' => $this->batchId,
            'order_id' => $this->orderId,
            'order_type' => $this->orderType,
            'user_count' => $userCount
        ]);

        // Check if order is paused
        try {
            $order = \App\Models\Order::find($this->orderId);
            if ($order && isset($order->status) && $order->status === 'paused') {
                Log::info('[PublishOrderAnnouncementBatchJob] Skipping - order paused', [
                    'batch_id' => $this->batchId,
                    'order_id' => $this->orderId
                ]);
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('[PublishOrderAnnouncementBatchJob] Failed to check order status', [
                'batch_id' => $this->batchId,
                'order_id' => $this->orderId,
                'error' => $e->getMessage()
            ]);
        }

        $mqttPublisher = app(MqttPublisherRedis::class);
        $successCount = 0;
        $failureCount = 0;

        // Publish to each user in the batch
        foreach ($this->userIds as $userId) {
            try {
                $topic = "orders/{$userId}";
                $payload = [
                    'order_id' => $this->orderId,
                    'type' => $this->orderType,
                    'url' => $this->targetUrl,
                ];

                // Enqueue to Redis MQTT publish queue
                $mqttPublisher->enqueue($topic, $payload);
                $successCount++;

                // Small delay between publishes to avoid overwhelming the system
                // This creates backpressure and prevents Redis/MQTT broker overload
                if ($successCount % 50 === 0) {
                    usleep(5000); // 5ms pause every 50 publishes
                }

            } catch (\Throwable $e) {
                $failureCount++;
                Log::error('[PublishOrderAnnouncementBatchJob] Failed to publish to user', [
                    'batch_id' => $this->batchId,
                    'order_id' => $this->orderId,
                    'user_id' => $userId,
                    'error' => $e->getMessage()
                ]);

                // Continue processing remaining users even if one fails
                // We don't want a single user failure to block the entire batch
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        Log::info('[PublishOrderAnnouncementBatchJob] Batch publish completed', [
            'batch_id' => $this->batchId,
            'order_id' => $this->orderId,
            'total_users' => $userCount,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'duration_ms' => $duration,
            'publishes_per_second' => $duration > 0 ? round(($successCount / $duration) * 1000, 2) : 0
        ]);

        // Track metrics in Redis
        try {
            $hour = date('YmdH');
            $redis = Redis::connection('default');
            
            $redis->hincrby("publish_batch_metrics:{$hour}", 'batches_processed', 1);
            $redis->hincrby("publish_batch_metrics:{$hour}", 'users_published', $successCount);
            $redis->hincrby("publish_batch_metrics:{$hour}", 'users_failed', $failureCount);
            $redis->hincrby("publish_batch_metrics:{$hour}", 'total_duration_ms', (int)$duration);
            
            // Expire metrics after 48 hours
            $redis->expire("publish_batch_metrics:{$hour}", 172800);
        } catch (\Throwable $e) {
            Log::warning('[PublishOrderAnnouncementBatchJob] Failed to update metrics', [
                'batch_id' => $this->batchId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('[PublishOrderAnnouncementBatchJob] Job failed', [
            'batch_id' => $this->batchId,
            'order_id' => $this->orderId,
            'user_count' => count($this->userIds),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Track failure metrics
        try {
            $hour = date('YmdH');
            $redis = Redis::connection('default');
            $redis->hincrby("publish_batch_metrics:{$hour}", 'batches_failed', 1);
            $redis->expire("publish_batch_metrics:{$hour}", 172800);
        } catch (\Throwable $e) {
            // Silent fail on metrics update
        }
    }
}
