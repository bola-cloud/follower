<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - Active Users</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto mt-8">
        <h1 class="text-2xl font-bold mb-4">WebSocket Active Users</h1>
        <div class="bg-white shadow rounded p-4">
            <h4 class="text-lg">
                Currently connected users:
                <span id="active-count" class="inline-block px-2 py-1 bg-blue-500 text-white rounded">0</span>
            </h4>
        </div>
    </div>
    <script src="https://js.pusher.com/7.2/pusher.min.js"></script>
    <script>
        Pusher.logToConsole = true;
        const pusher = new Pusher('localkey123', {
            wsHost: '127.0.0.1', // Use explicit host
            wsPort: 6001,
            forceTLS: false,
            disableStats: true,
        });
        const presence = pusher.subscribe('presence-dashboard');
        presence.bind('pusher:subscription_succeeded', members => {
            console.log('Subscription succeeded:', members.count);
            document.getElementById('active-count').textContent = members.count;
        });
        presence.bind('pusher:member_added', member => {
            console.log('Member added:', member);
            let count = parseInt(document.getElementById('active-count').textContent);
            document.getElementById('active-count').textContent = count + 1;
        });
        presence.bind('pusher:member_removed', member => {
            console.log('Member removed:', member);
            let count = parseInt(document.getElementById('active-count').textContent);
            document.getElementById('active-count').textContent = count - 1;
        });
        pusher.connection.bind('connected', function() {
            console.log('Connected to WebSocket server!');
        });
        pusher.connection.bind('error', function(err) {
            console.error('Pusher error:', err);
        });
    </script>
</body>
</html>
