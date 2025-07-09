import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const ACTIVE_USERS_CHANNEL = 'presence-active-users';
    const activeCountEl = document.getElementById('active-count');
    const userListEl = document.getElementById('user-list');
    const simulateBtn = document.getElementById('simulate-user');

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

    let guestId = null;

    function joinChannel(id, name) {
        // Laravel already assigns unique guest ID if unauthenticated
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
    }

    // simulate myself as active
    simulateBtn.addEventListener('click', () => {
        if (guestId !== null) return; // already simulated

        guestId = 'guest_' + Math.random().toString(36).substr(2, 9);

        // Optional: store guestId in sessionStorage so it persists across reloads
        sessionStorage.setItem('guestId', guestId);

        joinChannel(guestId, 'Guest');
    });

    // If already simulated earlier (page reload)
    const storedGuestId = sessionStorage.getItem('guestId');
    if (storedGuestId) {
        guestId = storedGuestId;
        joinChannel(guestId, 'Guest');
    }
});
