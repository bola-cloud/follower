#!/bin/bash

# Critical Debugging Script for Order Response Processing
# This will show exactly where messages are being lost

echo "================================================"
echo "MQTT ORDER RESPONSE DEBUGGING"
echo "================================================"
echo ""

echo "=== STEP 1: PM2 Status ==="
pm2 list | grep mqtt-handler
echo ""

echo "=== STEP 2: Check if mqtt-handler is receiving ANY messages ==="
echo "Tailing last 20 lines of PM2 logs..."
pm2 logs mqtt-handler --lines 20 --nostream | grep "MQTT RAW MESSAGE"
echo ""

echo "=== STEP 3: Check if order/res messages are being received ==="
pm2 logs mqtt-handler --lines 100 --nostream | grep "order/res"
echo ""

echo "=== STEP 4: Check Node environment variables ==="
pm2 env mqtt-handler | grep -E "ORDER_RES_BATCH|API_BASE|MQTT_BROKER"
echo ""

echo "=== STEP 5: Check Laravel drain endpoint logs ==="
tail -50 /home/egfollow/htdocs/egfollow.com/storage/logs/laravel.log | grep "MQTT_API_DRAIN"
echo ""

echo "=== STEP 6: Check Redis queue length ==="
redis-cli -n 2 llen order_responses:drain_queue:done
redis-cli -n 2 llen order_responses:drain_queue:external
echo ""

echo "=== STEP 7: Check pending actions in database ==="
mysql -u egfollow -p egfollow_db -e "SELECT order_id, status, COUNT(*) as count FROM actions WHERE order_id IN (4453, 4454) GROUP BY order_id, status ORDER BY order_id, status;" 2>/dev/null || echo "MySQL connection failed - run manually"
echo ""

echo "================================================"
echo "NEXT STEPS:"
echo "================================================"
echo "1. If NO 'MQTT RAW MESSAGE' logs → mqtt-handler is not running or crashed"
echo "   Fix: pm2 restart mqtt-handler"
echo ""
echo "2. If 'MQTT RAW MESSAGE' exists but NO 'order/res' logs → handler not subscribed"
echo "   Fix: Check subscription in mqtt_handler.cjs"
echo ""
echo "3. If 'order/res' logs exist but NO 'MQTT_API_DRAIN' → batch not flushing or HTTP error"
echo "   Check: pm2 logs mqtt-handler | grep 'Flushing order response'"
echo ""
echo "4. If 'MQTT_API_DRAIN' exists but Redis queue is 0 → Laravel not pushing to Redis"
echo "   Check Laravel logs for Redis errors"
echo ""
echo "5. If Redis queue growing but actions stay pending → DrainJob not running"
echo "   Check: tail -f laravel.log | grep DrainOrderResponsesJob"
echo ""
