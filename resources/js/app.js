import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const ACTIVE_USERS_CHANNEL = 'presence-active-users';
    const activeCountEl = document.getElementById('active-count');
    const userListEl = document.getElementById('user-list');

    if (!window.Echo) {
        console.error('Echo not loaded!');
        return;
    }

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
});
