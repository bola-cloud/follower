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
        if (!$this->checkUserEligibility($order, $user)) {
            return ['error' => 'User is not eligible for this order.'];
        }

        // Create action for the user using existing logic
        $this->createPendingActionForUser($order, $user);

        // Dispatch job
        dispatch(new SendMqttToUserJob($user->id, $order->id, $order->type, $order->target_url));

        return ['message' => 'User processed successfully for resume.'];
    }

    private function checkUserEligibility(Order $order, User $user): bool
    {
        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    private function getEligibleUsers(Order $order)
    {
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
            // Return pending users if any
            return User::whereIn('id', $pendingUserIds)->get();
        }

        // Get new eligible users
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

        // Combine pending and new eligible users
        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();
        return $pendingUsers->merge($eligibleUsers);
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

        // Re-send to pending users using ping validation
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

        // Insert actions
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

        DB::table('actions')->insert($actions->toArray());

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
    }

    private function sendMqttToEligibleUsersWithPing(Order $order, $remaining): void
    {
        $orderData = [
            'activation_order_id' => $order->id
        ];

        $this->publishToMqtt('order/ping/req', $orderData);

        Log::info("[ResumeOrderService] Sent resumed order {$order->id} directly to `order/ping/req` via MQTT");
    }

    private function sendMqttPing(Order $order): void
    {
        $pingData = [
            'order_id' => $order->id
        ];

        $this->publishToMqtt('order/ping/req', $pingData);

        Log::info("[ResumeOrderService] Sent ping for resumed order {$order->id} to `order/ping/req` via MQTT");
    }

    private function publishToMqtt($topic, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }
}
