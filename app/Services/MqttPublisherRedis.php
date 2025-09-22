<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MqttPublisherRedis
{
    protected $key;

    public function __construct()
    {
        $this->key = env('MQTT_QUEUE_KEY', 'mqtt:publish');
    }

    /**
     * Enqueue a publish job to Redis list for the persistent Node worker to consume
     * @param string $topic
     * @param mixed $payload (array or string)
     * @param int $qos
     * @param bool $retain
     * @return bool
     */
    public function enqueue(string $topic, $payload, int $qos = 0, bool $retain = false): bool
    {
        $job = [
            'topic' => $topic,
            'payload' => $payload,
            'qos' => $qos,
            'retain' => $retain,
            'meta' => [ 'enqueued_at' => time() ]
        ];

        try {
            $payload = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                Log::warning('[MqttPublisherRedis] json_encode failed', ['job' => $job, 'error' => json_last_error_msg()]);
                return false;
            }

            // Use named "queue" connection (config.database.redis.queue) when available so we
            // enqueue to the same Redis DB the Node worker is configured to watch (DB 2).
            try {
                $redisConn = Redis::connection('queue');
            } catch (\Throwable $e) {
                // Fall back to default Redis connection
                $redisConn = Redis::connection();
            }

            $res = $redisConn->rpush($this->key, $payload);
            // Ensure the list has at least one element after push
            if (!is_int($res) || $res <= 0) {
                Log::warning('[MqttPublisherRedis] rpush returned unexpected result', ['key' => $this->key, 'rpush' => $res]);
                return false;
            }

            try {
                $redisConn->expire($this->key, 86400);
            } catch (\Throwable $e) {
                // best-effort expire; don't fail enqueue
            }

            // Publish a short notification on a pub/sub channel to wake up any subscriber
            // This allows the persistent Node worker to immediately drain the list instead
            // of waiting for BRPOP timeout. Channel name is configurable via env.
            try {
                $notifyChannel = env('MQTT_QUEUE_PUBSUB_CHANNEL', $this->key . ':notify');
                // publish returns number of clients that received the message
                $redisConn->publish($notifyChannel, '1');
            } catch (\Throwable $e) {
                // best-effort notify; don't fail enqueue
            }
            // Log enqueue success for observability (shows in storage/logs/laravel.log)
            try {
                Log::info('[MqttPublisherRedis] enqueued', ['key' => $this->key, 'rpush' => $res, 'topic' => $topic, 'meta' => $job['meta']]);
            } catch (\Throwable $e) {
                // swallow logging errors to avoid breaking enqueue path
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[MqttPublisherRedis] enqueue failed', ['error' => $e->getMessage(), 'job' => $job]);
            return false;
        }
    }
}
