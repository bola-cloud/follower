#!/usr/bin/env node
/*
  Usage:
    node load_test_mqtt_simulator.cjs <export_json_path> [--broker=mqtt://host:1883] [--concurrency=50] [--delay=20] [--log=path]

  Example:
    node node_scripts/load_test_mqtt_simulator.cjs storage/logs/loadtest-169xxx.json --broker=mqtt://109.199.112.65:1883 --concurrency=100 --delay=5 --log=storage/logs/loadtest-mqtt.log

  The script reads the exported JSON produced by the PHP command, then publishes for each user a small sequence of MQTT messages that mimic production topics:
    - orders/{user_id}  (order announcement)
    - order/ping/req    (server ping)
    - order/ping/res    (device ping response)
    - order/res/{order_id}/{user_id}  (final device result)

  The script records every publish to the provided log file.
*/

const mqtt = require('mqtt');
const fs = require('fs');
const path = require('path');
const mysql = require('mysql2/promise');

function parseArgs() {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    console.error('Missing export JSON path');
    process.exit(2);
  }
  const res = {
    jsonPath: args[0],
    broker: process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883',
  concurrency: Number(process.env.SIM_CONCURRENCY) || 25,  // Reduced from 50 for less aggressive load
  delay: Number(process.env.SIM_DELAY_MS) || 50,        // Increased from 20ms for more breathing room
    log: null,
    fullPayload: false,
    verifyAll: false,
    statusTimeout: 30000,
    rateLimit: Number(process.env.SIM_RATE_LIMIT) || 100,   // Messages per second limit
    batchSize: Number(process.env.SIM_BATCH_SIZE) || 10,    // Process users in batches
    batchDelay: Number(process.env.SIM_BATCH_DELAY_MS) || 100   // Delay between batches in ms
  };

  args.slice(1).forEach(a => {
    if (a.startsWith('--broker=')) res.broker = a.split('=')[1];
    if (a.startsWith('--concurrency=')) res.concurrency = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--delay=')) res.delay = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--log=')) res.log = a.split('=')[1];
    if (a === '--full-payload') res.fullPayload = true;
    if (a === '--verify-all') res.verifyAll = true;
    if (a.startsWith('--status-timeout=')) res.statusTimeout = parseInt(a.split('=')[1], 10) || 30000;
    if (a.startsWith('--rate-limit=')) res.rateLimit = parseInt(a.split('=')[1], 10) || 100;
    if (a.startsWith('--batch-size=')) res.batchSize = parseInt(a.split('=')[1], 10) || 10;
    if (a.startsWith('--batch-delay=')) res.batchDelay = parseInt(a.split('=')[1], 10) || 100;
  });

  if (!res.log) res.log = path.join(process.cwd(), 'loadtest-mqtt.log');
  return res;
}

// Add rate limiting and connection throttling
class RateLimiter {
  constructor(messagesPerSecond) {
    this.messagesPerSecond = messagesPerSecond;
    this.tokens = messagesPerSecond;
    this.lastRefill = Date.now();
  }

  async waitForToken() {
    const now = Date.now();
    const elapsed = (now - this.lastRefill) / 1000;
    this.tokens = Math.min(this.messagesPerSecond, this.tokens + elapsed * this.messagesPerSecond);
    this.lastRefill = now;

    if (this.tokens >= 1) {
      this.tokens -= 1;
      return;
    }

    const waitTime = Math.ceil((1 - this.tokens) / this.messagesPerSecond * 1000);
    await new Promise(resolve => setTimeout(resolve, waitTime));
    this.tokens = 0;
  }
}

class ConnectionPool {
  constructor(maxConnections = 5) {
    this.maxConnections = maxConnections;
    this.activeConnections = 0;
    this.waitingQueue = [];
  }

  async acquire() {
    if (this.activeConnections < this.maxConnections) {
      this.activeConnections++;
      return;
    }

    return new Promise(resolve => {
      this.waitingQueue.push(resolve);
    });
  }

  release() {
    this.activeConnections--;
    if (this.waitingQueue.length > 0) {
      const next = this.waitingQueue.shift();
      this.activeConnections++;
      next();
    }
  }
}

let dbPool = null;

async function createDbPoolIfNeeded() {
  if (!process.env.DB_HOST || !process.env.DB_DATABASE) return null;
  if (dbPool) return dbPool;
  const host = process.env.DB_HOST || process.env.MYSQL_HOST || '127.0.0.1';
  const port = process.env.DB_PORT || process.env.MYSQL_PORT || '3306';
  const database = process.env.DB_DATABASE || process.env.MYSQL_DATABASE || '';
  const user = process.env.DB_USERNAME || process.env.MYSQL_USER || '';
  const pass = process.env.DB_PASSWORD || process.env.MYSQL_PASSWORD || '';
  const poolLimit = Number(process.env.MYSQL_POOL_LIMIT) || 10;
  dbPool = mysql.createPool({ host, port: Number(port), user, password: pass, database, waitForConnections: true, connectionLimit: poolLimit, queueLimit: 0 });
  return dbPool;
}

async function queryActionStatus(orderId, userId) {
  if (!process.env.DB_HOST || !process.env.DB_DATABASE) return null;
  try {
    const pool = await createDbPoolIfNeeded();
    const [rows] = await pool.execute('SELECT status FROM actions WHERE order_id = ? AND user_id = ? LIMIT 1', [Number(orderId), Number(userId)]);
    if (!rows || rows.length === 0) return null;
    const status = rows[0].status;
    return status == null ? null : String(status).trim();
  } catch (e) {
    // swallow DB errors and return null so caller can retry
    return null;
  }
}

