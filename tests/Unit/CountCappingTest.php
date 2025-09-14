<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CountCappingTest extends TestCase
{
    public function test_least_usage_present_in_critical_files()
    {
        $paths = [
            __DIR__ . '/../../app/Jobs/ActionQueueJob.php',
            __DIR__ . '/../../app/Listeners/HandleActionResponse.php',
            __DIR__ . '/../../app/Console/Commands/LoadTestOrder.php',
            __DIR__ . '/../../app/Services/BatchDatabaseService.php',
            __DIR__ . '/../../app/Jobs/OptimizedActionBatchJob.php',
            __DIR__ . '/../../app/Http/Controllers/Api/MqttResponseController.php',
        ];

        $found = false;
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                $this->fail("Expected file to exist: {$path}");
            }

            $content = file_get_contents($path);
            if (strpos($content, 'LEAST(done_count +') !== false || strpos($content, 'LEAST(done_count+') !== false) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'Expected at least one critical file to contain LEAST-based update for done_count capping');
    }
}
