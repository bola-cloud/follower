# Deployment Checklist: Order Response High-Load Optimization

## Pre-Deployment Verification

### 1. Verify Redis is Running
```powershell
# Check Redis status
redis-cli ping
# Expected output: PONG

# Check Redis memory
redis-cli info memory
# Ensure available memory > 500MB
```

### 2. Backup Current Configuration
```powershell
# Backup .env
Copy-Item .env .env.backup.$(Get-Date -Format "yyyyMMddHHmmss")

# Backup supervisor config
Copy-Item laravel-workers-ultra-batch.conf laravel-workers-ultra-batch.conf.backup
```

### 3. Verify Database Indexes
```sql
-- Run this SQL to ensure proper indexes exist
SHOW INDEX FROM actions WHERE Key_name = 'actions_order_user_status_idx';

-- If not exists, create it:
CREATE INDEX actions_order_user_status_idx 
ON actions(order_id, user_id, status);
```

## Deployment Steps

### Step 1: Update .env File
```powershell
# CRITICAL: Change QUEUE_CONNECTION from sync to redis
(Get-Content .env) -replace 'QUEUE_CONNECTION=sync', 'QUEUE_CONNECTION=redis' | Set-Content .env

# Verify the change
Select-String -Path .env -Pattern "QUEUE_CONNECTION"
# Expected: QUEUE_CONNECTION=redis
```

### Step 2: Clear Configuration Cache
```powershell
# Clear Laravel caches
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
```

### Step 3: Restart Queue Workers (Linux Production Server)
**Note: Run these commands on your production server via SSH**

```bash
# Stop all queue workers
sudo supervisorctl stop laravel-queues-ultra:*

# Wait 5 seconds for graceful shutdown
sleep 5

# Verify no workers are running
ps aux | grep "queue:work"

# Start workers
sudo supervisorctl start laravel-queues-ultra:*

# Verify workers started
sudo supervisorctl status laravel-queues-ultra:*
# Expected: All processes should show "RUNNING"
```

### Step 4: Restart Node MQTT Handler
**Run on production server:**

```bash
# If using PM2
pm2 restart mqtt_handler

# Verify it's running
pm2 list | grep mqtt_handler
# Expected: status should be "online"

# Check logs for startup messages
pm2 logs mqtt_handler --lines 20
# Expected: "✅ Subscribed to topic: order/res/+/+"
```

### Step 5: Clear Stale Redis Keys
```bash
# Clear any old deduplication keys (optional but recommended)
redis-cli --scan --pattern "action_processing:*" | xargs redis-cli DEL

# Verify queue is empty before starting
redis-cli llen queues:high
# Expected: 0 or small number
```

## Post-Deployment Verification

### 1. Verify Queue Workers are Processing
```bash
# Watch queue size (should stay low, < 100)
watch -n 1 'redis-cli llen queues:high'

# Monitor worker logs
tail -f /home/egfollow/htdocs/egfollow.com/storage/logs/queue-high.log

# Expected log entries:
# "[ProcessOrderResponseBatchJob] Starting batch processing"
# "[ProcessOrderResponseBatchJob] Batch completed successfully"
```

### 2. Test with Small Load
```powershell
# From development machine, send test MQTT messages
# This simulates 10 order responses
cd node_scripts
node -e "
const mqtt = require('mqtt');
const client = mqtt.connect('mqtt://109.199.112.65:1883');
client.on('connect', () => {
  for(let i=1; i<=10; i++) {
    client.publish('order/res/1/'+i, JSON.stringify({status:'done'}));
  }
  setTimeout(() => client.end(), 1000);
});
"
```

### 3. Check Database Updates
```sql
-- Verify actions were updated (run 5-10 seconds after test)
SELECT COUNT(*) FROM actions 
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE);
-- Expected: Should show new updates

-- Check order done_count incremented
SELECT id, done_count, total_count, updated_at 
FROM orders 
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
ORDER BY updated_at DESC 
LIMIT 5;
```

### 4. Monitor Queue Metrics
```bash
# Check Redis metrics for last minute
redis-cli hgetall "order_res_batch_metrics:$(date +%Y%m%d%H%M)"

# Expected output (example):
# "total_responses" "120"
# "updated_count" "118"
# "batch_count" "3"
# "max_duration_ms" "340"
```

### 5. Check for Failed Jobs
```bash
# List any failed jobs
php artisan queue:failed

# Expected: Empty list or only old failures
# If new failures appear, investigate with:
php artisan queue:retry all  # Retry all failed jobs
```

## Load Testing (Optional but Recommended)

### Simulate 4000 Actions in 5 Seconds
```bash
# Run on production or staging server
cd node_scripts

# Using the load test simulator (if available)
node load_test_mqtt_simulator.cjs --actions=4000 --duration=5 --topic=order/res

# OR create a simple test script:
node -e "
const mqtt = require('mqtt');
const client = mqtt.connect('mqtt://109.199.112.65:1883');
let sent = 0;
client.on('connect', () => {
  const interval = setInterval(() => {
    for(let i=0; i<800; i++) {
      client.publish(\`order/res/1/\${sent++}\`, JSON.stringify({status:'done'}));
    }
    if(sent >= 4000) {
      clearInterval(interval);
      setTimeout(() => client.end(), 1000);
    }
  }, 1000);
});
"
```

