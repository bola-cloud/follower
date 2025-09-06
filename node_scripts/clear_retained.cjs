// node_scripts/clear_all_retained.cjs
const mqtt = require('mqtt');
const client = mqtt.connect('mqtt://109.199.112.65:1883');
const retainedTopics = new Set();
let timer;

client.on('connect', () => {
  console.log('🔎 Scanning for retained messages...');
  client.subscribe('#', { qos: 1 });
});

client.on('message', (topic, payload, packet) => {
  if (packet && packet.retain) {
    retainedTopics.add(topic);
    console.log('⚠️ Found retained:', topic);
  }
  clearTimeout(timer);
  timer = setTimeout(() => {
    if (retainedTopics.size === 0) {
      console.log('✅ No retained messages found.');
      client.end();
      process.exit(0);
    }

    console.log('🧹 Clearing retained messages...');
    let done = 0;
    retainedTopics.forEach(t => {
      client.publish(t, '', { qos: 1, retain: true }, () => {
        console.log(`Cleared: ${t}`);
        done++;
        if (done === retainedTopics.size) {
          console.log('✅ All retained messages cleared.');
          client.end();
          process.exit(0);
        }
      });
    });
  }, 2000); // wait ~2s after last retained received
});
