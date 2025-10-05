# Deployment Guide: High-Volume Batching System

## Pre-Deployment Checklist

✅ **Code Review**:
- [x] `app/Jobs/ProcessPingResponseBatchJob.php` - New batch processing job
- [x] `app/Http/Controllers/Api/MqttResponseController.php` - Added `triggerOrderBatch` endpoint
- [x] `routes/api.php` - Added `/api/mqtt/trigger-order-batch` route
- [x] `node_scripts/mqtt_handler.cjs` - Added ping response batching logic

✅ **Configuration Files**:
- [x] `.env` - Add batch processing variables
- [x] `ecosystem.config.cjs` - Add batch env vars for mqtt-handler

✅ **Dependencies**:
- PHP >= 8.1
- Redis >= 6.0
- Laravel Queue Workers configured
- PM2 for Node process management

---

## Step-by-Step Deployment

### Step 1: Backup Current System

```bash
# Backup database
mysqldump -u root -p egfollow > backup_$(date +%Y%m%d_%H%M%S).sql

# Backup code
cd /var/www/egfollow.com
tar -czf ../egfollow_backup_$(date +%Y%m%d_%H%M%S).tar.gz .

# Backup Redis data (optional)
redis-cli save
cp /var/lib/redis/dump.rdb /backup/redis_dump_$(date +%Y%m%d_%H%M%S).rdb
```

### Step 2: Pull Code Updates

```bash
cd /var/www/egfollow.com
git fetch origin
git checkout new-batch-code
git pull origin new-batch-code
```

### Step 3: Update Environment Variables

**Laravel (.env)**:
```bash
# Add to .env file
cat >> .env << 'EOF'

# === Ping Response Batching Configuration ===
# Chunk size for processing batched users (controls DB load)
PING_BATCH_PROCESS_CHUNK_SIZE=80

# Delay between chunks in milliseconds (controls publish rate)
PING_BATCH_CHUNK_DELAY_MS=50

# Queue configuration (ensure high-priority queue exists)
QUEUE_CONNECTION=redis
QUEUE_HIGH_PRIORITY=high
EOF
```

**PM2 Ecosystem (ecosystem.config.cjs)**:

Update the `mqtt-handler` app configuration:

```javascript
{
  name: 'mqtt-handler',
  script: './node_scripts/mqtt_handler.cjs',
  instances: 1,
  exec_mode: 'fork',
  env: {
    NODE_ENV: 'production',
    MQTT_BROKER: 'mqtt://109.199.112.65:1883',
    API_BASE: 'https://egfollow.com',
    
    // Existing batch config for action responses
    MQTT_BATCH_ENABLED: 'true',
    MQTT_BATCH_SIZE: '10',
    MQTT_BATCH_TIMEOUT: '2000',
    
    // NEW: Ping response batching
    PING_BATCH_ENABLED: 'true',
    PING_BATCH_SIZE: '100',
    PING_BATCH_TIMEOUT: '500',
    PING_BATCH_MAX_SIZE: '1000',
    
    // Concurrency control
    MQTT_MAX_INFLIGHT: '50',
    MQTT_HTTP_TIMEOUT: '20000'
  }
}
```

### Step 4: Clear Caches

```bash
cd /var/www/egfollow.com

# Clear Laravel caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Rebuild optimized config
php artisan config:cache
php artisan route:cache
```

### Step 5: Restart Queue Workers

```bash
# Gracefully restart all queue workers
php artisan queue:restart

# Wait for workers to finish current jobs
sleep 5

# Check if workers are running (should see high-priority workers)
ps aux | grep "queue:work"

# If no workers running, start them via supervisor
sudo supervisorctl restart laravel-worker:*

# Verify workers started
sudo supervisorctl status | grep laravel-worker
```

### Step 6: Restart MQTT Handler

```bash
# Reload PM2 ecosystem with new env vars
pm2 reload ecosystem.config.cjs --only mqtt-handler

# Or restart with updated env
pm2 restart mqtt-handler --update-env

# Verify it's running with new config
pm2 show mqtt-handler

# Check logs for batch config confirmation
pm2 logs mqtt-handler --lines 50 | grep -i batch
```

### Step 7: Verify Services

