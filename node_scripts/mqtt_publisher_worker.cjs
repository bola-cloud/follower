#!/usr/bin/env node
/*
Persistent MQTT publisher worker
- Consumes JSON jobs from a Redis list (BRPOP) and publishes them to MQTT
- Designed to run under PM2 (or systemd) and handle high throughput by
  running a configurable number of worker loops in parallel.

Environment variables:
  REDIS_URL (optional) - example: redis://127.0.0.1:6379
  REDIS_QUEUE_KEY - Redis list key to BRPOP from (default: mqtt:publish)
  MQTT_BROKER - MQTT connection string (default: mqtt://109.199.112.65:1883)
  CONCURRENCY - number of parallel BRPOP worker loops (default: 50)
  MQTT_PUBLISH_TIMEOUT_MS - per-publish timeout (default: 5000)
  METRICS_INTERVAL_MS - periodic metrics log interval (default: 10000)

Job format expected (JSON string pushed by producers):
  { "topic": "orders/123", "payload": {...}|"string", "qos":0, "retain":false, "meta": {...} }

This file depends on the npm packages: mqtt, ioredis
Install them in project root: `npm install mqtt ioredis`
Run with pm2: `pm2 start node_scripts/mqtt_publisher_worker.cjs --name mqtt-publisher --node-args="--unhandled-rejections=strict"`
*/

const mqtt = require('mqtt');
const IORedis = require('ioredis');

const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
const REDIS_QUEUE_KEY = process.env.REDIS_QUEUE_KEY || 'mqtt:publish';
const MQTT_BROKER = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const CONCURRENCY = parseInt(process.env.CONCURRENCY || '50', 10);
const MQTT_PUBLISH_TIMEOUT_MS = parseInt(process.env.MQTT_PUBLISH_TIMEOUT_MS || '5000', 10);
const METRICS_INTERVAL_MS = parseInt(process.env.METRICS_INTERVAL_MS || '10000', 10);

let running = true;

function now() { return new Date().toISOString(); }

const redis = new IORedis(REDIS_URL, {
  // optional tuning
  maxRetriesPerRequest: null,
  enableReadyCheck: true,
});

const mqttClient = mqtt.connect(MQTT_BROKER, {
  clean: true,
  reconnectPeriod: 1000,
});

let connected = false;
let publishCount = 0;
let errorCount = 0;
let loopCount = 0;

mqttClient.on('connect', () => {
  connected = true;
  console.log(`${now()} [mqtt] connected to ${MQTT_BROKER}`);
});

mqttClient.on('reconnect', () => {
  console.log(`${now()} [mqtt] reconnecting...`);
});

mqttClient.on('close', () => {
  connected = false;
  console.log(`${now()} [mqtt] connection closed`);
});

mqttClient.on('error', (err) => {
  connected = false;
  errorCount++;
  console.error(`${now()} [mqtt] error:`, err && err.message ? err.message : err);
});

async function publishPromise(topic, message, options) {
  return new Promise((resolve, reject) => {
    let settled = false;
    const timer = setTimeout(() => {
      if (!settled) {
        settled = true;
        reject(new Error('publish timeout'));
      }
    }, MQTT_PUBLISH_TIMEOUT_MS);

    mqttClient.publish(topic, message, options || {}, (err) => {
      clearTimeout(timer);
      if (settled) return;
      settled = true;
      if (err) return reject(err);
      resolve();
    });
  });
}

async function workerLoop(id) {
  console.log(`${now()} [worker-${id}] started`);
  while (running) {
    try {
      // BRPOP blocks until an item is available or timeout expires (5s)
      const res = await redis.brpop(REDIS_QUEUE_KEY, 5);
      loopCount++;
      if (!res) continue;
      const payloadRaw = res[1];
      let job;
      try {
        job = JSON.parse(payloadRaw);
      } catch (err) {
        console.error(`${now()} [worker-${id}] invalid JSON job, dropping:`, err.message);
        continue;
      }

      const topic = job.topic;
      let payload = job.payload;
      if (typeof payload === 'object') {
        try { payload = JSON.stringify(payload); } catch (e) { payload = String(payload); }
      } else {
        payload = String(payload || '');
      }

      const qos = typeof job.qos === 'number' ? job.qos : 0;
      const retain = !!job.retain;

      if (!topic) {
        console.warn(`${now()} [worker-${id}] job missing topic, skipping`);
        continue;
      }

      // Wait until MQTT is connected or timeout
      const start = Date.now();
      while (!connected && Date.now() - start < 10000 && running) {
        await new Promise(r => setTimeout(r, 200));
      }

      if (!connected) {
        // Push job back into Redis tail for retry, to not lose it
        await redis.lpush(REDIS_QUEUE_KEY, payloadRaw);
        console.warn(`${now()} [worker-${id}] mqtt not connected, requeued job`);
        await new Promise(r => setTimeout(r, 500));
        continue;
      }

      try {
        await publishPromise(topic, payload, {qos, retain});
        publishCount++;
        // Optionally add monitoring hooks here (statsd/prometheus)
      } catch (err) {
        errorCount++;
        console.error(`${now()} [worker-${id}] publish failed:`, err.message || err);
        // push job back to Redis for retry, but avoid tight immediate retry
        await redis.lpush(REDIS_QUEUE_KEY, payloadRaw);
        await new Promise(r => setTimeout(r, 200));
      }
    } catch (err) {
      // Redis or other unexpected error - log and backoff briefly
      console.error(`${now()} [worker-${id}] loop error:`, err && err.message ? err.message : err);
      await new Promise(r => setTimeout(r, 1000));
    }
  }
  console.log(`${now()} [worker-${id}] stopped`);
}

// Start concurrency worker loops
const workers = [];
for (let i = 0; i < CONCURRENCY; i++) {
  workers.push(workerLoop(i + 1));
}

// Metrics logger
const metricsTimer = setInterval(async () => {
  try {
    const qlen = await redis.llen(REDIS_QUEUE_KEY);
    console.log(`${now()} [metrics] qlen=${qlen} publish=${publishCount} errors=${errorCount} loops=${loopCount} connected=${connected}`);
  } catch (e) {
    console.error(`${now()} [metrics] failed to get qlen:`, e && e.message ? e.message : e);
  }
}, METRICS_INTERVAL_MS);

async function shutdown() {
  if (!running) return;
  running = false;
  console.log(`${now()} [shutdown] stopping workers...`);
  clearInterval(metricsTimer);
  try { await Promise.allSettled(workers); } catch (e) {}
  try { mqttClient.end(true); } catch (e) {}
  try { await redis.quit(); } catch (e) {}
  console.log(`${now()} [shutdown] exited`);
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

// Unhandled rejections should crash so PM2 can restart with a clean state
process.on('unhandledRejection', (err) => {
  console.error(`${now()} [fatal] unhandledRejection:`, err && err.stack ? err.stack : err);
  process.exit(1);
});

// Keep node process alive by awaiting all worker promises
Promise.all(workers).then(() => shutdown()).catch(() => shutdown());
