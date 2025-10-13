#!/bin/bash
# Quick deployment script for drain approach changes

echo "🚀 Deploying Drain Approach for Zero Data Loss..."
echo "================================================"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Step 1: Pull latest changes
echo -e "${YELLOW}Step 1: Pulling latest changes...${NC}"
git pull origin new-batch-code || { echo -e "${RED}Failed to pull changes${NC}"; exit 1; }
echo -e "${GREEN}✓ Changes pulled${NC}"

# Step 2: Clear Laravel caches
echo -e "${YELLOW}Step 2: Clearing Laravel caches...${NC}"
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan route:clear
composer dump-autoload
echo -e "${GREEN}✓ Caches cleared${NC}"

# Step 3: Check Redis connectivity
echo -e "${YELLOW}Step 3: Checking Redis connectivity...${NC}"
redis-cli ping > /dev/null 2>&1 || { echo -e "${RED}Redis not accessible!${NC}"; exit 1; }
echo -e "${GREEN}✓ Redis is running${NC}"

# Step 4: Restart Node MQTT handler
echo -e "${YELLOW}Step 4: Restarting Node MQTT handler...${NC}"
if command -v pm2 &> /dev/null; then
    pm2 restart mqtt_handler || { echo -e "${RED}Failed to restart Node handler${NC}"; exit 1; }
    echo -e "${GREEN}✓ Node handler restarted${NC}"
else
    echo -e "${YELLOW}⚠ pm2 not found, skipping Node restart${NC}"
fi

# Step 5: Restart queue workers
echo -e "${YELLOW}Step 5: Restarting Laravel queue workers...${NC}"
if command -v supervisorctl &> /dev/null; then
    sudo supervisorctl restart all || { echo -e "${RED}Failed to restart workers${NC}"; exit 1; }
    echo -e "${GREEN}✓ Queue workers restarted${NC}"

    # Show worker status
    echo ""
    echo "Queue worker status:"
    sudo supervisorctl status | grep laravel-worker
else
    echo -e "${YELLOW}⚠ supervisorctl not found, skipping worker restart${NC}"
fi

# Step 6: Verify setup
echo ""
echo -e "${YELLOW}Step 6: Verifying setup...${NC}"
echo ""

# Check queue workers
WORKER_COUNT=$(ps aux | grep 'queue:work' | grep -v grep | wc -l)
echo "Active queue workers: $WORKER_COUNT"

# Check Redis queues
DONE_QUEUE=$(redis-cli llen order_responses:drain_queue:done 2>/dev/null || echo "0")
EXTERNAL_QUEUE=$(redis-cli llen order_responses:drain_queue:external 2>/dev/null || echo "0")
echo "Drain queue (done): $DONE_QUEUE items"
echo "Drain queue (external): $EXTERNAL_QUEUE items"

# Check Node process
if command -v pm2 &> /dev/null; then
    pm2 list | grep mqtt_handler
fi

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}✓ Deployment Complete!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "📝 Next Steps:"
echo "1. Run a small test (1000 responses)"
echo "2. Monitor queue: watch -n 1 'redis-cli llen order_responses:drain_queue:done'"
echo "3. Check logs: tail -f storage/logs/laravel.log | grep DrainOrderResponses"
echo "4. Verify DB: SELECT done_count, total_count FROM orders WHERE id = YOUR_ORDER_ID"
echo ""
echo "📚 Full documentation: DRAIN_APPROACH_GUIDE.md"
echo ""
