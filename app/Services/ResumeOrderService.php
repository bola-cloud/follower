<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Jobs\SendMqttToUserJob;
use App\Jobs\PublishPendingActionsBatchJob;
use Carbon\Carbon;

class ResumeOrderService
{
    /**
     * Normalize URL for comparison: remove query params, fragments, protocol, www, trailing slashes
     * This ensures URLs like:
     * - https://www.instagram.com/reel/DRqCdG0DDZS/?igsh=Y3k2bXZ4bXdoMmM2
     * - https://www.instagram.com/reel/DRqCdG0DDZS/?igsh=Y3k2bXZ4bXdoMmM2/
     * - https://instagram.com/reel/DRqCdG0DDZS/
     * - http://www.instagram.com/reel/DRqCdG0DDZS
     * All produce the same normalized URL: instagram.com/reel/DRqCdG0DDZS
     */
    private function normalizeUrl(string $url): string
    {
        // Remove leading/trailing whitespace
        $normalized = trim($url);

        // Remove protocol (http:// or https://)
        $normalized = preg_replace('#^https?://#i', '', $normalized);

        // Remove www. prefix
        $normalized = preg_replace('#^www\.#i', '', $normalized);

        // Remove query string (everything after ?)
        if (($pos = strpos($normalized, '?')) !== false) {
            $normalized = substr($normalized, 0, $pos);
        }

        // Remove fragment (everything after #)
        if (($pos = strpos($normalized, '#')) !== false) {
            $normalized = substr($normalized, 0, $pos);
        }

        // Remove all trailing slashes
        $normalized = rtrim($normalized, '/');

        // Convert to lowercase for case-insensitive comparison
        $normalized = strtolower($normalized);

        return $normalized;
    }

