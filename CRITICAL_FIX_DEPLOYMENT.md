# 🚨 CRITICAL FIX: Action Status Update Deadlock + Redis Lock Issue

## Root Causes Identified

### Issue #1: Wrong WHERE Clause in DrainOrderResponsesJob
**Actions stuck in `pending` status** - created by initial device pings but never transitioned to `done`/`external`

**DrainOrderResponsesJob** couldn't update them because of incorrect WHERE clause:
```php
// ❌ WRONG: Only excludes 'done', but actions are in 'pending' status
->where('status', '!=', 'done')
```

### Issue #2: Redis Lock Preventing Drain Jobs
**Drain jobs weren't running at all** - Redis lock was set for 5 minutes but jobs finish in 2-3 seconds:

1. First batch arrives → Lock set for 300 seconds → Drain job dispatched
2. Drain job finishes in 2 seconds
3. More batches arrive → Lock still exists → **No new drain jobs dispatched**
4. Queue grows to 5000+ items but nothing processes them
5. Lock expires after 5 minutes, but by then ProcessPingResponseBatchJob has failed with MaxAttemptsExceededException

## The Fixes

### Fix #1: Correct WHERE Clause
Changed `DrainOrderResponsesJob` WHERE clause to:
```php
// ✅ CORRECT: Excludes target status, allows pending→done and pending→external
->where('status', '!=', $this->status)
```

### Fix #2: Reduce Lock Timeout + Auto-Dispatch
1. **Reduced lock timeout** from 300 seconds → 30 seconds
2. **Added lock clearing** in DrainOrderResponsesJob:
   - Clears lock when queue is empty
   - Clears lock on error
   - Dispatches new job immediately if more items remain
3. **Added debug logging** to see when locks are blocking new jobs

## Files Changed

- `app/Jobs/DrainOrderResponsesJob.php`
  - Fixed WHERE clause in `updateActions()` method
  - Added lock clearing logic in `handle()` method
  - Auto-dispatches next job if queue has more items
  
- `app/Http/Controllers/Api/MqttResponseController.php`
  - Reduced lock timeout from 300s → 30s in `startDrainJobIfNeeded()`
  - Added debug logging for lock status

## Deployment Steps

### 1. Deploy the fix
```bash
cd /home/egfollow/htdocs/egfollow.com
git pull origin new-batch-code
```

### 2. Clear failed queue jobs (they're stuck in retry loop)
```bash
# Clear failed jobs table
php artisan queue:clear
php artisan queue:flush

# Or manually delete failed jobs for ProcessPingResponseBatchJob
php artisan tinker
>>> DB::table('failed_jobs')->where('payload', 'like', '%ProcessPingResponseBatchJob%')->delete();
>>> exit
```

### 3. Restart queue workers
```bash
# Restart all queue workers to load new code
php artisan queue:restart

# Or via supervisor
supervisorctl restart laravel-workers:*
```

### 4. Manually trigger drain for existing stuck queues
```bash
php artisan tinker
>>> \App\Jobs\DrainOrderResponsesJob::dispatch('done');
>>> \App\Jobs\DrainOrderResponsesJob::dispatch('external');
>>> exit
```

### 5. Monitor logs and verify fix
```bash
# Watch Laravel logs for drain activity
tail -f storage/logs/laravel.log | grep "DrainOrderResponsesJob"

# Should see lines like:
# [DrainOrderResponsesJob] Chunk updated {"status":"done","order_id":4467,"chunk_index":0,"chunk_size":X,"updated":X}
# where updated > 0 (previously was 0 for pending actions)

# Check Redis queue lengths
redis-cli -n 2 llen order_responses:drain_queue:done
redis-cli -n 2 llen order_responses:drain_queue:external

# Verify actions are updating
mysql -u root egfollow_db -e "SELECT status, COUNT(*) as count FROM actions WHERE order_id=4467 GROUP BY status"

# Should see 'done' and 'external' counts increasing, 'pending' decreasing
```

### 6. Check ProcessPingResponseBatchJob stops failing
```bash
tail -f storage/logs/laravel.log | grep "ProcessPingResponseBatchJob"

# Should see:
# [ProcessPingResponseBatchJob] capacity computed {"done_count":X,...} where X > 0
# No more "MaxAttemptsExceededException" errors
```

## Expected Results After Fix

1. ✅ Drain jobs update actions from `pending` → `done`/`external`
2. ✅ `done_count` increases in orders table
3. ✅ ProcessPingResponseBatchJob sees available slots and stops retrying
4. ✅ New device responses are processed correctly
5. ✅ No more "No available slots" messages in logs

## Verification Queries

```sql
-- Check action status distribution for order 4467
SELECT status, COUNT(*) as count 
FROM actions 
WHERE order_id = 4467 
GROUP BY status;

-- Check order done_count vs total_count
SELECT id, done_count, total_count, status 
FROM orders 
WHERE id = 4467;

-- Count stuck pending actions (should decrease to 0)
SELECT COUNT(*) as stuck_pending
FROM actions
WHERE order_id = 4467 
  AND status = 'pending'
  AND created_at < NOW() - INTERVAL 15 MINUTE;
```

## Rollback (if needed)

If this fix causes issues (unlikely), revert with:
```bash
git checkout HEAD~1 app/Jobs/DrainOrderResponsesJob.php
php artisan queue:restart
```

## Notes

- The bug only affected actions that were created in `pending` status by the first ping
- Once fixed, the backlog of ~1000 pending actions for order 4467 should drain within 1-2 minutes
- Future orders will process correctly from the start
- No data was lost - all responses are in Redis drain queues waiting to be processed

---
**Fix applied:** 2025-10-16 01:10 UTC
**Branch:** new-batch-code
**Priority:** CRITICAL - Fixes complete system deadlock
