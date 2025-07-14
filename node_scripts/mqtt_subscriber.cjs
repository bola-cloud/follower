const mqtt = require('mqtt');
const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

// Subscribe to all response topics
client.on('connect', () => {
  console.log('✅ Subscribed to responses');
  client.subscribe('order/res/+/+'); // Wildcard subscription
});

client.on('message', (topic, message) => {
  const payload = JSON.parse(message.toString());

  // Extract order_id and user_id from topic
  const match = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (!match) return console.warn('❌ Invalid topic format:', topic);

  const order_id = parseInt(match[1], 10);
  const user_id = parseInt(match[2], 10);

  console.log(`✅ Response received for order ${order_id} from user ${user_id}`);
  console.log('Payload:', payload);

  // TODO: process the response (e.g., update database via API or queue)
});
