#!/usr/bin/env node
const mqtt = require('mqtt');
const argv = require('yargs/yargs')(process.argv.slice(2))
  .usage('Usage: $0 --broker <broker> --order <orderId> --start <startId> --end <endId> [--clientId <id>]')
  .option('broker', { type: 'string', demandOption: true, describe: 'mqtt broker like mqtt://host:1883' })
  .option('order', { type: 'number', demandOption: true, describe: 'order id to use in topic' })
  .option('start', { type: 'number', demandOption: true, describe: 'start user id' })
  .option('end', { type: 'number', demandOption: true, describe: 'end user id' })
  .option('qos', { type: 'number', default: 0 })
  .option('interval', { type: 'number', default: 0, describe: 'ms delay between publishes (0 for flood)' })
  .option('clientId', { type: 'string', default: 'sim-done-' + Math.random().toString(16).slice(2,8) })
  .help()
  .argv;

const broker = argv.broker;
const orderId = argv.order;
const start = argv.start;
const end = argv.end;
const qos = argv.qos || 0;
const interval = argv.interval || 0;

if (end < start) {
  console.error('end must be >= start');
  process.exit(2);
}

const client = mqtt.connect(broker, { clientId: argv.clientId, reconnectPeriod: 0 });

client.on('connect', () => {
  console.log('connected to broker', broker);
  let userIds = [];
  for (let id = start; id <= end; id++) userIds.push(id);

  let i = 0;
  const total = userIds.length;
  const payload = JSON.stringify({ status: 'done' });

  function publishNext() {
    if (i >= total) {
      console.log('All messages published, exiting.');
      setTimeout(() => client.end(false, () => process.exit(0)), 200);
      return;
    }
    const uid = userIds[i++];
    const topic = `order/res/${orderId}/${uid}`;
    client.publish(topic, payload, { qos }, (err) => {
      if (err) console.error('publish error', err);
      // progress
      if (i % 100 === 0) process.stdout.write(`published ${i}/${total}\r`);
    });

    if (interval > 0) {
      setTimeout(publishNext, interval);
    } else {
      // immediate next tick to avoid stack blow
      setImmediate(publishNext);
    }
  }

  publishNext();
});

client.on('error', (e) => {
  console.error('mqtt error', e);
  process.exit(1);
});
