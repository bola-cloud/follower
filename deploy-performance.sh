#!/bin/bash
# High-Performance Deployment Script
# Optimizes Redis, Supervisor, and Laravel for 2000+ actions/minute

echo "🚀 Deploying High-Performance MQTT Configuration..."

# 1. Backup current configurations
echo "📁 Creating backups..."
sudo cp /etc/supervisor/conf.d/laravel-workers.conf /etc/supervisor/conf.d/laravel-workers.conf.backup.$(date +%Y%m%d_%H%M%S) 2>/dev/null || echo "No existing supervisor config found"
sudo cp /etc/redis/redis.conf /etc/redis/redis.conf.backup.$(date +%Y%m%d_%H%M%S) 2>/dev/null || echo "No existing Redis config found"

# 2. Deploy optimized supervisor configuration
echo "⚙️ Deploying supervisor configuration..."
sudo cp laravel-workers-ultra.conf /etc/supervisor/conf.d/laravel-workers.conf

# 3. Deploy Redis performance configuration
echo "⚙️ Deploying Redis configuration..."
sudo cp redis-performance.conf /etc/redis/redis.conf

# 4. Restart services with proper order
echo "🔄 Restarting services..."

# Stop Laravel workers first
sudo supervisorctl stop laravel-queue-high:*
sudo supervisorctl stop laravel-queue-default:* 2>/dev/null || true
sudo supervisorctl stop laravel-queue-bulk:*
sudo supervisorctl stop laravel-queue-actions:* 2>/dev/null || true

# Restart Redis
sudo systemctl restart redis-server
sleep 2

# Update supervisor and start workers
sudo supervisorctl reread
sudo supervisorctl update
sleep 2

sudo supervisorctl start laravel-queue-high:*
sudo supervisorctl start laravel-queue-default:*
sudo supervisorctl start laravel-queue-bulk:*
sudo supervisorctl start laravel-queue-actions:*

# 5. Clear and optimize Laravel
echo "🧹 Optimizing Laravel..."
cd /home/egfollow/htdocs/egfollow.com

# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan queue:clear

# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 6. Verify deployment
echo "✅ Verifying deployment..."
sudo supervisorctl status
echo "📊 Redis info:"
redis-cli info memory | grep used_memory_human
redis-cli info stats | grep total_commands_processed

echo "🎯 Performance monitoring commands:"
echo "  - Watch queue status: watch -n 1 'php artisan queue:work --queue=actions --once --verbose'"
echo "  - Monitor Redis: redis-cli monitor"
echo "  - Check worker logs: tail -f storage/logs/queue-*.log"
echo "  - Supervisor status: sudo supervisorctl status"

echo "✅ High-performance deployment complete!"
echo "📈 Expected capacity: 2000+ actions/minute with 7 total workers (3 vCPU optimized)"
echo "💾 Memory allocation: ~6GB total (2GB workers + 4GB Redis)"
echo ""
echo "🔧 Next steps:"
echo "  1. Copy .env.performance to .env and adjust APP_* settings"
echo "  2. Run: php artisan config:cache"
echo "  3. Monitor performance with: ./monitor-performance.sh --continuous"
