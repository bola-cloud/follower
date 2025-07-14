<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class Dashboard extends Controller
{
    public function index(Request $request)
    {
        // ✅ 1. Clear previous cache (set expires in controller, optional)
        Cache::put('device_activations_set', [], now()->addMinutes(2));
        Cache::put('device_activations_count', 0, now()->addMinutes(2));

        // ✅ 2. Run the Node.js script to trigger MQTT
        $process = new Process(['node', base_path('node_scripts/mqtt_ping_devices.cjs')]);

        try {
            $process->mustRun();
            logger('✅ MQTT ping script executed successfully');
        } catch (ProcessFailedException $e) {
            logger()->error('❌ MQTT ping failed: ' . $e->getMessage());
        }

        return view('admin.dashboard');
    }
}
