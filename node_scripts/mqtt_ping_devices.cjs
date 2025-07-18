client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');

  const message = JSON.stringify({ request: 'ping' });
  client.publish('devices/activation/req', message, {}, (err) => {
    if (err) {
      console.error('❌ Publish failed:', err.message);
    } else {
      console.log('📢 Message published to topic');
    }
    client.end();
    process.exit(0);
  });
});

client.on('error', (err) => {
  console.error('❌ MQTT Error:', err.message);
});
