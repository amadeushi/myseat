/* Dashboard "Bestellungen": numbers of the day, all orders of the day, next step as a button, refreshes itself. */
(function () {
	'use strict';
	var page = document.getElementById('orders-page'); if (!page) { return; }
	var TOKEN = page.dataset.token, DATE = page.dataset.date, filter = 'all', open = {};
	var LABEL = { 'new': 'neu', accepted: 'angenommen', preparing: 'in Zubereitung', ready: 'fertig', delivering: 'unterwegs', done: 'erledigt', cancelled: 'storniert' };
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function post(op, data) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN); for (var k in data) { fd.append(k, data[k]); }
		return fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function next(o) {
		if (o.status === 'new') { return ['accepted', 'Annehmen']; }
		if (o.status === 'accepted') { return ['preparing', 'Wird gekocht']; }
		if (o.status === 'preparing') { return ['ready', 'Fertig']; }
		if (o.status === 'ready') { return o.type === 'delivery' ? ['delivering', 'Unterwegs'] : ['done', 'Abgeholt']; }
		if (o.status === 'delivering') { return ['done', 'Geliefert']; }
		return null;
	}
	function stats(s) {
		var cells = [['Bestellungen', s.orders], ['Umsatz', money(s.revenue)], ['Lieferung', s.deliveries], ['Abholung', s.pickups], ['Online bezahlt', s.paid_online], ['Noch offen', s.open], ['Ø bis fertig', s.avg_ready_min === null ? '–' : s.avg_ready_min + ' Min']];
		document.getElementById('orders-stats').innerHTML = cells.map(function (c) { return '<div class="orders-stat"><span>' + c[0] + '</span><strong>' + c[1] + '</strong></div>'; }).join('');
	}
	function row(o) {
		var n = next(o), pay = o.pay === 'mollie' ? (o.pay_status === 'paid' ? 'online bezahlt' : 'online offen') : (o.pay === 'cash' ? 'bar' : 'Karte') + (o.pay_status === 'paid' ? ', kassiert' : ', offen');
		var h = '<div class="orders-row st-' + o.status + '" data-id="' + o.id + '"><div class="orders-main">' +
			'<button type="button" class="orders-toggle" aria-expanded="' + (open[o.id] ? 'true' : 'false') + '" aria-label="Details">' + (open[o.id] ? '−' : '+') + '</button>' +
			'<span class="orders-no">#' + o.day_no + '</span><span class="orders-time">' + esc(o.scheduled ? o.scheduled + ' geplant' : o.created) + '</span>' +
			'<span class="orders-type ' + o.type + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			'<span class="orders-who">' + esc(o.name) + (o.test ? ' <em class="orders-test">Test</em>' : '') + '</span>' +
			'<span class="orders-sum">' + money(o.total) + '</span><span class="orders-pay">' + pay + '</span>' +
			'<span class="orders-status">' + LABEL[o.status] + '</span><span class="orders-act">' +
			(n ? '<button type="button" class="button_dark" data-status="' + n[0] + '">' + n[1] + '</button>' : '') + '</span></div>';
		if (open[o.id]) {
			h += '<div class="orders-detail"><p><strong>' + esc(o.name) + '</strong> · <a href="tel:' + esc(o.phone.replace(/\s+/g, '')) + '">' + esc(o.phone) + '</a>' + (o.email ? ' · ' + esc(o.email) : '') + ' · Nr. ' + esc(o.number) + '</p>' +
				(o.address ? '<p>' + esc(o.address) + (o.address_note ? ' (' + esc(o.address_note) + ')' : '') + '</p>' : '') +
				'<ul>' + o.items.map(function (it) { return '<li>' + it.qty + '× ' + esc(it.title) + (it.variation ? ' (' + esc(it.variation) + ')' : '') + (it.options.length ? ' – ' + esc(it.options.join(', ')) : '') + (it.note ? ' <em>„' + esc(it.note) + '“</em>' : '') + ' <span>' + money(it.line) + '</span></li>'; }).join('') + '</ul>' +
				(o.note ? '<p><em>Anmerkung: ' + esc(o.note) + '</em></p>' : '') +
				'<p class="orders-totals">Zwischensumme ' + money(o.subtotal) + (o.discount ? ' · Gutschein ' + esc(o.coupon) + ' −' + money(o.discount) : '') + (o.fee ? ' · Liefergebühr ' + money(o.fee) : '') + (o.tip ? ' · Trinkgeld ' + money(o.tip) : '') + ' · <strong>Gesamt ' + money(o.total) + '</strong></p>' +
				'<p>' + (o.status !== 'done' && o.status !== 'cancelled' ? '<button type="button" class="offer-delete" data-status="cancelled">Stornieren</button> ' : '') + (o.test ? '<button type="button" class="offer-delete" data-delete="1">Testbestellung löschen</button>' : '') + '</p></div>';
		}
		return h + '</div>';
	}
	function load() {
		fetch('ajax/shop_orders.php?op=day&date=' + DATE + '&filter=' + filter, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { document.getElementById('orders-note').textContent = r.error || 'Das hat nicht geklappt.'; return; }
			stats(r.stats);
			document.getElementById('orders-list').innerHTML = r.orders.length ? r.orders.map(row).join('') : '<p class="orders-empty">Keine Bestellungen an diesem Tag.</p>';
			document.getElementById('orders-note').textContent = '';
		}).catch(function () { document.getElementById('orders-note').textContent = 'Keine Verbindung.'; });
	}
	page.addEventListener('click', function (ev) {
		var dm = ev.target.closest('[data-demo]');
		if (dm) {
			var data = { type: dm.dataset.demo };
			if (dm.dataset.demo === 'delivery') {
				var street = prompt('Straße und Hausnummer für die Testlieferung (leer lassen für die Restaurant-Adresse):', '');
				if (street === null) { return; }
				street = street.trim();
				if (street !== '') {
					var zip = prompt('PLZ:', '') || '', city = prompt('Ort:', 'Hildesheim') || '';
					data.street = street; data.zip = zip.trim(); data.city = city.trim();
				}
			}
			dm.disabled = true;
			post('demo', data).then(function (r) { dm.disabled = false; if (!r.ok) { alert(r.error || 'Das hat nicht geklappt.'); } load(); });
			return;
		}
		var f = ev.target.closest('.orders-filter button');
		if (f) { filter = f.dataset.filter; Array.prototype.forEach.call(document.querySelectorAll('.orders-filter button'), function (b) { b.setAttribute('aria-pressed', b === f ? 'true' : 'false'); }); load(); return; }
		var rowEl = ev.target.closest('.orders-row'); if (!rowEl) { return; }
		var id = rowEl.dataset.id;
		if (ev.target.closest('.orders-toggle')) { open[id] = !open[id]; load(); return; }
		var st = ev.target.closest('[data-status]');
		if (st) {
			if (st.dataset.status === 'cancelled' && !confirm('Diese Bestellung wirklich stornieren?')) { return; }
			var d = { id: id, status: st.dataset.status }; if (st.dataset.status === 'accepted') { d.eta = 30; }
			st.disabled = true; post('status', d).then(function (r) { if (!r.ok) { alert(r.error || 'Das hat nicht geklappt.'); } load(); }); return;
		}
		if (ev.target.closest('[data-delete]')) { if (confirm('Diese Testbestellung endgültig löschen?')) { post('delete_test', { id: id }).then(function (r) { if (!r.ok) { alert(r.error); } load(); }); } }
	});
	load(); setInterval(load, 20000);
})();
