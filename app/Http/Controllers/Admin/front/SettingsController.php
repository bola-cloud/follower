<?php

namespace App\Http\Controllers\Admin\front;

use App\Http\Controllers\Controller;
use App\Models\FrontSetting as Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Show settings form.
     */
    public function index()
    {
        $settings = [
            'app_name' => Setting::get('app_name', 'ReactX'),
            'app_description' => Setting::get('app_description', ''),
            'seo_title' => Setting::get('seo_title', 'Download Official EGFollow APK | Free Instagram Followers'),
            'seo_description' => Setting::get('seo_description', 'Get instant Instagram reacts and followers with Egfollow. Safe, secure, and free exchange platform for social media growth.'),
            'seo_keywords' => Setting::get('seo_keywords', ''),
            'app_logo' => Setting::get('app_logo', null),
        ];

        return view('reactx.settings', compact('settings'));
    }

    /**
     * Update settings.
     */
    public function update(Request $request)
    {
        // Validate input
        $validated = $request->validate([
            'app_name' => 'required|string|max:255',
            'app_description' => 'nullable|string|max:1000',
            'seo_title' => 'required|string|max:255',
            'seo_description' => 'required|string|max:500',
            'seo_keywords' => 'nullable|string|max:500',
            'app_logo' => 'nullable|image|mimes:jpeg,png,gif,webp|max:2048',
        ]);

        // Handle logo upload
        if ($request->hasFile('app_logo')) {
            // Delete old logo if exists
            $oldLogo = Setting::get('app_logo');
            if ($oldLogo && Storage::disk('public')->exists($oldLogo)) {
                Storage::disk('public')->delete($oldLogo);
            }

            // Store new logo
            $logoPath = $request->file('app_logo')->store('settings', 'public');
            Setting::set('app_logo', $logoPath);
        }

        // Update other settings
        Setting::set('app_name', $validated['app_name']);
        Setting::set('app_description', $validated['app_description'] ?? '');
        Setting::set('seo_title', $validated['seo_title']);
        Setting::set('seo_description', $validated['seo_description']);
        Setting::set('seo_keywords', $validated['seo_keywords'] ?? '');

        return redirect()->back()->with('success', 'Settings updated successfully!');
    }

    /**
     * Get all settings as JSON (for public use).
     */
    public function getPublic()
    {
        return response()->json([
            'app_name' => Setting::get('app_name', 'ReactX'),
            'app_description' => Setting::get('app_description', ''),
            'seo_title' => Setting::get('seo_title', ''),
            'seo_description' => Setting::get('seo_description', ''),
            'app_logo' => Setting::get('app_logo') ? asset('storage/' . Setting::get('app_logo')) : null,
        ]);
    }
}
