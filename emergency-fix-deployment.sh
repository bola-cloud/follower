#!/bin/bash

echo "🚀 Emergency Fix Deployment Script"
echo "=================================="

# Navigate to project directory
cd /home/egfollow/htdocs/egfollow.com

echo "📋 Current system status before fixes:"
echo "-------------------------------------"

# Check current MySQL connections
mysql -u root -p -e "SHOW PROCESSLIST;" | wc -l

# Check queue status
php artisan queue:status

echo ""
echo "🔧 Applying emergency fixes..."
echo "-----------------------------"

# 1. Run the failed_jobs table migration
echo "1. Fixing failed_jobs table schema..."
php artisan migrate --path=database/migrations/2025_09_12_170000_fix_failed_jobs_table_uuid.php --force

# 2. Clear failed jobs that couldn't be logged
echo "2. Clearing problematic failed jobs..."
php artisan queue:flush --queue=actions

# 3. Restart queue workers to pick up the fixed ActionQueueJob
echo "3. Restarting queue workers..."
sudo supervisorctl restart laravel-workers-ultra:*

# 4. Wait a moment for workers to stabilize
echo "4. Waiting for workers to stabilize..."
sleep 10

echo ""
echo "📊 System status after fixes:"
echo "-----------------------------"

# Check supervisor status
sudo supervisorctl status laravel-workers-ultra:*

# Check MySQL connections
echo "Current MySQL connections:"
mysql -u root -p -e "SHOW PROCESSLIST;" | wc -l

# Check queue sizes
echo "Queue status:"
php artisan queue:status

# Check for recent failures
echo "Recent failed jobs:"
php artisan queue:failed | tail -5

echo ""
echo "✅ Emergency fixes applied!"
echo "=========================="
echo "Monitor the logs with: tail -f storage/logs/laravel.log"
