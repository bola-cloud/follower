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
            // allow sentinel '__none__' or integer user id or empty string
            'preferred_cookie_user_id' => ['nullable'],
        ]);

        // Convert sentinel '__none__' to null to indicate 'do not use cookies'
        if (array_key_exists('preferred_cookie_user_id', $validated)) {
            $v = $validated['preferred_cookie_user_id'];
            if ($v === '__none__') {
                $validated['preferred_cookie_user_id'] = null;
            } elseif ($v === '') {
                // keep empty string as-is to mean 'no preference (random)'
                $validated['preferred_cookie_user_id'] = '';
            } else {
                // ensure integer or null
                $validated['preferred_cookie_user_id'] = is_numeric($v) ? (int)$v : null;
            }
        }

        foreach ($validated as $key => $value) {
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return redirect()->route('admin.settings.index')->with('success', 'تم تحديث الإعدادات بنجاح');
    }
}
