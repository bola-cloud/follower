#!/bin/bash

# Critical Emergency Fixes for High MySQL Load
# ==========================================

echo "🚨 Applying CRITICAL fixes for MySQL connection issues..."

cd /home/egfollow/htdocs/egfollow.com

# 1. Fix the failed_jobs table schema immediately
echo "1️⃣ Fixing failed_jobs table schema..."
php artisan migrate --path=database/migrations/2025_09_12_170000_fix_failed_jobs_table_uuid.php --force

# 2. Clear all failed jobs that are causing issues
echo "2️⃣ Clearing problematic failed jobs..."
php artisan queue:flush

# 3. Update the supervisor configuration to reduce workers temporarily
echo "3️⃣ Temporarily reducing workers to prevent MySQL overload..."

# Create a temporary reduced config
cat > /tmp/laravel-workers-reduced.conf << 'EOF'
[program:laravel-workers-reduced]
process_name=%(program_name)s_%(process_num)02d
command=php /home/egfollow/htdocs/egfollow.com/artisan queue:work redis --queue=high,optimized-actions,actions,bulk,default --sleep=3 --tries=3 --timeout=120 --memory=256
directory=/home/egfollow/htdocs/egfollow.com
user=egfollow
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/home/egfollow/htdocs/egfollow.com/storage/logs/worker-reduced.log
stdout_logfile_maxbytes=100MB
stdout_logfile_backups=5
numprocs=10
startsecs=10
stopwaitsecs=30
priority=999
EOF

# Stop current workers
echo "4️⃣ Stopping current workers..."
sudo supervisorctl stop laravel-workers-ultra:*

# Replace with reduced config temporarily
sudo cp /tmp/laravel-workers-reduced.conf /etc/supervisor/conf.d/laravel-workers-reduced.conf

# Reload supervisor
echo "5️⃣ Starting reduced worker configuration..."
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-workers-reduced:*

# Wait for stabilization
sleep 15

echo "✅ Critical fixes applied!"
echo "📊 Current status:"
echo "===================="

# Check supervisor status
sudo supervisorctl status laravel-workers-reduced:*

# Check MySQL connections
echo ""
echo "Current MySQL connections:"
mysql -u root -p -e "SELECT COUNT(*) as active_connections FROM INFORMATION_SCHEMA.PROCESSLIST;" 2>/dev/null || echo "Could not check (need password)"

echo ""
echo "🔧 Next steps:"
echo "=============="
echo "1. Monitor MySQL connections for 10 minutes"
echo "2. If stable (<100 connections), switch back to full ultra config"
echo "3. Check logs: tail -f storage/logs/worker-reduced.log"
echo "4. Run health check: php artisan system:emergency-health-check"
