/* The driver's own page: the open pool to accept deliveries from, his own queue of accepted-but-not-yet-started
   ones, and the detail of whichever one he has started (if any) - all three stacked and visible at once, so he
   can bundle several deliveries before setting off. Starting one is only possible while none other is active,
   so at most one guest ever sees his live position at a time. GPS is not sent from here at all - the Traccar
   app on the phone pings order/driver_gps.php in the background, independent of this page being open. This
   page only polls driver_list and re-renders, never reloads. */
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
	function armable(btn, confirmText, idleText, go) {
		btn.addEventListener('click', function () {
			if (!btn.dataset.armed) { btn.dataset.armed = '1'; btn.textContent = confirmText; setTimeout(function () { btn.dataset.armed = ''; btn.textContent = idleText; }, 4000); return; }
			go(btn);
		});
	}

	function currentHtml(o, hasQueueWaiting) {
		return '<h2 class="dv-section">Deine aktive Lieferung</h2>' +
			'<div class="st-box dv-addr"><h2>Adresse</h2><p class="dv-big">' + esc(o.address) + '</p>' +
			(o.address_note ? '<p class="cart-opts">' + esc(o.address_note) + '</p>' : '') +
			'<p class="dv-links"><a class="cart-go dv-alt" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=' + encodeURIComponent(o.route_dest) + '">Route öffnen</a>' +
			'<a class="cart-go dv-alt" href="tel:' + esc(o.phone.replace(/[^0-9+]/g, '')) + '">' + esc(o.customer_name) + ' anrufen</a></p></div>' +
			'<div class="st-box"><h2>Kassieren</h2><p class="dv-big">' + esc(o.pay) + '</p></div>' +
			'<div class="st-box"><h2>Bestellung</h2>' + o.items.map(function (it) { return '<p>' + it.qty + '× ' + esc(it.title) + '</p>'; }).join('') +
			(o.note ? '<p class="cart-opts">Anmerkung: ' + esc(o.note) + '</p>' : '') + '</div>' +
			'<div class="dv-actions"><button type="button" class="cart-go dv-done" id="dv-done">Zugestellt</button>' +
			(hasQueueWaiting ? '<button type="button" class="cart-go dv-alt" id="dv-pause">Pausieren (andere Lieferung starten)</button>' : '') +
			'<button type="button" class="cart-go dv-alt" id="dv-release">Zurück in den Pool</button>' +
			'<button type="button" class="cart-go dv-fail" id="dv-fail-btn">Fehlgeschlagen</button>' +
			'<div class="dv-fail-form" id="dv-fail-form" hidden>' +
			'<label for="dv-fail-reason">Was ist das Problem?</label>' +
			'<textarea id="dv-fail-reason" rows="3" placeholder="z.B. Kunde nicht erreichbar, Adresse nicht auffindbar ..."></textarea>' +
			'<button type="button" class="cart-go dv-fail" id="dv-fail-send">Meldung senden</button>' +
			'<button type="button" class="cart-go dv-alt" id="dv-fail-cancel">Abbrechen</button></div>' +
			'<p class="co-why" id="dv-msg" role="status" aria-live="polite"></p></div>';
	}
	function queueCardHtml(o, locked) {
		return '<article class="st-box dv-card">' +
			'<p class="dv-big">Lieferung #' + o.day_no + ' · ' + esc(o.customer_name) + '</p>' +
			'<p class="st-lead">' + (o.zone ? esc(o.zone) + ' · ' : '') + (o.zip ? esc(o.zip) + ' · ' : '') + o.items + ' Position' + (o.items === 1 ? '' : 'en') + ' · ' + fmt(o.total) + ' · ' + esc(o.when) + '</p>' +
			'<button type="button" class="cart-go" data-start="' + o.id + '"' + (locked ? ' disabled title="Erst die aktive Lieferung abschließen, pausieren oder zurückgeben"' : '') + '>Starten</button>' +
			'<button type="button" class="cart-go dv-alt" data-unqueue="' + o.id + '">Zurück in den Pool</button>' +
			'</article>';
	}
	function openCardHtml(o) {
		return '<article class="st-box dv-card">' +
			'<p class="dv-big">Lieferung #' + o.day_no + ' · ' + esc(o.customer_name) + '</p>' +
			'<p class="st-lead">' + (o.zone ? esc(o.zone) + ' · ' : '') + (o.zip ? esc(o.zip) + ' · ' : '') + o.items + ' Position' + (o.items === 1 ? '' : 'en') + ' · ' + fmt(o.total) + ' · ' + esc(o.when) + '</p>' +
			'<button type="button" class="cart-go" data-claim="' + o.id + '">Annehmen</button>' +
			'</article>';
	}

	function render(r) {
		var parts = [];
		if (r.current) { parts.push(currentHtml(r.current, r.queued.length > 0)); }
		if (r.queued.length) {
			parts.push('<h2 class="dv-section">Deine Warteliste (' + r.queued.length + ')</h2><div class="dv-list">' + r.queued.map(function (o) { return queueCardHtml(o, !!r.current); }).join('') + '</div>');
		}
		if (r.open.length) {
			parts.push('<h2 class="dv-section">Offene Lieferungen</h2><div class="dv-list">' + r.open.map(openCardHtml).join('') + '</div>');
		} else if (!r.current && !r.queued.length) {
			parts.push('<p class="st-box cart-empty">Gerade keine offenen Lieferungen. Diese Seite aktualisiert sich von selbst.</p>');
		}
		root.innerHTML = parts.join('');

		state(r.current ? 'Du bist unterwegs.' : (r.queued.length ? 'Bereit zum Start' : 'Offene Lieferungen'));

		if (r.current) {
			var say = function (t) { var m = $('#dv-msg'); if (m) { m.textContent = t || ''; } };
			armable($('#dv-done'), 'Wirklich zugestellt?', 'Zugestellt', function () { post('driver_complete', { order_id: r.current.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); });
			armable($('#dv-release'), 'Wirklich zurückgeben?', 'Zurück in den Pool', function () { post('driver_release', { order_id: r.current.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); });
			var pauseBtn = $('#dv-pause');
			if (pauseBtn) { armable(pauseBtn, 'Wirklich pausieren?', 'Pausieren (andere Lieferung starten)', function () { post('driver_pause', { order_id: r.current.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); }); }
			$('#dv-fail-btn').addEventListener('click', function () { $('#dv-fail-form').hidden = false; this.hidden = true; $('#dv-fail-reason').focus(); });
			$('#dv-fail-cancel').addEventListener('click', function () { $('#dv-fail-form').hidden = true; $('#dv-fail-btn').hidden = false; });
			$('#dv-fail-send').addEventListener('click', function () {
				var reason = $('#dv-fail-reason').value.trim(); if (!reason) { say('Bitte kurz beschreiben, was das Problem war.'); return; }
				this.disabled = true;
				post('driver_fail', { order_id: r.current.id, reason: reason }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); $('#dv-fail-send').disabled = false; } });
			});
		}
		$$('[data-start]', root).forEach(function (btn) {
			if (btn.disabled) { return; }
			btn.addEventListener('click', function () {
				btn.disabled = true; btn.textContent = 'Startet …';
				post('driver_start', { order_id: +btn.dataset.start }).then(function (rr) { if (rr.ok) { render(rr); } else { state(rr.error); refresh(); } });
			});
		});
		$$('[data-unqueue]', root).forEach(function (btn) {
			armable(btn, 'Wirklich zurückgeben?', 'Zurück in den Pool', function () {
				btn.disabled = true;
				post('driver_release', { order_id: +btn.dataset.unqueue }).then(function (rr) { if (rr.ok) { render(rr); } else { state(rr.error); refresh(); } });
			});
		});
		$$('[data-claim]', root).forEach(function (btn) {
			armable(btn, 'Wirklich annehmen?', 'Annehmen', function () {
				btn.disabled = true; btn.textContent = 'Nimmt an …';
				post('driver_claim', { order_id: +btn.dataset.claim }).then(function (rr) { if (!rr.ok) { state(rr.error); refresh(); return; } render(rr); });
			});
		});
	}

	function refresh() { post('driver_list').then(function (r) { if (r.ok) { render(r); } else { state(r.error); } }).catch(function () { state('Keine Verbindung, ich versuche es weiter.'); }); }
	refresh();
	setInterval(refresh, 20000);
})();
