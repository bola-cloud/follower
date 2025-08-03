<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PingService
{
    public function sendPing(string $topic, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";
        exec($command . " > /dev/null 2>&1 &");

        Log::info("[PingService] Sent ping to topic {$topic} with data: " . json_encode($data));
    }
}
