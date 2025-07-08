<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Active Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">

    <div class="container">
        <h1>WebSocket Active Users</h1>

        <div class="card mt-4">
            <div class="card-body">
                <h4>Currently connected users:
                    <span class="badge bg-primary">{{ $activeConnections }}</span>
                </h4>
            </div>
        </div>
    </div>

    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <script>
        Pusher.logToConsole = true;

        const pusher = new Pusher('local', {
            wsHost: window.location.hostname,
            wsPort: 6001,
            forceTLS: false,
            cluster: 'mt1',
            enabledTransports: ['ws', 'wss'],
        });

        const channel = pusher.subscribe('test-channel');
        channel.bind('test-event', function(data) {
            console.log('Received test event:', data);
        });
    </script>

</body>
</html>
