<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleActionResponse
{
    public function handle($event)
    {
        $payload = $event->payload;
        // Log::info('ActionResponse received:', $payload);

        if (!isset($payload['order_id'], $payload['user_id'], $payload['status'])) {
            Log::error('Invalid ActionResponse payload (missing keys):', $payload);
            return;
        }

        // Normalize incoming statuses (support done/external and legacy success/failed)
        $rawStatus = strtolower(trim($payload['status']));
        $statusMap = [
            'done' => 'done',
            'completed' => 'done',
            'success' => 'done',
            'finished' => 'done',
            'external' => 'external',
            'failed' => 'external',
            'redirect' => 'external'
        ];

        if (!isset($statusMap[$rawStatus])) {
            Log::warning('Unknown ActionResponse status normalized to external', ['status' => $rawStatus, 'payload' => $payload]);
            $normalized = 'external';
        } else {
            $normalized = $statusMap[$rawStatus];
        }

        $orderId = intval($payload['order_id']);
        $userId = intval($payload['user_id']);

        DB::beginTransaction();
        try {
            // Update only actions that are not already done to avoid double-counting
            $updated = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->where('status', '!=', 'done')
                ->update([
                    'status' => $normalized,
                    'performed_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($updated) {
                // If we set status to done, increment the order done_count atomically
                if ($normalized === 'done') {
                    DB::statement(
                        "UPDATE orders SET done_count = LEAST(done_count + 1, total_count), updated_at = NOW() WHERE id = ? AND done_count < total_count",
                        [$orderId]
                    );

                    // Fire OrderCompleted if necessary (re-check current values)
                    $order = \App\Models\Order::find($orderId);
                    if ($order && $order->done_count >= $order->total_count && $order->status !== 'completed') {
                        $order->update(['status' => 'completed']);
                        event(new \App\Events\OrderCompleted($order));
                    }
                }

                DB::commit();
                return;
            }

            // If nothing updated, action might be missing or already done. Try to detect and create missing action atomically.
            $existing = DB::table('actions')
                ->where('order_id', $orderId)
                ->where('user_id', $userId)
                ->first();

            if (!$existing) {
                // Create missing action using insertOrIgnore to handle races
                $created = DB::table('actions')->insertOrIgnore([
                    'order_id' => $orderId,
                    'user_id' => $userId,
                    'type' => $payload['type'] ?? 'create',
                    'status' => $normalized,
                    'performed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($created && $normalized === 'done') {
                    DB::statement(
                        "UPDATE orders SET done_count = LEAST(done_count + 1, total_count), updated_at = NOW() WHERE id = ? AND done_count < total_count",
                        [$orderId]
                    );
                }

                DB::commit();
                return;
            }

            // If existing and status is already 'done', nothing to do. If existing but status is done, ensure counts are consistent.
            if ($existing->status === 'done') {
                // Ensure orders.done_count is not missing this completion (repair if needed)
                $doneCount = DB::table('actions')->where('order_id', $orderId)->where('status', 'done')->count();
                DB::table('orders')->where('id', $orderId)->update(['done_count' => $doneCount, 'updated_at' => now()]);
                DB::commit();
                return;
            }

            // If we reach here, it means the existing action wasn't updated (possibly a race). Log and enqueue for retry.
            Log::warning('ActionResponse could not update or create action; enqueueing for retry', ['order_id' => $orderId, 'user_id' => $userId, 'status' => $normalized]);

            // Push to redis queue for reliable retry by ActionQueueJob
            try {
                \Illuminate\Support\Facades\Redis::rpush('mqtt_actions_queue', json_encode(['order_id' => $orderId, 'user_id' => $userId, 'status' => $normalized]));
            } catch (\Throwable $__e) {
                Log::error('Failed to enqueue action for retry', ['error' => $__e->getMessage(), 'payload' => $payload]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to process ActionResponse:', ['error' => $e->getMessage(), 'payload' => $payload]);
        }
    }
}
