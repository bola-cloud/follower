<?php

namespace App\Http\Controllers\Admin\front;

use App\Http\Controllers\Controller;
use App\Models\Apk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ApkController extends Controller
{
    /**
     * Store a newly uploaded APK file.
     */
    public function store(Request $request)
    {
        // Use manual validator so we can return errors as JSON for AJAX requests
        $rules = [
            'version' => 'required|string|unique:apks,version',
            // validate as a file and size only; we'll check extension/mime manually because some servers
            // may not report the APK mime type consistently
            'apk_file' => 'required|file|max:153600', // 150MB (in kilobytes)
            'play_url' => 'nullable|url',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        // Check the uploaded file is present and valid. If PHP limits are exceeded, the file may be missing.
        if (! $request->hasFile('apk_file') || ! $request->file('apk_file')->isValid()) {
            $msg = 'APK file is missing or invalid. Check PHP `upload_max_filesize` and `post_max_size` settings.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $msg], 422);
            }
            return redirect()->back()->with('error', $msg)->withInput();
        }

        // Additional check: some servers/clients may not present the mime type as 'apk',
        // so validate extension or accepted mime types explicitly
        $file = $request->file('apk_file');
        $ext = strtolower($file->getClientOriginalExtension() ?? '');
        $mime = strtolower($file->getClientMimeType() ?? '');
        $allowedMimes = [
            'application/vnd.android.package-archive',
            'application/octet-stream',
            'application/zip',
            'application/x-zip-compressed'
        ];

        if ($ext !== 'apk' && ! in_array($mime, $allowedMimes, true)) {
            $msg = 'The apk file must be a valid APK file.';
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => $msg, 'detected_ext' => $ext, 'detected_mime' => $mime], 422);
            }
            return redirect()->back()->with('error', $msg)->withInput();
        }

        try {
            // Store the APK file
            $file = $request->file('apk_file');
            $fileName = time() . '_' . $file->getClientOriginalName();
            $filePath = $file->storeAs('apks', $fileName, 'public');

            // Get file size in bytes
            $fileSize = Storage::disk('public')->size($filePath);

            // Create APK record. New uploads default to 'pending' so admin can choose active one.
            $apk = Apk::create([
                'version' => $request->version,
                'file_name' => $fileName,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'play_store_url' => $request->play_url ?? null,
                'status' => 'pending',
                'download_count' => 0,
            ]);

            return redirect()->route('admin.reactx.dashboard')
                ->with('success', "APK v{$apk->version} uploaded successfully!");
        } catch (\Exception $e) {
            return redirect()->route('admin.reactx.dashboard')
                ->with('error', 'Failed to upload APK: ' . $e->getMessage());
        }
    }

    /**
     * Download APK file and increment download count.
     */
    public function download($id)
    {

        $apk = Apk::findOrFail($id);

        // Get user identifier (IP + User-Agent) to prevent duplicate counts from same client
        $userIdentifier = md5($_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT'] ?? '');
        $cacheKey = "download_{$id}_{$userIdentifier}";

        // Only increment if this user hasn't downloaded this APK in the last 30 seconds
        if (!cache()->has($cacheKey)) {
            $apk->increment('download_count');
            cache()->put($cacheKey, true, now()->addSeconds(30));
        }

        // Get file path
        $filePath = storage_path('app/public/' . $apk->file_path);

        if (!file_exists($filePath)) {
            abort(404, 'File not found');
        }

        // Return file for download
        return response()->download($filePath, $apk->file_name);

        // $apk = Apk::findOrFail($id);

        // // Increment download count
        // $apk->increment('download_count');

        // // Get file path
        // $filePath = storage_path('app/public/' . $apk->file_path);

        // if (!file_exists($filePath)) {
        //     abort(404, 'File not found');
        // }

        // // Return file for download
        // return response()->download($filePath, $apk->file_name);
    }

    /**
     * Delete an APK file.
     */
    public function destroy(Request $request, $id)
    {
        $apk = Apk::findOrFail($id);

        // Delete file from storage
        if (Storage::disk('public')->exists($apk->file_path)) {
            Storage::disk('public')->delete($apk->file_path);
        }

        $apk->delete();

        // If this was an AJAX request, return JSON so the frontend can display messages
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['message' => 'APK deleted successfully!'], 200);
        }

        return redirect()->route('admin.reactx.dashboard')
            ->with('success', 'APK deleted successfully!');
    }

    /**
     * Get APK statistics for dashboard.
     */
    public function getStats()
    {
        $apks = Apk::all();
        $totalDownloads = $apks->sum('download_count');
        $liveApks = $apks->where('status', 'live')->count();

        return response()->json([
            'total_downloads' => $totalDownloads,
            'active_apks' => $liveApks,
            'crash_rate' => '0.12%',
        ]);
    }

    /**
     * Get all APKs as JSON (for admin dashboard).
     */
    public function getAllApks()
    {
        // Return live APKs first so the frontend can pick the active APK as the primary download
        $apks = Apk::orderByRaw("(status = 'live') DESC")->orderBy('created_at', 'desc')->get();

        return response()->json($apks);
    }

    /**
     * Activate a single APK and deactivate others.
     */
    public function activate(Request $request, $id)
    {
        $apk = Apk::findOrFail($id);

        // Only allow this to be done by admin middleware (route group already protects it)
        try {
            DB::transaction(function () use ($apk) {
                // Demote any currently live APKs to 'pending' (avoid invalid enum values)
                Apk::where('status', 'live')->where('id', '!=', $apk->id)->update(['status' => 'pending']);

                // Promote selected APK to live
                $apk->status = 'live';
                $apk->save();
            });

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'APK activated successfully', 'apk' => $apk], 200);
            }

            return redirect()->route('admin.reactx.dashboard')->with('success', 'APK activated successfully');
        } catch (\Exception $e) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['message' => 'Failed to activate APK: ' . $e->getMessage()], 500);
            }
            return redirect()->route('admin.reactx.dashboard')->with('error', 'Failed to activate APK: ' . $e->getMessage());
        }
    }
}
