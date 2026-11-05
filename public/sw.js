const CACHE = 'showerqueue-static-v1';
const SHELL = ['/offline.html', '/icons/icon-192.png', '/icons/icon-512.png', '/icons/icon.svg', '/manifest.webmanifest'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(SHELL))); self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('showerqueue-') && key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', event => {
    const request = event.request, url = new URL(request.url);
    // Authenticated HTML, Livewire, and every mutation always go to the server.
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;
    if (request.mode === 'navigate') { event.respondWith(fetch(request).catch(() => caches.match('/offline.html'))); return; }
    if (url.pathname.startsWith('/build/assets/') || SHELL.includes(url.pathname)) {
        event.respondWith(caches.match(request).then(cached => cached || fetch(request).then(response => { if (response.ok) { const copy = response.clone(); caches.open(CACHE).then(cache => cache.put(request, copy)); } return response; })));
    }
});
self.addEventListener('push', event => {
    let payload; try { payload = event.data.json(); } catch { payload = {}; }
    event.waitUntil(self.registration.showNotification(payload.title || 'ShowerQueue', { body: payload.body || 'There’s a household update in your inbox.', icon: '/icons/icon-192.png', badge: '/icons/icon-192.png', tag: payload.tag || 'showerqueue-update', data: { url: '/inbox' } }));
});
self.addEventListener('notificationclick', event => { event.notification.close(); event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async clients => { const client = clients.find(client => new URL(client.url).origin === self.location.origin); if (client) { await client.navigate('/inbox'); return client.focus(); } return self.clients.openWindow('/inbox'); })); });
