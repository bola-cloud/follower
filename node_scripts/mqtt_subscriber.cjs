const mqtt = require('mqtt');
const axios = require('axios');
const https = require('https');

// Only run subscriber if explicitly enabled to avoid duplication with mqtt_handler.cjs
const SUBSCRIBER_ENABLED = process.env.MQTT_SUBSCRIBER_ENABLED === 'true';

if (!SUBSCRIBER_ENABLED) {
  console.log('🔒 MQTT Subscriber is disabled by default to prevent duplication.');
  console.log('   Set MQTT_SUBSCRIBER_ENABLED=true to enable this standalone subscriber.');
  console.log('   Note: mqtt_handler.cjs under PM2 is the primary MQTT processor.');
  process.exit(0);
}

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

const axiosInstance = axios.create({
  httpsAgent: new https.Agent({ keepAlive: true, maxSockets: 50 }),
  timeout: 15000,
});

let inflight = 0;
const MAX_INFLIGHT = parseInt(process.env.MQTT_HANDLER_MAX_INFLIGHT || '20', 10);
async function throttledPost(url, payload, maxAttempts = 3) {
  let attempt = 0;
  while (attempt < maxAttempts) {
    while (inflight >= MAX_INFLIGHT) await new Promise(r => setTimeout(r, 50));
    inflight++;
    try {
      return await axiosInstance.post(url, payload);
    } catch (err) {
      attempt++;
      if (attempt >= maxAttempts) throw err;
      const backoff = 200 * Math.pow(2, attempt);
      console.warn(`⚠️ [HTTP_RETRY] attempt ${attempt} failed for ${url} (${err.code || err.message}), retrying in ${backoff}ms`);
      await new Promise(r => setTimeout(r, backoff));
    } finally {
      inflight--;
    }
  }
}

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
      const res = await throttledPost('https://egfollow.com/api/mqtt/response', {
        order_id,
        user_id,
        status
      });

      console.log('✅ Action updated:', res.data);
    } catch (err) {
      console.error('❌ Failed to update action:', err.response?.data || err.message);
    }
});
