const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker and subscribing to "devices/activation/res"');
  client.subscribe('devices/activation/res', (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    }
  });
});

client.on('message', async (topic, message) => {
  if (topic !== 'devices/activation/res') return;

  try {
    const payload = JSON.parse(message.toString());
    const { device_id, status } = payload;

    if (!device_id || !status) {
      console.warn('⚠️ Missing device_id or status:', payload);
      return;
    }

    await axios.post('https://egfollow.com/api/mqtt/device-activation', {
      device_id,
      status,
    });

    console.log(`✅ Stored activation response for device ${device_id} | Status: ${status}`);
  } catch (err) {
    console.error('❌ Failed to process message:', err.message);
  }
});
