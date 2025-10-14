# Critical Fix: Order Blocking Issue

## Problem Analysis

### Root Causes Identified

1. **ProcessPingResponseBatchJob bottleneck** (PRIMARY ISSUE)
   - Node sends 1000 ping responses per batch (`PING_BATCH_SIZE=1000`)
   - Laravel processes only 500 at a time (`PING_BATCH_PROCESS_CHUNK_SIZE=500`)
   - Each chunk has 50ms delay → 2+ chunks × 50ms = 100ms+ just for delays
   - Job runs on `high` queue with only 32 workers
   - **Result**: First 3000-user order creates 3 batches that saturate the queue, second order waits

2. **Insufficient queue workers**
   - Only 32 workers on `high` queue
   - Each `ProcessPingResponseBatchJob` takes 2-5 seconds
   - Multiple concurrent orders saturate available workers

3. **Mismatched batch sizes**
   - Node: `PING_BATCH_SIZE=1000`, `ORDER_RES_BATCH_SIZE=1000`
   - Laravel: `PING_BATCH_PROCESS_CHUNK_SIZE=500`, `BATCH_ACTION_CHUNK_SIZE=500`
   - Mismatch causes unnecessary chunking and delays

4. **Rate limiting too restrictive**
   - `BATCH_ACTION_MAX_PER_MIN=5000` (only 5k actions/min)
   - For two 3000-action orders, that's 6k actions needed immediately
   - Rate limiter will delay/enqueue second order's actions

## Solution: Apply These Changes

### 1. Update Server .env (PRIORITY 1 - CRITICAL)

Add/update these lines in your production `.env`:

```bash
# === CRITICAL FIX FOR ORDER BLOCKING ===

# Increase chunk size to match Node batch size (reduce iterations)
PING_BATCH_PROCESS_CHUNK_SIZE=1000

# Reduce inter-chunk delay for faster processing
PING_BATCH_CHUNK_DELAY_MS=10

# Increase action batch sizes to match
BATCH_ACTION_CHUNK_SIZE=1000
BATCH_DB_TX_CHUNK_SIZE=1000

# CRITICAL: Increase rate limit to allow multiple concurrent orders
# For 2 orders × 3000 actions each = 6000 actions needed per minute minimum
BATCH_ACTION_MAX_PER_MIN=15000

# Increase order response batch size
ORDER_RES_BATCH_SIZE=1500
ORDER_RES_BATCH_MAX_SIZE=2000

# Increase drain batch size for better throughput
DRAIN_BATCH_SIZE=1500
```

### 2. Update Supervisor Config (PRIORITY 1 - CRITICAL)

Edit `/home/egfollow/htdocs/egfollow.com/laravel-workers-ultra-batch.conf`:

**Change the `[program:laravel-queue-high]` section:**

```ini
[program:laravel-queue-high]
process_name=%(program_name)s_%(process_num)02d
command=php /home/egfollow/htdocs/egfollow.com/artisan queue:work redis --queue=high --sleep=0 --tries=1 --timeout=120 --max-jobs=5000 --max-time=3600 --memory=384
directory=/home/egfollow/htdocs/egfollow.com
autostart=true
autorestart=true
user=egfollow
numprocs=48
redirect_stderr=true
stdout_logfile=/home/egfollow/htdocs/egfollow.com/storage/logs/queue-high.log
stdout_logfile_maxbytes=50MB
stdout_logfile_backups=5
stopsignal=QUIT
killasgroup=true
stopwaitsecs=2
environment=QUEUE_CONNECTION="redis",REDIS_CLIENT="predis",REDIS_DB="2"
```

**Key changes:**
- `numprocs=48` (was 32) → 50% more workers
- `timeout=120` (was 60) → allow longer processing
- `memory=384` (was 256) → allow larger batches

**Update the summary comment at the bottom:**

