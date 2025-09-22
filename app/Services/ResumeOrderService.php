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
        Log::error('[ResumeOrderService] handle start', [
            'user_id' => $user->id ?? null,
            'order_id' => $order->id ?? null,
            'order_type' => $order->type ?? null
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
        Log::error('[ResumeOrderService] batchInsertPendingAction start', [
            'order_id' => $order->id ?? null,
            'user_ids_count' => count($userIds)
        ]);

        // Delegate batch insertion to the centralized BatchActionService
        $batchService = app(\App\Services\BatchActionService::class);
        return $batchService->batchInsertPendingAction($order, $userIds);
    }

    // performBatchInsert moved to BatchActionService

    public function checkUserEligibility(Order $order, User $user): bool
    {
        Log::error('[ResumeOrderService] checkUserEligibility start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);

        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    public function getEligibleUsers(Order $order)
    {
        Log::error('[ResumeOrderService] getEligibleUsers start', [
            'order_id' => $order->id ?? null,
            'target_url' => $order->target_url ?? null
        ]);

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
        Log::error('[ResumeOrderService] createPendingActionForUser start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);
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
        Log::error('[ResumeOrderService] publishOrderAnnouncement start', [
            'order_id' => $orderId ?? null,
            'user_id' => $userId ?? null,
            'type' => $type ?? null,
            'url' => $url ?? null
        ]);
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
        Log::error('[ResumeOrderService] publishOrderAnnouncement Data',$payloadArray);

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

    private function dispatchMqttJob(Order $order, User $user): void
    {
        Log::error('[ResumeOrderService] dispatchMqttJob start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);
        dispatch(new SendMqttToUserJob(
            $user->id,
            $order->id,
            $order->type,
            $order->target_url
        ));
    }
    private function sendMqttToEligibleUsersWithPing(Order $order, $remaining): void
    {
        Log::error('[ResumeOrderService] sendMqttToEligibleUsersWithPing start', [
            'order_id' => $order->id ?? null,
            'remaining' => $remaining ?? null
        ]);
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
        Log::error('[ResumeOrderService] sendMqttPing start', [
            'order_id' => $order->id ?? null
        ]);
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
        Log::error('[ResumeOrderService] publishToMqtt start', [
            'topic' => $topic ?? null,
            'data_sample' => is_array($data) ? array_slice($data,0,5) : null
        ]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }
}
