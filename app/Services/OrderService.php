<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;
use App\Jobs\SendMqttToUserJob;

class OrderService
{
    public function handleOrderCreated(Order $order)
    {
        try {
            // Calculate remaining slots considering recent pending actions
            $actualDoneCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'done')
                ->count();

            // Count pending actions created within the past 30 minutes
            $recentPendingCount = DB::table('actions')
                ->where('order_id', $order->id)
                ->where('status', 'pending')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->count();

            $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;

            if ($remaining <= 0) {
                return;
            }

            $eligibleUsers = $this->getEligibleUsers($order, $remaining);

            $this->createPendingActions($order, $eligibleUsers);
            $this->sendMqttPing($order);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    private function getEligibleUsers(Order $order, $limit = 0)
    {
        $order->loadMissing('user');

        $query = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->whereIn('order_id', function ($s) use ($order) {
                        $s->select('id')->from('orders')->where('target_url', $order->target_url);
                    })
                    ->whereIn('status', ['done', 'external'])
                    ->whereNotExists(function ($reciprocal) use ($order) {
                        $reciprocal->select(DB::raw(1))
                            ->from('actions as a2')
                            ->join('orders as o2', 'a2.order_id', '=', 'o2.id')
                            ->whereColumn('a2.user_id', 'actions.user_id')
                            ->whereIn('a2.status', ['done', 'external'])
                            ->where('o2.user_id', $order->user_id)
                            ->whereColumn('o2.target_url', 'users.profile_link');
                    });
            })
            ->where('profile_link', '!=', $order->target_url);

        // if ($limit > 0) {
        //     $query->limit($limit);
        // }

        $result = $query->get();

