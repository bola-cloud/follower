<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\TestBroadcast;

class SoketiTestController extends Controller
{
    public function triggerTestEvent(Request $request)
    {
        // Get message from request or set a default one
        $message = $request->input('message', 'Hello, WebSocket!');

        // Trigger the event
        event(new TestBroadcast($message));

        // Return a response
        return response()->json(['status' => 'success', 'message' => $message]);
    }
}
