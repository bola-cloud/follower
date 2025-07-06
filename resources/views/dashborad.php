<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <h1 class="text-2xl font-bold mb-4">Dashboard</h1>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold text-gray-700">Active Users</h2>
                <p id="activeUsersCount" class="text-3xl font-bold text-blue-600">0</p>
                <p class="text-sm text-gray-500">Users currently online</p>
            </div>
            <!-- Add more cards for other stats if needed -->
        </div>
    </div>

    <script>
        function fetchActiveUsersCount() {
            $.ajax({
                url: '/api/active-users-count',
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer {{ auth()->check() ? auth()->user()->createToken('auth_token')->plainTextToken : '' }}',
                    'Accept': 'application/json'
                },
                success: function(response) {
                    $('#activeUsersCount').text(response.active_users_count);
                },
                error: function(xhr) {
                    console.error('Failed to fetch active users count:', xhr.responseText);
                    $('#activeUsersCount').text('Error');
                }
            });
        }

        // Fetch count on page load
        fetchActiveUsersCount();

        // Refresh every 10 seconds
        setInterval(fetchActiveUsersCount, 10000);
    </script>
</body>
</html>