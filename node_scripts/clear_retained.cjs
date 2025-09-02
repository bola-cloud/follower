#!/usr/bin/env node
// Clear retained MQTT messages helper
// Usage:
//   node node_scripts/clear_retained.cjs
// Environment variables:
//   MQTT_BROKER (default mqtt://109.199.112.65:1883)
//   MQTT_USER, MQTT_PASS (optional)
//   MQTT_SUB_TOPICS (comma-separated subscribe topics, default "order/#,user/#")
//   MQTT_TIMEOUT_MS (how long to listen for retained messages, default 5000)
//   MQTT_MAX_TOPICS (max topics to clear, default 2000)

const mqtt = require('mqtt');
const { URL } = require('url');

const BROKER = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const USER = process.env.MQTT_USER || process.env.MQTT_USERNAME;
const PASS = process.env.MQTT_PASS || process.env.MQTT_PASSWORD;
const SUB_TOPICS = (process.env.MQTT_SUB_TOPICS || 'order/#,user/#').split(',').map(s => s.trim()).filter(Boolean);
const TIMEOUT_MS = parseInt(process.env.MQTT_TIMEOUT_MS || '5000', 10);
const MAX_TOPICS = parseInt(process.env.MQTT_MAX_TOPICS || '2000', 10);

console.log('MQTT clear retained helper');
console.log('Broker:', BROKER);
console.log('Subscribe topics:', SUB_TOPICS);
console.log('Listen timeout (ms):', TIMEOUT_MS);

const opts = { connectTimeout: 10_000 };
if (USER) opts.username = USER;
if (PASS) opts.password = PASS;

const client = mqtt.connect(BROKER, opts);

const discovered = new Set();
let timer;

client.on('connect', () => {
  console.log('Connected to broker');
  SUB_TOPICS.forEach(t => {
    client.subscribe(t, { qos: 0 }, (err) => {
      if (err) console.error('Subscribe error', t, err.message || err);
    });
  });

  // stop listening after TIMEOUT_MS and start clearing
  timer = setTimeout(async () => {
    await clearTopics();
  }, TIMEOUT_MS);
});

client.on('message', (topic, payload, packet) => {
  // Only record retained messages
  if (!packet.retain) return;
  if (discovered.size >= MAX_TOPICS) return;
  discovered.add(topic);
});

client.on('error', (err) => {
  console.error('MQTT error', err.message || err);
});

async function clearTopics() {
  clearTimeout(timer);

  const topics = Array.from(discovered).sort();
  console.log(`Discovered ${topics.length} retained topics (showing up to ${MAX_TOPICS})`);

  if (topics.length === 0) {
    console.log('No retained topics discovered. Exiting.');
    client.end();
    return;
  }

  // If too many topics, prompt (when run interactively) or respect MAX_TOPICS
  const toClear = topics.slice(0, MAX_TOPICS);

  for (const t of toClear) {
    await publishClear(t);
  }

  console.log('Clearing complete for', toClear.length, 'topics. Waiting 1s then exiting.');
  setTimeout(() => client.end(), 1000);
}

function publishClear(topic) {
  return new Promise((resolve) => {
    client.publish(topic, '', { retain: true, qos: 1 }, (err) => {
      if (err) console.error('Failed to clear', topic, err.message || err);
      else console.log('Cleared', topic);
      // small delay between publishes to avoid flooding the broker
      setTimeout(resolve, 20);
    });
  });
}

// allow CTRL+C to exit
process.on('SIGINT', () => {
  console.log('Interrupted, exiting.');
  client.end();
  process.exit(0);
});
