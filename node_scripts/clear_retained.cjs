// clear_retained.js
const mqtt = require('mqtt');
const BROKER = 'mqtt://109.199.112.65:1883';
const PREFIX = '#'; // or 'devices/#' to limit scope

const client = mqtt.connect(BROKER);
const retainedTopics = new Set();
let idleTimer;

client.on('connect', () => {
  // Clean session so broker re-sends all retained messages immediately
  client.subscribe(PREFIX, { qos: 1 }, (err) => {
    if (err) return console.error('Subscribe error:', err.message);
  });
});

client.on('message', (topic, payload, packet) => {
  if (packet && packet.retain) {
    retainedTopics.add(topic);
  }
  // reset a small idle timer—once no more retained msgs arrive, we clear them
  clearTimeout(idleTimer);
  idleTimer = setTimeout(async () => {
    console.log('Retained topics found:', [...retainedTopics]);
    await Promise.all(
      [...retainedTopics].map(t =>
        new Promise((resolve) =>
          client.publish(t, '', { retain: true, qos: 1 }, () => resolve())
        )
      )
    );
    console.log('✅ Cleared retained messages.');
    client.end();
  }, 1500); // wait ~1.5s after the last retained message arrives
});
