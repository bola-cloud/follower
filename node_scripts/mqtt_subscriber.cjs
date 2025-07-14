const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');
  client.subscribe('order/res/+/+');
  console.log('✅ Subscribed to topic: order/res/+/+');
});

client.on('message', (topic, message) => {
  const payload = JSON.parse(message.toString());

  const match = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (!match) return console.warn('❌ Invalid topic format:', topic);

  const order_id = parseInt(match[1], 10);
  const user_id = parseInt(match[2], 10);

  console.log(`📥 Response received for order ${order_id} from user ${user_id}`);
  console.log('📦 Payload:', payload);

  // TODO: Add logic to save or process response
});
