// node_scripts/mqtt_handler.cjs

const mqtt = require('mqtt');
const axios = require('axios');

const broker = 'mqtt://109.199.112.65:1883';
const client = mqtt.connect(broker);

// Redis client for job queue processing
let redisClient;
let isProcessingJobs = false;

// Initialize Redis connection
async function initializeRedis() {
  try {
    redisClient = Redis.createClient({
      host: process.env.REDIS_HOST || '127.0.0.1',
      port: process.env.REDIS_PORT || 6379,
    });

    await redisClient.connect();
    console.log('✅ Redis connected for job processing');

    // Start processing publish jobs
    startJobProcessor();
  } catch (error) {
    console.error('❌ Redis connection failed:', error);
    // Continue without Redis - fallback to direct publishing
  }
}

// High-performance job processor for thousands of publishing jobs
async function startJobProcessor() {
  if (isProcessingJobs) return;
  isProcessingJobs = true;

  console.log('🚀 Starting high-throughput MQTT job processor...');

  while (isProcessingJobs) {
    try {
      // Process multiple jobs in batch for efficiency
      const jobs = [];

      // Get up to 100 jobs at once for batch processing
      for (let i = 0; i < 100; i++) {
        const job = await redisClient.brPop('mqtt_publish_queue', 0.1); // 100ms timeout
        if (job) {
          jobs.push(JSON.parse(job.element));
        } else {
          break; // No more jobs available
        }
      }

      if (jobs.length > 0) {
        await processBatchJobs(jobs);
      } else {
        // No jobs, wait a bit before checking again
        await sleep(100);
      }

    } catch (error) {
      console.error('❌ Job processor error:', error);
      await sleep(1000); // Wait before retrying
    }
  }
}

// Process jobs in batches for maximum efficiency
async function processBatchJobs(jobs) {
  const batchSize = jobs.length;
  let successCount = 0;
  let failedJobs = [];

  console.log(`📦 Processing batch of ${batchSize} MQTT publish jobs...`);

  // Process all jobs concurrently for maximum speed
  const publishPromises = jobs.map(async (jobData) => {
    try {
      const { user_id, url, order_id, type, retry_count = 0 } = jobData;
      const topic = `user/${user_id}`;
      const payload = JSON.stringify({ url, order_id, type });

      // Publish with QoS 1 for delivery guarantee
      await new Promise((resolve, reject) => {
        client.publish(topic, payload, { qos: 1, retain: false }, (error) => {
          if (error) reject(error);
          else resolve();
        });
      });

      successCount++;

    } catch (error) {
      console.error(`❌ Failed to publish job for order ${jobData.order_id}:`, error.message);

      // Retry failed jobs up to 3 times
      if ((jobData.retry_count || 0) < 3) {
        failedJobs.push({
          ...jobData,
          retry_count: (jobData.retry_count || 0) + 1
        });
      }
    }
  });

  await Promise.allSettled(publishPromises);

  // Requeue failed jobs for retry
  if (failedJobs.length > 0) {
    for (const failedJob of failedJobs) {
      await redisClient.lPush('mqtt_publish_queue', JSON.stringify(failedJob));
    }
    console.log(`🔄 Requeued ${failedJobs.length} failed jobs for retry`);
  }

  console.log(`✅ Batch complete: ${successCount}/${batchSize} jobs published successfully`);
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker');

  // Initialize Redis for job processing
  initializeRedis();

  // Subscribe to all required topics
  client.subscribe([
    'devices/activation/req',  // Listen to activation requests (dashboard)
    'devices/activation/v2/res',  // Device activation responses (dashboard)
    'order/ping/req',          // Order ping requests
    'order/ping/res',          // Order ping responses
    'order/res/+/+',
    'user/ping/+' // Add ping subscription
  ], (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    } else {
      console.log('✅ Subscribed to all required topics');
    }
  });
});

