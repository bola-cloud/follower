#!/usr/bin/env node
/* eslint-disable no-console */
/*
Persistent MQTT publisher worker (Redis -> MQTT) with client pool, retries, and graceful shutdown.
Requires: npm i mqtt ioredis
Run with pm2: pm2 start node_scripts/mqtt_publisher_worker.cjs --name mqtt-publisher --node-args="--unhandled-rejections=strict"
*/

const mqtt = require('mqtt');
const IORedis = require('ioredis');
const crypto = require('crypto');

const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379';
// Base queue key (from env). Worker will attempt BRPOP on multiple candidate keys
const REDIS_QUEUE_KEY = process.env.REDIS_QUEUE_KEY || 'mqtt:publish';
// Redis key prefix used by Laravel (if any). Defaults to 'egf:' in this project.
const REDIS_PREFIX = process.env.REDIS_PREFIX || 'egf:';
// Candidate keys to poll: raw key, single-prefixed, double-prefixed (handles accidental double-prefixing)
const REDIS_QUEUE_KEYS = [
  REDIS_QUEUE_KEY,
  `${REDIS_PREFIX}${REDIS_QUEUE_KEY}`,
  `${REDIS_PREFIX}${REDIS_PREFIX}${REDIS_QUEUE_KEY}`,
].filter((v, i, a) => a.indexOf(v) === i);
// dead letter keys will be computed from the actual popped key dynamically
const MQTT_BROKER = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const CONCURRENCY = parseInt(process.env.CONCURRENCY || '50', 10);
const MQTT_PUBLISH_TIMEOUT_MS = parseInt(process.env.MQTT_PUBLISH_TIMEOUT_MS || '5000', 10);
const METRICS_INTERVAL_MS = parseInt(process.env.METRICS_INTERVAL_MS || '10000', 10);
const CLIENT_POOL = parseInt(process.env.CLIENT_POOL || '8', 10);
const MAX_RETRIES = parseInt(process.env.MAX_RETRIES || '5', 10);

let running = true;
let publishCount = 0;
let errorCount = 0;
let loopCount = 0;

function now() { return new Date().toISOString(); }

const redis = new IORedis(REDIS_URL, { maxRetriesPerRequest: null, enableReadyCheck: true });

// ---------- MQTT POOL ----------
const mqttClients = [];
let connectedClients = 0;

function buildClient(i) {
  const c = mqtt.connect(MQTT_BROKER, {
    clean: true,
    reconnectPeriod: 1000,
    // keepalive: 30,
    // connectTimeout: 10000,
  });
  c._poolIndex = i;

  c.on('connect', () => {
    connectedClients++;
    console.log(`${now()} [mqtt-${i}] connected`);
  });

  c.on('close', () => {
    connectedClients = Math.max(0, connectedClients - 1);
    console.log(`${now()} [mqtt-${i}] closed`);
  });

  c.on('error', (err) => {
    errorCount++;
    console.error(`${now()} [mqtt-${i}] error:`, err?.message || err);
  });

  return c;
}

for (let i = 0; i < CLIENT_POOL; i++) {
  mqttClients.push(buildClient(i));
}

function pickConnectedClient() {
  if (connectedClients === 0) return null;
  const start = loopCount % mqttClients.length;
  for (let k = 0; k < mqttClients.length; k++) {
    const idx = (start + k) % mqttClients.length;
    const c = mqttClients[idx];
    if (c.connected) return c;
  }
  return null;
}

function publishWithTimeout(client, topic, payload, opts) {
  return new Promise((resolve, reject) => {
    let done = false;
    const timer = setTimeout(() => {
      if (!done) {
        done = true;
        return reject(new Error('publish timeout'));
      }
    }, MQTT_PUBLISH_TIMEOUT_MS);

    client.publish(topic, payload, opts || {}, (err) => {
      clearTimeout(timer);
      if (done) return;
      done = true;
      if (err) return reject(err);
      resolve();
    });
  });
}

