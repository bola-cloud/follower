const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');
  client.subscribe('order/res/+/+');
  console.log('✅ Subscribed to topic: order/res/+/+');
});

client.on('message', async (topic, message) => {
  const payload = JSON.parse(message.toString());

  const match = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (!match) return console.warn('❌ Invalid topic format:', topic);

  const order_id = parseInt(match[1], 10);
  const user_id = parseInt(match[2], 10);
  const status = payload.status;

  console.log(`📥 Response received for order ${order_id} from user ${user_id}`);
  console.log('📦 Payload:', payload);

  try {
    const res = await axios.post('https://egfollow.com/api/mqtt/response', {
      order_id,
      user_id,
      status
    });

    console.log('✅ Action updated:', res.data);
  } catch (err) {
    console.error('❌ Failed to update action:', err.response?.data || err.message);
  }
});
