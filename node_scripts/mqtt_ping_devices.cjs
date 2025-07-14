// node_scripts/mqtt_ping_devices.cjs
const mqtt = require('mqtt');

const client = mqtt.connect('mqtt://109.199.112.65:1883');

client.on('connect', () => {
  const message = JSON.stringify({ request: 'ping' });

  client.publish('devices/activation/req', message, {}, (err) => {
    if (err) {
      console.error('❌ Failed to publish:', err.message);
      process.exit(1);
    }

    console.log('📢 MQTT ping sent to all devices');
    client.end(); // disconnect
    process.exit(0); // clean exit
  });
});

client.on('error', (err) => {
  console.error('❌ MQTT Error:', err.message);
  process.exit(1);
});
