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

    // Add a small log when no resolver was successful (helpful for debugging callers)
    protected function logNoResult(string $target, string $type)
    {
        Log::error('[InstagramLookup] no result found', ['target' => $target, 'type' => $type]);
    }

    protected function getMediaIdFromShortcodeWithCookies(string $shortcode, int $tries): ?string
    {
        // First attempt to use the admin-preferred cookie user if configured
        $preferredId = setting('preferred_cookie_user_id');
        if ($preferredId) {
            $u = User::find($preferredId);
            if ($u && $u->cookies) {
                Log::info('[InstagramLookup] trying preferred user for mediaId', ['user_id' => $u->id]);
                $cookieHeader = $this->buildCookieHeader($u->cookies);
                $csrf = $this->extractCsrfTokenFromCookies($cookieHeader);
                if ($cookieHeader && $csrf) {
                    // try preferred user first, then fall back to other cookie users if preferred fails
                    $others = User::whereNotNull('cookies')->where('id', '<>', $u->id)->inRandomOrder()->limit(max(0, $tries - 1))->get();
                    $users = collect([$u])->merge($others);
                } else {
                    Log::warning('[InstagramLookup] preferred user missing cookie/csrf, falling back', ['user_id' => $u->id]);
                    $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
                }
            } else {
                $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
            }
        } else {
            $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
        }

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
                    // If GraphQL returned errors, log truncated message and try next user
                    if (!empty($json['errors'])) {
                        $err = null;
                        try { $err = json_encode($json['errors']); } catch (\Throwable $_) { $err = null; }
                        Log::warning('[InstagramLookup] graphql errors in mediaId response', ['user_id' => $u->id, 'errors_trunc' => $err ? substr($err, 0, 200) : null]);
                        continue; // try next cookie user
                    }
                    // Attempt to extract media id using multiple possible keys (GraphQL responses vary)
                    $mediaId = null;
                    // Common keys observed: xdt_shortcode_media, shortcode_media, media, item, shortcode_media.edge_media_to_caption
                    $candidates = [
                        $json['data']['xdt_shortcode_media'] ?? null,
                        $json['data']['shortcode_media'] ?? null,
                        $json['data']['media'] ?? null,
                        $json['data']['item'] ?? null,
                    ];

                    foreach ($candidates as $cand) {
                        if (is_array($cand) && !empty($cand['id'])) {
                            $mediaId = $cand['id'];
                            break;
                        }
                    }

                    // Fallback: shallow scan for first "id" field under data
                    if (!$mediaId && is_array($json)) {
                        $found = null;
                        if (isset($json['data']) && is_array($json['data'])) {
                            array_walk_recursive($json['data'], function($v, $k) use (&$found) {
                                if ($found) return;
                                if ($k === 'id' && is_scalar($v)) {
                                    $found = $v;
                                }
                            });
                        }
                        if ($found) {
                            $mediaId = $found;
                        }
                    }

                    if ($mediaId) {
                        Log::info('[InstagramLookup] mediaId found', ['user_id' => $u->id, 'mediaId' => $mediaId]);
                        return (string)$mediaId;
                    }
                    // If not found, log diagnostic info so we can see why (keep logs compact and avoid leaking cookies)
                    $jsonTopKeys = [];
                    if (is_array($json)) {
                        $jsonTopKeys = array_keys($json);
                    }
                    $bodyLength = null;
                    try {
                        $body = $resp->body();
                        $bodyLength = is_string($body) ? strlen($body) : null;
                    } catch (\Throwable $e) {
                        $bodyLength = null;
                    }
                    Log::warning('[InstagramLookup] mediaId not found in response', [
                        'user_id' => $u->id,
                        'status' => $resp->status(),
                        'response_json_top_keys' => $jsonTopKeys,
                        'response_body_length' => $bodyLength,
                    ]);

                    // --- Fallback 1: try the public JSON endpoint ?__a=1 which sometimes
                    // returns structured data when the GraphQL doc_id path fails.
                    try {
                        $jsonEndpoints = [
                            "https://www.instagram.com/p/{$shortcode}/?__a=1",
                            "https://www.instagram.com/p/{$shortcode}/?__a=1&__d=dis",
                        ];
                        foreach ($jsonEndpoints as $je) {
                            try {
                                $start2 = microtime(true);
                                $resp2 = Http::withHeaders([
                                    'accept' => 'application/json, text/javascript, */*; q=0.01',
                                    'cookie' => $cookieHeader,
                                    'user-agent' => 'Mozilla/5.0 (compatible; InstagramLookup/1.0)',
                                    'x-csrftoken' => $csrf,
                                ])->get($je);
                                $dur2 = round((microtime(true) - $start2) * 1000);
                                Log::info('[InstagramLookup] mediaId fallback json endpoint request', ['user_id' => $u->id, 'url' => $je, 'status' => $resp2->status(), 'duration_ms' => $dur2]);
                                if ($resp2->ok()) {
                                    $j2 = $resp2->json();
                                    // attempt same extraction strategy
                                    $cands2 = [
                                        $j2['graphql']['shortcode_media'] ?? null,
                                        $j2['media'] ?? null,
                                        $j2['items'][0] ?? null,
                                        $j2['data']['shortcode_media'] ?? null,
                                    ];
                                    foreach ($cands2 as $c2) {
                                        if (is_array($c2) && !empty($c2['id'])) {
                                            $mediaId = $c2['id'];
                                            break 2; // found, break both foreach
                                        }
                                    }
                                    // shallow recursive search
                                    if (!$mediaId && is_array($j2)) {
                                        $found2 = null;
                                        if (isset($j2) && is_array($j2)) {
                                            array_walk_recursive($j2, function($v, $k) use (&$found2) {
                                                if ($found2) return;
                                                if ($k === 'id' && is_scalar($v)) {
                                                    $found2 = $v;
                                                }
                                            });
                                        }
                                        if ($found2) {
                                            $mediaId = $found2;
                                            break;
                                        }
                                    }
                                }
                            } catch (\Throwable $__e2) {
                                Log::warning('[InstagramLookup] fallback json endpoint failed', ['user_id' => $u->id, 'url' => $je, 'error' => $__e2->getMessage()]);
                                continue;
                            }
                        }
                    } catch (\Throwable $__f) {
                        // swallow fallback errors and continue to next user
                    }

                    // --- Fallback 2: fetch the public HTML page and attempt to extract
                    // embedded JSON (window._sharedData or similar) which contains
                    // the shortcode_media object. This is a last-resort heuristic.
                    if (!$mediaId) {
                        try {
                            $pageUrl = "https://www.instagram.com/p/{$shortcode}/";
                            $start3 = microtime(true);
                            $resp3 = Http::withHeaders([
                                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                                'cookie' => $cookieHeader,
                                'user-agent' => 'Mozilla/5.0 (compatible; InstagramLookup/1.0)',
                            ])->get($pageUrl);
                            $dur3 = round((microtime(true) - $start3) * 1000);
                            Log::info('[InstagramLookup] mediaId fallback html page request', ['user_id' => $u->id, 'url' => $pageUrl, 'status' => $resp3->status(), 'duration_ms' => $dur3]);
                            if ($resp3->ok()) {
                                $html = $resp3->body();
                                // Look for window._sharedData = {...}; pattern
                                if (preg_match('/window\._sharedData\s*=\s*(\{.*?\})\s*;/', $html, $mhtml)) {
                                    $payload = $mhtml[1] ?? null;
                                    if ($payload) {
                                        try {
                                            $j3 = json_decode($payload, true);
                                            if (is_array($j3)) {
                                                // drill to find first id
                                                $found3 = null;
                                                array_walk_recursive($j3, function($v, $k) use (&$found3) {
                                                    if ($found3) return;
                                                    if ($k === 'id' && is_scalar($v)) {
                                                        $found3 = $v;
                                                    }
                                                });
                                                if ($found3) {
                                                    $mediaId = $found3;
                                                }
                                            }
                                        } catch (\Throwable $__ee) {
                                            // ignore JSON parse errors
                                        }
                                    }
                                }
                            }
                        } catch (\Throwable $__e3) {
                            Log::warning('[InstagramLookup] fallback html page fetch failed', ['user_id' => $u->id, 'error' => $__e3->getMessage()]);
                        }
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
        // Prefer admin-selected cookie user if present and valid
        $preferredId = setting('preferred_cookie_user_id');
        if ($preferredId) {
            $u = User::find($preferredId);
            if ($u && $u->cookies) {
                Log::info('[InstagramLookup] trying preferred user for userPk', ['user_id' => $u->id]);
                $cookieHeader = $this->buildCookieHeader($u->cookies);
                $csrf = $this->extractCsrfTokenFromCookies($cookieHeader);
                if ($cookieHeader) {
                    $others = User::whereNotNull('cookies')->where('id', '<>', $u->id)->inRandomOrder()->limit(max(0, $tries - 1))->get();
                    $users = collect([$u])->merge($others);
                } else {
                    Log::warning('[InstagramLookup] preferred user missing cookie header, falling back', ['user_id' => $u->id]);
                    $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
                }
            } else {
                $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
            }
        } else {
            $users = User::whereNotNull('cookies')->inRandomOrder()->limit($tries)->get();
        }

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
                    if (!empty($json['errors'])) {
                        $err = null;
                        try { $err = json_encode($json['errors']); } catch (\Throwable $_) { $err = null; }
                        Log::warning('[InstagramLookup] graphql errors in userPk response', ['user_id' => $u->id, 'errors_trunc' => $err ? substr($err, 0, 200) : null]);
                        continue;
                    }
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
