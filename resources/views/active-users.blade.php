<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Simulate 1 Active User</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/laravel-echo/1.15.0/echo.iife.js"></script>
<script src="https://js.pusher.com/7.2/pusher.min.js"></script>
</head>
<body>
    <h1>Simulated Active Users: <span id="active-count">0</span></h1>

    <script>
        // Use app key from your env
        const appKey = "{{ env('PUSHER_APP_KEY', 'localkey123') }}";

        // Use Laravel Echo to connect
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key: appKey,
            wsHost: window.location.hostname,
            wsPort: 6001,
            forceTLS: false,
            disableStats: true,
            enabledTransports: ['ws', 'wss'],
        });

        const ACTIVE_USERS_CHANNEL = 'presence-active-users';
        let activeCountEl = document.getElementById('active-count');

        window.Echo.join(ACTIVE_USERS_CHANNEL)
            .here((users) => {
                // You will see yourself as 1 active user
                activeCountEl.textContent = users.length;
                console.log('Currently here:', users);
            })
            .joining((user) => {
                let count = parseInt(activeCountEl.textContent);
                activeCountEl.textContent = count + 1;
            })
            .leaving((user) => {
                let count = parseInt(activeCountEl.textContent);
                activeCountEl.textContent = count - 1;
            });
    </script>
</body>
</html>
