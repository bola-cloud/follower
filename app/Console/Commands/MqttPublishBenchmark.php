<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class MqttPublishBenchmark extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:publish-benchmark
                            {--count=1000 : Number of messages to publish}
                            {--mode=enqueue : publish mode: "enqueue" (use MqttPublisherRedis->enqueue) or "direct" (Redis::publish)}
                            {--topic=benchmark/test : Topic base to publish to}
                            {--payload-size=128 : Approx payload size in bytes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a publish throughput benchmark for the MQTT publish service.';

    public function handle()
    {
        $count = (int) $this->option('count');
        $mode = $this->option('mode');
        $topicBase = (string) $this->option('topic');
        $payloadSize = (int) $this->option('payload-size');

        if ($count <= 0) {
            $this->error('Count must be a positive integer');
            return 1;
        }

        $this->info("Starting MQTT publish benchmark: count={$count}, mode={$mode}");

        // Try to resolve the publisher service if available
        $publisher = null;
        try {
            if (app()->bound(\App\Services\MqttPublisherRedis::class)) {
                $publisher = app(\App\Services\MqttPublisherRedis::class);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $start = microtime(true);
        $published = 0;

        // Pre-generate a payload to roughly match requested size
        $sample = Str::random(max(8, $payloadSize));

        for ($i = 0; $i < $count; $i++) {
            $payload = json_encode([
                'id' => $i,
                'ts' => round(microtime(true) * 1000),
                'data' => $sample,
            ]);

            $topic = $topicBase . '/' . ($i % 1000);

            try {
                if ($mode === 'enqueue' && $publisher && method_exists($publisher, 'enqueue')) {
                    // enqueue(topic, payload, delaySeconds, useQueue)
                    $ok = $publisher->enqueue($topic, json_decode($payload, true), 0, false);
                    $published++;
                } else {
                    // Fallback to Redis publish (string payload)
                    Redis::publish($topic, $payload);
                    $published++;
                }
            } catch (\Throwable $e) {
                $this->warn("publish failed at #{$i}: " . $e->getMessage());
            }
        }

        $elapsed = microtime(true) - $start;
        $elapsedMinutes = max(1e-6, $elapsed / 60.0);

        $perMinute = $published / $elapsedMinutes;

        $this->info("Completed. Total published: {$published}");
        $this->info(sprintf('Elapsed: %.3f sec (%.3f min)', $elapsed, $elapsed / 60.0));
        $this->info(sprintf('Throughput: %.1f publishes/min (%.1f publishes/sec)', $perMinute, $published / max(1e-6, $elapsed)));

        return 0;
    }
}
