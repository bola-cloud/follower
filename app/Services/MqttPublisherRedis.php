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
            $res = Redis::rpush($this->key, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            Redis::expire($this->key, 86400);
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
