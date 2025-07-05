<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleActionResponse
{
    public function handle($event)
    {
        $payload = $event->payload;
        Log::info('ActionResponse received:', $payload);

        if (!isset($payload['order_id'], $payload['user_id'], $payload['status']) ||
            !in_array($payload['status'], ['success', 'failed'])) {
            Log::error('Invalid ActionResponse payload:', $payload);
            return;
        }

        DB::beginTransaction();
        try {
            // Update action
            $updated = DB::table('actions')
                ->where('order_id', $payload['order_id'])
                ->where('user_id', $payload['user_id'])
                ->update([
                    'status' => $payload['status'] === 'success' ? 'done' : 'external',
                    'performed_at' => now(),
                ]);

            // If action was updated and status is 'done', increment done_count
            if ($updated && $payload['status'] === 'success') {
                $order = \App\Models\Order::find($payload['order_id']);
                if ($order) {
                    $order->increment('done_count');
                    // Check if order is complete
                    if ($order->done_count >= $order->total_count) {
                        $order->update(['status' => 'completed']);
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to process ActionResponse:', ['error' => $e->getMessage()]);
        }
    }
}