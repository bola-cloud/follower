#!/bin/bash
# Production PM2 Fix Script - Resolves heavy system load and missing actions
# This script deletes and recreates the pm2 mqtt-handler process to load updated environment variables

set -e  # Exit on any error

echo "=================================================="
echo "🔧 PM2 Environment Fix Script"
echo "=================================================="
echo ""

# Change to project directory
cd /home/egfollow/htdocs/egfollow.com

echo "📍 Current directory: $(pwd)"
echo ""

# Step 1: Check current pm2 configuration
echo "=== Step 1: Current PM2 Configuration ==="
pm2 show mqtt-handler | grep -A 5 "Environment" || echo "⚠️ mqtt-handler not found or no environment vars visible"
echo ""

# Step 2: Delete old process
echo "=== Step 2: Deleting old mqtt-handler process ==="
pm2 delete mqtt-handler || echo "⚠️ mqtt-handler already deleted or not found"
echo "✅ Old process deleted"
echo ""

# Step 3: Start fresh from config
echo "=== Step 3: Starting mqtt-handler with production environment ==="
pm2 start ecosystem.config.cjs --env production --only mqtt-handler
echo "✅ mqtt-handler started"
echo ""

# Step 4: Save configuration
echo "=== Step 4: Saving PM2 configuration ==="
pm2 save
echo "✅ Configuration saved"
echo ""

# Wait a moment for process to initialize
echo "⏳ Waiting 3 seconds for process to initialize..."
sleep 3
echo ""

# Step 5: Verify environment variables
echo "=== Step 5: Verifying Environment Variables ==="
echo ""
echo "🔍 Checking ORDER_RES_BATCH_SIZE (should be 200):"
pm2 show mqtt-handler | grep ORDER_RES_BATCH_SIZE || echo "⚠️ Variable not found"
echo ""

echo "🔍 Checking ORDER_RES_BATCH_ENABLED (should be true):"
pm2 show mqtt-handler | grep ORDER_RES_BATCH_ENABLED || echo "⚠️ Variable not found"
echo ""

echo "🔍 Checking ORDER_RES_BATCH_TIMEOUT (should be 200):"
pm2 show mqtt-handler | grep ORDER_RES_BATCH_TIMEOUT || echo "⚠️ Variable not found"
echo ""

# Step 6: Show current process status
echo "=== Step 6: Current Process Status ==="
pm2 list
echo ""

# Step 7: Monitor logs for 10 seconds
echo "=== Step 7: Monitoring Logs (10 seconds) ==="
echo "Looking for batch activity and checking for DEPRECATED warnings..."
echo ""
timeout 10 pm2 logs mqtt-handler --lines 30 || true
echo ""

# Step 8: Check Redis drain queue
echo "=== Step 8: Redis Drain Queue Status ==="
echo "Done queue length:"
redis-cli -n 2 llen order_responses:drain_queue:done || echo "⚠️ Could not check Redis"
echo "External queue length:"
redis-cli -n 2 llen order_responses:drain_queue:external || echo "⚠️ Could not check Redis"
echo ""

# Step 9: Check recent Laravel logs
echo "=== Step 9: Recent Laravel Drain Activity ==="
tail -100 storage/logs/laravel.log | grep -E "MQTT_API_DRAIN|DrainOrderResponsesJob" | tail -10 || echo "ℹ️ No recent drain activity"
echo ""

# Step 10: Count deprecated warnings in recent logs
echo "=== Step 10: Checking for DEPRECATED Warnings ==="
DEPRECATED_COUNT=$(tail -1000 storage/logs/laravel.log | grep -c "DEPRECATED: Single handler" || echo "0")
echo "DEPRECATED warnings in last 1000 log lines: $DEPRECATED_COUNT"
if [ "$DEPRECATED_COUNT" -gt 50 ]; then
    echo "⚠️ WARNING: Too many deprecated warnings ($DEPRECATED_COUNT) - batching may not be working"
elif [ "$DEPRECATED_COUNT" -gt 0 ]; then
    echo "ℹ️ Some deprecated warnings found, but this is normal for a few fallback cases"
else
    echo "✅ No deprecated warnings - batching is working perfectly"
fi
echo ""

# Final summary
echo "=================================================="
echo "✅ PM2 Fix Complete!"
echo "=================================================="
echo ""
echo "📊 Next Steps:"
echo ""
echo "1. Verify ORDER_RES_BATCH_SIZE shows 200 above"
echo "   ✅ If yes: pm2 is now using correct configuration"
echo "   ❌ If no: Contact for manual debugging"
echo ""
echo "2. Test with a small order (100 devices):"
echo "   • Create test order with 100 target"
echo "   • Monitor: pm2 logs mqtt-handler --lines 50"
echo "   • Expected: 1-2 batch flushes, 0-5 DEPRECATED warnings"
echo "   • Success rate: 95-100%"
echo ""
echo "3. Monitor for batch activity:"
echo "   pm2 logs mqtt-handler | grep 'Flushing order response batch'"
echo "   tail -f storage/logs/laravel.log | grep MQTT_API_DRAIN"
echo ""
echo "4. Check system load:"
echo "   • Should be MUCH lighter than before"
echo "   • No more thousands of individual HTTP calls"
echo "   • Processing should be 5-10x faster"
echo ""
echo "=================================================="
echo ""

# Exit successfully
exit 0
