/**
 * PM2 ecosystem configuration for the MQTT publisher worker
 *
 * Usage:
 *   pm2 start node_scripts/ecosystem.mqtt.config.js --env production
 *   pm2 save
 *   pm2 startup # follow printed instructions to enable on boot
 *
 * Adjust concurrency and env variables as needed.
 */

module.exports = {
  apps: [
    {
      name: 'mqtt-publisher',
      script: 'node_scripts/mqtt_publisher_worker.cjs',
      // Provide node args to make unhandled rejections crash (so PM2 restarts a clean state)
      node_args: '--unhandled-rejections=strict',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '512M',
      env: {
        NODE_ENV: 'production',
        REDIS_URL: process.env.REDIS_URL || 'redis://127.0.0.1:6379',
        REDIS_QUEUE_KEY: process.env.REDIS_QUEUE_KEY || 'mqtt:publish',
        MQTT_BROKER: process.env.MQTT_BROKER || 'mqtt://109.199.112.65:1883',
        CONCURRENCY: process.env.CONCURRENCY || '50',
        MQTT_PUBLISH_TIMEOUT_MS: process.env.MQTT_PUBLISH_TIMEOUT_MS || '5000',
        METRICS_INTERVAL_MS: process.env.METRICS_INTERVAL_MS || '10000',
      },
      error_file: 'storage/logs/mqtt-publisher-err.log',
      out_file: 'storage/logs/mqtt-publisher-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm Z'
    }
  ]
};
