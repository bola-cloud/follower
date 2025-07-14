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
            'status' => 'required|in:active',
        ]);

        $deviceId = $validated['device_id'];
        $cacheSetKey = 'device_activations_set';
        $cacheCountKey = 'device_activations_count';

        $existingDevices = Cache::get($cacheSetKey, []);

        if (!in_array($deviceId, $existingDevices)) {
            $existingDevices[] = $deviceId;
            Cache::put($cacheSetKey, $existingDevices, now()->addMinutes(2));
            Cache::put($cacheCountKey, count($existingDevices), now()->addMinutes(2));
        }

        return response()->json([
            'message' => 'Stored',
            'count' => Cache::get($cacheCountKey, 0),
        ]);
    }

}
