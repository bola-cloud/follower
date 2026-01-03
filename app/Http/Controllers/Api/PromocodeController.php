<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Promocode;

class PromocodeController extends Controller
{
    public function redeem(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $promocode = Promocode::where('code', $request->code)->first();

        if (!$promocode) {
            return response()->json(['error' => 'Invalid promocode.'], 404);
        }

        if ($promocode->expires_at && $promocode->expires_at->isPast()) {
            return response()->json(['error' => 'Promocode expired.'], 410);
        }

        if ($promocode->max_uses > 0 && $promocode->uses_count >= $promocode->max_uses) {
            return response()->json(['error' => 'Promocode usage limit reached.'], 410);
        }

        // Check if user already used this code
        if ($promocode->users()->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'You have already used this promocode.'], 409);
        }

        // Backward compatibility: if old system marked it used
        if ($promocode->used_by && $promocode->max_uses == 1) {
            return response()->json(['error' => 'Promocode already used.'], 409);
        }

        // Redeem points
        $user->increment('points', $promocode->points);

        $promocode->increment('uses_count');
        $promocode->users()->attach($user->id, ['used_at' => now()]);

        // Keep 'used_by' null for multi-use, or set it if it's the first user? 
        // Better to rely on pivot. We only update `used_by` if specific legacy behavior needed, but let's ignore it for new codes.

        return response()->json([
            'message' => 'Promocode redeemed successfully.',
            'added_points' => $promocode->points,
            'total_points' => $user->points,
        ]);
    }
}
