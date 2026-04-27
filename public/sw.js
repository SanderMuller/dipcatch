// DipCatch service worker — handles Web Push notifications.
//
// Lifecycle:
//   - install: claim activation immediately so updates roll out without
//     waiting for tab close/reopen.
//   - push: render a Notification with title/body/icon from the payload and
//     stash the deep-link URL on the notification's `data` so the click
//     handler can route to it.
//   - notificationclick: focus an open tab on the URL or open a new one.

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    if (!event.data) {
        return;
    }

    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'DipCatch', body: event.data.text() };
    }

    const title = payload.title || 'DipCatch';
    const options = {
        body: payload.body || '',
        icon: payload.icon || '/favicon.svg',
        badge: payload.badge || '/favicon.svg',
        data: payload.data || {},
        tag: payload.tag || 'dipcatch-price-drop',
        renotify: true,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Defense-in-depth: only honour same-origin URLs from the payload, so a
    // tampered push body cannot turn a click into an off-site redirect.
    const raw = (event.notification.data && event.notification.data.url) || '/app';
    let target;
    try {
        const parsed = new URL(raw, self.registration.scope);
        target = parsed.origin === self.location.origin ? parsed.toString() : '/app';
    } catch (_) {
        target = '/app';
    }

    event.waitUntil(
        self.clients
            .matchAll({ type: 'window', includeUncontrolled: true })
            .then((clientList) => {
                for (const client of clientList) {
                    if (client.url === target && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (self.clients.openWindow) {
                    return self.clients.openWindow(target);
                }
                return null;
            }),
    );
});
