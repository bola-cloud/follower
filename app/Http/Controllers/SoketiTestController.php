<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Events\OrderCreated;
use App\Events\ActionResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SoketiTestController extends Controller
{
    /**
     * Trigger a test OrderCreated event.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function triggerTestOrder(Request $request)
    {
        try {
            // Get an authenticated user or use a test user
            $user = $request->user() ?? User::find(1); // Fallback to user ID 1 for testing
            if (!$user) {
                return response()->json(['error' => 'No authenticated user or test user found.'], 401);
            }

            // Create a test order
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

            // Trigger OrderCreated event
            event(new OrderCreated($order));

            return response()->json([
                'message' => 'Test OrderCreated event triggered.',
                'order' => $order,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger test order:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to trigger test order.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Simulate an ActionResponse event.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
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
            // Trigger ActionResponse event
            event(new ActionResponse($payload));

            return response()->json([
                'message' => 'Test ActionResponse event triggered.',
                'payload' => $payload,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger test response:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Failed to trigger test response.', 'details' => $e->getMessage()], 500);
        }
    }
}