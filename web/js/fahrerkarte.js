/* Driver map of the dispatch (web/fahrerkarte.php). The map shows the restaurant, the deliveries (numbered, by state) and the drivers (named, in their
   colour, with the track of the last minutes). Selecting a driver or a delivery - or both - opens the details over the lists: for a driver his stops,
   position, estimate and a slider over his track of the last hours; for a delivery the order and the drivers to ask, best first. Assigning and taking a
   delivery back use the operations of the dispatch screen (ajax/shop_orders.php). The page asks for new data every 10 seconds and draws what changed;
   a selection, a half-moved slider or an armed button stay as they are. */
(function () {
	'use strict';
	const body = document.body, TOKEN = body.dataset.token, POLL_MS = 10000;
	const PAL = ['#62b6cb', '#a99be8', '#5fc5a2', '#e2b56b', '#9ba6c0', '#d98fb0'];

	const $ = (s, r) => (r || document).querySelector(s);
	const $$ = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const eur = c => (c / 100).toFixed(2).replace('.', ',') + ' €';
	const num1 = n => n.toFixed(1).replace('.', ',');
	const dist = m => m < 1000 ? Math.max(10, Math.round(m / 10) * 10) + ' m' : num1(m / 1000) + ' km';
	const hhmm = ts => new Date(ts * 1000).toTimeString().slice(0, 5);
	const store = {
		get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
		set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	};
	function haversine(a, b, c, d) {
		const r = 6371000, p1 = a * Math.PI / 180, p2 = c * Math.PI / 180, dp = p2 - p1, dl = (d - b) * Math.PI / 180;
		const x = Math.sin(dp / 2) ** 2 + Math.cos(p1) * Math.cos(p2) * Math.sin(dl / 2) ** 2;
		return 2 * r * Math.asin(Math.min(1, Math.sqrt(x)));
	}
	const colorOf = d => PAL[(d.color || 0) % 6];

	let data = null, polledAt = 0, offlineSince = 0, pollN = 0, first = true;
	let sel = { d: 0, o: 0 };
	let filter = store.get('fkFilter', 'all'), trailsOn = store.get('fkTrails', '1') === '1', zonesOn = store.get('fkZones', '0') === '1';
	let track = { id: 0, points: [] }, scrub = null, scrubbing = false, zones = null;
	let preselect = +(new URLSearchParams(location.search).get('o') || 0); // from the dispatch screen: the delivery to look at

	const findD = id => data && data.drivers.filter(d => d.id === id)[0] || null;
	const findO = id => data && data.orders.filter(o => o.id === id)[0] || null;
	const kindOf = o => o.status === 'delivering' ? 'out' : (o.status === 'ready' ? (o.driver_id ? 'assigned' : 'open') : 'kitchen');
	const visible = o => filter === 'all' || (filter === 'open' && kindOf(o) === 'open') || (filter === 'late' && o.late_min > 0);

	function get(op, q) { return fetch('ajax/shop_orders.php?op=' + op + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(r => r.json()); }
	function post(op, extra) { return fetch('ajax/shop_orders.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(Object.assign({ op: op, token: TOKEN }, extra)) }).then(r => r.json()); }

	let toastTimer = null;
	function toast(text, label, action) {
		const el = $('#fk-toast'); clearTimeout(toastTimer);
		el.innerHTML = '<span>' + esc(text) + '</span>' + (action ? '<button type="button">' + esc(label) + '</button>' : '');
		el.hidden = false;
		if (action) { $('button', el).addEventListener('click', () => { el.hidden = true; action(); }); }
		toastTimer = setTimeout(() => { el.hidden = true; }, 7000);
	}
	function armable(btn, confirmText, idleText, go) {
		btn.addEventListener('click', () => {
			if (!btn.dataset.armed) { btn.dataset.armed = '1'; btn.textContent = confirmText; setTimeout(() => { btn.dataset.armed = ''; btn.textContent = idleText; }, 4000); return; }
			go(btn);
		});
	}

	/* ---- texts ---- */
	function ageText(d) { return d.age == null ? 'noch nie gesehen' : (d.age < 90 ? 'live' : 'vor ' + Math.max(1, Math.round(d.age / 60)) + ' Min'); }
	function stateText(d) {
		if (d.state === 'offline') { return 'Offline'; }
		if (d.state === 'free') { return 'Frei'; }
		return d.state === 'delivering' ? 'Unterwegs' : d.stops.length + (d.stops.length === 1 ? ' Stopp' : ' Stopps');
	}
	const stale = d => d.state !== 'offline' && d.age != null && d.age > 240 && d.stops.length > 0;
	const way = o => o.km == null ? 'Strecke unbekannt' : (o.approx ? 'ca. ' : '') + num1(o.km) + ' km' + (o.min ? ' · ' + o.min + ' Min' : '');
	const payText = o => o.paykind === 'paid' ? 'Bezahlt' : (o.paykind === 'cash' ? 'Bar ' : 'Karte ') + eur(o.collect);
	const lateText = o => o.late_min >= 60 ? 'seit ' + Math.floor(o.late_min / 60) + ' Std ' + (o.late_min % 60) + ' Min überfällig' : o.late_min + ' Min überfällig';
	const place = o => o.suburb || o.zone || (o.zip ? 'PLZ ' + o.zip : 'Stadtteil unbekannt');

	/* ---- header ---- */
	function counts() {
		const os = data.orders, open = os.filter(o => kindOf(o) === 'open').length, asg = os.filter(o => kindOf(o) === 'assigned').length, out = os.filter(o => kindOf(o) === 'out').length;
		const late = os.filter(o => o.late_min > 0).length, on = data.drivers.filter(d => d.state !== 'offline').length;
		$('#fk-counts').innerHTML = '<span class="k-count"><b>' + open + '</b> offen</span><span class="k-count"><b>' + asg + '</b> zugeteilt</span><span class="k-count"><b>' + out + '</b> unterwegs</span>' +
			(late ? '<span class="k-count is-late"><b>' + late + '</b> überfällig</span>' : '') + '<span class="k-count"><b>' + on + '</b> von ' + data.drivers.length + ' Fahrern online</span>';
	}

	/* ---- lists ---- */
	function driverCard(d) {
		const flags = d.stops.map(id => { const o = findO(id); return o ? '<span class="fk-flag">#' + o.day_no + '</span>' : ''; }).join('') +
			(d.eta_min ? '<span class="fk-flag">Ankunft ca. ' + d.eta_min + ' Min</span>' : '') + (stale(d) ? '<span class="fk-flag fk-flag--warn">Position ' + esc(ageText(d)) + '</span>' : '');
		return '<button type="button" class="fk-card fk-d' + (sel.d === d.id ? ' is-sel' : '') + (d.state === 'offline' ? ' is-offline' : '') + '" data-d="' + d.id + '" style="--c:' + colorOf(d) + '">' +
			'<i class="fk-dot"></i><span class="fk-main"><b>' + esc(d.name) + '</b><span>' + stateText(d) + ' · ' + (d.state === 'offline' ? esc(ageText(d)) : 'GPS ' + esc(ageText(d))) + '</span></span>' +
			'<span class="fk-meta"><b>' + d.shift.done + '</b><span>heute</span></span>' + (flags ? '<span class="fk-flags">' + flags + '</span>' : '') + '</button>';
	}
	function orderCard(o) {
		const k = kindOf(o), d = o.driver_id ? findD(o.driver_id) : null;
		const flags = (o.source === 'lieferando' ? '<span class="fk-flag fk-flag--lief">Lieferando</span>' : '') + (o.late_min > 0 ? '<span class="fk-flag fk-flag--late">' + esc(lateText(o)) + '</span>' : '') +
			(d ? '<span class="fk-flag fk-flag--drv" style="--c:' + colorOf(d) + '">' + esc(d.name) + '</span>' : '') +
			'<span class="fk-flag">' + esc(payText(o)) + '</span>' + (o.lat == null ? '<span class="fk-flag fk-flag--warn">ohne Standort</span>' : '');
		return '<button type="button" class="fk-card fk-o fk-o--' + k + (sel.o === o.id ? ' is-sel' : '') + (o.late_min > 0 ? ' is-late' : '') + '" data-o="' + o.id + '"' + (d ? ' style="--c:' + colorOf(d) + '"' : '') + '>' +
			'<span class="fk-no">' + o.day_no + '</span><span class="fk-main"><b>' + esc(o.customer_name || place(o)) + '</b><span>' + (o.customer_name ? esc(place(o)) + ' · ' : '') + esc(o.street) + '</span></span>' +
			'<span class="fk-meta"><b>' + esc(way(o)) + '</b><span>' + (o.scheduled ? 'geplant ' : 'bis ') + esc(o.due) + '</span></span><span class="fk-flags">' + flags + '</span></button>';
	}
	function renderLists() {
		const ld = $('#fk-drivers'), lo = $('#fk-orders'), sd = ld.scrollTop, so = lo.scrollTop;
		const ds = data.drivers.slice().sort((a, b) => (a.state === 'offline') - (b.state === 'offline') || a.name.localeCompare(b.name, 'de'));
		ld.innerHTML = ds.length ? ds.map(driverCard).join('') : '<p class="fk-empty">Keine aktiven Fahrer. Fahrer legst du in den Einstellungen unter Lieferservice an.</p>';
		const os = data.orders.filter(visible);
		const groups = [['open', 'Offen'], ['assigned', 'Zugeteilt'], ['out', 'Unterwegs'], ['kitchen', 'In der Küche']];
		let h = '';
		groups.forEach(g => { const l = os.filter(o => kindOf(o) === g[0]); if (l.length) { h += '<h3 class="fk-grp">' + g[1] + ' (' + l.length + ')</h3>' + l.map(orderCard).join(''); } });
		lo.innerHTML = h || '<p class="fk-empty">' + (filter === 'all' ? 'Keine Lieferungen offen.' : 'Nichts dabei, das zum Filter passt.') + '</p>';
		ld.scrollTop = sd; lo.scrollTop = so;
		$('#fk-n-d').textContent = String(data.drivers.length); $('#fk-n-o').textContent = String(os.length);
	}

	/* ---- details of the selection ---- */
	function candRow(c, o, best) {
		const d = findD(c.driver); if (!d) { return ''; }
		const info = (d.stops.length ? d.stops.length + (d.stops.length === 1 ? ' Stopp' : ' Stopps') : 'frei') + ' · ' + dist(c.dist_m) + ' vom Auftrag' + (c.on_way_m != null ? ' · liegt nah an seinem Stopp (' + dist(c.on_way_m) + ')' : '');
		return '<div class="fk-cand' + (best ? ' is-best' : '') + '" style="--c:' + colorOf(d) + '"><i class="fk-dot"></i><span class="fk-main"><b>' + esc(d.name) + (best ? ' · Empfehlung' : '') + '</b><span>' + esc(info) + '</span></span>' +
			'<button type="button" class="fk-act fk-act--go" data-assign="' + o.id + ':' + d.id + '">Zuteilen</button></div>';
	}
	function orderBody(o, withCands) {
		const d = o.driver_id ? findD(o.driver_id) : null, k = kindOf(o);
		let h = '<div class="fk-dh"><h3>#' + o.day_no + ' · ' + esc(o.customer_name || place(o)) + (o.source === 'lieferando' ? ' <span class="fk-flag fk-flag--lief">Lieferando</span>' : '') + '<small>' + (o.customer_name ? esc(place(o)) + ' · ' : '') + esc(o.street) + ', ' + esc(o.zip) + '</small></h3><button type="button" class="fk-x" data-close aria-label="Schließen">Schließen</button></div>' +
			'<div class="fk-row"><span class="fk-big">' + esc(way(o)) + '</span><span class="fk-txt">' + (o.scheduled ? 'Wunschzeit ' : 'Ziel ') + esc(o.due) + ' Uhr</span>' + (o.late_min > 0 ? '<span class="fk-flag fk-flag--late">' + esc(lateText(o)) + '</span>' : '') + '</div>' +
			(o.door ? '<p class="fk-door">Hinweis: ' + esc(o.door) + '</p>' : '') +
			'<div class="fk-row"><span class="fk-txt">' + esc(o.customer_name) + ' · ' + esc(payText(o)) + '</span>' + (o.phone ? '<a class="fk-act" href="tel:' + esc(String(o.phone).replace(/[^0-9+]/g, '')) + '">Gast anrufen</a>' : '') + '</div>' +
			'<h4 class="fk-sub">Bestellung</h4><ul class="fk-items">' + o.lines.map(l => '<li><b>' + l.qty + '×</b> ' + esc(l.title) + (l.variation ? ' ' + esc(l.variation) : '') + (l.opts ? '<small>+ ' + esc(l.opts) + '</small>' : '') + (l.note ? '<small>' + esc(l.note) + '</small>' : '') + '</li>').join('') + '</ul>' +
			(o.note ? '<p class="fk-door">Anmerkung: ' + esc(o.note) + '</p>' : '');
		if (!withCands) { return h; }
		if (k === 'kitchen') { return h + '<p class="fk-door">Noch in der Küche. Zuteilen geht, sobald der Auftrag fertig ist.</p>'; }
		if (k === 'open') {
			const online = o.cands || [], offline = data.drivers.filter(x => x.state === 'offline');
			h += '<h4 class="fk-sub">Zuteilen an</h4>' + (online.length ? online.slice(0, 4).map((c, i) => candRow(c, o, !!c.best)).join('') : '<p class="fk-door">Kein Fahrer mit aktuellem Standort' + (o.lat == null ? ' (der Auftrag hat keinen Standort)' : '') + '.</p>');
			offline.forEach(x => { h += '<div class="fk-cand" style="--c:' + colorOf(x) + '"><i class="fk-dot"></i><span class="fk-main"><b>' + esc(x.name) + '</b><span>ohne aktuellen Standort</span></span><button type="button" class="fk-act" data-assign="' + o.id + ':' + x.id + '">Zuteilen</button></div>'; });
			return h;
		}
		// assigned or on the way: who has it, take it back, or hand it to somebody else
		h += '<h4 class="fk-sub">' + (k === 'out' ? 'Unterwegs mit ' : 'Zugeteilt an ') + (d ? esc(d.name) : '?') + '</h4><div class="fk-row"><button type="button" class="fk-act" data-release="' + o.id + '">Zurück in den Pool</button></div>';
		if (k === 'assigned' && o.lat != null) {
			const others = data.drivers.filter(x => x.id !== o.driver_id && x.state !== 'offline').map(x => ({ driver: x.id, dist_m: Math.round(haversine(x.lat, x.lng, o.lat, o.lng)), on_way_m: null })).sort((a, b) => a.dist_m - b.dist_m);
			if (others.length) { h += '<h4 class="fk-sub">Umteilen an</h4>' + others.slice(0, 3).map(c => candRow(c, o, false)).join(''); }
		}
		return h;
	}
	function scrubHtml(d) {
		if (data.track_days === 0) { return '<h4 class="fk-sub">Weg</h4><p class="fk-door">Der Weg wird nicht gespeichert (Einstellungen > Lieferservice > Weg der Fahrer).</p>'; }
		const p = track.id === d.id ? track.points : [];
		if (p.length < 2) { return '<h4 class="fk-sub">Weg</h4><p class="fk-door">Noch kein Weg aufgezeichnet.</p>'; }
		const i = scrub === null ? p.length - 1 : Math.min(scrub, p.length - 1), ago = Math.max(0, Math.round((Date.now() / 1000 - p[i][2]) / 60));
		return '<h4 class="fk-sub">Wo war ' + esc(d.name) + '? Weg der letzten Stunden</h4><div class="fk-scrub"><div class="fk-scrub-t"><b id="fk-sc-t">' + hhmm(p[i][2]) + ' Uhr</b><span id="fk-sc-a">' + (scrub === null ? 'jetzt' : 'vor ' + ago + ' Min') + '</span>' +
			'<button type="button" class="fk-act" data-live>Zurück zu jetzt</button></div><input type="range" id="fk-sc" min="0" max="' + (p.length - 1) + '" value="' + i + '" aria-label="Zeitpunkt im Weg des Fahrers"/></div>';
	}
	function driverBody(d, compact) {
		let h = compact ? '<h4 class="fk-sub">Fahrer ' + esc(d.name) + '</h4>' : '<div class="fk-dh"><h3>' + esc(d.name) + '<small>' + stateText(d) + ' · ' + (d.state === 'offline' ? esc(ageText(d)) : 'GPS ' + esc(ageText(d))) + '</small></h3><button type="button" class="fk-x" data-close aria-label="Schließen">Schließen</button></div>';
		h += '<div class="fk-row">' + (d.phone ? '<a class="fk-act" href="tel:' + esc(String(d.phone).replace(/[^0-9+]/g, '')) + '">' + esc(d.name) + ' anrufen</a>' : '<span class="fk-txt">Keine Telefonnummer hinterlegt</span>') +
			(d.eta_min ? '<span class="fk-big">Ankunft beim Gast ca. ' + d.eta_min + ' Min</span>' : '') + (stale(d) ? '<span class="fk-flag fk-flag--warn">Position ' + esc(ageText(d)) + ': Traccar-App prüfen</span>' : '') + '</div>';
		h += '<h4 class="fk-sub">Aufträge (' + d.stops.length + ')</h4>' + (d.stops.length ? '<div class="fk-stops">' + d.stops.map(id => {
			const o = findO(id); if (!o) { return ''; }
			return '<div class="fk-stop" style="--c:' + colorOf(d) + '"><span class="fk-no">' + o.day_no + '</span><span class="fk-main"><b>' + esc(o.customer_name || place(o)) + '</b><span>' + (o.customer_name ? esc(place(o)) + ' · ' : '') + esc(o.street) + (o.status === 'delivering' ? ' · unterwegs' : '') + '</span></span>' +
				'<span><button type="button" class="fk-act" data-pick-o="' + o.id + '">Anzeigen</button> <button type="button" class="fk-act" data-release="' + o.id + '">Zurück in den Pool</button></span></div>';
		}).join('') + '</div>' : '<p class="fk-door">Keine Aufträge.</p>');
		if (!compact) { h += scrubHtml(d); }
		h += '<h4 class="fk-sub">Heute</h4><dl class="fk-dl"><div><dt>Geliefert</dt><dd>' + d.shift.done + '</dd></div><div><dt>Gefahren, geschätzt</dt><dd>ca. ' + num1(d.shift.km) + ' km</dd></div><div><dt>Bar kassiert</dt><dd>' + eur(d.shift.cash) + '</dd></div><div><dt>Karte an der Tür</dt><dd>' + eur(d.shift.card) + '</dd></div></dl>';
		return h;
	}
	function renderDetail() {
		const box = $('#fk-detail'), d = sel.d ? findD(sel.d) : null, o = sel.o ? findO(sel.o) : null;
		if (!d && !o) { box.hidden = true; box.innerHTML = ''; return; }
		if (scrubbing || $('[data-armed="1"]', box)) { return; }
		const st = box.scrollTop; let h = '';
		if (d && o) {
			const can = o.status === 'ready' && o.driver_id !== d.id;
			h = '<div class="fk-dh"><h3>' + esc(d.name) + ' und #' + o.day_no + (o.customer_name ? ' ' + esc(o.customer_name) : '') + '<small>' + esc(place(o)) + ' · ' + esc(o.street) + '</small></h3><button type="button" class="fk-x" data-close aria-label="Schließen">Schließen</button></div>' +
				'<div class="fk-link"><span>' + (can ? 'Zuteilen: #' + o.day_no + ' an ' + esc(d.name) + (d.state === 'offline' ? ' (ohne Standort)' : '') : (o.driver_id === d.id ? '#' + o.day_no + ' gehört schon zu ' + esc(d.name) : (o.status === 'delivering' ? '#' + o.day_no + ' ist schon unterwegs' : 'Noch in der Küche: Zuteilen geht bei Fertig'))) + '</span>' +
				(can ? '<button type="button" class="fk-act fk-act--go" data-assign="' + o.id + ':' + d.id + '">Zuteilen</button>' : '') + '</div>' +
				orderBody(o, false).replace(/<div class="fk-dh">.*?<\/div>(?=<div class="fk-row">)/, '') + driverBody(d, true);
		} else { h = o ? orderBody(o, true) : driverBody(d, false); }
		box.innerHTML = h; box.hidden = false; box.scrollTop = st;
		wireDetail(box);
	}
	function wireDetail(box) {
		$$('[data-close]', box).forEach(b => b.addEventListener('click', clearSel));
		$$('[data-pick-o]', box).forEach(b => b.addEventListener('click', () => { sel.o = +b.dataset.pickO; renderAll(); focusOrder(); }));
		$$('[data-assign]', box).forEach(b => b.addEventListener('click', () => { const p = b.dataset.assign.split(':'); assign(+p[0], +p[1], b); }));
		$$('[data-release]', box).forEach(b => armable(b, 'Wirklich zurückgeben?', 'Zurück in den Pool', () => { b.disabled = true; release(+b.dataset.release); }));
		const live = $('[data-live]', box); if (live) { live.addEventListener('click', () => { scrub = null; renderDetail(); drawMap(false); }); }
		const rg = $('#fk-sc', box);
		if (rg) {
			rg.addEventListener('pointerdown', () => { scrubbing = true; }); rg.addEventListener('pointerup', () => { scrubbing = false; }); rg.addEventListener('pointercancel', () => { scrubbing = false; });
			rg.addEventListener('input', () => {
				const p = track.points, i = +rg.value; scrub = i >= p.length - 1 ? null : i;
				$('#fk-sc-t').textContent = hhmm(p[i][2]) + ' Uhr'; $('#fk-sc-a').textContent = scrub === null ? 'jetzt' : 'vor ' + Math.max(0, Math.round((Date.now() / 1000 - p[i][2]) / 60)) + ' Min';
				drawGhost();
			});
		}
	}

	/* ---- actions ---- */
	function assign(orderId, driverId, btn) {
		if (btn) { btn.disabled = true; }
		post('assign', { id: orderId, driver: driverId }).then(r => {
			if (!r.ok) { toast(r.error || 'Das hat nicht geklappt.'); if (btn) { btn.disabled = false; } load(); return; }
			const o = findO(orderId), d = findD(driverId);
			toast('#' + (o ? o.day_no : '') + ' an ' + (d ? d.name : '') + ' zugeteilt.', 'Rückgängig', () => release(orderId, true));
			load();
		}).catch(() => { toast('Keine Verbindung. Bitte nochmal versuchen.'); if (btn) { btn.disabled = false; } });
	}
	function release(orderId, quiet) {
		post('release_to_pool', { id: orderId }).then(r => { if (!r.ok) { toast(r.error || 'Das hat nicht geklappt.'); } else if (!quiet) { toast('Zurück in den Pool.'); } load(); }).catch(() => toast('Keine Verbindung. Bitte nochmal versuchen.'));
	}

	/* ---- map ---- */
	let map = null, L_ = {};
	function initMap() {
		if (map || !window.L) { return; }
		map = L.map('fk-map', { zoomControl: true, attributionControl: true }).setView([52.1508, 9.9511], 12);
		L.tileLayer('ajax/tile.php?z={z}&x={x}&y={y}', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
		['zones', 'trails', 'routes', 'pins', 'drivers', 'link', 'ghost'].forEach(n => { L_[n] = L.layerGroup().addTo(map); });
		map.on('click', clearSel);
		window.addEventListener('resize', () => map.invalidateSize());
	}
	function icon(cls, html, size, anchor) { return L.divIcon({ className: cls, html: html, iconSize: size, iconAnchor: anchor || [size[0] / 2, size[1] / 2] }); }
	function trailLines(pts, color, weight, alpha) {
		if (pts.length < 2) { return; }
		const n = pts.length, cuts = [0, Math.floor(n / 3), Math.floor(n * 2 / 3), n - 1], ops = [0.25, 0.5, 0.9];
		for (let s = 0; s < 3; s++) {
			const seg = pts.slice(cuts[s], cuts[s + 1] + 1).map(p => [p[0], p[1]]);
			if (seg.length > 1) { L.polyline(seg, { color: color, weight: weight, opacity: ops[s] * alpha, lineCap: 'round', interactive: false }).addTo(L_.trails); }
		}
	}
	function drawZones() {
		if (!map) { return; }
		L_.zones.clearLayers();
		if (!zonesOn || !zones) { return; }
		zones.forEach(z => L.polygon(z.polygon, { color: '#c9a259', weight: 1.5, dashArray: '6 6', fillColor: '#c9a259', fillOpacity: z.active ? 0.07 : 0.02, interactive: false }).addTo(L_.zones).bindTooltip(z.name, { permanent: true, direction: 'center', className: 'fk-tip' }));
	}
	function drawGhost() {
		if (!map) { return; }
		L_.ghost.clearLayers();
		const d = sel.d ? findD(sel.d) : null;
		if (scrub !== null && d && track.id === d.id && track.points[scrub]) { const p = track.points[scrub]; L.marker([p[0], p[1]], { icon: icon('', '<div class="fk-ghost"></div>', [26, 26]), interactive: false, keyboard: false }).addTo(L_.ghost); if (!map.getBounds().pad(-0.1).contains([p[0], p[1]])) { map.panTo([p[0], p[1]]); } }
	}
	function drawMap(fit) {
		if (!data) { return; }
		initMap(); if (!map) { return; }
		['trails', 'routes', 'pins', 'drivers', 'link'].forEach(n => L_[n].clearLayers());
		const all = [], dimD = !!sel.d;
		if (data.origin) { const ll = [data.origin.lat, data.origin.lng]; all.push(ll); L.marker(ll, { icon: icon('fk-shop', '<span><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11l8-7 8 7M6 10v9h12v-9"/></svg></span>', [34, 34]), interactive: false, keyboard: false }).addTo(L_.pins); }
		data.drivers.forEach(d => {
			const c = colorOf(d), isSel = sel.d === d.id, p = (isSel && track.id === d.id && track.points.length > 1) ? track.points : d.trail;
			if (trailsOn && d.state !== 'offline') { trailLines(p, c, isSel ? 5 : 3, dimD && !isSel ? 0.4 : 1); }
			if (d.lat == null) { return; }
			const here = [d.lat, d.lng]; all.push(here);
			let prev = here;
			d.stops.forEach(id => { const o = findO(id); if (o && o.lat != null) { L.polyline([prev, [o.lat, o.lng]], { color: c, weight: isSel ? 3 : 2, opacity: dimD && !isSel ? 0.25 : 0.7, dashArray: '8 8', interactive: false }).addTo(L_.routes); prev = [o.lat, o.lng]; } });
			L.marker(here, { icon: icon('', '<div class="fk-drv' + (stale(d) ? ' is-stale' : '') + (isSel ? ' is-sel' : '') + (dimD && !isSel ? ' is-dim' : '') + '" style="--c:' + c + '"><i></i>' + esc(d.name) + '</div>', [0, 0], [0, 0]), zIndexOffset: isSel ? 900 : 600, keyboard: false })
				.on('click', () => selectDriver(d.id)).addTo(L_.drivers);
		});
		data.orders.filter(visible).forEach(o => {
			if (o.lat == null) { return; }
			const k = kindOf(o), d = o.driver_id ? findD(o.driver_id) : null, ll = [o.lat, o.lng]; all.push(ll);
			const dim = (sel.d && o.driver_id !== sel.d && sel.o !== o.id) ? ' is-dim' : '';
			L.marker(ll, { icon: icon('fk-pin fk-pin--' + k + (o.late_min > 0 ? ' is-late' : '') + (sel.o === o.id ? ' is-sel' : '') + dim, '<span' + (d ? ' style="--c:' + colorOf(d) + '"' : '') + '>' + o.day_no + '</span>', [34, 34]), zIndexOffset: sel.o === o.id ? 800 : (k === 'open' ? 300 : 100), keyboard: false })
				.on('click', () => selectOrder(o.id)).addTo(L_.pins);
		});
		const d = sel.d ? findD(sel.d) : null, o = sel.o ? findO(sel.o) : null;
		if (d && o && d.lat != null && o.lat != null) { L.polyline([[d.lat, d.lng], [o.lat, o.lng]], { color: '#f6f1e6', weight: 3, opacity: 0.9, dashArray: '2 8', interactive: false }).addTo(L_.link); }
		drawGhost();
		if ((fit || first) && all.length) { first = false; map.fitBounds(all, { padding: [60, 60], maxZoom: 15 }); }
	}
	function focusDriver() {
		const d = findD(sel.d); if (!d || !map || d.lat == null) { return; }
		const pts = [[d.lat, d.lng]]; d.stops.forEach(id => { const o = findO(id); if (o && o.lat != null) { pts.push([o.lat, o.lng]); } });
		if (pts.length === 1) { map.setView(pts[0], Math.max(map.getZoom(), 14)); } else { map.fitBounds(pts, { padding: [90, 90], maxZoom: 15 }); }
	}
	function focusOrder() {
		const o = findO(sel.o); if (!o || !map || o.lat == null) { return; }
		const pts = [[o.lat, o.lng]]; (o.cands || []).slice(0, 2).forEach(c => { const d = findD(c.driver); if (d && d.lat != null) { pts.push([d.lat, d.lng]); } });
		if (sel.d) { const d = findD(sel.d); if (d && d.lat != null) { pts.push([d.lat, d.lng]); } }
		if (pts.length === 1) { map.setView(pts[0], Math.max(map.getZoom(), 14)); } else { map.fitBounds(pts, { padding: [90, 90], maxZoom: 15 }); }
	}

	/* ---- selection ---- */
	function renderAll() { counts(); renderLists(); renderDetail(); drawMap(false); }
	function clearSel() { sel = { d: 0, o: 0 }; scrub = null; track = { id: 0, points: [] }; renderAll(); }
	function selectDriver(id) {
		sel.d = sel.d === id ? 0 : id; scrub = null; track = { id: 0, points: [] };
		renderAll(); if (sel.d) { focusDriver(); loadTrack(); }
	}
	function selectOrder(id) { sel.o = sel.o === id ? 0 : id; renderAll(); if (sel.o) { focusOrder(); } }
	function loadTrack() {
		const id = sel.d; if (!id || !data || data.track_days === 0) { return; }
		get('track', '&driver=' + id + '&hours=6').then(r => { if (r.ok && sel.d === id) { track = { id: id, points: r.points }; if (!scrubbing) { renderDetail(); } drawMap(false); } }).catch(() => {});
	}

	/* ---- polling ---- */
	function load() {
		return get('dispatch_map').then(r => {
			if (!r.ok) { return; }
			offlineSince = 0; $('#k-offline').hidden = true; data = r; polledAt = Date.now(); pollN++;
			if (sel.d && !findD(sel.d)) { sel.d = 0; } if (sel.o && !findO(sel.o)) { sel.o = 0; }
			renderAll(); upd();
			if (preselect) { const id = preselect; preselect = 0; if (findO(id)) { sel.o = id; renderAll(); focusOrder(); } }
			if (sel.d && pollN % 3 === 0) { loadTrack(); }
		}).catch(() => { if (!offlineSince) { offlineSince = Date.now(); } $('#k-offline').hidden = false; upd(); });
	}
	function upd() {
		const el = $('#fk-upd'), s = polledAt ? Math.round((Date.now() - polledAt) / 1000) : null;
		el.textContent = s === null ? 'Lädt …' : 'aktualisiert vor ' + s + ' s'; el.classList.toggle('is-old', s !== null && s > 30);
	}
	function clock() { $('#k-clock').textContent = new Date().toTimeString().slice(0, 5); }

	$('#fk-drivers').addEventListener('click', e => { const b = e.target.closest('[data-d]'); if (b) { selectDriver(+b.dataset.d); } });
	$('#fk-orders').addEventListener('click', e => { const b = e.target.closest('[data-o]'); if (b) { selectOrder(+b.dataset.o); } });

	/* ---- header controls ---- */
	function filters() { $$('#fk-filter button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.filter === filter))); }
	$$('#fk-filter button').forEach(b => b.addEventListener('click', () => { filter = b.dataset.filter; store.set('fkFilter', filter); filters(); if (data) { renderAll(); } }));
	$('#fk-trails').addEventListener('click', e => { trailsOn = !trailsOn; store.set('fkTrails', trailsOn ? '1' : '0'); e.currentTarget.setAttribute('aria-pressed', String(trailsOn)); drawMap(false); });
	$('#fk-zones').addEventListener('click', e => {
		zonesOn = !zonesOn; store.set('fkZones', zonesOn ? '1' : '0'); e.currentTarget.setAttribute('aria-pressed', String(zonesOn));
		if (zonesOn && !zones) { get('zones').then(r => { zones = r.ok ? r.zones : []; drawZones(); }).catch(() => {}); } else { drawZones(); }
	});
	$('#fk-fit').addEventListener('click', () => drawMap(true));
	$('#k-full').addEventListener('click', () => { if (document.fullscreenElement) { document.exitFullscreen(); } else if (document.documentElement.requestFullscreen) { document.documentElement.requestFullscreen(); } });
	document.addEventListener('keydown', e => { if (e.key === 'Escape' && (sel.d || sel.o)) { clearSel(); } });
	$('#fk-trails').setAttribute('aria-pressed', String(trailsOn)); $('#fk-zones').setAttribute('aria-pressed', String(zonesOn));
	filters();
	if (zonesOn) { get('zones').then(r => { zones = r.ok ? r.zones : []; initMap(); drawZones(); }).catch(() => {}); }

	clock(); setInterval(clock, 30000); setInterval(upd, 1000);
	load(); setInterval(load, POLL_MS);
	document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { load(); } });
})();
