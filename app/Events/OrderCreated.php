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
        } catch (\Throwable $e) {
            \Log::error('Failed to initialize OrderCreated event:', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getEligibleUsers(Order $order)
    {
        return User::whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')->from('actions')
                  ->where('order_id', $order->id)
                  ->whereIn('status', ['done', 'external']);
            })
            ->get();
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
            })->toArray();

            DB::table('actions')->insert($actions);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::error('Failed to create pending actions:', ['error' => $e->getMessage()]);
            throw $e;
        }
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