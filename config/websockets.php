<?php

use BeyondCode\LaravelWebSockets\Dashboard\Http\Middleware\Authorize;

return [

    'dashboard' => [
        'port' => env('LARAVEL_WEBSOCKETS_PORT', 6001),
    ],

    'apps' => [
        [
            'id' => env('PUSHER_APP_ID'),
            'name' => env('APP_NAME'),
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'path' => 'app', // This should match your WebSocket server path
            'enable_client_messages' => true,
            'enable_statistics' => true,
        ],
    ],

    'app_provider' => BeyondCode\LaravelWebSockets\Apps\ConfigAppProvider::class,

    'allowed_origins' => [
        'https://egfollow.com',
    ],

    'logger' => BeyondCode\LaravelWebSockets\Statistics\Logger\HttpStatisticsLogger::class,

    'max_request_size_in_kb' => 250,

    'path' => 'laravel-websockets',

    'middleware' => [
        'web',
        // Authorize::class,
    ],

    'statistics' => [
        'model' => \BeyondCode\LaravelWebSockets\Statistics\Models\WebSocketsStatisticsEntry::class,

        'logger' => BeyondCode\LaravelWebSockets\Statistics\Logger\HttpStatisticsLogger::class,

        'interval_in_seconds' => 60,

        'delete_statistics_older_than_days' => 60,

        'perform_dns_lookup' => false,

        // ✅ FIXED: was false in your version — must be true if you want dashboard to show stats
        'enable_statistics' => true,
    ],

    // ✅ OPTIONAL: Enable this if you want WSS (SSL) support directly via Laravel WebSockets
    'ssl' => [
        'local_cert' => '/etc/letsencrypt/live/egfollow.com/fullchain.pem',
        'local_pk' => '/etc/letsencrypt/live/egfollow.com/privkey.pem',
        'passphrase' => null,
        'verify_peer' => false, // <- ✅ add this if not already present
    ],

    'channel_manager' => \BeyondCode\LaravelWebSockets\WebSockets\Channels\ChannelManagers\ArrayChannelManager::class,
];
