<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTriggerOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [5, 15];

    public function __construct(private int $orderId, private int $userId, private string $type, private ?string $messageId = null)
    {
        $this->onQueue('trigger-orders');
    }

    public function handle()
    {
        try {
            // Resolve order and user quickly inside the job
            $order = \App\Models\Order::find($this->orderId);
            $user = \App\Models\User::find($this->userId);

            if (!$order || !$user) {
                Log::warning('[ProcessTriggerOrderJob] order or user not found', ['order_id' => $this->orderId, 'user_id' => $this->userId]);
                return;
            }

            if ($this->type === 'resume') {
                $service = app(\App\Services\ResumeOrderService::class);
                $service->handle($order, $user);
            } else {
                $service = app(\App\Services\OrderService::class);
                $service->handle($order, $user);
            }

        } catch (\Throwable $e) {
            Log::error('[ProcessTriggerOrderJob] failed', ['error' => $e->getMessage(), 'order_id' => $this->orderId, 'user_id' => $this->userId]);
            throw $e;
        } finally {
            // Remove dedupe key if messageId present
            if ($this->messageId) {
                try { \Cache::forget('trigger:d:' . $this->messageId); } catch (\Throwable $__e) {}
            } else {
                try { \Cache::forget('trigger:h:' . md5($this->orderId . ':' . $this->userId . ':' . $this->type)); } catch (\Throwable $__e) {}
            }
        }
    }
}
