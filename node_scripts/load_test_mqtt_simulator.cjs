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
// mysql2 is optional: we only require it if DB verification is enabled at runtime.
// This avoids a hard failure when mysql2 isn't installed on hosts where DB
// verification isn't needed. We will attempt to require it lazily inside
// createDbPoolIfNeeded().
let mysql = null;

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
    batchSize: Number(process.env.SIM_BATCH_SIZE) || 200,    // Process users in batches (default 200)
    batchDelay: Number(process.env.SIM_BATCH_DELAY_MS) || 200,   // Delay between batches in ms
    burstPercent: Number(process.env.SIM_BURST_PERCENT) || 30, // percent of batch that can respond as burst (0-100)
    jitterMs: Number(process.env.SIM_JITTER_MS) || 50, // random jitter added to per-user delay
    adaptive: (process.env.SIM_ADAPTIVE === '0' || process.env.SIM_ADAPTIVE === 'false') ? false : true,
    adaptiveFailureThresholdPercent: Number(process.env.SIM_ADAPTIVE_FAIL_PCT) || 10, // percent
    adaptiveMinRate: Number(process.env.SIM_ADAPTIVE_MIN_RATE) || 10
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

  // allow adjusting the rate at runtime (used by adaptive throttling)
  setRate(newRate) {
    this.messagesPerSecond = Math.max(1, Number(newRate) || 1);
    // clamp tokens to new rate
    this.tokens = Math.min(this.tokens, this.messagesPerSecond);
    this.lastRefill = Date.now();
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

  // adjust max connections at runtime
  setMaxConnections(n) {
    this.maxConnections = Math.max(1, Number(n) || 1);
  }
}

let dbPool = null;

