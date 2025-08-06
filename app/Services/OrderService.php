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
            $remaining = $order->total_count - $order->done_count;

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
        DB::beginTransaction();
        try {
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
            })->filter(function ($action) {
                return !DB::table('actions')
                    ->where('order_id', $action['order_id'])
                    ->where('user_id', $action['user_id'])
                    ->exists();
            })->toArray();

            DB::table('actions')->insert($actions);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function sendMqttPing(Order $order)
    {
        static $sentOrders = [];

        if (in_array($order->id, $sentOrders)) {
            Log::info("[OrderService] Ping for order {$order->id} already sent, skipping.");
            return;
        }

        $sentOrders[] = $order->id;

        $pingData = [
            'order_id' => $order->id
        ];

        $this->publishToMqtt('order/ping/req', $pingData);

        Log::info("[OrderService] Sent ping for order {$order->id} to `order/ping/req` via MQTT");
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
        return DB::transaction(function () use ($order, $user) {
            // Lock the order row for update to prevent race conditions
            $lockedOrder = Order::where('id', $order->id)->lockForUpdate()->first();

            if (!$lockedOrder) {
                return ['error' => 'Order not found.'];
            }

            // Recalculate remaining slots with fresh data
            $actualDoneCount = DB::table('actions')
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'done')
                ->count();

            $remaining = $lockedOrder->total_count - $actualDoneCount;

            if ($remaining <= 0) {
                return ['error' => 'No remaining actions available.'];
            }

            if (!$this->checkUserEligibility($lockedOrder, $user)) {
                return ['error' => 'User is not eligible for this order.'];
            }

            // Check if action already exists for this user (any status)
            $existingAction = DB::table('actions')
                ->where('order_id', $lockedOrder->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingAction) {
                if ($existingAction->status === 'pending') {
                    // Re-dispatch job for pending action
                    dispatch(new SendMqttToUserJob($user->id, $lockedOrder->id, $lockedOrder->type, $lockedOrder->target_url));
                    return ['message' => 'Pending action re-dispatched for this user.'];
                }
                // Block if status is done or external
                if (in_array($existingAction->status, ['done', 'external'])) {
                    return ['error' => 'User already completed or has external action for this order.'];
                }
                return ['error' => 'Action already exists for this user.'];
            }

            // Create the action
            DB::table('actions')->insert([
                'order_id' => $lockedOrder->id,
                'user_id' => $user->id,
                'type' => $lockedOrder->type,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Dispatch job
            dispatch(new SendMqttToUserJob($user->id, $lockedOrder->id, $lockedOrder->type, $lockedOrder->target_url));

            return ['message' => 'User processed successfully.'];
        });
    }
}
