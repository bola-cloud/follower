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

            // --- Deduplication: avoid enqueueing near-duplicates that come from
            // multiple code-paths in a short window (e.g. batch+single publish).
            // This is best-effort: we use SETNX + EX to suppress duplicates for a
            // short TTL (default 2s). If Redis returns an error we fall back to
            // enqueueing to avoid dropping messages.
            try {
                $dedupeTtl = (int) env('MQTT_DEDUPE_TTL', 2);
                $dedupeKey = $this->key . ':dedupe:' . sha1($topic . '|' . ($payload ?? ''));

                // Prefer setnx (widely supported); if it returns truthy we set an
                // expiry to limit duplication window. If setnx returns falsy,
                // treat as duplicate and skip enqueue. This is intentionally
                // lightweight and non-blocking.
                $setnxRes = $redisConn->setnx($dedupeKey, 1);
                if ($setnxRes) {
                    // best-effort expire (if expire fails we still proceed)
                    try { $redisConn->expire($dedupeKey, $dedupeTtl); } catch (\Throwable $__e) {}
                } else {
                    try {
                        Log::info('[MqttPublisherRedis] duplicate suppressed (dedupe)', ['key' => $dedupeKey, 'topic' => $topic]);
                    } catch (\Throwable $__l) {}
                    return true; // treat as successful (avoid fallback path)
                }
            } catch (\Throwable $__d) {
                // If dedupe fails for any reason, continue to enqueue to avoid
                // silently dropping messages.
            }

            // Short-lived dedupe: prevent near-duplicate publishes across different code paths
            // Build a stable dedupe key from topic + payload to avoid re-enqueueing identical jobs
            try {
                $dedupeTtl = (int) env('MQTT_RECENT_PUBLISH_TTL', 3); // seconds
                $dedupeKey = 'mqtt:recent_publish:' . md5($topic . '|' . $job['payload']);

                // Use SETNX semantics via setnx + expire to be compatible with different redis drivers
                $wasSet = false;
                try {
                    $wasSet = $redisConn->setnx($dedupeKey, time());
                } catch (\Throwable $__e) {
                    // setnx may not be available on some connections; fall back to raw set with NX via eval
                    try {
                        $wasSet = $redisConn->set($dedupeKey, time(), 'NX', 'EX', $dedupeTtl);
                    } catch (\Throwable $__ee) {
                        // If both approaches fail, we can't dedupe — continue without suppression
                        $wasSet = true;
                    }
                }

                if ($wasSet) {
                    // ensure TTL is set when setnx succeeded
                    try { $redisConn->expire($dedupeKey, $dedupeTtl); } catch (\Throwable $__ignore) {}
                } else {
                    // Duplicate detected within TTL window — suppress enqueue and log
                    Log::info('[MqttPublisherRedis] Suppressing duplicate publish (recently published)', [
                        'topic' => $topic,
                        'dedupe_key' => $dedupeKey,
                        'ttl' => $dedupeTtl
                    ]);
                    // Treat as success: upstream will assume message handled to avoid fallback execs
                    return true;
                }
            } catch (\Throwable $e) {
                // If dedupe check fails for any reason, continue to enqueue normally
                Log::warning('[MqttPublisherRedis] dedupe check failed, proceeding to enqueue', ['error' => $e->getMessage()]);
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
