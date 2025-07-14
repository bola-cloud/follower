const mqtt = require('mqtt');
const axios = require('axios');

// MQTT Broker URL
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

// Topic to listen to
const topic = 'devices/activation/res';

client.on('connect', () => {
  console.log(`✅ Connected to MQTT broker and subscribing to "${topic}"`);
  client.subscribe(topic, (err) => {
    if (err) {
      console.error(`❌ Failed to subscribe to "${topic}":`, err.message);
    }
  });
});

client.on('message', async (receivedTopic, message) => {
  if (receivedTopic !== topic) return;

  try {
    const payload = JSON.parse(message.toString());
    const { device_id, status } = payload;

    if (!device_id || !status) {
      console.warn('⚠️ Missing device_id or status in payload:', payload);
      return;
    }

    // Send POST request to Laravel API
    const response = await axios.post('https://egfollow.com/api/mqtt/device-activation', {
      device_id,
      status,
    });

    console.log(`✅ Stored activation response for device ${device_id} | Status: ${status}`);
  } catch (err) {
    console.error('❌ Failed to process MQTT message:', err.message);
  }
});
