#!/bin/bash

echo "=========================================="
echo "CHECKING ORDER RESPONSE BATCH TIMER"
echo "=========================================="
echo ""

echo "1. Check if timer was started:"
pm2 logs mqtt-handler --lines 1000 --nostream | grep "ORDER RESPONSE BATCH TIMER STARTED"
echo ""

echo "2. Check if timer is triggering:"
pm2 logs mqtt-handler --lines 500 --nostream | grep "Timer triggered"
echo ""

echo "3. Check flush function calls:"
pm2 logs mqtt-handler --lines 500 --nostream | grep "FLUSH_DEBUG.*ENTRY"
echo ""

echo "4. Check batch accumulation:"
pm2 logs mqtt-handler --lines 200 --nostream | grep "order/res received" | tail -20
echo ""

echo "5. Check HTTP requests to Laravel:"
pm2 logs mqtt-handler --lines 500 --nostream | grep "Making HTTP POST\|Making axios POST"
echo ""

echo "=========================================="
echo "DIAGNOSIS:"
echo "=========================================="
echo ""
echo "If you DON'T see 'ORDER RESPONSE BATCH TIMER STARTED':"
echo "  → PM2 didn't reload mqtt_handler with latest code"
echo "  → Run: pm2 reload ecosystem.config.cjs --only mqtt-handler"
echo ""
echo "If you see 'TIMER STARTED' but NO 'Timer triggered' logs:"
echo "  → Timer is not executing (PM2 issue or JavaScript error)"
echo "  → Check: pm2 logs mqtt-handler --err"
echo ""
echo "If you see 'Timer triggered' but NO 'FLUSH_DEBUG ENTRY' logs:"
echo "  → flushOrderResponseBatch() function is not being called"
echo "  → JavaScript error or function not defined"
echo ""
echo "If you see 'FLUSH_DEBUG ENTRY' but NO 'Making HTTP POST':"
echo "  → Function exits early (empty batch check or error)"
echo "  → Check if batch.length === 0 after splice"
echo ""
