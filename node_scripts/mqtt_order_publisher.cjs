const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker, {
  clean: true,
  reconnectPeriod: 0
});

const rawInput = process.argv[2];
let finished = false;

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
    client.publish(topic, message, { retain: false, qos: 0 }, (err) => {
      finished = true;

      if (err) {
        console.error('❌ Failed to publish message:', err);
      } else {
        console.log(`✅ Published to "${topic}": ${message}`);
      }

      client.end(); // graceful close
    });
  });

  client.on('error', (err) => {
    console.error('❌ MQTT connection error:', err.message);
    process.exit(1);
  });

  // Timeout failsafe
  setTimeout(() => {
    if (!finished) {
      console.warn('⚠️ Timeout reached before confirmation, exiting...');
      client.end(true); // force close
      process.exit(1);
    }
  }, 10000); // ⬅️ Increase to 10 seconds
} catch (err) {
  console.error('❌ Error:', err.message);
  process.exit(1);
}
