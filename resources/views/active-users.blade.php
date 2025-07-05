<!DOCTYPE html>
  <html>
  <head>
      <title>Active Users</title>
      <script src="https://js.pusher.com/8.2/pusher.min.js"></script>
      <style>
          .user-list { margin: 20px; }
          .user { padding: 10px; border-bottom: 1px solid #ccc; }
      </style>
  </head>
  <body>
      <h1>Active Users</h1>
      <div class="user-list" id="userList"></div>

      <script>
          // Initialize Pusher
          const pusher = new Pusher('{{ env('PUSHER_APP_KEY') }}', {
              wsHost: '127.0.0.1',
              wsPort: 6001,
              forceTLS: false,
              disableStats: true,
              authEndpoint: '/api/broadcasting/auth',
              auth: {
                  headers: {
                      'Authorization': 'Bearer {{ auth()->check() ? auth()->user()->createToken('auth_token')->plainTextToken : '' }}'
                  }
              }
          });

          // Subscribe to presence channel
          const channel = pusher.subscribe('presence.active.users');

          // Update user list
          function updateUserList(members) {
              const userList = document.getElementById('userList');
              userList.innerHTML = '';
              Object.values(members).forEach(member => {
                  const div = document.createElement('div');
                  div.className = 'user';
                  div.textContent = `User: ${member.name} (ID: ${member.id})`;
                  userList.appendChild(div);
              });
          }

          // Bind to presence events
          channel.bind('pusher:subscription_succeeded', (members) => {
              updateUserList(members.members);
          });

          channel.bind('pusher:member_added', (member) => {
              updateUserList(channel.members.members);
          });

          channel.bind('pusher:member_removed', (member) => {
              updateUserList(channel.members.members);
          });
      </script>
  </body>
  </html>