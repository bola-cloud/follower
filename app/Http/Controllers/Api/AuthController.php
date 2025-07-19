<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation errors',
                'status' => false,
                'data' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User registered successfully',
            'status' => true,
            'data' => [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        if (!auth()->attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details',
                'status' => false,
                'data' => null,
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'status' => true,
            'data' => [
                'user' => $user,
                'access_token' => $token,
                'token_type' => 'Bearer',
            ],
        ], 200);
    }


    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out',
            'status' => true,
            'data' => null,
        ], 200);
    }


    public function googleLogin(Request $request)
    {
        $data = $request->only(['google_id', 'name', 'email']);

        $validator = Validator::make($data, [
            'google_id' => 'required|string',
            'name' => 'required|string',
            'email' => 'nullable|email|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Try to find user by google_id
        $user = User::where('google_id', $data['google_id'])->first();

        if (!$user) {
            // Create new user
            $user = User::create([
                'google_id' => $data['google_id'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'profile_link' => $data['profile_link'] ?? null,
                'points' => 0,
            ]);
        } else {
            // Update missing email if previously null and provided now
            if (empty($user->email) && !empty($data['email'])) {
                $user->update(['email' => $data['email']]);
            }
        }

        // Create API token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function updateProfileLink(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'profile_link' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $newProfile = $request->input('profile_link');

        // If user already has a profile link, ensure it's the same
        if (!empty($user->profile_link)) {
            if ($user->profile_link !== $newProfile) {
                return response()->json([
                    'error' => 'This account username does not match your previously linked account.'
                ], 409); // Conflict
            }

            return response()->json([
                'message' => 'Profile link already set and matches.',
                'profile_link' => $user->profile_link,
            ], 200);
        }

        // If user doesn't have a profile link, ensure the new one isn't already used
        $exists = \App\Models\User::where('profile_link', $newProfile)->exists();

        if ($exists) {
            return response()->json([
                'error' => 'This profile link is already taken by another user.'
            ], 409);
        }

        // Save new profile_link
        $user->profile_link = $newProfile;
        $user->save();

        return response()->json([
            'message' => 'Profile link successfully updated.',
            'profile_link' => $user->profile_link,
        ], 200);
    }

    public function points(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        return response()->json([
            'points' => $user->points,
            'timer' => $user->timer, // Assuming you have a timer field
        ]);
    }

}
