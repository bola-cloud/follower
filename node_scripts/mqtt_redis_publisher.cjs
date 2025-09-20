// Persistent publisher: reads jobs from Redis and publishes via MQTT (one TCP conn)
const mqtt = require('mqtt');
const Redis = require('ioredis');

const BROKER = process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883';
const REDIS_URL = process.env.REDIS_URL || 'redis://127.0.0.1:6379/2'; // use queues DB
const QUEUE_KEY = process.env.MQTT_QUEUE_KEY || 'mqtt:publish';

const redis = new Redis(REDIS_URL, { lazyConnect: false, maxRetriesPerRequest: null });
const client = mqtt.connect(BROKER, { reconnectPeriod: 1000, keepalive: 30 });

client.on('connect', () => console.log('✅ MQTT publisher connected to', BROKER));
client.on('reconnect', () => console.log('🔁 MQTT reconnecting'));
client.on('error', (e) => console.error('❌ MQTT error:', e && e.message ? e.message : e));
redis.on('error', (e) => console.error('❌ Redis error:', e && e.message ? e.message : e));

async function loop() {
  while (true) {
    // BRPOP blocks until an item is available (no busy polling)
    const res = await redis.brpop(QUEUE_KEY, 0);
    if (!res) continue;
    const [, raw] = res;

    let job;
    try { job = JSON.parse(raw); } catch { continue; }

    const topic  = job.topic;
    const payload = typeof job.payload === 'string' ? job.payload : JSON.stringify(job.payload);
    const opts = {
      qos: Number(job.qos ?? 1),
      retain: Boolean(job.retain ?? false)
    };

    await new Promise((resolve) => {
      client.publish(topic, payload, opts, (err) => {
        if (err) {
          console.error('❌ Publish failed:', topic, err.message);
          // Optional: push to a retry queue
          // redis.lpush(`${QUEUE_KEY}:retry`, raw).catch(()=>{});
        }
        resolve();
      });
    });
  }
}

process.on('SIGINT', () => { console.log('🛑 mqtt_redis_publisher stopping'); client.end(); process.exit(0); });
loop().catch((e) => console.error('Loop error:', e));
