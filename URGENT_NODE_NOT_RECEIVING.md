# URGENT: Node Handler Not Receiving order/res Messages

## Problem
- Devices ARE sending responses to `order/res/4453/15775` (visible in MQTTX)
- Laravel has ZERO drain logs
- This means **Node handler is not receiving these messages**

## Root Causes (Most Likely)

### 1. mqtt-handler process crashed or not running
```bash
pm2 status mqtt-handler
```

**If status is "errored" or "stopped":**
```bash
pm2 restart mqtt-handler
pm2 logs mqtt-handler --lines 50
```

### 2. mqtt-handler not reloaded with new code
```bash
# Deploy the enhanced logging code
pm2 reload ecosystem.config.cjs --env production --only mqtt-handler

# Watch logs
pm2 logs mqtt-handler --lines 0
```

### 3. MQTT broker connection issue
```bash
# Check logs for connection errors
pm2 logs mqtt-handler | grep -E "Connected to MQTT|Subscription error|MQTT RAW MESSAGE"
```

**Expected output:**
```
✅ Connected to MQTT broker
✅ Subscribed to topics
🔔 MQTT RAW MESSAGE -> topic: order/res/4453/15775 | size: 18 bytes
```

**If NO "MQTT RAW MESSAGE" logs:**
- Handler is not receiving ANY messages
- MQTT broker connection is broken
- Check broker: `mqtt://109.199.112.65:1883`

### 4. Subscription not working
The handler should subscribe to `order/res/+/+`.

**Check subscription in code:**
```bash
grep "order/res" /home/egfollow/htdocs/egfollow.com/node_scripts/mqtt_handler.cjs
```

Should show:
```javascript
'order/res/+/+',
```

## Immediate Actions

### Step 1: Restart mqtt-handler with new logging
```bash
cd /home/egfollow/htdocs/egfollow.com

# Pull latest code
git pull origin new-batch-code

# Restart PM2 with production env
pm2 reload ecosystem.config.cjs --env production --only mqtt-handler

# Verify it started
pm2 status mqtt-handler
```

### Step 2: Watch logs for ANY MQTT messages
```bash
# Terminal 1: Watch all MQTT messages
pm2 logs mqtt-handler --lines 0 | grep "MQTT RAW MESSAGE"

# Terminal 2: Create a test order (order 4455 with 10 users)
```

### Step 3: Verify messages are received
After creating test order, you should see in PM2 logs:
```
🔔 MQTT RAW MESSAGE -> topic: order/ping/res | size: 123 bytes
🔔 MQTT RAW MESSAGE -> topic: orders/35658 | size: 156 bytes
```

**Wait ~30 seconds** for devices to respond, then you should see:
```
🔔 MQTT RAW MESSAGE -> topic: order/res/4455/35658 | size: 18 bytes
🔍 Checking if topic matches order/res pattern: order/res/4455/35658
🔍 Regex match result: MATCHED
📨 order/res received: order_id=4455, user_id=35658, status=done
```

### Step 4: If STILL no order/res messages

**Manually publish via MQTTX to test:**
- **Topic:** `order/res/4453/15775`
- **Payload:** `{"status": "done"}`
- **QoS:** 0

Then check PM2 logs immediately. If you see the message → handler is working, devices aren't publishing. If you DON'T see the message → handler not subscribed or crashed.

## Common Issues

### Issue 1: PM2 process shows "online" but not receiving messages
**Symptom:** `pm2 status` shows online but no logs appear  
**Cause:** Process started before code was pulled  
**Fix:**
```bash
pm2 delete mqtt-handler
pm2 start ecosystem.config.cjs --env production --only mqtt-handler
```

### Issue 2: "Module not found" error in PM2 logs
**Symptom:** PM2 logs show module import errors  
**Cause:** Missing npm dependencies  
**Fix:**
```bash
npm install
pm2 restart mqtt-handler
```

### Issue 3: Connection refused to MQTT broker
**Symptom:** Logs show "ECONNREFUSED 109.199.112.65:1883"  
**Cause:** MQTT broker down or firewall blocking  
**Fix:**
```bash
# Test broker connection
telnet 109.199.112.65 1883

# If fails, check broker status or use different broker
```

## Debug Commands Summary

```bash
# 1. Check PM2 status
pm2 status mqtt-handler

# 2. Check recent logs
pm2 logs mqtt-handler --lines 50

# 3. Watch for order/res messages
pm2 logs mqtt-handler --lines 0 | grep -E "order/res|MQTT RAW"

# 4. Check environment
pm2 env mqtt-handler | grep ORDER_RES_BATCH

# 5. Check Laravel logs (should be empty if Node not sending)
tail -f storage/logs/laravel.log | grep MQTT_API_DRAIN

# 6. Test with manual MQTTX publish
# Topic: order/res/4453/15775
# Payload: {"status": "done"}
```

## What to Report

After running the above commands, report:

1. **PM2 status:** Online/Stopped/Errored?
2. **MQTT connection:** Do you see "Connected to MQTT broker"?
3. **Raw messages:** Do you see "MQTT RAW MESSAGE" for ANY topic?
4. **order/res messages:** Do you see "order/res" in logs?
5. **Manual test:** When you publish via MQTTX, does PM2 log it?

This will tell us exactly where the breakdown is.
