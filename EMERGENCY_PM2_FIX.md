# 🚨 EMERGENCY: PM2 NOT USING UPDATED CONFIG

## Critical Problem Identified

Your logs show:
- ✅ Drain system code is deployed
- ❌ **PM2 is NOT using the updated environment**
- ❌ Still making individual API calls (DEPRECATED warnings)
- ❌ NO batch activity (`📦 Flushing order response batch: 200`)
- ❌ NO drain activity (`[MQTT_API_DRAIN]`)

**This means pm2 restart did NOT reload the environment variables from ecosystem.config.cjs**

---

## 🔍 Diagnosis

### What you're seeing:
```
[MQTT_API] ⚠️ DEPRECATED: Single handler called - use batch endpoint instead
[MQTT_API] ⚠️ DEPRECATED: Single handler called - use batch endpoint instead
[MQTT_API] ⚠️ DEPRECATED: Single handler called - use batch endpoint instead
... (thousands of times)
```

### What you should see:
```
📦 Flushing order response batch: 200 actions (reason: size_limit)
✅ Order response batch queued to drain: 200 actions
[MQTT_API_DRAIN] Batch received {"total_actions":200}
[DrainOrderResponsesJob] Processing batch {"batch_size":200}
```

---

## ✅ IMMEDIATE FIX (Run on Production Server)

### Step 1: Check Current PM2 Environment
```bash
pm2 show mqtt-handler | grep ORDER_RES
```

**If you see:**
- `ORDER_RES_BATCH_SIZE: 50` or `ORDER_RES_BATCH_SIZE: 100` → Wrong!
- Missing ORDER_RES variables → Wrong!

**You need:**
- `ORDER_RES_BATCH_SIZE: 200` ← Must be this!

---

### Step 2: Delete and Recreate PM2 Process

**CRITICAL: pm2 restart does NOT reload env vars. You MUST delete and recreate!**

```bash
cd /home/egfollow/htdocs/egfollow.com

# Step A: Delete the old process completely
pm2 delete mqtt-handler

# Step B: Start fresh from config file with production environment
pm2 start ecosystem.config.cjs --env production --only mqtt-handler

# Step C: Save the new configuration
pm2 save

# Step D: Verify environment variables
pm2 show mqtt-handler | grep -E "ORDER_RES|BATCH"
```

**Expected output after Step D:**
```
ORDER_RES_BATCH_ENABLED: true
ORDER_RES_BATCH_SIZE: 200          ← MUST BE 200!
ORDER_RES_BATCH_TIMEOUT: 200
ORDER_RES_BATCH_MAX_SIZE: 500
```

---

### Step 3: Verify Batching is Working

```bash
# Watch pm2 logs for 30 seconds
pm2 logs mqtt-handler --lines 50

# You should see (when devices respond):
📦 Flushing order response batch: 200 actions (reason: size_limit)
✅ Order response batch queued to drain: 200 actions

# You should NOT see:
⚠️ DEPRECATED: Single handler called  ← Should be < 5%
```

---

### Step 4: Check Laravel Drain Logs

```bash
# Watch Laravel logs
tail -f storage/logs/laravel.log | grep -E "MQTT_API_DRAIN|DrainOrderResponsesJob"

# You should see:
[MQTT_API_DRAIN] Batch received {"total_actions":200}
[DrainOrderResponsesJob] Processing batch {"batch_size":200}
[DrainOrderResponsesJob] Batch processed successfully
```

---

## 🎯 Quick Copy-Paste Commands

**Run this entire block on production server:**

```bash
cd /home/egfollow/htdocs/egfollow.com

echo "=== Current PM2 Environment ==="
pm2 show mqtt-handler | grep ORDER_RES || echo "No ORDER_RES variables found!"

echo ""
echo "=== Deleting old process ==="
pm2 delete mqtt-handler

echo ""
echo "=== Starting fresh with production config ==="
pm2 start ecosystem.config.cjs --env production --only mqtt-handler

echo ""
echo "=== Saving configuration ==="
pm2 save

echo ""
echo "=== Verifying new environment ==="
pm2 show mqtt-handler | grep ORDER_RES_BATCH_SIZE

echo ""
echo "=== Checking for batch size 200 ==="
BATCH_SIZE=$(pm2 show mqtt-handler | grep ORDER_RES_BATCH_SIZE | awk -F: '{print $2}' | tr -d ' ')
if [ "$BATCH_SIZE" = "200" ]; then
    echo "✅ SUCCESS: Batch size is 200!"
else
    echo "❌ FAILED: Batch size is $BATCH_SIZE (should be 200)"
    echo "Try manually checking ecosystem.config.cjs"
fi

echo ""
echo "=== Monitoring logs for 15 seconds ==="
timeout 15 pm2 logs mqtt-handler --lines 30 || true

echo ""
echo "=== Done! Create a test order and verify batching ==="
```