```ini
# Configuration Summary:
# Total Workers: 72 (48 high + 8 optimized + 8 actions + 6 bulk + 2 default + 8 trigger-orders)
# Expected MySQL Connections: 45-60 total
# Memory Usage: ~24GB total (distributed across 72 workers)
# Throughput: 150,000+ jobs per hour
# Designed for: 10,000+ concurrent users with multiple concurrent orders (5+ orders simultaneously)
```

### 3. Update PM2 Config (PRIORITY 2 - RECOMMENDED)

Edit `ecosystem.config.cjs` to increase Node batch sizes:

```javascript
// In env_production section:
env_production: {
  NODE_ENV: 'production',
  MQTT_BROKER: 'mqtt://109.199.112.65:1883',
  API_BASE: 'https://egfollow.com',
  MQTT_HTTP_TIMEOUT: '30000',
  MQTT_MAX_INFLIGHT: '100',  // Increased from 75
  MQTT_BATCH_ENABLED: 'true',
  MQTT_BATCH_SIZE: '1500',   // Increased from 1000
  MQTT_BATCH_TIMEOUT: '3000',
  MQTT_HEALTH_CHECK_INTERVAL: '30000',
  
  // Ping response batching
  PING_BATCH_ENABLED: 'true',
  PING_BATCH_SIZE: '1500',      // Increased from 1000
  PING_BATCH_TIMEOUT: '400',    // Reduced from 500 for faster flush
  PING_BATCH_MAX_SIZE: '2000',  // Increased from 1000
  
  // Device activation batching
  DEVICE_ACT_BATCH_ENABLED: 'true',
  DEVICE_ACT_BATCH_SIZE: '1500', // Increased from 1000
  DEVICE_ACT_BATCH_TIMEOUT: '400',
  
  // Order response batching - CRITICAL
  ORDER_RES_BATCH_ENABLED: 'true',
  ORDER_RES_BATCH_SIZE: '1500',      // Increased from 1000
  ORDER_RES_BATCH_TIMEOUT: '150',    // Reduced from 200 for faster flush
  ORDER_RES_BATCH_MAX_SIZE: '2000',  // Increased from 1000
  DEBUG: 'false'
}
```

### 4. Deployment Steps (IN ORDER)

```bash
# 1. Backup current configs
cd /home/egfollow/htdocs/egfollow.com
cp .env .env.backup.$(date +%Y%m%d_%H%M%S)
cp laravel-workers-ultra-batch.conf laravel-workers-ultra-batch.conf.backup
cp ecosystem.config.cjs ecosystem.config.cjs.backup

# 2. Update .env with new values (use nano/vi)
nano .env
# Add the CRITICAL FIX section from step 1 above

# 3. Update supervisor config
sudo nano /home/egfollow/htdocs/egfollow.com/laravel-workers-ultra-batch.conf
# OR if supervisor config is in /etc/supervisor/conf.d/:
sudo nano /etc/supervisor/conf.d/laravel-workers-ultra-batch.conf

# 4. Update PM2 config
nano ecosystem.config.cjs

# 5. Reload supervisor (this will restart workers with new config)
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queues-ultra:*

# 6. Restart PM2 apps with new env
pm2 restart mqtt-handler
pm2 restart mqtt-publisher

# 7. Verify workers are running
sudo supervisorctl status
pm2 list

# 8. Monitor logs for first few minutes
tail -f storage/logs/laravel.log storage/logs/queue-high.log
pm2 logs mqtt-handler --lines 100
```

### 5. Verification & Testing

```bash
# Check Redis queue lengths (should stay low)
redis-cli -n 2 LLEN "egfollow_database_high"
redis-cli -n 2 LLEN "egfollow_database_actions"

# Check worker count
sudo supervisorctl status | grep laravel-queue-high | wc -l
# Should show 48

# Monitor rate limiter (should not hit limit often)
redis-cli -n 2 KEYS "batch_action_rate_global:*"
redis-cli -n 2 GET "batch_action_rate_global:$(date -u +%Y%m%d%H%M)"

# Test: Create 2 orders with 3000 actions each within 10 seconds
# Both should start processing immediately (check logs)
```

## Expected Improvements

