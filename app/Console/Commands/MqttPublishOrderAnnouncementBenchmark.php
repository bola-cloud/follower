<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use ReflectionMethod;

class MqttPublishOrderAnnouncementBenchmark extends Command
{
    protected $signature = 'mqtt:publish-order-announcement-benchmark
                            {--count=1000 : Number of publish calls to perform}
                            {--concurrency=50 : Number of concurrent workers to use}
                            {--duration=60 : Duration (seconds) to run (approx) — will stop after count completed}
                            {--topic=orders : Topic base (ignored, informational)}';

    protected $description = 'Benchmark OrderService::publishOrderAnnouncement (calls private method via reflection) to measure publish initiations per minute.';

    public function handle()
    {
        $count = (int) $this->option('count');
        $concurrency = (int) $this->option('concurrency');
        $duration = (int) $this->option('duration');

        if ($count <= 0) {
            $this->error('Count must be > 0');
            return 1;
        }

        $this->info("Starting publish-order-announcement benchmark: count={$count}, concurrency={$concurrency}");

        // Resolve OrderService and access private method publishOrderAnnouncement
        $orderService = app(\App\Services\OrderService::class);
        try {
            $rm = new ReflectionMethod(get_class($orderService), 'publishOrderAnnouncement');
            $rm->setAccessible(true);
        } catch (\ReflectionException $e) {
            $this->error('Cannot access publishOrderAnnouncement: ' . $e->getMessage());
            return 1;
        }

        // Worker loop: generate random userId and orderId and call the private method
        $start = microtime(true);
        $published = 0;

        $workers = [];
        $chunk = (int) max(1, floor($count / $concurrency));

        for ($w = 0; $w < $concurrency; $w++) {
            $workers[] = function() use(&$published, $chunk, $rm, $orderService) {
                for ($i = 0; $i < $chunk; $i++) {
                    $userId = rand(1000, 9999999);
                    $orderId = rand(1000, 9999999);
                    $type = 'follow';
                    $targetUrl = 'https://example.com/' . Str::random(8);
                    try {
                        // call private method (userId, orderId, type, url)
                        $rm->invoke($orderService, $userId, $orderId, $type, $targetUrl);
                        $published++;
                    } catch (\Throwable $e) {
                        // ignore publish failure for benchmarking the call rate
                    }
                }
            };
        }

        // Run workers sequentially but quickly to avoid process explosion
        foreach ($workers as $fn) { $fn(); }

        $elapsed = microtime(true) - $start;
        $elapsedMinutes = max(1e-6, $elapsed / 60.0);
        $perMinute = $published / $elapsedMinutes;

        $this->info("Completed. Calls invoked: {$published}");
        $this->info(sprintf('Elapsed: %.3f sec', $elapsed));
        $this->info(sprintf('Invocation throughput: %.1f calls/min (%.1f calls/sec)', $perMinute, $published / max(1e-6, $elapsed)));

        return 0;
    }
}
