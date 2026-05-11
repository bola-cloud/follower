// Robust mqtt_handler.cjs
// Accepts both { activation_order_id } and { order_id } payload shapes
// Adds retries and configurable concurrency for API calls
// ✅ BATCHING: Accumulates ping responses and processes in batches for 5000+ concurrent handling

const mqtt = require('mqtt');
const axios = require('axios');
const https = require('https');
const { randomUUID } = require('crypto');
const fs = require('fs');

const DEBUG = process.env.NODE_ENV !== 'production';
const broker = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const API_BASE = process.env.API_BASE || 'https://egfollow.com';
const HTTP_TIMEOUT = parseInt(process.env.MQTT_HTTP_TIMEOUT || '20000', 10); // ms

// Concurrency control
let inflightRequests = 0;
// Allow higher default concurrency for high-throughput environments; can be tuned via MQTT_MAX_INFLIGHT
const MAX_INFLIGHT = parseInt(process.env.MQTT_MAX_INFLIGHT || '200', 10);

// High-volume processing support
const BATCH_ENABLED = process.env.MQTT_BATCH_ENABLED !== 'false';
const BATCH_SIZE = parseInt(process.env.MQTT_BATCH_SIZE || '1000', 10);
const BATCH_TIMEOUT = parseInt(process.env.MQTT_BATCH_TIMEOUT || '2000', 10); // ms
const HEALTH_CHECK_INTERVAL = parseInt(process.env.MQTT_HEALTH_CHECK_INTERVAL || '30000', 10); // 30s
// Allow retrying health checks with an insecure TLS agent when the internal
// health endpoint uses a certificate that doesn't include local IPs (common
// in staging). Enable via ALLOW_INSECURE_HEALTHCHECK=true only when needed.
const ALLOW_INSECURE_HEALTHCHECK = String(process.env.ALLOW_INSECURE_HEALTHCHECK || 'false').toLowerCase() === 'true';

// ✅ PING RESPONSE BATCHING: Accumulate ping responses for batch processing
const PING_BATCH_ENABLED = process.env.PING_BATCH_ENABLED !== 'false';
const PING_BATCH_SIZE = parseInt(process.env.PING_BATCH_SIZE || '1000', 10); // Max responses per batch
const PING_BATCH_TIMEOUT = parseInt(process.env.PING_BATCH_TIMEOUT || '500', 10); // Max wait time in ms
const PING_BATCH_MAX_SIZE = parseInt(process.env.PING_BATCH_MAX_SIZE || '1000', 10); // Emergency flush threshold

const pingResponseBatch = []; // Accumulator for ping responses
let pingBatchTimer = null;

// Device activation batching
const DEVICE_ACT_BATCH_ENABLED = process.env.DEVICE_ACT_BATCH_ENABLED !== 'false';
// Larger batch defaults to reduce HTTP pressure and handle spikes (tune with env vars)
const DEVICE_ACT_BATCH_SIZE = parseInt(process.env.DEVICE_ACT_BATCH_SIZE || '1000', 10);
const DEVICE_ACT_BATCH_TIMEOUT = parseInt(process.env.DEVICE_ACT_BATCH_TIMEOUT || '500', 10);
let deviceActBatch = [];
let deviceActTimer = null;

// ✅ ORDER RESPONSE BATCHING: Accumulate order/res responses for batch processing
const ORDER_RES_BATCH_ENABLED = process.env.ORDER_RES_BATCH_ENABLED !== 'false';
const ORDER_RES_BATCH_SIZE = parseInt(process.env.ORDER_RES_BATCH_SIZE || '1000', 10); // Reduced from 300 for faster drain
const ORDER_RES_BATCH_TIMEOUT = parseInt(process.env.ORDER_RES_BATCH_TIMEOUT || '200', 10); // Max wait time in ms (reduced from 500 for faster flushing)
const ORDER_RES_BATCH_MAX_SIZE = parseInt(process.env.ORDER_RES_BATCH_MAX_SIZE || '1000', 10); // Reduced from 1000 - flush more frequently