```bash
# 1. Check MQTT handler is connected
pm2 logs mqtt-handler --lines 20
# Should see: "✅ Connected to MQTT broker"
# Should see: "✅ Subscribed to topics"

# 2. Check queue workers are running
ps aux | grep "queue:work.*high"
# Should see at least 2-3 workers on high-priority queue

# 3. Check Redis connection
redis-cli ping
# Should return: PONG

# 4. Test batch endpoint
curl -X POST https://egfollow.com/api/mqtt/trigger-order-batch \
  -H "Content-Type: application/json" \
  -d '{
    "batch_id": "test-batch-1",
    "responses": [
      {"order_id": 1, "user_id": 100, "type": "create"},
      {"order_id": 1, "user_id": 101, "type": "create"}
    ]
  }'
# Should return: {"success":true, ...}
```

### Step 8: Test with Low Volume

```bash
# Create a test order with 10 users
# Use your test script or admin dashboard

# Monitor MQTT handler logs for batching
pm2 logs mqtt-handler --lines 100

# You should see:
# 📦 Flushing ping batch: X responses (reason: timer or size_limit)
# ✅ Ping batch processed: X responses

# Monitor Laravel logs for job execution
tail -f storage/logs/laravel.log | grep ProcessPingResponseBatchJob

# You should see:
# [ProcessPingResponseBatchJob] start batch_id=...
# [ProcessPingResponseBatchJob] completed processed=X published=X
```

### Step 9: Gradual Load Testing

**Test 1: 100 Users**
```bash
# Create order with 100 follows
# Monitor for 2-3 minutes
# Check: No errors, all orders published

# Verify metrics
redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"
```

**Test 2: 500 Users**
```bash
# Create order with 500 follows
# Monitor for 5 minutes
# Check: Processing time < 3 seconds, no deadlocks

# Check DB load
mysql -u root -p -e "SHOW PROCESSLIST;"
```

**Test 3: 1000 Users**
```bash
# Create order with 1000 follows
# Monitor for 10 minutes
# Check: All published, no missing orders

# Verify publish count
redis-cli llen egf:mqtt:publish  # Should be low (consumed quickly)
```

---

## Rollback Plan

If issues occur, rollback to previous version:

### Quick Rollback (Disable Batching Only)

**Option 1: Disable in mqtt_handler** (keeps code, disables feature):
```bash
# Update ecosystem.config.cjs
# Set PING_BATCH_ENABLED: 'false'

pm2 reload ecosystem.config.cjs --only mqtt-handler
```

**Option 2: Use previous git commit**:
```bash
cd /var/www/egfollow.com
git log --oneline -10  # Find commit before batching changes
git checkout <previous-commit-hash>

# Clear caches
php artisan config:clear
php artisan route:clear

# Restart services
php artisan queue:restart
pm2 restart mqtt-handler
```

---

## Monitoring Post-Deployment

### Key Metrics to Watch

**1. Batch Processing Metrics** (Redis):
```bash
# Every minute, check current minute's metrics
watch -n 5 'redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"'

# Key fields:
# - total_users: Should match concurrent responses
# - eligible_users: Should be ~70-90% of total
# - processed: Should equal eligible_users
# - published: Should equal processed
# - batches: Number of batch jobs dispatched
```

**2. Laravel Queue Status**:
```bash
# Check queue depth (should stay low)
php artisan queue:monitor high --max=50

# Check failed jobs
php artisan queue:failed

# Retry failed jobs if any
php artisan queue:retry all
```

**3. MQTT Handler Logs**:
```bash
# Watch for batch flush events
pm2 logs mqtt-handler | grep "ping batch"

# Should see regular flushes every 500ms or when batch size reached
```

**4. Database Performance**:
```bash
# Monitor slow queries
mysql -u root -p -e "SELECT * FROM information_schema.PROCESSLIST WHERE TIME > 5;"

# Check InnoDB status
mysql -u root -p -e "SHOW ENGINE INNODB STATUS\G" | grep -A 20 "LATEST DETECTED DEADLOCK"
```

**5. System Resources**:
```bash
# CPU and memory
top -b -n 1 | grep -E "(php|node|mysql|redis)"

# Redis memory
redis-cli info memory | grep used_memory_human

# Disk I/O
iostat -x 1 5
```

---

## Tuning for 5000+ Concurrent

If initial testing is successful, tune for higher load:

