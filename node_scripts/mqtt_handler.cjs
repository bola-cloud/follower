// node_scripts/mqtt_handler.cjs

const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');

  // Subscribe to all required topics
  client.subscribe([
    'devices/activation/req',  // Listen to activation requests (dashboard)
    'devices/activation/res',  // Device activation responses (dashboard)
    'order/ping/req',          // Order ping requests
    'order/ping/res',          // Order ping responses
    'order/res/+/+',
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
    const { request, order_id } = payload;
    console.log(`📡 Order ping broadcast sent for order ${order_id}`);
    return;
  }

  // ✅ Handle order ping responses (separate from device activation)
  if (topic === 'order/ping/res') {
    const { device_id, status, order_id } = payload;
    console.log(`🔄 Order ping response from device ${device_id} for order ${order_id}`);
    return;
  }

  // ✅ Handle device activation (dashboard only - no order_id)
  if (topic === 'devices/activation/res') {
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
