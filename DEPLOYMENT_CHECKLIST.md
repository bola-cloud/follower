# 🚀 DEPLOYMENT CHECKLIST

## Pre-Deployment Verification ✅

- [x] Fixed WHERE clause in DrainOrderResponsesJob
- [x] Reduced Redis lock timeout from 300s to 30s  
- [x] Added lock clearing logic in drain job
- [x] Added auto-dispatch for remaining queue items
- [x] Added debug logging for lock status
- [x] Validated syntax - no errors found
- [x] Created deployment guides

## Deployment Steps

### Step 1: Commit and Push (Windows/Local)
```powershell
cd C:\Bola\Followers
git add -A
git commit -m "Fix drain job deadlock: WHERE clause + Redis lock timeout"
git push origin new-batch-code
```

### Step 2: Deploy to Production (SSH to Server)
```bash
cd /home/egfollow/htdocs/egfollow.com
git pull origin new-batch-code
```

### Step 3: Clear Stuck State
```bash
# Clear failed jobs
php artisan queue:flush

# Clear Redis locks
redis-cli -n 2 del drain_job_running:done
redis-cli -n 2 del drain_job_running:external
```

### Step 4: Restart Workers
```bash
php artisan queue:restart
# OR: supervisorctl restart laravel-workers:*
```

### Step 5: Check Queue Backlog
```bash
redis-cli -n 2 llen order_responses:drain_queue:done
redis-cli -n 2 llen order_responses:drain_queue:external
```

### Step 6: Trigger Drain Jobs
```bash
php artisan tinker
\App\Jobs\DrainOrderResponsesJob::dispatch('done');
\App\Jobs\DrainOrderResponsesJob::dispatch('external');
exit
```

### Step 7: Monitor Logs (Real-Time)
```bash
# In separate terminal windows:
tail -f storage/logs/laravel.log | grep "DrainOrderResponsesJob"
tail -f storage/logs/laravel.log | grep "capacity computed"
```

## Success Indicators (Within 2 Minutes)

### ✅ Logs Show:
- `[DrainOrderResponsesJob] Processing batch from drain queue`
- `"updated":XXX` where XXX > 0 (not 0)
- `Queue still has items, workers will continue processing`
- No more "No available slots (pending fills capacity)"
- No more MaxAttemptsExceededException

### ✅ Database Shows:
```bash
# Actions transitioning from pending to done/external
mysql -u root egfollow_db -e "SELECT status, COUNT(*) FROM actions WHERE order_id=4468 GROUP BY status"

# done_count increasing
mysql -u root egfollow_db -e "SELECT done_count, total_count FROM orders WHERE id=4468"
```

### ✅ Redis Shows:
```bash
# Queue lengths decreasing
watch -n 1 'redis-cli -n 2 llen order_responses:drain_queue:done'
```

## Post-Deployment Validation

### Quick Test (5 minutes after deploy):
```bash
# 1. Check drain queue is processing
redis-cli -n 2 llen order_responses:drain_queue:done
redis-cli -n 2 llen order_responses:drain_queue:external
# Should be 0 or decreasing rapidly

# 2. Check done_count is updating
mysql -u root egfollow_db -e "SELECT id, done_count, total_count FROM orders WHERE id IN (4467, 4468) ORDER BY id"
# done_count should be > 0 and increasing

# 3. Check no failed jobs
mysql -u root egfollow_db -e "SELECT COUNT(*) as failed_jobs FROM failed_jobs WHERE created_at > NOW() - INTERVAL 5 MINUTE"
# Should be 0 or decreasing

# 4. Check ProcessPingResponseBatchJob is working
tail -n 100 storage/logs/laravel.log | grep "ProcessPingResponseBatchJob.*completed"
# Should see successful completions with published > 0
```

### Full Validation (10 minutes after deploy):
```bash
# Generate a test order response manually
redis-cli -n 2 rpush order_responses:drain_queue:done '{"order_id":4468,"user_id":99999,"status":"done"}'

# Wait 5 seconds, then check if it was processed
mysql -u root egfollow_db -e "SELECT * FROM actions WHERE order_id=4468 AND user_id=99999"
# Should show status='done' and performed_at updated recently
```

## Rollback Plan (If Needed)

```bash
cd /home/egfollow/htdocs/egfollow.com
git checkout HEAD~1 app/Jobs/DrainOrderResponsesJob.php app/Http/Controllers/Api/MqttResponseController.php
php artisan queue:restart
```

## Emergency Contacts / Notes

- **Issue:** Drain jobs not processing
- **Quick Fix:** Delete Redis locks and manually dispatch
  ```bash
  redis-cli -n 2 del drain_job_running:done drain_job_running:external
  php artisan tinker
  \App\Jobs\DrainOrderResponsesJob::dispatch('done');
  exit
  ```

- **Issue:** Actions still stuck in pending
- **Check:** Verify WHERE clause was deployed correctly
  ```bash
  grep "status.*this->status" app/Jobs/DrainOrderResponsesJob.php
  # Should show: ->where('status', '!=', $this->status)
  ```

---

**Deployment Date:** 2025-10-16  
**Estimated Downtime:** 0 seconds (hot deploy)  
**Estimated Fix Time:** 1-2 minutes for backlog to clear