client.on('message', async (topic, message) => {
  let payload;

  try {
    payload = JSON.parse(message.toString());
  } catch (err) {
    console.error('❌ Failed to parse JSON message:', err.message);
    return;
  }

  // ✅ Handle device activation requests (for logging/monitoring)
  if (topic === 'devices/activation/req') {
    const { request, order_id } = payload;

    if (order_id) {
      console.log(`📡 Order ping broadcast sent for order ${order_id}`);
    } else {
      console.log(`📡 Regular activation check broadcast sent`);
    }
    return;
  }

  // ✅ Handle order ping requests (for logging/monitoring)
  if (topic === 'order/ping/req') {
    const { type, order_id } = payload;
    const activation = true;
    console.log(`📡 Order ping broadcast sent for order_id ${order_id} with type ${type}, activation: ${activation}`);
    // If you need to publish or forward, include activation in the payload
    // client.publish('order/ping/req', JSON.stringify({ type, order_id, activation }), { qos: 1 });
    return;
  }

  // ✅ Handle order ping responses (separate from device activation)
  if (topic === 'order/ping/res') {
    console.log('🔎 [DEBUG] Received message on order/ping/res:', message.toString());
    const { type, order_id, user_id } = payload;
    const activation = true;
    console.log('🔎 [DEBUG] Parsed payload:', { ...payload, activation });

    if (!type || !order_id || !user_id) {
      console.error('❌ Invalid response payload:', payload);
      return;
    }

    try {
      const response = await axios.post('https://egfollow.com/api/mqtt/trigger-order', {
        order_id,
        user_id,
        type,
        activation
      });

      console.log(`✅ Triggered API for type ${type}, order_id ${order_id}, user ${user_id}, activation: ${activation} | Response:`, response.data);
    } catch (err) {
      console.error(`❌ Failed to trigger API for type ${type}, order_id ${order_id}, user ${user_id}, activation: ${activation}:`, err.response?.data || err.message);
    }

    return;
  }

  // ✅ Handle device activation (dashboard only - no order_id)
  if (topic === 'devices/activation/v2/res') {
    const { device_id, status } = payload;

    if (!device_id || !status) {
      return console.warn('⚠️ Missing device_id or status:', payload);
    }

    // Regular activation - cache it for dashboard
    try {
      const res = await axios.post('https://egfollow.com/api/mqtt/device-activation', {
        device_id,
        status,
      });

      console.log(`✅ Stored activation for device ${device_id} | Status: ${status} | Count: ${res.data.count}`);
    } catch (err) {
      console.error('❌ Failed to store activation:', err.response?.data || err.message);
    }

    return;
  }

  // ✅ Handle user pings
  const pingMatch = topic.match(/^user\/ping\/(\d+)$/);
  if (pingMatch) {
    const userId = parseInt(pingMatch[1], 10);
    const { order_id, request } = payload;

    if (request === 'ping') {
      // Respond to ping (simulate user device response)
      const response = {
        order_id: order_id,
        status: 'online'
      };

      client.publish(`user/ping/response/${userId}`, JSON.stringify(response), { qos: 1 });
      console.log(`🏓 Ping response sent for user ${userId}`);
    }
    return;
  }

  // ✅ Handle order responses
  const match = topic.match(/^order\/res\/(\d+)\/(\d+)$/);
  if (match) {
    const order_id = parseInt(match[1], 10);
    const user_id = parseInt(match[2], 10);
    const { status } = payload;

    if (!status || !order_id || !user_id) {
      return console.warn('⚠️ Missing fields in order response:', payload);
    }

    try {
      const res = await axios.post('https://egfollow.com/api/mqtt/response', {
        order_id,
        user_id,
        status
      });

      console.log(`✅ Action updated for order ${order_id}, user ${user_id} | Status: ${status}`);
    } catch (err) {
      console.error('❌ Failed to update action:', err.response?.data || err.message);
    }
  } else {
    console.warn('⚠️ Unrecognized topic:', topic);
  }
});

// Graceful shutdown handling
process.on('SIGINT', async () => {
  console.log('📡 Received SIGINT, shutting down gracefully...');
  isProcessingJobs = false;

  if (redisClient) {
    await redisClient.quit();
  }

  client.end();
  process.exit(0);
});

process.on('SIGTERM', async () => {
  console.log('📡 Received SIGTERM, shutting down gracefully...');
  isProcessingJobs = false;

  if (redisClient) {
    await redisClient.quit();
  }

  client.end();
  process.exit(0);
});