**For 5000 Concurrent Responses**:

```bash
# mqtt_handler (ecosystem.config.cjs)
PING_BATCH_SIZE=200                # Larger batches
PING_BATCH_TIMEOUT=300             # Faster flush
PING_BATCH_MAX_SIZE=2000           # Higher emergency threshold

# Laravel (.env)
PING_BATCH_PROCESS_CHUNK_SIZE=100  # Larger chunks
PING_BATCH_CHUNK_DELAY_MS=30       # Faster publish rate
```

**Start Additional Queue Workers**:
```bash
# Run 5 high-priority workers for parallel processing
for i in {1..5}; do
  nohup php artisan queue:work --queue=high --sleep=0 --tries=3 >> storage/logs/queue-worker-$i.log 2>&1 &
done
```

---

## Expected Performance

### Before Batching
- 1000 concurrent responses: ~10-30 seconds processing
- Database deadlocks: Common
- Missing orders: 5-10%
- CPU load: High spikes

### After Batching
- 1000 concurrent responses: ~600-1500ms processing
- Database deadlocks: Rare (chunked inserts)
- Missing orders: 0%
- CPU load: Smooth, distributed

### Publish Rate
- Theoretical max: 96,000/min (chunk size 80 + 50ms delay)
- Practical sustained: 5,000-10,000/min
- Limited by: Eligibility checks, DB throughput

---

## Troubleshooting

### Issue: Batch jobs not processing
**Symptom**: Jobs dispatched but nothing happens  
**Check**:
```bash
# Are workers running?
ps aux | grep "queue:work"

# Is Redis accessible?
redis-cli ping

# Check queue depth
redis-cli llen "queues:high"
```
**Solution**:
```bash
# Start workers
php artisan queue:work --queue=high &

# Or restart supervisor
sudo supervisorctl restart laravel-worker:*
```

### Issue: Slow batch processing
**Symptom**: Jobs take >5 seconds for 100 users  
**Check**:
```bash
# Database slow queries
mysql -u root -p -e "SHOW PROCESSLIST;"

# Check logs for errors
tail -f storage/logs/laravel.log | grep -i error
```
**Solution**:
```bash
# Reduce chunk size
# In .env: PING_BATCH_PROCESS_CHUNK_SIZE=50

# Restart queue workers
php artisan queue:restart
```

### Issue: Batches not flushing
**Symptom**: No "Flushing ping batch" logs  
**Check**:
```bash
pm2 show mqtt-handler | grep PING_BATCH_ENABLED
```
**Solution**:
```bash
# Ensure PING_BATCH_ENABLED=true
pm2 restart mqtt-handler --update-env
```

---

## Support & Logs

**Laravel Logs**:
```bash
tail -f /var/www/egfollow.com/storage/logs/laravel.log
```

**PM2 Logs**:
```bash
pm2 logs mqtt-handler --lines 200
```

**Supervisor Logs**:
```bash
tail -f /var/log/supervisor/laravel-worker-*.log
```

**MySQL Error Log**:
```bash
tail -f /var/log/mysql/error.log
```

---

## Success Criteria

✅ **Deployment successful if**:
1. MQTT handler connects and subscribes to topics
2. Batch endpoint responds with 200 OK
3. Test order (100 users) processes in <2 seconds
4. No database deadlocks during test
5. All orders published (check Redis metrics)
6. Queue workers remain stable for 30 minutes

✅ **Production ready if**:
1. 1000 concurrent responses process in <3 seconds
2. No missing orders after 10 test runs
3. Database CPU < 70% during peak load
4. Queue depth stays < 100 jobs
5. No failed jobs after 1 hour of operation

---

## Next Steps After Deployment

1. **Monitor for 24 hours**: Watch metrics, logs, and database performance
2. **Tune gradually**: Adjust batch sizes and delays based on actual load
3. **Load test incrementally**: Test with 2000, 3000, 5000 users
4. **Document issues**: Keep log of any errors or performance bottlenecks
5. **Plan capacity**: Based on metrics, plan for future scaling (more workers, Redis sharding, etc.)

---

## Contact

For deployment support, check:
- `BATCHING_CONFIGURATION.md` - Full configuration reference
- Laravel logs: `storage/logs/laravel.log`
- PM2 logs: `pm2 logs mqtt-handler`