const orderResponseBatch = []; // Accumulator for order/res responses
let orderResBatchTimer = null;

// System health tracking
let systemHealth = { status: 'unknown', lastCheck: 0, circuitOpen: false };
const pendingActions = []; // For batching action responses

// --- Known orders cache --------------------------------------------------
// Track order_ids observed via `orders/+` messages so we can detect when
// devices reference an order (on order/ping/res) that wasn't announced to
// users via `orders/{user_id}`. This is intentionally lightweight and
// in-memory — it's only for detection/alerting, not a source of truth.
const KNOWN_ORDERS = new Map(); // order_id -> timestamp(ms)
const ORDER_TTL = parseInt(process.env.KNOWN_ORDER_TTL_MS || String(1000 * 60 * 10), 10); // 10m default

// Periodic cleanup to avoid unbounded memory growth
setInterval(() => {
  const now = Date.now();
  for (const [orderId, ts] of KNOWN_ORDERS) {
    if (now - ts > ORDER_TTL) KNOWN_ORDERS.delete(orderId);
  }
}, Math.max(60_000, Math.floor(ORDER_TTL / 10)));

function recordMissingOrder(orderId, payload, topic) {
  try {
    const line = JSON.stringify({ ts: new Date().toISOString(), orderId, topic, payload }) + '\n';
    fs.appendFileSync('missing_orders.log', line, { encoding: 'utf8' });
  } catch (err) {
    if (DEBUG) console.error('Failed to write missing_orders.log:', err.message);
  }
}

function sleep(ms) {
  return new Promise((res) => setTimeout(res, ms));
}

// Health check system
async function checkSystemHealth() {
  try {
    const response = await axios.get(`${API_BASE}/api/health/system`, {
            timeout: 10000,
            headers: { 'Accept': 'application/json' },
            validateStatus: (status) => status < 400 // Allow redirects but treat as success if < 400
        });

    const health = response.data;
    systemHealth = {
      status: health.status || 'unknown',
      lastCheck: Date.now(),
      circuitOpen: health.circuit_breaker?.status === 'open' || false,
      load: health.system?.load || 'unknown'
    };

    if (DEBUG && health.status !== 'healthy') {
      console.warn('⚠️ System health check:', health);
    }

    return health;
  } catch (err) {
    // Handle certificate altname mismatch (common when API_BASE points at
    // 127.0.0.1 or an IP not present in the cert). Optionally retry with an
    // insecure https agent if explicitly allowed by env var.
    const isAltNameError = err.code === 'ERR_TLS_CERT_ALTNAME_INVALID' ||
      (err.message && err.message.includes("certificate's altnames"));

    if (isAltNameError && ALLOW_INSECURE_HEALTHCHECK) {
      if (DEBUG) console.warn('⚠️ Health check TLS altname mismatch detected; retrying with insecure agent due to ALLOW_INSECURE_HEALTHCHECK=true');
      try {
        const insecureAgent = new https.Agent({ rejectUnauthorized: false });
        const response2 = await axios.get(`${API_BASE}/api/health/system`, {
          timeout: 5000,
          httpsAgent: insecureAgent,
          headers: { 'Accept': 'application/json' }
        });

        const health = response2.data;
        systemHealth = {
          status: health.status || 'unknown',
          lastCheck: Date.now(),
          circuitOpen: health.circuit_breaker?.status === 'open' || false,
          load: health.system?.load || 'unknown'
        };

        if (DEBUG && health.status !== 'healthy') {
          console.warn('⚠️ System health (insecure retry):', health);
        }

        return health;
      } catch (err2) {
        // fallthrough to normal error handling below with err2
        err = err2;
      }
    }

    if (DEBUG) console.error('❌ Health check failed:', err.message);

    // 💡 IMPROVEMENT: Don't choke the system if health check fails due to network/SSL
    // Keep last known status but mark it as 'unknown' to avoid aggressive throttling
    systemHealth = {
      ...systemHealth,
      status: 'unknown',
      lastCheck: Date.now(),
      // circuitOpen: false, // Don't force circuit open on connection errors
      error: err.message
    };
    return null;
  }
}

