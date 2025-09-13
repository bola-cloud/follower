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
const { exec } = require('child_process');

function parseArgs() {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    console.error('Missing export JSON path');
    process.exit(2);
  }
  const res = { jsonPath: args[0], broker: process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883', concurrency: 50, delay: 20, log: null };
  args.slice(1).forEach(a => {
    if (a.startsWith('--broker=')) res.broker = a.split('=')[1];
    if (a.startsWith('--concurrency=')) res.concurrency = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--delay=')) res.delay = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--log=')) res.log = a.split('=')[1];
  });
  if (!res.log) res.log = path.join(process.cwd(), 'loadtest-mqtt.log');
  return res;
}

function shellEscape(s) {
  if (s == null) return '';
  return String(s).replace(/'/g, "'\\''");
}

function queryActionStatusViaMysql(orderId, userId, cb) {
  const host = process.env.DB_HOST || process.env.MYSQL_HOST || '127.0.0.1';
  const port = process.env.DB_PORT || process.env.MYSQL_PORT || '3306';
  const database = process.env.DB_DATABASE || process.env.MYSQL_DATABASE || '';
  const user = process.env.DB_USERNAME || process.env.MYSQL_USER || '';
  const pass = process.env.DB_PASSWORD || process.env.MYSQL_PASSWORD || '';

  // Build mysql CLI command
  let auth = '';
  if (user) auth += ` -u'${shellEscape(user)}'`;
  if (pass) auth += ` -p'${shellEscape(pass)}'`;

  const dbPart = database ? ` -D '${shellEscape(database)}'` : '';
  const sql = `SELECT status FROM actions WHERE order_id=${Number(orderId)} AND user_id=${Number(userId)} LIMIT 1;`;
  const cmd = `mysql -h '${shellEscape(host)}' -P ${Number(port)}${auth}${dbPart} -N -s -e '${shellEscape(sql)}'`;

  exec(cmd, { timeout: 5000 }, (err, stdout, stderr) => {
    if (err) return cb(err, null);
    const out = stdout ? stdout.toString().trim() : '';
    return cb(null, out || null);
  });
}

function waitForActionDone(orderId, userId, timeoutMs = 30000) {
  return new Promise((resolve) => {
    const start = Date.now();
    let backoff = 200;

    function tick() {
      queryActionStatusViaMysql(orderId, userId, (err, status) => {
        if (!err && status && status.toLowerCase() === 'done') return resolve(true);
        if (Date.now() - start >= timeoutMs) return resolve(false);
        setTimeout(() => {
          backoff = Math.min(2000, Math.round(backoff * 1.4));
          tick();
        }, backoff);
      });
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

    // Simple throttled publisher: run user jobs in parallel with concurrency
    let idx = 0;
    let inFlight = 0;

    function publishForUser(userId) {
      // Return a promise that resolves only after both publishes receive broker acknowledgement
      return new Promise((resolve) => {
        const pingResTopic = `order/ping/res`;
        const pingResMessage = JSON.stringify({ order_id: orderId, user_id: userId, status: 'ok', type: doc.type || 'follow' });

        const resultTopic = `order/res/${orderId}/${userId}`;
        const resultMessage = JSON.stringify({ order_id: orderId, user_id: userId, status: 'done' });

        // helper to publish and wait for callback
        function publishAsync(topic, message, optsPub) {
          return new Promise((res) => {
            client.publish(topic, message, optsPub || { qos: 1 }, (err) => {
              const out = { ts: new Date().toISOString(), user_id: userId, topic: topic, message: JSON.parse(message), err: err ? err.message : null };
              logStream.write(JSON.stringify(out) + '\n');
              // resolve regardless of err; caller can inspect the log for details
              res(err ? false : true);
            });
          });
        }

        // publish ping, then delay, then publish result, waiting for both acknowledgements
        publishAsync(pingResTopic, pingResMessage, { qos: 1 })
          .then(() => {
            // small delay before final result
            setTimeout(async () => {
              try {
                await publishAsync(resultTopic, resultMessage, { qos: 1 });

                // Call waitForActionDone after publishing result for each user
                if (process.env.DB_HOST && process.env.DB_DATABASE) {
                  try {
                    const ok = await waitForActionDone(orderId, userId, 30000);
                    if (!ok) {
                      logStream.write(JSON.stringify({ ts: new Date().toISOString(), warning: 'action_not_done_timeout', user_id: userId, order_id: orderId }) + '\n');
                    }
                  } catch (e) {
                    // ignore DB polling errors
                  }
                }
              } catch (e) {
                // publish failed, continue
              }
              resolve();
            }, Math.max(1, opts.delay));
          })
          .catch(() => {
            // even if ping publish failed, attempt result publish
            setTimeout(async () => {
              try {
                await publishAsync(resultTopic, resultMessage, { qos: 1 });
                if (process.env.DB_HOST && process.env.DB_DATABASE) {
                  try {
                    const ok = await waitForActionDone(orderId, userId, 30000);
                    if (!ok) {
                      logStream.write(JSON.stringify({ ts: new Date().toISOString(), warning: 'action_not_done_timeout', user_id: userId, order_id: orderId }) + '\n');
                    }
                  } catch (e) {}
                }
              } catch (e) {
                // ignore
              }
              resolve();
            }, Math.max(1, opts.delay));
          });
      });
    }

    // worker loop
    function kick() {
      while (inFlight < opts.concurrency && idx < userIds.length) {
        const uid = userIds[idx++];
        inFlight++;
        publishForUser(uid).then(() => {
          inFlight--;
          if (idx % 100 === 0) console.log(`progress: ${idx}/${userIds.length}`);
          if (idx >= userIds.length && inFlight === 0) {
            logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'done', processed: idx }) + '\n');
            logStream.end(() => {
              console.log('All published, exiting');
              // Gracefully close the client so in-flight acks can finish
              try {
                client.end(false, () => process.exit(0));
              } catch (e) {
                // fallback to force close if graceful end fails
                client.end(true, () => process.exit(0));
              }
            });
          } else {
            // kick more
            kick();
          }
        });
      }
    }

    kick();
  });
}

main().catch(err => { console.error(err); process.exit(1); });
