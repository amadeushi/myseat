/* Dashboard "Bestellungen": numbers of the day, all orders of the day, next step as a button, refreshes itself. */
(function () {
	'use strict';
	var page = document.getElementById('orders-page'); if (!page) { return; }
	var TOKEN = page.dataset.token, DATE = page.dataset.date, filter = 'all', open = {};
	var LABEL = { 'new': 'neu', accepted: 'angenommen', preparing: 'in Zubereitung', ready: 'fertig', delivering: 'unterwegs', done: 'erledigt', cancelled: 'storniert', failed: 'fehlgeschlagen' };
	var ICON_BOX = '<svg class="orders-type-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7l9-4 9 4v10l-9 4-9-4V7zm9-4v18M3 7l9 4 9-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_BAG = '<svg class="orders-type-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8h12l-1.2 12.5a1 1 0 0 1-1 .9H8.2a1 1 0 0 1-1-.9L6 8zm3 0V6a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function $(s, r) { return (r || document).querySelector(s); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function post(op, data) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN); for (var k in data) { fd.append(k, data[k]); }
		return fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}

	// ---- inline status note, replaces alert() - themed, auto-clearing, same .is-error pattern used elsewhere
	var noteTimer = null;
	function note(msg, isError) {
		var el = $('#orders-note');
		el.textContent = msg; el.classList.toggle('is-error', !!isError);
		clearTimeout(noteTimer);
		if (msg) { noteTimer = setTimeout(function () { el.textContent = ''; }, 5000); }
	}

	// ---- themed dialog, replaces confirm()/prompt(): one <dialog> in the page, filled per call, resolved by
	// the form's submit (OK) or the explicit cancel button. method="dialog" sets dlg.returnValue to the
	// submitter's value, so "confirm" vs anything else tells the two paths apart.
	var dlg = $('#orders-dlg');
	function openDialog(title, bodyHtml, confirmLabel) {
		return new Promise(function (resolve) {
			$('#od-title').textContent = title;
			$('#od-body').innerHTML = bodyHtml;
			$('#od-confirm').textContent = confirmLabel || 'OK';
			function onClose() {
				dlg.removeEventListener('close', onClose);
				resolve(dlg.returnValue === 'confirm' ? $('#od-form') : null);
			}
			dlg.addEventListener('close', onClose);
			if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
			var first = $('#od-body input, #od-body textarea'); (first || $('#od-confirm')).focus();
		});
	}
	$('#od-cancel').addEventListener('click', function () { dlg.close('cancel'); });
	function confirmDialog(title, message, confirmLabel) {
		return openDialog(title, '<p>' + esc(message) + '</p>', confirmLabel).then(function (f) { return !!f; });
	}
	// the ETA staff commits to when accepting a new order - shown and editable instead of silently sent
	function askEta() {
		return openDialog('Bestellung annehmen', '<label>Zusage an den Gast<span class="od-row"><input type="number" id="od-eta" value="30" min="5" max="180" step="5" required/> Minuten</span></label>', 'Annehmen').then(function (f) {
			return f ? Math.max(5, parseInt(f.querySelector('#od-eta').value, 10) || 30) : null;
		});
	}
	// the address for a test delivery (replaces three chained prompt() calls) - leave the street empty to use
	// the restaurant's own configured address, same behaviour the old prompt() flow had
	function askDemoAddress() {
		return openDialog('Testbestellung: Lieferung',
			'<label>Straße + Hausnummer<br/><input type="text" id="od-street" placeholder="leer lassen für Restaurant-Adresse"/></label>' +
			'<label>PLZ<br/><input type="text" id="od-zip"/></label>' +
			'<label>Ort<br/><input type="text" id="od-city" value="Hildesheim"/></label>', 'Anlegen').then(function (f) {
			if (!f) { return null; }
			return { street: f.querySelector('#od-street').value.trim(), zip: f.querySelector('#od-zip').value.trim(), city: f.querySelector('#od-city').value.trim() };
		});
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
		var h = '<div class="orders-row st-' + esc(o.status) + '" data-id="' + o.id + '"><div class="orders-main">' +
			'<button type="button" class="orders-toggle" aria-expanded="' + (open[o.id] ? 'true' : 'false') + '" aria-label="' + (open[o.id] ? 'Details ausblenden' : 'Details anzeigen') + '">' + (open[o.id] ? '−' : '+') + '</button>' +
			'<span class="orders-no">#' + o.day_no + '</span><span class="orders-time">' + esc(o.scheduled ? o.scheduled + ' geplant' : o.created) + '</span>' +
			'<span class="orders-type ' + esc(o.type) + '">' + (o.type === 'delivery' ? ICON_BOX : ICON_BAG) + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			'<span class="orders-who">' + esc(o.name) + (o.source === 'phone' ? ' <em class="orders-test">Telefon</em>' : '') + (o.test ? ' <em class="orders-test">Test</em>' : '') + '</span>' +
			'<span class="orders-sum">' + money(o.total) + '</span><span class="orders-pay">' + pay + '</span>' +
			'<span class="orders-status">' + LABEL[o.status] + '</span><span class="orders-act">' +
			(n ? '<button type="button" class="button_dark" data-status="' + n[0] + '"' + (o.scheduled ? ' data-sched="1"' : '') + '>' + n[1] + '</button>' : '') + '</span></div>';
		if (open[o.id]) {
			h += '<div class="orders-detail"><p><strong>' + esc(o.name) + '</strong>' + (o.phone ? ' · <a href="tel:' + esc(o.phone.replace(/\s+/g, '')) + '">' + esc(o.phone) + '</a>' : '') + (o.email ? ' · ' + esc(o.email) : '') + ' · Nr. ' + esc(o.number) + '</p>' +
				(o.address ? '<p>' + esc(o.address) + (o.address_note ? ' (' + esc(o.address_note) + ')' : '') + '</p>' : '') +
				(o.status === 'failed' && o.fail_reason ? '<p class="orders-fail">Fehlgeschlagen: ' + esc(o.fail_reason) + '</p>' : '') +
				'<ul>' + o.items.map(function (it) { return '<li>' + it.qty + '× ' + esc(it.title) + (it.variation ? ' (' + esc(it.variation) + ')' : '') + (it.options.length ? ' – ' + esc(it.options.join(', ')) : '') + (it.note ? ' <em>„' + esc(it.note) + '“</em>' : '') + ' <span>' + money(it.line) + '</span></li>'; }).join('') + '</ul>' +
				(o.note ? '<p><em>Anmerkung: ' + esc(o.note) + '</em></p>' : '') +
				'<p class="orders-totals">Zwischensumme ' + money(o.subtotal) + (o.discount ? ' · ' + (o.coupon ? 'Gutschein ' + esc(o.coupon) : 'Rabatt') + ' −' + money(o.discount) : '') + (o.surcharge ? ' · Aufschlag +' + money(o.surcharge) : '') + (o.adjust ? ' (' + esc(o.adjust) + ')' : '') + (o.fee ? ' · Liefergebühr ' + money(o.fee) : '') + (o.tip ? ' · Trinkgeld ' + money(o.tip) : '') + ' · <strong>Gesamt ' + money(o.total) + '</strong></p>' +
				'<p>' + (o.status !== 'done' && o.status !== 'cancelled' ? '<button type="button" class="offer-delete" data-status="cancelled">Stornieren</button> ' : '') + (o.test ? '<button type="button" class="offer-delete" data-delete="1">Testbestellung löschen</button>' : '') + '</p></div>';
		}
		return h + '</div>';
	}
	// ---- pause of the orders: a switch per kind; off = paused for the chosen time, on = orders are taken again
	function drawPause(p) {
		if (!p) { return; }
		['delivery', 'pickup'].forEach(function (k) {
			var row = $('.pause-item[data-kind="' + k + '"]'), s = p[k]; if (!row || !s) { return; }
			row.classList.toggle('is-paused', !!s.paused);
			$('[data-pause-switch]', row).checked = !s.paused;
			$('[data-pause-for]', row).hidden = !!s.paused;
			$('[data-pause-note]', row).textContent = s.paused ? 'pausiert' + (s.until ? ' bis ' + s.until + ' Uhr' : ', bis du sie wieder einschaltest') : '';
		});
	}
	page.addEventListener('change', function (ev) {
		var sw = ev.target.closest && ev.target.closest('[data-pause-switch]'); if (!sw) { return; }
		var row = sw.closest('.pause-item'), kind = row.dataset.kind, on = !sw.checked;
		sw.disabled = true;
		post('pause_set', { kind: kind, on: on ? 1 : 0, minutes: $('[data-pause-for]', row).value }).then(function (r) {
			sw.disabled = false;
			if (!r.ok) { sw.checked = !sw.checked; note(r.error || 'Das hat nicht geklappt.', true); return; }
			drawPause(r.pause); note((kind === 'delivery' ? 'Lieferung' : 'Abholung') + (on ? ' pausiert.' : ' wird wieder angenommen.'));
		}).catch(function () { sw.disabled = false; sw.checked = !sw.checked; note('Keine Verbindung.', true); });
	});
	function load() {
		fetch('ajax/shop_orders.php?op=day&date=' + DATE + '&filter=' + filter, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); return; }
			stats(r.stats); drawPause(r.pause);
			var failedNote = '';
			if (filter === 'closed') { var nFailed = r.orders.filter(function (o) { return o.status === 'failed'; }).length; if (nFailed) { failedNote = '<p class="orders-fail-chip">Fehlgeschlagen: ' + nFailed + '</p>'; } }
			document.getElementById('orders-list').innerHTML = failedNote + (r.orders.length ? r.orders.map(row).join('') : '<p class="orders-empty">Keine Bestellungen an diesem Tag.</p>');
			note('');
		}).catch(function () { note('Keine Verbindung.', true); });
	}
	page.addEventListener('click', function (ev) {
		var dm = ev.target.closest('[data-demo]');
		if (dm) {
			if (dm.dataset.demo === 'delivery') {
				askDemoAddress().then(function (addr) {
					if (!addr) { return; }
					var data = { type: 'delivery' };
					if (addr.street !== '') { data.street = addr.street; data.zip = addr.zip; data.city = addr.city; }
					dm.disabled = true;
					post('demo', data).then(function (r) { dm.disabled = false; if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); });
				});
				return;
			}
			dm.disabled = true;
			post('demo', { type: 'pickup' }).then(function (r) { dm.disabled = false; if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); });
			return;
		}
		var f = ev.target.closest('.orders-filter button');
		if (f) { filter = f.dataset.filter; Array.prototype.forEach.call(document.querySelectorAll('.orders-filter button'), function (b) { b.setAttribute('aria-pressed', b === f ? 'true' : 'false'); }); load(); return; }
		var rowEl = ev.target.closest('.orders-row'); if (!rowEl) { return; }
		var id = rowEl.dataset.id;
		if (ev.target.closest('.orders-toggle')) { open[id] = !open[id]; load(); return; }
		var st = ev.target.closest('[data-status]');
		if (st) {
			if (st.dataset.status === 'cancelled') {
				confirmDialog('Bestellung stornieren', 'Diese Bestellung wirklich stornieren? Das kann nicht rückgängig gemacht werden.', 'Stornieren').then(function (ok) {
					if (!ok) { return; }
					post('status', { id: id, status: 'cancelled' }).then(function (r) { if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); });
				});
				return;
			}
			if (st.dataset.status === 'accepted' && st.dataset.sched) {
				// a wish time stands as the guest chose it: nothing to ask
				st.disabled = true;
				post('status', { id: id, status: 'accepted' }).then(function (r) { st.disabled = false; if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); });
				return;
			}
			if (st.dataset.status === 'accepted') {
				askEta().then(function (eta) {
					if (eta === null) { return; }
					st.disabled = true;
					post('status', { id: id, status: 'accepted', eta: eta }).then(function (r) { st.disabled = false; if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); });
				});
				return;
			}
			st.disabled = true; post('status', { id: id, status: st.dataset.status }).then(function (r) { if (!r.ok) { note(r.error || 'Das hat nicht geklappt.', true); } load(); }); return;
		}
		if (ev.target.closest('[data-delete]')) {
			confirmDialog('Testbestellung löschen', 'Diese Testbestellung endgültig löschen? Das kann nicht rückgängig gemacht werden.', 'Löschen').then(function (ok) {
				if (!ok) { return; }
				post('delete_test', { id: id }).then(function (r) { if (!r.ok) { note(r.error, true); } load(); });
			});
		}
	});
	load(); setInterval(load, 20000);
})();
