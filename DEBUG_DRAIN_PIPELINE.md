# DEBUG GUIDE: Actions Not Updating Despite Receiving Responses

## Situation
- ✅ Devices ARE sending responses to `order/res/{order_id}/{user_id}`
- ❌ Actions are NOT being updated in the database
- Actions remain `pending` forever

## Debugging Pipeline

### 1. Node Handler (Receiving & Batching)

**Check if Node is receiving responses:**
```bash
pm2 logs mqtt-handler --lines 100 | grep "📨 order/res received"
```

**Expected output:**
```
📨 order/res received: order_id=4450, user_id=35658, status=done, ORDER_RES_BATCH_ENABLED=true, batch_size=1
📊 Order response added to batch: 1/1000 (order_id=4450, user_id=35658)
⏱️ Batch timer scheduled: will flush in 500ms if not full
```

**Check if batches are being flushed:**
```bash
pm2 logs mqtt-handler --lines 100 | grep "Flushing order response batch"
```

**Expected output:**
```
🚀 Flushing order response batch: 50 actions (reason: timer)
📋 Sample actions: [{"order_id":4450,"user_id":35658,"status":"done"},...]
✅ Order response batch sent to drain endpoint: batch_id=xxx, size=50, queued=50
```

**❌ If NO "📨 order/res received" messages:**
- Devices are NOT publishing responses
- Check MQTTX to verify messages are published
- Verify topic format: `order/res/{order_id}/{user_id}` exactly

**❌ If received but NOT flushing:**
- Check: `ORDER_RES_BATCH_ENABLED=true`
- Check: Batch timer is being scheduled
- Wait 500ms and check again (timer delay)

---

### 2. Laravel Drain Endpoint (Receiving from Node)

**Check if Laravel receives batches:**
```bash
tail -f storage/logs/laravel.log | grep "MQTT_API_DRAIN"
```

**Expected output:**
```
[MQTT_API_DRAIN] Request received {"content_length":12345,"has_actions":true,"actions_count":50}
[MQTT_API_DRAIN] Batch validated and starting processing {"batch_id":"xxx","total_actions":50,"sample_actions":[...]}
[MQTT_API_DRAIN] Pushing to Redis {"batch_id":"xxx","status":"done","count":50,"queue_key":"order_responses:drain_queue:done","sample":[...]}
[MQTT_API_DRAIN] Pushed to drain queue {"batch_id":"xxx","status":"done","count":50,"queue_length_after":50}
[MQTT_API_DRAIN] Drain job dispatched {"status":"done"}
```

**❌ If NO "Request received" messages:**
- Node is NOT reaching Laravel
- Check Node logs for HTTP errors
- Check: `API_BASE=https://egfollow.com` in PM2 env
- Check NGINX/Apache logs for blocked requests

**❌ If "Validation failed":**
- Check validation errors in logs
- Verify payload format matches expected schema
- Common issue: empty actions array

**❌ If received but NOT "Pushed to drain queue":**
- Check if actions are being grouped by status
- Check if `groupActionsByStatus()` is working
- Check Redis connection

---

### 3. Redis Drain Queue (Storage)

**Check queue length:**
```bash
redis-cli -n 2 llen order_responses:drain_queue:done
redis-cli -n 2 llen order_responses:drain_queue:external
```

**Expected:**
- Should be > 0 if responses are being queued
- Should decrease as DrainJob processes them

**Check queue contents (sample):**
```bash
redis-cli -n 2 lrange order_responses:drain_queue:done 0 2
```

**Expected output:**
```json
{"order_id":4450,"user_id":35658,"status":"done"}
{"order_id":4450,"user_id":35659,"status":"done"}
```

**❌ If queue length is 0:**
- Laravel is NOT pushing to Redis
- Check Laravel logs for Redis errors
- Check Redis connection config in `config/database.php`
- Verify Redis is running: `redis-cli ping`

**❌ If queue is growing but not draining:**
- DrainJob is NOT running or failing
- Proceed to next step

---

### 4. Drain Job (Processing Queue)

**Check if drain job is running:**
```bash
tail -f storage/logs/laravel.log | grep "DrainOrderResponsesJob"
```

**Expected output:**
```
[DrainOrderResponsesJob] Processing batch from drain queue {"status":"done","batch_size":50,"sample_responses":[...]}
[DrainOrderResponsesJob] Updating actions for order {"status":"done","order_id":4450,"user_count":50,"sample_users":[35658,35659,...]}
[DrainOrderResponsesJob] Chunk updated {"status":"done","order_id":4450,"chunk_index":0,"chunk_size":50,"updated":50}
[DrainOrderResponsesJob] Actions updated {"status":"done","order_id":4450,"updated_count":50}
[DrainOrderResponsesJob] Completed updateActions {"status":"done","order_id":4450,"total_updated":50}
[DrainOrderResponsesJob] Batch processed successfully {"status":"done","processed_count":50,"updated_count":50,"duration_ms":123}
```

