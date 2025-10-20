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

        // Keep sentinel '__none__' stored as a flag (do not use cookies).
        // Database 'value' column may be non-nullable, so avoid storing PHP null here.
        if (array_key_exists('preferred_cookie_user_id', $validated)) {
            $v = $validated['preferred_cookie_user_id'];
            if ($v === '__none__') {
                // store sentinel string
                $validated['preferred_cookie_user_id'] = '__none__';
            } elseif ($v === '') {
                // keep empty string as-is to mean 'no preference (random)'
                $validated['preferred_cookie_user_id'] = '';
            } else {
                // ensure integer-like string (store as string to keep DB types consistent)
                $validated['preferred_cookie_user_id'] = is_numeric($v) ? (string)intval($v) : '';
            }
        }

        foreach ($validated as $key => $value) {
            \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return redirect()->route('admin.settings.index')->with('success', 'تم تحديث الإعدادات بنجاح');
    }
}
