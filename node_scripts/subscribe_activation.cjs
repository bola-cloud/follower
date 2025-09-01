const mqtt = require('mqtt');
const axios = require('axios');
const https = require('https');

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
  console.log('✅ Connected to MQTT broker and subscribing to "devices/activation/v2/res"');
  client.subscribe('devices/activation/v2/res', (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    }
  });
});

client.on('message', async (topic, message) => {
  if (topic !== 'devices/activation/v2/res') return;

  try {
    const payload = JSON.parse(message.toString());
    const { device_id, status } = payload;

    if (!device_id || !status) {
      console.warn('⚠️ Missing device_id or status:', payload);
      return;
    }

    await throttledPost('https://egfollow.com/api/mqtt/device-activation', {
      device_id,
      status,
    });

    console.log(`✅ Stored activation response for device ${device_id} | Status: ${status}`);
  } catch (err) {
    console.error('❌ Failed to process message:', err.message);
  }
});
