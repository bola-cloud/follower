<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{user_id}', function ($user, $userId) {
    return $user && (int) $user->id === (int) $userId;
});

Broadcast::channel('actions.{user_id}', function ($user, $userId) {
    return $user && (int) $user->id === (int) $userId;
});

// Broadcast::channel('presence.active.users', function ($user) {
//     return $user ? ['id' => $user->id, 'name' => $user->name] : false;
// });

Broadcast::channel('presence-active-users', function ($user, $id) {
    if ($user) {
        return [
            'id' => $user->id,
            'name' => $user->name,
        ];
    }

    // Get guestId from header
    $guestId = request()->header('X-Guest-Id', uniqid());

    return [
        'id' => $guestId,
        'name' => 'Guest'
    ];
});

// Broadcast::channel('presence-dashboard', function () {
//     return [
//         'id' => uniqid(),
//         'name' => 'Guest'
//     ];
// });
