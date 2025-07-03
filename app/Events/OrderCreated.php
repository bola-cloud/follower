<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $type;
    public $targetUrl;
    public $order;

    /**
     * Create a new event instance.
     *
     * @param $userId
     * @param $type
     * @param $targetUrl
     * @param $order
     */
    public function __construct($userId, $type, $targetUrl, $order)
    {
        $this->userId = $userId;
        $this->type = $type;
        $this->targetUrl = $targetUrl;
        $this->order = $order;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return \Illuminate\Broadcasting\Channel|array
     */
    public function broadcastOn()
    {
        return new Channel('orders.' . $this->userId); // Broadcasting to the user's specific channel
    }

    /**
     * Get the name of the event.
     *
     * @return string
     */
    public function broadcastAs()
    {
        return 'order.created'; // Event name for the broadcast
    }
}
