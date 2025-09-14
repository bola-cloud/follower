// PM2 Ecosystem Configuration for High-Volume MQTT Processing
// This configuration optimizes the MQTT handler for handling 500-1000 concurrent users

module.exports = {
  apps: [
    {
      name: 'mqtt-handler',
      script: './node_scripts/mqtt_handler.cjs',
      instances: 1, // Single instance to avoid message duplication
      exec_mode: 'fork', // Fork mode for Node.js scripts
      watch: false, // Disable in production
      max_memory_restart: '1G',
      node_args: '--max-old-space-size=512',

      // High-volume processing environment variables
      env: {
        NODE_ENV: 'production',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',

        // Connection and concurrency settings
        MQTT_HTTP_TIMEOUT: '30000', // 30s timeout for API calls
        MQTT_MAX_INFLIGHT: '75', // Increased from 50 for higher throughput

        // Batch processing settings
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '20', // Process 20 actions per batch
        MQTT_BATCH_TIMEOUT: '3000', // Wait 3s to accumulate batch

        // Health monitoring
        MQTT_HEALTH_CHECK_INTERVAL: '30000', // Check system health every 30s

        // Logging
        DEBUG: 'false' // Set to 'true' for verbose logging during testing
      },

      // Development environment (for testing)
      env_development: {
        NODE_ENV: 'development',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',

        // More conservative settings for development
        MQTT_HTTP_TIMEOUT: '20000',
        MQTT_MAX_INFLIGHT: '30',

        // Batch processing (smaller batches for testing)
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '10',
        MQTT_BATCH_TIMEOUT: '2000',

        // More frequent health checks during development
        MQTT_HEALTH_CHECK_INTERVAL: '15000',

        // Enable debug logging
        DEBUG: 'true'
      },

      // High-load testing environment
      env_testing: {
        NODE_ENV: 'testing',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',

        // Aggressive settings for load testing
        MQTT_HTTP_TIMEOUT: '45000', // Longer timeout under load
        MQTT_MAX_INFLIGHT: '100', // High concurrency for testing

        // Larger batches for load testing
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '30',
        MQTT_BATCH_TIMEOUT: '1500', // Faster batch processing

        // Frequent monitoring during testing
        MQTT_HEALTH_CHECK_INTERVAL: '10000',

        // Detailed logging for analysis
        DEBUG: 'true'
      },

      // Restart settings
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',

      // Logging
      log_file: './logs/mqtt-handler.log',
      out_file: './logs/mqtt-handler-out.log',
      error_file: './logs/mqtt-handler-error.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',

      // Process monitoring
      pmx: true,

      // Graceful shutdown
      kill_timeout: 5000,

      // Additional process options
      cron_restart: '0 4 * * *', // Restart daily at 4 AM for memory cleanup
      merge_logs: true,
      combine_logs: true
    },

    // Optional: Separate process for health monitoring and metrics collection
    {
      name: 'mqtt-monitor',
      script: './node_scripts/mqtt_monitor.cjs',
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '256M',

      env: {
        NODE_ENV: 'production',
        // Use local HTTP for internal health checks to avoid external HTTPS/hostname issues
        API_BASE: 'http://127.0.0.1',
        MONITOR_INTERVAL: '60000', // Check every minute
        ALERT_THRESHOLD_QUEUE_SIZE: '500',
        ALERT_THRESHOLD_ERROR_RATE: '0.05' // 5% error rate threshold
      },

      // Only run if monitoring script exists
      ignore_watch: ['node_modules', 'logs'],
      autorestart: true,
      max_restarts: 5,
      min_uptime: '30s'
    }
  ]
};
