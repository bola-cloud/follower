// Minimal placeholder mqtt_handler.cjs
// Backup of the original file created: mqtt_handler.cjs.bak

const mqtt = require('mqtt');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker (placeholder)');
  client.subscribe(['order/ping/res', 'order/res/+/+', 'orders/+'], (err) => {
    if (err) console.error('❌ Subscription error:', err.message);
    else console.log('✅ Subscribed to minimal topics');
  });
});

client.on('message', (topic, message) => {
  // Simply print incoming messages and don't call any external APIs
  console.log(`🔔 MQTT recv -> topic: ${topic} | payload: ${message.toString()}`);
});

process.on('SIGINT', () => {
  console.log('📡 Placeholder handler shutting down...');
  client.end();
  process.exit(0);
});

