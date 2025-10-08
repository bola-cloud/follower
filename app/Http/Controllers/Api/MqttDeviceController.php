<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class MqttDeviceController extends Controller
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'status' => 'required|in:active',
        ]);

        $deviceId = $validated['device_id'];
        $setKey = 'device_activations_set';

        try {
            // Use Redis SET to store unique device ids atomically and efficiently
            Redis::sadd($setKey, $deviceId);

            // Optionally set a TTL to avoid indefinite growth (e.g., 10 minutes)
            // Only set TTL if key is new
            Redis::expire($setKey, 600);

            $count = Redis::scard($setKey);

            return response()->json([
                'message' => 'Stored',
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            // Fallback to cache-based behavior if Redis is unavailable
            \Log::error('[MqttDeviceController] Redis unavailable, falling back to Cache: ' . $e->getMessage());

            $cacheSetKey = 'device_activations_set';
            $cacheCountKey = 'device_activations_count';

            $existingDevices = Cache::get($cacheSetKey, []);

            if (!in_array($deviceId, $existingDevices)) {
                $existingDevices[] = $deviceId;
                Cache::put($cacheSetKey, $existingDevices); // No expiration time
                Cache::put($cacheCountKey, count($existingDevices)); // No expiration time
            }

            return response()->json([
                'message' => 'Stored (fallback)',
                'count' => Cache::get($cacheCountKey, 0),
            ]);
        }
    }

}