// ---------- WORKER LOOP ----------
async function workerLoop(id) {
  console.log(`${now()} [worker-${id}] started`);
  if (id === 1) {
    // Log resolved runtime config once for visibility
    try {
      console.log(`${now()} [config] REDIS_URL=${REDIS_URL} REDIS_QUEUE_KEY=${REDIS_QUEUE_KEY} DEAD_LETTER_KEY=${DEAD_LETTER_KEY} MQTT_BROKER=${MQTT_BROKER} CLIENT_POOL=${CLIENT_POOL}`);
    } catch (e) {}
  }
  while (running) {
    try {
      // BRPOP across candidate keys; res[0] will be the key popped from, res[1] the value
      const res = await redis.brpop(REDIS_QUEUE_KEYS, 5); // 5s
      loopCount++;
      if (!res) continue;

      const poppedKey = res[0];
      const payloadRaw = res[1];
      if (process.env.DEBUG_MQTT_WORKER) {
        try { console.error(`${now()} [worker-${id}] BRPOP raw:`, payloadRaw); } catch(e) {}
      }
      let job;
      try {
        job = JSON.parse(payloadRaw);
        if (process.env.DEBUG_MQTT_WORKER) {
          try { console.error(`${now()} [worker-${id}] parsed job topic=${job.topic} meta=${JSON.stringify(job.meta||{})}`); } catch(e) {}
        }
      } catch (err) {
        console.error(`${now()} [worker-${id}] invalid JSON, dropping:`, err.message);
        continue; // drop malformed
      }

      const topic = job.topic;
      if (!topic) {
        console.warn(`${now()} [worker-${id}] job missing topic, dropping`);
        continue;
      }

      // Serialize payload
      let payload = job.payload;
      if (typeof payload === 'object') {
        try { payload = JSON.stringify(payload); } catch { payload = String(payload); }
      } else {
        payload = String(payload ?? '');
      }

      // Optional verbose publish tracing (non-invasive; disabled by default)
      // Set either DEBUG_MQTT_PUBLISH_VERBOSE=1 or DEBUG_MQTT_WORKER=1 to enable.
      const _debugPublishVerbose = process.env.DEBUG_MQTT_PUBLISH_VERBOSE || process.env.DEBUG_MQTT_WORKER;
      let _dedupeKey = null;
      if (_debugPublishVerbose) {
        try {
          const h = crypto.createHash('md5').update(topic + '|' + payload).digest('hex');
          _dedupeKey = `mqtt:recent_publish:${h}`;
          const sample = payload.length > 200 ? `${payload.slice(0,200)}...` : payload;
          try { console.error(`${now()} [worker-${id}] 📤 publish trace dedupe=${_dedupeKey} topic=${topic} sample=${sample} meta_retries=${meta._retries} poppedFrom=${poppedKey}`); } catch (e) {}
        } catch (e) {}
      }

      const qos = Number.isInteger(job.qos) ? job.qos : 0;
      const retain = !!job.retain;

      const meta = job.meta && typeof job.meta === 'object' ? job.meta : {};
      meta._retries = Number.isInteger(meta._retries) ? meta._retries : 0;

      // Wait up to 10s for any client
      const startWait = Date.now();
      while (connectedClients === 0 && Date.now() - startWait < 10000 && running) {
        await new Promise(r => setTimeout(r, 200));
      }
      if (connectedClients === 0) {
        // Requeue back to the same key we popped from
        await redis.lpush(poppedKey, payloadRaw); // requeue
        console.warn(`${now()} [worker-${id}] no mqtt connection, requeued to ${poppedKey}`);
        await new Promise(r => setTimeout(r, 500));
        continue;
      }

      const client = pickConnectedClient();
      if (!client) {
        // Requeue back to the same key we popped from
        await redis.lpush(poppedKey, payloadRaw);
        console.warn(`${now()} [worker-${id}] pool has no connected client, requeued to ${poppedKey}`);
        await new Promise(r => setTimeout(r, 500));
        continue;
      }

      try {
        await publishWithTimeout(client, topic, payload, { qos, retain });
        publishCount++;
        // Small success log to make publishes visible in pm2 logs
        try { console.log(`${now()} [worker-${id}] published topic=${topic}`); } catch (e) {}
        if (_debugPublishVerbose) {
          try { console.error(`${now()} [worker-${id}] 📤 published dedupe=${_dedupeKey} topic=${topic}`); } catch (e) {}
        }
      } catch (err) {
        errorCount++;
        meta._retries += 1;
        job.meta = meta;

        if (meta._retries > MAX_RETRIES) {
          const dead = JSON.stringify({ job, reason: err.message, failedAt: now() });
          // push to DLQ derived from the key we popped from
          await redis.lpush(`${poppedKey}:dead`, dead);
          console.error(`${now()} [worker-${id}] publish failed, DLQ (retries=${meta._retries}) dedupe=${_dedupeKey}:`, err.message);
        } else {
          const requeue = JSON.stringify(job);
          // requeue to the same key we popped from
          await redis.rpush(poppedKey, requeue);
          console.warn(`${now()} [worker-${id}] publish failed, requeued to ${poppedKey} (retries=${meta._retries}) dedupe=${_dedupeKey}:`, err.message);
        }
        await new Promise(r => setTimeout(r, 200));
      }
    } catch (err) {
      console.error(`${now()} [worker-${id}] loop error:`, err?.message || err);
      await new Promise(r => setTimeout(r, 1000));
    }
  }
  console.log(`${now()} [worker-${id}] stopped`);
}

// Start workers
const workers = [];
for (let i = 0; i < CONCURRENCY; i++) {
  workers.push(workerLoop(i + 1));
}

// Metrics
const metricsTimer = setInterval(async () => {
  try {
    // sum lengths across candidate keys for visibility
    const qlens = await Promise.all(REDIS_QUEUE_KEYS.map(k => redis.llen(k).catch(() => 0)));
    const qlen = qlens.reduce((a, b) => a + (Number(b) || 0), 0);
    // sum dead-letter lengths across candidate keys
    const dlens = await Promise.all(REDIS_QUEUE_KEYS.map(k => redis.llen(`${k}:dead`).catch(() => 0)));
    const dlen = dlens.reduce((a, b) => a + (Number(b) || 0), 0);
    console.log(`${now()} [metrics] qlen=${qlen} dlq=${dlen} publish=${publishCount} errors=${errorCount} loops=${loopCount} connected=${connectedClients}`);
  } catch (e) {
    console.error(`${now()} [metrics] qlen failed:`, e?.message || e);
  }
}, METRICS_INTERVAL_MS);

// Shutdown
async function shutdown() {
  if (!running) return;
  running = false;
  console.log(`${now()} [shutdown] stopping workers...`);
  clearInterval(metricsTimer);
  try { await Promise.allSettled(workers); } catch {}

  try {
    await Promise.allSettled(mqttClients.map(c => new Promise(res => c.end(true, res))));
  } catch {}

  try { await redis.quit(); } catch {}
  console.log(`${now()} [shutdown] exited`);
  process.exit(0);
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
process.on('unhandledRejection', (err) => {
  console.error(`${now()} [fatal] unhandledRejection:`, err?.stack || err);
  process.exit(1);
});

Promise.all(workers).then(shutdown).catch(shutdown);
