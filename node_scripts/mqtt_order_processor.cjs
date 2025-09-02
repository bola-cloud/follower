// node_scripts/mqtt_order_processor.cjs
const mqtt = require('mqtt');
const axios = require('axios');
const https = require('https');

const axiosInstance = axios.create({
  httpsAgent: new https.Agent({ keepAlive: true, maxSockets: 50 }),
  timeout: 15000,
});

let inflight = 0;
const MAX_INFLIGHT = parseInt(process.env.MQTT_HANDLER_MAX_INFLIGHT || '20', 10);
async function throttledPost(url, payload, maxAttempts = 3) {
  let attempt = 0;
  while (attempt < maxAttempts) {
    while (inflight >= MAX_INFLIGHT) await new Promise(r => setTimeout(r, 50));
    inflight++;
    try {
      return await axiosInstance.post(url, payload);
    } catch (err) {
      attempt++;
      if (attempt >= maxAttempts) throw err;
      const backoff = 200 * Math.pow(2, attempt);
      console.warn(`⚠️ [HTTP_RETRY] attempt ${attempt} failed for ${url} (${err.code || err.message}), retrying in ${backoff}ms`);
      await new Promise(r => setTimeout(r, backoff));
    } finally {
      inflight--;
    }
  }
}

const client = mqtt.connect('mqtt://109.199.112.65:1883');
const pendingPings = new Map(); // Track pending pings: user_id -> {order_id, timeout}
const responsiveUsers = []; // Users who responded to ping
let currentOrder = null;
let targetCount = 0;
let processedCount = 0;
let currentEligibleUsers = []; // Store current batch of eligible users for checking

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker for order processing');

  // Subscribe to order ping responses and order processing requests
  client.subscribe([
    'order/ping/res',
    'order/process/request'
  ], (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    } else {
      console.log('✅ Subscribed to order ping responses and order processing');
    }
  });
});

client.on('message', async (topic, message) => {
  try {
    const payload = JSON.parse(message.toString());

    // Handle order ping responses (users responding to order ping)
    if (topic === 'order/ping/res') {
      const { device_id, status, order_id } = payload;

      if (status === 'online' && order_id) {
        await handlePingResponse(parseInt(device_id), { status, order_id });
      }
      return;
    }

    // Handle order processing requests
    if (topic === 'order/process/request') {
      await processOrderWithPing(payload);
      return;
    }

  } catch (err) {
    console.error('❌ Failed to parse message:', err.message);
  }
});

async function processOrderWithPing(orderData) {
  const { order_id, eligible_users, total_count } = orderData;

  currentOrder = order_id;
  targetCount = total_count;
  processedCount = 0;
  responsiveUsers.length = 0; // Clear previous responses

  console.log(`🚀 Starting order ${order_id} processing with ping validation`);
  console.log(`📊 Target: ${total_count}, Available users: ${eligible_users.length}`);

  // Start pinging users in batches
  await pingUsersInBatches(eligible_users, order_id);
}

async function pingUsersInBatches(users, orderId, batchSize = 50) {
  for (let i = 0; i < users.length && processedCount < targetCount; i += batchSize) {
    const batch = users.slice(i, i + batchSize);
    currentEligibleUsers = batch; // Store current batch for eligibility checking

    console.log(`📡 Pinging batch ${Math.floor(i/batchSize) + 1}: ${batch.length} users`);

    // Set up tracking for all users in this batch
    for (const user of batch) {
      const timeout = setTimeout(() => {
        pendingPings.delete(user.id);
      }, 5000);

      pendingPings.set(user.id, { order_id: orderId, timeout });
    }

    // Send one broadcast ping using order ping topic
    const pingData = {
  type: 'create',
  order_id: orderId,
  activation: true
    };
    client.publish(`order/ping/req`, JSON.stringify(pingData), { qos: 1 });

    // Wait for responses (5 seconds timeout)
    await waitForBatchResponses(5000);

    // Process responsive users from this batch
    await processResponsiveUsers(orderId);

    // If we've reached target count, stop
    if (processedCount >= targetCount) {
      console.log(`✅ Target reached! Processed ${processedCount}/${targetCount}`);
      break;
    }

    // Small delay between batches
    await new Promise(resolve => setTimeout(resolve, 1000));
  }

  // Final summary
  console.log(`📋 Order ${orderId} complete: ${processedCount}/${targetCount} processed`);
}

async function sendPingToUser(userId, orderId) {
  const pingData = {
  type: 'create',
  order_id: orderId,
  activation: true
  };

  // Set timeout for this ping
  const timeout = setTimeout(() => {
    pendingPings.delete(userId);
  }, 5000);

  pendingPings.set(userId, { order_id: orderId, timeout });

  // Send broadcast ping check using order ping topic
  client.publish(`order/ping/req`, JSON.stringify(pingData), { qos: 1 });
}

async function handlePingResponse(userId, payload) {
  const pingData = pendingPings.get(userId);

  if (!pingData) {
    return; // Timeout or not expected
  }

  // Check if user is eligible (in current batch)
  const isEligible = currentEligibleUsers.some(user => user.id === userId);

  if (!isEligible) {
    console.log(`⚠️ User ${userId} responded but not eligible for this batch`);
    return;
  }

  // Clear timeout and remove from pending
  clearTimeout(pingData.timeout);
  pendingPings.delete(userId);

  if (payload.status === 'online' && payload.order_id === pingData.order_id) {
    responsiveUsers.push(userId);
    console.log(`✅ User ${userId} responded and is eligible - now ${responsiveUsers.length} responsive users`);
  }
}

async function waitForBatchResponses(timeout) {
  return new Promise(resolve => {
    setTimeout(resolve, timeout);
  });
}

async function processResponsiveUsers(orderId) {
  // Only process users who are both responsive and eligible
  const eligibleResponsiveUsers = responsiveUsers.filter(userId =>
    currentEligibleUsers.some(user => user.id === userId)
  );

  // Respect total count limit - only take what we need
  const remainingNeeded = targetCount - processedCount;
  const usersToProcess = eligibleResponsiveUsers.splice(0, remainingNeeded);

  console.log(`📊 Responsive: ${responsiveUsers.length}, Eligible+Responsive: ${eligibleResponsiveUsers.length + usersToProcess.length}, Processing: ${usersToProcess.length}`);

  for (const userId of usersToProcess) {
    if (processedCount >= targetCount) break;

    try {
      // Call Laravel API to dispatch job for this user (use throttledPost)
      await throttledPost('https://egfollow.com/api/mqtt/trigger-order', {
        order_id: orderId,
        user_id: userId
      });

      processedCount++;
      console.log(`🎯 Dispatched job for user ${userId} (${processedCount}/${targetCount})`);

    } catch (err) {
      console.error(`❌ Failed to dispatch job for user ${userId}:`, err.response?.data || err.message);
    }
  }

  // Remove processed users from responsive list
  responsiveUsers.splice(0, responsiveUsers.length);
}

client.on('error', (err) => {
  console.error('❌ MQTT Error:', err.message);
});

console.log('🔄 Order processor with ping validation ready...');