// Initialize health checking
checkSystemHealth();
setInterval(checkSystemHealth, HEALTH_CHECK_INTERVAL);

// Batch processing for action responses
function processBatchedActions() {
  if (pendingActions.length === 0) return;

  const batch = pendingActions.splice(0, BATCH_SIZE);
  if (batch.length === 0) return;

  // Process batch - if batching fails, fall back to individual processing
  processBatch(batch).catch(async (err) => {
    if (DEBUG) console.warn('⚠️ Batch processing failed, falling back to individual:', err.message);

    // Process each action individually
    for (const action of batch) {
      try {
        await throttledPost(`${API_BASE}/api/mqtt/response`, action);
      } catch (individualErr) {
        console.error('❌ Individual action failed:', individualErr.message, action);
      }
    }
  });
}

async function processBatch(actions) {
  if (!BATCH_ENABLED || !Array.isArray(actions) || actions.length <= 1) {
    // Process individually
    for (const action of actions) {
      await throttledPost(`${API_BASE}/api/mqtt/response`, action);
    }
    return;
  }

  try {
    // Use batch endpoint if available
    const response = await throttledPost(`${API_BASE}/api/mqtt/response-batch`, {
      actions: actions,
      batch_id: randomUUID(),
      timestamp: Date.now()
    });

    if (DEBUG) console.log(`✅ Batch processed: ${actions.length} actions`);
    return response;
  } catch (err) {
    // If batch endpoint not available, fall back to individual
    if (err.response?.status === 404) {
      for (const action of actions) {
        await throttledPost(`${API_BASE}/api/mqtt/response`, action);
      }
      return;
    }
    throw err;
  }
}

// Start batch processing timer
if (BATCH_ENABLED) {
  setInterval(processBatchedActions, BATCH_TIMEOUT);
}

// ✅ PING BATCH PROCESSING: Flush accumulated ping responses
async function flushPingResponseBatch(reason = 'timer') {
  if (pingResponseBatch.length === 0) return;

  // Clear timer if active
  if (pingBatchTimer) {
    clearTimeout(pingBatchTimer);
    pingBatchTimer = null;
  }

  const batch = pingResponseBatch.splice(0, PING_BATCH_MAX_SIZE);
  if (!Array.isArray(batch) || batch.length === 0) {
    console.warn('⚠️ Attempted to flush empty ping response batch, skipping');
    return;
  }
  const batchId = randomUUID();
  const batchSize = batch.length;

  if (DEBUG) console.log(`📦 Flushing ping batch: ${batchSize} responses (reason: ${reason})`);

  try {
    const response = await axios.post(
      `${API_BASE}/api/mqtt/trigger-order-batch`,
      {
        batch_id: batchId,
        responses: batch
      },
      { timeout: HTTP_TIMEOUT * 2 } // Allow longer timeout for batches
    );

    if (DEBUG) {
      console.log(`✅ Ping batch processed: ${batchSize} responses`, {
        batch_id: batchId,
        jobs_dispatched: response.data?.jobs_dispatched,
        duration_ms: response.data?.duration_ms
      });
    }

  } catch (err) {
    const status = err.response?.status;
    console.error(`❌ Ping batch failed (${batchSize} responses) status=${status}:`, err.response?.data || err.message);
    if (status === 504 || err.code === 'ETIMEDOUT') {
      console.error('🔔 Detected HTTP 504 or timeout when sending ping response batch', { batch_id: batchId, batchSize, status, error: err.message });
    }

    // If batch endpoint fails, fall back to individual processing for this batch
    if (err.response?.status === 404 || err.response?.status === 500) {
      console.warn(`⚠️ Falling back to individual processing for ${batchSize} responses`);

      for (const response of batch) {
        try {
          await throttledPost(`${API_BASE}/api/mqtt/trigger-order`, response);
        } catch (individualErr) {
          console.error('❌ Individual fallback failed:', individualErr.message, response);
        }
      }
    }
  }
}

