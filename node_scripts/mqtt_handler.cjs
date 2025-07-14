// node_scripts/mqtt_handler.cjs

const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');

  // Subscribe to both topics
  client.subscribe([
    'devices/activation/res',
    'order/res/+/+'
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

  // ✅ Handle device activation
  if (topic === 'devices/activation/res') {
    const { device_id, status } = payload;

    if (!device_id || !status) {
      return console.warn('⚠️ Missing device_id or status:', payload);
    }

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
