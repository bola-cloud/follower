<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        return $query->get();
    }

    private function createPendingActions(Order $order, $eligibleUsers)
    {
        // Use optimized batch service for better connection management
        $batchService = app(\App\Services\BatchDatabaseService::class);

        try {
            $now = now();
            $userIds = $eligibleUsers->pluck('id')->toArray();

            // Use batch service for optimized insertion
            $inserted = $batchService->createOrderActions($order, $userIds);

            Log::info('[OrderService] Batch pending actions created', [
                'order_id' => $order->id,
                'eligible_users_count' => count($userIds),
                'inserted' => $inserted
            ]);

            // Dispatch per-user MQTT announcement jobs so each user receives `orders/{user_id}`
            // This ensures the MQTT handler records known orders and devices can respond to pings.
            if (!empty($userIds)) {
                // First, synchronously announce to a small chunk so the MQTT
                // handler can record KNOWN_ORDERS before we send the ping.
                // This mirrors ResumeOrderService which publishes before pinging.
                $syncChunk = array_slice($userIds, 0, 50);
                foreach ($syncChunk as $uid) {
                    try {
                        // Publish directly and wait (use the same synchronous publisher)
                        $this->publishOrderAnnouncement($uid, $order->id, $order->type, $order->target_url);
                    } catch (\Throwable $je) {
                        Log::warning('[OrderService] Failed to publish synchronous order announcement', [
                            'order_id' => $order->id,
                            'user_id' => $uid,
                            'error' => $je->getMessage()
                        ]);
                    }
                }

                // Dispatch the rest asynchronously to the high-priority queue
                $remaining = array_slice($userIds, count($syncChunk));
                $chunks = array_chunk($remaining, 200);
                foreach ($chunks as $chunk) {
                    foreach ($chunk as $uid) {
                        try {
                            dispatch(new \App\Jobs\SendMqttToUserJob($uid, $order->id, $order->type, $order->target_url));
                        } catch (\Throwable $je) {
                            Log::warning('[OrderService] Failed to dispatch SendMqttToUserJob', [
                                'order_id' => $order->id,
                                'user_id' => $uid,
                                'error' => $je->getMessage()
                            ]);
                        }
                    }
                }

                Log::info('[OrderService] Synchronously announced to first chunk and dispatched SendMqttToUserJob for remaining users', [
                    'order_id' => $order->id,
                    'sync_announced' => count($syncChunk),
                    'dispatched_count' => count($remaining)
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
     * 🔄 SYNCHRONOUS ORDER ANNOUNCEMENT
     * Publish order to users immediately (not queued) to ensure MQTT handler
     * knows about the order before devices respond to pings
     */
    private function publishOrderAnnouncement($userId, $orderId, $type, $url)
    {
        $payloadArray = [
            'user_id' => $userId,
            'url' => $url,
            'order_id' => $orderId,
            'type' => $type,
        ];

        $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        // Execute synchronously and capture output for debugging
        $command = "node {$scriptPath} {$escapedJson} 2>&1";
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            // Primary attempt failed (node script timeout or error). Log and fall back to a non-blocking publisher
            Log::warning('Failed to publish order announcement', [
                'user_id' => $userId,
                'order_id' => $orderId,
                'exit_code' => $exitCode,
                'output' => implode("\n", $output)
            ]);

            // Fallback: launch the same node publisher in background (non-blocking). This avoids long synchronous timeouts
            $bgCommand = "node {$scriptPath} {$escapedJson} > /dev/null 2>&1 &";
            @exec($bgCommand);

            Log::warning('Fallback publisher launched in background', [
                'user_id' => $userId,
                'order_id' => $orderId,
                'bg_command' => $bgCommand
            ]);
        } else {
            Log::info('Order announcement published', [
                'user_id' => $userId,
                'order_id' => $orderId,
                'output' => implode("\n", $output)
            ]);
        }
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

        try {
            // Create action without individual announcement to prevent MQTT duplication
            // Actions should be announced in batch during order creation, not per user

            // Retry logic for lock timeouts with exponential backoff
            $maxRetries = 3;
            $inserted = 0;

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                try {
                    // Use shorter lock timeout for action inserts to fail faster
                    DB::statement('SET SESSION innodb_lock_wait_timeout = 5');

                    $inserted = DB::table('actions')->insertOrIgnore([
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'type' => $order->type,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    // Restore default timeout
                    DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
                    break; // Success, exit retry loop

                } catch (\Illuminate\Database\QueryException $e) {
                    // Check if it's a lock timeout error (1205)
                    if ($e->getCode() === 'HY000' && strpos($e->getMessage(), '1205') !== false) {
                        if ($attempt < $maxRetries) {
                            $delay = pow(2, $attempt - 1) * 100000; // 100ms, 200ms, 400ms (microseconds)
                            \Log::warning('[OrderService] Lock timeout, retrying', [
                                'attempt' => $attempt,
                                'delay_ms' => $delay / 1000,
                                'order_id' => $order->id,
                                'user_id' => $user->id
                            ]);
                            usleep($delay);
                            continue;
                        }
                        // Max retries exceeded, log and rethrow
                        \Log::error('[OrderService] Lock timeout after max retries', [
                            'order_id' => $order->id,
                            'user_id' => $user->id,
                            'attempts' => $maxRetries
                        ]);
                    }
                    throw $e; // Re-throw non-timeout errors or after max retries
                }
            }

            if ($inserted === 0) {
                // Another worker likely inserted the same action concurrently. Fetch and respond accordingly.
                $existingAction = DB::table('actions')
                    ->select('status')
                    ->where('order_id', $order->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingAction) {
                    if ($existingAction->status === 'pending') {
                        return ['message' => 'Pending action already exists for this user.'];
                    }
                    if (in_array($existingAction->status, ['done', 'external'])) {
                        return ['error' => 'User already completed or has external action for this order.'];
                    }
                    return ['error' => 'Action already exists for this user.'];
                }

                // If no existing action found after an ignored insert, fall through to a generic response
                return ['error' => 'Failed to create action due to concurrent activity.'];
            }

            return ['message' => 'User processed successfully.'];
        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate entry error code from MySQL is 1062 (SQLSTATE 23000)
            if ($e->getCode() === '23000' && strpos($e->getMessage(), '1062') !== false) {
                // Log the race condition for debugging
                \Log::warning('[OrderService] Race condition detected - duplicate action inserted concurrently', [
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
                        return ['message' => 'Pending action already exists for this user.'];
                    }
                    if (in_array($existingAction->status, ['done', 'external'])) {
                        return ['error' => 'User already completed or has external action for this order.'];
                    }
                    return ['error' => 'Action already exists for this user.'];
                }
            }

            // Not a duplicate or unexpected: rethrow so triggerOrder can log and handle
            throw $e;
        }
    }
}