**❌ If NO "Processing batch" messages:**
- Job is NOT being dispatched
- Check supervisor/queue workers are running:
  ```bash
  sudo supervisorctl status laravel-queues-ultra:*
  ```
- Check queue workers logs:
  ```bash
  tail -f storage/logs/queue-high.log
  ```
- Manually dispatch job for testing:
  ```bash
  php artisan tinker
  \App\Jobs\DrainOrderResponsesJob::dispatch('done');
  ```

**❌ If "Processing batch" but NOT "Chunk updated":**
- SQL UPDATE is failing silently
- Check MySQL errors in Laravel logs
- Check actions table exists and has correct columns
- Manually test query:
  ```sql
  UPDATE actions 
  SET status='done', performed_at=NOW(), updated_at=NOW() 
  WHERE order_id=4450 AND user_id=35658 AND status!='done';
  ```

**❌ If "Chunk updated" but `updated=0`:**
- Actions don't exist OR already updated
- Check if actions exist:
  ```sql
  SELECT * FROM actions WHERE order_id=4450 AND user_id=35658;
  ```
- If status is already 'done' → duplicate responses
- If action doesn't exist → ProcessPingResponseBatchJob didn't create it

---

### 5. Database (Final Verification)

**Check if actions are being updated:**
```sql
-- Check pending actions for order
SELECT status, COUNT(*) FROM actions WHERE order_id=4450 GROUP BY status;

-- Check recent updates
SELECT order_id, user_id, status, performed_at, updated_at 
FROM actions 
WHERE order_id=4450 
ORDER BY updated_at DESC 
LIMIT 10;

-- Check if specific action updated
SELECT * FROM actions WHERE order_id=4450 AND user_id=35658;
```

**Expected after processing:**
- `status='done'`
- `performed_at` and `updated_at` should be recent timestamps

---

## Quick Diagnostic Script

Run this to check entire pipeline in one go:

```bash
#!/bin/bash

echo "=== 1. Node Handler Status ==="
pm2 logs mqtt-handler --lines 50 | grep -E "order/res received|Flushing order response" | tail -10

echo ""
echo "=== 2. Laravel Drain Endpoint ==="
tail -50 storage/logs/laravel.log | grep "MQTT_API_DRAIN" | tail -10

echo ""
echo "=== 3. Redis Queue Length ==="
redis-cli -n 2 llen order_responses:drain_queue:done

echo ""
echo "=== 4. Drain Job Processing ==="
tail -50 storage/logs/laravel.log | grep "DrainOrderResponsesJob" | tail -10

echo ""
echo "=== 5. Queue Workers Status ==="
sudo supervisorctl status laravel-queue-high:* | head -5

echo ""
echo "=== 6. Database Actions Status (Order 4450) ==="
mysql -e "SELECT status, COUNT(*) as count FROM actions WHERE order_id=4450 GROUP BY status;" egfollow_db

echo ""
echo "=== 7. Recent Action Updates ==="
mysql -e "SELECT order_id, user_id, status, updated_at FROM actions WHERE order_id=4450 ORDER BY updated_at DESC LIMIT 5;" egfollow_db
```

---

## Common Issues & Fixes

### Issue 1: Batches not flushing (stuck at low count)
**Symptom:** `batch_size=1` but never flushes  
**Cause:** Batch timeout too high or timer not triggering  
**Fix:** 
```bash
# Restart mqtt-handler with correct timeout
pm2 reload ecosystem.config.cjs --env production --only mqtt-handler
```

### Issue 2: Laravel not receiving batches
**Symptom:** Node logs show "sent to drain endpoint" but Laravel has no logs  
**Cause:** HTTP error, timeout, or wrong URL  
**Fix:**
```bash
# Check Node logs for HTTP errors
pm2 logs mqtt-handler | grep "Order response batch failed"

# Verify API_BASE
pm2 env mqtt-handler | grep API_BASE
```

### Issue 3: Redis queue growing infinitely
**Symptom:** `llen` keeps increasing, never decreases  
**Cause:** Drain job not running or failing  
**Fix:**
```bash
# Check workers
sudo supervisorctl status | grep laravel-queue-high

# Restart workers
sudo supervisorctl restart laravel-queues-ultra:*

# Check for job errors
tail -f storage/logs/laravel.log | grep "DrainOrderResponsesJob.*error"
```

### Issue 4: UPDATE returns 0 rows
**Symptom:** `updated=0` in logs  
**Cause:** Actions already done OR don't exist  
**Fix:**
```sql
-- Check action status
SELECT order_id, user_id, status FROM actions WHERE order_id=4450 LIMIT 10;

-- If all 'done' → duplicate processing (fine)
-- If all 'pending' → UPDATE query condition issue
-- If missing → ProcessPingResponseBatchJob didn't create them
```

---

## Next Steps After Debugging

1. **Identify where the pipeline breaks** using the steps above
2. **Paste the relevant logs** from that step
3. **We'll fix the specific issue** causing the breakdown

The comprehensive logging added will show exactly where responses are being lost.
