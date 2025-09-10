#!/bin/bash
# Performance Monitoring Script
# Tracks queue processing, Redis performance, and worker health

echo "📊 MQTT Queue Performance Monitor"
echo "================================="
echo "Target: 2000 actions/minute (33/second)"
echo ""

# Function to get queue lengths
get_queue_stats() {
    echo "🔄 Queue Status:"
    redis-cli -n 0 llen laravel_database_high 2>/dev/null | sed 's/^/  High priority: /' || echo "  High priority: 0"
    redis-cli -n 0 llen laravel_database_default 2>/dev/null | sed 's/^/  Default: /' || echo "  Default: 0"
    redis-cli -n 0 llen laravel_database_bulk 2>/dev/null | sed 's/^/  Bulk: /' || echo "  Bulk: 0"
    redis-cli -n 3 llen laravel_database_actions 2>/dev/null | sed 's/^/  Actions: /' || echo "  Actions: 0"
    redis-cli -n 2 llen mqtt_actions_queue 2>/dev/null | sed 's/^/  MQTT Queue: /' || echo "  MQTT Queue: 0"
}

# Function to get Redis performance
get_redis_stats() {
    echo ""
    echo "💾 Redis Performance:"
    redis-cli info memory | grep -E "(used_memory_human|used_memory_peak_human)" | sed 's/^/  /'
    redis-cli info stats | grep -E "(total_commands_processed|instantaneous_ops_per_sec)" | sed 's/^/  /'
}

# Function to get worker status
get_worker_status() {
    echo ""
    echo "👷 Worker Status:"
    sudo supervisorctl status | grep laravel-queue | sed 's/^/  /'
}

# Function to calculate throughput
calculate_throughput() {
    echo ""
    echo "📈 Throughput Analysis (last 60 seconds):"

    # Get initial counts
    start_commands=$(redis-cli info stats | grep total_commands_processed | cut -d: -f2 | tr -d '\r')
    start_time=$(date +%s)

    sleep 60

    # Get final counts
    end_commands=$(redis-cli info stats | grep total_commands_processed | cut -d: -f2 | tr -d '\r')
    end_time=$(date +%s)

    # Calculate rates
    command_rate=$(( (end_commands - start_commands) / (end_time - start_time) ))

    echo "  Redis commands/sec: $command_rate"
    echo "  Est. actions/minute: $(( command_rate * 60 / 10 ))" # Rough estimate
}

# Main monitoring loop
if [ "$1" = "--continuous" ]; then
    echo "Starting continuous monitoring (Ctrl+C to stop)..."
    while true; do
        clear
        echo "📊 MQTT Queue Performance Monitor - $(date)"
        echo "================================="
        get_queue_stats
        get_redis_stats
        get_worker_status
        echo ""
        echo "⏰ Next update in 5 seconds..."
        sleep 5
    done
elif [ "$1" = "--throughput" ]; then
    calculate_throughput
else
    # Single snapshot
    get_queue_stats
    get_redis_stats
    get_worker_status
    echo ""
    echo "💡 Usage:"
    echo "  ./monitor-performance.sh              # Single snapshot"
    echo "  ./monitor-performance.sh --continuous # Live monitoring"
    echo "  ./monitor-performance.sh --throughput # 60-second throughput test"
fi
