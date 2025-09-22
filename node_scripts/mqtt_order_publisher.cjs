const mqtt = require('mqtt');
const broker = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';

const rawInput = process.argv[2];
if (!rawInput) {
  console.error('❌ Error: pass a JSON string as argv[2]');
  process.exit(1);
}

let data;
try {
  data = JSON.parse(rawInput);
} catch (e) {
  console.error('❌ Error: invalid JSON:', e.message);
  process.exit(1);
}

const required = ['user_id', 'order_id', 'url', 'type'];
for (const k of required) {
  if (!(k in data)) {
    console.error(`❌ Error: missing field "${k}"`);
    process.exit(1);
  }
}
if (!['follow', 'like'].includes(data.type)) {
  console.error(`❌ Invalid order type: ${data.type}`);
  process.exit(1);
}

const client = mqtt.connect(broker, { clean: true, reconnectPeriod: 0 });
const topic = `orders/${data.user_id}`;
const message = JSON.stringify({ url: data.url, order_id: data.order_id, type: data.type });

let finished = false;

const connectTimer = setTimeout(() => {
  if (!finished) {
    console.error('❌ MQTT connect timeout');
    try { client.end(true); } catch {}
    process.exit(1);
  }
}, 8000);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');
  client.publish(topic, message, { retain: false, qos: 0 }, (err) => {
    finished = true;
    clearTimeout(connectTimer);
    if (err) {
      console.error('❌ Failed to publish message:', err);
      client.end(true);
      process.exit(1);
    } else {
      console.log(`✅ Published to "${topic}": ${message}`);
      client.end();
    }
  });
});

client.on('error', (err) => {
  finished = true;
  clearTimeout(connectTimer);
  console.error('❌ MQTT connection error:', err.message);
  process.exit(1);
});
