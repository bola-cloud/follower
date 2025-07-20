const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

const rawInput = process.argv[2]; // JSON: { user_id, order_id, type, url }

try {
  const data = JSON.parse(rawInput);

  if (!['follow', 'like'].includes(data.type)) {
    throw new Error(`Invalid order type: ${data.type}`);
  }

  const topic = `orders/${data.user_id}`;
  const message = JSON.stringify({
    url: data.url,
    order_id: data.order_id,
    type: data.type,
  });

  client.on('connect', () => {
    console.log('✅ Connected to MQTT broker');
    client.publish(topic, message, (err) => {
      if (err) {
        console.error('❌ Failed to publish message:', err);
      } else {
        console.log(`✅ Order published to "${topic}": ${message}`);
      }
      client.end();
    });
  });

  client.on('error', (err) => {
    console.error('❌ MQTT connection error:', err);
    process.exit(1);
  });
} catch (err) {
  console.error('❌ Error:', err.message);
  process.exit(1);
}
