<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
            // Validate email only if user does not exist
            $validator = Validator::make($data, [
                'email' => 'nullable|email|max:255|unique:users,email',
                'google_id' => 'required|string|unique:users,google_id',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            // Create new user
            $user = User::create([
                'google_id' => $data['google_id'],
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'profile_link' => $data['profile_link'] ?? null,
                'points' => 0,
                'timer' => now()->addMinutes(30), // set timer column
            ]);

            // Dispatch job to add points after 30 minutes
            \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));
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

    /**
     * Update Profile Link V2
     * - If the requested link matches the current one -> 200 (no-op)
     * - If the user has no profile_link -> set it and return 200
     * - If the user already has a profile_link and requested one is free ->
     *     perform a "reassign" (create new user with same data except name/profile_link/cookies)
     *     and return 201
     * - If requested link is taken by another user -> 409
     *
     * Expected payload: { name, profile_link }
     */
    public function updateProfileLinkV2(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        $data = $request->only(['profile_link']);
        $validator = Validator::make($data, [
            'profile_link' => 'required|string|max:255',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation errors', 'status' => false, 'data' => $validator->errors()], 422);
        }

        $requested = $data['profile_link'];

        // Case 1: already set and matches
        if (!empty($user->profile_link) && $user->profile_link === $requested) {
            return response()->json(['message' => 'Profile link already set and matches.', 'status' => true], 200);
        }

        // Check whether requested link is taken by someone else
        $existing = User::where('profile_link', $requested)->first();
        if ($existing && $existing->id !== $user->id) {
            // taken by another account
            return response()->json(['message' => 'This profile link is already taken by another user.', 'status' => false], 409);
        }

        // If user has no profile_link currently, simply set it and return 200
        if (empty($user->profile_link)) {
            $user->profile_link = $requested;
            $user->save();
            return response()->json(['message' => 'Profile link set.', 'status' => true, 'data' => ['user' => $user]], 200);
        }

        // Otherwise user has a profile_link and requested is free -> perform reassign
        $sourceEmail = $user->email;
        try {
            $createdUser = DB::transaction(function () use ($data, $sourceEmail, $user) {
                // reload old user
                $old = User::where('id', $user->id)->first();

                // copy metadata (not name/profile_link/cookies/password)
                $copy = [
                    'points' => $old->points ?? 0,
                    'type' => $old->type ?? 'user',
                    'timer' => $old->timer ?? null,
                ];

                // detach identifying fields on old account
                $old->google_id = null;
                $old->email = null;
                $old->profile_link = null;
                $old->save();

                // create new user with cookies=null; keep the same name as the old account
                $new = User::create([
                    'name' => $old->name,
                    'email' => $sourceEmail,
                    'password' => null,
                    'profile_link' => $data['profile_link'],
                    'points' => $copy['points'],
                    'type' => $copy['type'],
                    'timer' => $copy['timer'],
                    'cookies' => null,
                ]);

                \App\Jobs\AddPointsToUser::dispatch($new->id)->delay(now()->addMinutes(30));
                return $new;
            });
        } catch (\Throwable $e) {
            Log::error('updateProfileLinkV2 failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to reassign profile link', 'status' => false], 500);
        }

        $token = $createdUser->createToken('auth_token')->plainTextToken;
        return response()->json(['message' => 'Profile reassigned and new account created', 'status' => true, 'data' => ['user' => $createdUser, 'access_token' => $token]], 201);
    }

    public function points(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        return response()->json([
            'points' => $user->points,
            'timer' => $user->timer
                ? Carbon::parse($user->timer)->timezone('Africa/Cairo')->format('Y-m-d H:i:s')
                : null,
        ]);
    }

    public function disconnectAccount(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated.'], 401);
        }

        $user->google_id = null;
        $user->email = null;
        // $user->profile_link = null;
        $user->save();

        return response()->json([
            'message' => 'Account disconnected successfully. You can no longer log in with this Google or Instagram account.',
            'status' => true,
        ], 200);
    }

    /**
     * Detach `email` (and google_id) from any existing account that currently
     * owns it, then create a new user with the same email and provided data.
     *
     * Note: This performs a force reassign of the email address. Callers must
     * ensure this behavior is acceptable (this endpoint is destructive for the
     * previous account's email field). Wraps actions in a DB transaction.
     *
    * Expected payload: { name, password, profile_link, phone? }
     */
    public function reassignEmailAndCreate(Request $request)
    {
        // Require authenticated user (old account)
        $authUser = $request->user();
        if (! $authUser) {
            return response()->json(['message' => 'User not authenticated', 'status' => false], 401);
        }

        // Accept only the new name and profile_link in the request
        $data = $request->only(['name', 'profile_link']);
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'profile_link' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation errors', 'status' => false, 'data' => $validator->errors()], 422);
        }

        // Ensure profile_link isn't already taken
        $existsProfile = User::where('profile_link', $data['profile_link'])->exists();
        if ($existsProfile) {
            return response()->json(['message' => 'This profile link is already taken by another user.', 'status' => false], 409);
        }

        // Source email comes from the authenticated (old) account
        $sourceEmail = $authUser->email;
        if (empty($sourceEmail)) {
            return response()->json(['message' => 'Authenticated account does not have an email to transfer.', 'status' => false], 400);
        }

        try {
            $createdUser = DB::transaction(function () use ($data, $sourceEmail, $authUser) {
                // Refresh old account to avoid stale data
                $old = User::where('id', $authUser->id)->first();

                // Capture fields to copy (except name, profile_link, cookies, password)
                // We DO NOT copy passwords for Google-authenticated users.
                $copy = [
                    'points' => $old->points ?? 0,
                    'type' => $old->type ?? 'user',
                    'timer' => $old->timer ?? null,
                ];

                // Detach identifying fields from the old account so they
                // can be reused: clear email, google_id and profile_link
                $old->google_id = null;
                $old->email = null;
                $old->profile_link = null;
                $old->save();

                // Create the new user: use provided name/profile_link,
                // use the source email and copied fields, set cookies to null
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $sourceEmail,
                    'profile_link' => $data['profile_link'],
                    'points' => $copy['points'],
                    'type' => $copy['type'],
                    'timer' => $copy['timer'],
                    'cookies' => null,
                ]);

                // Optionally dispatch existing post-create job
                \App\Jobs\AddPointsToUser::dispatch($user->id)->delay(now()->addMinutes(30));

                return $user;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to reassign email and create user', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to reassign email and create user', 'status' => false], 500);
        }

        $token = $createdUser->createToken('auth_token')->plainTextToken;
        return response()->json(['message' => 'Email transferred and new user created', 'status' => true, 'data' => ['user' => $createdUser, 'access_token' => $token, 'token_type' => 'Bearer']], 201);
    }

    /**
     * Update the authenticated user's cookies.
     *
     * Payload: { cookies: { ... } }
     */
    public function updateCookies(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'User not authenticated',
                'status' => false,
            ], 401);
        }

        // Accept either an array (JSON) or a cookie header string
        $validator = Validator::make($request->all(), [
            'cookies' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation errors',
                'status' => false,
                'data' => $validator->errors(),
            ], 422);
        }

        try {
            $raw = $request->input('cookies');

            // If a string is provided (cookie header format), parse it into an associative array
            if (is_string($raw)) {
                $cookies = [];
                // Split by semicolon and parse key=value pairs
                $pairs = array_filter(array_map('trim', explode(';', $raw)));
                foreach ($pairs as $pair) {
                    // Some cookies may contain '=' in the value, so limit to 2 parts
                    $parts = explode('=', $pair, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]);
                        $v = trim($parts[1]);
                        // URL decode values like %3A
                        $v = rawurldecode($v);
                        $cookies[$k] = $v;
                    }
                }
            } elseif (is_array($raw)) {
                $cookies = $raw;
            } else {
                // Unsupported format
                return response()->json([
                    'message' => 'Unsupported cookies format',
                    'status' => false,
                ], 422);
            }

            $user->cookies = $cookies;
            $user->save();

            return response()->json([
                'message' => 'Cookies updated',
                'status' => true,
                'data' => ['user_id' => $user->id, 'cookies' => $cookies],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Failed to update user cookies', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to update cookies',
                'status' => false,
            ], 500);
        }
    }
}
