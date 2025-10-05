# Flow Diagram: High-Volume Batching System

## Overview Flow (Create Order → Ping → Process → Publish)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          1. ADMIN CREATES ORDER                          │
│                    (Admin Dashboard / OrderController)                   │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                    2. SEND PING TO ALL DEVICES                           │
│                      (PingService → MQTT Topic)                          │
│                    Topic: order/ping/req                                 │
│                    Payload: {type, order_id, activation}                 │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                  3. DEVICES RESPOND (Concurrent)                         │
│                      ⚡ 1000-5000 responses                              │
│                    Topic: order/ping/res                                 │
│                    Payload: {order_id, user_id, type}                    │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│            ✅ NEW: MQTT HANDLER BATCHING (Tier 1)                       │
│                   (mqtt_handler.cjs)                                     │
│                                                                           │
│  Old Behavior (PING_BATCH_ENABLED=false):                               │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Each response → Individual HTTP call                            │   │
│  │  1000 responses = 1000 API calls                                 │   │
│  │  Result: API overload, slow processing                           │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  ✅ New Behavior (PING_BATCH_ENABLED=true):                             │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  Accumulate responses in memory array                            │   │
│  │  Flush when:                                                      │   │
│  │    - Size reaches 100 (PING_BATCH_SIZE)                          │   │
│  │    - Timer expires 500ms (PING_BATCH_TIMEOUT)                    │   │
│  │    - Emergency threshold 1000 (PING_BATCH_MAX_SIZE)              │   │
│  │  1000 responses = 10 batch API calls                             │   │
│  │  Result: 99% reduction in HTTP calls                             │   │
│  └──────────────────────────────────────────────────────────────────┘   │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│        ✅ NEW: BATCH API ENDPOINT (Tier 2)                              │
│           (MqttResponseController::triggerOrderBatch)                    │
│                                                                           │
│  Endpoint: POST /api/mqtt/trigger-order-batch                           │
│  Payload: {batch_id, responses: [{order_id, user_id, type}, ...]}      │
│                                                                           │
│  Process:                                                                │
│  1. Validate batch (up to 5000 responses)                               │
│  2. Group by order_id + type                                            │
│  3. Dispatch ProcessPingResponseBatchJob for each group                 │
│  4. Return immediately (async)                                           │
│                                                                           │
│  Result: Non-blocking, efficient grouping                               │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│        ✅ NEW: BATCH PROCESSING JOB (Tier 3)                            │
│              (ProcessPingResponseBatchJob)                               │
│                                                                           │
│  Input: order_id, type, user_ids[], batch_id                           │
│                                                                           │
│  Step 1: Load Order (once)                                              │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  SELECT * FROM orders WHERE id = ?                               │   │
│  │  Result: Single query for entire batch                           │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  Step 2: Batch Eligibility Check                                        │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  SELECT * FROM users WHERE id IN (?, ?, ...)  // All at once    │   │
│  │  SELECT COUNT(*) FROM actions WHERE ...       // Check existing  │   │
│  │  Filter: Eligible users only                                     │   │
│  │  Result: 850 eligible out of 1000                                │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  Step 3: Chunked Processing (850 users → 11 chunks of 80)              │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  For each chunk (80 users):                                      │   │
│  │                                                                   │   │
│  │    3a. Insert Pending Actions (Batch)                            │   │
│  │    ┌────────────────────────────────────────────────────────┐   │   │
│  │    │  INSERT IGNORE INTO actions                            │   │
│  │    │  (order_id, user_id, type, status, ...)                │   │
│  │    │  VALUES (?, ?, ?, ?), (?, ?, ?, ?), ...  (80 rows)     │   │
│  │    │  Result: Single query for 80 inserts                   │   │
│  │    └────────────────────────────────────────────────────────┘   │   │
│  │                                                                   │   │
│  │    3b. Publish Order Announcements (Redis Pipeline)              │   │
│  │    ┌────────────────────────────────────────────────────────┐   │   │
│  │    │  RPUSH mqtt:publish job1 job2 ... job80                │   │
│  │    │  Result: Single pipeline for 80 publishes              │   │
│  │    └────────────────────────────────────────────────────────┘   │   │
│  │                                                                   │   │
│  │    3c. Small Delay (Rate Limiting)                               │   │
│  │    ┌────────────────────────────────────────────────────────┐   │   │
│  │    │  usleep(50 * 1000)  // 50ms                            │   │
│  │    │  Result: Controlled publish rate                       │   │
│  │    └────────────────────────────────────────────────────────┘   │   │
│  │                                                                   │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  Step 4: Record Metrics                                                 │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │  HINCRBY ping_batch_metrics:202510051430 total_users 850        │   │
│  │  HINCRBY ping_batch_metrics:202510051430 processed 850          │   │
│  │  HINCRBY ping_batch_metrics:202510051430 published 850          │   │
│  │  Result: Per-minute metrics for monitoring                       │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                           │
│  Total Time: ~600-1500ms for 1000 users                                │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                  4. ORDERS PUBLISHED TO MQTT                             │
│                    (mqtt_publisher_worker.cjs)                           │
│                                                                           │
│  Worker BRPOPs from Redis list (mqtt:publish or egf:mqtt:publish)      │
│  Publishes to MQTT topics: orders/{user_id}                             │
│                                                                           │
│  Publish Rate: 5000+ orders/minute sustained                            │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                  5. DEVICES RECEIVE & RESPOND                            │
│                    Topic: order/res/{order_id}/{user_id}                 │
│                    Payload: {status: "done" | "external"}                │
└───────────────────────────────────┬─────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                  6. UPDATE ACTION STATUS                                 │
│                    (MqttResponseController::handle)                      │
│                    UPDATE actions SET status='done' WHERE ...            │
└─────────────────────────────────────────────────────────────────────────┘
```

---

## Detailed Batch Processing Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    MQTT HANDLER BATCH ACCUMULATION                       │
└─────────────────────────────────────────────────────────────────────────┘

Time: 0ms
┌────────────────┐
│ Response 1     │ ──► pingResponseBatch.push({order_id: 123, user_id: 1})
└────────────────┘

Time: 10ms
┌────────────────┐
│ Response 2     │ ──► pingResponseBatch.push({order_id: 123, user_id: 2})
└────────────────┘

Time: 20ms
┌────────────────┐
│ Response 3-100 │ ──► pingResponseBatch.push(...) x98
└────────────────┘

Time: 30ms (Batch Size = 100)
┌────────────────────────────────────────────────────────────────────────┐
│ FLUSH TRIGGERED (reason: size_limit)                                   │
│                                                                         │
│ POST /api/mqtt/trigger-order-batch                                     │
│ {                                                                       │
│   "batch_id": "uuid-1",                                                │
│   "responses": [                                                        │
│     {"order_id": 123, "user_id": 1, "type": "create"},                │
│     {"order_id": 123, "user_id": 2, "type": "create"},                │
│     ...  (100 responses)                                                │
│   ]                                                                     │
│ }                                                                       │
└────────────────────────────────────────────────────────────────────────┘

Time: 40ms
┌────────────────┐
│ Response 101   │ ──► New batch starts
└────────────────┘

Time: 540ms (Timeout = 500ms since last flush)
┌────────────────────────────────────────────────────────────────────────┐
│ FLUSH TRIGGERED (reason: timer)                                        │
│                                                                         │
│ POST /api/mqtt/trigger-order-batch                                     │
│ {                                                                       │
│   "batch_id": "uuid-2",                                                │
│   "responses": [                                                        │
│     {"order_id": 123, "user_id": 101, "type": "create"},              │
│     ...  (41 responses)                                                 │
│   ]                                                                     │
│ }                                                                       │
└────────────────────────────────────────────────────────────────────────┘

Result: 141 responses → 2 HTTP calls (instead of 141)
```

