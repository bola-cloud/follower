<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $eligibleUsers;

    public function __construct(Order $order)
    {
        try {
            $this->order = $order;
            $this->eligibleUsers = $this->getEligibleUsers($order);
            $this->createPendingActions($order);
            $this->sendMqttToEligibleUsers($order); // ✅ MQTT Broadcast
        } catch (\Throwable $e) {
            \Log::error('Failed to initialize OrderCreated event:', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getEligibleUsers(Order $order)
    {

        // Ensure the order's creator is loaded
        $order->loadMissing('user');

        return \App\Models\User::where('type', 'user')
            // Exclude users who already followed/liked this profile (done or external)
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->whereIn('order_id', function ($s) use ($order) {
                        $s->select('id')
                            ->from('orders')
                            ->where('target_url', $order->target_url);
                    })
                    ->whereIn('status', ['done', 'external'])
                    // ✅ Reciprocal exception: allow user if order creator previously followed them
                    ->whereNotExists(function ($reciprocal) use ($order) {
                        $reciprocal->select(DB::raw(1))
                            ->from('actions as a2')
                            ->join('orders as o2', 'a2.order_id', '=', 'o2.id')
                            ->whereColumn('a2.user_id', 'actions.user_id') // same target user
                            ->where('a2.status', 'done')
                            ->where('o2.user_id', $order->user_id) // order creator
                            ->whereColumn('o2.target_url', 'users.profile_link'); // creator followed them before
                    });
            })
            // ✅ Prevent user from receiving order to follow themselves
            ->where('profile_link', '!=', $order->target_url)
            ->get();


        // return User::where('type', 'user')
        //     ->whereNotIn('id', function ($q) use ($order) {
        //         $q->select('user_id')->from('actions')
        //             ->whereIn('order_id', function ($s) use ($order) {
        //                 $s->select('id')->from('orders')
        //                     ->where('target_url', $order->target_url); // ✅ match orders with same link
        //             })
        //             ->whereIn('status', ['done', 'external']);
        //     })
        //     ->where('profile_link', '!=', $order->target_url) // ✅ exclude self
        //     ->get();


        // return User::where('type', 'user')
        //     ->where(function ($query) use ($order) {
        //         // Exclude users who already completed this order
        //         $query->whereNotIn('id', function ($q) use ($order) {
        //             $q->select('user_id')->from('actions')
        //                 ->where('order_id', $order->id)
        //                 ->whereIn('status', ['done', 'external']);
        //         });
        //     })
        //      ->where('profile_link', '!=', $order->target_url) // ✅ strict self-profile check
        //     ->get();
            // ->where(function ($query) use ($order) {
            //     // Exclude users whose profile_link contains the same content ID
            //     $query->whereRaw("NOT (profile_link LIKE ?)", ["%{$order->target_url}%"]);
            // })
            // ->get();
    }

    private function createPendingActions(Order $order)
    {
        DB::beginTransaction();

        try {
            $now = now();

            $actions = $this->eligibleUsers->map(function ($user) use ($order, $now) {
                return [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'type' => $order->type,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->filter(function ($action) {
                // Prevent duplicates
                return !DB::table('actions')
                    ->where('order_id', $action['order_id'])
                    ->where('user_id', $action['user_id'])
                    ->exists();
            })->toArray();

            DB::table('actions')->insert($actions);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Failed to create pending actions:', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function sendMqttToEligibleUsers(Order $order)
    {
        foreach ($this->eligibleUsers as $user) {
            $payload = json_encode([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'type' => 'order.created',
            ]);

            $escapedPayload = escapeshellarg($payload);

            // ✅ New topic structure: orders/{order_id}/{user_id}
            exec("node node_scripts/mqtt_order_publisher.cjs {$escapedPayload} > /dev/null 2>&1 &");
        }
    }

    // public function broadcastOn()
    // {
    //     return $this->eligibleUsers->map(fn ($user) => new Channel("orders.{$user->id}"))->toArray();
    // }

    // public function broadcastAs()
    // {
    //     return 'order.created';
    // }
}
