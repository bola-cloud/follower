<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

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

        // Use the 'queue' Redis connection which is configured to use the logical DB for queue/worker data (typically DB 2)
        $redis = \Illuminate\Support\Facades\Redis::connection('queue');

        try {
            Log::info('[MqttDeviceController] handle() received activation', ['device_id' => $deviceId, 'connection' => 'queue']);
            // Use Redis SET to store unique device ids atomically and efficiently
            $redis->sadd($setKey, $deviceId);

            // Optionally set a TTL to avoid indefinite growth (e.g., 10 minutes)
            $redis->expire($setKey, 600);

            $count = $redis->scard($setKey);

            Log::info('[MqttDeviceController] handle() stored activation', ['device_id' => $deviceId, 'set' => $setKey, 'count' => $count]);

            return response()->json([
                'message' => 'Stored',
                'count' => $count,
            ]);
        } catch (\Throwable $e) {
            // Fallback to cache-based behavior if Redis is unavailable
            Log::error('[MqttDeviceController] Redis unavailable, falling back to Cache', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            $cacheSetKey = 'device_activations_set';
            $cacheCountKey = 'device_activations_count';

            $existingDevices = Cache::get($cacheSetKey, []);

            if (!in_array($deviceId, $existingDevices)) {
                $existingDevices[] = $deviceId;
                Cache::put($cacheSetKey, $existingDevices); // No expiration time
                Cache::put($cacheCountKey, count($existingDevices)); // No expiration time
                Log::warning('[MqttDeviceController] handle() cache fallback stored device', ['device_id' => $deviceId, 'count' => count($existingDevices)]);
            } else {
                Log::info('[MqttDeviceController] handle() cache fallback already contains device', ['device_id' => $deviceId]);
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
            Log::info('[MqttDeviceController] handleBatch() received batch', ['incoming' => count($validated['activations']), 'unique' => count($deviceIds)]);
            // Use Redis pipeline for efficient bulk SADD
            $redis = \Illuminate\Support\Facades\Redis::connection('queue');
            $pipe = $redis->pipeline();
            foreach ($deviceIds as $did) {
                $pipe->sadd($setKey, $did);
            }
            // ensure TTL is set
            $pipe->expire($setKey, 600);
            $pipe->exec();

            $count = $redis->scard($setKey);
            Log::info('[MqttDeviceController] handleBatch() stored batch', ['set' => $setKey, 'count' => $count]);

            return response()->json(['message' => 'Batch stored', 'count' => $count]);
        } catch (\Throwable $e) {
            Log::error('[MqttDeviceController] handleBatch failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to store batch'], 500);
        }
    }

}
