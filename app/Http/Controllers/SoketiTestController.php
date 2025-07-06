<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Events\OrderCreated;
use App\Events\ActionResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SoketiTestController extends Controller
{
    public function triggerTestOrder(Request $request)
    {
        try {
            $user = $request->user() ?? User::find(1);
            if (!$user) {
                return response()->json(['error' => 'No authenticated user or test user found.'], 401);
            }

            $order = Order::create([
                'type' => 'follow',
                'total_count' => 10,
                'done_count' => 0,
                'cost' => 10,
                'status' => 'active',
                'target_url' => 'https://www.instagram.com/testuser/',
                'target_url_hash' => sha1('https://www.instagram.com/testuser/'),
                'user_id' => $user->id,
            ]);

            event(new OrderCreated($order));

            return response()->json([
                'message' => 'Test OrderCreated event triggered.',
                'order' => $order,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Test order failed:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to trigger test order.', 'details' => $e->getMessage()], 500);
        }
    }

    public function triggerTestResponse(Request $request)
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'order_id' => 'required|exists:orders,id',
            'user_id' => 'required|exists:users,id',
            'status' => 'required|in:success,failed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();

        try {
            event(new ActionResponse($payload));

            return response()->json([
                'message' => 'Test ActionResponse event triggered.',
                'payload' => $payload,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Test response failed:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to trigger test response.', 'details' => $e->getMessage()], 500);
        }
    }

    public function getActiveUsersCount(Request $request)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . env('PUSHER_APP_SECRET'),
            ])->get('http://127.0.0.1:6001/api/channels/presence.active.users');

            if ($response->failed()) {
                $errorDetails = $response->json() ?? $response->body();
                Log::error('Failed to fetch active users from Soketi:', [
                    'status' => $response->status(),
                    'body' => $errorDetails,
                ]);
                return response()->json([
                    'error' => 'Failed to fetch active users count.',
                    'details' => $errorDetails,
                ], 500);
            }

            $data = $response->json();
            $count = $data['count'] ?? 0;

            Log::info('Active users count retrieved:', [
                'count' => $count,
                'user_id' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Active users count retrieved.',
                'active_users_count' => $count,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch active users count:', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to fetch active users count.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}