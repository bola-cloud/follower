const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

// Get Laravel-passed JSON (order + eligible user ID)
const rawInput = process.argv[2]; // Should be JSON: { user_id, order_id, type }

try {
  const data = JSON.parse(rawInput);

  client.on('connect', () => {
    console.log('✅ Connected to MQTT broker');

    // Define topic per user (e.g., orders/3)
    const topic = `orders/${data.user_id}`;

    // Send minimal event payload
    const message = JSON.stringify({
      type: 'order.created',
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
