<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ResumeOrderService
{
    public function resume(Order $order): array
    {
        // Prevent resume if less than 12 hours passed since last update
        if ($order->updated_at->diffInHours(now()) < 12) {
            throw new \Exception('Cannot resume order. Please wait 12 hours before trying again.');
        }

        $pendingUserIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->toArray();

        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();

        foreach ($pendingUsers as $user) {
            $this->sendMqtt($order, $user);
        }

        // Limit the number of new users based on remaining actions
        $remaining = $order->total_count - $order->done_count - count($pendingUserIds);

        if ($remaining <= 0) {
            return [
                'message' => 'No remaining actions needed.',
                'pending_resend_count' => count($pendingUsers),
                'new_eligible_count' => 0
            ];
        }

        $eligibleUsers = User::where('type', 'user')
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

        foreach ($eligibleUsers as $user) {
            $this->sendMqtt($order, $user);
        }

        $order->touch();

        return [
            'message' => 'Order resumed successfully.',
            'pending_resend_count' => count($pendingUsers),
            'new_eligible_count' => count($eligibleUsers)
        ];
    }

    private function sendMqtt(Order $order, User $user): void
    {
        $payload = [
            'user_id' => $user->id,
            'url' => $order->target_url,
            'order_id' => $order->id,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $escaped = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');
        $command = "node {$scriptPath} {$escaped}";

        // Debug log
        \Log::info("[sendMqtt] Executing: {$command}");

        // Capture output and errors
        $output = [];
        $resultCode = null;
        exec($command . " 2>&1", $output, $resultCode);

        \Log::info("[sendMqtt] Output: " . implode("\n", $output));
        \Log::info("[sendMqtt] Exit code: {$resultCode}");

        if ($resultCode !== 0) {
            \Log::error("[sendMqtt] MQTT publish failed for user #{$user->id}");
        }
    }
}
