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

            // Log Redis queue config for debugging
            try {
                $redisConfig = config('database.redis.queue');
                Log::info('[MqttDeviceController] redis.queue.config', $redisConfig);
            } catch (\Throwable $e) {
                Log::warning('[MqttDeviceController] failed to read redis.queue.config', ['error' => $e->getMessage()]);
            }

            // Use Redis SET to store unique device ids atomically and efficiently
            $saddResult = $redis->sadd($setKey, $deviceId);

            // Optionally set a TTL to avoid indefinite growth (e.g., 10 minutes)
            $expireResult = $redis->expire($setKey, 600);

            // Read raw scard
            $count = $redis->scard($setKey);

            Log::info('[MqttDeviceController] handle() stored activation', ['device_id' => $deviceId, 'set' => $setKey, 'sadd' => $saddResult, 'expire' => $expireResult, 'count' => $count]);

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
            try {
                $redisConfig = config('database.redis.queue');
                Log::info('[MqttDeviceController] redis.queue.config', $redisConfig);
            } catch (\Throwable $e) {
                Log::warning('[MqttDeviceController] failed to read redis.queue.config', ['error' => $e->getMessage()]);
            }

            // Use a direct SADD loop to avoid client-specific pipeline issues
            $redis = \Illuminate\Support\Facades\Redis::connection('queue');
            $added = 0;
            foreach ($deviceIds as $did) {
                try {
                    $res = $redis->sadd($setKey, $did);
                    // SADD returns 1 if the element was added, 0 if it was already a member
                    $added += (int) $res;
                } catch (\Throwable $__e) {
                    // Log per-item failure but continue processing remaining ids
                    Log::warning('[MqttDeviceController] handleBatch() sadd failed for device', ['device_id' => $did, 'error' => $__e->getMessage()]);
                }
            }

            // Ensure TTL is set once
            try { $expireResult = $redis->expire($setKey, 600); } catch (\Throwable $__e) { $expireResult = false; }

            $count = 0;
            try { $count = $redis->scard($setKey); } catch (\Throwable $__e) { $count = 0; }

            Log::info('[MqttDeviceController] handleBatch() stored batch', ['set' => $setKey, 'incoming' => count($validated['activations']), 'unique_attempted' => count($deviceIds), 'added_new' => $added, 'expire' => $expireResult, 'count' => $count]);

            return response()->json(['message' => 'Batch stored', 'count' => $count]);
        } catch (\Throwable $e) {
            Log::error('[MqttDeviceController] handleBatch failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to store batch'], 500);
        }
    }

}
