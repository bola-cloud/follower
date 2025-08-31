#!/usr/bin/env node

const mqtt = require('mqtt');

async function publishBatch(messages) {
    const client = mqtt.connect('mqtt://109.199.112.65:1883', {
        clientId: `batch_publisher_${Date.now()}`,
        keepalive: 60,
        connectTimeout: 30000,
    });

    return new Promise((resolve, reject) => {
        let publishCount = 0;
        const totalMessages = messages.length;
        let hasError = false;

        client.on('connect', async () => {
            console.log(`📦 Publishing batch of ${totalMessages} messages...`);

            for (const message of messages) {
                if (hasError) break;

                const { user_id, url, order_id, type } = message;
                const topic = `user/${user_id}`;
                const payload = JSON.stringify({ url, order_id, type });

                client.publish(topic, payload, { qos: 1, retain: false }, (error) => {
                    if (error && !hasError) {
                        hasError = true;
                        client.end();
                        reject(new Error(`Failed to publish message for order ${order_id}: ${error.message}`));
                        return;
                    }

                    publishCount++;

                    if (publishCount === totalMessages) {
                        console.log(`✅ Successfully published ${publishCount} messages`);
                        client.end();
                        resolve();
                    }
                });
            }
        });

        client.on('error', (error) => {
            if (!hasError) {
                hasError = true;
                reject(new Error(`MQTT connection error: ${error.message}`));
            }
        });

        // Timeout after 30 seconds
        setTimeout(() => {
            if (!hasError) {
                hasError = true;
                client.end();
                reject(new Error('Batch publish timeout'));
            }
        }, 30000);
    });
}

// Parse command line arguments
async function main() {
    try {
        const messagesJson = process.argv[2];
        if (!messagesJson) {
            throw new Error('No messages provided');
        }

        const messages = JSON.parse(messagesJson);
        if (!Array.isArray(messages) || messages.length === 0) {
            throw new Error('Invalid messages format');
        }

        await publishBatch(messages);
        process.exit(0);

    } catch (error) {
        console.error('❌ Batch publish failed:', error.message);
        process.exit(1);
    }
}

main();
