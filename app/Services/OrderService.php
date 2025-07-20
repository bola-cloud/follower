<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        $alreadySentUserIds = [];

        foreach ($eligibleUsers as $user) {
            if (!in_array($user->id, $alreadySentUserIds)) {
                $payloadArray = [
                    'user_id' => $user->id,
                    'url' => $order->target_url,
                    'order_id' => $order->id,
                    'type' => $order->type,
                ];
                $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $escapedJson = escapeshellarg($json);
                $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');
                $command = "node {$scriptPath} {$escapedJson} > /dev/null 2>&1 &";
                exec($command);

                $alreadySentUserIds[] = $user->id;
            }
        }

    }

}
