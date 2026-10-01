/* The driver's own page: open-delivery list to accept from, then the detail of whichever one he has. GPS is not
   sent from here at all - the Traccar app on the phone pings order/driver_gps.php in the background, independent
   of this page being open. This page only polls driver_list and renders list or current, swapping without reload. */
(function () {
	'use strict';
	var body = document.body, root = document.getElementById('dv-root'); if (!root) { return; }
	var TOKEN = body.dataset.token, DEVICE = body.dataset.device;

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function state(t) { var el = $('#dv-state'); if (el) { el.textContent = t; } }
	function post(op, data) {
		data = data || {}; data.op = op; data.token = TOKEN; data.device = DEVICE;
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
	}

	function renderOpen(open) {
		if (!open.length) { root.innerHTML = '<p class="st-box cart-empty">Gerade keine offenen Lieferungen. Diese Seite aktualisiert sich von selbst.</p>'; return; }
		root.innerHTML = '<div class="dv-list">' + open.map(function (o) {
			return '<article class="st-box dv-card">' +
				'<p class="dv-big">Lieferung #' + o.day_no + ' · ' + esc(o.customer_name) + '</p>' +
				'<p class="st-lead">' + (o.zone ? esc(o.zone) + ' · ' : '') + (o.zip ? esc(o.zip) + ' · ' : '') + o.items + ' Position' + (o.items === 1 ? '' : 'en') + ' · ' + fmt(o.total) + ' · ' + esc(o.when) + '</p>' +
				'<button type="button" class="cart-go" data-claim="' + o.id + '">Annehmen</button>' +
				'</article>';
		}).join('') + '</div>';
		$$('[data-claim]', root).forEach(function (btn) {
			btn.addEventListener('click', function () {
				btn.disabled = true; btn.textContent = 'Nimmt an …';
				post('driver_claim', { order_id: +btn.dataset.claim }).then(function (r) {
					if (!r.ok) { state(r.error); btn.disabled = false; btn.textContent = 'Annehmen'; refresh(); return; }
					render(r);
				});
			});
		});
	}

	function renderCurrent(o) {
		var h = '<div class="st-box dv-addr"><h2>Adresse</h2><p class="dv-big">' + esc(o.address) + '</p>' +
			(o.address_note ? '<p class="cart-opts">' + esc(o.address_note) + '</p>' : '') +
			'<p class="dv-links"><a class="cart-go dv-alt" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=' + encodeURIComponent(o.route_dest) + '">Route öffnen</a>' +
			'<a class="cart-go dv-alt" href="tel:' + esc(o.phone.replace(/[^0-9+]/g, '')) + '">' + esc(o.customer_name) + ' anrufen</a></p></div>' +
			'<div class="st-box"><h2>Kassieren</h2><p class="dv-big">' + esc(o.pay) + '</p></div>' +
			'<div class="st-box"><h2>Bestellung</h2>' + o.items.map(function (it) { return '<p>' + it.qty + '× ' + esc(it.title) + '</p>'; }).join('') +
			(o.note ? '<p class="cart-opts">Anmerkung: ' + esc(o.note) + '</p>' : '') + '</div>' +
			'<div class="dv-actions"><button type="button" class="cart-go dv-done" id="dv-done">Zugestellt</button>' +
			'<button type="button" class="cart-go dv-alt" id="dv-release">Zurück in den Pool</button>' +
			'<p class="co-why" id="dv-msg" role="status" aria-live="polite"></p></div>';
		root.innerHTML = h;
		function say(t) { var m = $('#dv-msg'); if (m) { m.textContent = t || ''; } }
		$('#dv-done').addEventListener('click', function () {
			var b = this; if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = 'Wirklich zugestellt?'; setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Zugestellt'; }, 4000); return; }
			post('driver_complete', { order_id: o.id }).then(function (r) { if (r.ok) { render(r); } else { say(r.error); } });
		});
		$('#dv-release').addEventListener('click', function () {
			var b = this; if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = 'Wirklich zurückgeben?'; setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Zurück in den Pool'; }, 4000); return; }
			post('driver_release', { order_id: o.id }).then(function (r) { if (r.ok) { render(r); } else { say(r.error); } });
		});
	}

	function render(r) {
		if (r.current) { state('Du bist unterwegs.'); renderCurrent(r.current); } else { state('Offene Lieferungen'); renderOpen(r.open); }
	}

	function refresh() { post('driver_list').then(function (r) { if (r.ok) { render(r); } else { state(r.error); } }).catch(function () { state('Keine Verbindung, ich versuche es weiter.'); }); }
	refresh();
	setInterval(refresh, 20000);
})();
