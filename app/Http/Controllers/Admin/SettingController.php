<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index()
    {
        return view('admin.settings.index');
    }

    /**
     * Update the settings.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'points_per_follow' => 'required|integer',
            'points_per_like' => 'required|integer',
            'app_version' => 'required|string',
            'download_link' => 'required|url',
            'mandatory' => 'required|boolean',
            'build_number' => 'required|integer',
            'added_points' => 'required|integer',
        ]);

        foreach ($validated as $key => $value) {
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return redirect()->route('admin.settings.index')->with('success', 'تم تحديث الإعدادات بنجاح');
    }
}
