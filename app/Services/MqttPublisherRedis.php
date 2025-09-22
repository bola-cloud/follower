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
     * @param mixed  $payload (array or string)
     * @param int    $qos
     * @param bool   $retain
     * @return bool
     */
    public function enqueue(string $topic, $payload, int $qos = 0, bool $retain = false): bool
    {
        $job = [
            'topic'   => $topic,
            'payload' => $payload,
            'qos'     => $qos,
            'retain'  => $retain,
            'meta'    => ['enqueued_at' => time()]
        ];

        try {
            $encoded = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                Log::warning('[MqttPublisherRedis] json_encode failed', ['job' => $job, 'error' => json_last_error_msg()]);
                return false;
            }

            try {
                $redisConn = Redis::connection('queue');
            } catch (\Throwable $e) {
                $redisConn = Redis::connection();
            }

            $notifyChannel = env('MQTT_QUEUE_PUBSUB_CHANNEL', $this->key . ':notify');

            // Single RTT via pipeline: RPUSH + EXPIRE + PUBLISH
            $results = $redisConn->pipeline(function ($pipe) use ($encoded, $notifyChannel) {
                $pipe->rpush($this->key, $encoded);
                $pipe->expire($this->key, 86400);
                $pipe->publish($notifyChannel, '1'); // best-effort wake up
            });

            // RPUSH result is index 0 in pipeline results
            $rpushRes = $results[0] ?? null;
            if (!is_int($rpushRes) || $rpushRes <= 0) {
                Log::warning('[MqttPublisherRedis] rpush unexpected result', ['key' => $this->key, 'rpush' => $rpushRes]);
                return false;
            }

            try {
                Log::info('[MqttPublisherRedis] enqueued', ['key' => $this->key, 'len' => $rpushRes, 'topic' => $job['topic']]);
            } catch (\Throwable $e) {
                // ignore logging errors
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[MqttPublisherRedis] enqueue failed', ['error' => $e->getMessage(), 'job' => $job]);
            return false;
        }
    }
}
