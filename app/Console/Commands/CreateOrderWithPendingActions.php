<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreateOrderWithPendingActions extends Command
{
    protected $signature = 'mqtt:create-order-with-actions
                            {--start=14634 : start user id}
                            {--end=15634 : end user id}
                            {--order-id= : reuse existing order id (optional)}
                            {--type=follow : order type}
                            {--target-url= : target url (defaults to generated)}
                            {--total=1000 : total_count for order}
                            {--batch=500 : insert batch size for actions}';

    protected $description = 'Create an order and insert pending actions for a user id range (creates actions.status = pending)';

    public function handle()
    {
        $start = (int) $this->option('start');
        $end = (int) $this->option('end');
        $reuseOrderId = $this->option('order-id');
        $type = $this->option('type');
        $targetUrl = $this->option('target-url');
        $total = (int) $this->option('total');
        $batch = (int) $this->option('batch');

        // Basic schema checks
        if (!Schema::hasTable('orders') || !Schema::hasTable('actions') || !Schema::hasTable('users')) {
            $this->error('Required tables (orders, actions, users) do not exist in the database. Aborting.');
            return 1;
        }

        if ($start <= 0 || $end < $start) {
            $this->error('Invalid start/end range');
            return 1;
        }

        // Reuse or create order
        if ($reuseOrderId) {
            $order = DB::table('orders')->where('id', $reuseOrderId)->first();
            if (!$order) {
                $this->error('Order id ' . $reuseOrderId . ' not found');
                return 1;
            }
            $orderId = $reuseOrderId;
            $this->info('Reusing order id ' . $orderId);
        } else {
            // pick an existing user as owner (first found)
            $ownerId = DB::table('users')->where('type', 'user')->value('id');
            if (!$ownerId) {
                $this->error('No user found to own order (users.type = user)');
                return 1;
            }
            if (empty($targetUrl)) {
                $targetUrl = 'https://example.com/' . substr(md5(uniqid('', true)), 0, 8);
            }

            $now = now();
            $orderId = DB::table('orders')->insertGetId([
                'user_id' => $ownerId,
                'target_url' => $targetUrl,
                'type' => $type,
                'total_count' => $total,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->info('Created order id ' . $orderId . ' target_url ' . $targetUrl);
        }

        // Build list of existing user ids within the requested range
        $rangeIds = range($start, $end);
        $existing = DB::table('users')->whereIn('id', $rangeIds)->pluck('id')->toArray();
        if (empty($existing)) {
            $this->error('No users found in that id range');
            return 1;
        }

        // Produce exactly $total user ids by repeating the available ones if necessary.
        $userIds = [];
        $i = 0;
        while (count($userIds) < $total) {
            $userIds[] = $existing[$i % count($existing)];
            $i++;
        }

        // Remove any existing actions for this order/user to avoid duplicates
        $this->info('Cleaning existing pending actions for this order and user set (if any)');
        DB::table('actions')->where('order_id', $orderId)->whereIn('user_id', $userIds)->delete();

        // Insert in batches exactly $total rows
        $now = now();
        $rows = [];
        $inserted = 0;
        foreach ($userIds as $uid) {
            $rows[] = [
                'order_id' => $orderId,
                'user_id' => $uid,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= $batch) {
                DB::table('actions')->insert($rows);
                $inserted += count($rows);
                $rows = [];
            }
        }
        if (!empty($rows)) {
            DB::table('actions')->insert($rows);
            $inserted += count($rows);
        }

        $this->info('Inserted ' . $inserted . ' pending actions for order ' . $orderId . ' (requested ' . $total . ')');
        $this->info('Order ID: ' . $orderId);
        $this->info('To simulate device responses, run the node script to publish to topics: order/res/{orderId}/{userId}');

        return 0;
    }
}
