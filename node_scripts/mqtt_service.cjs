#!/usr/bin/env node

const mqtt = require('mqtt');
const Redis = require('redis');

class MqttService {
    constructor() {
        this.mqttClient = null;
        this.redisClient = null;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 10;
        this.processingQueue = [];
        this.maxBatchSize = 100;
        this.batchTimeout = 1000; // 1 second
    }

    async initialize() {
        try {
            // Initialize Redis client
            this.redisClient = Redis.createClient({
                host: process.env.REDIS_HOST || '127.0.0.1',
                port: process.env.REDIS_PORT || 6379,
            });

            await this.redisClient.connect();
            console.log('✅ Redis connected');

            // Initialize MQTT client
            this.mqttClient = mqtt.connect('mqtt://109.199.112.65:1883', {
                clientId: `mqtt_service_${Date.now()}`,
                keepalive: 60,
                reconnectPeriod: 5000,
                connectTimeout: 30000,
            });

            this.setupMqttHandlers();
            this.startQueueProcessor();

        } catch (error) {
            console.error('❌ Initialization failed:', error);
            process.exit(1);
        }
    }

    setupMqttHandlers() {
        this.mqttClient.on('connect', () => {
            console.log('✅ MQTT connected to broker');
            this.isConnected = true;
            this.reconnectAttempts = 0;
        });

        this.mqttClient.on('error', (error) => {
            console.error('❌ MQTT connection error:', error);
            this.isConnected = false;
        });

        this.mqttClient.on('reconnect', () => {
            this.reconnectAttempts++;
            console.log(`🔄 MQTT reconnecting... (attempt ${this.reconnectAttempts})`);

            if (this.reconnectAttempts > this.maxReconnectAttempts) {
                console.error('❌ Max reconnection attempts reached');
                process.exit(1);
            }
        });
    }

    async startQueueProcessor() {
        console.log('🚀 Starting queue processor...');

        while (true) {
            try {
                // Check Redis queue for new jobs
                const job = await this.redisClient.brPop('mqtt_queue', 1);

                if (job) {
                    const jobData = JSON.parse(job.element);
                    await this.processJob(jobData);
                }

            } catch (error) {
                console.error('❌ Queue processing error:', error);
                await this.sleep(1000); // Wait before retrying
            }
        }
    }

    async processJob(jobData) {
        if (!this.isConnected) {
            console.warn('⚠️ MQTT not connected, requeueing job');
            await this.requeueJob(jobData);
            return;
        }

        try {
            const { user_id, url, order_id, type } = jobData;
            const topic = `user/${user_id}`;
            const payload = JSON.stringify({ url, order_id, type });

            // Publish with QoS 1 for delivery guarantee
            await new Promise((resolve, reject) => {
                this.mqttClient.publish(topic, payload, {
                    qos: 1,
                    retain: false
                }, (error) => {
                    if (error) reject(error);
                    else resolve();
                });
            });

            console.log(`📤 Published to ${topic}: order ${order_id}`);

        } catch (error) {
            console.error('❌ Job processing failed:', error);
            await this.requeueJob(jobData);
        }
    }

    async requeueJob(jobData) {
        try {
            await this.redisClient.lPush('mqtt_queue', JSON.stringify(jobData));
        } catch (error) {
            console.error('❌ Failed to requeue job:', error);
        }
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    async shutdown() {
        console.log('🛑 Shutting down MQTT service...');

        if (this.mqttClient) {
            this.mqttClient.end();
        }

        if (this.redisClient) {
            await this.redisClient.quit();
        }

        process.exit(0);
    }
}

// Handle graceful shutdown
process.on('SIGINT', async () => {
    console.log('📡 Received SIGINT, shutting down gracefully...');
    if (global.mqttService) {
        await global.mqttService.shutdown();
    }
});

process.on('SIGTERM', async () => {
    console.log('📡 Received SIGTERM, shutting down gracefully...');
    if (global.mqttService) {
        await global.mqttService.shutdown();
    }
});

// Start the service
async function main() {
    console.log('🚀 Starting MQTT Service for high-throughput processing...');
    global.mqttService = new MqttService();
    await global.mqttService.initialize();
}

main().catch(console.error);
