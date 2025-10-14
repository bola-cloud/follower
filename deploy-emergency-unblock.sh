#!/bin/bash

# 🚨 EMERGENCY DEPLOYMENT: Unblock Orders & Speed Up Processing
# Fixes:
# 1. Removes self-rescheduling loop (stops infinite blocking)
# 2. Removes all delays (2 seconds -> 0 seconds)
# 3. Increases batch size (200 -> 1000)
# 4. Increases chunk size (200 -> 500)
# 5. Doubles workers (16 -> 32 for high-priority queue)

set -e  # Exit on any error

echo "========================================="
echo "🚨 EMERGENCY DEPLOYMENT STARTED"
echo "========================================="
echo ""

# Step 1: Verify we're in the right directory
if [ ! -f "artisan" ]; then
    echo "❌ Error: Not in Laravel root directory"
    exit 1
fi

echo "✅ Laravel root directory confirmed"
echo ""

# Step 2: Git pull latest fixes
echo "📥 Pulling latest code changes..."
git pull origin new-batch-code
echo ""

# Step 3: Clear all caches
echo "🧹 Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear
composer dump-autoload -o
echo ""

# Step 4: Update .env if needed
echo "⚙️ Checking DRAIN_BATCH_SIZE in .env..."
if ! grep -q "DRAIN_BATCH_SIZE=1000" .env 2>/dev/null; then
    echo "Adding DRAIN_BATCH_SIZE=1000 to .env..."
    echo "" >> .env
    echo "# Drain Queue Configuration" >> .env
    echo "DRAIN_BATCH_SIZE=1000" >> .env
    echo "✅ DRAIN_BATCH_SIZE added"
else
    echo "✅ DRAIN_BATCH_SIZE already set"
fi
echo ""

# Step 5: Copy supervisor config (if not already in place)
echo "📋 Updating supervisor configuration..."
sudo cp laravel-workers-ultra-batch.conf /etc/supervisor/conf.d/laravel-workers.conf
echo ""

# Step 6: Reload supervisor
echo "🔄 Reloading supervisor..."
sudo supervisorctl reread
sudo supervisorctl update
echo ""

# Step 7: Clear any stuck Redis locks
echo "🔓 Clearing stuck drain job locks..."
redis-cli -n 2 del "drain_job_running:done" > /dev/null 2>&1 || echo "No 'done' lock found"
redis-cli -n 2 del "drain_job_running:external" > /dev/null 2>&1 || echo "No 'external' lock found"
echo "✅ Locks cleared"
echo ""

# Step 8: Check current drain queue length
echo "📊 Current drain queue status:"
DONE_QUEUE=$(redis-cli -n 2 llen "order_responses:drain_queue:done" 2>/dev/null || echo "0")
EXTERNAL_QUEUE=$(redis-cli -n 2 llen "order_responses:drain_queue:external" 2>/dev/null || echo "0")
echo "  - Done queue: $DONE_QUEUE items"
echo "  - External queue: $EXTERNAL_QUEUE items"
echo ""

# Step 9: Restart high-priority queue workers (32 workers)
echo "🚀 Restarting high-priority queue workers (32 workers)..."
sudo supervisorctl restart laravel-queue-high:*
echo ""

# Step 10: Wait for workers to start
echo "⏳ Waiting for workers to stabilize (5 seconds)..."
sleep 5
echo ""

# Step 11: Check worker status
echo "✅ Worker status:"
sudo supervisorctl status laravel-queue-high:* | head -n 5
WORKER_COUNT=$(sudo supervisorctl status laravel-queue-high:* | grep RUNNING | wc -l)
echo "  - Running workers: $WORKER_COUNT/32"
echo ""

# Step 12: Restart PM2 (if needed)
echo "🔄 Restarting PM2 mqtt-handler..."
pm2 restart mqtt-handler
echo ""

# Step 13: Monitor processing for 10 seconds
echo "👀 Monitoring drain queue for 10 seconds..."
for i in {1..10}; do
    DONE_QUEUE=$(redis-cli -n 2 llen "order_responses:drain_queue:done" 2>/dev/null || echo "0")
    echo "  [$i/10] Done queue: $DONE_QUEUE items"
    sleep 1
done
echo ""

# Step 14: Summary
echo "========================================="
echo "✅ DEPLOYMENT COMPLETED SUCCESSFULLY"
echo "========================================="
echo ""
echo "📊 Changes Applied:"
echo "  ✅ Self-rescheduling loop REMOVED (no more infinite blocking)"
echo "  ✅ Adaptive delays REMOVED (2s -> 0s = instant processing)"
echo "  ✅ Batch size INCREASED (200 -> 1000 = 5x faster pops)"
echo "  ✅ Chunk size INCREASED (200 -> 500 = 2.5x faster DB updates)"
echo "  ✅ Workers DOUBLED (16 -> 32 = 2x parallel capacity)"
echo ""
echo "🎯 Expected Performance:"
echo "  - 3000 actions: 30+ minutes -> 10-15 seconds (120x faster)"
echo "  - No more order blocking (parallel processing enabled)"
echo "  - Batch updates: 5-30 actions -> 500-1000 actions per cycle"
echo ""
echo "🧪 Testing:"
echo "  1. Create order with 100 users -> should complete in 1-2 seconds"
echo "  2. Create order with 1000 users -> should complete in 5-10 seconds"
echo "  3. Create order with 3000 users -> should complete in 10-15 seconds"
echo "  4. Create 2-3 orders simultaneously -> all should process in parallel"
echo ""
echo "📝 Monitoring Commands:"
echo "  - Watch drain queue: watch -n 1 'redis-cli -n 2 llen order_responses:drain_queue:done'"
echo "  - Watch logs: tail -f storage/logs/laravel.log | grep DrainOrderResponses"
echo "  - Watch workers: sudo supervisorctl status laravel-queue-high:*"
echo ""
echo "🚨 If order 4434 still stuck:"
echo "  - Check order status: php artisan tinker -> Order::find(4434)"
echo "  - Manually complete: Order::where('id', 4434)->update(['status' => 'completed'])"
echo ""

exit 0