    public function handle(Order $order, User $user): array
    {
        // Focused info logging for resume processing
        Log::error('[ResumeOrderService] handle start', [
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

        // if ($existingAction) {
        //     if ($existingAction->status === 'pending') {
        //         // Re-publish order announcement immediately
        //         $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
        //         return ['message' => 'Pending action re-dispatched for this user.'];
        //     }
        //     return ['error' => 'Action already exists for this user.'];
        // }

        // Create the action - no lock needed since triggerOrder already validated slots
        // Add a final safety check to prevent exceeding total_count (count done + recent pending only)
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

        // Check if action already exists for this user-order combination
        // $existingAction = DB::table('actions')
        //     ->select('status')
        //     ->where('user_id', $user->id)
        //     ->where('order_id', $order->id)
        //     ->first();

        if ($existingAction) {
            if ($existingAction->status === 'pending') {
                // If a pending action already exists for this user, dispatch a job that
                // publishes announcements for pending actions in chunked Redis pipelines.
                try {
                    dispatch(new PublishPendingActionsBatchJob($order->id));
                    Log::info('[ResumeOrderService] Dispatched PublishPendingActionsBatchJob for existing pending action', [
                        'order_id' => $order->id,
                        'user_id' => $user->id
                    ]);
                    return ['message' => 'Pending action exists. Publish job dispatched for pending users.'];
                } catch (\Throwable $e) {
                    Log::warning('[ResumeOrderService] Failed to dispatch PublishPendingActionsBatchJob', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'error' => $e->getMessage()
                    ]);
                    // Fall back to previous behavior: rely on batch job triggered by ping
                    return ['message' => 'Pending action exists. Announcement will be sent via batch job.'];
                }
            }
            // Block if status is done or external
            if (in_array($existingAction->status, ['done', 'external'])) {
                return ['error' => 'User already completed or has external action for this order.'];
            }
            return ['message' => 'Action already exists for this user and order.'];
        }

        try {
            // Create action using high-performance batch system
            $result = $this->batchInsertPendingAction($order, [$user->id]);

                if ($result['inserted'] > 0) {
                    // ✅ NO PUBLISHING HERE: Order announcements will be sent by ProcessPingResponseBatchJob
                    // after devices respond to ping requests. This prevents duplicate publishing.
                    Log::info('[ResumeOrderService] Action inserted, batch job will handle announcement', [
                        'order_id' => $order->id,
                        'user_id' => $user->id,
                        'note' => 'Announcement will be sent after ping response processed'
                    ]);
                    return ['message' => 'User processed successfully. Announcement will be sent via batch job.'];
                } else {
                    return ['message' => 'Action already exists or was handled concurrently.'];
                }

        } catch (\Illuminate\Database\QueryException $e) {
            // Duplicate entry error code from MySQL is 1062 (SQLSTATE 23000)
            if ($e->getCode() === '23000' && strpos($e->getMessage(), '1062') !== false) {
                // Log the race condition for debugging
                \Log::warning('[ResumeOrderService] Race condition detected - duplicate action inserted concurrently', [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);

                // Race: someone else inserted the action concurrently. Fetch it and apply the same decision logic.
                $existingAction = DB::table('actions')
                    ->select('status')
                    ->where('order_id', $order->id)
                    ->where('user_id', $user->id)
                    ->first();

                if ($existingAction) {
                    if ($existingAction->status === 'pending') {
                        $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
                        return ['message' => 'Pending action re-dispatched for this user.'];
                    }
                    if (in_array($existingAction->status, ['done', 'external'])) {
                        return ['error' => 'User already completed or has external action for this order.'];
                    }
                    return ['message' => 'Action already exists for this user and order.'];
                }
            }

            // Not a duplicate or unexpected: rethrow
            throw $e;
        }
    }

    /**
     * High-performance batch insertion system for pending actions
     * Handles up to 5000+ actions per minute with chunking and connection optimization
     */
    public function batchInsertPendingAction(Order $order, array $userIds): array
    {
        Log::error('[ResumeOrderService] batchInsertPendingAction start', [
            'order_id' => $order->id ?? null,
            'user_ids_count' => count($userIds)
        ]);

        // Delegate batch insertion to the centralized BatchActionService
        $batchService = app(\App\Services\BatchActionService::class);
        return $batchService->batchInsertPendingAction($order, $userIds);
    }

    // performBatchInsert moved to BatchActionService

    /**
     * Batch eligibility check: given an order and a list of candidate user IDs,
     * returns the subset of user IDs that are eligible for this order.
     * This avoids calling getEligibleUsers multiple times and eliminates code duplication.
     *
     * @param Order $order The order to check eligibility for
     * @param array $candidateUserIds Array of user IDs to check (e.g., active users)
     * @return array Array of eligible user IDs from the candidates
     */
    public function batchCheckEligibility(Order $order, array $candidateUserIds): array
    {
        if (empty($candidateUserIds)) {
            return [];
        }

        // Normalize target URL for comparisons (strip query params, fragments, protocol, www, trailing slashes)
        $normalizedTarget = $this->normalizeUrl($order->target_url);

        // DEBUG: Log normalization details
        Log::info('[batchCheckEligibility] URL normalization', [
            'order_id' => $order->id,
            'original_url' => $order->target_url,
            'normalized_url' => $normalizedTarget
        ]);

        // Get pending users for this order (intersected with candidates)
        $pendingUserIds = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->whereIn('user_id', $candidateUserIds)
            ->pluck('user_id')
            ->toArray();

        // Count actual done actions
        $actualDoneCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'done')
            ->count();

        // Count recent pending actions (last 30 minutes)
        $recentPendingCount = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;

        // If no remaining slots, only return pending users
        if ($remaining <= 0) {
            return $pendingUserIds;
        }

        // DEBUG: Check how many users already completed this link on OTHER orders
        // Compare normalized URLs directly
        $usersWithSameLink = DB::table('actions as a1')
            ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
            ->whereIn('a1.status', ['done', 'external'])
            ->whereRaw(
                "LOWER(TRIM(TRAILING '/' FROM REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(o1.target_url), '\\\\\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\\\\\.)?', ''))) = ?",
                [$normalizedTarget]
            )
            ->where('o1.id', '!=', $order->id)
            ->whereIn('a1.user_id', $candidateUserIds)
            ->select('a1.user_id', 'o1.id as other_order_id', 'o1.target_url as other_url', 'a1.status')
            ->get();

        if ($usersWithSameLink->isNotEmpty()) {
            Log::warning('[batchCheckEligibility] Found users who already completed same link', [
                'order_id' => $order->id,
                'normalized_target' => $normalizedTarget,
                'users_with_same_link_count' => $usersWithSameLink->count(),
                'sample_users' => $usersWithSameLink->take(10)->map(function($item) {
                    return [
                        'user_id' => $item->user_id,
                        'other_order_id' => $item->other_order_id,
                        'other_url' => $item->other_url,
                        'status' => $item->status
                    ];
                })->toArray()
            ]);
        }

        // Query new eligible users restricted to the candidate IDs
        // ✅ CRITICAL FIX: Exclude users who already took action (done/external) on ANY order with the same target URL
        $eligibleUserIds = DB::table('users')
            ->select('users.id')
            ->where('users.type', 'user')
            ->whereIn('users.id', $candidateUserIds)
            // Exclude users who already have done/external actions on THIS order
            ->whereNotIn('users.id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->where('order_id', $order->id)
                    ->whereIn('status', ['done', 'external']);
            })
            // Exclude pending users (we'll add them separately)
            ->whereNotIn('users.id', $pendingUserIds)
            // Exclude users whose profile_link matches the target username
            // profile_link is stored as username only (e.g., "faris__ahmed25")
            // target_url can be full URL (e.g., "https://www.instagram.com/faris__ahmed25")
            // Extract username from normalized target: instagram.com/faris__ahmed25 -> faris__ahmed25
            ->whereRaw(
                "LOWER(TRIM(users.profile_link)) != ?",
                [strtolower(preg_replace('#^[^/]+/#', '', $normalizedTarget))]
            )
            // ✅ CRITICAL: Exclude users who have done/external on OTHER orders with same target_url
            // Compare normalized URLs directly instead of hashes to avoid mismatch issues
            ->whereNotIn('users.id', function ($sub) use ($order, $normalizedTarget) {
                $sub->select('a1.user_id')
                    ->from('actions as a1')
                    ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                    ->whereIn('a1.status', ['done', 'external'])
                    ->whereRaw(
                        "LOWER(TRIM(TRAILING '/' FROM REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(o1.target_url), '\\\\\\\\\\\\\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\\\\\\\\\\\\\.)?', ''))) = ?",
                        [$normalizedTarget]
                    )
                    ->where('o1.id', '!=', $order->id);
            })
            ->pluck('users.id')
            ->toArray();

        // DEBUG: Check if any users who should be excluded are still in eligible list
        $shouldBeExcluded = $usersWithSameLink->pluck('user_id')->toArray();
        $wronglyIncluded = array_intersect($shouldBeExcluded, $eligibleUserIds);

        if (!empty($wronglyIncluded)) {
            Log::error('[batchCheckEligibility] CRITICAL: Users wrongly included despite completing same link', [
                'order_id' => $order->id,
                'wrongly_included_count' => count($wronglyIncluded),
                'wrongly_included_users' => $wronglyIncluded,
                // 'target_hash' => $targetHash
            ]);
        }

        // Combine pending users (at front) with newly eligible users
        return array_values(array_unique(array_merge($pendingUserIds, $eligibleUserIds)));
    }

