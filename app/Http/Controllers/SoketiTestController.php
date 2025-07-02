<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Events\TestBroadcast;

class SoketiTestController extends Controller
{
    public function trigger(Request $request)
    {
        $message = $request->input('message', 'Test Broadcast at 01:30 AM');
        broadcast(new TestBroadcast($message));

        return response()->json(['status' => 'Broadcast triggered', 'message' => $message]);
    }
}