function waitForActionDone(orderId, userId, timeoutMs = 30000) {
  return new Promise((resolve) => {
    const start = Date.now();
    let backoff = 200;

    async function tick() {
      const status = await queryActionStatus(orderId, userId);
      if (status && status.toLowerCase() === 'done') return resolve(true);
      if (Date.now() - start >= timeoutMs) return resolve(false);
      setTimeout(() => {
        backoff = Math.min(2000, Math.round(backoff * 1.4));
        tick();
      }, backoff);
    }

    tick();
  });
}

async function main() {
  const opts = parseArgs();

  if (!fs.existsSync(opts.jsonPath)) {
    console.error('Export JSON not found:', opts.jsonPath);
    process.exit(2);
  }

  const raw = fs.readFileSync(opts.jsonPath, 'utf8');
  const doc = JSON.parse(raw);

  const orderId = doc.order_id;
  const userIds = doc.user_ids || [];

  const client = mqtt.connect(opts.broker, { clean: true, reconnectPeriod: 0 });

  client.on('error', (err) => {
    console.error('MQTT error:', err.message);
    process.exit(1);
  });

  client.on('connect', async () => {
    console.log('Connected to broker', opts.broker);

    const logStream = fs.createWriteStream(opts.log, { flags: 'a' });
    logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'start', order_id: orderId, count: userIds.length }) + '\n');

    // Initialize rate limiter and connection pool
    const rateLimiter = new RateLimiter(opts.rateLimit);
    const connectionPool = new ConnectionPool(opts.concurrency);

    // Batch processing for better resource management
    let idx = 0;
    let inFlight = 0;
    let successCount = 0;
    let timeoutCount = 0;

    async function publishForUser(userId) {
      // Acquire connection slot and rate limit token
      await connectionPool.acquire();
      await rateLimiter.waitForToken();

      try {
        const pingResTopic = `order/ping/res`;
        const pingResMessage = JSON.stringify({ order_id: orderId, user_id: userId, status: 'ok', type: doc.type || 'follow' });

        const resultTopic = `order/res/${orderId}/${userId}`;
        const resultMessage = opts.fullPayload
          ? JSON.stringify({ order_id: orderId, user_id: userId, status: 'done' })
          : JSON.stringify({ status: 'done' });

        // Helper to publish and wait for callback
        function publishAsync(topic, message, optsPub) {
          return new Promise((res) => {
            client.publish(topic, message, optsPub || { qos: 1 }, (err) => {
              const out = { ts: new Date().toISOString(), user_id: userId, topic: topic, message: JSON.parse(message), err: err ? err.message : null };
              logStream.write(JSON.stringify(out) + '\n');
              res(err);
            });
          });
        }

        // Publish ping first
        await publishAsync(pingResTopic, pingResMessage, { qos: 1 });

        // Small delay before final result
        await new Promise(resolve => setTimeout(resolve, Math.max(1, opts.delay)));

        // Publish result
        await publishAsync(resultTopic, resultMessage, { qos: 1 });

        // Verify in database if configured
        if (process.env.DB_HOST && process.env.DB_DATABASE) {
          try {
            const ok = await waitForActionDone(orderId, userId, opts.statusTimeout);
            if (!ok) {
              timeoutCount++;
              logStream.write(JSON.stringify({ ts: new Date().toISOString(), warning: 'action_not_done_timeout', user_id: userId, order_id: orderId }) + '\n');
            } else {
              successCount++;
            }
          } catch (e) {
            // ignore DB polling errors
          }
        }
      } catch (e) {
        // Handle any publish errors
        console.warn(`Publish failed for user ${userId}:`, e.message);
      } finally {
        // Always release the connection slot
        connectionPool.release();
      }
    }

    // Process users in batches to avoid overwhelming the system
    for (let batchStart = 0; batchStart < userIds.length; batchStart += opts.batchSize) {
      const batch = userIds.slice(batchStart, batchStart + opts.batchSize);
      const batchPromises = batch.map(userId => publishForUser(userId));

      // Wait for current batch to complete before starting next batch
      await Promise.all(batchPromises);

      // Add delay between batches if not the last batch
      if (batchStart + opts.batchSize < userIds.length) {
        await new Promise(resolve => setTimeout(resolve, opts.batchDelay));
        console.log(`Processed batch ${Math.floor(batchStart / opts.batchSize) + 1}/${Math.ceil(userIds.length / opts.batchSize)}`);
      }
    }

    // Log completion
    logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'finished', success: successCount, timeouts: timeoutCount, total: userIds.length }) + '\n');
    logStream.end();

    console.log(`Load test completed: ${successCount} success, ${timeoutCount} timeouts out of ${userIds.length} total users`);

    // Gracefully close connections
    try {
      client.end(false, async () => {
        try {
          if (dbPool) await dbPool.end();
        } catch (e) {}
        process.exit(timeoutCount > 0 ? 1 : 0);
      });
    } catch (e) {
      client.end(true, async () => {
        try {
          if (dbPool) await dbPool.end();
        } catch (e) {}
        process.exit(timeoutCount > 0 ? 1 : 0);
      });
    }
  });
}

main().catch(err => { console.error(err); process.exit(1); });
