<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Presence Active Users Test</title>
<script src="https://js.pusher.com/7.2/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo/dist/echo.iife.js"></script>
</head>
<body>
<h1>Testing Presence Channel: presence-active-users</h1>

<div id="log" style="background:#eee;padding:1em;margin-top:1em;"></div>

<script>
const log = (msg) => {
    const div = document.getElementById('log');
    div.innerHTML += `<p>${msg}</p>`;
    console.log(msg);
};

// 🔷 Laravel user API token
const userToken = '1|9WoJt8nVp2sjNCc6nsJKHNUL5hv7nLVPbswtU2Ywe3f05add';

const echo = new Echo({
    broadcaster: 'pusher',
    key: 'localkey123',
    wsHost: 'egfollow.com',
    wsPort: 6001,
    forceTLS: false,
    disableStats: true,
    authEndpoint: 'https://egfollow.com/broadcasting/auth',
    auth: {
        headers: {
            Authorization: `Bearer ${userToken}`
        }
    }
});

log('Connecting to Soketi and joining presence-active-users…');

echo.join('presence-active-users')
    .here(users => {
        log(`Currently online: ${users.length}`);
        users.forEach(u => log(`- ${u.name} (ID: ${u.id})`));
    })
    .joining(user => {
        log(`User joined: ${user.name} (ID: ${user.id})`);
    })
    .leaving(user => {
        log(`User left: ${user.name} (ID: ${user.id})`);
    })
    .error(error => {
        log(`Error: ${error}`);
    });
</script>
</body>
</html>
