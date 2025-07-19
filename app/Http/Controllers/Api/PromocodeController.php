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

        if ($promocode->used_by) {
            return response()->json(['error' => 'Promocode already used.'], 409);
        }

        // Redeem points
        $user->increment('points', $promocode->points);

        $promocode->update([
            'activated_at' => now(),
            'used_by' => $user->id,
        ]);

        return response()->json([
            'message' => 'Promocode redeemed successfully.',
            'added_points' => $promocode->points,
            'total_points' => $user->points,
        ]);
    }
}