    public function checkUserEligibility(Order $order, User $user): bool
    {
        Log::error('[ResumeOrderService] checkUserEligibility start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);

        $eligibleUsers = $this->getEligibleUsers($order);

        // Use a more efficient lookup by creating an array of eligible user IDs
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Check if the user ID exists in the eligible user IDs
        return in_array($user->id, $eligibleUserIds);
    }

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

        // Normalize target URL for comparisons (strip query params, fragments, protocol, www, trailing slashes)
        $normalizedTarget = $this->normalizeUrl($order->target_url);

        // Get new eligible users - EXACTLY matching batchCheckEligibility logic
        // Use DB::table() instead of Eloquent to ensure consistent query structure
        $eligibleUserIds = DB::table('users')
            ->select('users.id')
            ->where('users.type', 'user')
            // Exclude users who already have done/external actions on THIS order
            ->whereNotIn('users.id', function ($q) use ($order) {
                $q->select('user_id')
                    ->from('actions')
                    ->where('order_id', $order->id)
                    ->whereIn('status', ['done', 'external']);
            })
            // Exclude pending users (we'll add them separately)
            ->whereNotIn('users.id', $pendingUserIds)
            // Exclude users whose profile_link matches the target username
            // profile_link is stored as username only (e.g., "faris__ahmed25")
            // target_url can be full URL (e.g., "https://www.instagram.com/faris__ahmed25")
            // Extract username from normalized target: instagram.com/faris__ahmed25 -> faris__ahmed25
            ->whereRaw(
                "LOWER(TRIM(users.profile_link)) != ?",
                [strtolower(preg_replace('#^[^/]+/#', '', $normalizedTarget))]
            )
            // ✅ CRITICAL: Exclude users who have done/external on OTHER orders with same target_url
            // Compare normalized URLs directly instead of hashes to avoid mismatch issues
            ->whereNotIn('users.id', function ($sub) use ($order, $normalizedTarget) {
                $sub->select('a1.user_id')
                    ->from('actions as a1')
                    ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
                    ->whereIn('a1.status', ['done', 'external'])
                    ->whereRaw(
                        "LOWER(TRIM(TRAILING '/' FROM REGEXP_REPLACE(REGEXP_REPLACE(REGEXP_REPLACE(TRIM(o1.target_url), '\\\\\\\\\\\\\\\\?.*$', ''), '#.*$', ''), '^(https?://)?(www\\\\\\\\\\\\\\\\.)?', ''))) = ?",
                        [$normalizedTarget]
                    )
                    ->where('o1.id', '!=', $order->id);
            })
            ->orderBy('users.id', 'desc')
            ->pluck('users.id')
            ->toArray();

        // Get User models for eligible IDs
        $eligibleUsers = User::whereIn('id', $eligibleUserIds)->orderBy('id', 'desc')->get();

        // Combine pending and new eligible users
        $pendingUsers = User::whereIn('id', $pendingUserIds)->get();
        $combinedUsers = $pendingUsers->merge($eligibleUsers);

        // Diagnostic logging: report counts and small samples so operators can
        // tell whether getEligibleUsers returned any candidates or whether
        // exclusions filtered everyone out. This does NOT change behavior.
        try {
            $eligibleIds = $eligibleUsers->pluck('id')->toArray();
            $combinedIds = $combinedUsers->pluck('id')->toArray();
            Log::info('[ResumeOrderService] getEligibleUsers result', [
                'order_id' => $order->id,
                'pending_count' => count($pendingUserIds),
                'eligible_count' => count($eligibleIds),
                'combined_count' => count($combinedIds),
                'eligible_sample' => array_slice($eligibleIds, 0, 20),
                'combined_sample' => array_slice($combinedIds, 0, 20),
                'remaining' => $remaining,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ResumeOrderService] failed to log eligibleUsers result sample', ['error' => $e->getMessage()]);
        }

        return $combinedUsers;
    }

    private function createPendingActionForUser(Order $order, User $user): void
    {
        Log::error('[ResumeOrderService] createPendingActionForUser start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);
        // Check if action already exists
        $existingAction = DB::table('actions')
            ->where('order_id', $order->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$existingAction) {
            DB::table('actions')->insertOrIgnore([
                'order_id' => $order->id,
                'user_id' => $user->id,
                'type' => $order->type,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
    // public function resume(Order $order): array
    // {
    //     $user = auth()->user();

    //     if (!$user) {
    //         return ['error' => 'User not authenticated.'];
    //     }

    //     // ❌ Apply 12-hour cooldown ONLY for non-admins
    //     if ($user->type !== 'admin' && $order->updated_at->diffInHours(now()) < 12) {
    //         return [
    //             'error' => 'Cannot resume order. Please wait 12 hours before trying again.',
    //             'hours_remaining' => 12 - $order->updated_at->diffInHours(now()),
    //         ];
    //     }


    //     // Re-send to pending users using ping validation (only fresh pending actions)
    //     $pendingUserIds = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'pending')
    //         ->pluck('user_id')
    //         ->toArray();

    //     $pendingUsers = User::whereIn('id', $pendingUserIds)->get();

    //     // Recalculate actual done count and recent pending actions from database
    //     $actualDoneCount = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'done')
    //         ->count();

    //     // Count pending actions created within the past 30 minutes
    //     $recentPendingCount = DB::table('actions')
    //         ->where('order_id', $order->id)
    //         ->where('status', 'pending')
    //         ->where('created_at', '>=', now()->subMinutes(30))
    //         ->count();

    //     $remaining = $order->total_count - $actualDoneCount - $recentPendingCount;
    //     if ($remaining <= 0) {
    //         // Only send ping for pending users if there are any and no remaining work
    //         if ($pendingUsers->count() > 0) {
    //             $this->sendMqttToEligibleUsersWithPing($order, $pendingUsers->count());
    //         }
    //         return [
    //             'message' => 'No remaining actions needed.',
    //             'pending_resend_count' => count($pendingUsers),
    //             'new_eligible_count' => 0
    //         ];
    //     }

    //     // Select new eligible users
    //     $eligibleUsers = User::where('type', 'user')
    //         ->orderBy('id', 'desc')
    //         ->whereNotIn('id', function ($q) use ($order) {
    //             $q->select('user_id')->from('actions')->where('order_id', $order->id);
    //         })
    //         ->whereNotIn('id', $pendingUserIds)
    //         ->where('profile_link', '!=', $order->target_url)
    //         ->whereNotIn('id', function ($sub) use ($order) {
    //             $sub->select('a1.user_id')
    //                 ->from('actions as a1')
    //                 ->join('orders as o1', 'a1.order_id', '=', 'o1.id')
    //                 ->where('a1.status', 'done')
    //                 ->whereColumn('o1.target_url', 'users.profile_link')
    //                 ->where('o1.user_id', $order->user_id);
    //         })
    //         ->limit($remaining)
    //         ->get();

    //     // Insert actions with duplicate protection
    //     $now = now();
    //     $actions = $eligibleUsers->map(function ($user) use ($order, $now) {
    //         return [
    //             'order_id' => $order->id,
    //             'user_id' => $user->id,
    //             'type' => $order->type,
    //             'status' => 'pending',
    //             'created_at' => $now,
    //             'updated_at' => $now,
    //         ];
    //     });

    //     // Use optimized batch service for better connection management
    //     $batchService = app(\App\Services\BatchDatabaseService::class);

    //     try {
    //         $userIds = $eligibleUsers->pluck('id')->toArray();

    //         // Use batch service for optimized insertion
    //         $inserted = $batchService->createOrderActions($order, $userIds);

    //         Log::info('[ResumeOrderService] Batch actions created', [
    //             'order_id' => $order->id,
    //             'attempted' => count($userIds),
    //             'inserted' => $inserted
    //         ]);

    //     } catch (\Throwable $e) {
    //         Log::error('[ResumeOrderService] Failed batch insert of actions', [
    //             'order_id' => $order->id,
    //             'error' => $e->getMessage()
    //         ]);
    //         throw $e;
    //     }

    //     // Send ONE ping for the order (covers both pending and new users)
    //     $this->sendMqttToEligibleUsersWithPing($order, $remaining);

    //     $order->touch();

    //     return [
    //         'message' => 'Order resumed successfully.',
    //         'pending_resend_count' => count($pendingUsers),
    //         'new_eligible_count' => count($eligibleUsers)
    //     ];
    // }

    /**
     * 🔄 SYNCHRONOUS ORDER ANNOUNCEMENT
     * Publish order to users immediately (not queued) to ensure MQTT handler
     * knows about the order before devices respond to pings
     */

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
    Log::error('[ResumeOrderService] publishOrderAnnouncement payload', $payloadArray);

        // 1) Fast path: enqueue to Redis worker
        $enqueued = false;
        try {
            $publisher = app(\App\Services\MqttPublisherRedis::class);
            $enqueued = (bool) $publisher->enqueue("orders/{$userId}", $payloadArray, 0, false);
        } catch (\Throwable $e) {
            Log::warning('[ResumeOrderService] MqttPublisherRedis->enqueue failed', ['error' => $e->getMessage(), 'order_id' => $orderId, 'user_id' => $userId]);
        }

        if ($enqueued) {
            Log::error('[ResumeOrderService] Enqueued publish job (queue mode)', ['order_id' => $orderId, 'user_id' => $userId, 'queue_key' => env('MQTT_QUEUE_KEY', env('REDIS_QUEUE_KEY', 'mqtt:publish'))]);
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

    /**
     * Run a shell command with a timeout (in milliseconds) using proc_open.
     * Returns array with keys: output (string), exit_code (int), duration_ms (float)
     */
    private function runCommandWithTimeout(string $command, int $timeoutMs): array
    {
        $start = microtime(true);

        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            return ['output' => '', 'exit_code' => 1, 'duration_ms' => 0.0];
        }

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $timeoutSec = $timeoutMs / 1000;

        $status = proc_get_status($process);
        while ($status['running']) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            // Wait up to 100ms for output
            $ready = stream_select($read, $write, $except, 0, 100000);
            if ($ready > 0) {
                foreach ($read as $r) {
                    $chunk = stream_get_contents($r);
                    if ($chunk !== false && $chunk !== '') {
                        $output .= $chunk;
                    }
                }
            }

            // Check timeout
            $elapsed = microtime(true) - $start;
            if ($elapsed >= $timeoutSec) {
                // Timeout reached: terminate process
                try { proc_terminate($process); } catch (\Throwable $e) {}
                $output .= "\n[timeout] process killed after {$timeoutMs}ms";
                break;
            }

            usleep(100000); // 100ms
            $status = proc_get_status($process);
        }

        // Read any remaining output
        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);

        foreach ($pipes as $p) {
            @fclose($p);
        }

        $exitCode = proc_close($process);
        $durationMs = round((microtime(true) - $start) * 1000, 2);

        return ['output' => trim($output), 'exit_code' => $exitCode, 'duration_ms' => $durationMs];
    }

    private function dispatchMqttJob(Order $order, User $user): void
    {
        Log::error('[ResumeOrderService] dispatchMqttJob start', [
            'order_id' => $order->id ?? null,
            'user_id' => $user->id ?? null
        ]);
        dispatch(new SendMqttToUserJob(
            $user->id,
            $order->id,
            $order->type,
            $order->target_url
        ));
    }
    private function sendMqttToEligibleUsersWithPing(Order $order, $remaining): void
    {
        Log::error('[ResumeOrderService] sendMqttToEligibleUsersWithPing start', [
            'order_id' => $order->id ?? null,
            'remaining' => $remaining ?? null
        ]);
        $orderData = [
            'type' => 'resume',
            'order_id' => $order->id,
            'activation' => true
        ];

        // Respect a global minimum interval between order pings to avoid
        // creating bursts of device responses that can overwhelm the broker.
        // This uses a Redis-stored timestamp and an env-configurable interval
        // (milliseconds). Default is 2000ms (2s).
        try {
            $this->rateLimitOrderPing();
        } catch (\Throwable $e) {
            // best-effort; continue to publish if limiter fails
            Log::warning('[ResumeOrderService] rateLimitOrderPing failed', ['error' => $e->getMessage()]);
        }

        $this->publishToMqtt('order/ping/req', $orderData);

        // Log::info("[ResumeOrderService] Sent resumed order {$order->id} directly to `order/ping/req` via MQTT");
    }

    private function sendMqttPing(Order $order): void
    {
        Log::error('[ResumeOrderService] sendMqttPing start', [
            'order_id' => $order->id ?? null
        ]);
        $pingData = [
            'type' => 'resume',
            'order_id' => $order->id,
            'activation' => true
        ];

        try {
            $this->rateLimitOrderPing();
        } catch (\Throwable $e) {
            Log::warning('[ResumeOrderService] rateLimitOrderPing failed', ['error' => $e->getMessage()]);
        }

        $this->publishToMqtt('order/ping/req', $pingData);

        // Log::info("[ResumeOrderService] Sent ping for resumed order {$order->id} to `order/ping/req` via MQTT");
    }

    private function publishToMqtt($topic, $data)
    {
        Log::error('[ResumeOrderService] publishToMqtt start', [
            'topic' => $topic ?? null,
            'data_sample' => is_array($data) ? array_slice($data,0,5) : null
        ]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");
    }

    /**
     * Rate-limit publishing of order pings to avoid bursting many orders at once.
     * Uses Redis key defined by ORDER_PING_LAST_KEY and env ORDER_PING_MIN_INTERVAL_MS
     * (milliseconds). This is a best-effort limiter intended to stagger order
     * ping publications across processes.
     */
    private function rateLimitOrderPing(): void
    {
        $redis = null;
        try {
            $redis = app('redis')->connection();
        } catch (\Throwable $e) {
            return; // cannot rate-limit without Redis
        }

        $key = env('ORDER_PING_LAST_KEY', 'order:ping:last_ts');
        $minIntervalMs = (int) env('ORDER_PING_MIN_INTERVAL_MS', 2000); // default 2s

        try {
            $nowMs = (int) round(microtime(true) * 1000);
            $lastMs = (int) $redis->get($key);
            $diff = $nowMs - $lastMs;
            if ($diff < $minIntervalMs && $diff > -10000) { // ignore crazy past values
                $sleepMs = $minIntervalMs - $diff;
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
                $nowMs = (int) round(microtime(true) * 1000);
            }

            // Update last ping time
            $redis->set($key, $nowMs);
            // Optional: set TTL to avoid stale keys (keep a few minutes)
            $redis->expire($key, max(60, (int) ceil($minIntervalMs / 1000) * 5));
        } catch (\Throwable $e) {
            // best-effort only
            return;
        }
    }
}
