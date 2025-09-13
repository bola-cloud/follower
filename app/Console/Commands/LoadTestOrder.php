<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Jobs\ActionQueueJob;

class LoadTestOrder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --count=1000 number of actions to generate
     */
    protected $signature = 'load:test-order {--count=1000} {--no-rollback : If set, commit changes instead of rolling back} {--persist : alias for --no-rollback (commit changes)}';

    /**
     * The console command description.
     */
    protected $description = 'Create an order with many pending actions and simulate device responses to measure processing time';

    public function handle()
    {
        $count = (int) $this->option('count');
        $this->info("Starting safe load test: creating order with {$count} actions (non-destructive by default)");

        $t0 = microtime(true);

        $noRollback = (bool) $this->option('no-rollback');

        // Run everything inside a DB transaction so we can roll back and avoid touching real data
        DB::beginTransaction();
        try {
            // Create an order owner and many users (will be rolled back)
            $owner = User::factory()->create(['type' => 'user']);

            $this->info('Creating users...');
            $batch = 200;
            $userIds = [];
            for ($i = 0; $i < $count; $i += $batch) {
                $chunk = min($batch, $count - $i);
                $created = User::factory()->count($chunk)->create()->pluck('id')->toArray();
                $userIds = array_merge($userIds, $created);
                $this->line("  created users: " . count($userIds));
            }

            $t_users = microtime(true);

            // Create the order
            $order = Order::create([
                'type' => 'follow',
                'total_count' => $count,
                'done_count' => 0,
                'cost' => 0,
                'status' => 'active',
                'target_url' => $targetUrl = 'https://loadtest.example/item/' . uniqid(),
                'target_url_hash' => sha1($targetUrl),
                'user_id' => $owner->id,
            ]);

            $t_order = microtime(true);

            $this->info('Inserting pending actions into DB...');

            // Insert pending actions in chunks to avoid huge single queries
            $now = now();
            $chunks = array_chunk($userIds, 500);
            foreach ($chunks as $idx => $chunk) {
                $rows = [];
                foreach ($chunk as $uid) {
                    $rows[] = [
                        'order_id' => $order->id,
                        'user_id' => $uid,
                        'type' => 'follow',
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('actions')->insertOrIgnore($rows);
                $this->line("  inserted actions: " . (($idx + 1) * count($chunk)));
            }

            $t_actions = microtime(true);

            // Build simulated payloads in memory (do NOT push to global Redis key to avoid side-effects)
            $this->info('Building simulated MQTT payloads (in-memory)...');
            $payloads = [];
            foreach ($userIds as $uid) {
                $payloads[] = [
                    'topics' => [
                        'order/ping/req' => ['order_id' => $order->id],
                        'order/ping/res' => ['order_id' => $order->id, 'user_id' => $uid, 'status' => 'ok'],
                        "orders/{$uid}" => ['order_id' => $order->id, 'user_id' => $uid],
                        "order/res/{$order->id}/{$uid}" => ['order_id' => $order->id, 'user_id' => $uid, 'status' => 'done'],
                    ],
                    'order_id' => $order->id,
                    'user_id' => $uid,
                    'status' => 'done',
                    'type' => 'follow',
                    'timestamp' => now()->toDateTimeString(),
                ];
            }

            $t_payloads = microtime(true);

            $this->info('Processing payloads directly (no Redis, no external network)...');

            $t_job_start = microtime(true);

            $processed = 0;
            foreach ($payloads as $p) {
                // replicate ActionQueueJob::processAction logic but kept local to avoid touching Redis
                $orderId = $p['order_id'];
                $userId = $p['user_id'];
                $status = $p['status'];

                $updated = DB::table('actions')
                    ->where('order_id', $orderId)
                    ->where('user_id', $userId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => $status,
                        'performed_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($updated > 0) {
                    $processed++;
                    if ($status === 'done') {
                        DB::table('orders')->where('id', $orderId)->increment('done_count');

                        // Check completion
                        $orderRow = DB::table('orders')->where('id', $orderId)->first(['done_count', 'total_count', 'status']);
                        if ($orderRow && $orderRow->done_count >= $orderRow->total_count && $orderRow->status !== 'completed') {
                            DB::table('orders')->where('id', $orderId)->where('status', '!=', 'completed')->update(['status' => 'completed']);
                        }
                    }
                }
            }

            $t_job_end = microtime(true);

            // Read final counts (still inside transaction)
            $doneCount = DB::table('actions')->where('order_id', $order->id)->where('status', 'done')->count();
            $orderRow = DB::table('orders')->where('id', $order->id)->first();

            $t_final = microtime(true);

            $this->info('Load test complete. Summary (non-destructive):');
            $this->line('  users_created_time: ' . round($t_users - $t0, 3) . 's');
            $this->line('  order_created_time: ' . round($t_order - $t_users, 3) . 's');
            $this->line('  actions_insert_time: ' . round($t_actions - $t_order, 3) . 's');
            $this->line('  payloads_build_time: ' . round($t_payloads - $t_actions, 3) . 's');
            $this->line('  processing_time: ' . round($t_job_end - $t_job_start, 3) . 's');
            $this->line('  final_checks_time: ' . round($t_final - $t_job_end, 3) . 's');
            $this->line('  total_time: ' . round($t_final - $t0, 3) . 's');
            $this->line('  processed_payloads: ' . $processed);
            $this->line('  done_actions_count: ' . $doneCount);
            $this->line('  order_done_count (db): ' . ($orderRow->done_count ?? 'n/a'));

            if ($doneCount >= $count) {
                $this->info("SUCCESS: All {$count} actions processed as 'done' (within transaction)");
            } else {
                $this->error("INCOMPLETE: Only {$doneCount} of {$count} actions were processed.");
            }

            // Commit or rollback depending on flag
            // Export run metadata and deletion plan to a file for manual cleanup later
            // Format expected by node simulators: { order_id, user_ids, type }
            $export = [
                'run_ts' => now()->toDateTimeString(),
                'order_id' => $order->id,
                'user_ids' => $userIds,
                'type' => $order->type ?? 'follow',
                'notes' => 'This file is for manual cleanup. Do not delete automatically.'
            ];

            // Grab approximate action id range created in this transaction (best-effort)
            $firstAction = DB::table('actions')->where('order_id', $order->id)->orderBy('id', 'asc')->first();
            $lastAction = DB::table('actions')->where('order_id', $order->id)->orderBy('id', 'desc')->first();
            if ($firstAction) $export['action_id_sample_start'] = $firstAction->id;
            if ($lastAction) $export['action_id_sample_end'] = $lastAction->id;

            // Save export JSON to storage/logs with a unique name (do not remove on rollback)
            $exportPath = storage_path('logs/loadtest_' . time() . '.json');
            file_put_contents($exportPath, json_encode($export, JSON_PRETTY_PRINT));
            $this->line('Exported run metadata to: ' . $exportPath);

            if ($noRollback) {
                DB::commit();
                $this->warn('NOTE: Changes were committed to the database (--no-rollback specified).');
            } else {
                DB::rollBack();
                $this->info('All database changes rolled back. No real data was modified.');
            }

            $this->line('Deletion plan:');
            $this->line('  order ids: ' . $order->id);
            $this->line('  user ids: ' . (count($userIds) > 0 ? (min($userIds) . ' - ' . max($userIds)) : 'none'));
            $this->line('  action ids approx: ' . ($export['action_id_sample_start'] ?? 'n/a') . ' - ' . ($export['action_id_sample_end'] ?? 'n/a'));

            return 0;

            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Load test failed: ' . $e->getMessage());
            return 1;
        }
    }
}
