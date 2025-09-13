#!/usr/bin/env node
/*
  Usage:
    node http_load_test_simulator.cjs <export_json_path> [--api=http://localhost] [--concurrency=100] [--delay=20] [--log=path]

  Example:
    node node_scripts/http_load_test_simulator.cjs storage/logs/loadtest_1757727643.json --api=http://127.0.0.1:8000 --concurrency=200 --delay=5 --log=storage/logs/loadtest-http.log

  The script reads the exported JSON produced by the PHP command, then for each user:
    1) POST /api/mqtt/trigger-order (simulate order/ping/res -> trigger-order)
    2) POST /api/mqtt/response (simulate final device result)

  Use this when you prefer direct HTTP simulation and don't want to publish to MQTT.
*/

const fs = require('fs');
const path = require('path');
const axios = require('axios');

function parseArgs() {
  const args = process.argv.slice(2);
  if (args.length === 0) {
    console.error('Missing export JSON path');
    process.exit(2);
  }
  const res = { jsonPath: args[0], api: process.env.API_BASE || 'http://127.0.0.1:8000', concurrency: 100, delay: 20, log: null };
  args.slice(1).forEach(a => {
    if (a.startsWith('--api=')) res.api = a.split('=')[1];
    if (a.startsWith('--concurrency=')) res.concurrency = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--delay=')) res.delay = parseInt(a.split('=')[1], 10);
    if (a.startsWith('--log=')) res.log = a.split('=')[1];
  });
  if (!res.log) res.log = path.join(process.cwd(), 'loadtest-http.log');
  return res;
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

  const logStream = fs.createWriteStream(opts.log, { flags: 'a' });
  logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'start', order_id: orderId, count: userIds.length }) + '\n');

  const triggerUrl = `${opts.api.replace(/\/$/, '')}/api/mqtt/trigger-order`;
  const responseUrl = `${opts.api.replace(/\/$/, '')}/api/mqtt/response`;

  // simple concurrency pool
  let inFlight = 0;
  let idx = 0;

  function sleep(ms) { return new Promise(res => setTimeout(res, ms)); }

  async function sendForUser(userId) {
    try {
      // 1) Simulate ping response -> trigger-order
      const triggerBody = { message_id: `lt-${Date.now()}-${userId}`, order_id: orderId, user_id: userId, type: 'create', activation: true };
      const tRes = await axios.post(triggerUrl, triggerBody, { timeout: 20000 });
      logStream.write(JSON.stringify({ ts: new Date().toISOString(), stage: 'trigger', user: userId, status: tRes.status }) + '\n');
    } catch (err) {
      logStream.write(JSON.stringify({ ts: new Date().toISOString(), stage: 'trigger', user: userId, error: err.response ? err.response.data : err.message }) + '\n');
    }

    // small delay before final response
    await sleep(Math.max(1, opts.delay));

    try {
      // 2) Simulate final device result -> response endpoint
      const respBody = { order_id: orderId, user_id: userId, status: 'done' };
      const rRes = await axios.post(responseUrl, respBody, { timeout: 20000 });
      logStream.write(JSON.stringify({ ts: new Date().toISOString(), stage: 'response', user: userId, status: rRes.status }) + '\n');
    } catch (err) {
      logStream.write(JSON.stringify({ ts: new Date().toISOString(), stage: 'response', user: userId, error: err.response ? err.response.data : err.message }) + '\n');
    }
  }

  function kick() {
    while (inFlight < opts.concurrency && idx < userIds.length) {
      const uid = userIds[idx++];
      inFlight++;
      sendForUser(uid).then(() => {
        inFlight--;
        if (idx % 100 === 0) console.log(`progress: ${idx}/${userIds.length}`);
        if (idx >= userIds.length && inFlight === 0) {
          logStream.write(JSON.stringify({ ts: new Date().toISOString(), event: 'done', processed: idx }) + '\n');
          logStream.end(() => { console.log('All done'); process.exit(0); });
        } else {
          kick();
        }
      });
    }
  }

  kick();
}

main().catch(err => { console.error(err); process.exit(1); });