---

## Database Query Comparison

### OLD WAY (No Batching)
```
1000 concurrent ping responses:

┌──────────────────────────────────────────────────────────────┐
│  API Call 1: user_id=1                                       │
│    SELECT * FROM orders WHERE id = 123                       │
│    SELECT * FROM users WHERE id = 1                          │
│    SELECT COUNT(*) FROM actions WHERE order_id=123 AND ...   │
│    INSERT INTO actions (order_id, user_id, ...) VALUES (...) │
│    RPUSH mqtt:publish ...                                    │
├──────────────────────────────────────────────────────────────┤
│  API Call 2: user_id=2                                       │
│    SELECT * FROM orders WHERE id = 123                       │
│    SELECT * FROM users WHERE id = 2                          │
│    SELECT COUNT(*) FROM actions WHERE order_id=123 AND ...   │
│    INSERT INTO actions (order_id, user_id, ...) VALUES (...) │
│    RPUSH mqtt:publish ...                                    │
├──────────────────────────────────────────────────────────────┤
│  ... (998 more identical patterns)                           │
└──────────────────────────────────────────────────────────────┘

Total Queries: ~5000 queries (5 per user)
Total Time: 10-30 seconds (parallel execution with locks)
Issues: Deadlocks, connection pool exhaustion, missing orders
```