async function createDbPoolIfNeeded() {
  // Allow explicit disabling of DB verification
  if (process.env.SIM_NO_DB_VERIFY === '1' || process.env.SIM_NO_DB_VERIFY === 'true') return null;

  if (!process.env.DB_HOST || !process.env.DB_DATABASE) return null;
  if (dbPool) return dbPool;

  // Try to require mysql2 lazily. If it's not installed, warn and skip DB verification
  if (!mysql) {
    try {
      mysql = require('mysql2/promise');
    } catch (e) {
      console.warn('mysql2 not found; DB verification disabled. Install mysql2 if you want DB polling.');
      return null;
    }
  }

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

        // Verify in database if configured; return object { published, dbOk }
        if (process.env.DB_HOST && process.env.DB_DATABASE) {
          try {
            const okDb = await waitForActionDone(orderId, userId, opts.statusTimeout);
            if (!okDb) {
              logStream.write(JSON.stringify({ ts: new Date().toISOString(), warning: 'action_not_done_timeout', user_id: userId, order_id: orderId }) + '\n');
            }
            return { published: true, dbOk: !!okDb, userId };
          } catch (e) {
            // treat DB polling error as pending (null) rather than immediate failure
            return { published: true, dbOk: null, userId };
          }
        }

        // If no DB verification configured, consider publish success as success
        return { published: true, dbOk: null, userId };
      } catch (e) {
        // Handle any publish errors
        console.warn(`Publish failed for user ${userId}:`, e.message);
        return false;
      } finally {
        // Always release the connection slot
        connectionPool.release();
      }
    }

    // Helper: shuffle array in-place
    function shuffle(array) {
      for (let i = array.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [array[i], array[j]] = [array[j], array[i]];
      }
    }

    // Run a batch with burst responders, jitter and adaptive throttling
    async function runBatch(batchIndex, batch) {
      const batchLen = batch.length;
      const burstCount = Math.max(0, Math.min(batchLen, Math.round(batchLen * opts.burstPercent / 100)));

      // decide burst indices
      const indices = Array.from({ length: batchLen }, (_, i) => i);
      shuffle(indices);
      const burstSet = new Set(indices.slice(0, burstCount));

      const promises = [];
      for (let i = 0; i < batchLen; i++) {
        const userId = batch[i];
        if (burstSet.has(i)) {
          // start immediately (burst)
          promises.push(publishForUser(userId));
        } else {
          // staggered start with jitter
          const jitter = Math.floor(Math.random() * opts.jitterMs);
          const startDelay = Math.max(0, opts.delay + jitter);
          const p = new Promise((resolve) => {
            setTimeout(async () => {
              try {
                const ok = await publishForUser(userId);
                resolve(ok);
              } catch (e) {
                resolve(false);
              }
            }, startDelay);
          });
          promises.push(p);
        }
      }

      const results = await Promise.all(promises);

      // results are objects { published:bool, dbOk: bool|null, userId }
      let publishFailures = 0;
      let dbFailures = 0;
      let dbPending = [];
      let success = 0;

      for (const r of results) {
        if (!r || !r.published) {
          publishFailures++;
        } else {
          if (r.dbOk === true) {
            success++;
          } else if (r.dbOk === false) {
            dbFailures++;
            dbPending.push(r.userId);
          } else if (r.dbOk === null) {
            // treat null as pending
            dbPending.push(r.userId);
          }
        }
      }

      // If DB verification is enabled and many are pending, do a re-check after a grace period
      if (process.env.DB_HOST && process.env.DB_DATABASE && dbPending.length > 0) {
        const recheckDelay = Number(process.env.SIM_DB_RECHECK_MS) || opts.statusTimeout;
        logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'db_recheck_scheduled', batch: batchIndex, pending: dbPending.length, delayMs: recheckDelay }) + '\n');
        await new Promise(resolve => setTimeout(resolve, recheckDelay));

        // re-check statuses for pending users
        let recheckedFailures = 0;
        for (const uid of dbPending) {
          try {
            const ok = await queryActionStatus(orderId, uid);
            if (ok && ok.toLowerCase() === 'done') {
              success++;
            } else {
              recheckedFailures++;
              logStream.write(JSON.stringify({ ts: new Date().toISOString(), warning: 'db_recheck_still_not_done', user_id: uid, order_id: orderId }) + '\n');
            }
          } catch (e) {
            recheckedFailures++;
          }
        }
        dbFailures = recheckedFailures;
      }

      const failures = publishFailures + dbFailures;

      successCount += success;
      timeoutCount += failures;

      // Adaptive throttling: only trigger if publish failures or final DB failures exceed threshold
      if (opts.adaptive) {
        const failPct = batchLen === 0 ? 0 : (failures / batchLen) * 100;
        if (failPct >= opts.adaptiveFailureThresholdPercent) {
          // back off: reduce rate and concurrency, increase batchDelay
          const currentRate = rateLimiter.messagesPerSecond || opts.rateLimit;
          const newRate = Math.max(opts.adaptiveMinRate, Math.floor(currentRate * 0.6));
          rateLimiter.setRate(newRate);

          const currentMaxCon = connectionPool.maxConnections || opts.concurrency;
          const newCon = Math.max(1, Math.floor(currentMaxCon * 0.7));
          connectionPool.setMaxConnections(newCon);

          opts.batchDelay = Math.min(60000, Math.floor(opts.batchDelay * 1.5));

          logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'adaptive_backoff', batch: batchIndex, failPct: Math.round(failPct), newRate, newCon, newBatchDelay: opts.batchDelay }) + '\n');
          console.warn(`Adaptive backoff applied after batch ${batchIndex}: failPct=${Math.round(failPct)}%, newRate=${newRate}, newCon=${newCon}, batchDelay=${opts.batchDelay}`);
          // small cooldown to let backend recover
          await new Promise(resolve => setTimeout(resolve, Math.min(10000, opts.batchDelay)));
        }
      }

      return { success, failures };
    }

    // Process users in batches to avoid overwhelming the system
    for (let batchStart = 0, batchIndex = 1; batchStart < userIds.length; batchStart += opts.batchSize, batchIndex++) {
      const batch = userIds.slice(batchStart, batchStart + opts.batchSize);
      const { success, failures } = await runBatch(batchIndex, batch);
      console.log(`Batch ${batchIndex}: processed=${batch.length}, success=${success}, failures=${failures}`);

      // Add delay between batches if not the last batch
      if (batchStart + opts.batchSize < userIds.length) {
        await new Promise(resolve => setTimeout(resolve, opts.batchDelay));
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
