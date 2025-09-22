// PM2 Ecosystem Configuration (CJS) for High-Volume MQTT Processing
// This file is CommonJS (.cjs) so PM2 can require it even when the package uses ESM

module.exports = {
  apps: [
    {
      name: 'mqtt-handler',
      script: './node_scripts/mqtt_handler.cjs',
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '1G',
      node_args: '--max-old-space-size=512',

      env: {
        NODE_ENV: 'production',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',
        MQTT_HTTP_TIMEOUT: '30000',
        MQTT_MAX_INFLIGHT: '75',
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '20',
        MQTT_BATCH_TIMEOUT: '3000',
        MQTT_HEALTH_CHECK_INTERVAL: '30000',
        DEBUG: 'false'
      },

      env_development: {
        NODE_ENV: 'development',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',
        MQTT_HTTP_TIMEOUT: '20000',
        MQTT_MAX_INFLIGHT: '30',
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '10',
        MQTT_BATCH_TIMEOUT: '2000',
        MQTT_HEALTH_CHECK_INTERVAL: '15000',
        DEBUG: 'true'
      },

      env_production: {
        NODE_ENV: 'production',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',
        MQTT_HTTP_TIMEOUT: '30000',
        MQTT_MAX_INFLIGHT: '75',
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '20',
        MQTT_BATCH_TIMEOUT: '3000',
        MQTT_HEALTH_CHECK_INTERVAL: '30000',
        DEBUG: 'false'
      },

      env_testing: {
        NODE_ENV: 'testing',
        MQTT_BROKER: 'mqtt://109.199.112.65:1883',
        API_BASE: 'https://egfollow.com',
        MQTT_HTTP_TIMEOUT: '45000',
        MQTT_MAX_INFLIGHT: '100',
        MQTT_BATCH_ENABLED: 'true',
        MQTT_BATCH_SIZE: '30',
        MQTT_BATCH_TIMEOUT: '1500',
        MQTT_HEALTH_CHECK_INTERVAL: '10000',
        DEBUG: 'true'
      },

      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
      log_file: './logs/mqtt-handler.log',
      out_file: './logs/mqtt-handler-out.log',
      error_file: './logs/mqtt-handler-error.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
      pmx: true,
      kill_timeout: 5000,
      cron_restart: '0 4 * * *',
      merge_logs: true,
      combine_logs: true
    },

    {
      name: 'mqtt-monitor',
      script: './node_scripts/mqtt_monitor.cjs',
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '256M',

      env: {
        NODE_ENV: 'production',
        API_BASE: 'http://127.0.0.1',
        MONITOR_INTERVAL: '60000',
        ALERT_THRESHOLD_QUEUE_SIZE: '500',
        ALERT_THRESHOLD_ERROR_RATE: '0.05'
      },

      env_production: {
        NODE_ENV: 'production',
        API_BASE: 'http://127.0.0.1',
        MONITOR_INTERVAL: '60000',
        ALERT_THRESHOLD_QUEUE_SIZE: '500',
        ALERT_THRESHOLD_ERROR_RATE: '0.05'
      },

      ignore_watch: ['node_modules', 'logs'],
      autorestart: true,
      max_restarts: 5,
      min_uptime: '30s'
    }
  ]
};

// Add mqtt-publisher app for persistent Redis->MQTT worker
// Note: this block is appended to the same ecosystem so PM2 can manage all services
module.exports.apps.push({
  name: 'mqtt-publisher',
  script: './node_scripts/mqtt_publisher_worker.cjs',
  instances: 1,
  exec_mode: 'fork',
  watch: false,
  max_memory_restart: '512M',
  node_args: '--max-old-space-size=256',
  env: {
    NODE_ENV: 'production',
    REDIS_URL: 'redis://127.0.0.1:6379',
    REDIS_QUEUE_KEY: 'mqtt:publish',
    MQTT_BROKER: 'mqtt://109.199.112.65:1883',
    CONCURRENCY: '50',
    MQTT_PUBLISH_TIMEOUT_MS: '5000',
    METRICS_INTERVAL_MS: '10000'
  },
  env_production: {
    NODE_ENV: 'production',
    REDIS_URL: 'redis://127.0.0.1:6379',
    REDIS_QUEUE_KEY: 'mqtt:publish',
    MQTT_BROKER: 'mqtt://109.199.112.65:1883',
    CONCURRENCY: '50',
    MQTT_PUBLISH_TIMEOUT_MS: '5000',
    METRICS_INTERVAL_MS: '10000'
  },
  autorestart: true,
  max_restarts: 10,
  min_uptime: '10s',
  log_file: './logs/mqtt-publisher.log',
  out_file: './logs/mqtt-publisher-out.log',
  error_file: './logs/mqtt-publisher-error.log',
  log_date_format: 'YYYY-MM-DD HH:mm:ss Z',
  merge_logs: true,
  combine_logs: true
});
