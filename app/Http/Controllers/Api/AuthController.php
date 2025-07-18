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
        $data = $request->only(['google_id', 'name', 'profile_link']);

        $validator = Validator::make($data, [
            'google_id' => 'required|string|unique:users,google_id',
            'name' => 'required|string',
            'profile_link' => 'nullable|unique:users,profile_link',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Find or create user
        $user = User::firstOrCreate(
            ['google_id' => $data['google_id']],
            [
                'name' => $data['name'] ?? 'No Name',
                'profile_link' => $data['profile_link'] ?? null,
                'points' => 0,
            ]
        );

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

        // Validate input
        $validator = Validator::make($request->all(), [
            'profile_link' => ['required', 'string', 'unique:users,profile_link'], // Instagram username style
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
            ],200);
        }

        // Save new profile_link
        $user->profile_link = $newProfile;
        $user->save();

        return response()->json([
            'message' => 'Profile link successfully updated.',
            'profile_link' => $user->profile_link,
        ],200);
    }
}
