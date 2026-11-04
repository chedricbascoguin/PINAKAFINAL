self.addEventListener('install', event => {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', event => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (error) {
    data = {
      title: 'RHU Resident Portal',
      body: event.data ? event.data.text() : 'You have a new RHU notification.'
    };
  }

  const title = data.title || 'RHU Resident Portal';
  const options = {
    body: data.body || 'You have a new RHU notification.',
    icon: 'assets/images/resident-icon-192.png',
    badge: 'assets/images/resident-icon-192.png',
    tag: data.tag || 'rhu-resident-notification',
    renotify: true,
    data: {
      url: data.url || 'ResidentDashboard.php'
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data && event.notification.data.url ? event.notification.data.url : 'ResidentDashboard.php';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
      for (const client of clientList) {
        if ('focus' in client) {
          client.navigate(url);
          return client.focus();
        }
      }
      return clients.openWindow(url);
    })
  );
});
