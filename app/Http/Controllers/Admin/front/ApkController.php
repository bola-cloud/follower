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
     * Store a newly uploaded APK file or update external link.
     */
    public function store(Request $request)
    {
        // Use manual validator so we can return errors as JSON for AJAX requests
        $rules = [
            'version' => 'required|string',
            'apk_link' => 'required|url',
            'play_url' => 'nullable|url',
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
            return redirect()->back()->withErrors($validator)->withInput();
        }

        try {
            // Save external link to settings
            \App\Models\FrontSetting::set('apk_external_link', $request->apk_link);

            // Create a dummy APK record for version tracking / stats if needed, 
            // or just rely on settings. For now, we'll create a record to maintain compatibility
            // with existing stats logic, but mark it as 'external'
            $apk = Apk::create([
                'version' => $request->version,
                'file_name' => 'external_link',
                'file_path' => $request->apk_link, // Storing link in path for reference
                'file_size' => 0,
                'play_store_url' => $request->play_url ?? null,
                'status' => 'live',
                'download_count' => 0,
            ]);

            // Deactivate other APKs since this is now the live one
            Apk::where('status', 'live')->where('id', '!=', $apk->id)->update(['status' => 'pending']);

            return redirect()->route('admin.reactx.dashboard')
                ->with('success', "APK Link v{$apk->version} updated successfully!");
        } catch (\Exception $e) {
            return redirect()->route('admin.reactx.dashboard')
                ->with('error', 'Failed to update APK Link: ' . $e->getMessage());
        }
    }

    /**
     * Download APK file and increment download count.
     */
    public function downloadLatest()
    {
        // Check for external link first
        $externalLink = \App\Models\FrontSetting::get('apk_external_link');
        if ($externalLink) {
            // Increment a general counter if needed, or just redirect
            // For now, we just redirect to the external URL
            return redirect()->away($externalLink);
        }

        $apk = Apk::where('status', 'live')->orderBy('created_at', 'desc')->first();

        if (!$apk) {
            // Fallback to any APK if no active one found, or 404
            $apk = Apk::orderBy('created_at', 'desc')->firstOrFail();
        }

        return $this->download($apk->id);
    }

    /**
     * Download APK file and increment download count.
     */
    public function download($id)
    {
        // Check for external link first
        $externalLink = \App\Models\FrontSetting::get('apk_external_link');
        if ($externalLink) {
            return redirect()->away($externalLink);
        }

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

        // Return file for download with explicit headers to prevent .zip renaming
        $headers = [
            'Content-Type' => 'application/vnd.android.package-archive',
        ];

        return response()->download($filePath, $apk->file_name, $headers);
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
     * Accept a single chunk for an ongoing upload.
     * Expects: upload_id, chunk_index, chunk (file), total_chunks (optional), file_name (optional)
     */
    public function uploadChunk(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer',
            'chunk' => 'required|file',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploadId = $request->input('upload_id');
        $index = (int) $request->input('chunk_index');

        $file = $request->file('chunk');
        $tmpDir = 'apk_uploads/tmp';
        $tmpName = $uploadId . '_' . $index;

        // Store chunk on local disk (storage/app)
        Storage::disk('local')->putFileAs($tmpDir, $file, $tmpName);

        return response()->json(['ok' => true, 'index' => $index], 200);
    }

    /**
     * Complete chunked upload: assemble chunks and create APK record.
     * Expects: upload_id, total_chunks, version, play_url (optional), original_file_name
     */
    public function completeChunkUpload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'total_chunks' => 'required|integer|min:1',
            'version' => 'required|string|unique:apks,version',
            'original_file_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $uploadId = $request->input('upload_id');
        $total = (int) $request->input('total_chunks');
        $originalFileName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $request->input('original_file_name'));

        $tmpDir = storage_path('app/apk_uploads/tmp');
        $finalName = time() . '_' . $originalFileName;
        $finalRelPath = 'apks/' . $finalName;

        // Assemble chunks
        try {
            $finalPath = storage_path('app/public/' . $finalRelPath);
            $out = fopen($finalPath, 'wb');
            if ($out === false) {
                throw new \Exception('Unable to open final file for writing');
            }

            for ($i = 0; $i < $total; $i++) {
                $chunkPath = $tmpDir . DIRECTORY_SEPARATOR . $uploadId . '_' . $i;
                if (!file_exists($chunkPath)) {
                    fclose($out);
                    throw new \Exception("Missing chunk {$i}");
                }
                $in = fopen($chunkPath, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);

            // Get file size
            $fileSize = filesize($finalPath);

            // Move to public storage (already in storage/app/public)
            $apk = Apk::create([
                'version' => $request->input('version'),
                'file_name' => $finalName,
                'file_path' => $finalRelPath,
                'file_size' => $fileSize,
                'play_store_url' => $request->input('play_url') ?? null,
                'status' => 'pending',
                'download_count' => 0,
            ]);

            // Clean up tmp chunks
            for ($i = 0; $i < $total; $i++) {
                $chunkPath = $tmpDir . DIRECTORY_SEPARATOR . $uploadId . '_' . $i;
                @unlink($chunkPath);
            }

            return response()->json(['ok' => true, 'apk' => $apk], 200);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to assemble upload: ' . $e->getMessage()], 500);
        }
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
