#!/bin/bash
# Emergency fix for Redis queue error
# Run this script on production server: bash emergency-redis-fix.sh

set -e

echo "============================================"
echo "Emergency Redis Queue Fix"
echo "============================================"
echo ""

# Check current directory
if [ ! -f "artisan" ]; then
    echo "❌ ERROR: Must run from Laravel root directory"
    echo "   cd /home/egfollow/htdocs/egfollow.com"
    exit 1
fi

echo "✅ Running from Laravel root directory"
echo ""

# Backup config
echo "[1/7] Backing up config/database.php..."
cp config/database.php config/database.php.backup.$(date +%Y%m%d%H%M%S)
echo "✅ Backup created"
echo ""

# Check which Redis client is available
echo "[2/7] Checking Redis client availability..."
HAS_PHPREDIS=$(php -m | grep -c "redis" || true)
HAS_PREDIS=$(composer show 2>/dev/null | grep -c "predis/predis" || true)

echo "   phpredis extension: $HAS_PHPREDIS"
echo "   predis package: $HAS_PREDIS"
echo ""

if [ "$HAS_PHPREDIS" -gt 0 ]; then
    echo "   ✅ Using phpredis (faster)"
    REDIS_CLIENT="phpredis"
elif [ "$HAS_PREDIS" -gt 0 ]; then
    echo "   ✅ Using predis (compatible)"
    REDIS_CLIENT="predis"
else
    echo "   ⚠️  Neither found - installing predis..."
    composer require predis/predis --no-interaction
    REDIS_CLIENT="predis"
fi
echo ""

# Update config/database.php
echo "[3/7] Updating config/database.php to use $REDIS_CLIENT..."
sed -i "s/'client' => env('REDIS_CLIENT', 'phpredis')/'client' => env('REDIS_CLIENT', '$REDIS_CLIENT')/" config/database.php
sed -i "s/'client' => env('REDIS_CLIENT', 'predis')/'client' => env('REDIS_CLIENT', '$REDIS_CLIENT')/" config/database.php
echo "✅ Config updated"
echo ""

# Update .env if not already set
echo "[4/7] Ensuring REDIS_CLIENT in .env..."
if grep -q "^REDIS_CLIENT=" .env; then
    sed -i "s/^REDIS_CLIENT=.*/REDIS_CLIENT=$REDIS_CLIENT/" .env
else
    echo "REDIS_CLIENT=$REDIS_CLIENT" >> .env
fi
echo "✅ .env updated"
echo ""

# Clear caches
echo "[5/7] Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear
php artisan queue:restart
echo "✅ Caches cleared"
echo ""

# Restart queue workers
echo "[6/7] Restarting queue workers..."
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl restart laravel-queues-ultra:* || true
    echo "✅ Queue workers restarted via supervisor"
else
    echo "⚠️  Supervisor not found - restart manually"
fi
echo ""

# Verify fix
echo "[7/7] Verifying fix..."
echo ""

# Test Redis connection
echo "Testing Redis connection..."
REDIS_TEST=$(php -r "require 'vendor/autoload.php'; \$app = require_once 'bootstrap/app.php'; \$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap(); echo Redis::ping();" 2>&1 || echo "FAILED")

if [[ "$REDIS_TEST" == *"PONG"* ]]; then
    echo "✅ Redis connection working"
else
    echo "❌ Redis connection failed: $REDIS_TEST"
fi
echo ""

# Check for errors in last 10 log lines
echo "Checking recent logs for Redis errors..."
ERROR_COUNT=$(tail -10 storage/logs/laravel.log 2>/dev/null | grep -c "Cannot use object of type Redis" || true)
if [ "$ERROR_COUNT" -eq 0 ]; then
    echo "✅ No Redis errors in recent logs"
else
    echo "⚠️  Still seeing Redis errors ($ERROR_COUNT found)"
fi
echo ""

# Check supervisor status
if command -v supervisorctl &> /dev/null; then
    echo "Queue worker status:"
    sudo supervisorctl status | grep laravel-queue | head -5
fi
echo ""

echo "============================================"
echo "Fix Complete!"
echo "============================================"
echo ""
echo "Next steps:"
echo "1. Monitor logs: tail -f storage/logs/laravel.log"
echo "2. Check queue depth: redis-cli llen queues:high"
echo "3. Send test MQTT messages"
echo "4. Verify DB updates"
echo ""
echo "If still having issues:"
echo "- Check EMERGENCY_REDIS_FIX.md for detailed troubleshooting"
echo "- Revert to sync queue temporarily if needed"
echo ""
