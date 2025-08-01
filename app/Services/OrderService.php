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
            $this->sendMqttToEligibleUsers($order, $eligibleUsers);
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
                            ->where('a2.status', 'done')
                            ->where('o2.user_id', $order->user_id)
                            ->whereColumn('o2.target_url', 'users.profile_link');
                    });
            })
            ->where('profile_link', '!=', $order->target_url);

        if ($limit > 0) {
            $query->limit($limit);
        }

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

    private function sendMqttToEligibleUsers(Order $order, $eligibleUsers)
    {
        // Send to Node.js via MQTT for ping validation
        $orderData = [
            'order_id' => $order->id,
            'total_count' => $order->total_count - $order->done_count,
            'eligible_users' => $eligibleUsers->map(function($user) {
                return ['id' => $user->id, 'profile_link' => $user->profile_link];
            })->toArray()
        ];

        // Use MQTT to send order data to Node.js processor
        $this->publishToMqtt('order/process/request', $orderData);

        Log::info("[OrderService] Sent order {$order->id} to Node.js processor via MQTT with " . count($eligibleUsers) . " eligible users");
    }

    private function publishToMqtt($topic, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $escapedTopic = escapeshellarg($topic);

        // Use mosquitto_pub to send MQTT message
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$escapedTopic} -m {$escapedJson} -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }

}
