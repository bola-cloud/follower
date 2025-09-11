#!/bin/bash

# 🔍 Ultra Performance Server Readiness Check
# Run this script before deploying the ultra configuration

echo "🚀 Laravel Ultra Workers - Server Readiness Check"
echo "=================================================="
echo ""

# System Information
echo "📊 SYSTEM INFORMATION"
echo "--------------------"
echo "OS: $(uname -a)"
echo "CPU Cores: $(nproc)"
echo "Total Memory: $(free -h | grep '^Mem:' | awk '{print $2}')"
echo "Available Memory: $(free -h | grep '^Mem:' | awk '{print $7}')"
echo "Disk Space: $(df -h / | tail -1 | awk '{print $4}' | sed 's/G/ GB/')"
echo ""

# PHP Configuration Check
echo "🐘 PHP CONFIGURATION"
echo "--------------------"
php_version=$(php -v | head -n1)
echo "PHP Version: $php_version"
echo "Memory Limit: $(php -r "echo ini_get('memory_limit');")"
echo "Max Execution Time: $(php -r "echo ini_get('max_execution_time');")"
echo "Max Input Vars: $(php -r "echo ini_get('max_input_vars');")"
echo ""

# MySQL Configuration Check
echo "🗄️ MYSQL CONFIGURATION"
echo "----------------------"
mysql_version=$(mysql -V)
echo "MySQL Version: $mysql_version"

mysql -e "
SELECT 'Max Connections' as Setting, VARIABLE_VALUE as Value FROM INFORMATION_SCHEMA.GLOBAL_VARIABLES WHERE VARIABLE_NAME='max_connections'
UNION ALL
SELECT 'Current Connections' as Setting, VARIABLE_VALUE as Value FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Threads_connected'
UNION ALL
SELECT 'Max Used Connections' as Setting, VARIABLE_VALUE as Value FROM INFORMATION_SCHEMA.GLOBAL_STATUS WHERE VARIABLE_NAME='Max_used_connections'
UNION ALL
SELECT 'InnoDB Buffer Pool Size' as Setting, VARIABLE_VALUE as Value FROM INFORMATION_SCHEMA.GLOBAL_VARIABLES WHERE VARIABLE_NAME='innodb_buffer_pool_size'
UNION ALL
SELECT 'Query Cache Size' as Setting, VARIABLE_VALUE as Value FROM INFORMATION_SCHEMA.GLOBAL_VARIABLES WHERE VARIABLE_NAME='query_cache_size';
"

echo ""

# Redis Configuration Check
echo "🔴 REDIS CONFIGURATION"
echo "---------------------"
redis_version=$(redis-server --version 2>/dev/null || echo "Redis not found")
echo "Redis Version: $redis_version"

if command -v redis-cli &> /dev/null; then
    echo "Redis Memory Usage: $(redis-cli info memory | grep used_memory_human | cut -d: -f2 | tr -d '\r')"
    echo "Redis Connected Clients: $(redis-cli info clients | grep connected_clients | cut -d: -f2 | tr -d '\r')"
    echo "Redis Database 2 Keys: $(redis-cli -n 2 dbsize)"
else
    echo "❌ Redis CLI not available"
fi
echo ""

# Supervisor Check
echo "👷 SUPERVISOR STATUS"
echo "-------------------"
if command -v supervisorctl &> /dev/null; then
    echo "Supervisor Status:"
    sudo supervisorctl status | grep laravel-queue || echo "No Laravel queues configured"
else
    echo "❌ Supervisor not installed"
fi
echo ""

# Queue Worker Process Check
echo "🔄 CURRENT QUEUE WORKERS"
echo "----------------------"
queue_processes=$(ps aux | grep "queue:work" | grep -v grep | wc -l)
echo "Active Queue Workers: $queue_processes"
if [ $queue_processes -gt 0 ]; then
    echo "Queue Worker Details:"
    ps aux | grep "queue:work" | grep -v grep | awk '{print "  PID:", $2, "Memory:", $4"%", "Command:", $11, $12, $13, $14, $15}'
fi
echo ""

# Laravel Application Check
echo "🎯 LARAVEL APPLICATION STATUS"
echo "----------------------------"
cd /home/egfollow/htdocs/egfollow.com 2>/dev/null || echo "❌ Laravel directory not found"

