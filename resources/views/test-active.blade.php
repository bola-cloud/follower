<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Test Active User</title>
<script src="https://js.pusher.com/7.2/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo/dist/echo.iife.js"></script>
</head>
<body>
<h1>Simulating Active User…</h1>

<script>
const Echo = new window.Echo({
    broadcaster: 'pusher',
    key: '{{ env('PUSHER_APP_KEY') }}',
    wsHost: '{{ env('PUSHER_HOST') }}',
    wsPort: {{ env('PUSHER_PORT') }},
    forceTLS: false,
    disableStats: true,
    authEndpoint: '/broadcasting/auth',
    auth: {
        headers: {
            Authorization: 'Bearer {{ auth()->check() ? auth()->user()->createToken("auth_token")->plainTextToken : "" }}'
        }
    }
});

Echo.join('presence-active-users')
    .here(users => console.log('Current users:', users))
    .joining(user => console.log('User joined:', user))
    .leaving(user => console.log('User left:', user));
</script>
</body>
</html>
