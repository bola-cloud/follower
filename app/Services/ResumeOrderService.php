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

        // Use ping validation for pending users too
        if ($pendingUsers->count() > 0) {
            $this->sendMqttToEligibleUsersWithPing($order, $pendingUsers, $pendingUsers->count());
        }

        // Recalculate actual done count from database
        $actualDoneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        $remaining = $order->total_count - $actualDoneCount;
        if ($remaining <= 0) {
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

        // Use ping validation system instead of direct dispatch
        $this->sendMqttToEligibleUsersWithPing($order, $eligibleUsers, $remaining);

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

    private function sendMqttToEligibleUsersWithPing(Order $order, $eligibleUsers, $remaining): void
    {
        // Send to Node.js via MQTT for ping validation
        $orderData = [
            'order_id' => $order->id,
            'total_count' => $remaining,
            'eligible_users' => $eligibleUsers->map(function($user) {
                return ['id' => $user->id, 'profile_link' => $user->profile_link];
            })->toArray()
        ];

        // Use MQTT to send order data to Node.js processor
        $this->publishToMqtt('order/process/request', $orderData);

        Log::info("[ResumeOrderService] Sent resumed order {$order->id} to Node.js processor via MQTT with " . count($eligibleUsers) . " eligible users");
    }

    private function publishToMqtt($topic, $data)
    {
        $scriptPath = base_path('node_scripts/mqtt_order_processor.cjs');
        $logFile = storage_path('logs/mqtt_order_processor.log');
        $command = "node {$scriptPath} >> {$logFile} 2>&1 &";
        exec($command);
    }
}