### NEW WAY (With Batching)
```
1000 concurrent ping responses:

┌──────────────────────────────────────────────────────────────┐
│  Batch Job 1: 1000 user_ids                                  │
│                                                               │
│  Phase 1: Load Order (once)                                  │
│    SELECT * FROM orders WHERE id = 123                       │
│                                                               │
│  Phase 2: Batch Eligibility (all users at once)             │
│    SELECT * FROM users WHERE id IN (1,2,3,...,1000)         │
│    SELECT user_id FROM actions WHERE order_id=123            │
│    AND user_id IN (1,2,3,...,1000)                          │
│    Filter → 850 eligible users                               │
│                                                               │
│  Phase 3: Chunked Processing (850 users → 11 chunks)        │
│    Chunk 1 (users 1-80):                                     │
│      INSERT IGNORE INTO actions VALUES                       │
│        (123,1,...), (123,2,...), ... (123,80,...)  [1 query]│
│      RPUSH pipeline: 80 jobs [1 pipeline]                    │
│      usleep(50ms)                                            │
│                                                               │
│    Chunk 2 (users 81-160):                                   │
│      INSERT IGNORE INTO actions VALUES                       │
│        (123,81,...), (123,82,...), ... (123,160,...) [1 qry]│
│      RPUSH pipeline: 80 jobs [1 pipeline]                    │
│      usleep(50ms)                                            │
│                                                               │
│    ... (9 more chunks)                                       │
│                                                               │
│  Phase 4: Metrics                                            │
│    HINCRBY metrics total_users 1000                          │
│    HINCRBY metrics processed 850                             │
└──────────────────────────────────────────────────────────────┘

Total Queries: ~15 queries (1 order + 2 eligibility + 11 inserts + 1 metrics)
Total Time: 600-1500ms (serial chunked execution)
Issues: None (controlled chunks, no locks)
```

**Improvement**: 5000 queries → 15 queries = **99.7% reduction**

---

## Performance Metrics Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        REDIS METRICS (Per Minute)                        │
└─────────────────────────────────────────────────────────────────────────┘

Key: ping_batch_metrics:202510051430  (YYYY-MM-DD HH:MM)

┌────────────────────────────────────────────────────────────────────────┐
│  Field              │  Value  │  Description                            │
├─────────────────────┼─────────┼────────────────────────────────────────┤
│  total_users        │  1000   │  Total ping responses received         │
│  eligible_users     │   850   │  Passed eligibility checks             │
│  processed          │   850   │  Successfully inserted to DB           │
│  published          │   850   │  Order announcements published         │
│  batches            │    10   │  Number of batch jobs dispatched       │
│  total_duration_ms  │  6500   │  Total processing time                 │
└────────────────────────────────────────────────────────────────────────┘

