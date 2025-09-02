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
                // Re-dispatch job for pending action immediately
                dispatch(new SendMqttToUserJob($user->id, $order->id, $order->type, $order->target_url));
                return ['message' => 'Pending action re-dispatched for this user.'];
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

        // Check if action already exists for this user-order combination
        $existingAction = DB::table('actions')
            ->select('status')
            ->where('user_id', $user->id)
            ->where('order_id', $order->id)
            ->first();

        if ($existingAction) {
            if ($existingAction->status === 'pending') {
                // Re-dispatch job for pending action immediately
                dispatch(new SendMqttToUserJob($user->id, $order->id, $order->type, $order->target_url));
                return ['message' => 'Pending action re-dispatched for this user.'];
            }
            // Block if status is done or external
            if (in_array($existingAction->status, ['done', 'external'])) {
                return ['error' => 'User already completed or has external action for this order.'];
            }
            return ['message' => 'Action already exists for this user and order.'];
        }

        try {
            DB::table('actions')->insert([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Dispatch job immediately
            dispatch(new SendMqttToUserJob($user->id, $order->id, $order->type, $order->target_url));

            return ['message' => 'User processed successfully.'];
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
                        dispatch(new SendMqttToUserJob($user->id, $order->id, $order->type, $order->target_url));
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

    public function checkUserEligibility(Order $order, User $user): bool
    {
        $eligibleUsers = $this->getEligibleUsers($order);

        // // Debugging: Log eligible users
        // Log::info('[ResumeOrderService] Eligible users for order', [
        //     'order_id' => $order->id,
        //     'eligible_users' => $eligibleUsers->pluck('id')->toArray(),
        // ]);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Debugging: Log user eligibility check
        $isEligible = in_array($user->id, $eligibleUserIds);
        // Log::info('[ResumeOrderService] User eligibility check', [
        //     'user_id' => $user->id,
        //     'is_eligible' => $isEligible,
        // ]);

        // Check if the user ID exists in the eligible user IDs
        return $isEligible;
    }

    private function getEligibleUsers(Order $order)
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

        $remaining = $order->total_count - $actualDoneCount;

        if ($remaining <= 0) {
            // // Debugging: Log no remaining actions
            // Log::info('[ResumeOrderService] No remaining actions for order', [
            //     'order_id' => $order->id,
            // ]);

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

        // // Debugging: Log eligible users fetched
        // Log::info('[ResumeOrderService] New eligible users fetched', [
        //     'order_id' => $order->id,
        //     'eligible_users' => $eligibleUsers->pluck('id')->toArray(),
        // ]);

        // Combine pending and new eligible users
        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();
        $combinedUsers = $pendingUsers->merge($eligibleUsers);

        // // Debugging: Log combined users
        // Log::info('[ResumeOrderService] Combined eligible users', [
        //     'order_id' => $order->id,
        //     'combined_users' => $combinedUsers->pluck('id')->toArray(),
        // ]);

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
            DB::table('actions')->insert([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    public function resume(Order $order): array
    {
        $user = auth()->user();

        if (!$user) {
            return ['error' => 'User not authenticated.'];
        }

        // ❌ Apply 12-hour cooldown ONLY for non-admins
        if ($user->type !== 'admin' && $order->updated_at->diffInHours(now()) < 12) {
            return [
                'error' => 'Cannot resume order. Please wait 12 hours before trying again.',
                'hours_remaining' => 12 - $order->updated_at->diffInHours(now()),
            ];
        }


        // Re-send to pending users using ping validation (only fresh pending actions)
        $pendingUserIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->toArray();

        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();

        // Recalculate actual done count from database
        $actualDoneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        $remaining = $order->total_count - $actualDoneCount;
        if ($remaining <= 0) {
            // Only send ping for pending users if there are any and no remaining work
            if ($pendingUsers->count() > 0) {
                $this->sendMqttToEligibleUsersWithPing($order, $pendingUsers->count());
            }
            return [
                'message' => 'No remaining actions needed.',
                'pending_resend_count' => count($pendingUsers),
                'new_eligible_count' => 0
            ];
        }

        // Select new eligible users
        $eligibleUsers = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')->from('actions')->where('order_id', $order->id);
            })
            ->whereNotIn('id', $pendingUserIds)
            ->where('profile_link', '!=', $order->target_url)
            ->whereNotIn('id', function ($sub) use ($order) {
                $sub->select('a1.user_id')
                    ->from('actions as a1')
                    ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                    ->where('a1.status', 'done')
                    ->whereColumn('o1.target_url', 'users.profile_link')
                    ->where('o1.user_id', $order->user_id);
            })
            ->limit($remaining)
            ->get();

        // Insert actions with duplicate protection
        $now = now();
        $actions = $eligibleUsers->map(function ($user) use ($order, $now) {
            return [
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });

        // Use try-catch to handle any remaining duplicate constraint violations
        try {
            DB::table('actions')->insert($actions->toArray());
        } catch (\Illuminate\Database\QueryException $e) {
            // If duplicate entry error, log it but continue (1062 is duplicate entry error)
            if ($e->getCode() === '23000' && strpos($e->getMessage(), '1062') !== false) {
                \Log::warning('[ResumeOrderService] Duplicate action detected during bulk insert for order ' . $order->id, ['error' => $e->getMessage()]);
            } else {
                throw $e; // Re-throw if it's a different error
            }
        }

        // Send ONE ping for the order (covers both pending and new users)
        $this->sendMqttToEligibleUsersWithPing($order, $remaining);

        $order->touch();

        return [
            'message' => 'Order resumed successfully.',
            'pending_resend_count' => count($pendingUsers),
            'new_eligible_count' => count($eligibleUsers)
        ];
    }

    private function dispatchMqttJob(Order $order, User $user): void
    {
        dispatch(new SendMqttToUserJob(
            $user->id,
            $order->id,
            $order->type,
            $order->target_url
        ));
    }    private function sendMqttToEligibleUsersWithPing(Order $order, $remaining): void
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
