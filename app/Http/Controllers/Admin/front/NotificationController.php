<?php

namespace App\Http\Controllers\Admin\front;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\PushNotification;
use App\Jobs\SendFcmNotification;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = PushNotification::orderBy('created_at', 'desc')->paginate(20);
        return view('admin.notifications.index', compact('notifications'));
    }

    public function create()
    {
        return view('admin.notifications.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
        ]);

        $record = PushNotification::create([
            'admin_user_id' => auth()->id(),
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'data' => $request->input('data') ? json_decode($request->input('data'), true) : null,
            'status' => 'pending',
        ]);

        // dispatch job to send
        SendFcmNotification::dispatch($record->id);

        return redirect()->route('admin.notifications.index')->with('success', 'Notification queued for sending');
    }

    public function show(PushNotification $notification)
    {
        return view('admin.notifications.show', compact('notification'));
    }

    public function resend(PushNotification $notification)
    {
        $notification->status = 'pending';
        $notification->save();
        SendFcmNotification::dispatch($notification->id);
        return back()->with('success', 'Notification re-queued');
    }
}
