// Robust mqtt_handler.cjs
// Accepts both { activation_order_id } and { order_id } payload shapes
// Adds retries and configurable concurrency for API calls

const mqtt = require('mqtt');
const axios = require('axios');

const DEBUG = process.env.NODE_ENV !== 'production';
const broker = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const API_BASE = process.env.API_BASE || 'https://egfollow.com';

// Concurrency control
let inflightRequests = 0;
const MAX_INFLIGHT = parseInt(process.env.MQTT_MAX_INFLIGHT || '50', 10);

function sleep(ms) {
  return new Promise((res) => setTimeout(res, ms));
}

async function postWithRetries(url, data, retries = 3, backoff = 200) {
  let lastErr;
  for (let i = 0; i <= retries; i++) {
    try {
      return await axios.post(url, data, { timeout: 10_000 });
    } catch (err) {
      lastErr = err;
      if (i < retries) await sleep(backoff * Math.pow(2, i));
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
    'order/ping/req',
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

  // order/ping/req — a request (incoming) to start/inspect a ping; accept and log to avoid 'Unrecognized topic'
  if (topic === 'order/ping/req') {
    const rawOrder = payload.order_id ?? payload.orderId ?? payload.order;
    const rawUser = payload.user_id ?? payload.userId ?? payload.user;
    const orderId = rawOrder == null ? null : parseInt(rawOrder, 10);
    const userId = rawUser == null ? null : parseInt(rawUser, 10);

    if (DEBUG) console.log('🔔 MQTT recv -> topic: order/ping/req | payload:', payload);

    // Nothing to forward here by default; we just accept the topic so it doesn't show as unrecognized.
    // If you want this to trigger an API call, we can add that behavior later.
    return;
  }

  // order/ping/res — devices report they received a ping (activation)
  if (topic === 'order/ping/res') {
    // Support both shapes: activation_order_id or order_id
    const rawActivation = payload.activation_order_id ?? payload.order_id ?? payload.activationOrderId ?? payload.activationOrder_id;
    const rawUser = payload.user_id ?? payload.userId ?? payload.user;
    const type = payload.type;

    const orderId = rawActivation == null ? NaN : parseInt(rawActivation, 10);
    // Allow user_id to be null (device didn't provide a user); forward null to API instead of rejecting
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

    try {
      const res = await throttledPost(`${API_BASE}/api/mqtt/trigger-order`, {
        order_id: orderId,
        // send null or numeric user_id depending on what we parsed
        user_id: userId,
        type,
        activation: true
      });

      if (DEBUG) console.log('✅ Triggered API:', res.data || res.status);
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


