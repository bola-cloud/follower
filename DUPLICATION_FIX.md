# Duplication Fix: orders/{user_id} Topic

## Problem Analysis

### Issue Description
Devices are receiving **duplicate order notifications** on the `orders/{user_id}` topic, which causes them to send **duplicate responses** on `order/res/{order_id}/{user_id}` topic. This creates duplicate action updates in the database.

### Root Cause
**Double Publishing** from two sources:

1. **ProcessPingResponseBatchJob** (Batch Processing)
   - After inserting pending actions via `insertPendingActionsChunk()`
   - Publishes via `publishOrderAnnouncementsChunk()` 
   - Location: `app/Jobs/ProcessPingResponseBatchJob.php:140`

2. **OrderService/ResumeOrderService** (Individual Processing)
   - After creating individual actions
   - Publishes via `publishOrderAnnouncement()`
   - Locations:
     - `app/Services/OrderService.php:174` (synchronous publish to first 50 users)
     - `app/Services/OrderService.php:377` (individual user publish)
     - `app/Services/ResumeOrderService.php:70` (re-dispatch pending action)

### Duplication Flow

```
Admin creates order
        ↓
OrderService::handle()
        ↓
batchInsertPendingActions()
├─ Creates 1000 pending actions
├─ Publishes to first 50 users synchronously  ← PUBLISH #1
└─ Sends MQTT ping to devices
        ↓
Devices respond on order/ping/res
        ↓
mqtt_handler batches responses
        ↓
MqttResponseController::triggerOrderBatch
        ↓
ProcessPingResponseBatchJob
├─ Inserts/Updates pending actions
└─ publishOrderAnnouncementsChunk()  ← PUBLISH #2 (DUPLICATE!)
        ↓
Devices receive DUPLICATE orders/{user_id}
        ↓
Devices send DUPLICATE order/res/{order_id}/{user_id}
        ↓
Database gets duplicate action updates
```

---

## Solution Options

### Option 1: Remove Publishing from Services (Recommended)
**Rationale**: Since we're using batch-only processing, let the batch job handle ALL publishing.

**Changes**:
- Remove `publishOrderAnnouncement()` calls from OrderService
- Remove `publishOrderAnnouncement()` calls from ResumeOrderService
- Keep publishing ONLY in `ProcessPingResponseBatchJob`

**Pros**:
- ✅ Single source of truth for publishing
- ✅ No duplication possible
- ✅ Cleaner code flow
- ✅ Better performance (batch publishing is faster)

**Cons**:
- ⚠️ Initial 50 users won't get immediate notification (will wait for batch)
- ⚠️ Devices must wait for batch processing before seeing order

### Option 2: Add Deduplication Check
**Rationale**: Prevent publishing if order already announced to user.

**Changes**:
- Track published orders per user in Redis
- Check before publishing
- Skip if already published

**Pros**:
- ✅ Keeps both publishing paths
- ✅ Works with mixed batch/individual processing

**Cons**:
- ❌ More complex
- ❌ Redis overhead
- ❌ Doesn't fix root cause

### Option 3: Conditional Publishing in Batch Job
**Rationale**: Only publish in batch job if not already published by service.

**Changes**:
- Add flag to pending actions: `announced` (boolean)
- Set `announced=true` when service publishes
- Batch job only publishes if `announced=false`

**Pros**:
- ✅ Flexible for mixed processing
- ✅ No Redis dependency

**Cons**:
- ❌ Requires database schema change
- ❌ More complex logic
- ❌ Still two publishing paths

---

## Recommended Solution: Option 1

Remove publishing from services and centralize in batch job.

### Implementation Steps

#### Step 1: Remove Publishing from OrderService

**File**: `app/Services/OrderService.php`

**Remove lines 167-187** (synchronous publish to first 50 users):
```php
// REMOVE THIS BLOCK:
if ($result['inserted'] > 0) {
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

    Log::error('[OrderService] Synchronously announced order to initial users', [
        'order_id' => $order->id,
        'announced_count' => count($syncChunk)
    ]);
}
```

**Remove lines 352-377** (individual user publish):
```php
// REMOVE THIS BLOCK:
if ($result['inserted'] > 0) {
    // Add focused logging immediately before publish
    $topic = "orders/{$user->id}";
    // ... logging code ...

    // Publish synchronously for this user
    $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
    return ['message' => 'User processed successfully and announcement published.'];
}
```

**Replace with**:
```php
if ($result['inserted'] > 0) {
    Log::info('[OrderService] Action created, announcement will be sent by batch job', [
        'order_id' => $order->id,
        'user_id' => $user->id
    ]);
    return ['message' => 'User processed successfully. Order announcement will be sent via batch job.'];
}
```

#### Step 2: Remove Publishing from ResumeOrderService

**File**: `app/Services/ResumeOrderService.php`

**Remove lines 68-70** (re-dispatch pending action):
```php
// REMOVE THIS:
Log::error('[ResumeOrderService] dispatching publish for existing pending action [TRACE]', [...]);
$this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
```

**Replace with**:
```php
Log::info('[ResumeOrderService] Pending action exists, batch job will handle announcement', [
    'order_id' => $order->id,
    'user_id' => $user->id
]);
```

#### Step 3: Keep Batch Job Publishing (No Changes)

**File**: `app/Jobs/ProcessPingResponseBatchJob.php`

