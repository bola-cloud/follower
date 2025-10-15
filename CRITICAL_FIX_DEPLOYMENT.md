# 🚨 CRITICAL FIX: Action Status Update Deadlock

## Root Cause Identified

**The system was in a deadlock:**

1. **Actions stuck in `pending` status** - created by initial device pings but never transitioned to `done`/`external`
2. **DrainOrderResponsesJob** couldn't update them because of incorrect WHERE clause:
   ```php
   // ❌ WRONG: Only excludes 'done', but actions are in 'pending' status
   ->where('status', '!=', 'done')
   ```
3. **ProcessPingResponseBatchJob** kept retrying because:
   - `done_count=0` (no actions ever marked done)
   - `pending_count=1000` (all slots filled with stuck pending actions)
   - `available_slots=0` (capacity full with pending)
   - Result: "No available slots, skipping" → infinite retry → MaxAttemptsExceededException

## The Fix

Changed `DrainOrderResponsesJob` WHERE clause to:
```php
// ✅ CORRECT: Excludes target status, allows pending→done and pending→external
->where('status', '!=', $this->status)
```

Now:
- For status='done': updates any action NOT already 'done' (including 'pending')
- For status='external': updates any action NOT already 'external' (including 'pending')

## Files Changed

- `app/Jobs/DrainOrderResponsesJob.php` - Fixed WHERE clause in `updateActions()` method

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