// Start ping batch flush timer
if (PING_BATCH_ENABLED) {
  setInterval(() => flushPingResponseBatch('timer'), PING_BATCH_TIMEOUT);
}

// ✅ ORDER RESPONSE BATCH PROCESSING: Flush accumulated order/res responses
async function flushOrderResponseBatch(reason = 'timer') {
  // If there's nothing to flush, return quickly
  if (orderResponseBatch.length === 0) return;

  // Clear per-message timer if active
  if (orderResBatchTimer) {
    clearTimeout(orderResBatchTimer);
    orderResBatchTimer = null;
  }

  const batch = orderResponseBatch.splice(0, ORDER_RES_BATCH_MAX_SIZE);
  if (!Array.isArray(batch) || batch.length === 0) {
    // nothing to do
    return;
  }

  const batchId = randomUUID();
  const batchSize = batch.length;

  try {
    // Primary: drain endpoint — use postWithRetries to get retry/backoff and circuit-awareness
    const response = await postWithRetries(`${API_BASE}/api/mqtt/response-batch-drain`, { batch_id: batchId, actions: batch, timestamp: Date.now() });

    // Always log success so PM2 logs show the HTTP result (helpful when DEBUG=false)
    console.log(`[mqtt-handler] Order response batch sent: batch_id=${batchId}, size=${batchSize}, status=${response?.status}`);
    if (DEBUG && response?.data) console.log('[mqtt-handler] drain response data:', response.data);
  } catch (err) {
    const status = err.response?.status;
    console.error(`[mqtt-handler] Order response batch failed status=${status}:`, err.response?.data || err.message);
    if (DEBUG && err.stack) console.error(err.stack);

    // Fallback: regular batch endpoint (also use postWithRetries)
    try {
      const fbResp = await postWithRetries(`${API_BASE}/api/mqtt/response-batch`, { batch_id: batchId, actions: batch, timestamp: Date.now() });
      console.log(`[mqtt-handler] Fallback batch processed: size=${batchSize}, status=${fbResp?.status}`);
      if (DEBUG && fbResp?.data) console.log('[mqtt-handler] fallback response data:', fbResp.data);
    } catch (fallbackErr) {
      console.error('[mqtt-handler] Fallback batch also failed:', fallbackErr.response?.data || fallbackErr.message);
      if (DEBUG && fallbackErr.stack) console.error(fallbackErr.stack);

      // Final fallback: individual posts
      for (const action of batch) {
        try {
          await throttledPost(`${API_BASE}/api/mqtt/response`, action);
        } catch (individualErr) {
          console.error('[mqtt-handler] Individual fallback failed:', individualErr.message, action);
        }
      }
    }
  }
}

// Start order response batch flush timer
if (ORDER_RES_BATCH_ENABLED) {
  console.log(`⏰ ORDER RESPONSE BATCH TIMER STARTED: Will flush every ${ORDER_RES_BATCH_TIMEOUT}ms`);
  setInterval(() => {
    if (orderResponseBatch.length > 0) {
      if (DEBUG) console.log(`⏰ Timer triggered: Flushing ${orderResponseBatch.length} order responses (reason: timer_interval)`);
      flushOrderResponseBatch('timer_interval');
    }
  }, ORDER_RES_BATCH_TIMEOUT);
}

