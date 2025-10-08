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

    /**
     * Batch handler: accept multiple activations in one request for high-throughput ingestion
     * Payload: { activations: [ { device_id: '123', status: 'active' }, ... ] }
     */
    public function handleBatch(Request $request)
    {
        $validated = $request->validate([
            'activations' => 'required|array|min:1|max:5000',
            'activations.*.device_id' => 'required|string',
            'activations.*.status' => 'required|in:active',
        ]);

        $setKey = 'device_activations_set';
        $deviceIds = array_unique(array_map(function($a){ return $a['device_id']; }, $validated['activations']));

        try {
            // Use Redis pipeline for efficient bulk SADD
            $pipe = \Illuminate\Support\Facades\Redis::pipeline();
            foreach ($deviceIds as $did) {
                $pipe->sadd($setKey, $did);
            }
            // ensure TTL is set
            $pipe->expire($setKey, 600);
            $pipe->exec();

            $count = \Illuminate\Support\Facades\Redis::scard($setKey);

            return response()->json(['message' => 'Batch stored', 'count' => $count]);
        } catch (\Throwable $e) {
            \Log::error('[MqttDeviceController] handleBatch failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to store batch'], 500);
        }
    }

}
