# 🔴 CRITICAL: Order Response Timer Not Running

## Evidence from Your Logs

Your PM2 logs show:
- ✅ Messages being received: `batch_size=93, 94, 95...100`
- ❌ **NO timer startup log**: Missing "⏰ ORDER RESPONSE BATCH TIMER STARTED"
- ❌ **NO timer trigger logs**: Missing "⏰ Timer triggered"
- ❌ **NO flush logs**: Missing "🚀 Flushing order response batch"

## Root Cause

The `setInterval` timer on **line 331** of mqtt_handler.cjs is **NEVER being initialized** when PM2 starts the process.

This happens because:

1. PM2 loaded an **old version** of the code without the timer
2. You ran `pm2 reload mqtt-handler` which does a **graceful reload**
3. Graceful reload **reuses the same process** without reinitializing global timers
4. The `setInterval` at line 331 **only runs ONCE when the script first loads**

## Why Batch Stuck at 100

1. Messages come in → added to `orderResponseBatch` array
2. Batch grows: 93 → 94 → 95 → ... → 100
3. **Size check fails**: `100 < 1000` (ORDER_RES_BATCH_SIZE)
4. **Emergency check fails**: `100 < 1000` (ORDER_RES_BATCH_MAX_SIZE)
5. **Per-message timer set**: `setTimeout(() => flushOrderResponseBatch('timer'), 500)`
6. **Per-message timer cleared**: When `flushOrderResponseBatch()` is called, it clears `orderResBatchTimer` at line 252
7. **Global timer not running**: The `setInterval` was never initialized
8. **Result**: Batch sits forever with 100 items, never flushed!

## The Fix

**You MUST restart (not reload) PM2 to reinitialize the global timer:**

```bash
cd /home/egfollow/htdocs/egfollow.com
git pull origin new-batch-code

# CRITICAL: Use restart (not reload) to reinitialize timers
pm2 restart mqtt-handler

# Verify timer started
pm2 logs mqtt-handler --lines 20 | grep "ORDER RESPONSE BATCH TIMER STARTED"
```

### Expected Output After Restart:

```
3|mqtt-han | ⏰ ORDER RESPONSE BATCH TIMER STARTED: Will flush every 500ms
3|mqtt-han | ⏰ Timer triggered: No order responses to flush
3|mqtt-han | ⏰ Timer triggered: No order responses to flush
... (repeats every 500ms)
```

Then when messages arrive:
```
3|mqtt-han | 📨 order/res received: order_id=4455, user_id=423, status=external, batch_size=1
3|mqtt-han | 📊 Order response added to batch: 1/1000
3|mqtt-han | ⏱️ Batch timer scheduled: will flush in 500ms if not full
... (wait 500ms)
3|mqtt-han | ⏰ Timer triggered: Flushing 1 order responses (reason: timer_interval)
3|mqtt-han | 🚀 Flushing order response batch: 1 actions (reason: timer_interval)
3|mqtt-han | 📋 Sample actions: [{"order_id":4455,"user_id":423,"status":"external"}]
3|mqtt-han | ✅ Order response batch sent to drain endpoint
```

## Why `pm2 reload` Didn't Work

- `pm2 reload` does a **zero-downtime graceful reload**
- It keeps the old process running while starting a new one
- **But your code has global `setInterval` timers that only run at module load time**
- If the old process had a timer, the new code might not reinitialize it properly
- `pm2 restart` **kills and restarts the process**, forcing timer reinitialization

## Verification Commands

```bash
# 1. Pull latest code
cd /home/egfollow/htdocs/egfollow.com
git pull origin new-batch-code

# 2. RESTART (not reload!)
pm2 restart mqtt-handler

# 3. Verify timer started (should see within 5 seconds)
pm2 logs mqtt-handler --lines 50 | grep "TIMER STARTED"

# 4. Watch timer triggering (should see every 500ms)
pm2 logs mqtt-handler --lines 0 | grep "Timer triggered"

# 5. Create test order to verify full pipeline
# Then watch for flush logs
pm2 logs mqtt-handler --lines 0 | grep "Flushing order response"
```

## If Timer Still Doesn't Start

If you still don't see "⏰ ORDER RESPONSE BATCH TIMER STARTED" after `pm2 restart`, then there's a JavaScript error preventing the timer initialization. Check for errors:

```bash
# Check for JavaScript errors
pm2 logs mqtt-handler --err

# Check if ORDER_RES_BATCH_ENABLED is set correctly
pm2 env mqtt-handler | grep ORDER_RES_BATCH_ENABLED
```

The timer initialization code is wrapped in:
```javascript
if (ORDER_RES_BATCH_ENABLED) {
  console.log(`⏰ ORDER RESPONSE BATCH TIMER STARTED: Will flush every ${ORDER_RES_BATCH_TIMEOUT}ms`);
  setInterval(() => {
    // ...
  }, ORDER_RES_BATCH_TIMEOUT);
}
```

If `ORDER_RES_BATCH_ENABLED === false`, the timer won't start!

## Permanent Fix: Remove Dual Timer System

The current code has **two competing timer mechanisms**:

1. **Global setInterval** (line 331) - good, runs forever
2. **Per-message setTimeout** (line 643) - **BAD, causes conflicts**

We should **remove the per-message setTimeout** and rely only on the global timer:

```javascript
// REMOVE THIS (lines 642-645):
else if (!orderResBatchTimer) {
  orderResBatchTimer = setTimeout(() => flushOrderResponseBatch('timer'), ORDER_RES_BATCH_TIMEOUT);
  console.log(`⏱️ Batch timer scheduled: will flush in ${ORDER_RES_BATCH_TIMEOUT}ms if not full`);
}
```

The global `setInterval` is sufficient - it checks every 500ms and flushes if batch has items.

## Summary

1. ✅ **Immediate Fix**: Run `pm2 restart mqtt-handler` (not reload)
2. ✅ **Verify**: Check for "⏰ ORDER RESPONSE BATCH TIMER STARTED" log
3. ✅ **Test**: Create order, watch for "⏰ Timer triggered: Flushing X responses"
4. ✅ **Future**: Remove per-message setTimeout to prevent conflicts
