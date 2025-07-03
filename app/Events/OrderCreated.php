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

class OrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $order;
    public $eligibleUsers;

    // Constructor accepts the order and eligible users
    public function __construct(Order $order)
    {
        $this->order = $order;
        $this->eligibleUsers = $this->getEligibleUsers($order); // Get all eligible users
    }

    // Loop through eligible users and broadcast to their respective channels
    public function broadcastOn()
    {
        $channels = [];

        foreach ($this->eligibleUsers as $user) {
            // Send to each user’s channel
            $channels[] = new Channel('orders.' . $user->id);
        }

        return $channels;
    }

    // Function to get eligible users
    private function getEligibleUsers(Order $order)
    {
        \Log::info('eligible users');
        // Start with all users who haven't already performed the action
        $eligibleUsers = User::whereNotIn('id', function ($query) use ($order) {
            $query->select('user_id')
                  ->from('actions')
                  ->where('order_id', $order->id)
                  ->whereIn('status', ['done', 'external']);
        });

        // Get the user who made the order
        $user = $order->user;

        // If the user is a follow, add the related users
        if ($order->type == 'follow') {
            // Add the user who is being followed to the eligible list
            $eligibleUsers->orWhere(function ($query) use ($user) {
                $query->where('id', '!=', $user->id); // Exclude the current user
            });
        }

        return $eligibleUsers->get();
    }

    public function broadcastAs()
    {
        return 'order.created';
    }
}
