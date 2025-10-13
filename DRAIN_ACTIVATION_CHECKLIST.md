# Drain Queue Activation Checklist

## Problem Report
- **Expected**: 2925 responses processed
- **Actual**: Only 1073 actions updated (36.7% success, 63.3% loss)
- **No drain logs visible** in laravel.log

## Root Cause Analysis

The drain system code is deployed but **NOT ACTIVE** because:

### 1. **Node.js Process Not Restarted**
The code is deployed but the running Node process (pm2) is still using the **old version** that doesn't call the drain endpoint.

**Evidence:**
- No `[MQTT_API_DRAIN]` logs in laravel.log
- No `DrainOrderResponsesJob` logs
- Still seeing old batch processing logs: `[MQTT_API_BATCH]`, `[ProcessOrderResponseBatchJob]`

### 2. **Missing Configuration**
Even if Node is restarted, the drain system needs proper configuration in `.env`.

---

## Activation Steps (Production Deployment)

### Step 1: Verify Code is Pulled
```bash
cd /home/egfollow/htdocs/egfollow.com
git status
git branch  # Should show: * new-batch-code
git pull origin new-batch-code
```

### Step 2: Clear Laravel Caches
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
composer dump-autoload
```

### Step 3: Verify .env Configuration
Check that these settings exist in `.env`:

```bash
# Queue settings (REQUIRED for drain)
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis

# Drain batch size (optional, default 200)
DRAIN_BATCH_SIZE=200

# Node batch settings (already present, verify they match)
ORDER_RES_BATCH_ENABLED=true
ORDER_RES_BATCH_SIZE=200
ORDER_RES_BATCH_TIMEOUT=200
ORDER_RES_BATCH_MAX_SIZE=500
```

### Step 4: Restart Supervisor (Queue Workers)
```bash
# Reread config and restart all workers
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queues-ultra:*

# Verify workers are running
sudo supervisorctl status | grep laravel-queue
```

**Expected output:**
```
laravel-queue-high:laravel-queue-high_00   RUNNING   pid 12345, uptime 0:00:05
laravel-queue-high:laravel-queue-high_01   RUNNING   pid 12346, uptime 0:00:05
...
(should see 16 high-priority workers running)
```

### Step 5: Restart Node.js (pm2) - **CRITICAL**
```bash
# This activates the drain endpoint calls
pm2 restart mqtt_handler

# Verify it's running and check logs
pm2 list
pm2 logs mqtt_handler --lines 20
```

**Look for in pm2 logs:**
```
✅ Order response batch queued to drain: 200 actions
```

### Step 6: Monitor Drain Queue (Real-time)
Open a separate terminal and watch the drain queue:

```bash
# Watch queue length in real-time
watch -n 1 'redis-cli llen order_responses:drain_queue:done'

# Or manual check
redis-cli llen order_responses:drain_queue:done
redis-cli llen order_responses:drain_queue:external
```

### Step 7: Check Laravel Logs for Drain Activity
```bash
tail -f storage/logs/laravel.log | grep -E 'DRAIN|DrainOrderResponses'
```

**Expected logs when drain is active:**
```
[MQTT_API_DRAIN] Batch received for drain queue {"batch_id":"...","total_actions":200}
[MQTT_API_DRAIN] Pushed to drain queue {"status":"done","count":200}
[MQTT_API_DRAIN] Drain job dispatched {"status":"done"}
[DrainOrderResponsesJob] Processing batch from drain queue {"batch_size":200}
[DrainOrderResponsesJob] Batch processed successfully {"processed_count":200,"updated_count":198}
[DrainOrderResponsesJob] Rescheduling drain job {"remaining_count":1500}
```

---

## Quick Diagnostic Commands

### Check if drain endpoint exists
```bash
php artisan route:list | grep drain
```
**Expected:** `POST api/mqtt/response-batch-drain`

### Check if DrainOrderResponsesJob class exists
```bash
php artisan tinker
>>> class_exists('\App\Jobs\DrainOrderResponsesJob');
# Should return: true
```

### Test drain queue manually (safe test)
```bash
# Push a test item to drain queue
redis-cli rpush order_responses:drain_queue:done '{"order_id":999,"user_id":1}'

# Check queue length
redis-cli llen order_responses:drain_queue:done
# Should return: 1

# Trigger drain job manually
php artisan queue:work redis --queue=high --once

# Check queue again (should be 0 if processed)
redis-cli llen order_responses:drain_queue:done
```

---

## Troubleshooting

### Issue: No drain logs after pm2 restart
**Cause:** Node is still calling old endpoint

**Solution:**
```bash
# Force reload the Node script
pm2 delete mqtt_handler
pm2 start ecosystem.config.cjs

# OR completely restart pm2
pm2 restart all
```

### Issue: Drain logs appear but queue keeps growing
**Cause:** Not enough high-priority workers

**Solution:**
Check supervisor config has enough workers:
```bash
grep numprocs laravel-workers-ultra-batch.conf | grep high
# Should show: numprocs=16
```

If less than 10, increase it and restart:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queues-ultra:laravel-queue-high:*
```

### Issue: "Class DrainOrderResponsesJob not found"
**Cause:** Composer autoload not updated

**Solution:**
```bash
composer dump-autoload
php artisan config:clear
```

---

## Success Indicators

### ✅ Drain System is Working When You See:

1. **In pm2 logs:**
   ```
   ✅ Order response batch queued to drain: 200 actions
   ```

2. **In Laravel logs:**
   ```
   [MQTT_API_DRAIN] Batch received for drain queue
   [DrainOrderResponsesJob] Processing batch from drain queue
   [DrainOrderResponsesJob] Batch processed successfully
   ```

3. **In Redis:**
   ```bash
   redis-cli llen order_responses:drain_queue:done
   # Shows increasing numbers during burst, then draining to 0
   ```

4. **In database:**
   - DB action count matches sent response count (98-100% success rate)
   - Orders marked as completed when done_count >= total_count

---

## Performance Expectations

With drain queue active:

| Responses | Expected Time | Success Rate | Queue Behavior |
|-----------|---------------|--------------|----------------|
| 1000      | 5-10s         | 98-100%      | Peak ~1000, drains quickly |
| 3000      | 15-25s        | 98-100%      | Peak ~3000, steady drain |
| 5000      | 20-40s        | 98-100%      | Peak ~5000, gradual drain |

**Without drain (current problem):**
- 2925 responses → only 1073 updated (36.7% success)
- Data loss due to race conditions, deduplication errors, queue overload

---

## Next Steps After Activation

1. Deploy following the steps above
2. Run a **small test** (send 500 responses) and verify logs show drain activity
3. Check DB count matches sent count (should be 490-500 updated)
4. If successful, proceed with larger tests: 1000 → 3000 → 5000
5. Report back with logs showing:
   - pm2 logs (drain endpoint calls)
   - Laravel logs (drain job processing)
   - Redis queue lengths during processing

---

## Emergency Rollback

If drain causes issues, roll back by editing `node_scripts/mqtt_handler.cjs`:

```javascript
// Change line 258 from:
`${API_BASE}/api/mqtt/response-batch-drain`,

// To:
`${API_BASE}/api/mqtt/response-batch`,
```

Then restart pm2:
```bash
pm2 restart mqtt_handler
```

This reverts to the old batch processing (with data loss) but keeps the system running.
