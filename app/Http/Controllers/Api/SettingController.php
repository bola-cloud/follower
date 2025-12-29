<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Setting;

class SettingController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        // Ensure critical defaults are sent even if not in DB yet, and cast to correct types
        $settings['referral_points'] = (int) setting('referral_points', 50);
        $settings['points_add_delay'] = (int) setting('points_add_delay', 30);

        return response()->json([
            'settings' => $settings
        ]);
    }
}
