<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderCreated
{
    use Dispatchable, SerializesModels;

    public $order;
    public $eligibleUsers;

    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->eligibleUsers = $this->getEligibleUsers($order);
        $this->createPendingActions($order);
    }

    private function getEligibleUsers(Order $order)
    {
        return User::whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')->from('actions')
                  ->where('order_id', $order->id)
                  ->whereIn('status', ['done', 'external']);
            })
            ->where('id', '!=', $order->user_id)
            ->get();
    }

    private function createPendingActions(Order $order)
    {
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
        })->toArray();

        DB::table('actions')->insert($actions);
    }

    public function broadcastOn()
    {
        return $this->eligibleUsers->map(fn ($user) => new Channel("orders.{$user->id}"))->toArray();
    }

    public function broadcastAs()
    {
        return 'order.created';
    }
}