---

## 📊 How to Verify It's Fixed

### Test 1: Check pm2 Environment
```bash
pm2 show mqtt-handler | grep ORDER_RES_BATCH_SIZE
# Must show: ORDER_RES_BATCH_SIZE: 200
```

### Test 2: Monitor Logs During Order
```bash
# Terminal 1: Watch pm2
pm2 logs mqtt-handler --lines 0

# Terminal 2: Watch Laravel
tail -f storage/logs/laravel.log | grep -E "DRAIN|DEPRECATED"
```

**Create a test order with 100 devices**

**Expected results:**
- ✅ PM2 logs show: `📦 Flushing order response batch: 200 actions`
- ✅ Laravel logs show: `[MQTT_API_DRAIN] Batch received`
- ✅ Laravel logs show: `[DrainOrderResponsesJob] Processing batch`
- ✅ DEPRECATED warnings: 0-5 (< 5%)
- ✅ System load: Normal (not heavy)

**If you still see many DEPRECATED warnings:**
- ❌ Batching is NOT working
- ❌ Check ecosystem.config.cjs has `ORDER_RES_BATCH_SIZE: '200'` in env_production
- ❌ Make sure you used `--env production` flag

---

## 🔧 Troubleshooting

### Problem 1: "mqtt-handler not found" when deleting
**Solution:**
```bash
pm2 list  # See all processes
pm2 delete all  # Delete everything
pm2 start ecosystem.config.cjs --env production  # Start all
```

### Problem 2: Batch size still not 200 after recreate
**Solution:**
```bash
# Verify config file has the value
cat ecosystem.config.cjs | grep -A 5 "env_production:" | grep ORDER_RES_BATCH_SIZE

# Should show: ORDER_RES_BATCH_SIZE: '200',

# If not, the config file wasn't updated - pull from git first:
git pull origin new-batch-code
pm2 delete mqtt-handler
pm2 start ecosystem.config.cjs --env production --only mqtt-handler
pm2 save
```

### Problem 3: Still seeing DEPRECATED warnings after fix
**Solution:**
```bash
# Check if batching is disabled in environment
pm2 show mqtt-handler | grep ORDER_RES_BATCH_ENABLED
# Must show: ORDER_RES_BATCH_ENABLED: true

# If false or missing, recreate:
pm2 delete mqtt-handler
pm2 start ecosystem.config.cjs --env production --only mqtt-handler
pm2 save
```

---

## 📞 What Happens After Fix

### Before Fix (Current State):
| Metric | Value | Status |
|--------|-------|--------|
| Batch size | 50 or 100 | ❌ Too small |
| HTTP calls for 3000 responses | ~3000 | ❌ Massive overhead |
| DEPRECATED warnings | Thousands | ❌ Batching disabled |
| Drain logs | None | ❌ Not working |
| System load | Very heavy | ❌ |

### After Fix (Expected):
| Metric | Value | Status |
|--------|-------|--------|
| Batch size | 200 | ✅ Optimal |
| HTTP calls for 3000 responses | ~15 | ✅ 200x reduction |
| DEPRECATED warnings | 0-5% | ✅ Normal fallback |
| Drain logs | Present | ✅ Working |
| System load | Normal | ✅ |

---

## 🎯 Critical Checklist

- [ ] pm2 show mqtt-handler shows `ORDER_RES_BATCH_SIZE: 200`
- [ ] pm2 logs show "Flushing order response batch: 200 actions"
- [ ] Laravel logs show "[MQTT_API_DRAIN] Batch received"
- [ ] DEPRECATED warnings < 5% (not thousands)
- [ ] System load is normal (not heavy)
- [ ] Test order completes in 5-15 seconds (not 60+ seconds)

---

## ⚡ Why This Happens

**pm2 restart** preserves the old environment variables in memory. It does NOT re-read ecosystem.config.cjs.

**Only these commands reload the config:**
- `pm2 delete <app>` + `pm2 start ecosystem.config.cjs --env <env>`
- `pm2 delete all` + `pm2 start ecosystem.config.cjs --env <env>`

**These do NOT reload config:**
- `pm2 restart <app>` ❌
- `pm2 reload <app>` ❌
- `pm2 stop <app>` + `pm2 start <app>` ❌

---

## 🚀 Next Steps After Fix

1. **Verify batch size 200** in pm2 environment
2. **Test with 100 devices** - should complete in 3-5 seconds
3. **Test with 1000 devices** - should complete in 10-15 seconds
4. **Test with 3000 devices** - should complete in 20-30 seconds
5. **System should be normal** (not heavy)

---

**Run the fix now and let me know the results!**
