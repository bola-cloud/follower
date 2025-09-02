#!/usr/bin/env node
// Clear retained MQTT topics from a file (one topic per line)
// Usage:
//   node node_scripts/clear_retained_from_file.cjs /tmp/retained_topics.txt
// Env:
//   MQTT_BROKER (default mqtt://109.199.112.65:1883)
//   MQTT_USER, MQTT_PASS (optional)
//   MQTT_QOS (default 1)
//   MQTT_DELAY_MS (delay between publishes, default 20)

const fs = require('fs');
const mqtt = require('mqtt');

const file = process.argv[2] || process.env.RETAINED_TOPICS_FILE || '/tmp/retained_topics.txt';
if (!fs.existsSync(file)) {
  console.error('Topics file not found:', file);
  process.exit(2);
}

const BROKER = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const USER = process.env.MQTT_USER || process.env.MQTT_USERNAME;
const PASS = process.env.MQTT_PASS || process.env.MQTT_PASSWORD;
const QOS = parseInt(process.env.MQTT_QOS || '1', 10);
const DELAY = parseInt(process.env.MQTT_DELAY_MS || '20', 10);

const topics = fs.readFileSync(file, 'utf8')
  .split(/\r?\n/)
  .map(s => s.trim())
  .filter(Boolean);

if (topics.length === 0) {
  console.log('No topics to clear in', file);
  process.exit(0);
}

console.log('Clearing', topics.length, 'topics from file', file);
console.log('Broker:', BROKER);

const opts = { connectTimeout: 10_000 };
if (USER) opts.username = USER;
if (PASS) opts.password = PASS;

const client = mqtt.connect(BROKER, opts);

client.on('connect', async () => {
  console.log('Connected, starting clears...');
  for (const t of topics) {
    await publishClear(t);
  }
  console.log('Done clearing', topics.length, 'topics. Exiting.');
  setTimeout(() => client.end(), 500);
});

client.on('error', (err) => {
  console.error('MQTT error', err && err.message ? err.message : err);
});

function publishClear(topic) {
  return new Promise((resolve) => {
    client.publish(topic, '', { retain: true, qos: QOS }, (err) => {
      if (err) console.error('Failed to clear', topic, err && err.message ? err.message : err);
      else console.log('Cleared', topic);
      setTimeout(resolve, DELAY);
    });
  });
}
