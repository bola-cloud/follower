// Robust mqtt_handler.cjs
// Accepts both { activation_order_id } and { order_id } payload shapes
// Adds retries and configurable concurrency for API calls

const mqtt = require('mqtt');
const axios = require('axios');
const { randomUUID } = require('crypto');
const fs = require('fs');

const DEBUG = process.env.NODE_ENV !== 'production';
const broker = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const API_BASE = process.env.API_BASE || 'https://egfollow.com';
const HTTP_TIMEOUT = parseInt(process.env.MQTT_HTTP_TIMEOUT || '20000', 10); // ms

// Concurrency control
let inflightRequests = 0;
const MAX_INFLIGHT = parseInt(process.env.MQTT_MAX_INFLIGHT || '50', 10);

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

async function postWithRetries(url, data, retries = 4, backoff = 300) {
  let lastErr;
  for (let i = 0; i <= retries; i++) {
    try {
      return await axios.post(url, data, { timeout: HTTP_TIMEOUT });
    } catch (err) {
      lastErr = err;

      const status = err.response?.status;
      const isServerError = status >= 500 && status < 600;
      const isConflict = status === 409;

      // Decide whether to retry: network errors, 5xx, or 409 (DB busy)
      const shouldRetry = !err.response || isServerError || isConflict;

      if (!shouldRetry) {
        // Not retriable (eg. validation error) — rethrow immediately
        throw err;
      }

      if (i < retries) {
        // exponential backoff with jitter
        const delay = Math.round(backoff * Math.pow(2, i) + (Math.random() * backoff));
        if (DEBUG) console.warn(`⚠️ HTTP retry ${i + 1}/${retries} for ${url} (status=${status || 'network'}, delay=${delay}ms)`);
        await sleep(delay);
        continue;
      }
      // final failure after retries
    }
  }
  throw lastErr;
}

async function throttledPost(url, data) {
  while (inflightRequests >= MAX_INFLIGHT) {
    await sleep(10);
  }

  inflightRequests++;
  try {
    return await postWithRetries(url, data, 2, 150);
  } finally {
    inflightRequests--;
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
  let payload;
  try {
    payload = JSON.parse(message.toString());
  } catch (err) {
    console.error('❌ Failed to parse JSON message:', err.message);
    return;
  }

  if (DEBUG) console.log(`🔔 MQTT recv -> topic: ${topic} | payload: ${JSON.stringify(payload)}`);

  // ...existing code...

  // order/ping/res — devices report task completion (mark action as done)
  if (topic === 'order/ping/res') {
    // Support both shapes: activation_order_id or order_id
    const rawActivation = payload.activation_order_id ?? payload.order_id ?? payload.activationOrderId ?? payload.activationOrder_id;
    const rawUser = payload.user_id ?? payload.userId ?? payload.user;
    const type = payload.type;

    const orderId = rawActivation == null ? NaN : parseInt(rawActivation, 10);
    const userId = rawUser == null ? null : parseInt(rawUser, 10);

    if (!type || Number.isNaN(orderId) || userId === null || Number.isNaN(userId)) {
      console.error('❌ Invalid ping response payload (missing order_id, user_id, or type):', {
        type,
        activation_order_id: Number.isNaN(orderId) ? rawActivation : orderId,
        user_id: userId,
        raw_user_id: rawUser,
        raw_activation_order_id: rawActivation,
        original: payload
      });
      return;
    }

    try {
      try {
      // Detect if this order_id was previously announced via orders/+
      if (!KNOWN_ORDERS.has(orderId)) {
        const msg = `⚠️ Received ping response for unknown order ${orderId} (user ${userId})`;
        console.warn(msg, { original: payload });
        // append to local log for later inspection
        recordMissingOrder(orderId, payload, topic);
      }

      const res = await throttledPost(`${API_BASE}/api/mqtt/response`, {
        order_id: orderId,
        user_id: userId,
        status: 'done'
      });

      if (DEBUG) console.log('✅ Action marked as done:', res.data || res.status, 'order=', orderId, 'user=', userId);
    } catch (err) {
      console.error('❌ Failed to mark action as done:', err.response?.data || err.message);
    }
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

  // order/res/{order_id}/{user_id} — final device response (task done/external)
  const respMatch = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (respMatch) {
    const order_id = parseInt(respMatch[1], 10);
    const user_id = parseInt(respMatch[2], 10);
    const { status } = payload;

    if (!status || Number.isNaN(order_id) || Number.isNaN(user_id)) {
      console.warn('⚠️ Missing fields in order response payload:', { topic, payload });
      return;
    }

    try {
      const res = await throttledPost(`${API_BASE}/api/mqtt/response`, {
        order_id,
        user_id,
        status
      });

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
      await axios.post(`${API_BASE}/api/mqtt/device-activation`, { device_id, status });
    } catch (err) {
      console.error('❌ Failed to store activation:', err.response?.data || err.message);
    }
    return;
  }

  if (DEBUG) console.warn('⚠️ Unrecognized topic:', topic);
});

process.on('SIGINT', () => {
  console.log('📡 MQTT handler shutting down...');
  client.end();
  process.exit(0);
});


