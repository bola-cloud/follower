<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PingService
{
    public function sendPing(string $topic, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        $command = "mosquitto_pub -h 109.199.112.65 -p 1883 -t {$topic} -m " . escapeshellarg($json) . " -q 1";

        // Execute synchronously to capture any errors
        $output = [];
        $return_var = 0;
        exec($command . " 2>&1", $output, $return_var);

        if ($return_var !== 0) {
            Log::error("[PingService] MQTT command failed", [
                'command' => $command,
                'return_code' => $return_var,
                'output' => implode("\n", $output),
                'topic' => $topic,
                'data' => $data
            ]);
        } else {
            Log::info("[PingService] ✅ Successfully sent ping to topic {$topic}", [
                'data' => $data,
                'output' => implode("\n", $output)
            ]);
        }
    }
}