Health Check:
✅ processed = eligible_users (100% success)
✅ published = processed (no publish failures)
✅ total_duration_ms < 10000 (acceptable performance)
```

---

## Configuration Impact on Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    CONFIGURATION TUNING IMPACT                           │
└─────────────────────────────────────────────────────────────────────────┘

PING_BATCH_SIZE (mqtt_handler):
┌──────────────────────────────────────────────────────────────────────┐
│  Value │ Behavior                                                     │
├────────┼──────────────────────────────────────────────────────────────┤
│   50   │ More frequent flushes → Lower latency, more API calls      │
│  100   │ Balanced (default) → Good trade-off                         │
│  200   │ Larger batches → Higher throughput, slight latency increase │
│  500+  │ Very large batches → Risk of timeout or memory issues       │
└──────────────────────────────────────────────────────────────────────┘

PING_BATCH_TIMEOUT (mqtt_handler):
┌──────────────────────────────────────────────────────────────────────┐
│  Value │ Behavior                                                     │
├────────┼──────────────────────────────────────────────────────────────┤
│  200ms │ Fast flush → Low latency, smaller batches                   │
│  500ms │ Balanced (default) → Good batch accumulation                │
│ 1000ms │ Slow flush → Larger batches, higher latency                 │
└──────────────────────────────────────────────────────────────────────┘

PING_BATCH_PROCESS_CHUNK_SIZE (Laravel):
┌──────────────────────────────────────────────────────────────────────┐
│  Value │ DB Load  │ Publish Rate │ Risk                              │
├────────┼──────────┼──────────────┼───────────────────────────────────┤
│   50   │ Low      │ ~4000/min    │ Safe, more queries                │
│   80   │ Medium   │ ~6000/min    │ Balanced (default)                │
│  100   │ High     │ ~8000/min    │ Fast, watch for deadlocks         │
│  200+  │ Very High│ 12000+/min   │ Risky, likely deadlocks           │
└──────────────────────────────────────────────────────────────────────┘

PING_BATCH_CHUNK_DELAY_MS (Laravel):
┌──────────────────────────────────────────────────────────────────────┐
│  Value │ Publish Rate │ Impact                                        │
├────────┼──────────────┼───────────────────────────────────────────────┤
│   10ms │ 40000+/min   │ Very fast, may overwhelm MQTT/Redis           │
│   50ms │  6000/min    │ Balanced (default), stable                    │
│  100ms │  3000/min    │ Conservative, very stable                     │
│  500ms │   600/min    │ Too slow for high volume                      │
└──────────────────────────────────────────────────────────────────────┘

Recommended for 5000 concurrent:
  PING_BATCH_SIZE=200
  PING_BATCH_TIMEOUT=300
  PING_BATCH_PROCESS_CHUNK_SIZE=100
  PING_BATCH_CHUNK_DELAY_MS=30
```

---

## System States Visualization

