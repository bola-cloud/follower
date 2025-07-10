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

    // Get eligible users for the order
    private function getEligibleUsers(Order $order)
    {
        // If the order creator is an admin
        if ($order->user->type === 'admin') {
            return $this->getEligibleUsersForAdmin($order);
        }

        // If the user is a non-admin, check for 'follow' or 'like' actions
        if ($order->type === 'follow') {
            return $this->getEligibleUsersForFollow($order);
        }

        // If the order type is 'like', we check if the users have interacted with the same post
        if ($order->type === 'like') {
            return $this->getEligibleUsersForLike($order);
        }

        return collect(); // If none of the above, return an empty collection
    }

    // Logic for non-admin users with 'follow' order type
    private function getEligibleUsersForFollow(Order $order)
    {
        return User::whereNotIn('id', function ($q) use ($order) {
            $q->select('user_id')->from('actions')
              ->where('order_id', $order->id)
              ->where('type', 'user')
              ->whereIn('status', ['done', 'external']);
        })
        ->get();
    }

    // Logic for non-admin users with 'like' order type
    private function getEligibleUsersForLike(Order $order)
    {
        $targetUrl = $this->stripInstagramQueryParams($order->target_url);
        return User::whereNotIn('id', function ($q) use ($order, $targetUrl) {
            $q->select('user_id')->from('actions')
              ->where('order_id', $order->id)
              ->where('type', 'user')
              ->where('target_url', 'like', "%$targetUrl%") // Match the link (without query string)
              ->whereIn('status', ['done', 'external']);
        })
        ->get();
    }

    // Logic for admin users checking 'target_url' against existing actions
    private function getEligibleUsersForAdmin(Order $order)
    {
        // Get target_url from the order and strip the query params
        $targetUrl = $this->stripInstagramQueryParams($order->target_url);

        // Get users who haven't already interacted (via actions table) with this post link
        return User::whereNotIn('id', function ($q) use ($order, $targetUrl) {
            $q->select('user_id')->from('actions')
              ->where('order_id', $order->id)
              ->where('type', 'user')
              ->where('target_url', 'like', "%$targetUrl%") // Ensure link matches without query string
              ->whereIn('status', ['done', 'external']);
        })
        ->get();
    }

    // Helper function to strip query params from Instagram URLs
    private function stripInstagramQueryParams($url)
    {
        // Check if the URL is an Instagram URL and contains a query string
        if (preg_match('/^(https:\/\/www\.instagram\.com\/reel\/[A-Za-z0-9_-]+)\/?/', $url, $matches)) {
            return $matches[1]; // Return only the core URL without query parameters
        }

        return $url; // Return the URL as-is if it's not an Instagram URL or doesn't match
    }

    // Function to create pending actions for users
    private function createPendingActions(Order $order)
    {

        dd('pending');
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

    // Broadcasting to the eligible users
    public function broadcastOn()
    {
        return $this->eligibleUsers->map(fn ($user) => new Channel("orders.{$user->id}"))->toArray();
    }

    // The event name to be used when broadcasting
    public function broadcastAs()
    {
        return 'order.created';
    }
}
