<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Active Users</title>

<script src="https://cdn.jsdelivr.net/npm/laravel-echo/dist/echo.iife.js"></script>
<script src="https://cdn.jsdelivr.net/npm/socket.io-client/dist/socket.io.js"></script>

</head>
<body>
    <h1>Active Users: <span id="active-count">1</span></h1>

    <ul id="users-list">
        <li>You</li>
    </ul>

<script>
const token = document.querySelector('meta[name="csrf-token"]').content;

window.Echo = new Echo({
    broadcaster: 'socket.io',
    host: window.location.hostname + ':6001',  // make sure 6001 matches your websocket port
    auth: {
        headers: {
            'X-CSRF-TOKEN': token
        }
    }
});

const userList = document.getElementById('users-list');
const activeCount = document.getElementById('active-count');

let users = {};

function renderUsers() {
    userList.innerHTML = '';
    Object.values(users).forEach(name => {
        const li = document.createElement('li');
        li.textContent = name;
        userList.appendChild(li);
    });
    activeCount.textContent = Object.keys(users).length;
}

window.Echo.join('presence-active-users')
    .here((members) => {
        users = {};
        members.forEach(member => {
            users[member.id] = member.name;
        });
        renderUsers();
    })
    .joining((user) => {
        users[user.id] = user.name;
        renderUsers();
    })
    .leaving((user) => {
        delete users[user.id];
        renderUsers();
    });
</script>

</body>
</html>
