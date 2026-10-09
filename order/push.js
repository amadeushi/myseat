/* Notifications for the status page of an order: a button "Benachrichtigung einschalten" (only where the browser can do it and the order is still running). The guest taps it, the browser
   asks for permission, the subscription goes to the shop together with the secret token of the order (api.php), and from then on the shop knocks when the status changes (order/sw.js
   shows the text). Without support (or on an iPhone that is not yet on the home screen) the box explains what to do instead; the page itself keeps refreshing as before. */
(function () {
	'use strict';
	var box = document.getElementById('st-push'); if (!box) { return; }
	var order = box.dataset.order, TOKEN = document.body.dataset.token;
	var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
	var ok = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
	function msg(t, err) { var m = document.getElementById('st-pushmsg'); m.textContent = t; m.classList.toggle('is-error', !!err); }
	function api(op, data) {
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(Object.assign({ op: op, token: TOKEN, order: order }, data || {})) }).then(function (r) { return r.json(); });
	}
	function b64ToBytes(s) { s = s.replace(/-/g, '+').replace(/_/g, '/'); var raw = atob(s + '==='.slice((s.length + 3) % 4)), a = new Uint8Array(raw.length); for (var i = 0; i < raw.length; i++) { a[i] = raw.charCodeAt(i); } return a; }
	function done(text) { box.innerHTML = '<p class="st-pushdone" role="status">' + text + '</p>'; }

	if (!ok) {
		// an iPhone allows notifications only for a page on the home screen
		if (ios && !standalone) { box.hidden = false; box.querySelector('p').textContent = 'Auf dem iPhone geht das, wenn du diese Seite zum Home-Bildschirm hinzufügst (Teilen-Symbol, „Zum Home-Bildschirm“) und von dort öffnest.'; box.querySelector('button').hidden = true; }
		return;
	}
	if (Notification.permission === 'denied') { return; }
	box.hidden = false;
	var btn = box.querySelector('button');
	// already switched on in this browser: say so
	navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (reg) { return reg.pushManager.getSubscription(); }).then(function (sub) {
		if (sub && Notification.permission === 'granted') { api('push_subscribe', { endpoint: sub.endpoint }).then(function (r) { if (r.ok) { done('Benachrichtigungen sind an. Wir melden uns, wenn sich etwas tut.'); } }); }
	}).catch(function () {});
	btn.addEventListener('click', function () {
		btn.disabled = true; msg('Einen Moment ...', false);
		Notification.requestPermission().then(function (p) {
			if (p !== 'granted') { btn.disabled = false; msg('Ohne Erlaubnis geht es nicht. Du kannst sie in den Einstellungen deines Browsers ändern.', true); return; }
			return fetch('api.php?op=push_key', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (k) {
				if (!k.ok) { throw new Error('key'); }
				return navigator.serviceWorker.register('sw.js', { scope: './' }).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
					return reg.pushManager.getSubscription().then(function (sub) { return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(k.key) }); });
				});
			}).then(function (sub) { return api('push_subscribe', { endpoint: sub.endpoint }); }).then(function (r) {
				if (r.ok) { done('Benachrichtigungen sind an. Wir melden uns, wenn sich etwas tut.'); } else { btn.disabled = false; msg(r.error || 'Das hat nicht geklappt.', true); }
			});
		}).catch(function () { btn.disabled = false; msg('Das hat leider nicht geklappt. Du kannst die Seite einfach offen lassen.', true); });
	});
})();