        return $result;
    }

    private function createPendingActions(Order $order, $eligibleUsers)
    {
        if ($eligibleUsers->isEmpty()) {
            return;
        }

        // Use the centralized BatchActionService for consistent action creation
        $batchService = app(\App\Services\BatchActionService::class);

        try {
            $userIds = $eligibleUsers->pluck('id')->toArray();

            // Use batch service for optimized insertion
            $result = $batchService->batchInsertPendingAction($order, $userIds);

            Log::info('[OrderService] Batch pending actions created', [
                'order_id' => $order->id,
                'eligible_users_count' => count($userIds),
                'inserted' => $result['inserted'],
                'skipped' => $result['skipped']
            ]);

            // After actions are created, publish order announcements to inform MQTT handler
            // This ensures devices know about the order before they receive pings
            if ($result['inserted'] > 0) {
                // Publish announcements to first 50 users synchronously
                // This ensures MQTT handler records known orders before ping is sent
                $syncChunk = array_slice($userIds, 0, 50);
                foreach ($syncChunk as $uid) {
                    try {
                        $this->publishOrderAnnouncement($uid, $order->id, $order->type, $order->target_url);
                    } catch (\Throwable $je) {
                        Log::warning('[OrderService] Failed to publish synchronous order announcement', [
                            'order_id' => $order->id,
                            'user_id' => $uid,
                            'error' => $je->getMessage()
                        ]);
                    }
                }

                Log::info('[OrderService] Synchronously announced order to initial users', [
                    'order_id' => $order->id,
                    'announced_count' => count($syncChunk)
                ]);
            }

        } catch (\Throwable $e) {
            Log::error('[OrderService] Failed to create batch pending actions', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'eligible_users_count' => $eligibleUsers->count(),
            ]);
            throw $e;
        }
    }

    /**
     * 🔄 SYNCHRONOUS ORDER ANNOUNCEMENT
     * Publish order to users immediately (not queued) to ensure MQTT handler
     * knows about the order before devices respond to pings
     */
    private function publishOrderAnnouncement($userId, $orderId, $type, $url)
    {
        // Defensive: if userId is missing or null, skip publishing
        if (empty($userId)) {
            Log::warning('Skipping publishOrderAnnouncement because user_id is empty', ['order_id' => $orderId, 'user_id' => $userId]);
            return;
        }

        $payloadArray = [
            'user_id' => $userId,
            'url' => $url,
            'order_id' => $orderId,
            'type' => $type,
        ];

        $json = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $escapedJson = escapeshellarg($json);
        $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');

        // Execute synchronously and capture output for debugging
    $command = "node {$scriptPath} {$escapedJson} 2>&1";
    $output = [];
    $exitCode = 0;

    // Measure duration for debugging timeouts
    $start = microtime(true);
    exec($command, $output, $exitCode);
    $duration = microtime(true) - $start;
    Log::info('[OrderService] publish command executed', ['command' => $command, 'duration_ms' => round($duration * 1000, 2), 'exit_code' => $exitCode]);

        if ($exitCode !== 0) {
            // Primary attempt failed (node script timeout or error). Log and enqueue for background publishing
            $outputText = implode("\n", $output);
            Log::warning('Failed to publish order announcement', [
                'user_id' => $userId,
                'order_id' => $orderId,
                'exit_code' => $exitCode,
                'output' => $outputText
            ]);

            // Enqueue to Redis publish queue for reliable background processing
            try {
                $publishQueue = env('MQTT_PUBLISH_QUEUE', 'mqtt_publish_queue');
                $payload = [
                    'user_id' => $userId,
                    'order_id' => $orderId,
                    'type' => $type,
                    'url' => $url,
                    'json' => $json,
                    'attempts' => 0,
                    'last_error' => $outputText,
                    'enqueued_at' => time()
                ];
                Redis::rpush($publishQueue, json_encode($payload));
                Redis::expire($publishQueue, 86400); // keep for 24h
            } catch (\Throwable $e) {
                // If Redis fails, fallback to background exec to avoid blocking
                $bgCommand = "node {$scriptPath} {$escapedJson} > /dev/null 2>&1 &";
                @exec($bgCommand);
                Log::warning('Fallback publisher launched in background after Redis failure', [
                    'user_id' => $userId,
                    'order_id' => $orderId,
                    'bg_command' => $bgCommand,
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            Log::info('Order announcement published', [
                'user_id' => $userId,
                'order_id' => $orderId,
                'output' => implode("\n", $output)
            ]);
        }
    }

    /**
     * Public wrapper to allow controlled invocation of the publisher from controllers or tests.
     * This calls the existing private publisher and returns a simple result array.
     */
    public function publishAnnouncementPublic(int $userId, int $orderId, string $type, string $url): array
    {
        try {
            $this->publishOrderAnnouncement($userId, $orderId, $type, $url);
            return ['success' => true, 'message' => 'Publish attempted'];
        } catch (\Throwable $e) {
            Log::error('[OrderService] publishAnnouncementPublic failed', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function sendMqttPing(Order $order)
    {
        static $sentOrders = [];

        if (in_array($order->id, $sentOrders)) {
            // Log::info("[OrderService] Ping for order {$order->id} already sent, skipping.");
            return;
        }

        $sentOrders[] = $order->id;

        $pingData = [
            'type' => $order->type ?? 'create',
            'order_id' => $order->id,
            'activation' => true
        ];

        $this->publishToMqtt('order/ping/req', $pingData);
        // Log::info("[OrderService] Sent ping for order {$order->id} to `order/ping/req` via MQTT");
    }

    private function publishToMqtt($topic, $data)
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }

    private function checkUserEligibility(Order $order, User $user): bool
    {
        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    public function handle(Order $order, User $user): array
    {
        // Clear, focused logging for create operation
        \Log::info('[OrderService] handle start', [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_type' => $order->type
        ]);
        // No transaction or lock needed here - triggerOrder already validated slots and eligibility
        // Just check basic eligibility and create the action

        if (!$this->checkUserEligibility($order, $user)) {
            return ['error' => 'User is not eligible for this order.'];
        }

        // Check if action already exists for this user (select only status)
        $existingAction = DB::table('actions')
            ->select('status')
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingAction) {
            if ($existingAction->status === 'pending') {
                // Don't re-publish - user already has pending announcement from batch creation
                return ['message' => 'Pending action already exists for this user.'];
            }
            // Block if status is done or external
            if (in_array($existingAction->status, ['done', 'external'])) {
                return ['error' => 'User already completed or has external action for this order.'];
            }
            return ['error' => 'Action already exists for this user.'];
        }

        // Create the action - add a final safety check to prevent exceeding total_count
        $currentActionCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where(function($query) {
                $query->where('status', 'done')
                      ->orWhere(function($subQuery) {
                          $subQuery->where('status', 'pending')
                                   ->where('created_at', '>=', now()->subMinutes(15));
                      });
            })
            ->count();

        if ($currentActionCount >= $order->total_count) {
            return ['error' => 'Order capacity reached.'];
        }

        try {
            // Use ResumeOrderService's batch inserter to create the pending action(s)
            $resumeService = app(\App\Services\ResumeOrderService::class);
            $result = $resumeService->batchInsertPendingAction($order, [$user->id]);

            if ($result['inserted'] > 0) {
                // Publish synchronously for this user
                $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                return ['message' => 'User processed successfully and announcement published.'];
            }

            return ['message' => 'Action already exists or was handled concurrently.'];

        } catch (\Throwable $e) {
            Log::error('[OrderService] Batch insertion via ResumeOrderService failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
