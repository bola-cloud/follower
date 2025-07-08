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
</body>
</html>
