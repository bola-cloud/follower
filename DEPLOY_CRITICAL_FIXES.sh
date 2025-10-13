#!/bin/bash
# CRITICAL DEPLOYMENT: Fix heavy system load and missing actions
# This script applies all necessary fixes for the batch processing system

set -e  # Exit on any error

echo "=========================================================="
echo "🚨 CRITICAL DEPLOYMENT: System Performance Fix"
echo "=========================================================="
echo ""
echo "This script will:"
echo "  1. Pull latest code changes from git"
echo "  2. Update composer dependencies"
echo "  3. Clear all Laravel caches"
echo "  4. Restart supervisor queue workers"
echo "  5. Delete and recreate pm2 mqtt-handler with correct config"
echo "  6. Verify all systems are working"
echo ""
read -p "Press ENTER to continue or Ctrl+C to cancel..."
echo ""

# Change to project directory
cd /home/egfollow/htdocs/egfollow.com
echo "📍 Working directory: $(pwd)"
echo ""

# Step 1: Git pull
echo "=== Step 1: Pulling latest code from git ==="
git fetch origin
git pull origin new-batch-code
echo "✅ Code updated"
echo ""

# Step 2: Composer update
echo "=== Step 2: Updating Composer dependencies ==="
composer dump-autoload -o
echo "✅ Composer autoload optimized"
echo ""

# Step 3: Clear Laravel caches
echo "=== Step 3: Clearing Laravel caches ==="
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
echo "✅ All caches cleared"
echo ""

# Step 4: Restart supervisor workers
echo "=== Step 4: Restarting Supervisor queue workers ==="
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queue-high:*
sudo supervisorctl restart laravel-queue-optimized-actions:*
sudo supervisorctl restart laravel-queue-actions:*
sudo supervisorctl status | grep laravel-queue
echo "✅ Supervisor workers restarted"
echo ""

# Step 5: Fix PM2 environment
echo "=== Step 5: Fixing PM2 mqtt-handler environment ==="
echo ""
echo "Current pm2 environment:"
pm2 show mqtt-handler | grep -E "ORDER_RES|BATCH" || echo "⚠️ Variables not found"
echo ""

echo "Deleting old mqtt-handler process..."
pm2 delete mqtt-handler || echo "⚠️ Process already deleted"

echo "Starting mqtt-handler with production config..."
pm2 start ecosystem.config.cjs --env production --only mqtt-handler

echo "Saving PM2 configuration..."
pm2 save

echo ""
echo "⏳ Waiting 3 seconds for initialization..."
sleep 3
echo ""

echo "New pm2 environment:"
pm2 show mqtt-handler | grep -E "ORDER_RES|BATCH" || echo "⚠️ Variables not found"
echo "✅ PM2 mqtt-handler recreated"
echo ""

# Step 6: Verify Redis connectivity
echo "=== Step 6: Verifying Redis connectivity ==="
redis-cli -n 2 ping && echo "✅ Redis DB 2 responding" || echo "❌ Redis DB 2 not responding"
echo ""

# Step 7: Check Redis drain queues
echo "=== Step 7: Checking Redis drain queues ==="
DONE_QUEUE_LEN=$(redis-cli -n 2 llen order_responses:drain_queue:done)
EXTERNAL_QUEUE_LEN=$(redis-cli -n 2 llen order_responses:drain_queue:external)
echo "Done queue length: $DONE_QUEUE_LEN"
echo "External queue length: $EXTERNAL_QUEUE_LEN"
TOTAL_QUEUED=$((DONE_QUEUE_LEN + EXTERNAL_QUEUE_LEN))
echo "Total queued: $TOTAL_QUEUED"
if [ "$TOTAL_QUEUED" -gt 1000 ]; then
    echo "⚠️ WARNING: Large backlog ($TOTAL_QUEUED items) - may take time to process"
elif [ "$TOTAL_QUEUED" -gt 0 ]; then
    echo "ℹ️ Backlog present ($TOTAL_QUEUED items) - being processed"
else
    echo "✅ No backlog"
fi
echo ""

# Step 8: Monitor logs for 15 seconds
echo "=== Step 8: Monitoring logs for batch activity ==="
echo "Watching for 15 seconds..."
echo ""
timeout 15 pm2 logs mqtt-handler --lines 30 || true
echo ""

