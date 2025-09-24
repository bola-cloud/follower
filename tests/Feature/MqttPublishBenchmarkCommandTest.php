<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MqttPublishBenchmarkCommandTest extends TestCase
{
    /**
     * A basic test to ensure the publish benchmark command runs.
     * This is a smoke test that publishes a small number of messages.
     *
     * @return void
     */
    public function test_benchmark_command_runs()
    {
        $exit = Artisan::call('mqtt:publish-benchmark', ['--count' => 10, '--mode' => 'direct']);
        $this->assertEquals(0, $exit);
        $output = Artisan::output();
        $this->assertStringContainsString('Completed. Total published', $output);
    }
}
