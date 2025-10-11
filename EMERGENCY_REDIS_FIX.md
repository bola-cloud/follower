# EMERGENCY FIX: Redis Queue Error

## Problem
Error: "Cannot use object of type Redis as array" at RedisQueue.php:286

Queue workers are crashing and no actions are being processed.

## Root Cause
**Redis client mismatch** - The config specifies `phpredis` but production has `predis` (or vice versa).

## IMMEDIATE FIX (Choose Option A or B)

### Option A: Switch to Predis (RECOMMENDED - Already applied to local files)

**On Production Server:**

```bash
# 1. Update config/database.php - change Redis client to predis
sed -i "s/'client' => env('REDIS_CLIENT', 'phpredis')/'client' => env('REDIS_CLIENT', 'predis')/" config/database.php

# 2. Ensure predis/predis is installed
composer require predis/predis

# 3. Clear all caches
php artisan config:clear
php artisan cache:clear

# 4. Restart queue workers
sudo supervisorctl restart laravel-queues-ultra:*

# 5. Verify workers are running without errors
sudo supervisorctl status
tail -f storage/logs/laravel.log
```

### Option B: Temporary Rollback to Sync Queue (IF Option A fails)

**On Production Server:**

```bash
# 1. Revert to sync queue temporarily
sed -i 's/QUEUE_CONNECTION=redis/QUEUE_CONNECTION=sync/' .env

# 2. Clear config
php artisan config:clear

# 3. Restart workers (they will run but process synchronously)
sudo supervisorctl restart laravel-queues-ultra:*
```

## Verification

### Check if fix worked:

```bash
# 1. Check for Redis errors in logs
tail -50 storage/logs/laravel.log | grep "Redis as array"
# Should return nothing if fixed

# 2. Test Redis connection in Laravel
php artisan tinker
>>> Redis::ping()
# Should return: "+PONG"

# 3. Test queue dispatch
>>> dispatch(new \App\Jobs\ProcessOrderResponseBatchJob([['order_id'=>1,'user_id'=>1,'status'=>'done']], 'done', 'test'));
>>> exit

# 4. Check if job was processed
tail -20 storage/logs/laravel.log | grep ProcessOrderResponseBatchJob
# Should see processing logs
```

## Why This Happened

Laravel supports two Redis clients:
- **predis/predis** - Pure PHP implementation (slower, universal compatibility)
- **phpredis** - PHP extension (faster, requires PHP extension installed)

The error occurs when:
1. Config specifies `phpredis` but the extension isn't installed
2. Config specifies `predis` but the package isn't installed
3. Config uses one client's syntax with the other client

## Permanent Solution

### Check which Redis client is actually available:

```bash
# Check if phpredis extension is installed
php -m | grep redis

# Check if predis package is installed
composer show | grep predis
```

### Configure based on what you have:

**If phpredis extension IS installed:**
```bash
# Set in .env
REDIS_CLIENT=phpredis

# Ensure config/database.php has:
# 'client' => env('REDIS_CLIENT', 'phpredis'),
```

**If predis/predis package IS installed:**
```bash
# Install if needed
composer require predis/predis

# Set in .env
REDIS_CLIENT=predis

# Ensure config/database.php has:
# 'client' => env('REDIS_CLIENT', 'predis'),
```

## Performance Comparison

| Client | Speed | Install | Best For |
|--------|-------|---------|----------|
| **phpredis** | Fast (C extension) | `pecl install redis` | Production, high-load |
| **predis** | Slower (Pure PHP) | `composer require predis/predis` | Development, compatibility |

For production with 4000+ actions/5sec, **phpredis is strongly recommended**.

### Install phpredis Extension (Recommended for Production):

```bash
# On Ubuntu/Debian
sudo apt-get install php-redis
sudo systemctl restart php8.1-fpm  # or your PHP version

# On CentOS/RHEL
sudo yum install php-pecl-redis
sudo systemctl restart php-fpm

# Verify installation
php -m | grep redis
# Should output: redis

# Update config
sed -i "s/'client' => env('REDIS_CLIENT', 'predis')/'client' => env('REDIS_CLIENT', 'phpredis')/" config/database.php
php artisan config:clear
sudo supervisorctl restart laravel-queues-ultra:*
```

## Files Modified

**Local workspace:**
- ✅ `config/database.php` - Changed default client from `phpredis` to `predis`

**Production server (you need to apply):**
- Update `config/database.php` OR install missing Redis client

## After Fix is Applied

1. **Verify queue workers are processing:**
   ```bash
   sudo supervisorctl status | grep laravel-queue
   # All should show RUNNING
   ```

2. **Monitor queue depth:**
   ```bash
   watch -n 1 'redis-cli llen queues:high'
   # Should stay < 100
   ```

3. **Send test MQTT messages and verify DB updates**

4. **If still having issues, check:**
   ```bash
   # Redis server status
   redis-cli ping
   
   # PHP Redis extension
   php -m | grep redis
   
   # Predis package
   composer show predis/predis
   
   # Laravel can connect
   php artisan tinker
   >>> Redis::connection()->ping()
   ```

## Monitoring After Fix

```bash
# Watch for any new Redis errors
tail -f storage/logs/laravel.log | grep -i redis

# Monitor job processing
tail -f storage/logs/queue-high.log | grep "Batch completed"

# Check failed jobs
php artisan queue:failed
```

## Contact/Next Steps

1. Apply Option A (predis) fix immediately
2. Verify workers are running without errors
3. Test with small MQTT load (100 messages)
4. Once stable, consider installing phpredis for better performance
5. Re-run full load test with 4000 actions

---

**Status:** 🔴 CRITICAL - Queue workers not processing  
**Priority:** IMMEDIATE - Apply fix within 5 minutes  
**Estimated downtime:** 2-5 minutes for fix + restart
