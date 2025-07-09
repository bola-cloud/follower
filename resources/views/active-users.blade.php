<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Simulate Active User</title>
@vite('resources/js/app.js')
</head>
<body>
    <h1>Simulated Active Users: <span id="active-count">0</span></h1>
    <ul id="user-list"></ul>

    <script>
        const ACTIVE_USERS_CHANNEL = 'presence-active-users';
        const activeCountEl = document.getElementById('active-count');
        const userListEl = document.getElementById('user-list');

        const activeUsers = new Map();

        function renderUsers() {
            activeCountEl.textContent = activeUsers.size;
            userListEl.innerHTML = '';
            for (const user of activeUsers.values()) {
                const li = document.createElement('li');
                li.textContent = `${user.name} (${user.id})`;
                userListEl.appendChild(li);
            }
        }

        window.Echo.join(ACTIVE_USERS_CHANNEL)
            .here(users => {
                activeUsers.clear();
                users.forEach(u => activeUsers.set(u.id, u));
                renderUsers();
            })
            .joining(user => {
                activeUsers.set(user.id, user);
                renderUsers();
            })
            .leaving(user => {
                activeUsers.delete(user.id);
                renderUsers();
            });
    </script>
</body>
</html>
