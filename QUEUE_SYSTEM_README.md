# MQTT Action Queue Processing System

## Overview
This system solves database connection issues caused by thousands of concurrent action updates by implementing a queue-based batch processing approach.

## Problem Solved
- **Issue**: Thousands of actions updated simultaneously overwhelmed MySQL connection pool
- **Result**: 504 Gateway Timeout errors and "Connection refused" database errors
- **Solution**: Store actions in cache queue, process in small batches with controlled timing

## How It Works

### 1. Action Queuing (MqttResponseController)
```
MQTT Request → Queue in Cache → Immediate Response
```
- Actions are stored in Redis/Cache with unique keys
- Immediate response sent to MQTT client
- No direct database updates during high traffic

### 2. Batch Processing (ActionQueueJob)
```
Cache Queue → Small Batches (5 actions) → Database Updates → 0.5s Delay → Next Batch
```
- Processes 5 actions per batch maximum
- 0.5 second delay between batches
- Database connectivity checks before each batch
- Transaction safety for each action
- Progressive backoff on failures (10s, 30s, 60s)

### 3. Fallback System
- If queuing fails → immediate processing
- If database fails → requeue actions for later
- If job fails → automatic retry with backoff

## Configuration

### Environment Variables
```bash
# Enable the new system
MQTT_USE_QUEUE=true

# Auto-create missing actions
MQTT_AUTO_CREATE_MISSING=true

# Ensure Redis is configured
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Queue Worker
Ensure queue workers are running:
```bash
php artisan queue:work --queue=default --tries=3 --timeout=300
```

## Monitoring

### Cache Keys
- `mqtt_action_queue:*` - Individual queued actions
- `mqtt_action_queue_job_running` - Job status flag

### Logs
- `ActionQueueJob started` - Job begins processing
- `Processing batch of X actions` - Batch processing
- `Processed action ID: X` - Individual action success
- `Failed to process action` - Action failures
- `No queued actions found` - Queue empty

### Performance Metrics
- **Before**: 1000+ concurrent DB connections → Connection refused
- **After**: 5 actions per batch → Controlled load
- **Throughput**: ~10 actions per second (600 per minute)
- **Database load**: Minimal connection usage

## Files Modified

1. **app/Jobs/ActionQueueJob.php** (NEW)
   - Batch processing logic
   - Database safety checks
   - Progressive backoff
   - Cache queue management

2. **app/Http/Controllers/Api/MqttResponseController.php**
   - `queueActionForProcessing()` - Store in cache
   - `ensureActionQueueJobRunning()` - Manage job dispatch
   - `processActionImmediately()` - Fallback processing

3. **config/database.php**
   - Connection timeouts
   - Connection pooling
   - Error handling

4. **app/Jobs/BulkOrderProcessingJob.php**
   - Reduced batch sizes (3 actions)
   - Longer delays (5 seconds)
   - Enhanced error handling

## Benefits

1. **Database Protection**: No connection pool exhaustion
2. **High Availability**: Immediate MQTT responses
3. **Reliability**: Fallback mechanisms
4. **Scalability**: Handles thousands of concurrent requests
5. **Monitoring**: Comprehensive logging
6. **Graceful Degradation**: Automatic fallback to immediate processing

## Usage

The system activates automatically when `MQTT_USE_QUEUE=true`. No code changes needed in MQTT clients.

1. MQTT requests continue as normal
2. Actions are queued instead of immediately processed
3. Background job processes actions gradually
4. Database remains stable under high load

## Troubleshooting

- **Queue not processing**: Check queue workers are running
- **Actions not updating**: Check Redis connection
- **Still getting timeouts**: Verify MQTT_USE_QUEUE=true
- **Database errors**: Check database configuration and connectivity
