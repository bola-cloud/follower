<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\BulkOrderProcessingJob;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TestMqttFlow extends Command
{
    protected $signature = 'test:mqtt-flow';
    protected $description = 'Test the complete MQTT flow';

    public function handle()
    {
        $this->info('🔍 Testing MQTT flow...');

        // 1. Check if there are active orders
        $activeOrders = Order::where('status', 'active')
            ->where('done_count', '<', DB::raw('total_count'))
            ->get();

        $this->info("📊 Active orders: {$activeOrders->count()}");

        if ($activeOrders->isEmpty()) {
            $this->warn('⚠️ No active orders found. Create an order first.');
            return;
        }

        foreach ($activeOrders->take(3) as $order) {
            $remaining = $order->total_count - $order->done_count;
            $this->line("  Order {$order->id}: {$remaining} remaining ({$order->done_count}/{$order->total_count})");
        }

        // 2. Test sending a ping for the first order
        $firstOrder = $activeOrders->first();
        $this->info("🚀 Testing ping for order {$firstOrder->id}...");

        try {
            $pingService = app()->make(\App\Services\PingService::class);
            $pingData = [
                'type' => $firstOrder->type ?? 'create',
                'order_id' => $firstOrder->id,
                'activation' => true
            ];

            $pingService->sendPing('order/ping/req', $pingData);
            $this->info("✅ Ping sent successfully!");

        } catch (\Throwable $e) {
            $this->error("❌ Ping failed: " . $e->getMessage());
            return;
        }

        // 3. Test trigger-order endpoint manually
        $this->info("🧪 Testing trigger-order endpoint manually...");

        // Get a test user
        $testUser = User::where('type', 'user')->first();
        if (!$testUser) {
            $this->warn('⚠️ No test user found.');
            return;
        }

        try {
            $request = new \Illuminate\Http\Request([
                'order_id' => $firstOrder->id,
                'user_id' => $testUser->id,
                'type' => 'create',
                'activation' => true
            ]);

            $controller = new \App\Http\Controllers\Api\MqttResponseController();
            $response = $controller->triggerOrder($request);

            $this->info("✅ Trigger-order response: " . $response->getContent());

        } catch (\Throwable $e) {
            $this->error("❌ Trigger-order failed: " . $e->getMessage());
        }

        // 4. Show monitoring commands
        $this->info("\n📊 Monitor the flow with these commands:");
        $this->line("  tail -f storage/logs/laravel.log | grep 'MQTT_API\\|BulkOrder\\|SendMqtt'");
        $this->line("  tail -f storage/logs/mqtt_output.log");
        $this->line("  pm2 logs mqtt-handler --lines 20");

        // 5. Dispatch bulk job
        $this->info("\n🚀 Dispatching BulkOrderProcessingJob...");
        BulkOrderProcessingJob::dispatch();
        $this->info("✅ Job dispatched!");
    }
}
