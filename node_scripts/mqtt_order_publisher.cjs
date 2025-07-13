const mqtt = require('mqtt');

// ✅ Use new broker IP
const brokerIP = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(brokerIP);

// Receive data from Laravel
const data = process.argv[2];

client.on('connect', () => {
    console.log('✅ Connected to MQTT broker');

    try {
        const orderData = JSON.parse(data);

        const topic = `orders/${orderData.user_id}`;
        const message = JSON.stringify({
            type: 'order.created',
            order_id: orderData.order_id,
            content_type: orderData.type,
            target: orderData.target_url
        });

        client.publish(topic, message, (err) => {
            if (err) {
                console.error('❌ Failed to publish message:', err);
            } else {
                console.log(`✅ Order published to "${topic}": ${message}`);
            }
            client.end();
        });
    } catch (e) {
        console.error('❌ JSON parse error:', e.message);
        client.end();
    }
});
