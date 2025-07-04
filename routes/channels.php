<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{user_id}', function ($user, $userId) {
    return $user && (int) $user->id === (int) $userId;
});

Broadcast::channel('actions.{user_id}', function ($user, $userId) {
    return $user && (int) $user->id === (int) $userId;
});

Broadcast::channel('presence.active.users', function ($user) {
    return $user ? ['id' => $user->id, 'name' => $user->name] : false;
});