# Quick Reference Card: Batching System

## 🚀 One-Page Cheat Sheet

### Core Concept
**Problem**: 1000 concurrent ping responses = 1000 API calls = System overload  
**Solution**: Batch 1000 responses into 10 batches = 10 API calls = 99% reduction

---

## 📋 Essential Commands

### Deployment
```bash
# 1. Pull code
git checkout new-batch-code && git pull

# 2. Add config to .env
echo "PING_BATCH_PROCESS_CHUNK_SIZE=80" >> .env
echo "PING_BATCH_CHUNK_DELAY_MS=50" >> .env

# 3. Clear caches
php artisan config:clear && php artisan route:clear

# 4. Restart services
php artisan queue:restart
pm2 reload ecosystem.config.cjs --only mqtt-handler
```

### Monitoring
```bash
# Watch batch metrics (updates every 5 seconds)
watch -n 5 'redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"'

# Watch MQTT handler logs
pm2 logs mqtt-handler | grep "ping batch"

# Watch Laravel job processing
tail -f storage/logs/laravel.log | grep ProcessPingResponseBatchJob

# Check queue depth
redis-cli llen "queues:high"
```

### Troubleshooting
```bash
# Batches not flushing?
pm2 restart mqtt-handler --update-env

# Jobs not processing?
ps aux | grep "queue:work"  # Check workers running
php artisan queue:work --queue=high &  # Start if missing

# Slow processing?
# Reduce chunk size in .env to 50, then:
php artisan queue:restart
```

---

## ⚙️ Configuration Matrix

### Default (Recommended)
```bash
# mqtt_handler
PING_BATCH_ENABLED=true
PING_BATCH_SIZE=100
PING_BATCH_TIMEOUT=500
PING_BATCH_MAX_SIZE=1000

# Laravel
PING_BATCH_PROCESS_CHUNK_SIZE=80
PING_BATCH_CHUNK_DELAY_MS=50
```
**Handles**: 1000 concurrent, 5000 orders/min  
**Processing**: ~1-2 seconds for 1000 users

### High Volume (5000+ concurrent)
```bash
# mqtt_handler
PING_BATCH_SIZE=200
PING_BATCH_TIMEOUT=300
PING_BATCH_MAX_SIZE=2000

# Laravel
PING_BATCH_PROCESS_CHUNK_SIZE=100
PING_BATCH_CHUNK_DELAY_MS=30
```
**Handles**: 5000+ concurrent, 10000 orders/min  
**Processing**: ~3-5 seconds for 5000 users

### Conservative (DB Limited)
```bash
# mqtt_handler
PING_BATCH_SIZE=50
PING_BATCH_TIMEOUT=500

# Laravel
PING_BATCH_PROCESS_CHUNK_SIZE=50
PING_BATCH_CHUNK_DELAY_MS=100
```
**Handles**: 500 concurrent, 3000 orders/min  
**Processing**: Very stable, low DB load

---

## 📊 Key Metrics

### Redis Metrics Command
```bash
redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"
```

### Healthy System
```
total_users: 1000       # Responses received
eligible_users: 850     # Passed eligibility
processed: 850          # ✅ Should equal eligible_users
published: 850          # ✅ Should equal processed
batches: 10             # Number of jobs
total_duration_ms: 6500 # ✅ Should be < 10000 for 1000 users
```

### Warning Signs
- `processed < eligible_users`: Database errors (check Laravel logs)
- `published < processed`: MQTT/Redis errors (check publisher logs)
- `total_duration_ms > 10000`: Performance degradation (reduce chunk size)

---

## 🔍 Log Patterns

### MQTT Handler (Good)
```
📦 Flushing ping batch: 100 responses (reason: size_limit)
✅ Ping batch processed: 100 responses jobs_dispatched=2 duration_ms=45
```

### Laravel Job (Good)
```
[ProcessPingResponseBatchJob] start batch_id=xxx order_id=123 user_count=100
[ProcessPingResponseBatchJob] eligible users filtered eligible_count=85
[ProcessPingResponseBatchJob] completed processed=85 published=85 duration_ms=650
```

### Errors to Watch
```
❌ Ping batch failed (100 responses)  # API endpoint issue
⚠️ Emergency flush: exceeded 1000    # Batch accumulator full
❌ Failed to insert chunk            # Database deadlock
```

---

## 🔧 Quick Fixes

