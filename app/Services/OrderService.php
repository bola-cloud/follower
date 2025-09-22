<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;
use App\Jobs\SendMqttToUserJob;

class OrderService
{
    public function handleOrderCreated(Order $order)
    {
        try {
            // Calculate remaining slots considering recent pending actions
            $actualDoneCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'done')
                ->count();

            // Count pending actions created within the past 30 minutes
            $recentPendingCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->count();

            $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;

            if ($remaining <= 0) {
                return;
            }

            $eligibleUsers = $this->getEligibleUsers($order, $remaining);

            $this->createPendingActions($order, $eligibleUsers);
            $this->sendMqttPing($order);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function getEligibleUsers(Order $order, $limit = 0)
    {
        $order->loadMissing('user');

        $query = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->whereIn('order_id', function ($s) use ($order) {
                        $s->select('id')->from('orders')->where('target_url', $order->target_url);
                    })
                    ->whereIn('status', ['done', 'external'])
                    ->whereNotExists(function ($reciprocal) use ($order) {
                        $reciprocal->select(DB::raw(1))
                            ->from('actions as a2')
                            ->join('orders as o2', 'a2.order_id', '=', 'o2.id')
                            ->whereColumn('a2.user_id', 'actions.user_id')
                            ->whereIn('a2.status', ['done', 'external'])
                            ->where('o2.user_id', $order->user_id)
                            ->whereColumn('o2.target_url', 'users.profile_link');
                    });
            })
            ->where('profile_link', '!=', $order->target_url);

        // if ($limit > 0) {
        //     $query->limit($limit);
        // }

        $result = $query->get();