async function postWithRetries(url, data, retries = 4, backoff = 300) {
  // Check system health before making requests
  const now = Date.now();
  if (now - systemHealth.lastCheck > HEALTH_CHECK_INTERVAL * 2) {
    await checkSystemHealth();
  }

  // If circuit breaker is open, use longer backoff
  if (systemHealth.circuitOpen) {
    backoff = Math.max(backoff, 1000); // Minimum 1s backoff when circuit is open
    retries = Math.max(retries, 6); // More retries when system is degraded
  }

  let lastErr;
  for (let i = 0; i <= retries; i++) {
    try {
      return await axios.post(url, data, { timeout: HTTP_TIMEOUT });
    } catch (err) {
      lastErr = err;

      const status = err.response?.status;
      const isServerError = status >= 500 && status < 600;
      const isConflict = status === 409;
      const isTooManyRequests = status === 429;

      // Decide whether to retry: network errors, 5xx, 409 (DB busy), or 429 (rate limited)
      const shouldRetry = !err.response || isServerError || isConflict || isTooManyRequests;

      if (!shouldRetry) {
        // Not retriable (eg. validation error) — rethrow immediately
        throw err;
      }

      if (i < retries) {
        // Enhanced backoff with system health awareness
        let delay = Math.round(backoff * Math.pow(2, i) + (Math.random() * backoff));

        // Extra delay for 429 (rate limiting) or when circuit is open
        if (isTooManyRequests || systemHealth.circuitOpen) {
          delay *= 2;
        }

        if (DEBUG) console.warn(`⚠️ HTTP retry ${i + 1}/${retries} for ${url} (status=${status || 'network'}, delay=${delay}ms, circuit=${systemHealth.circuitOpen ? 'open' : 'closed'})`);
        await sleep(delay);
        continue;
      }
      // final failure after retries
    }
  }
  throw lastErr;
}

async function throttledPost(url, data) {
  // Dynamic concurrency control based on system health
  let maxConcurrent = MAX_INFLIGHT;
  // 🚀 HIGH THROUGHPUT: Only throttle if we are CERTAIN of high load
  // If health check is failing (status unknown), assume server is fine and keep speed up
  if (systemHealth.status === 'unhealthy' || (systemHealth.circuitOpen && systemHealth.load === 'high')) {
    maxConcurrent = Math.floor(MAX_INFLIGHT * 0.5);
  }

  while (inflightRequests >= maxConcurrent) {
    await sleep(systemHealth.circuitOpen ? 50 : 10); // Longer wait when system is degraded
  }

  inflightRequests++;
  try {
    return await postWithRetries(url, data, systemHealth.circuitOpen ? 6 : 2, systemHealth.circuitOpen ? 500 : 150);
  } finally {
    inflightRequests--;
  }
}

async function postDeviceActivationBatch(batch) {
  if (!Array.isArray(batch) || batch.length === 0) return;
  try {
    const payload = { activations: batch };
    const res = await throttledPost(`${API_BASE}/api/mqtt/device-activation-batch`, payload);
    if (DEBUG) console.log(`✅ Posted device activation batch (${batch.length})`, res.data || res.status);
    return res;
  } catch (err) {
    console.error('❌ postDeviceActivationBatch failed:', err.response?.data || err.message);
    throw err;
  }
}

const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');
  client.subscribe([
    'devices/activation/req',
    'devices/activation/v2/res',
    'order/ping/res',
    'order/res/+/+',
    'orders/+',
    'user/ping/+'
  ], (err) => {
    if (err) console.error('❌ Subscription error:', err.message);
    else console.log('✅ Subscribed to topics');
  });
});

