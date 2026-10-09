/* Service worker of the order pages (scope /order/): it only shows push messages for an order ("Wird zubereitet", "Unterwegs zu dir" ...). It caches nothing and does not touch
   any request, so the pages behave exactly as without it. A push arrives without text (see web/classes/shop_push.class.php); the text is asked for at order/push.php. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
	event.waitUntil(self.registration.pushManager.getSubscription().then(function (sub) {
		return fetch('push.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub ? sub.endpoint : '' }) })
			.then(function (r) { return r.json(); }).catch(function () { return {}; })
			.then(function (m) {
				return self.registration.showNotification(m.title || 'Amadeus', {
					body: m.body || 'Es gibt Neuigkeiten zu deiner Bestellung.', icon: '/icon-192.png', badge: '/favicon-32.png', tag: 'order', renotify: true, data: { url: m.url || './' }
				});
			});
	}));
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var url = new URL((event.notification.data && event.notification.data.url) || './', self.registration.scope).href;
	event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) { if (list[i].url === url && 'focus' in list[i]) { return list[i].focus(); } }
		return self.clients.openWindow(url);
	}));
});
