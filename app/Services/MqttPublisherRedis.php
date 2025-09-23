<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class MqttPublisherRedis
{
    protected $key;

    public function __construct()
    {
        // Support either MQTT_QUEUE_KEY or REDIS_QUEUE_KEY (some deployments use one or the other)
        $this->key = env('MQTT_QUEUE_KEY', env('REDIS_QUEUE_KEY', 'mqtt:publish'));
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
        if (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                Log::warning('[MqttPublisherRedis] json_encode failed for payload array', ['payload' => $payload, 'error' => json_last_error_msg()]);
                return false;
            }
        } elseif (!is_string($payload)) {
            Log::warning('[MqttPublisherRedis] Invalid payload type, must be string or array', ['type' => gettype($payload)]);
            return false;
        }
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

            $usedConnection = 'default';
            try {
                $redisConn = Redis::connection('queue');
                $usedConnection = 'queue';
            } catch (\Throwable $e) {
                $redisConn = Redis::connection();
                $usedConnection = 'default';
            }

            // Diagnostic info (ERROR level so it appears in production logs)
            try {
                $diag = [
                    'redis_host' => env('REDIS_HOST') ?: null,
                    'redis_port' => env('REDIS_PORT') ?: null,
                    'redis_db' => env('REDIS_DB') ?: null,
                    'env_queue_key' => env('MQTT_QUEUE_KEY') ?: $this->key,
                    'using_connection' => $usedConnection,
                    'pipeline_started_at' => time(),
                ];
                Log::error('[MqttPublisherRedis] enqueue diagnostics', $diag);
            } catch (\Throwable $__d) {
                // ignore diag logging errors
            }

            $notifyChannel = env('MQTT_QUEUE_PUBSUB_CHANNEL', $this->key . ':notify');

            // Single RTT via pipeline: RPUSH + EXPIRE + PUBLISH
            $results = $redisConn->pipeline(function ($pipe) use ($encoded, $notifyChannel) {
                $pipe->rpush($this->key, $encoded);
                $pipe->expire($this->key, 86400);
                $pipe->publish($notifyChannel, '1'); // best-effort wake up
            });

            // Record pipeline results for debugging (ERROR level for production visibility)
            try {
                Log::error('[MqttPublisherRedis] pipeline results', ['results' => $results]);
            } catch (\Throwable $__l) {
                // ignore
            }

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