client.on('message', async (topic, message) => {
  // Log only in debug mode to avoid saturating PM2 logs
  if (DEBUG) {
    console.log(`🔔 MQTT RAW MESSAGE -> topic: ${topic} | size: ${message.length} bytes`);
  }

  let payload;
  try {
    payload = JSON.parse(message.toString());
    if (DEBUG) {
      console.log(`🔔 MQTT PARSED -> topic: ${topic} | payload: ${JSON.stringify(payload).substring(0, 200)}`);
    }
  } catch (err) {
    console.error('❌ Failed to parse JSON message:', err.message, 'raw:', message.toString().substring(0, 100));
    return;
  }

  if (DEBUG) console.log(`🔔 MQTT recv -> topic: ${topic} | payload: ${JSON.stringify(payload)}`);

  // ...existing code...

  // order/ping/res — devices report they received a ping (activation)
  if (topic === 'order/ping/res') {
    // Support both shapes: activation_order_id or order_id
    const rawActivation = payload.activation_order_id ?? payload.order_id ?? payload.activationOrderId ?? payload.activationOrder_id;
    const rawUser = payload.user_id ?? payload.userId ?? payload.user;
    const type = payload.type;

    const orderId = rawActivation == null ? NaN : parseInt(rawActivation, 10);
    const userId = rawUser == null ? null : parseInt(rawUser, 10);

    if (!type || Number.isNaN(orderId)) {
      console.error('❌ Invalid ping response payload:', {
        type,
        activation_order_id: Number.isNaN(orderId) ? rawActivation : orderId,
        user_id: userId,
        raw_user_id: rawUser,
        raw_activation_order_id: rawActivation,
        original: payload
      });
      return;
    }

    // Map device-level types (follow/like/...) to API-allowed types (create/resume)
    let mappedType = 'create';
    if (typeof type === 'string') {
      const t = type.toLowerCase();
      if (t === 'resume') mappedType = 'resume';
      else mappedType = 'create';
    }

    // If the device didn't provide a user_id, skip
    if (userId === null) {
      console.warn('⚠️ Ping response missing user_id, skipping:', payload);
      return;
    }

    // Detect if this order_id was previously announced via orders/+
    if (!KNOWN_ORDERS.has(orderId)) {
      const msg = `⚠️ Received ping response for unknown order ${orderId} (user ${userId})`;
      console.warn(msg, { original: payload });
      recordMissingOrder(orderId, payload, topic);
    }

    // ✅ BATCH MODE: Accumulate ping responses for batch processing
    if (PING_BATCH_ENABLED) {
      pingResponseBatch.push({
        order_id: orderId,
        user_id: userId,
        type: mappedType,
        activation: true
      });

      // Flush immediately if batch is full
      if (pingResponseBatch.length >= PING_BATCH_SIZE) {
        flushPingResponseBatch('size_limit');
      }
      // Emergency flush if batch is extremely large
      else if (pingResponseBatch.length >= PING_BATCH_MAX_SIZE) {
        console.warn(`⚠️ Emergency flush: ping batch exceeded ${PING_BATCH_MAX_SIZE}`);
        flushPingResponseBatch('emergency');
      }
      // Schedule timer flush if not already scheduled
      else if (!pingBatchTimer) {
        pingBatchTimer = setTimeout(() => flushPingResponseBatch('timer'), PING_BATCH_TIMEOUT);
      }

      if (DEBUG && pingResponseBatch.length % 50 === 0) {
        console.log(`📊 Ping batch accumulator: ${pingResponseBatch.length} responses`);
      }

      return;
    }

    // FALLBACK: Individual processing (legacy mode if batching disabled)
    try {
      const messageId = randomUUID();
      const postBody = {
        message_id: messageId,
        order_id: orderId,
        user_id: userId,
        type: mappedType,
        activation: true
      };

      const res = await throttledPost(`${API_BASE}/api/mqtt/trigger-order`, postBody);

      if (DEBUG) console.log('✅ Triggered API:', res.data || res.status, 'message_id=', messageId);
    } catch (err) {
      console.error('❌ Failed to trigger API for ping response:', err.response?.data || err.message);
    }

    return;
  }

  // user ping
  const pingMatch = topic.match(/^user\/ping\/(\d+)$/);
  if (pingMatch) {
    const userId = parseInt(pingMatch[1], 10);
    const { order_id, request } = payload;
    if (request === 'ping') {
      const response = { order_id: order_id, status: 'online' };
      client.publish(`user/ping/response/${userId}`, JSON.stringify(response), { qos: 1 });
      if (DEBUG) console.log(`🏓 Ping response sent for user ${userId}`);
    }
    return;
  }

  // orders/{user_id} notifications
  const ordersMatch = topic.match(/^orders\/(\d+)$/);
  if (ordersMatch) {
    const userId = parseInt(ordersMatch[1], 10);
    const { url, order_id, type } = payload;
    if (!url || !order_id || !type || Number.isNaN(userId)) {
      console.warn('⚠️ Missing fields in orders message:', payload);
      return;
    }
    // Record the announced order_id in our known-orders cache so we can
    // detect later if devices report pings for orders that were never
    // announced to users via `orders/{user_id}`.
    try {
      const id = Number(order_id);
      if (!Number.isNaN(id)) KNOWN_ORDERS.set(id, Date.now());
    } catch (err) {
      if (DEBUG) console.warn('Failed to record known order:', err.message);
    }

    if (DEBUG) console.log(`� Order notification for user ${userId}: order ${order_id}`);
    return;
  }

  // Silence noisy regex trace logs in production
  if (DEBUG) {
    console.log(`🔍 Checking if topic matches order/res pattern: ${topic}`);
  }
  const respMatch = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (DEBUG) {
    console.log(`🔍 Regex match result: ${respMatch ? 'MATCHED' : 'NO MATCH'}`);
  }

  if (respMatch) {
    const order_id = parseInt(respMatch[1], 10);
    const user_id = parseInt(respMatch[2], 10);
    const { status } = payload;

    // Log order responses only in debug or periodically
    if (DEBUG || orderResponseBatch.length % 100 === 0) {
      console.log(`📨 order/res received: order_id=${order_id}, user_id=${user_id}, status=${status}`);
    }

    if (!status || Number.isNaN(order_id) || Number.isNaN(user_id)) {
      console.warn('⚠️ Missing fields in order response payload:', { topic, payload });
      return;
    }

    // If device reports 'busy' on the final response topic, silently ignore it.
    // Final responses are expected to be 'done' or 'external' only.
    if (String(status).toLowerCase() === 'busy') {
      if (DEBUG) console.log(`⏭️ Ignoring final 'busy' response for order ${order_id} user ${user_id}`);
      // Optionally record to a lightweight log for post-mortem without calling API
      try {
        // Keep a small local log file for debugging missing orders, but avoid heavy I/O in hot paths
        fs.appendFileSync('ignored_busy_responses.log', JSON.stringify({ ts: new Date().toISOString(), order_id, user_id, status }) + '\n', { encoding: 'utf8' });
      } catch (e) {
        if (DEBUG) console.warn('Failed to write ignored_busy_responses.log:', e.message);
      }
      return;
    }

    const actionData = { order_id, user_id, status };

    // ✅ BATCH MODE: Accumulate order responses for batch processing
    if (ORDER_RES_BATCH_ENABLED) {
      orderResponseBatch.push(actionData);
      if (DEBUG || orderResponseBatch.length % 100 === 0) {
        console.log(`📊 Order response added to batch: ${orderResponseBatch.length}/${ORDER_RES_BATCH_SIZE}`);
      }

      // Flush immediately if batch is full
      if (orderResponseBatch.length >= ORDER_RES_BATCH_SIZE) {
        console.log(`🔔 Batch full, flushing immediately: ${orderResponseBatch.length} responses`);
        flushOrderResponseBatch('size_limit');
      }
      // Emergency flush if batch is extremely large
      else if (orderResponseBatch.length >= ORDER_RES_BATCH_MAX_SIZE) {
        console.warn(`⚠️ Emergency flush: order response batch exceeded ${ORDER_RES_BATCH_MAX_SIZE}`);
        flushOrderResponseBatch('emergency');
      }
      // Schedule timer flush if not already scheduled
      else if (!orderResBatchTimer) {
        orderResBatchTimer = setTimeout(() => flushOrderResponseBatch('timer'), ORDER_RES_BATCH_TIMEOUT);
        console.log(`⏱️ Batch timer scheduled: will flush in ${ORDER_RES_BATCH_TIMEOUT}ms if not full`);
      }

      if (DEBUG && orderResponseBatch.length % 25 === 0) {
        console.log(`📊 Order response batch accumulator: ${orderResponseBatch.length} actions`);
      }

      return;
    }

    // FALLBACK: Legacy batching (old method, less efficient)
    // Use batching if enabled and system is healthy
    if (BATCH_ENABLED && !systemHealth.circuitOpen && systemHealth.status === 'healthy') {
      pendingActions.push(actionData);

      // Process immediately if batch is full
      if (pendingActions.length >= BATCH_SIZE) {
        processBatchedActions();
      }

      if (DEBUG) console.log(`📦 Action queued for legacy batch: ${pendingActions.length}/${BATCH_SIZE}`);
      return;
    }

    // Process immediately (non-batched or system degraded)
    try {
      const res = await throttledPost(`${API_BASE}/api/mqtt/response`, actionData);
      if (DEBUG) console.log('✅ Action updated:', res.data || res.status);
    } catch (err) {
      console.error('❌ Failed to update action:', err.response?.data || err.message);
    }

    return;
  }

  // device activation v2
  if (topic === 'devices/activation/v2/res') {
    const { device_id, status } = payload;
    if (!device_id || !status) return console.warn('⚠️ Missing device_id or status:', payload);
    try {
      if (DEVICE_ACT_BATCH_ENABLED) {
        // accumulate and flush in batches
        deviceActBatch.push({ device_id: String(device_id), status });

        if (deviceActBatch.length >= DEVICE_ACT_BATCH_SIZE) {
          // flush immediately
          const toSend = deviceActBatch.splice(0, DEVICE_ACT_BATCH_SIZE);
          postDeviceActivationBatch(toSend).catch(e => console.error('❌ Batch post failed:', e.message));
        } else if (!deviceActTimer) {
          deviceActTimer = setTimeout(() => {
            const toSend = deviceActBatch.splice(0, deviceActBatch.length);
            deviceActTimer = null;
            if (toSend.length > 0) postDeviceActivationBatch(toSend).catch(e => console.error('❌ Batch post failed:', e.message));
          }, DEVICE_ACT_BATCH_TIMEOUT);
        }
      } else {
        await axios.post(`${API_BASE}/api/mqtt/device-activation`, { device_id, status });
      }
    } catch (err) {
      console.error('❌ Failed to store activation:', err.response?.data || err.message);
    }
    return;
  }

  if (DEBUG) console.warn('⚠️ Unrecognized topic:', topic);
});

