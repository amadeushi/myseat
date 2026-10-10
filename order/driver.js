/* The driver's own page. Three stacked lists that are all visible at once so he can bundle several deliveries before setting off: the delivery
   he is on, his own queue (in a tour order: always the nearest address next) and the open pool. Every card says which district the address is in
   and how far it is from the restaurant, for drivers who do not know the town; an overview map shows where everything lies. Starting and
   finishing a delivery needs a swipe (a tap in a pocket must never do it), the risky actions sit behind "Mehr". Accepting is a tap with a few
   seconds to take it back. GPS is not sent from here at all - the Traccar app on the phone pings order/driver_gps.php in the background; this
   page only shows how fresh that position is. The page polls driver_list and updates what changed, it never reloads (a half-typed reason or an
   open detail must survive the poll). */
(function () {
	'use strict';
	const body = document.body, root = document.getElementById('dv-root'); if (!root) { return; }
	const TOKEN = body.dataset.token, DEVICE = body.dataset.device;
	const POLL_MS = 20000, VOLATILE = ['waiting', 'you_km', 'near'];

	const $ = (s, r) => (r || document).querySelector(s);
	const $$ = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const eur = c => (c / 100).toFixed(2).replace('.', ',') + ' €';
	const num1 = n => n.toFixed(1).replace('.', ',');
	const store = {
		get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
		set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	};
	const ICONS = {
		route: '<path d="M5 19L19 5M9 5h10v10"/>',
		phone: '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
		sound: '<path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M16.5 9a4 4 0 010 6M19 6.5a8 8 0 010 11"/>',
		soundOff: '<path d="M4 9v6h4l5 4V5L8 9H4z"/><path d="M17 9l5 6M22 9l-5 6"/>',
		map: '<path d="M9 4L3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"/>',
		arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
		pin: '<path d="M12 21s7-6.2 7-11a7 7 0 10-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.5"/>'
	};
	const icon = (n, s) => '<svg class="dv-ic" viewBox="0 0 24 24" width="' + (s || 20) + '" height="' + (s || 20) + '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICONS[n] + '</svg>';

	let data = null, polledAt = 0, offlineSince = 0;

	function say(t) { const el = $('#dv-state'); if (el) { el.textContent = t; } }
	function post(op, payload) {
		payload = payload || {}; payload.op = op; payload.token = TOKEN; payload.device = DEVICE;
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(payload) }).then(r => r.json());
	}

	/* ---- toast with an undo ---- */
	let toastTimer = null;
	function toast(text, actionLabel, action, ms) {
		const el = $('#dv-toast'); clearTimeout(toastTimer);
		el.innerHTML = '<span>' + esc(text) + '</span>' + (action ? '<button type="button">' + esc(actionLabel) + '</button>' : '');
		el.hidden = false;
		const hide = () => { el.hidden = true; };
		if (action) { $('button', el).addEventListener('click', () => { hide(); action(); }); }
		toastTimer = setTimeout(hide, ms || 6000);
	}

	/* ---- two taps for the risky buttons inside "Mehr" ---- */
	function armable(btn, confirmText, idleText, go) {
		btn.addEventListener('click', () => {
			if (!btn.dataset.armed) { btn.dataset.armed = '1'; btn.textContent = confirmText; setTimeout(() => { btn.dataset.armed = ''; btn.textContent = idleText; }, 4000); return; }
			go(btn);
		});
	}

	/* ---- the swipe control: the thumb has to be dragged (almost) all the way. Keyboard: arrow right three times. ---- */
	function swipe(label, variant, disabled, onDone) {
		const el = document.createElement('div');
		el.className = 'dv-swipe dv-swipe--' + variant;
		if (disabled) { el.setAttribute('aria-disabled', 'true'); }
		el.innerHTML = '<span class="dv-swipe-fill"></span><span class="dv-swipe-label">' + esc(label) + '</span>' +
			'<span class="dv-swipe-thumb" role="slider" tabindex="' + (disabled ? '-1' : '0') + '" aria-label="' + esc(label) + '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="nicht bestätigt">' + icon('arrow', 26) + '</span>';
		const thumb = $('.dv-swipe-thumb', el), fill = $('.dv-swipe-fill', el), lab = $('.dv-swipe-label', el);
		let max = 0, x = 0, startX = 0, dragging = false, fired = false;
		const measure = () => { max = Math.max(1, el.clientWidth - thumb.offsetWidth - 6); };
		const set = (px, anim) => {
			x = px; const ease = 'cubic-bezier(.16,1,.3,1)';
			thumb.style.transition = anim ? 'transform 240ms ' + ease : 'none'; fill.style.transition = anim ? 'width 240ms ' + ease : 'none';
			thumb.style.transform = 'translateX(' + px + 'px)'; fill.style.width = px > 0 ? (px + thumb.offsetWidth + 3) + 'px' : '0';
			thumb.setAttribute('aria-valuenow', String(Math.round(px / max * 100)));
		};
		const fire = () => {
			fired = true; measure(); set(max, true); el.classList.add('is-done'); lab.textContent = 'Wird gesendet …'; thumb.setAttribute('aria-valuetext', 'bestätigt');
			onDone(() => { fired = false; el.classList.remove('is-done'); lab.textContent = label; thumb.setAttribute('aria-valuetext', 'nicht bestätigt'); set(0, true); });
		};
		const off = () => fired || el.getAttribute('aria-disabled') === 'true';
		thumb.addEventListener('pointerdown', e => { if (off()) { return; } measure(); dragging = true; startX = e.clientX - x; thumb.setPointerCapture(e.pointerId); el.classList.add('is-drag'); });
		thumb.addEventListener('pointermove', e => { if (dragging) { set(Math.max(0, Math.min(max, e.clientX - startX)), false); } });
		thumb.addEventListener('pointerup', () => { if (!dragging) { return; } dragging = false; el.classList.remove('is-drag'); if (x >= max * 0.88) { fire(); } else { set(0, true); } });
		thumb.addEventListener('pointercancel', () => { dragging = false; el.classList.remove('is-drag'); set(0, true); });
		thumb.addEventListener('keydown', e => {
			if (off()) { return; }
			if (e.key === 'ArrowRight' || e.key === 'ArrowUp') { e.preventDefault(); measure(); set(Math.min(max, x + max * 0.34), true); if (x >= max * 0.88) { fire(); } }
			if (e.key === 'ArrowLeft' || e.key === 'ArrowDown' || e.key === 'Home') { e.preventDefault(); set(0, true); }
		});
		return el;
	}

	/* ---- distance, district, tags ---- */
	const youText = o => o.you_km != null ? 'ab dir ' + num1(o.you_km) + ' km Luftlinie' : '';
	const nearText = o => o.near ? 'Liegt nah bei #' + o.near.no + ' · ' + (o.near.m < 1000 ? Math.round(o.near.m / 10) * 10 + ' m' : num1(o.near.m / 1000) + ' km') : '';
	const waitText = o => o.waiting != null && o.waiting >= 1 ? 'wartet seit ' + o.waiting + ' Min' : '';
	function dist(o) {
		if (o.km == null) { return '<p class="dv-dist dv-dist--none">Entfernung noch unbekannt</p>'; }
		const far = o.km <= 2.5 ? 1 : (o.km <= 5 ? 2 : 3);
		const label = (o.approx ? 'Luftlinie ca. ' : '') + num1(o.km) + ' km' + (o.min ? ', etwa ' + o.min + ' Minuten Fahrt' : '') + ' vom Restaurant';
		return '<p class="dv-dist" aria-label="' + esc(label) + '"><span class="dv-meter" data-far="' + far + '" aria-hidden="true"><i></i><i></i><i></i></span>' +
			'<strong>' + (o.approx ? 'ca. ' : '') + num1(o.km) + ' km</strong>' + (o.min ? '<span>' + o.min + ' Min</span>' : '') +
			'<small data-dyn="you"' + (youText(o) ? '' : ' hidden') + '>' + esc(youText(o)) + '</small></p>';
	}
	function payTag(o) { return o.paykind === 'paid' ? 'Bezahlt' : (o.paykind === 'cash' ? 'Bar ' : 'Karte ') + eur(o.collect); }
	function place(o) { return o.suburb || o.zone || (o.zip ? 'PLZ ' + o.zip : 'Stadtteil unbekannt'); }

	function cardHtml(o, kind, locked, step) {
		// the name of the guest is the head (the driver has to know whom the trip is for before he takes it); the district stands under it, without a name it is the head itself
		const head = '<header class="dv-head"><div class="dv-ttl"><h3 class="dv-sub">' + esc(o.customer_name || place(o)) + '</h3>' + (o.customer_name ? '<p class="dv-place">' + esc(place(o)) + '</p>' : '') + '</div><span class="dv-no">' + (kind === 'queue' ? 'Stopp ' + step + ' · ' : '') + '#' + o.day_no + '</span></header>';
		const tags = '<ul class="dv-tags"><li class="dv-tag dv-tag--pay">' + esc(payTag(o)) + '</li><li class="dv-tag">' + o.items + ' Pos.</li>' +
			(o.when !== 'so schnell wie möglich' ? '<li class="dv-tag">Wunschzeit ' + esc(o.when) + '</li>' : '') +
			(kind === 'soon' ? '<li class="dv-tag dv-tag--kitchen">' + (o.cooking ? 'Wird gekocht' : 'Angenommen') + ' · fertig ca. ' + esc(o.ready_txt) + ' Uhr</li>' : '') + (kind === 'open' ? '<li class="dv-tag dv-wait" data-dyn="wait"' + (waitText(o) ? '' : ' hidden') + '>' + esc(waitText(o)) + '</li>' : '') + '</ul>';
		const near = '<p class="dv-near" data-dyn="near"' + (nearText(o) ? '' : ' hidden') + '>' + icon('pin', 16) + '<span>' + esc(nearText(o)) + '</span></p>';
		let act;
		if (kind === 'soon') { act = '<p class="dv-soon-note">Noch in der Küche. Du kannst sie annehmen, sobald sie fertig ist.</p>'; }
		else if (kind === 'open') { act = '<button type="button" class="cart-go dv-take" data-claim="' + o.id + '">Annehmen</button>'; }
		else {
			act = '<details class="dv-check"><summary><span>Alles dabei?</span><span class="dv-check-n" aria-hidden="true"></span></summary><ul class="dv-checklist"></ul></details>' +
				'<div class="dv-swipe-slot"></div>' + (locked ? '<p class="dv-locked-note">Erst die aktive Lieferung abschließen, pausieren oder zurückgeben.</p>' : '') +
				'<details class="dv-more"><summary>Mehr</summary><button type="button" class="cart-go dv-alt dv-unq" data-unqueue="' + o.id + '">Zurück in den Pool</button></details>';
		}
		return '<article class="dv-card dv-card--' + kind + '" data-card="' + o.id + '">' + head + dist(o) +
			'<p class="dv-street">' + esc(o.street) + '</p>' + (o.door ? '<p class="dv-door"><span>Hinweis</span> ' + esc(o.door) + '</p>' : '') + tags + near + act + '</article>';
	}

	function itemsHtml(items) {
		return '<ul class="dv-items">' + items.map(it => '<li><strong>' + it.qty + '×</strong> ' + esc(it.title) + (it.variation ? ' <span class="dv-var">' + esc(it.variation) + '</span>' : '') +
			(it.opts ? '<span class="dv-opts">+ ' + esc(it.opts) + '</span>' : '') + (it.note ? '<span class="dv-inote">' + esc(it.note) + '</span>' : '') + '</li>').join('') + '</ul>';
	}

	function currentHtml(o, hasQueue) {
		const money = o.paykind === 'paid'
			? '<div class="dv-collect is-paid"><p class="dv-collect-l">Bezahlt</p><p class="dv-collect-v">Nichts zu kassieren</p></div>'
			: '<div class="dv-collect is-' + o.paykind + '"><p class="dv-collect-l">' + (o.paykind === 'cash' ? 'Bar kassieren' : 'Mit Karte kassieren') + '</p><p class="dv-collect-v">' + eur(o.collect) + '</p>' +
			(o.pay_with ? '<p class="dv-collect-w">Gast zahlt mit ' + eur(o.pay_with) + ' &middot; Rückgeld <strong>' + eur(o.pay_with - o.collect) + '</strong></p>' : '') +
			(o.paykind === 'cash' ? '<details class="dv-change"><summary>Wechselgeld rechnen</summary><div class="dv-change-in"><div class="dv-chips" id="dv-chips"></div>' +
				'<label for="dv-given">Gast gibt</label><input type="text" id="dv-given" inputmode="decimal" autocomplete="off" placeholder="zum Beispiel 50"/>' +
				'<p class="dv-change-out" id="dv-change-out" aria-live="polite"></p></div></details>' : '') + '</div>';
		return '<section class="dv-active" aria-label="Deine aktive Lieferung">' +
			'<header class="dv-head"><h3 class="dv-sub">' + esc(place(o)) + '</h3><span class="dv-no">#' + o.day_no + ' · unterwegs</span></header>' + dist(o) +
			'<p class="dv-street dv-street--big">' + esc(o.street) + '</p><p class="dv-city">' + esc(o.address.replace(o.street + ', ', '')) + '</p>' +
			(o.address_note ? '<p class="dv-door"><span>Hinweis</span> ' + esc(o.address_note) + '</p>' : '') +
			'<div class="dv-links"><a class="cart-go dv-route" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;travelmode=driving&amp;destination=' + encodeURIComponent(o.route_dest) + '">' + icon('route', 22) + 'Route öffnen</a>' +
			'<a class="cart-go dv-alt" href="tel:' + esc(String(o.phone).replace(/[^0-9+]/g, '')) + '">' + icon('phone', 20) + esc(o.customer_name) + ' anrufen</a></div>' +
			money + '<h4 class="dv-h4">Bestellung</h4>' + itemsHtml(o.items) + (o.note ? '<p class="dv-onote"><span>Anmerkung</span> ' + esc(o.note) + '</p>' : '') +
			'<div class="dv-swipe-slot"></div>' +
			'<details class="dv-more"><summary>Mehr</summary>' +
			(hasQueue ? '<button type="button" class="cart-go dv-alt" id="dv-pause">Pausieren (andere Lieferung starten)</button>' : '') +
			'<button type="button" class="cart-go dv-alt" id="dv-release">Zurück in den Pool</button>' +
			'<button type="button" class="cart-go dv-alt dv-fail" id="dv-fail-btn">Fehlgeschlagen</button>' +
			'<div class="dv-fail-form" id="dv-fail-form" hidden><label for="dv-fail-reason">Was ist das Problem?</label>' +
			'<textarea id="dv-fail-reason" rows="3" placeholder="z.B. Kunde nicht erreichbar, Adresse nicht auffindbar ..."></textarea>' +
			'<button type="button" class="cart-go dv-fail" id="dv-fail-send">Meldung senden</button>' +
			'<button type="button" class="cart-go dv-alt" id="dv-fail-cancel">Abbrechen</button></div></details>' +
			'<p class="co-why" id="dv-msg" role="status" aria-live="polite"></p></section>';
	}

	/* ---- rendering without losing what is half done ---- */
	let built = false, W = {}, currentId = null, currentSig = null;
	const nodes = { queue: {}, open: {}, soon: {} }, sigs = { queue: {}, open: {}, soon: {} };
	const sigOf = o => JSON.stringify(o, (k, v) => VOLATILE.indexOf(k) >= 0 ? undefined : v);

	function skeleton() {
		if (built) { return; }
		root.innerHTML = '<div id="dv-current"></div>' +
			'<details class="dv-mapbox" id="dv-mapbox"><summary>' + icon('map', 20) + '<span>Karte</span></summary><div class="dv-mapin"><div id="dv-map" class="st-map dv-map" role="img" aria-label="Karte der Lieferungen"></div>' +
			'<p class="dv-legend">Leerer Kreis: Restaurant · Pulsierend: du · Gold gefüllt: offen · Gold umrandet: deine Warteliste · Grün: aktiv · Die Zahl ist die Bestellnummer.</p><button type="button" class="dv-textbtn" id="dv-fit">Alles zeigen</button></div></details>' +
			'<h2 class="dv-section" id="dv-queue-h" hidden></h2><p class="dv-hint" id="dv-queue-hint" hidden>Reihenfolge nach kürzestem Weg: immer die nächste Adresse zuerst.</p><div class="dv-list" id="dv-queue"></div>' +
			'<h2 class="dv-section" id="dv-open-h" hidden></h2>' +
			'<div class="dv-sort" id="dv-sort" role="group" aria-label="Sortierung" hidden><button type="button" data-sort="time">Nach Zeit</button><button type="button" data-sort="near">Nach Nähe</button></div>' +
			'<div class="dv-list" id="dv-open"></div>' +
			'<p class="st-box cart-empty" id="dv-empty" hidden>Gerade keine offenen Lieferungen. Diese Seite aktualisiert sich von selbst.</p>' +
			'<h2 class="dv-section dv-section--soon" id="dv-soon-h" hidden></h2><div class="dv-list" id="dv-soon"></div>' +
			'<section class="dv-shift" id="dv-shift" hidden></section>';
		W = { current: $('#dv-current'), queue: $('#dv-queue'), open: $('#dv-open'), qh: $('#dv-queue-h'), qhint: $('#dv-queue-hint'), oh: $('#dv-open-h'), soon: $('#dv-soon'), sh: $('#dv-soon-h'), sort: $('#dv-sort'), empty: $('#dv-empty'), shift: $('#dv-shift'), mapbox: $('#dv-mapbox') };
		const sort = store.get('dvSort', 'time');
		$$('button', W.sort).forEach(b => {
			b.setAttribute('aria-pressed', String(b.dataset.sort === sort));
			b.addEventListener('click', () => { store.set('dvSort', b.dataset.sort); $$('button', W.sort).forEach(x => x.setAttribute('aria-pressed', String(x === b))); if (data) { renderLists(data); } });
		});
		W.mapbox.open = store.get('dvMap', '0') === '1';
		W.mapbox.addEventListener('toggle', () => { store.set('dvMap', W.mapbox.open ? '1' : '0'); if (W.mapbox.open) { mapInit(); setTimeout(() => { if (map) { map.invalidateSize(); } mapDraw(true); }, 30); } });
		$('#dv-fit').addEventListener('click', () => mapDraw(true));
		built = true;
	}

	function currentGuarded() {
		const ff = $('#dv-fail-form', W.current);
		return !!((ff && !ff.hidden) || $('[data-armed="1"]', W.current) || $('.dv-more[open]', W.current) || $('.dv-change[open]', W.current));
	}
	function wireCurrent(cur) {
		const msg = t => { const m = $('#dv-msg', W.current); if (m) { m.textContent = t || ''; } };
		$('.dv-swipe-slot', W.current).appendChild(swipe('Zum Zustellen wischen', 'go', false, reset =>
			post('driver_complete', { order_id: cur.id }).then(r => { if (r.ok) { render(r); toast('Zugestellt. Danke!'); } else { msg(r.error); reset(); } }).catch(() => { msg('Keine Verbindung. Bitte nochmal wischen.'); reset(); })));
		const bind = (id, confirmText, idleText, op) => {
			const b = $('#' + id, W.current); if (!b) { return; }
			armable(b, confirmText, idleText, () => post(op, { order_id: cur.id }).then(r => { if (r.ok) { render(r); } else { msg(r.error); } }).catch(() => msg('Keine Verbindung.')));
		};
		bind('dv-release', 'Wirklich zurückgeben?', 'Zurück in den Pool', 'driver_release');
		bind('dv-pause', 'Wirklich pausieren?', 'Pausieren (andere Lieferung starten)', 'driver_pause');
		const failBtn = $('#dv-fail-btn', W.current);
		failBtn.addEventListener('click', () => { $('#dv-fail-form', W.current).hidden = false; failBtn.hidden = true; $('#dv-fail-reason', W.current).focus(); });
		$('#dv-fail-cancel', W.current).addEventListener('click', () => { $('#dv-fail-form', W.current).hidden = true; failBtn.hidden = false; });
		$('#dv-fail-reason', W.current).addEventListener('input', () => msg(''));
		const send = $('#dv-fail-send', W.current);
		send.addEventListener('click', () => {
			const reason = $('#dv-fail-reason', W.current).value.trim(); if (!reason) { msg('Bitte kurz beschreiben, was das Problem war.'); return; }
			send.disabled = true;
			post('driver_fail', { order_id: cur.id, reason: reason }).then(r => { if (r.ok) { render(r); } else { msg(r.error); send.disabled = false; } }).catch(() => { msg('Keine Verbindung.'); send.disabled = false; });
		});
		// change calculator: the amounts that make sense for this total, or a typed one
		const chips = $('#dv-chips', W.current);
		if (chips) {
			const total = cur.collect, out = $('#dv-change-out', W.current), inp = $('#dv-given', W.current);
			const calc = cents => { out.textContent = cents < total ? 'Es fehlen ' + eur(total - cents) : (cents === total ? 'Passend, kein Rückgeld.' : 'Rückgeld ' + eur(cents - total)); out.classList.toggle('is-short', cents < total); };
			const opts = []; [5, 10, 20, 50, 100, 200].forEach(d => { const v = Math.ceil(total / (d * 100)) * d * 100; if (opts.indexOf(v) < 0) { opts.push(v); } });
			opts.slice(0, 4).forEach(v => { const b = document.createElement('button'); b.type = 'button'; b.textContent = eur(v).replace(',00', ''); b.addEventListener('click', () => { inp.value = String(v / 100).replace('.', ','); calc(v); }); chips.appendChild(b); });
			inp.addEventListener('input', () => { const n = parseFloat(inp.value.replace(',', '.')); if (isFinite(n) && n > 0) { calc(Math.round(n * 100)); } else { out.textContent = ''; } });
		}
	}
	function updateCurrent(cur, hasQueue) {
		if (!cur) { if (currentId !== null) { W.current.innerHTML = ''; currentId = null; currentSig = null; } return; }
		const sig = sigOf(cur) + '|' + (hasQueue ? 1 : 0);
		if (currentId === cur.id && sig === currentSig) { dyn(W.current, cur); return; }
		if (currentId === cur.id && currentGuarded()) { return; }
		W.current.innerHTML = '<h2 class="dv-section">Du bist unterwegs</h2>' + currentHtml(cur, hasQueue);
		currentId = cur.id; currentSig = sig; wireCurrent(cur); dyn(W.current, cur);
	}

	// what changes by the minute is written into the card in place, so a poll never rebuilds a card for it
	function dyn(el, o) {
		const set = (name, text) => { const n = $('[data-dyn="' + name + '"]', el); if (n) { if (name === 'near') { $('span', n).textContent = text; } else { n.textContent = text; } n.hidden = !text; if (name === 'wait') { n.classList.toggle('is-late', o.waiting >= 15); } } };
		set('wait', waitText(o)); set('near', nearText(o)); set('you', youText(o));
	}

	// the checklist before the start: what the driver has ticked stays in this browser
	function checklist(el, o) {
		const key = 'dvChk_' + o.id; let done = []; try { done = JSON.parse(store.get(key, '[]')) || []; } catch (e) {}
		const ul = $('.dv-checklist', el), n = $('.dv-check-n', el), det = $('.dv-check', el), lines = o.lines || [];
		if (!lines.length) { det.hidden = true; return; }
		const upd = () => { const c = $$('input:checked', ul).length; n.textContent = c + ' von ' + lines.length; det.classList.toggle('is-complete', c === lines.length); };
		ul.innerHTML = lines.map((l, i) => '<li><label><input type="checkbox" data-i="' + i + '"' + (done.indexOf(i) >= 0 ? ' checked' : '') + '/><span><strong>' + l.qty + '×</strong> ' + esc(l.title) + (l.variation ? ' ' + esc(l.variation) : '') + (l.opts ? '<small>+ ' + esc(l.opts) + '</small>' : '') + '</span></label></li>').join('');
		ul.addEventListener('change', () => { store.set(key, JSON.stringify($$('input:checked', ul).map(i => +i.dataset.i))); upd(); });
		upd();
	}

	function node(o, kind, locked, step) {
		const t = document.createElement('div'); t.innerHTML = cardHtml(o, kind, locked, step); const el = t.firstElementChild;
		if (kind === 'open') {
			const b = $('[data-claim]', el);
			b.addEventListener('click', () => {
				b.disabled = true; b.textContent = 'Nimmt an …';
				post('driver_claim', { order_id: o.id }).then(r => {
					if (!r.ok) { say(r.error); toast(r.error); refresh(); return; }
					render(r); toast('#' + o.day_no + ' ist deine Lieferung.', 'Rückgängig', () => post('driver_release', { order_id: o.id }).then(rr => { if (rr.ok) { render(rr); } }), 7000);
				}).catch(() => { b.disabled = false; b.textContent = 'Annehmen'; toast('Keine Verbindung. Nochmal versuchen.'); });
			});
		} else if (kind === 'queue') {
			checklist(el, o);
			$('.dv-swipe-slot', el).appendChild(swipe('Zum Starten wischen', 'start', locked, reset =>
				post('driver_start', { order_id: o.id }).then(r => { if (r.ok) { render(r); } else { toast(r.error); reset(); refresh(); } }).catch(() => { toast('Keine Verbindung. Bitte nochmal wischen.'); reset(); })));
			const unq = $('[data-unqueue]', el);
			armable(unq, 'Wirklich zurückgeben?', 'Zurück in den Pool', () => { unq.disabled = true; post('driver_release', { order_id: o.id }).then(r => { if (r.ok) { render(r); } else { toast(r.error); refresh(); } }).catch(() => { unq.disabled = false; }); });
		}
		dyn(el, o);
		return el;
	}

	function diffList(kind, items, locked) {
		const container = W[kind], live = {};
		items.forEach((o, i) => {
			live[o.id] = true;
			const existing = nodes[kind][o.id];
			if (existing && $('[data-armed="1"]', existing)) { dyn(existing, o); }
			else {
				const sig = sigOf(o) + '|' + (locked ? 1 : 0) + '|' + (kind === 'queue' ? i : '');
				if (!existing || sigs[kind][o.id] !== sig) {
					const fresh = node(o, kind, locked, i + 1);
					if (existing && existing.parentNode) { existing.replaceWith(fresh); }
					nodes[kind][o.id] = fresh; sigs[kind][o.id] = sig;
				} else { dyn(existing, o); }
			}
			const n = nodes[kind][o.id];
			if (container.children[i] !== n) { container.insertBefore(n, container.children[i] || null); }
		});
		Object.keys(nodes[kind]).forEach(id => { if (!live[id]) { if (nodes[kind][id].parentNode) { nodes[kind][id].remove(); } delete nodes[kind][id]; delete sigs[kind][id]; } });
	}

	function renderLists(r) {
		W.qh.hidden = W.qhint.hidden = !r.queued.length;
		if (r.queued.length) { W.qh.textContent = 'Deine Warteliste (' + r.queued.length + ')'; }
		// the pool in the order the driver wants it; the queue keeps the tour order of the server
		const open = r.open.slice();
		if (store.get('dvSort', 'time') === 'near') { open.sort((a, b) => (a.km == null ? 1e9 : a.km) - (b.km == null ? 1e9 : b.km)); }
		diffList('queue', r.queued, !!r.current);
		diffList('open', open, false);
		W.oh.hidden = !r.open.length; W.sort.hidden = r.open.length < 2;
		if (r.open.length) { W.oh.textContent = 'Offene Lieferungen (' + r.open.length + ')'; }
		W.empty.hidden = !(!r.current && !r.queued.length && !r.open.length);
		// announced: still in the kitchen, only to look at (so a driver can judge whether to come straight back)
		const soon = r.soon || [];
		diffList('soon', soon, false);
		W.sh.hidden = !soon.length; if (soon.length) { W.sh.textContent = 'Noch in der Küche (' + soon.length + ')'; }
	}

	function shift(s) {
		if (!s || (!s.done && !s.failed)) { W.shift.hidden = true; return; }
		W.shift.hidden = false;
		W.shift.innerHTML = '<h2 class="dv-section">Heute</h2><dl class="dv-dl"><div><dt>Geliefert</dt><dd>' + s.done + (s.failed ? ' <small>(' + s.failed + ' fehlgeschlagen)</small>' : '') + '</dd></div>' +
			'<div><dt>Gefahren, geschätzt</dt><dd>ca. ' + num1(s.km) + ' km</dd></div><div><dt>Bar kassiert</dt><dd>' + eur(s.cash) + '</dd></div><div><dt>Karte an der Tür</dt><dd>' + eur(s.card) + '</dd></div></dl>';
	}

	/* ---- GPS state: a tracking that silently stopped is the usual failure ---- */
	function gpsView() {
		if (!data) { return; }
		const g = data.gps || {}, el = $('#dv-gps'), tx = $('#dv-gps-text');
		const age = g.age == null ? null : g.age + Math.round((Date.now() - polledAt) / 1000), mins = age == null ? 0 : Math.max(1, Math.round(age / 60));
		let cls = 'is-ok', text = 'GPS aktiv', bad = '';
		if (age == null) { cls = 'is-warn'; text = 'Noch kein Standort'; }
		else if (age > 120) { cls = 'is-warn'; text = 'Standort vor ' + mins + ' Min'; }
		if (age == null || age > 600 || (data.current && age > 240)) {
			cls = 'is-bad'; text = age == null ? 'Kein Standort' : 'Standort seit ' + mins + ' Min still';
			bad = 'Dein Standort kommt nicht an. Bitte die Traccar-App prüfen: läuft sie und ist der Standort auf „Immer“ gestellt?' + (data.current ? ' Sonst sieht der Gast dich nicht auf der Karte.' : '');
		}
		el.className = 'dv-gps ' + cls; tx.textContent = text;
		banner(offlineSince ? 'Keine Verbindung seit ' + new Date(offlineSince).toTimeString().slice(0, 5) + ' Uhr. Die Anzeige kann veraltet sein, ich versuche es weiter.' : bad);
	}
	function banner(t) { const b = $('#dv-banner'); b.textContent = t || ''; b.hidden = !t; }

	/* ---- overview map ---- */
	let map = null, layer = null, mapSig = '', mapIds = '';
	function mapInit() {
		if (map || !window.L || !W.mapbox.open) { return; }
		map = L.map($('#dv-map'), { zoomControl: true, scrollWheelZoom: false, attributionControl: true });
		L.tileLayer('tile_proxy.php?z={z}&x={x}&y={y}', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
		layer = L.layerGroup().addTo(map);
	}
	function plain(cls, ll) {
		const house = cls === 'shop';
		L.marker(ll, { icon: L.divIcon({ className: house ? 'dv-pin dv-pin--shop' : 'st-pin st-pin-' + cls, html: house ? '<span><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9h12v-9"/></svg></span>' : '<span></span>', iconSize: [34, 34], iconAnchor: [17, 17] }), keyboard: false, interactive: false }).addTo(layer);
	}
	function mapDraw(fit) {
		if (!data) { return; }
		if (!window.L) { W.mapbox.hidden = true; return; }
		mapInit(); if (!map) { return; }
		const pts = [];
		(data.open || []).forEach(o => { if (o.lat != null) { pts.push({ k: 'open', o: o }); } });
		(data.queued || []).forEach(o => { if (o.lat != null) { pts.push({ k: 'queue', o: o }); } });
		if (data.current && data.current.lat != null) { pts.push({ k: 'cur', o: data.current }); }
		const g = data.gps || {};
		const sig = JSON.stringify([data.origin, g.lat, g.lng, pts.map(p => [p.k, p.o.id, p.o.lat, p.o.lng])]);
		if (sig === mapSig && !fit) { return; }
		const ids = JSON.stringify(pts.map(p => p.o.id).sort()), changed = ids !== mapIds;
		mapSig = sig; mapIds = ids; layer.clearLayers();
		const all = [];
		if (data.origin) { const ll = [data.origin.lat, data.origin.lng]; plain('shop', ll); all.push(ll); }
		if (g.lat != null) { const ll = [g.lat, g.lng]; plain('driver', ll); all.push(ll); }
		pts.forEach(p => {
			const ll = [p.o.lat, p.o.lng];
			L.marker(ll, { icon: L.divIcon({ className: 'dv-pin dv-pin--' + p.k, html: '<span>' + esc(p.o.day_no) + '</span>', iconSize: [34, 34], iconAnchor: [17, 17] }), keyboard: false }).on('click', () => flashCard(p.o.id)).addTo(layer);
			all.push(ll);
		});
		if ((fit || changed || !map._dvFitted) && all.length) {
			map._dvFitted = true;
			if (all.length === 1) { map.setView(all[0], 15); } else { map.fitBounds(all, { padding: [36, 36], maxZoom: 16 }); }
		}
	}
	function flashCard(id) {
		const el = nodes.queue[id] || nodes.open[id] || (currentId === id ? W.current : null); if (!el) { return; }
		el.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
		el.classList.add('is-flash'); setTimeout(() => el.classList.remove('is-flash'), 1400);
	}

	/* ---- sound and vibration for a new delivery in the pool, screen that stays on during a delivery ---- */
	const snd = { on: store.get('dvSound', '0') === '1', ctx: null };
	function beep() {
		try {
			snd.ctx = snd.ctx || new (window.AudioContext || window.webkitAudioContext)(); if (snd.ctx.state === 'suspended') { snd.ctx.resume(); }
			const t = snd.ctx.currentTime;
			[[880, 0], [1320, 0.2]].forEach(p => {
				const o = snd.ctx.createOscillator(), g = snd.ctx.createGain(); o.type = 'sine'; o.frequency.value = p[0];
				g.gain.setValueAtTime(0.0001, t + p[1]); g.gain.exponentialRampToValueAtTime(0.5, t + p[1] + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + p[1] + 0.45);
				o.connect(g); g.connect(snd.ctx.destination); o.start(t + p[1]); o.stop(t + p[1] + 0.5);
			});
		} catch (e) {}
		if (navigator.vibrate) { navigator.vibrate([180, 90, 180]); }
	}
	function soundBtn() { const b = $('#dv-sound'); b.setAttribute('aria-pressed', String(snd.on)); b.innerHTML = icon(snd.on ? 'sound' : 'soundOff', 22); b.title = snd.on ? 'Ton an: neue Lieferungen melden sich' : 'Ton aus'; }
	$('#dv-sound').addEventListener('click', () => { snd.on = !snd.on; store.set('dvSound', snd.on ? '1' : '0'); soundBtn(); if (snd.on) { beep(); toast('Ton an: neue Lieferungen melden sich.'); } });
	soundBtn();

	let lock = null;
	function wake(on) {
		if (!('wakeLock' in navigator)) { return; }
		if (on && !lock) { navigator.wakeLock.request('screen').then(l => { lock = l; l.addEventListener('release', () => { lock = null; }); }).catch(() => {}); }
		else if (!on && lock) { lock.release().catch(() => {}); lock = null; }
	}

	/* ---- the whole page ---- */
	let known = null, held = {};
	function render(r) {
		data = r; polledAt = Date.now(); skeleton();
		updateCurrent(r.current, r.queued.length > 0);
		renderLists(r); shift(r.shift); mapDraw(false);
		say(r.current ? 'Du bist unterwegs.' : (r.queued.length ? 'Bereit zum Start' : 'Offene Lieferungen'));
		wake(!!r.current);
		// a new delivery in the pool (not one he has just given back himself) calls for attention
		if (known) {
			const fresh = r.open.filter(o => known.indexOf(o.id) < 0 && !held[o.id]);
			if (fresh.length) { fresh.forEach(o => { const n = nodes.open[o.id]; if (n) { n.classList.add('is-new'); setTimeout(() => n.classList.remove('is-new'), 6000); } }); if (snd.on) { beep(); } }
		}
		known = r.open.map(o => o.id); held = {};
		r.queued.forEach(o => { held[o.id] = true; }); if (r.current) { held[r.current.id] = true; }
		gpsView();
	}

	function refresh() {
		return post('driver_list').then(r => {
			offlineSince = 0;
			if (r.ok) { render(r); } else { say(r.error); }
		}).catch(() => { if (!offlineSince) { offlineSince = Date.now(); } say('Keine Verbindung, ich versuche es weiter.'); gpsView(); });
	}
	refresh();
	setInterval(refresh, POLL_MS);
	setInterval(gpsView, 15000);
	document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { if (data && data.current) { wake(true); } refresh(); } });
	window.addEventListener('online', refresh);
})();
