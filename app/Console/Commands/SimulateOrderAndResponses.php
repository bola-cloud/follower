<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Jobs\AddPointsToUser;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class SimulateOrderAndResponses extends Command
{
    protected $signature = 'simulate:order {--count=1000} {--no-node=false} {--broker=mqtt://109.199.112.65:1883} {--concurrency=100} {--delay=5}';
    protected $description = 'Create users, call order API to create an order, export metadata and run Node MQTT simulator to reply as those users.';

    public function handle()
    {
        $count = (int) $this->option('count');
        $noNode = filter_var($this->option('no-node'), FILTER_VALIDATE_BOOLEAN);
        $broker = $this->option('broker');
        $concurrency = (int) $this->option('concurrency');
        $delay = (int) $this->option('delay');

        $this->info("Simulate: creating {$count} users...");

        // Create an owner (the user who will place the order)
        $owner = User::factory()->create([
            'type' => 'user',
            'points' => max(100000, $count * 10),
        ]);

        $this->info('Owner created: id=' . $owner->id);

        // create responders in chunks to avoid memory spikes
        $chunk = 200;
        $createdIds = [];
        for ($i = 0; $i < $count; $i += $chunk) {
            $c = min($chunk, $count - $i);
            $users = User::factory()->count($c)->create([
                'type' => 'user',
                // responders don't need points but give a small amount
                'points' => 10,
            ]);
            $createdIds = array_merge($createdIds, $users->pluck('id')->toArray());
            $this->info("Created {$c} users (total " . count($createdIds) . ")");
        }

        // Prepare order payload
        $data = [
            'type' => 'follow',
            'total_count' => $count,
            'target_url' => Str::random(12),
        ];

        $this->info('Creating order inline (replicating store logic)...');

        try {
            DB::beginTransaction();

            // Ensure owner has enough points
            $pointsPerAction = function_exists('setting') ? setting('points_per_follow', 1) : 1;
            $cost = $data['total_count'] * $pointsPerAction;
            if ($owner->points < $cost) {
                $this->error('Owner has insufficient points to create test order.');
                DB::rollBack();
                return 1;
            }

            // Deduct points
            $owner->decrement('points', $cost);

            // Create order
            $order = Order::create([
                'type' => $data['type'],
                'total_count' => $data['total_count'],
                'done_count' => 0,
                'cost' => $cost,
                'status' => 'active',
                'target_url' => $data['target_url'],
                'target_url_hash' => sha1($data['target_url']),
                'user_id' => $owner->id,
            ]);

            if (! $order) {
                DB::rollBack();
                $this->error('Failed to create order model.');
                return 1;
            }

            // Timer logic similar to controller
            if ($owner->points === 0) {
                if (! $owner->timer || now()->greaterThan($owner->timer)) {
                    AddPointsToUser::dispatch($owner->id)->delay(now()->addMinutes(30));
                    $newTimer = now()->addMinutes(30);
                    $owner->update(['timer' => $newTimer]);
                }
            } else {
                $owner->update(['timer' => null]);
            }

            DB::commit();

            $orderId = $order->id;
            $this->info('Order created: id=' . $orderId);

            // Send ping via PingService (non-blocking try/catch) — this matches the
            // real production flow where pings are sent and devices respond.
            try {
                $pingService = app()->make(\App\Services\PingService::class);
                $pingService->sendPing('order/ping/req', [
                    'type' => 'create',
                    'order_id' => $orderId,
                    'activation' => true,
                ]);
            } catch (\Throwable $e) {
                Log::error('[SimulateOrder] Error sending ping: ' . $e->getMessage());
            }

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Exception while creating order: ' . $e->getMessage());
            return 1;
        }

        // export metadata
        $ts = time();
        // Wait for the server to create pending actions for this order and export
        // only the user_ids that have an action row. This keeps the simulator
        // responses aligned with what the server has actually stored.
        $maxWaitSeconds = 30;
        $pollIntervalMicro = 500000; // 0.5s
        $start = time();
        $foundUserIds = [];

        while (time() - $start < $maxWaitSeconds) {
            try {
                $foundUserIds = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('status', 'pending')
                    ->whereIn('user_id', $createdIds)
                    ->pluck('user_id')
                    ->toArray();

                $foundUserIds = array_values(array_unique($foundUserIds));
            } catch (\Throwable $e) {
                Log::warning('[SimulateOrder] Polling actions table failed', ['error' => $e->getMessage()]);
            }

            // If we found at least one stored action, stop early. Otherwise keep polling until timeout.
            if (count($foundUserIds) > 0) {
                break;
            }

            usleep($pollIntervalMicro);
        }

        // Fall back to the created user list if the DB returned nothing within the timeout.
        $exportUserIds = !empty($foundUserIds) ? $foundUserIds : $createdIds;

        $export = [
            'ts' => $ts,
            'order_id' => $orderId,
            'owner_id' => $owner->id,
            'user_ids' => $exportUserIds,
            'stored_count' => count($exportUserIds),
            'expected_count' => count($createdIds),
        ];

        $exportPath = storage_path("logs/loadtest_{$ts}.json");
        file_put_contents($exportPath, json_encode($export, JSON_PRETTY_PRINT));
        $this->info('Exported metadata to ' . $exportPath);

        // Start Node simulator unless asked not to
        if ($noNode) {
            $this->info('Node simulator disabled (--no-node=true).');
            $this->info('You can run: node node_scripts/load_test_mqtt_simulator.cjs ' . escapeshellarg($exportPath) . ' --broker=' . escapeshellarg($broker) . ' --concurrency=' . $concurrency . ' --delay=' . $delay);
            return 0;
        }

        $nodeScript = base_path('node_scripts/load_test_mqtt_simulator.cjs');
        if (!file_exists($nodeScript)) {
            $this->error('Node script not found at ' . $nodeScript . '. Create node_scripts/load_test_mqtt_simulator.cjs first.');
            return 1;
        }

        $logPath = storage_path("logs/loadtest-mqtt-{$ts}.log");
        $cmd = ['node', $nodeScript, $exportPath, '--broker=' . $broker, '--concurrency=' . $concurrency, '--delay=' . $delay, '--log=' . $logPath];

        // The Node simulator launch is intentionally disabled for real-device testing.
        // If you want to run the simulator locally, uncomment the block below and
        // ensure `node_scripts/load_test_mqtt_simulator.cjs` exists.
        //
        // $this->info('Starting Node simulator (will run until completion)...');
        // $process = new Process($cmd);
        // // Pass DB connection env so the Node simulator (running on the same host) can poll the actions table
        // $dbConn = config('database.connections.mysql');
        // $processEnv = [
        //     'DB_HOST' => $dbConn['host'] ?? env('DB_HOST'),
        //     'DB_PORT' => $dbConn['port'] ?? env('DB_PORT', '3306'),
        //     'DB_DATABASE' => $dbConn['database'] ?? env('DB_DATABASE'),
        //     'DB_USERNAME' => $dbConn['username'] ?? env('DB_USERNAME'),
        //     'DB_PASSWORD' => $dbConn['password'] ?? env('DB_PASSWORD'),
        // ];
        // $process->setEnv(array_merge($process->getEnv(), $processEnv));
        // $process->setTimeout(null);
        //
        // // Run the Node script and stream output to console so we can monitor progress.
        // $exitCode = $process->run(function ($type, $buffer) {
        //     // OUT vs ERR
        //     if (defined('\Symfony\Component\Process\Process::OUT') && \Symfony\Component\Process\Process::OUT === $type) {
        //         $this->line(trim($buffer));
        //     } else {
        //         $this->error(trim($buffer));
        //     }
        // });
        //
        // if ($exitCode !== 0 || ! $process->isSuccessful()) {
        //     $this->error('Node simulator finished with errors. Exit code: ' . $exitCode);
        //     $this->error('Node stderr: ' . $process->getErrorOutput());
        // } else {
        //     $this->info('Node simulator finished successfully. Log: ' . $logPath);
        // }

        // Print the identifiers and metadata for real devices to use when replying.
        $this->line('--- SIMULATION READY FOR REAL DEVICES ---');
        $this->line('Order ID: ' . $orderId);
        $this->line('Owner ID: ' . $owner->id);
        $this->line('Stored responder count (exported): ' . count($exportUserIds));
        $this->line('Expected responder count: ' . count($createdIds));
        $this->line('Export file path: ' . $exportPath);
        $this->line('First 50 responder IDs: ' . json_encode(array_slice($exportUserIds, 0, 50)));
        $this->line('If you want to run the Node simulator locally, re-enable the Process block in this command and ensure the script exists: ' . $nodeScript);

        return 0;
    }
}
