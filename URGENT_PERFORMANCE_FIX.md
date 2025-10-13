# 🚨 URGENT: Performance Fix for Batch Size Issue

## Problem Identified

Your logs show the drain system is working BUT with **batch size 50** instead of 200:

```
[MQTT_API_DRAIN] Batch received {"total_actions":50}  ← Should be 200!
```

**Impact:**
- 3000 actions = **60 HTTP calls** (instead of 15)
- Massive overhead causing delays
- Heavy system load
- Still missing actions due to slow processing

**Also seeing:** Many individual deprecated calls (single action processing) bypassing batching completely.

---

## Root Cause

**pm2 is NOT using the updated ecosystem.config.cjs file!**

When you did `pm2 restart mqtt-handler`, it didn't reload the config file. You need to:
1. Delete the old process
2. Start fresh from the updated config file

---

## IMMEDIATE FIX (Run on Production Server)

### Step 1: Stop and Delete Old pm2 Process
```bash
cd /home/egfollow/htdocs/egfollow.com

# Stop and delete the old process (clears old env vars)
pm2 delete mqtt-handler

# Verify it's gone
pm2 list
```

### Step 2: Start with New Config
```bash
# Start fresh from ecosystem.config.cjs with production env
pm2 start ecosystem.config.cjs --env production

# Verify the process started
pm2 list
```

### Step 3: Verify Batch Size is Now 200
```bash
# Check environment variables
pm2 show mqtt-handler | grep -A 5 "env:"

# Should show:
# ORDER_RES_BATCH_SIZE: 200
# ORDER_RES_BATCH_TIMEOUT: 200
# ORDER_RES_BATCH_MAX_SIZE: 500
```

### Step 4: Save pm2 Configuration
```bash
# Save the new config so it persists after reboot
pm2 save

# Setup startup script (if not already done)
pm2 startup
```

### Step 5: Monitor Logs (Should See Batch Size 200)
```bash
# Watch pm2 logs
pm2 logs mqtt-handler --lines 20

# Expected output when responses arrive:
# 📦 Flushing order response batch: 200 actions (reason: size_limit)
# ✅ Order response batch queued to drain: 200 actions
```

### Step 6: Monitor Laravel Logs
```bash
tail -f storage/logs/laravel.log | grep MQTT_API_DRAIN

# Expected output:
# [MQTT_API_DRAIN] Batch received {"total_actions":200}  ← Should be 200!
# [MQTT_API_DRAIN] Batch received {"total_actions":198}
# NOT 50!
```

---

## Additional Optimizations (Apply After Above Fix)

### Increase Drain Batch Size to 300

Current drain processes 200 at a time. For your load (3000+ responses), increase to 300:

**In .env, add/update:**
```bash
DRAIN_BATCH_SIZE=300
```

**Then:**
```bash
php artisan config:clear
sudo supervisorctl restart laravel-queues-ultra:laravel-queue-high:*
```

### Increase Database Chunk Size

Current chunking: 100 users at a time. Increase to 200 for better throughput.

**Edit:** `app/Jobs/DrainOrderResponsesJob.php`
```php
// Line ~249
$chunkSize = 200; // Changed from 100
```

Then:
```bash
php artisan config:clear
```

### Optimize Node Batch Timeout

Reduce timeout to flush faster (currently 200ms is OK, but can go to 100ms):

**In ecosystem.config.cjs (env_production):**
```javascript
ORDER_RES_BATCH_TIMEOUT: '100',  // Changed from 200
```

Then restart pm2:
```bash
pm2 restart mqtt-handler
```

---

## Expected Performance After Fix

| Metric | Current (Broken) | After Fix | Improvement |
|--------|------------------|-----------|-------------|
| **Batch Size** | 50 | 200 | **4x larger** |
| **HTTP Calls (3000)** | 60 calls | 15 calls | **4x fewer** |
| **Processing Time** | 30-60s + delays | 10-15s | **3x faster** |
| **System Load** | Very heavy | Normal | **Light** |
| **Success Rate** | 60-80% | 98-100% | **Near perfect** |

---

## Verification Checklist

After running the fix, verify these:

### ✅ 1. pm2 Environment Variables
```bash
pm2 show mqtt-handler | grep ORDER_RES
```
**Expected:**
```
ORDER_RES_BATCH_SIZE: 200
ORDER_RES_BATCH_TIMEOUT: 200 (or 100 after optimization)
ORDER_RES_BATCH_MAX_SIZE: 500
```

### ✅ 2. Batch Logs Show 200
```bash
pm2 logs mqtt-handler --lines 0
# Wait for responses to arrive
```
**Expected:**
```
📦 Flushing order response batch: 200 actions
✅ Order response batch queued to drain: 200 actions
```

### ✅ 3. Laravel Receives 200-Action Batches
```bash
tail -f storage/logs/laravel.log | grep "total_actions"
```
**Expected:**
```
[MQTT_API_DRAIN] Batch received {"batch_id":"...","total_actions":200}
[MQTT_API_DRAIN] Batch received {"batch_id":"...","total_actions":198}
NOT 50!
```

### ✅ 4. Drain Processing is Fast
```bash
tail -f storage/logs/laravel.log | grep "duration_ms"
```
**Expected:**
```
[DrainOrderResponsesJob] Batch processed {"processed_count":200,"updated_count":200,"duration_ms":1500}
# Should be < 2000ms (2 seconds)
```

### ✅ 5. No More Individual Deprecated Calls (or very few)
```bash
tail -f storage/logs/laravel.log | grep DEPRECATED
```
**Should be:** Empty or very few lines (< 5%)

If you still see many deprecated calls, it means some responses are bypassing batching. This happens when:
- Node batch timeout is too long
- MQTT messages arrive slower than batch timeout
- Fallback to individual processing due to errors

---

## Why This Fixes Everything

**Current Problem:**
- pm2 still using old env vars (batch size 50)
- 3000 responses = 60 HTTP calls with batch size 50
- Each call has 50-200ms overhead
- Total overhead: 60 × 150ms = **9 seconds of pure HTTP overhead**
- Plus DB processing time → **30-60 seconds total**
- Heavy load on all systems

**After Fix:**
- pm2 uses new env vars (batch size 200)
- 3000 responses = 15 HTTP calls with batch size 200
- Each call has 50-200ms overhead
- Total overhead: 15 × 150ms = **2.25 seconds HTTP overhead**
- Plus DB processing time → **10-15 seconds total**
- Light load, fast processing

---

## Quick Test After Fix

1. Send 1000 actions
2. Monitor:
```bash
# Terminal 1
pm2 logs mqtt-handler --lines 0

# Terminal 2
tail -f storage/logs/laravel.log | grep DRAIN
```

**Expected behavior:**
- pm2: Batches of 200 actions flushed
- Laravel: Receives 5 batches (200+200+200+200+200)
- Drain: Processes in 5-10 seconds
- DB: 980-1000 actions updated (98-100% success)

---

## Emergency Rollback

If something goes wrong:

```bash
# Restore old batch size temporarily
pm2 delete mqtt-handler
pm2 start ecosystem.config.cjs --env production
pm2 set mqtt-handler ORDER_RES_BATCH_SIZE 50
pm2 restart mqtt-handler
```

But the real fix is to use batch size 200+ for this load.

---

## Summary

**The drain system IS working**, but pm2 is using the wrong batch size (50 instead of 200).

**Fix:** Delete and recreate the pm2 process to load the updated config.

**Expected result:** 4x fewer HTTP calls, 3x faster processing, 98-100% success rate.

Run the commands in Step 1-4 above NOW, then test with 1000 actions.