process.on('SIGINT', () => {
  console.log('📡 MQTT handler shutting down...');

  // Process any remaining ping response batches
  if (pingResponseBatch.length > 0) {
    console.log(`🔄 Flushing ${pingResponseBatch.length} remaining ping responses...`);
    flushPingResponseBatch('shutdown').catch(err => {
      console.error('❌ Failed to flush ping responses on shutdown:', err.message);
    });
  }

  // Process any remaining order response batches
  if (orderResponseBatch.length > 0) {
    console.log(`🔄 Flushing ${orderResponseBatch.length} remaining order responses...`);
    flushOrderResponseBatch('shutdown').catch(err => {
      console.error('❌ Failed to flush order responses on shutdown:', err.message);
    });
  }

  // Process any remaining batched actions before shutdown
  if (pendingActions.length > 0) {
    console.log(`🔄 Processing ${pendingActions.length} remaining actions...`);
    processBatchedActions();

    // Wait a moment for processing
    setTimeout(() => {
      client.end();
      process.exit(0);
    }, 1000);
  } else {
    client.end();
    process.exit(0);
  }
});

// Graceful handling of uncaught errors
process.on('uncaughtException', (err) => {
  console.error('❌ Uncaught Exception:', err);
  // Process remaining actions and exit
  if (pendingActions.length > 0) {
    processBatchedActions();
  }
  setTimeout(() => process.exit(1), 2000);
});

process.on('unhandledRejection', (reason, promise) => {
  console.error('❌ Unhandled Rejection at:', promise, 'reason:', reason);
});


