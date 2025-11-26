// Persistent publisher: reads jobs from Redis and publishes via MQTT (one TCP conn)
const mqtt = require('mqtt');
const Redis = require('ioredis');

// Resolve broker, Redis URL and queue key with multiple fallback env names
const BROKER = process.env.MQTT_BROKER || process.env.MQTT_BROKER_URL || 'mqtt://109.199.112.65:1883';
const REDIS_URL = process.env.REDIS_URL || process.env.REDIS_MQTT_URL || process.env.REDIS_MQTT || 'redis://127.0.0.1:6379/2'; // use queues DB
const QUEUE_KEY = process.env.MQTT_QUEUE_KEY || process.env.MQTT_PUBLISH_QUEUE_KEY || process.env.REDIS_QUEUE_KEY || process.env.MQTT_PUBLISH_QUEUE || 'mqtt:publish';

const redis = new Redis(REDIS_URL, { lazyConnect: false, maxRetriesPerRequest: null });
const client = mqtt.connect(BROKER, { reconnectPeriod: 1000, keepalive: 30 });

client.on('connect', () => console.log('✅ MQTT publisher connected to', BROKER));
client.on('reconnect', () => console.log('🔁 MQTT reconnecting'));
client.on('error', (e) => console.error('❌ MQTT error:', e && e.message ? e.message : e));
redis.on('error', (e) => console.error('❌ Redis error:', e && e.message ? e.message : e));

// Diagnostic startup info
console.log('🔎 mqtt_redis_publisher startup config:', {
  BROKER,
  REDIS_URL,
  QUEUE_KEY
});

// Env-driven debug and wait-for-connect controls
const DEBUG = process.env.DEBUG_MQTT_PUBLISHER === '1' || process.env.DEBUG_MQTT_PUBLISHER === 'true';
const WAIT_FOR_MQTT = process.env.WAIT_FOR_MQTT === '1' || process.env.WAIT_FOR_MQTT === 'true';
const PUSH_FAILS_TO_RETRY = process.env.MQTT_PUSH_FAILS_TO_RETRY === '1' || process.env.MQTT_PUSH_FAILS_TO_RETRY === 'true';

if (DEBUG) console.log('⚙️ mqtt_redis_publisher debug enabled');

async function loop() {
  // Optionally wait for MQTT connect before consuming jobs to avoid popping jobs when publisher offline
  if (WAIT_FOR_MQTT) {
    if (client.connected) {
      if (DEBUG) console.log('MQTT already connected, starting consumer loop');
    } else {
      if (DEBUG) console.log('Waiting for MQTT connection before consuming queue...');
      await new Promise((resolve) => client.once('connect', resolve));
    }
  }

  while (true) {
    // BRPOP blocks until an item is available (no busy polling)
    const res = await redis.brpop(QUEUE_KEY, 0);
    if (!res) continue;
    const [, raw] = res;

    let job;
    try { job = JSON.parse(raw); } catch (err) {
      console.error('❌ Invalid JSON job popped from queue, skipping', err && err.message);
      continue;
    }

    const topic  = job.topic;
    const payload = typeof job.payload === 'string' ? job.payload : JSON.stringify(job.payload);
    const opts = {
      qos: Number(job.qos ?? 1),
      retain: Boolean(job.retain ?? false)
    };

    if (DEBUG) console.log('📤 popped job', { topic, opts, rawSnippet: raw.slice(0, 200) });

    // Optional verbose publish tracing (enable with DEBUG_MQTT_PUBLISH_VERBOSE=1)
    const VERBOSE = process.env.DEBUG_MQTT_PUBLISH_VERBOSE === '1' || process.env.DEBUG_MQTT_PUBLISH_VERBOSE === 'true';
    if (VERBOSE) {
      try {
        const crypto = require('crypto');
        const dedupeKey = 'mqtt:recent_publish:' + crypto.createHash('md5').update(topic + '|' + payload).digest('hex');
        console.log('📤 publish trace', { topic, dedupeKey, payloadSnippet: payload.slice(0,200) });
      } catch (e) {
        console.log('📤 publish trace error', e && e.message ? e.message : e);
      }
    }

    // publish with a callback; don't block other operations but ensure we log failures
    await new Promise((resolve) => {
      client.publish(topic, payload, opts, async (err) => {
        if (err) {
          console.error('❌ Publish failed:', topic, err && err.message ? err.message : err);
          if (PUSH_FAILS_TO_RETRY) {
            try {
              await redis.lpush(`${QUEUE_KEY}:retry`, raw);
              console.log('🔁 pushed failing job to retry list');
            } catch (e) {
              console.error('❌ Failed to push to retry list', e && e.message ? e.message : e);
            }
          }
        } else if (DEBUG) {
          console.log('✅ Published', topic);
        }
        resolve();
      });
    });
  }
}

process.on('SIGINT', () => { console.log('🛑 mqtt_redis_publisher stopping'); client.end(); process.exit(0); });
loop().catch((e) => console.error('Loop error:', e));