| Problem | Quick Fix | Command |
|---------|-----------|---------|
| Batches not flushing | Restart mqtt_handler | `pm2 restart mqtt-handler --update-env` |
| Jobs not processing | Start queue workers | `php artisan queue:work --queue=high &` |
| Slow processing | Reduce chunk size | Edit .env: `PING_BATCH_PROCESS_CHUNK_SIZE=50` |
| DB deadlocks | Reduce chunk size | Edit .env: `PING_BATCH_PROCESS_CHUNK_SIZE=40` |
| Missing orders | Check eligibility logs | `tail -f storage/logs/laravel.log \| grep eligible` |
| High latency | Reduce batch timeout | Edit ecosystem: `PING_BATCH_TIMEOUT=300` |

---

## 📁 Key Files

| File | Purpose | Changes |
|------|---------|---------|
| `node_scripts/mqtt_handler.cjs` | Accumulates ping responses | ✅ Added batching logic |
| `app/Jobs/ProcessPingResponseBatchJob.php` | Processes batches | ✅ New file |
| `app/Http/Controllers/Api/MqttResponseController.php` | Batch API endpoint | ✅ Added `triggerOrderBatch` method |
| `routes/api.php` | API routes | ✅ Added `/api/mqtt/trigger-order-batch` |
| `ecosystem.config.cjs` | PM2 config | ⚠️ Update with batch env vars |
| `.env` | Laravel config | ⚠️ Add batch processing vars |

---

## 🎯 Performance Targets

| Metric | Target | Alert If |
|--------|--------|----------|
| Processing time (1000 users) | < 3 seconds | > 10 seconds |
| Processed / Eligible ratio | 100% | < 95% |
| Published / Processed ratio | 100% | < 99% |
| Database deadlocks | 0 per 1000 | > 5 per 1000 |
| Failed jobs | 0 per hour | > 10 per hour |
| Queue depth | < 50 | > 200 |
| Redis memory | < 500MB | > 2GB |

---

## 🚨 Emergency Procedures

### System Overload
```bash
# 1. Disable batching temporarily
# Edit ecosystem.config.cjs: PING_BATCH_ENABLED='false'
pm2 reload ecosystem.config.cjs

# 2. Increase queue workers
for i in {1..5}; do php artisan queue:work --queue=high & done

# 3. Monitor recovery
watch -n 1 'redis-cli llen "queues:high"'
```

### Rollback to Previous Version
```bash
# 1. Find previous commit
git log --oneline -10

# 2. Checkout previous version
git checkout <commit-hash>

# 3. Clear caches and restart
php artisan config:clear
php artisan queue:restart
pm2 restart mqtt-handler
```

### Clear Stuck Jobs
```bash
# 1. Check failed jobs
php artisan queue:failed

# 2. Retry all failed jobs
php artisan queue:retry all

# 3. If still stuck, flush queue (⚠️ DANGEROUS)
redis-cli del "queues:high"
```

---

## 📞 Support Checklist

When asking for help, provide:
```bash
# 1. System metrics
redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"

# 2. Queue depth
redis-cli llen "queues:high"

# 3. Recent errors (last 50 lines)
tail -50 storage/logs/laravel.log | grep -i error
pm2 logs mqtt-handler --lines 50 --err

# 4. Configuration
cat .env | grep PING_BATCH
pm2 show mqtt-handler | grep PING_BATCH

# 5. Worker status
ps aux | grep "queue:work"
```

---

## ✅ Health Check Script

Save as `check_batching_health.sh`:
```bash
#!/bin/bash
echo "=== Batching System Health Check ==="
echo ""

echo "1. MQTT Handler Status:"
pm2 show mqtt-handler | grep -E "(status|uptime|restart)"
echo ""

echo "2. Queue Workers:"
ps aux | grep "queue:work" | grep -v grep | wc -l
echo ""

echo "3. Current Batch Metrics:"
redis-cli hgetall "ping_batch_metrics:$(date +%Y%m%d%H%M)"
echo ""

echo "4. Queue Depth:"
redis-cli llen "queues:high"
echo ""

echo "5. Recent Errors (last 10):"
tail -10 storage/logs/laravel.log | grep -i error
echo ""

echo "=== Health Check Complete ==="
```

Run with: `bash check_batching_health.sh`

---

## 🎓 Remember

**3 Tiers of Batching**:
1. **mqtt_handler**: Accumulates responses (Node.js)
2. **API Endpoint**: Groups and dispatches jobs (Laravel)
3. **Background Job**: Processes in chunks (Laravel Queue)

**Key Principle**: Batch everything to reduce load by 99%

**Golden Rule**: Monitor metrics every 5 minutes for first 24 hours after deployment

---

For full documentation, see:
- `BATCHING_SOLUTION_SUMMARY.md` - Overview
- `BATCHING_CONFIGURATION.md` - Detailed config
- `DEPLOYMENT_GUIDE.md` - Step-by-step deployment
- `FLOW_DIAGRAMS.md` - Visual flow diagrams
