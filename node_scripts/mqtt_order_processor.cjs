// node_scripts/mqtt_order_processor.cjs
const mqtt = require('mqtt');
const axios = require('axios');

const client = mqtt.connect('mqtt://109.199.112.65:1883');
const pendingPings = new Map(); // Track pending pings: user_id -> {order_id, timeout}
const responsiveUsers = []; // Users who responded to ping
let currentOrder = null;
let targetCount = 0;
let processedCount = 0;

client.on('connect', () => {
  console.log('✅ Connected to MQTT broker for order processing');

  // Subscribe to ping responses and order processing requests
  client.subscribe([
    'user/ping/response/+',
    'order/process/request'
  ], (err) => {
    if (err) {
      console.error('❌ Subscription error:', err.message);
    } else {
      console.log('✅ Subscribed to ping responses and order processing');
    }
  });
});

client.on('message', async (topic, message) => {
  try {
    const payload = JSON.parse(message.toString());

    // Handle ping responses
    if (topic.startsWith('user/ping/response/')) {
      const userId = parseInt(topic.split('/').pop());
      await handlePingResponse(userId, payload);
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

    console.log(`📡 Pinging batch ${Math.floor(i/batchSize) + 1}: ${batch.length} users`);

    // Send pings to batch
    for (const user of batch) {
      await sendPingToUser(user.id, orderId);
    }

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
    order_id: orderId,
    request: 'ping'
  };

  // Set timeout for this ping
  const timeout = setTimeout(() => {
    pendingPings.delete(userId);
  }, 5000);

  pendingPings.set(userId, { order_id: orderId, timeout });

  client.publish(`user/ping/${userId}`, JSON.stringify(pingData), { qos: 1 });
}

async function handlePingResponse(userId, payload) {
  const pingData = pendingPings.get(userId);

  if (!pingData) {
    return; // Timeout or not expected
  }

  // Clear timeout and remove from pending
  clearTimeout(pingData.timeout);
  pendingPings.delete(userId);

  if (payload.status === 'online' && payload.order_id === pingData.order_id) {
    responsiveUsers.push(userId);
    console.log(`✅ User ${userId} responded - now ${responsiveUsers.length} responsive users`);
  }
}

async function waitForBatchResponses(timeout) {
  return new Promise(resolve => {
    setTimeout(resolve, timeout);
  });
}

async function processResponsiveUsers(orderId) {
  const usersToProcess = responsiveUsers.splice(0, targetCount - processedCount);

  for (const userId of usersToProcess) {
    if (processedCount >= targetCount) break;

    try {
      // Call Laravel API to dispatch job for this user
      await axios.post('https://egfollow.com/api/mqtt/trigger-order', {
        order_id: orderId,
        user_id: userId
      });

      processedCount++;
      console.log(`🎯 Dispatched job for user ${userId} (${processedCount}/${targetCount})`);

    } catch (err) {
      console.error(`❌ Failed to dispatch job for user ${userId}:`, err.response?.data || err.message);
    }
  }
}

client.on('error', (err) => {
  console.error('❌ MQTT Error:', err.message);
});

console.log('🔄 Order processor with ping validation ready...');