Keep `publishOrderAnnouncementsChunk()` as-is. This is now the ONLY source of order announcements.

#### Step 4: Update Documentation

Add note to `BATCHING_SOLUTION_SUMMARY.md`:
```markdown
## Important: Single Publishing Source

Order announcements (`orders/{user_id}`) are published ONLY by `ProcessPingResponseBatchJob`.
Individual services (OrderService, ResumeOrderService) no longer publish to prevent duplicates.

Flow:
1. Service creates pending action in DB
2. Service sends MQTT ping
3. Device responds on order/ping/res
4. Batch job processes responses
5. Batch job publishes order announcements ← ONLY PUBLISH POINT
```

---

## Alternative Solution: Quick Fix (Redis Deduplication)

If you can't remove service publishing immediately, add deduplication:

### Redis-Based Deduplication

**Add to `publishOrderAnnouncement()` method**:
```php
private function publishOrderAnnouncement($userId, $orderId, $type, $url)
{
    // Check if already published
    $publishKey = "order_published:{$orderId}:{$userId}";
    if (Redis::exists($publishKey)) {
        Log::info('[OrderService] Skipping duplicate publish', [
            'order_id' => $orderId,
            'user_id' => $userId
        ]);
        return;
    }

    // Mark as published (expires in 1 hour)
    Redis::setex($publishKey, 3600, 1);

    // ... existing publish code ...
}
```

**Add to `ProcessPingResponseBatchJob::publishOrderAnnouncementsChunk()`**:
```php
private function publishOrderAnnouncementsChunk(Order $order, array $userIds): int
{
    if (empty($userIds)) {
        return 0;
    }

    // Filter out already-published users
    $unpublishedUsers = [];
    foreach ($userIds as $userId) {
        $publishKey = "order_published:{$order->id}:{$userId}";
        if (!Redis::exists($publishKey)) {
            $unpublishedUsers[] = $userId;
            Redis::setex($publishKey, 3600, 1); // Mark as published
        }
    }

    if (empty($unpublishedUsers)) {
        Log::info('[ProcessPingResponseBatchJob] All users already announced', [
            'order_id' => $order->id,
            'user_count' => count($userIds)
        ]);
        return 0;
    }

    // ... existing publish code using $unpublishedUsers instead of $userIds ...
}
```

---

## Testing the Fix

### Test 1: Single User Order
```bash
# Create order for 1 user
curl -X POST https://egfollow.com/api/orders -d '{
  "user_id": 1,
  "target_url": "https://instagram.com/test",
  "type": "follow",
  "total_count": 1
}'

# Monitor orders/{user_id} topic
mosquitto_sub -h 109.199.112.65 -p 1883 -t "orders/+" -v

# Expected: Only ONE message on orders/{user_id}
```

### Test 2: Batch Order (1000 users)
```bash
# Create order for 1000 users
curl -X POST https://egfollow.com/api/orders -d '{
  "user_id": 1,
  "target_url": "https://instagram.com/test",
  "type": "follow",
  "total_count": 1000
}'

# Monitor for duplicates
mosquitto_sub -h 109.199.112.65 -p 1883 -t "orders/+" -v | tee mqtt_orders.log

# Count duplicates
grep -o "orders/[0-9]*" mqtt_orders.log | sort | uniq -d

# Expected: No duplicates
```

### Test 3: Device Simulation
```bash
# Simulate 100 devices
node node_scripts/load_test_mqtt_simulator.cjs --devices=100 --order-id=123

# Monitor order/res/{order_id}/+ for duplicates
mosquitto_sub -h 109.199.112.65 -p 1883 -t "order/res/123/+" -v | tee mqtt_responses.log

# Count duplicate responses
grep -o "order/res/123/[0-9]*" mqtt_responses.log | sort | uniq -c | awk '$1 > 1'

# Expected: No duplicates
```

### Test 4: Database Check
```sql
-- Check for duplicate actions
SELECT order_id, user_id, COUNT(*) as count
FROM actions
WHERE order_id = 123
GROUP BY order_id, user_id
HAVING COUNT(*) > 1;

-- Expected: No results (no duplicates)
```

---

## Rollback Plan

If issues occur after implementing the fix:

### Immediate Rollback
```bash
# Revert code changes
git checkout HEAD~1 app/Services/OrderService.php
git checkout HEAD~1 app/Services/ResumeOrderService.php

# Clear caches
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

### Emergency: Re-enable Individual Publishing
If you need immediate publishing (before batch processes):

```php
// Add flag to skip batch publishing temporarily
if (env('SKIP_BATCH_PUBLISH', false)) {
    // Re-enable individual publishing
    $this->publishOrderAnnouncement($user->id, $order->id, $order->type, $order->target_url);
}
```

Add to `.env`:
```bash
SKIP_BATCH_PUBLISH=true  # Temporary emergency flag
```

---

## Summary

**Root Cause**: Double publishing from services AND batch job

**Recommended Fix**: Remove publishing from services, centralize in batch job

**Quick Fix Alternative**: Redis-based deduplication

**Expected Result**: Zero duplicate messages on `orders/{user_id}` and `order/res/{order_id}/{user_id}`

**Implementation Time**: 15-30 minutes

**Testing Time**: 30-60 minutes

**Risk Level**: Low (easy rollback, no schema changes)
