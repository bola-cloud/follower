const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Subscribed to activation responses');
  client.subscribe('devices/activation/res');
});

client.on('message', async (topic, message) => {
  try {
    const payload = JSON.parse(message.toString());
    const { device_id, status } = payload;

    // Call Laravel API to store response
    await axios.post('https://egfollow.com/api/mqtt/device-activation', {
      device_id,
      status,
    });
    console.log(`✅ Stored activation response for device ${device_id}`);
  } catch (err) {
    console.error('❌ Failed to handle message:', err.message);
  }
});
