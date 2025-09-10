#!/bin/bash
# Maximum Performance Deployment Script for EgFollow
# Optimized for 3 vCPU / 8GB RAM server

echo "🚀 Starting MAXIMUM PERFORMANCE deployment..."

# Set strict error handling
set -euo pipefail

PROJECT_PATH="/home/egfollow/htdocs/egfollow.com"
BACKUP_DATE=$(date +%Y%m%d_%H%M%S)

# Function to log with timestamp
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

# Backup existing configurations
log "📦 Creating backups..."
sudo cp /etc/supervisor/conf.d/laravel-workers-ultra.conf /etc/supervisor/conf.d/laravel-workers-ultra.conf.bak-${BACKUP_DATE} 2>/dev/null || true
sudo cp /etc/redis/redis.conf /etc/redis/redis.conf.bak-${BACKUP_DATE} 2>/dev/null || true

# Deploy new configurations
log "⚡ Deploying MAXIMUM PERFORMANCE configs..."
sudo cp ${PROJECT_PATH}/laravel-workers-ultra.conf /etc/supervisor/conf.d/laravel-workers-ultra.conf
sudo cp ${PROJECT_PATH}/redis-performance.conf /etc/redis/redis.conf
sudo cp ${PROJECT_PATH}/.env.max-performance ${PROJECT_PATH}/.env

# Set ownership and permissions for maximum performance
log "🔐 Setting optimal ownership and permissions..."
sudo chown -R egfollow:egfollow ${PROJECT_PATH}
sudo chown egfollow:www-data ${PROJECT_PATH}/.env
sudo chmod 640 ${PROJECT_PATH}/.env
sudo chown root:root /etc/supervisor/conf.d/laravel-workers-ultra.conf
sudo chmod 644 /etc/supervisor/conf.d/laravel-workers-ultra.conf
sudo chown redis:redis /etc/redis/redis.conf 2>/dev/null || sudo chown root:root /etc/redis/redis.conf
sudo chmod 644 /etc/redis/redis.conf

# Generate APP_KEY if missing
log "🔑 Ensuring APP_KEY is present..."
cd ${PROJECT_PATH}
if ! sudo -u egfollow grep -q '^APP_KEY=base64:' .env; then
    log "🔑 Generating new APP_KEY..."
    sudo -u egfollow php artisan key:generate --force
fi

# Clear all caches for maximum performance
log "🧹 Clearing all caches..."
sudo -u egfollow php artisan config:clear
sudo -u egfollow php artisan cache:clear
sudo -u egfollow php artisan route:clear
sudo -u egfollow php artisan view:clear

# Optimize for production (maximum performance)
log "⚡ Optimizing for MAXIMUM PERFORMANCE..."
sudo -u egfollow php artisan config:cache
sudo -u egfollow php artisan route:cache
sudo -u egfollow php artisan view:cache
sudo -u egfollow composer dump-autoload --optimize --no-dev

# Stop all current workers
log "🛑 Stopping current workers..."
sudo supervisorctl stop laravel-queue-high:* || true
sudo supervisorctl stop laravel-queue-default:* || true
sudo supervisorctl stop laravel-queue-actions:* || true
sudo supervisorctl stop laravel-queue-bulk:* || true

# Restart Redis with new configuration
log "🔄 Restarting Redis with MAXIMUM PERFORMANCE config..."
sudo systemctl restart redis || sudo systemctl restart redis-server

# Update and restart Supervisor with new configuration
log "🔄 Updating Supervisor with MAXIMUM PERFORMANCE workers..."
sudo supervisorctl reread
sudo supervisorctl update

# Start all workers with maximum performance settings
log "🚀 Starting MAXIMUM PERFORMANCE workers..."
sudo supervisorctl start laravel-queue-high:*
sudo supervisorctl start laravel-queue-default:*
sudo supervisorctl start laravel-queue-actions:*
sudo supervisorctl start laravel-queue-bulk:*

# Restart PHP-FPM and web server
log "🔄 Restarting PHP-FPM and web server..."
sudo systemctl restart php8.1-fpm || sudo systemctl restart php-fpm
sudo systemctl restart nginx || sudo systemctl restart apache2

# Wait a moment for services to stabilize
sleep 5

# Verify deployment
log "✅ Verifying MAXIMUM PERFORMANCE deployment..."
echo "================================"
echo "APP_KEY Status:"
sudo -u egfollow grep -E '^APP_KEY=' ${PROJECT_PATH}/.env | head -1

echo "================================"
echo "Redis Status:"
sudo systemctl status redis --no-pager | head -5

echo "================================"
echo "Supervisor Worker Status:"
sudo supervisorctl status | grep laravel-queue

echo "================================"
echo "Worker Process Count:"
ps aux | grep "artisan queue:work" | grep -v grep | wc -l
echo "Expected: 11 workers (4+2+3+2)"

echo "================================"
echo "Redis Memory Usage:"
redis-cli info memory | grep used_memory_human || echo "Redis CLI not available"

echo "================================"
echo "Recent Laravel Logs (last 10 lines):"
tail -n 10 ${PROJECT_PATH}/storage/logs/laravel.log 2>/dev/null || echo "No recent logs"

log "🎯 MAXIMUM PERFORMANCE deployment completed!"
log "📊 Expected capacity: 3000+ actions/minute"
log "⚡ Configuration: 11 total workers, 6GB Redis, aggressive batching"

echo ""
echo "🔥 MAXIMUM PERFORMANCE MODE ACTIVATED! 🔥"
echo "Monitor with: sudo supervisorctl status"
echo "Redis stats: redis-cli info stats"
echo "Queue stats: php artisan queue:monitor"