        return $result;
    }

    private function createPendingActions(Order $order, $eligibleUsers)
    {
        if ($eligibleUsers->isEmpty()) {
            return;
        }

        // Use the centralized BatchActionService for consistent action creation
        $batchService = app(\App\Services\BatchActionService::class);

        try {
            $userIds = $eligibleUsers->pluck('id')->toArray();

            // Use batch service for optimized insertion
            $result = $batchService->batchInsertPendingAction($order, $userIds);

            Log::info('[OrderService] Batch pending actions created', [
                'order_id' => $order->id,
                'eligible_users_count' => count($userIds),
                'inserted' => $result['inserted'],
                'skipped' => $result['skipped']
            ]);

            // After actions are created, publish order announcements to inform MQTT handler
            // This ensures devices know about the order before they receive pings
            if ($result['inserted'] > 0) {
                // Publish announcements to first 50 users synchronously
                // This ensures MQTT handler records known orders before ping is sent
                $syncChunk = array_slice($userIds, 0, 50);
                foreach ($syncChunk as $uid) {
                    try {
                        $this->publishOrderAnnouncement($uid, $order->id, $order->type, $order->target_url);
                    } catch (\Throwable $je) {
                        Log::warning('[OrderService] Failed to publish synchronous order announcement', [
                            'order_id' => $order->id,
                            'user_id' => $uid,
                            'error' => $je->getMessage()
                        ]);
                    }
                }

                Log::info('[OrderService] Synchronously announced order to initial users', [
                    'order_id' => $order->id,
                    'announced_count' => count($syncChunk)
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('[OrderService] Failed to create batch pending actions', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'eligible_users_count' => $eligibleUsers->count(),
            ]);
            throw $e;
        }
    }

    /**
     * 🔄 UNIFIED ORDER ANNOUNCEMENT PUBLISHER
     * Publish order to users with queue-first approach and robust fallbacks
     */
    private function publishOrderAnnouncement($userId, $orderId, $type, $url)
    {
        // Defensive order paused check
        try {
            $order = \App\Models\Order::find($orderId);
            if ($order && isset($order->status) && $order->status === 'paused') {
                Log::info('[publishOrderAnnouncement] Skipping publish because order is paused', ['order_id' => $orderId, 'user_id' => $userId]);
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('[publishOrderAnnouncement] Order lookup failed, continuing', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        if (empty($userId)) {
            Log::warning('[publishOrderAnnouncement] Skipping: empty userId', ['order_id' => $orderId, 'user_id' => $userId]);
            return;
        }

        $payloadArray = [
            'user_id' => $userId,
            'url' => $url,
            'order_id' => $orderId,
            'type' => $type,
        ];

        $jsonPayload = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            $jsonErr = json_last_error_msg();
            Log::error('[publishOrderAnnouncement] json_encode failed [TRACE]', ['error' => $jsonErr, 'order_id' => $orderId, 'user_id' => $userId, 'payload' => $payloadArray]);
            return;
        }

        $mode = env('MQTT_PUBLISH_MODE', 'sync');

        // Queue-mode: try enqueueing to Redis-backed publisher first
        // if ($mode === 'queue') {
        //     try {
        //         $publisher = app(\App\Services\MqttPublisherRedis::class);
        //         $enqueued = false;
        //         try {
        //             $enqueued = (bool)$publisher->enqueue("orders/{$userId}", $payloadArray, 0, false);
        //         } catch (\Throwable $inner) {
        //             Log::warning('[publishOrderAnnouncement] MqttPublisherRedis->enqueue threw, will fallback to sync', ['error' => $inner->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
        //         }

        //         if ($enqueued) {
        //             // Short, high-signal trace
        //             Log::error('[publishOrderAnnouncement] Enqueued publish job (queue mode) [TRACE]', ['order_id' => $orderId, 'user_id' => $userId]);
        //             return;
        //         }

        //         // If enqueue failed or returned false, we'll fall back to sync below
        //         Log::error('[publishOrderAnnouncement] enqueue returned false or failed; falling back to sync [TRACE]', ['order_id' => $orderId, 'user_id' => $userId]);
        //     } catch (\Throwable $e) {
        //         Log::warning('[publishOrderAnnouncement] Failed to resolve MqttPublisherRedis, falling back to sync', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
        //     }
        // }

        // Synchronous publish using node script (captures output + duration)
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');
        $escapedJson = escapeshellarg($jsonPayload);
        $command = "node {$scriptPath} {$escapedJson} 2>&1";
        $output = [];
        $exitCode = 0;
        $start = microtime(true);
        exec($command, $output, $exitCode);
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        Log::error('[publishOrderAnnouncement] publish command executed [TRACE]', [
            'order_id' => $orderId,
            'user_id' => $userId,
            'command' => $command,
            'duration_ms' => $durationMs,
            'exit_code' => $exitCode
        ]);

        // if ($exitCode !== 0) {
        //     $outputText = implode("\n", $output);
        //     Log::error('[publishOrderAnnouncement] Sync publish failed [TRACE]', [
        //         'order_id' => $orderId,
        //         'user_id' => $userId,
        //         'exit_code' => $exitCode,
        //         'output' => $outputText
        //     ]);

        //     // Fallback: enqueue to persistent Redis publisher list
        //     $publisherQueue = env('MQTT_QUEUE_KEY', 'mqtt:publish');
        //     $job = [
        //         'topic' => "orders/{$userId}",
        //         'payload' => $payloadArray,
        //         'qos' => 0,
        //         'retain' => false,
        //         'meta' => [
        //             'order_id' => $orderId,
        //             'attempts' => 0,
        //             'enqueued_at' => time(),
        //         ]
        //     ];

        //     try {
        //         $pushed = Redis::rpush($publisherQueue, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        //         Redis::expire($publisherQueue, 86400);
        //         Log::error('[publishOrderAnnouncement] Enqueued publish job to persistent publisher (fallback) [TRACE]', ['publisher_queue' => $publisherQueue, 'pushed' => $pushed, 'job' => $job]);
        //         return;
        //     } catch (\Throwable $e) {
        //         // Final fallback: spawn background node process so we don't lose the publish
        //         $bgCommand = "node {$scriptPath} {$escapedJson} > /dev/null 2>&1 &";
        //         @exec($bgCommand);
        //         Log::error('[publishOrderAnnouncement] Background publish spawned after Redis fallback failure [TRACE]', [
        //             'order_id' => $orderId,
        //             'user_id' => $userId,
        //             'bg_command' => $bgCommand,
        //             'error' => $e->getMessage()
        //         ]);
        //         return;
        //     }
        // }

        // Success path
        Log::error('[publishOrderAnnouncement] Order announcement published successfully [TRACE]', [
            'order_id' => $orderId,
            'user_id' => $userId,
            'output' => implode("\n", $output)
        ]);
    }

    /**
     * Public wrapper to allow controlled invocation of the publisher from controllers or tests.
     * This calls the existing private publisher and returns a simple result array.
     */
    public function publishAnnouncementPublic(int $userId, int $orderId, string $type, string $url): array
    {
        try {
            $this->publishOrderAnnouncement($userId, $orderId, $type, $url);
            return ['success' => true, 'message' => 'Publish attempted'];
        } catch (\Throwable $e) {
            Log::error('[OrderService] publishAnnouncementPublic failed', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function sendMqttPing(Order $order)
    {
        static $sentOrders = [];

        if (in_array($order->id, $sentOrders)) {
            // Log::info("[OrderService] Ping for order {$order->id} already sent, skipping.");
            return;
        }

        $sentOrders[] = $order->id;

        $pingData = [
            'type' => $order->type ?? 'create',
            'order_id' => $order->id,
            'activation' => true
        ];

        $this->publishToMqtt('order/ping/req', $pingData);
        // Log::info("[OrderService] Sent ping for order {$order->id} to `order/ping/req` via MQTT");
    }

    private function publishToMqtt($topic, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }

    private function checkUserEligibility(Order $order, User $user): bool
    {
        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    public function handle(Order $order, User $user): array
    {
        // Clear, focused logging for create operation
        \Log::info('[OrderService] handle start', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_type' => $order->type
        ]);
        // No transaction or lock needed here - triggerOrder already validated slots and eligibility
        // Just check basic eligibility and create the action

        if (!$this->checkUserEligibility($order, $user)) {
            return ['error' => 'User is not eligible for this order.'];
        }

        // Check if action already exists for this user (select only status)
        $existingAction = DB::table('actions')
            ->select('status')
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingAction) {
            if ($existingAction->status === 'pending') {
                // Don't re-publish - user already has pending announcement from batch creation
                return ['message' => 'Pending action already exists for this user.'];
            }
            // Block if status is done or external
            if (in_array($existingAction->status, ['done', 'external'])) {
                return ['error' => 'User already completed or has external action for this order.'];
            }
            return ['error' => 'Action already exists for this user.'];
        }

        // Create the action - add a final safety check to prevent exceeding total_count
        $currentActionCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where(function($query) {
                $query->where('status', 'done')
                      ->orWhere(function($subQuery) {
                          $subQuery->where('status', 'pending')
                                   ->where('created_at', '>=', now()->subMinutes(15));
                      });
            })
            ->count();

        if ($currentActionCount >= $order->total_count) {
            return ['error' => 'Order capacity reached.'];
        }

        try {
            // Use ResumeOrderService's batch inserter to create the pending action(s)
            $resumeService = app(\App\Services\ResumeOrderService::class);
            $result = $resumeService->batchInsertPendingAction($order, [$user->id]);

            if ($result['inserted'] > 0) {
                    // Add focused logging immediately before publish so we can trace topics and payloads
                    $topic = "orders/{$user->id}";
                    $pingTopic = 'order/ping/req';
                    $expectedResTopic = 'order/ping/res';
                    $payload = [
                        'user_id' => $user->id,
                        'url' => $order->target_url,
                        'order_id' => $order->id,
                        'type' => $order->type,
                    ];

                    Log::info('[OrderService] About to publish announcement from handle', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'topic' => $topic,
                        'payload' => $payload,
                        'publish_mode' => env('MQTT_PUBLISH_MODE', 'sync'),
                        'ping_topic' => $pingTopic,
                        'expected_response_topic' => $expectedResTopic,
                    ]);

                    // Publish synchronously for this user
                    $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                return ['message' => 'User processed successfully and announcement published.'];
            }

            return ['message' => 'Action already exists or was handled concurrently.'];

        } catch (\Throwable $e) {
            Log::error('[OrderService] Batch insertion via ResumeOrderService failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
