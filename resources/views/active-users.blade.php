<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <h1 class="text-2xl font-bold mb-4">Admin Dashboard</h1>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-6 rounded-lg shadow-md">
                <h2 class="text-lg font-semibold text-gray-700">Active Users</h2>
                <p id="activeUsersCount" class="text-3xl font-bold text-blue-600">0</p>
                <p id="error" class="text-sm text-red-500 hidden"></p>
                <p class="text-sm text-gray-500">Users currently online</p>
            </div>
        </div>
    </div>

    <script>
        function fetchActiveUsersCount() {
            $.ajax({
                url: '/api/active-users-count',
                method: 'GET',
                headers: {
                    'Authorization': 'Bearer 1|9WoJt8nVp2sjNCc6nsJKHNUL5hv7nLVPbswtU2Ywe3f05add',
                    'Accept': 'application/json'
                },
                success: function(response) {
                    $('#activeUsersCount').text(response.active_users_count);
                    $('#error').addClass('hidden');
                },
                error: function(xhr) {
                    console.error('Failed to fetch active users count:', xhr.responseText);
                    $('#activeUsersCount').text('Error');
                    $('#error').text('Failed to load active users: ' + (xhr.responseJSON?.details || xhr.responseJSON?.message || 'Unknown error')).removeClass('hidden');
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