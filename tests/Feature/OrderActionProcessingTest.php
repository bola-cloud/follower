<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\MqttResponseController;

class OrderActionProcessingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * End-to-end: create order + pending action, simulate device response,
     * and assert the action is updated and order done_count increments.
     */
    public function test_order_action_processing_updates_action_and_increments_order()
    {
        // Force controller to process immediately (no Redis queue) for deterministic test
        putenv('MQTT_USE_QUEUE=false');

        $owner = User::factory()->create(['type' => 'user']);
        $actor = User::factory()->create(['type' => 'user']);

        $order = Order::create([
            'type' => 'follow',
            'total_count' => 1,
            'done_count' => 0,
            'cost' => 0,
            'status' => 'active',
            'target_url' => 'https://example.test/post/1',
            'user_id' => $owner->id,
        ]);

        // Insert a pending action for the actor
        DB::table('actions')->insert([
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'type' => 'follow',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('actions', [
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'status' => 'pending',
        ]);

        // Create a POST-like request to the controller
        $request = Request::create('/api/mqtt-response', 'POST', [
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'status' => 'done',
        ]);

        $controller = new MqttResponseController();
        $response = $controller->handle($request);

        // Controller should return a successful JSON response
        $this->assertEquals(200, $response->getStatusCode());

        // Action must now be marked as done
        $this->assertDatabaseHas('actions', [
            'order_id' => $order->id,
            'user_id' => $actor->id,
            'status' => 'done',
        ]);

        // Order done_count must have incremented and be completed
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'done_count' => 1,
            'status' => 'completed',
        ]);
    }
}
