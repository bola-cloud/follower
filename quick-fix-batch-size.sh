#!/bin/bash
# Quick Fix Script for Batch Size Issue
# This script fixes the pm2 batch size problem and optimizes drain performance

set -e  # Exit on error

echo "========================================="
echo "🚀 QUICK FIX: Batch Size Optimization"
echo "========================================="
echo ""

# Navigate to project directory
cd /home/egfollow/htdocs/egfollow.com

echo "📦 Step 1: Pull latest code..."
git pull origin new-batch-code
echo "✅ Code updated"
echo ""

echo "🔧 Step 2: Update Laravel..."
composer dump-autoload --optimize
php artisan config:clear
php artisan cache:clear
php artisan route:clear
echo "✅ Laravel caches cleared"
echo ""

echo "🛑 Step 3: Stop old pm2 process..."
pm2 delete mqtt-handler || echo "Process not found (OK)"
echo "✅ Old process removed"
echo ""

echo "🚀 Step 4: Start with new config (batch size 200)..."
pm2 start ecosystem.config.cjs --env production
echo "✅ New process started"
echo ""

echo "💾 Step 5: Save pm2 config..."
pm2 save
echo "✅ Config saved"
echo ""

echo "🔄 Step 6: Restart queue workers..."
sudo supervisorctl restart laravel-queues-ultra:*
echo "✅ Workers restarted"
echo ""

echo "========================================="
echo "✅ FIX COMPLETE!"
echo "========================================="
echo ""
echo "📊 Verify batch size:"
echo "pm2 show mqtt-handler | grep ORDER_RES"
echo ""
echo "👀 Monitor logs:"
echo "pm2 logs mqtt-handler --lines 20"
echo ""
echo "Expected: Batch sizes of 200 (not 50!)"
echo "========================================="
