<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SimulateUserResponse extends Command
{
    protected $signature = 'test:simulate-response {order_id} {user_id} {type=create}';
    protected $description = 'Simulate a user responding to an order ping';

    public function handle()
    {
        $orderId = $this->argument('order_id');
        $userId = $this->argument('user_id');
        $type = $this->argument('type');

        $this->info("🤖 Simulating user {$userId} responding to order {$orderId}...");

        // Simulate ping response
        $pingPayload = [
            'activation_order_id' => (int)$orderId,
            'user_id' => (int)$userId,
            'type' => $type
        ];

        $this->info("📡 Sending ping response...");
        $this->sendMqttMessage('order/ping/res', $pingPayload);

        $this->info("⏱️ Wait a moment, then simulate task completion...");
        sleep(2);

        // Simulate task completion
        $taskPayload = [
            'status' => 'done'
        ];

        $this->info("✅ Sending task completion...");
        $this->sendMqttMessage("order/res/{$orderId}/{$userId}", $taskPayload);

        $this->info("🎉 Simulation complete! Check logs for results.");
    }

    private function sendMqttMessage($topic, $payload)
    {
        $jsonPayload = json_encode($payload);
        $escapedPayload = escapeshellarg($jsonPayload);

        // Use mosquitto_pub to send the message
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t " . escapeshellarg($topic) . " -m {$escapedPayload}";

        $this->line("Command: {$command}");

        exec($command . " 2>&1", $output, $returnCode);

        if ($returnCode === 0) {
            $this->info("✅ Message sent to topic: {$topic}");
        } else {
            $this->error("❌ Failed to send message. Output: " . implode("\n", $output));
        }
    }
}
