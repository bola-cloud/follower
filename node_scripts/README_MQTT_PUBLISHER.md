MQTT Publisher Worker (PM2)
================================

This worker consumes publish jobs from Redis and sends them to the MQTT broker.

Installation
------------

From the project root run:

  npm install mqtt ioredis

Environment variables
---------------------

Set these in your PM2 ecosystem or systemd unit:

- `REDIS_URL` (default: `redis://127.0.0.1:6379`)
- `REDIS_QUEUE_KEY` (default: `mqtt:publish`)
- `MQTT_BROKER` (default: `mqtt://109.199.112.65:1883`)
- `CONCURRENCY` (default: `50`)
- `MQTT_PUBLISH_TIMEOUT_MS` (default: `5000`)

Run with PM2
-----------

  pm2 start node_scripts/mqtt_publisher_worker.cjs --name mqtt-publisher --node-args="--unhandled-rejections=strict"

Example job format (pushed by producers - JSON string):

  {
    "topic": "orders/123",
    "payload": { "url": "https://...", "order_id": 123, "type": "create" },
    "qos": 0,
    "retain": false,
    "meta": { "order_id": 123 }
  }

Producers (PHP)
---------------

Your PHP code can RPUSH this JSON to the Redis list `mqtt:publish` (or the key set in `REDIS_QUEUE_KEY`). Use `BRPOP` in the worker to consume.
