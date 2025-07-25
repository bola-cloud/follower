const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker, {
  clean: true,              // ensure no session persistence
  reconnectPeriod: 0        // do not reconnect on fail
});

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
    type: data.type
  });

  client.on('connect', () => {
    console.log('✅ Connected to MQTT broker');

    // Publish with retain: false and qos: 0
    client.publish(topic, message, { retain: false, qos: 0 }, (err) => {
      if (err) {
        console.error('❌ Failed to publish message:', err);
      } else {
        console.log(`✅ Published to "${topic}": ${message}`);
      }

      client.end(); // disconnect after publish
    });
  });

  client.on('error', (err) => {
    console.error('❌ MQTT connection error:', err.message);
    process.exit(1);
  });

  // Safety timeout: force exit if not finished in 5 seconds
  setTimeout(() => {
    console.warn('⚠️ Timeout reached, exiting...');
    client.end(true);
    process.exit(1);
  }, 5000);

} catch (err) {
  console.error('❌ Error:', err.message);
  process.exit(1);
}
