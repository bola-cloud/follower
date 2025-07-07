<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{user_id}', function ($user, $userId) {
    return $user && (int) $user->id === (int) $userId;
});

Broadcast::channel('actions.{user_id}', function ($user, $userId) {
    \Log::info('Broadcast Auth', [
        'auth_user_id' => $user?->id,
        'channel_user_id' => $userId,
    ]);
    return $user && (int) $user->id === (int) $userId;
});


Broadcast::channel('presence.active.users', function ($user) {
    return $user ? ['id' => $user->id, 'name' => $user->name] : false;
});