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
                // No remaining slots — nothing to do
                return collect([]);
            }
        } catch (\Throwable $e) {
            Log::warning('[OrderService] handleOrderCreated failed', ['error' => $e->getMessage(), 'order_id' => $order->id ?? null]);
            return collect([]);
        }

        $order->loadMissing('user');

    // Normalize target identifier: extract canonical identifier (username or shortcode)
    $targetIdentifier = $this->extractTargetIdentifier($order->target_url ?? '');
    // Also normalize raw target by trimming trailing slash for direct comparisons
    $normalizedTarget = rtrim($order->target_url ?? '', '/');
    // Prefer stored hash if present, otherwise use sha1 of the canonical identifier
    $targetHash = $order->target_url_hash ?? sha1($targetIdentifier ?? '');

        $query = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order, $targetHash) {
                // Exclude users who have DONE or EXTERNAL actions on any other order
                // that references the same target (compare by target_url_hash when possible,
                // or by raw target_url as a fallback for legacy rows)
                $q->select('actions.user_id')
                    ->from('actions')
                    ->join('orders', 'actions.order_id', '=', 'orders.id')
                    ->whereIn('actions.status', ['done', 'external'])
                                        ->where(function ($w) use ($order, $targetHash, $targetIdentifier) {
                                                $w->where('orders.target_url_hash', $targetHash)
                                                    ->orWhere('orders.target_url', 'like', '%' . $targetIdentifier . '%');
                                        })
                    ->where('orders.id', '!=', $order->id);
            })
            // Compare profile_link ignoring trailing slash
            ->whereRaw("TRIM(TRAILING '/' FROM profile_link) != ?", [$normalizedTarget]);

        // if ($limit > 0) {
        //     $query->limit($limit);
        // }

        $result = $query->get();

        return $result;
    }

    /**
     * Get eligible users for this order
     *
     * @param Order $order
     * @param int $limit Optional limit (kept for signature compatibility)
     * @return \Illuminate\Support\Collection
     */
    public function getEligibleUsers(Order $order)
    {
        Log::error('[ResumeOrderService] getEligibleUsers start', [
            'order_id' => $order->id ?? null,
            'target_url' => $order->target_url ?? null
        ]);

        // Get pending users and new eligible users similar to resume method
        $pendingUserIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->toArray();

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
            // Return pending users if any
            return User::whereIn('id', $pendingUserIds)->get();
        }

        // Get new eligible users
        $eligibleUsers = User::where('type', 'user')
            ->orderBy('id', 'desc')
            ->whereNotIn('id', function ($q) use ($order) {
                $q->select('user_id')->from('actions')->where('order_id', $order->id)->whereIn('status', ['done', 'external']);
            })
            ->whereNotIn('id', $pendingUserIds)
            // Compare profile_link ignoring trailing slash
            ->whereRaw("TRIM(TRAILING '/' FROM profile_link) != ?", [$normalizedTarget])
            ->whereNotIn('id', function ($sub) use ($order) {
                $sub->select('a1.user_id')
                    ->from('actions as a1')
                    ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                    ->whereIn('a1.status', ['done', 'external'])
                    // Use normalized/hash based comparison to find other orders referencing same target
                    ->where('o1.target_url_hash', $order->target_url_hash)
                    ->where('o1.id', '!=', $order->id);
            })
            // Exclude users who have done/external actions on OTHER orders with same target identifier
            ->whereNotIn('id', function ($sub) use ($order) {
                $targetHashLocal = $order->target_url_hash ?? sha1($this->extractTargetIdentifier($order->target_url ?? ''));
                $targetIdentifierLocal = $this->extractTargetIdentifier($order->target_url ?? '');
                $sub->select('actions.user_id')
                    ->from('actions')
                    ->join('orders', 'actions.order_id', '=', 'orders.id')
                    ->whereIn('actions.status', ['done', 'external'])
                    ->where(function ($w) use ($order, $targetHashLocal, $targetIdentifierLocal) {
                        $w->where('orders.target_url_hash', $targetHashLocal)
                          ->orWhere('orders.target_url', 'like', '%' . $targetIdentifierLocal . '%');
                    })
                    ->where('orders.id', '!=', $order->id);
            })
            // ->limit($remaining)
            ->get();

        // Combine pending and new eligible users
        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();
        $combinedUsers = $pendingUsers->merge($eligibleUsers);

        return $combinedUsers;
    }

    private function createPendingActions(Order $order, $eligibleUsers)
    {
        Log::error('[OrderService] createPendingActions start', [
            'order_id' => $order->id ?? null,
            'eligible_users_count' => $eligibleUsers->count()
        ]);
        if ($eligibleUsers->isEmpty()) {
            return;
        }

        // Use the centralized BatchActionService for consistent action creation
        $batchService = app(\App\Services\BatchActionService::class);

        try {
            $userIds = $eligibleUsers->pluck('id')->toArray();

            // Use batch service for optimized insertion
            $result = $batchService->batchInsertPendingAction($order, $userIds);

            Log::info('[OrderService] Created batch pending actions', [
                'order_id' => $order->id,
                'eligible_users_count' => count($userIds),
                'inserted' => $result['inserted'],
                'skipped' => $result['skipped']
            ]);

            // ✅ NO PUBLISHING HERE: Order announcements will be sent by ProcessPingResponseBatchJob
            // after devices respond to ping requests. This prevents duplicate publishing.
            if ($result['inserted'] > 0) {
                Log::info('[OrderService] Actions created, batch job will handle announcements', [
                    'order_id' => $order->id,
                    'action_count' => $result['inserted'],
                    'note' => 'Announcements will be sent after ping responses processed'
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
     * 🔄 UNIFIED ORDER ANNOUNCEMENT PUBLISHER
     * Publish order to users with queue-first approach and robust fallbacks
     */
    private function publishOrderAnnouncement($userId, $orderId, $type, $url)
    {
        Log::info('[ResumeOrderService] publishOrderAnnouncement start', [
            'order_id' => $orderId ?? null,
            'user_id'  => $userId ?? null,
            'type'     => $type ?? null,
            'url'      => $url ?? null
        ]);

        // Skip paused orders
        try {
            $order = \App\Models\Order::find($orderId);
            if ($order && isset($order->status) && $order->status === 'paused') {
                Log::info('[publishOrderAnnouncement] Skipping - order paused', ['order_id' => $orderId, 'user_id' => $userId]);
                return;
            }
        } catch (\Throwable $e) {
            Log::warning('[publishOrderAnnouncement] Order lookup failed, continuing', ['order_id' => $orderId, 'error' => $e->getMessage()]);
        }

        if (empty($userId)) {
            Log::warning('[publishOrderAnnouncement] Skipping: empty userId', ['order_id' => $orderId, 'user_id' => $userId]);
            return;
        }

        // Include mediaId and userPk if available on the order
        $mediaId = null;
        $userPkVal = null;
        try {
            $o = \App\Models\Order::find($orderId);
            if ($o) {
                $mediaId = $o->mediaId ?? null;
                $userPkVal = $o->userPk ?? null;
            }
        } catch (\Throwable $e) {
            // ignore lookup error and continue with nulls
        }

        $payloadArray = [
            'user_id'  => $userId,
            'url'      => $url,
            'order_id' => $orderId,
            'type'     => $type,
            'mediaId'  => $mediaId,
            'userPk'   => $userPkVal,
        ];
        Log::info('[ResumeOrderService] publishOrderAnnouncement payload', $payloadArray);

        // 1) Fast path: enqueue to Redis worker
        $enqueued = false;
        try {
            $publisher = app(\App\Services\MqttPublisherRedis::class);
            $enqueued = (bool) $publisher->enqueue("orders/{$userId}", $payloadArray, 0, false);
        } catch (\Throwable $e) {
            Log::warning('[ResumeOrderService] MqttPublisherRedis->enqueue failed', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
        }

        if ($enqueued) {
            Log::info('[ResumeOrderService] Enqueued publish job (queue mode)', ['order_id' => $orderId, 'user_id' => $userId]);
            return;
        }

        // 2) If strict is enabled, do not block; exit early
        if (env('MQTT_STRICT_ENQUEUE', true)) {
            Log::warning('[ResumeOrderService] enqueue failed and MQTT_STRICT_ENQUEUE=true - skipping fallback', ['order_id' => $orderId, 'user_id' => $userId]);
            return;
        }

        // 3) Non-blocking background fallback (best effort)
        try {
            $json       = json_encode($payloadArray, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escaped    = escapeshellarg($json);
            $nodeBin    = env('NODE_BIN', 'node');
            $scriptPath = base_path('node_scripts/mqtt_order_publisher.cjs');
            $bgCommand  = escapeshellcmd($nodeBin) . " " . $scriptPath . " " . $escaped . " > /dev/null 2>&1 &";
            @exec($bgCommand);
            Log::warning('[ResumeOrderService] background publisher launched (fallback)', ['order_id' => $orderId, 'user_id' => $userId]);
        } catch (\Throwable $e) {
            Log::warning('[ResumeOrderService] background fallback failed', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
        }
    }

    private function publishToMqtt($topic, $data)
    {
        Log::error('[OrderService] publishToMqtt start', [
            'topic' => $topic ?? null,
            'data_sample' => is_array($data) ? array_slice($data,0,5) : null
        ]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }

    private function checkUserEligibility(Order $order, User $user): bool
    {
        Log::error('[OrderService] checkUserEligibility start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);
        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

    public function handle(Order $order, User $user): array
    {
        // Clear, focused logging for create operation
        \Log::error('[OrderService] handle start', [
            'user_id' => $user->id ?? null,
            'order_id' => $order->id ?? null,
            'order_type' => $order->type ?? null
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
                // ✅ NO PUBLISHING HERE: Order announcements will be sent by ProcessPingResponseBatchJob
                // after devices respond to ping requests. This prevents duplicate publishing.
                Log::info('[OrderService] Action created for user, batch job will handle announcement', [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'note' => 'Announcement will be sent after ping response processed'
                ]);
                return ['message' => 'User processed successfully. Order announcement will be sent via batch job.'];
            }

            return ['message' => 'Action already exists or was handled concurrently.'];

        } catch (\Throwable $e) {
            Log::error('[OrderService] Batch insertion via ResumeOrderService failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Extract canonical identifier from Instagram URL or ID-like strings.
     * Examples:
     * - https://www.instagram.com/username/ -> username
     * - https://www.instagram.com/reel/DKOexUFN1tj -> DKOexUFN1tj
     * - DLsNPlfu1V6 -> DLsNPlfu1V6 (already an id)
     */
    private function extractTargetIdentifier(?string $target)
    {
        if (empty($target)) return null;

        // If it's a pure ID (no slashes and short), return as-is
        $trim = trim($target);
        $trim = rtrim($trim, '/');
        // If the string contains no slash and is reasonable length, use directly
        if (strpos($trim, '/') === false) {
            return $trim;
        }

        // Parse URL and extract the last path segment
        $parts = parse_url($trim);
        if (!empty($parts['path'])) {
            $segments = array_values(array_filter(explode('/', $parts['path'])));
            if (!empty($segments)) {
                return end($segments);
            }
        }

        // Fallback: return raw trimmed string
        return $trim;
    }
}