if [ -f "artisan" ]; then
    echo "Laravel Version: $(php artisan --version 2>/dev/null || echo 'Could not determine')"
    echo "Environment: $(php artisan env 2>/dev/null || echo 'Could not determine')"
    echo "Queue Connection: $(php artisan tinker --execute="echo config('queue.default');" 2>/dev/null | tail -1 || echo 'Could not determine')"

    echo "Queue Sizes:"
    php artisan queue:size 2>/dev/null || echo "  Could not get queue sizes"

    echo "Failed Jobs:"
    failed_jobs=$(php artisan queue:failed 2>/dev/null | wc -l)
    echo "  Failed Jobs Count: $failed_jobs"
else
    echo "❌ Laravel artisan not found"
fi
echo ""

# Performance Recommendations
echo "💡 PERFORMANCE RECOMMENDATIONS"
echo "-----------------------------"

# Memory Check
total_mem_gb=$(free -g | grep '^Mem:' | awk '{print $2}')
if [ $total_mem_gb -lt 16 ]; then
    echo "⚠️  Consider upgrading to 16GB+ RAM for ultra configuration (current: ${total_mem_gb}GB)"
else
    echo "✅ Memory sufficient for ultra configuration (${total_mem_gb}GB)"
fi

# CPU Check
cpu_cores=$(nproc)
if [ $cpu_cores -lt 8 ]; then
    echo "⚠️  Consider upgrading to 8+ CPU cores for optimal performance (current: $cpu_cores cores)"
else
    echo "✅ CPU cores sufficient for ultra configuration ($cpu_cores cores)"
fi

# MySQL Max Connections Check
max_connections=$(mysql -e "SELECT VARIABLE_VALUE FROM INFORMATION_SCHEMA.GLOBAL_VARIABLES WHERE VARIABLE_NAME='max_connections';" -s -N)
if [ $max_connections -lt 200 ]; then
    echo "⚠️  Consider increasing MySQL max_connections to 200+ (current: $max_connections)"
else
    echo "✅ MySQL max_connections adequate ($max_connections)"
fi

# Disk Space Check
available_gb=$(df / | tail -1 | awk '{print $4}' | sed 's/G//')
if [ -z "$available_gb" ]; then
    available_gb=$(df / | tail -1 | awk '{print int($4/1024/1024)}')
fi

if [ $available_gb -lt 10 ]; then
    echo "⚠️  Low disk space, consider cleanup (available: ${available_gb}GB)"
else
    echo "✅ Disk space adequate (${available_gb}GB available)"
fi

echo ""
echo "🎯 DEPLOYMENT READINESS"
echo "----------------------"

ready_count=0
total_checks=4

if [ $total_mem_gb -ge 8 ]; then ((ready_count++)); fi
if [ $cpu_cores -ge 4 ]; then ((ready_count++)); fi
if [ $max_connections -ge 100 ]; then ((ready_count++)); fi
if [ $available_gb -ge 5 ]; then ((ready_count++)); fi

echo "Readiness Score: $ready_count/$total_checks"

if [ $ready_count -eq $total_checks ]; then
    echo "✅ SERVER READY for Ultra Configuration Deployment!"
    echo ""
    echo "Next Steps:"
    echo "1. Run: sudo supervisorctl stop laravel-queue-*:*"
    echo "2. Run: sudo cp laravel-workers-ultra-batch.conf /etc/supervisor/conf.d/laravel-workers.conf"
    echo "3. Run: sudo supervisorctl reread && sudo supervisorctl update"
    echo "4. Run: sudo supervisorctl start laravel-queues-ultra:*"
    echo "5. Monitor: watch -n 2 'sudo supervisorctl status | grep laravel-queue | grep RUNNING | wc -l'"
elif [ $ready_count -ge 2 ]; then
    echo "⚠️  SERVER PARTIALLY READY - deployment possible but monitor closely"
    echo "Consider addressing the warnings above for optimal performance"
else
    echo "❌ SERVER NOT READY - address critical issues before deploying ultra configuration"
fi

echo ""
echo "🔍 For detailed deployment instructions, see ULTRA_DEPLOYMENT_COMMANDS.md"