# Step 9: Check for DEPRECATED warnings
echo "=== Step 9: Checking for DEPRECATED warnings ==="
DEPRECATED_COUNT=$(tail -1000 storage/logs/laravel.log | grep -c "DEPRECATED: Single handler" || echo "0")
echo "DEPRECATED warnings in last 1000 log lines: $DEPRECATED_COUNT"
if [ "$DEPRECATED_COUNT" -gt 100 ]; then
    echo "❌ CRITICAL: Too many deprecated warnings ($DEPRECATED_COUNT)"
    echo "   This means batching is NOT working properly!"
    echo "   Check pm2 environment variables above."
elif [ "$DEPRECATED_COUNT" -gt 10 ]; then
    echo "⚠️ WARNING: Some deprecated warnings ($DEPRECATED_COUNT)"
    echo "   Monitor to ensure this doesn't increase."
elif [ "$DEPRECATED_COUNT" -gt 0 ]; then
    echo "ℹ️ Few deprecated warnings ($DEPRECATED_COUNT) - acceptable for fallback cases"
else
    echo "✅ No deprecated warnings - batching working perfectly!"
fi
echo ""

# Step 10: Check for recent drain activity
echo "=== Step 10: Checking recent drain activity ==="
DRAIN_LOG_COUNT=$(tail -500 storage/logs/laravel.log | grep -c "MQTT_API_DRAIN" || echo "0")
echo "Drain log entries in last 500 lines: $DRAIN_LOG_COUNT"
if [ "$DRAIN_LOG_COUNT" -gt 0 ]; then
    echo "✅ Drain system is active"
    echo ""
    echo "Recent drain activity:"
    tail -500 storage/logs/laravel.log | grep "MQTT_API_DRAIN" | tail -5
else
    echo "ℹ️ No recent drain activity (normal if no traffic)"
fi
echo ""

# Step 11: Verify batch size configuration
echo "=== Step 11: Verifying batch size configuration ==="
BATCH_SIZE=$(pm2 show mqtt-handler | grep ORDER_RES_BATCH_SIZE | awk -F: '{print $2}' | tr -d ' ')
echo "ORDER_RES_BATCH_SIZE: $BATCH_SIZE"
if [ "$BATCH_SIZE" = "200" ]; then
    echo "✅ Batch size correctly set to 200"
elif [ "$BATCH_SIZE" = "50" ] || [ "$BATCH_SIZE" = "100" ]; then
    echo "❌ ERROR: Batch size is still $BATCH_SIZE (should be 200)"
    echo "   PM2 did not load the new configuration!"
    echo "   Try manually: pm2 delete mqtt-handler && pm2 start ecosystem.config.cjs --env production --only mqtt-handler"
else
    echo "⚠️ Could not determine batch size from pm2 show output"
fi
echo ""

# Final summary
echo "=========================================================="
echo "✅ DEPLOYMENT COMPLETE!"
echo "=========================================================="
echo ""
echo "📊 System Status Summary:"
echo ""
echo "✓ Code: Updated from git"
echo "✓ Composer: Autoload optimized"
echo "✓ Laravel: All caches cleared"
echo "✓ Supervisor: Queue workers restarted"
echo "✓ PM2: mqtt-handler recreated with new config"
echo "✓ Redis: Connectivity verified"
echo ""

if [ "$BATCH_SIZE" = "200" ] && [ "$DEPRECATED_COUNT" -lt 50 ]; then
    echo "🎉 SUCCESS: System is properly configured!"
    echo ""
    echo "Next steps:"
    echo "  1. Test with 100 devices (expected: 95-100% success, 3-5 seconds)"
    echo "  2. Test with 1000 devices (expected: 98-100% success, 10-15 seconds)"
    echo "  3. Test with 3000 devices (expected: 98-100% success, 20-30 seconds)"
    echo ""
    echo "Monitor commands:"
    echo "  • pm2 logs mqtt-handler --lines 50"
    echo "  • tail -f storage/logs/laravel.log | grep DRAIN"
    echo "  • redis-cli -n 2 llen order_responses:drain_queue:done"
else
    echo "⚠️ WARNING: System may not be fully fixed"
    echo ""
    if [ "$BATCH_SIZE" != "200" ]; then
        echo "ISSUE: Batch size is $BATCH_SIZE (should be 200)"
        echo "FIX: Manually restart pm2 mqtt-handler"
    fi
    if [ "$DEPRECATED_COUNT" -gt 50 ]; then
        echo "ISSUE: Too many DEPRECATED warnings ($DEPRECATED_COUNT)"
        echo "FIX: Check pm2 logs for errors"
    fi
    echo ""
    echo "See CRITICAL_FIX_HEAVY_SYSTEM.md for detailed troubleshooting"
fi

echo ""
echo "=========================================================="
echo ""

exit 0
