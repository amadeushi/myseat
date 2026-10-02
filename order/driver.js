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
			'<button type="button" class="cart-go dv-alt dv-fail" id="dv-fail-btn">Fehlgeschlagen</button>' +
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
			'<button type="button" class="cart-go" data-start="' + o.id + '"' + (locked ? ' disabled' : '') + '>Starten</button>' +
			(locked ? '<p class="dv-locked-note">Erst die aktive Lieferung abschließen, pausieren oder zurückgeben.</p>' : '') +
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

	// Diff-rendered instead of a wholesale innerHTML replace on every 20s poll: a full rebuild used to silently
	// wipe a half-typed "Fehlgeschlagen" reason or an armed two-tap confirm the instant ANY field changed -
	// including the routine case of another order's status moving in the background. Same fix as orders.js /
	// disposition.js / kitchen_screen.js this session, adapted for this page's three stacked sections.
	var built = false, queueWrap, openWrap, currentWrap, queueHeading, openHeading, emptyMsg;
	var currentId = null, currentSig = null, queueNodes = {}, queueSig = {}, openNodes = {}, openSig = {};

	function ensureSkeleton() {
		if (built) { return; }
		root.innerHTML = '<div id="dv-current"></div>' +
			'<h2 class="dv-section" id="dv-queue-h" hidden></h2><div class="dv-list" id="dv-queue"></div>' +
			'<h2 class="dv-section" id="dv-open-h" hidden>Offene Lieferungen</h2><div class="dv-list" id="dv-open"></div>' +
			'<p class="st-box cart-empty" id="dv-empty" hidden>Gerade keine offenen Lieferungen. Diese Seite aktualisiert sich von selbst.</p>';
		currentWrap = $('#dv-current'); queueWrap = $('#dv-queue'); openWrap = $('#dv-open');
		queueHeading = $('#dv-queue-h'); openHeading = $('#dv-open-h'); emptyMsg = $('#dv-empty');
		built = true;
	}
	function currentGuarded() {
		var ff = $('#dv-fail-form', currentWrap);
		if (ff && !ff.hidden) { return true; }
		return !!$('[data-armed="1"]', currentWrap);
	}
	function wireCurrent(cur) {
		var say = function (t) { var m = $('#dv-msg', currentWrap); if (m) { m.textContent = t || ''; } };
		var doneBtn = $('#dv-done', currentWrap);
		if (doneBtn) { armable(doneBtn, 'Wirklich zugestellt?', 'Zugestellt', function () { post('driver_complete', { order_id: cur.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); }); }
		var releaseBtn = $('#dv-release', currentWrap);
		if (releaseBtn) { armable(releaseBtn, 'Wirklich zurückgeben?', 'Zurück in den Pool', function () { post('driver_release', { order_id: cur.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); }); }
		var pauseBtn = $('#dv-pause', currentWrap);
		if (pauseBtn) { armable(pauseBtn, 'Wirklich pausieren?', 'Pausieren (andere Lieferung starten)', function () { post('driver_pause', { order_id: cur.id }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); } }); }); }
		var failBtn = $('#dv-fail-btn', currentWrap);
		if (failBtn) { failBtn.addEventListener('click', function () { $('#dv-fail-form', currentWrap).hidden = false; failBtn.hidden = true; $('#dv-fail-reason', currentWrap).focus(); }); }
		var cancelBtn = $('#dv-fail-cancel', currentWrap);
		if (cancelBtn) { cancelBtn.addEventListener('click', function () { $('#dv-fail-form', currentWrap).hidden = true; $('#dv-fail-btn', currentWrap).hidden = false; }); }
		var sendBtn = $('#dv-fail-send', currentWrap);
		if (sendBtn) {
			sendBtn.addEventListener('click', function () {
				var reason = $('#dv-fail-reason', currentWrap).value.trim(); if (!reason) { say('Bitte kurz beschreiben, was das Problem war.'); return; }
				sendBtn.disabled = true;
				post('driver_fail', { order_id: cur.id, reason: reason }).then(function (rr) { if (rr.ok) { render(rr); } else { say(rr.error); sendBtn.disabled = false; } });
			});
		}
	}
	function updateCurrent(cur, hasQueueWaiting) {
		if (!cur) { if (currentId !== null) { currentWrap.innerHTML = ''; currentId = null; currentSig = null; } return; }
		var sig = JSON.stringify(cur) + '|' + (hasQueueWaiting ? 1 : 0);
		if (currentId === cur.id && sig === currentSig) { return; }
		if (currentId === cur.id && currentGuarded()) { return; }
		currentWrap.innerHTML = currentHtml(cur, hasQueueWaiting);
		currentId = cur.id; currentSig = sig;
		wireCurrent(cur);
	}
	function diffList(container, nodesMap, sigMap, items, sigExtra, nodeFn) {
		var liveIds = {};
		items.forEach(function (o) {
			liveIds[o.id] = true;
			var existing = nodesMap[o.id];
			if (existing && $('[data-armed="1"]', existing)) {
				if (existing.parentNode !== container) { container.appendChild(existing); }
				return;
			}
			var sig = JSON.stringify(o) + (sigExtra !== undefined ? '|' + sigExtra : '');
			if (!existing || sigMap[o.id] !== sig) {
				var fresh = nodeFn(o);
				if (existing && existing.parentNode) { existing.replaceWith(fresh); }
				nodesMap[o.id] = fresh; sigMap[o.id] = sig;
			}
			if (nodesMap[o.id].parentNode !== container) { container.appendChild(nodesMap[o.id]); }
		});
		Object.keys(nodesMap).forEach(function (id) {
			if (!liveIds[id]) { if (nodesMap[id].parentNode) { nodesMap[id].remove(); } delete nodesMap[id]; delete sigMap[id]; }
		});
	}
	function queueNode(o, locked) {
		var tmp = document.createElement('div'); tmp.innerHTML = queueCardHtml(o, locked); var el = tmp.firstElementChild;
		var startBtn = $('[data-start]', el);
		if (startBtn && !startBtn.disabled) {
			startBtn.addEventListener('click', function () {
				startBtn.disabled = true; startBtn.textContent = 'Startet …';
				post('driver_start', { order_id: +startBtn.dataset.start }).then(function (rr) { if (rr.ok) { render(rr); } else { state(rr.error); refresh(); } });
			});
		}
		var unqBtn = $('[data-unqueue]', el);
		armable(unqBtn, 'Wirklich zurückgeben?', 'Zurück in den Pool', function () {
			unqBtn.disabled = true;
			post('driver_release', { order_id: +unqBtn.dataset.unqueue }).then(function (rr) { if (rr.ok) { render(rr); } else { state(rr.error); refresh(); } });
		});
		return el;
	}
	function openNode(o) {
		var tmp = document.createElement('div'); tmp.innerHTML = openCardHtml(o); var el = tmp.firstElementChild;
		var claimBtn = $('[data-claim]', el);
		armable(claimBtn, 'Wirklich annehmen?', 'Annehmen', function () {
			claimBtn.disabled = true; claimBtn.textContent = 'Nimmt an …';
			post('driver_claim', { order_id: +claimBtn.dataset.claim }).then(function (rr) { if (!rr.ok) { state(rr.error); refresh(); return; } render(rr); });
		});
		return el;
	}

	function render(r) {
		ensureSkeleton();
		updateCurrent(r.current, r.queued.length > 0);
		queueHeading.hidden = !r.queued.length;
		if (r.queued.length) { queueHeading.textContent = 'Deine Warteliste (' + r.queued.length + ')'; }
		diffList(queueWrap, queueNodes, queueSig, r.queued, !!r.current, function (o) { return queueNode(o, !!r.current); });
		openHeading.hidden = !r.open.length;
		diffList(openWrap, openNodes, openSig, r.open, undefined, openNode);
		emptyMsg.hidden = !(!r.current && !r.queued.length && !r.open.length);
		state(r.current ? 'Du bist unterwegs.' : (r.queued.length ? 'Bereit zum Start' : 'Offene Lieferungen'));
	}

	function refresh() { post('driver_list').then(function (r) { if (r.ok) { render(r); } else { state(r.error); } }).catch(function () { state('Keine Verbindung, ich versuche es weiter.'); }); }
	refresh();
	setInterval(refresh, 20000);
})();