```
┌─────────────────────────────────────────────────────────────────────────┐
│                    SYSTEM STATE: NO LOAD                                 │
└─────────────────────────────────────────────────────────────────────────┘

MQTT Handler:   [Idle] ──► pingResponseBatch = []
API:            [Ready] ──► No requests
Queue:          [Empty] ──► 0 jobs
Redis:          [Light] ──► mqtt:publish: 0 items
Database:       [Idle] ──► 0 active queries

┌─────────────────────────────────────────────────────────────────────────┐
│              SYSTEM STATE: 1000 CONCURRENT RESPONSES                     │
└─────────────────────────────────────────────────────────────────────────┘

Time: 0-500ms (Accumulation Phase)
MQTT Handler:   [Active] ──► pingResponseBatch = [1...100]
                [Flush!] ──► POST /api/trigger-order-batch
                [Active] ──► pingResponseBatch = [101...200]
                [Flush!] ──► POST /api/trigger-order-batch
                ... (10 flushes total)

Time: 500-1500ms (Processing Phase)
API:            [Busy] ──► Dispatching 10 batch jobs
Queue:          [Filling] ──► high queue: 10 jobs
Workers:        [Processing] ──► 3 workers picking up jobs
Redis:          [Busy] ──► mqtt:publish: 850 items (accumulating)
Database:       [Active] ──► 5-10 concurrent batch INSERTs

Time: 1500-3000ms (Publishing Phase)
MQTT Workers:   [Busy] ──► BRPOP and publish from Redis
Redis:          [Draining] ──► mqtt:publish: 850 → 400 → 100 → 0
MQTT Broker:    [Active] ──► Delivering to devices

Time: 3000ms+ (Stable)
All Systems:    [Returning to Idle]
Metrics:        [Updated] ──► ping_batch_metrics hash recorded

┌─────────────────────────────────────────────────────────────────────────┐
│                SYSTEM STATE: 5000 CONCURRENT RESPONSES                   │
└─────────────────────────────────────────────────────────────────────────┘

Time: 0-1000ms (Accumulation Phase)
MQTT Handler:   [Very Active] ──► 50 flushes (batches of 100)
                [Emergency!] ──► Some batches hit max size (200)

Time: 1000-5000ms (Processing Phase)
API:            [Heavy Load] ──► 50 batch jobs dispatched
Queue:          [Full] ──► high queue: 50 jobs
Workers:        [Saturated] ──► 3-5 workers processing constantly
Redis:          [Heavy] ──► mqtt:publish: 4000+ items
Database:       [High Load] ──► 15-20 concurrent batch queries

Time: 5000-10000ms (Publishing & Stabilization)
MQTT Workers:   [Saturated] ──► Consuming as fast as possible
Redis:          [Draining] ──► mqtt:publish: slowly decreasing
Database:       [Cooling] ──► Query count dropping

Time: 10000ms+ (Recovery)
All Systems:    [Returning to Normal]
Metrics:        [Critical Logged] ──► May need tuning
```

---

## Error Handling Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          ERROR SCENARIOS                                 │
└─────────────────────────────────────────────────────────────────────────┘

Scenario 1: Batch API Endpoint Fails (500 Error)
┌────────────────────────────────────────────────────────────────────────┐
│  mqtt_handler flushes batch → API returns 500                          │
│  ├─► Fallback: Process each response individually                       │
│  ├─► Log error: "Falling back to individual processing"                │
│  └─► Result: Slower but data not lost                                  │
└────────────────────────────────────────────────────────────────────────┘

Scenario 2: Database Deadlock During Batch Insert
┌────────────────────────────────────────────────────────────────────────┐
│  ProcessPingResponseBatchJob inserts chunk → Deadlock                  │
│  ├─► Job retries (max 3 attempts)                                      │
│  ├─► Exponential backoff: 50ms, 100ms, 200ms                          │
│  ├─► If all retries fail → Job fails                                   │
│  └─► Log error, metrics show processed < eligible_users                │
└────────────────────────────────────────────────────────────────────────┘

Scenario 3: Queue Worker Crash During Processing
┌────────────────────────────────────────────────────────────────────────┐
│  Worker processing batch → Crash (OOM, timeout, etc.)                  │
│  ├─► Laravel queue marks job as failed                                 │
│  ├─► Job goes to failed_jobs table                                     │
│  ├─► Admin can retry: php artisan queue:retry all                      │
│  └─► Metrics show partial completion                                   │
└────────────────────────────────────────────────────────────────────────┘

Scenario 4: MQTT Publisher Can't Publish (Broker Down)
┌────────────────────────────────────────────────────────────────────────┐
│  mqtt_publisher_worker BRPOPs job → MQTT publish fails                │
│  ├─► Worker retries publish (built-in retry logic)                     │
│  ├─► If max retries exceeded → Job goes to DLQ                         │
│  ├─► Key: egf:mqtt:publish:dead                                        │
│  └─► Can be replayed when broker is back up                            │
└────────────────────────────────────────────────────────────────────────┘

Scenario 5: Batch Size Exceeds Memory Limit
┌────────────────────────────────────────────────────────────────────────┐
│  pingResponseBatch exceeds PING_BATCH_MAX_SIZE (1000)                 │
│  ├─► Emergency flush triggered immediately                              │
│  ├─► Log warning: "Emergency flush: exceeded max size"                 │
│  └─► Result: Batch split, processing continues                         │
└────────────────────────────────────────────────────────────────────────┘
```

This flow diagram document provides a comprehensive visual representation of how the batching system works end-to-end!

