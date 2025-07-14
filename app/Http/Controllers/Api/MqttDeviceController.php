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

        $key = 'device_activations_count';

        // You can track as a counter (Redis or Cache)
        Cache::increment($key);

        return response()->json(['message' => 'Stored', 'count' => Cache::get($key)]);
    }
}
