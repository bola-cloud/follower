<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MqttDeviceController extends Controller
{
    public function handle(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|string',
            'status' => 'required|in:active,inactive',
        ]);

        $deviceId = $validated['device_id'];
        $cacheSetKey = 'device_activations_set';
        $cacheCountKey = 'device_activations_count';

        // Use taggable cache if needed in Redis or use array
        $existingDevices = Cache::get($cacheSetKey, []);

        // If not already stored, add it
        if (!in_array($deviceId, $existingDevices)) {
            $existingDevices[] = $deviceId;

            // Store updated list with short expiry (e.g., 30 minutes)
            Cache::put($cacheSetKey, $existingDevices, now()->addMinutes(1));
            Cache::put($cacheCountKey, count($existingDevices), now()->addMinutes(1));
        }

        return response()->json([
            'message' => 'Stored',
            'count' => Cache::get($cacheCountKey, 0),
        ]);
    }
}