### Before Fix
- Order 1 (3000 actions): starts immediately
- Order 2 (3000 actions): waits 30-60+ seconds
- Queue depth: 50-100+ jobs backed up
- Rate limiter: frequently blocking

### After Fix
- Order 1 (3000 actions): starts immediately  
- Order 2 (3000 actions): starts within 2-3 seconds
- Queue depth: 5-10 jobs (normal processing lag)
- Rate limiter: rarely blocks (15k/min allows 5 orders/min)

### Performance Gains
- **50% more workers** (32→48) = 50% more throughput
- **2x larger chunks** (500→1000) = 50% fewer iterations
- **3x higher rate limit** (5k→15k) = supports 3-5 concurrent orders
- **80% faster inter-chunk delay** (50ms→10ms) = faster job completion

## Monitoring After Deploy

### Key Metrics to Watch

1. **Queue depth** (should stay < 20)
   ```bash
   watch -n 5 'redis-cli -n 2 LLEN "egfollow_database_high"'
   ```

2. **Rate limiter hits** (should rarely hit 15k limit)
   ```bash
   watch -n 1 'redis-cli -n 2 GET "batch_action_rate_global:$(date -u +%Y%m%d%H%M)"'
   ```

3. **Worker memory** (should stay under 384MB per worker)
   ```bash
   ps aux | grep "queue:work" | awk '{print $6/1024 " MB - " $11 " " $12}'
   ```

4. **Job processing time** (check logs for duration_ms)
   ```bash
   tail -f storage/logs/laravel.log | grep "ProcessPingResponseBatchJob.*completed"
   ```

### Red Flags

- Queue depth stays > 50 for more than 30 seconds → need even more workers
- Rate limiter consistently at 14,000+ → increase `BATCH_ACTION_MAX_PER_MIN` to 20,000
- Worker memory > 400MB → reduce batch sizes slightly
- Job duration_ms > 10,000 (10 seconds) → database may be slow, check indexes

## Rollback Plan (if needed)

```bash
# 1. Restore backups
cd /home/egfollow/htdocs/egfollow.com
cp .env.backup.YYYYMMDD_HHMMSS .env
cp laravel-workers-ultra-batch.conf.backup laravel-workers-ultra-batch.conf
cp ecosystem.config.cjs.backup ecosystem.config.cjs

# 2. Restart everything
sudo supervisorctl reread
sudo supervisorctl update  
sudo supervisorctl restart laravel-queues-ultra:*
pm2 restart mqtt-handler mqtt-publisher

# 3. Verify rollback
sudo supervisorctl status | grep laravel-queue-high | wc -l
# Should show 32 (original count)
```

## Additional Optimizations (Optional - Phase 2)

If you still see blocking after the above fixes, consider:

### Option A: Add Dedicated Ping Queue

Create a separate queue just for ping responses so they never block behind other jobs:

1. Add new supervisor program:
```ini
[program:laravel-queue-ping]
process_name=%(program_name)s_%(process_num)02d
command=php /home/egfollow/htdocs/egfollow.com/artisan queue:work redis --queue=ping --sleep=0 --tries=1 --timeout=120 --max-jobs=3000 --memory=384
directory=/home/egfollow/htdocs/egfollow.com
autostart=true
autorestart=true
user=egfollow
numprocs=24
# ... rest of config
```

2. Update `ProcessPingResponseBatchJob.php`:
```php
public function __construct(...)
{
    // ...
    $this->onQueue('ping'); // Instead of 'high'
}
```

### Option B: Use Drain Approach for Ping Responses

Create a drain endpoint similar to order responses for zero-loss, high-throughput ping processing.

## Summary

**CRITICAL CHANGES (apply immediately):**
1. ✅ .env: Increase batch sizes and rate limits
2. ✅ Supervisor: Increase high queue workers to 48
3. ✅ PM2: Increase Node batch sizes

**EXPECTED RESULT:**
Multiple 3000-action orders will process concurrently without blocking.

**TIME TO APPLY:** 10-15 minutes including verification

**RISK:** Low (can rollback in < 2 minutes)
