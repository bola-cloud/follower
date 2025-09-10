# High-Performance MQTT Queue Optimization
## Server Specs: 3 vCPU + 8GB RAM

### Performance Target
- **2 orders/minute × 1000 users = 2000 actions/minute**
- **Peak throughput: 33 actions/second**

### Optimized Configuration Summary

#### Supervisor Workers (Total: 7 processes)
- **High Priority Queue**: 3 workers (192MB each) - handles SendMqttToUserJob
- **Actions Queue**: 2 workers (128MB each) - handles MQTT response processing
- **Default Queue**: 1 worker (192MB) - handles general jobs
- **Bulk Queue**: 1 worker (256MB) - handles BulkOrderProcessingJob

#### Redis Configuration
- **Memory Allocation**: 4GB (50% of server RAM)
- **Persistence**: RDB snapshots only for performance
- **Connection Limit**: 5000 connections
- **Optimized for 3 vCPU**: hz=25, conservative settings

#### Queue Optimizations
- **Action batches**: 15 items (optimized for 3 vCPU)
- **Processing delays**: 100ms between batches
- **Memory limits**: 128-256MB per worker
- **Fast timeouts**: 20-45 seconds

### Deployment Instructions

1. **Backup existing configs:**
   ```bash
   sudo cp /etc/supervisor/conf.d/laravel-workers.conf /etc/supervisor/conf.d/laravel-workers.conf.backup
   sudo cp /etc/redis/redis.conf /etc/redis/redis.conf.backup
   ```

2. **Deploy configurations:**
   ```bash
   sudo cp laravel-workers-ultra.conf /etc/supervisor/conf.d/laravel-workers.conf
   sudo cp redis-performance.conf /etc/redis/redis.conf
   ```

3. **Update environment:**
   ```bash
   cp .env.performance .env
   ```

4. **Restart services:**
   ```bash
   # Stop workers
   sudo supervisorctl stop laravel-queue-high:*
   sudo supervisorctl stop laravel-queue-default:* 
   sudo supervisorctl stop laravel-queue-bulk:*
   sudo supervisorctl stop laravel-queue-actions:*
   
   # Restart Redis
   sudo systemctl restart redis-server
   
   # Update supervisor
   sudo supervisorctl reread
   sudo supervisorctl update
   sudo supervisorctl start all
   ```

5. **Optimize Laravel:**
   ```bash
   php artisan config:clear
   php artisan config:cache
   php artisan queue:clear
   ```

### Monitoring Commands

```bash
# Queue status
./monitor-performance.sh

# Live monitoring  
./monitor-performance.sh --continuous

# Worker status
sudo supervisorctl status

# Redis memory usage
redis-cli info memory | grep used_memory_human
```

### Expected Performance
- **Throughput**: 2000+ actions/minute sustained
- **Memory usage**: ~2GB for Laravel workers + 4GB Redis = 6GB total
- **CPU utilization**: 60-80% during peak load
- **Response time**: <500ms per action update

### Troubleshooting
- If queues back up: increase ACTION_BATCH_SIZE in .env
- If memory errors: reduce WORKER_MEMORY_LIMIT
- If timeouts: increase DB_TIMEOUT and queue timeouts
- Monitor logs: `tail -f storage/logs/queue-*.log`
