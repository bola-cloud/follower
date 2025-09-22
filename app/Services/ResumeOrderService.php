<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendMqttToUserJob;
use Carbon\Carbon;

class ResumeOrderService
{
    public function handle(Order $order, User $user): array
    {
        // Focused info logging for resume processing
        Log::info('[ResumeOrderService] resume start', [
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

        // if ($existingAction) {
        //     if ($existingAction->status === 'pending') {
        //         // Re-publish order announcement immediately
        //         $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
        //         return ['message' => 'Pending action re-dispatched for this user.'];
        //     }
        //     return ['error' => 'Action already exists for this user.'];
        // }

        // Create the action - no lock needed since triggerOrder already validated slots
        // Add a final safety check to prevent exceeding total_count (count done + recent pending only)
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

        // Check if action already exists for this user-order combination
        // $existingAction = DB::table('actions')
        //     ->select('status')
        //     ->where('user_id', $user->id)
        //     ->where('order_id', $order->id)
        //     ->first();

        if ($existingAction) {
            if ($existingAction->status === 'pending') {
                // Dispatch queued MQTT publish job instead of synchronous publish
                // Trace log for produced publish attempt
                Log::error('[ResumeOrderService] dispatching publish for existing pending action [TRACE]', ['order_id' => $order->id, 'user_id' => $user->id]);
                $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                return ['message' => 'Pending action re-dispatched for this user.'];
            }
            // Block if status is done or external
            if (in_array($existingAction->status, ['done', 'external'])) {
                return ['error' => 'User already completed or has external action for this order.'];
            }
            return ['message' => 'Action already exists for this user and order.'];
        }

        try {
            // Create action using high-performance batch system
            $result = $this->batchInsertPendingAction($order, [$user->id]);

                if ($result['inserted'] > 0) {
                    // Trace log for produced publish attempt
                    Log::error('[ResumeOrderService] publish job enqueued after insertion [TRACE]', ['order_id' => $order->id, 'user_id' => $user->id]);
                    // Dispatch queued MQTT publish job after successful insertion
                    $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                    return ['message' => 'User processed successfully.'];
                } else {
                    return ['message' => 'Action already exists or was handled concurrently.'];
                }

        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate entry error code from MySQL is 1062 (SQLSTATE 23000)
            if ($e->getCode() === '23000' && strpos($e->getMessage(), '1062') !== false) {
                // Log the race condition for debugging
                \Log::warning('[ResumeOrderService] Race condition detected - duplicate action inserted concurrently', [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);

                // Race: someone else inserted the action concurrently. Fetch it and apply the same decision logic.
                $existingAction = DB::table('actions')
                    ->select('status')
                    ->where('order_id', $order->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingAction) {
                    if ($existingAction->status === 'pending') {
                        $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                        return ['message' => 'Pending action re-dispatched for this user.'];
                    }
                    if (in_array($existingAction->status, ['done', 'external'])) {
                        return ['error' => 'User already completed or has external action for this order.'];
                    }
                    return ['message' => 'Action already exists for this user and order.'];
                }
            }

            // Not a duplicate or unexpected: rethrow
            throw $e;
        }
    }

    /**
     * High-performance batch insertion system for pending actions
     * Handles up to 5000+ actions per minute with chunking and connection optimization
     */
    public function batchInsertPendingAction(Order $order, array $userIds): array
    {
        // Delegate batch insertion to the centralized BatchActionService
        $batchService = app(\App\Services\BatchActionService::class);
        return $batchService->batchInsertPendingAction($order, $userIds);
    }

    // performBatchInsert moved to BatchActionService

    public function checkUserEligibility(Order $order, User $user): bool
    {
        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    public function getEligibleUsers(Order $order)
    {
        // // Debugging: Log order details
        // Log::info('[ResumeOrderService] Fetching eligible users for order', [
        //     'order_id' => $order->id,
        //     'target_url' => $order->target_url,
        // ]);

        // Get pending users and new eligible users similar to resume method
        $pendingUserIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->toArray();

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
            // Return pending users if any
            return User::whereIn('id', $pendingUserIds)->get();
        }

        // Get new eligible users
        $eligibleUsers = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')->from('actions')->where('order_id', $order->id)->whereIn('status', ['done', 'external']);
            })
            ->whereNotIn('id', $pendingUserIds)
            ->where('profile_link', '!=', $order->target_url)
            ->whereNotIn('id', function ($sub) use ($order) {
                $sub->select('a1.user_id')
                    ->from('actions as a1')
                    ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                    ->whereIn('a1.status', ['done', 'external'])
                    ->whereColumn('o1.target_url', 'users.profile_link')
                    ->where('o1.user_id', $order->user_id);
            })
            // ->limit($remaining)
            ->get();

        // Combine pending and new eligible users
        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();
        $combinedUsers = $pendingUsers->merge($eligibleUsers);

        return $combinedUsers;
    }

    private function createPendingActionForUser(Order $order, User $user): void
    {
        // Check if action already exists
        $existingAction = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$existingAction) {
            DB::table('actions')->insertOrIgnore([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    // public function resume(Order $order): array
    // {
    //     $user = auth()->user();

    //     if (!$user) {
    //         return ['error' => 'User not authenticated.'];
    //     }

    //     // ❌ Apply 12-hour cooldown ONLY for non-admins
    //     if ($user->type !== 'admin' && $order->updated_at->diffInHours(now()) < 12) {
    //         return [
    //             'error' => 'Cannot resume order. Please wait 12 hours before trying again.',
    //             'hours_remaining' => 12 - $order->updated_at->diffInHours(now()),
    //         ];
    //     }


    //     // Re-send to pending users using ping validation (only fresh pending actions)
    //     $pendingUserIds = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'pending')
    //         ->pluck('user_id')
    //         ->toArray();

    //     $pendingUsers = User::whereIn('id', $pendingUserIds)->get();

    //     // Recalculate actual done count and recent pending actions from database
    //     $actualDoneCount = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'done')
    //         ->count();

    //     // Count pending actions created within the past 30 minutes
    //     $recentPendingCount = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'pending')
    //         ->where('created_at', '>=', now()->subMinutes(30))
    //         ->count();

    //     $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;
    //     if ($remaining <= 0) {
    //         // Only send ping for pending users if there are any and no remaining work
    //         if ($pendingUsers->count() > 0) {
    //             $this->sendMqttToEligibleUsersWithPing($order, $pendingUsers->count());
    //         }
    //         return [
    //             'message' => 'No remaining actions needed.',
    //             'pending_resend_count' => count($pendingUsers),
    //             'new_eligible_count' => 0
    //         ];
    //     }

    //     // Select new eligible users
    //     $eligibleUsers = User::where('type', 'user')
    //         ->orderBy('id', 'desc')
    //         ->whereNotIn('id', function ($q) use ($order) {
    //             $q->select('user_id')->from('actions')->where('order_id', $order->id);
    //         })
    //         ->whereNotIn('id', $pendingUserIds)
    //         ->where('profile_link', '!=', $order->target_url)
    //         ->whereNotIn('id', function ($sub) use ($order) {
    //             $sub->select('a1.user_id')
    //                 ->from('actions as a1')
    //                 ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
    //                 ->where('a1.status', 'done')
    //                 ->whereColumn('o1.target_url', 'users.profile_link')
    //                 ->where('o1.user_id', $order->user_id);
    //         })
    //         ->limit($remaining)
    //         ->get();

    //     // Insert actions with duplicate protection
    //     $now = now();
    //     $actions = $eligibleUsers->map(function ($user) use ($order, $now) {
    //         return [
    //             'order_id' => $order->id,
    //             'user_id' => $user->id,
    //             'type' => $order->type,
    //             'status' => 'pending',
    //             'created_at' => $now,
    //             'updated_at' => $now,
    //         ];
    //     });

    //     // Use optimized batch service for better connection management
    //     $batchService = app(\App\Services\BatchDatabaseService::class);

    //     try {
    //         $userIds = $eligibleUsers->pluck('id')->toArray();

    //         // Use batch service for optimized insertion
    //         $inserted = $batchService->createOrderActions($order, $userIds);

    //         Log::info('[ResumeOrderService] Batch actions created', [
    //             'order_id' => $order->id,
    //             'attempted' => count($userIds),
    //             'inserted' => $inserted
    //         ]);

    //     } catch (\Throwable $e) {
    //         Log::error('[ResumeOrderService] Failed batch insert of actions', [
    //             'order_id' => $order->id,
    //             'error' => $e->getMessage()
    //         ]);
    //         throw $e;
    //     }

    //     // Send ONE ping for the order (covers both pending and new users)
    //     $this->sendMqttToEligibleUsersWithPing($order, $remaining);

    //     $order->touch();

    //     return [
    //         'message' => 'Order resumed successfully.',
    //         'pending_resend_count' => count($pendingUsers),
    //         'new_eligible_count' => count($eligibleUsers)
    //     ];
    // }

    /**
     * 🔄 SYNCHRONOUS ORDER ANNOUNCEMENT
     * Publish order to users immediately (not queued) to ensure MQTT handler
     * knows about the order before devices respond to pings
     */

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

        // $mode = env('MQTT_PUBLISH_MODE', 'sync');

        // // Queue-mode: try enqueueing to Redis-backed publisher first
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
        //         $pushed = \Illuminate\Support\Facades\Redis::rpush($publisherQueue, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        //         \Illuminate\Support\Facades\Redis::expire($publisherQueue, 86400);
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

    private function dispatchMqttJob(Order $order, User $user): void
    {
        dispatch(new SendMqttToUserJob(
            $user->id,
            $order->id,
            $order->type,
            $order->target_url
        ));
    }
    private function sendMqttToEligibleUsersWithPing(Order $order, $remaining): void
    {
        $orderData = [
            'type' => 'resume',
            'order_id' => $order->id,
            'activation' => true
        ];

        $this->publishToMqtt('order/ping/req', $orderData);

        // Log::info("[ResumeOrderService] Sent resumed order {$order->id} directly to `order/ping/req` via MQTT");
    }

    private function sendMqttPing(Order $order): void
    {
        $pingData = [
            'type' => 'resume',
            'order_id' => $order->id,
            'activation' => true
        ];

        $this->publishToMqtt('order/ping/req', $pingData);

        // Log::info("[ResumeOrderService] Sent ping for resumed order {$order->id} to `order/ping/req` via MQTT");
    }

    private function publishToMqtt($topic, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }
}