### Verify Load Test Results
```bash
# 1. Check queue depth stayed manageable
redis-cli llen queues:high
# Expected: < 500 even during load

# 2. Check processing metrics
redis-cli hgetall "order_res_batch_metrics:$(date +%Y%m%d%H%M)"
# Expected: total_responses ≈ 4000, updated_count > 3900

# 3. Check database updates
mysql -u root followers -e "
SELECT COUNT(*) as total_updated 
FROM actions 
WHERE updated_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE);
"
# Expected: 3900-4000 (99%+ success rate)

# 4. Check average processing time
tail -500 storage/logs/queue-high.log | grep "duration_ms" | 
  awk '{print $NF}' | awk '{sum+=$1; count++} END {print sum/count "ms"}'
# Expected: < 500ms per batch
```

## Monitoring Setup (Ongoing)

### 1. Queue Depth Alert
```bash
# Add to cron or monitoring system
# Alert if queue depth > 1000 for > 5 minutes
* * * * * [ $(redis-cli llen queues:high) -gt 1000 ] && echo "HIGH QUEUE DEPTH" | mail -s "Queue Alert" admin@example.com
```

### 2. Failed Jobs Alert
```bash
# Check failed jobs daily
0 8 * * * php /path/to/artisan queue:failed --format=json | jq length | mail -s "Failed Jobs Count" admin@example.com
```

### 3. Worker Health Check
```bash
# Ensure workers are running
*/5 * * * * [ $(sudo supervisorctl status laravel-queues-ultra:* | grep RUNNING | wc -l) -lt 40 ] && sudo supervisorctl restart laravel-queues-ultra:*
```

## Rollback Procedure (If Issues Occur)

### Emergency Rollback
```powershell
# 1. Restore .env backup
Copy-Item .env.backup.YYYYMMDDHHMMSS .env -Force

# 2. Clear config cache
php artisan config:clear

# 3. On production server, restart workers
# SSH to server and run:
sudo supervisorctl restart laravel-queues-ultra:*
pm2 restart mqtt_handler
```

### Verify Rollback
```bash
# 1. Check .env
grep QUEUE_CONNECTION .env
# Should show previous value

# 2. Test one action
curl -X POST https://egfollow.com/api/mqtt/response \
  -H "Content-Type: application/json" \
  -d '{"order_id":1,"user_id":1,"status":"done"}'
```

## Troubleshooting

### Issue: Queue not processing
```bash
# Check queue connection
php artisan tinker
>>> Redis::connection()->ping()
# Expected: "+PONG"

# Check workers are running
sudo supervisorctl status | grep laravel-queue
# All should show RUNNING

# Manually dispatch test job
>>> dispatch(new \App\Jobs\ProcessOrderResponseBatchJob([['order_id'=>1,'user_id'=>1,'status'=>'done']], 'done', 'test'));
# Check logs: tail -f storage/logs/queue-high.log
```

### Issue: High Redis memory usage
```bash
# Check memory
redis-cli info memory | grep used_memory_human

# Clear deduplication keys (they auto-expire but can clear manually)
redis-cli --scan --pattern "action_processing:*" | xargs redis-cli DEL

# Restart Redis if memory is critical
sudo systemctl restart redis
```

### Issue: Actions still missing
```bash
# Check node handler logs
pm2 logs mqtt_handler --lines 100 | grep "Order response batch"

# Verify HTTP endpoint is reachable
curl -X POST https://egfollow.com/api/mqtt/response-batch \
  -H "Content-Type: application/json" \
  -d '{"actions":[{"order_id":1,"user_id":1,"status":"done"}]}'

# Check for HTTP errors in Laravel logs
tail -100 storage/logs/laravel.log | grep "MQTT_API_BATCH"
```

## Success Criteria

✅ Queue workers showing "RUNNING" in supervisorctl  
✅ Redis queue depth < 100 under normal load  
✅ 99%+ of actions updating in database  
✅ Processing time < 2 seconds for 4000 actions  
✅ No failed jobs accumulating  
✅ Worker logs showing successful batch completions  
✅ Redis memory usage stable (not growing unbounded)  

## Documentation

After successful deployment, update these files:
- [X] `.env` - Changed QUEUE_CONNECTION to redis
- [X] `ORDER_RESPONSE_HIGH_LOAD_OPTIMIZATION.md` - Full technical documentation
- [X] `.env.high-load-optimized` - Reference configuration
- [ ] `README.md` - Add link to optimization docs
- [ ] Team wiki/docs - Document the changes and monitoring procedures

## Support Contacts

If issues persist after deployment:
1. Check logs: `storage/logs/queue-high.log`, `pm2 logs mqtt_handler`
2. Review documentation: `ORDER_RESPONSE_HIGH_LOAD_OPTIMIZATION.md`
3. Verify configuration: `.env.high-load-optimized` reference
4. Run health checks from this checklist
