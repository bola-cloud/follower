const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

const rawInput = process.argv[2]; // JSON: { user_id, order_id, type }

try {
  const data = JSON.parse(rawInput);

  client.on('connect', () => {
    console.log('✅ Connected to MQTT broker');

    // ✅ New topic: orders/{user_id}
    const topic = `orders/${data.user_id}`;

    const message = JSON.stringify({
      url: data.url,
      order_id: data.order_id,
    });

    client.publish(topic, message, (err) => {
      if (err) {
        console.error('❌ Failed to publish message:', err);
      } else {
        console.log(`✅ Order published to "${topic}": ${message}`);
      }

      client.end();
    });
  });
} catch (err) {
  console.error('❌ Failed to parse input JSON:', err.message);
  process.exit(1);
}
