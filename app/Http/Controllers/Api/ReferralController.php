<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\UserReferral;

class ReferralController extends Controller
{
    public function apply(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $user = $request->user();
        $code = strtoupper(trim($request->input('code')));

        if (DB::table('user_referrals')->where('user_id', $user->id)->exists()) {
            return response()->json(['error' => 'Referral code already used'], 400);
        }

        $referrer = User::where('invitation_code', $code)->first();
        if (!$referrer) {
            return response()->json(['error' => 'Invalid referral code'], 404);
        }

        if ($referrer->id === $user->id) {
            return response()->json(['error' => 'Cannot use your own code'], 400);
        }

        $pointsToAdd = (int) setting('referral_points', 50);

        try {
            DB::transaction(function () use ($user, $referrer, $code, $pointsToAdd) {
                UserReferral::create([
                    'user_id' => $user->id,
                    'referrer_id' => $referrer->id,
                    'code_used' => $code,
                ]);

                // Award points to the referrer (code owner) NOT the applicant
                $referrer->increment('points', $pointsToAdd);
            });
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Failed to apply referral code'], 500);
        }

        return response()->json(['added_points' => $pointsToAdd, 'total_points' => $user->fresh()->points]);
    }
}
