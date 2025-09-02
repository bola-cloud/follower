// node_scripts/mqtt_handler.cjs

const mqtt = require('mqtt');
const axios = require('axios');

const DEBUG = process.env.NODE_ENV !== 'production';
const broker = 'mqtt://109.199.112.65:1883';

// Simple throttling for API calls
let inflightRequests = 0;
const maxInflightRequests = 5;

async function throttledPost(url, data) {
  while (inflightRequests >= maxInflightRequests) {
    await new Promise(resolve => setTimeout(resolve, 10));
  }

  inflightRequests++;
  try {
    return await axios.post(url, data);
  } finally {
    inflightRequests--;
  }
}

const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');

  // Subscribe to all required topics
  client.subscribe([
    'devices/activation/req',  // Listen to activation requests (dashboard)
    'devices/activation/v2/res',  // Device activation responses (dashboard)
    'order/ping/req',          // Order ping requests
    'order/ping/res',          // Order ping responses
    'order/res/+/+',           // Order completion responses
    'orders/+',                // User order notifications
    'user/ping/+' // Add ping subscription
  ], (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    } else {
      console.log('✅ Subscribed to all required topics');
    }
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

  // DEBUG: log every incoming topic and raw payload to help trace missing topics
  console.log(`🔔 MQTT recv -> topic: ${topic} | payload: ${message.toString()}`);

  // ✅ Handle device activation requests (for logging/monitoring)
  if (topic === 'devices/activation/req') {
    const { request, order_id } = payload;

    if (order_id) {
      console.log(`📡 Order ping broadcast sent for order ${order_id}`);
    } else {
      console.log(`📡 Regular activation check broadcast sent`);
    }
    return;
  }

  // ✅ Handle order ping requests (for logging/monitoring)
  if (topic === 'order/ping/req') {
    const { type, order_id } = payload;
    console.log(`📡 Order ping request for order_id ${order_id} with type ${type}`);
    return;
  }

    // ✅ Handle order ping responses (device response to ping)
  if (topic === 'order/ping/res') {
    if (DEBUG) console.log('🔎 [DEBUG] Received message on order/ping/res:', message.toString());
    const { activation_order_id, user_id, type } = payload;

    // Convert user_id from string to integer and validate
    const userIdInt = parseInt(user_id, 10);
    const orderIdInt = parseInt(activation_order_id, 10);

    if (!type || !orderIdInt || !userIdInt || isNaN(userIdInt) || isNaN(orderIdInt)) {
      console.error('❌ Invalid ping response payload:', {
        type,
        activation_order_id: orderIdInt,
        user_id: userIdInt,
        raw_user_id: user_id,
        raw_activation_order_id: activation_order_id
      });
      return;
    }

    try {
      const response = await throttledPost('https://egfollow.com/api/mqtt/trigger-order', {
        order_id: orderIdInt,
        user_id: userIdInt,
        type,
        activation: true
      });

      if (DEBUG) console.log(`✅ Triggered API for type ${type}, order_id ${orderIdInt}, user ${userIdInt} | Response:`, response.data);
    } catch (err) {
      console.error(`❌ Failed to trigger API for type ${type}, order_id ${orderIdInt}, user ${userIdInt}:`, err.response?.data || err.message);
    }

    return;
  }

  // ✅ Handle device activation (dashboard only - no order_id)
  if (topic === 'devices/activation/v2/res') {
    const { device_id, status } = payload;

    if (!device_id || !status) {
      return console.warn('⚠️ Missing device_id or status:', payload);
    }

    // Regular activation - cache it for dashboard
    try {
      const res = await axios.post('https://egfollow.com/api/mqtt/device-activation', {
        device_id,
        status,
      });

      console.log(`✅ Stored activation for device ${device_id} | Status: ${status} | Count: ${res.data.count}`);
    } catch (err) {
      console.error('❌ Failed to store activation:', err.response?.data || err.message);
    }

    return;
  }

  // ✅ Handle user pings
  const pingMatch = topic.match(/^user\/ping\/(\d+)$/);
  if (pingMatch) {
    const userId = parseInt(pingMatch[1], 10);
    const { order_id, request } = payload;

    if (request === 'ping') {
      // Respond to ping (simulate user device response)
      const response = {
        order_id: order_id,
        status: 'online'
      };

      client.publish(`user/ping/response/${userId}`, JSON.stringify(response), { qos: 1 });
      console.log(`🏓 Ping response sent for user ${userId}`);
    }
    return;
  }

  // ✅ Handle orders/{user_id} messages (user order notifications)
  const ordersMatch = topic.match(/^orders\/(\d+)$/);
  if (ordersMatch) {
    const userId = parseInt(ordersMatch[1], 10);
    const { url, order_id, type } = payload;

    if (!url || !order_id || !type || !userId) {
      console.warn('⚠️ Missing fields in orders message:', payload);
      return;
    }

    console.log(`📦 Order notification sent to user ${userId}: order ${order_id}, type ${type}, url ${url}`);

    // This is just a notification message - the user device will process it
    // and later respond on order/res/{order_id}/{user_id} when task is complete
    return;
  }

  // ✅ Handle order responses
  const match = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (match) {
    const order_id = parseInt(match[1], 10);
    const user_id = parseInt(match[2], 10);
    const { status } = payload;

    if (!status || !order_id || !user_id) {
      return console.warn('⚠️ Missing fields in order response:', payload);
    }

    try {
      const res = await axios.post('https://egfollow.com/api/mqtt/response', {
        order_id,
        user_id,
        status
      });

      console.log(`✅ Action updated for order ${order_id}, user ${user_id} | Status: ${status}`);
    } catch (err) {
      console.error('❌ Failed to update action:', err.response?.data || err.message);
    }
  } else {
    console.warn('⚠️ Unrecognized topic:', topic);
  }
});

// Graceful shutdown handling
process.on('SIGINT', () => {
  console.log('📡 Received SIGINT, shutting down gracefully...');
  client.end();
  process.exit(0);
});

process.on('SIGTERM', () => {
  console.log('📡 Received SIGTERM, shutting down gracefully...');
  client.end();
  process.exit(0);
});
