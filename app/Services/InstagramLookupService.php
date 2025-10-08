<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramLookupService
{
    /**
     * Try to resolve mediaId (for like) or userPk (for follow) using up to $tries users with cookies.
     * Returns the found string or null.
     */
    public function resolve(string $target, string $type, int $tries = 5): ?string
    {
        // Determine what to lookup
        Log::info('[InstagramLookup] resolve called', ['target' => $target, 'type' => $type, 'tries' => $tries]);
        if ($type === 'like') {
            // target may be shortcode or full url; extract shortcode
            $shortcode = $this->extractShortcode($target) ?? $target;
            Log::info('[InstagramLookup] resolving mediaId', ['shortcode' => $shortcode]);
            if (!$shortcode) return null;
            return $this->getMediaIdFromShortcodeWithCookies($shortcode, $tries);
        }

        if ($type === 'follow') {
            $username = $this->extractUsername($target) ?? $target;
            Log::info('[InstagramLookup] resolving userPk', ['username' => $username]);
            if (!$username) return null;
            return $this->getUserPkWithCookies($username, $tries);
        }

        return null;
    }

    protected function getMediaIdFromShortcodeWithCookies(string $shortcode, int $tries): ?string
    {
        $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
        Log::info('[InstagramLookup] getMediaIdFromShortcodeWithCookies users_found', ['count' => $users->count(), 'shortcode' => $shortcode]);
        foreach ($users as $u) {
            Log::info('[InstagramLookup] trying user for mediaId', ['user_id' => $u->id]);
            $cookieHeader = $this->buildCookieHeader($u->cookies);
            $csrf = $this->extractCsrfTokenFromCookies($cookieHeader);
            // Log cookie keys present without values to avoid leaking secrets
            if (is_string($u->cookies)) {
                $cookieKeys = preg_split('/;\s*/', $u->cookies);
                $cookieSummary = array_map(function($p){ return preg_replace('/=.*/','', $p); }, $cookieKeys);
            } else if (is_array($u->cookies)) {
                $cookieSummary = array_keys($u->cookies);
            } else {
                $cookieSummary = [];
            }
            Log::info('[InstagramLookup] cookie summary', ['user_id' => $u->id, 'cookie_keys' => $cookieSummary, 'has_csrf' => $csrf ? true : false]);
            if (!$cookieHeader || !$csrf) {
                Log::warning('[InstagramLookup] skipping user due to missing cookie/header', ['user_id' => $u->id]);
                continue;
            }

            try {
                $variables = [
                    'shortcode' => $shortcode,
                    'fetch_tagged_user_count' => null,
                    'hoisted_comment_id' => null,
                    'hoisted_reply_id' => null,
                ];
                $body = [
                    'variables' => json_encode($variables),
                    'doc_id' => '8845758582119845',
                ];

                $start = microtime(true);
                $resp = Http::asForm()->withHeaders([
                    'authority' => 'www.instagram.com',
                    'accept' => '*/*',
                    'content-type' => 'application/x-www-form-urlencoded',
                    'cookie' => $cookieHeader,
                    'user-agent' => 'Instagram 244.0.0.17.110 (iPhone; CPU iPhone OS 16_0 like Mac OS X)',
                    'x-ig-app-id' => '936619743392459',
                    'x-requested-with' => 'XMLHttpRequest',
                    'x-csrftoken' => $csrf,
                ])->post('https://www.instagram.com/graphql/query', $body);

                $duration = round((microtime(true) - $start) * 1000);
                Log::info('[InstagramLookup] mediaId request completed', ['user_id' => $u->id, 'status' => $resp->status(), 'duration_ms' => $duration]);
                if ($resp->ok()) {
                    $json = $resp->json();
                    // Attempt to extract media id similar to the Flutter code
                    $mediaId = $json['data']['xdt_shortcode_media']['id'] ?? null;
                    if ($mediaId) {
                        Log::info('[InstagramLookup] mediaId found', ['user_id' => $u->id, 'mediaId' => $mediaId]);
                        return (string)$mediaId;
                    }
                } else {
                    Log::warning('[InstagramLookup] mediaId request non-OK', ['user_id' => $u->id, 'status' => $resp->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('[InstagramLookup] mediaId request failed', ['user_id' => $u->id, 'error' => $e->getMessage()]);
                continue;
            }
        }

        return null;
    }

    protected function getUserPkWithCookies(string $username, int $tries): ?string
    {
        $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
        Log::info('[InstagramLookup] getUserPkWithCookies users_found', ['count' => $users->count(), 'username' => $username]);
        foreach ($users as $u) {
            Log::info('[InstagramLookup] trying user for userPk', ['user_id' => $u->id]);
            $cookieHeader = $this->buildCookieHeader($u->cookies);
            $csrf = $this->extractCsrfTokenFromCookies($cookieHeader);
            // summarize cookie keys, do not log values
            if (is_string($u->cookies)) {
                $cookieKeys = preg_split('/;\s*/', $u->cookies);
                $cookieSummary = array_map(function($p){ return preg_replace('/=.*/','', $p); }, $cookieKeys);
            } else if (is_array($u->cookies)) {
                $cookieSummary = array_keys($u->cookies);
            } else {
                $cookieSummary = [];
            }
            Log::info('[InstagramLookup] cookie summary', ['user_id' => $u->id, 'cookie_keys' => $cookieSummary, 'has_csrf' => $csrf ? true : false]);
            if (!$cookieHeader) {
                Log::warning('[InstagramLookup] skipping user for userPk: missing cookieHeader', ['user_id' => $u->id]);
                continue;
            }

            try {
                $url = "https://www.instagram.com/api/v1/users/web_profile_info/?username={$username}";
                $start = microtime(true);
                $resp = Http::withHeaders([
                    'authority' => 'www.instagram.com',
                    'accept' => 'application/json',
                    'cookie' => $cookieHeader,
                    'user-agent' => 'Instagram 244.0.0.17.110',
                    'x-csrftoken' => $csrf ?? '',
                ])->get($url);
                $duration = round((microtime(true) - $start) * 1000);
                Log::info('[InstagramLookup] userPk request completed', ['user_id' => $u->id, 'status' => $resp->status(), 'duration_ms' => $duration]);
                if ($resp->ok()) {
                    $json = $resp->json();
                    $userPk = $json['data']['user']['id'] ?? null;
                    if ($userPk) {
                        Log::info('[InstagramLookup] userPk found', ['user_id' => $u->id, 'userPk' => $userPk]);
                        return (string)$userPk;
                    }
                } else {
                    Log::warning('[InstagramLookup] userPk request non-OK', ['user_id' => $u->id, 'status' => $resp->status()]);
                }
            } catch (\Throwable $e) {
                Log::warning('[InstagramLookup] userPk request failed', ['user_id' => $u->id, 'error' => $e->getMessage()]);
                continue;
            }
        }

        return null;
    }

    protected function buildCookieHeader($cookies): ?string
    {
        if (!$cookies) return null;
        // cookies might be stored as array ['csrftoken' => 'a', 'sessionid' => 'b'] or as string
        if (is_string($cookies)) return $cookies;
        if (is_array($cookies)) {
            $parts = [];
            foreach ($cookies as $k => $v) {
                if ($v === null) continue;
                $parts[] = $k . '=' . $v;
            }
            return implode('; ', $parts);
        }
        return null;
    }

    protected function extractCsrfTokenFromCookies(?string $cookieString): ?string
    {
        if (!$cookieString) return null;
        if (preg_match('/csrftoken=([^;]+)/', $cookieString, $m)) {
            return $m[1];
        }
        return null;
    }

    protected function extractShortcode(string $urlOrCode): ?string
    {
        // If it's a full URL, extract the shortcode after /p/ or /reel/
        try {
            $parts = parse_url($urlOrCode);
            if (!empty($parts['path'])) {
                $segments = array_values(array_filter(explode('/', $parts['path'])));
                foreach ($segments as $i => $seg) {
                    if (in_array($seg, ['p', 'reel']) && isset($segments[$i+1])) {
                        return $segments[$i+1];
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        // Otherwise assume input was shortcode
        return $urlOrCode ?: null;
    }

    protected function extractUsername(string $urlOrUser): ?string
    {
        // If it's a full URL, take first path segment
        try {
            $parts = parse_url($urlOrUser);
            if (!empty($parts['path'])) {
                $segments = array_values(array_filter(explode('/', $parts['path'])));
                if (count($segments) > 0) return $segments[0];
            }
        } catch (\Throwable $e) {}
        // else assume input is username
        return $urlOrUser ?: null;
    }
}
