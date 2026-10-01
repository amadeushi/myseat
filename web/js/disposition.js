/* Kitchen monitor: loads the board every few seconds, plays a sound for a new order, moves orders on with one tap. */
(function () {
	'use strict';
	var TOKEN = document.body.dataset.token, seen = null, lastOk = Date.now();

	function $(s) { return document.querySelector(s); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }

	// ---- sound: shared module (choice of sounds, volume, repeat until somebody taps the order)
	var acked = {}, current = [];
	var sound = MonitorSound.create({ key: 'dispatch', mount: $('#k-tools'), pending: function () { return current.filter(function (o) { return o.status === 'new' && !acked[o.id]; }).length; } });
	$('#k-full').addEventListener('click', function () { var d = document.documentElement; if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); } });

	// ---- clock
	function tick() { var d = new Date(); $('#k-clock').textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
	tick(); setInterval(tick, 10000);

	// ---- cards
	function column(o) { return o.status === 'new' ? 'new' : (o.status === 'accepted' || o.status === 'preparing' ? 'work' : 'ready'); }
	function failReasonText(o) { return '<p class="k-onote k-fail-reason">Fehlgeschlagen: ' + esc(o.fail_reason || 'kein Grund angegeben') + '</p>'; }
	function payText(o) {
		if (o.pay === 'lieferando') { return '<span class="k-badge paid">bei Lieferando bezahlt</span>'; }
		if (o.pay === 'mollie') { return '<span class="k-badge paid">online bezahlt</span>'; }
		return '<span class="k-badge cash">' + (o.pay === 'cash' ? 'bar kassieren' : 'Karte kassieren') + ' ' + money(o.total) + '</span>';
	}
	// the guest's own live-tracking link (order/status.php) - sent automatically by SMS once set up, but
	// not every guest has SMS enabled or a mobile number, so dispatch can still hand it over by hand
	function guestUrl(o) { return new URL('../order/status.php?t=' + encodeURIComponent(o.token), location.href).href; }
	// a driver claims his own delivery from his own queue (order/driver.php) now - nothing to send him
	// here, just show who has it once someone did, and whether his phone is currently reporting in
	function driverBadge(o) {
		if (!o.driver_name) { return ''; }
		var live = o.driver_age !== null && o.driver_age !== undefined ? ' · teilt Standort' : '';
		return '<span class="k-badge paid">Fahrer: ' + esc(o.driver_name) + live + '</span>';
	}
	function card(o) {
		var late = false, dueTxt = o.scheduled ? o.scheduled : o.due, sub = o.scheduled ? 'geplant' : (o.status === 'new' ? 'sofort' : 'bis');
		var ageMin = Math.max(0, Math.round((Date.now() / 1000 - o.created_ts) / 60));
		if (o.status === 'new' && ageMin >= 8) { late = true; }
		// "late" (still just waiting) and "failed" (actually broken) used to share the same danger-red ring -
		// late now escalates through amber plus this text label, never through the failure color alone
		var h = '<article class="k-card' + (o.status === 'new' ? ' is-new' : '') + (late ? ' is-late' : '') + (o.status === 'failed' ? ' is-failed' : '') + '" data-id="' + o.id + '"><div class="k-head"><span class="k-no">#' + o.day_no + '</span><span class="k-type ' + o.type + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			(o.source === 'lieferando' ? '<span class="k-badge lief">Lieferando</span>' : '') +
			(o.source === 'phone' ? '<span class="k-badge">Telefon</span>' : '') +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-due' + (late ? ' k-late' : '') + '">' + esc(dueTxt) + '<small>' + sub + (o.status === 'new' ? ' · vor ' + ageMin + ' Min' : '') + (late ? ' · VERSPÄTET' : '') + '</small></span></div>' +
			'<p class="k-who">' + esc(o.name) + (o.phone ? ' · ' + esc(o.phone) : '') + '</p>' + (o.address ? '<p class="k-addr">' + esc(o.address) + (o.address_note ? ' (' + esc(o.address_note) + ')' : '') + '</p>' : '') +
			(o.status === 'failed' ? failReasonText(o) : '') +
			'<ul class="k-items">' + o.items.map(function (it) {
				return '<li class="k-item"><span class="k-qty">' + it.qty + '×</span>' + esc(it.title) + (it.variation ? ' <span class="k-var">' + esc(it.variation) + '</span>' : '') +
					(it.options.length ? '<div class="k-opts">+ ' + it.options.map(esc).join(', ') + '</div>' : '') + (it.note ? '<span class="k-inote">' + esc(it.note) + '</span>' : '') + '</li>';
			}).join('') + '</ul>' + (o.note ? '<p class="k-onote">' + esc(o.note) + '</p>' : '') + '<div class="k-pay">' + payText(o) +
			'<button type="button" class="k-go secondary k-bonbtn" data-bon="' + o.id + '">Lieferschein drucken</button>' +
			'<button type="button" class="k-go secondary" data-guestlink="' + esc(guestUrl(o)) + '">Gast-Link kopieren</button></div><div class="k-actions">';
		if (o.status === 'new') {
			h += '<span class="k-eta-l">Annehmen, fertig in:</span>' + [20, 30, 45, 60].map(function (m) { return '<button type="button" class="k-go eta" data-act="accept" data-eta="' + m + '">' + m + ' Min</button>'; }).join('') + '<button type="button" class="k-go secondary" data-act="cancelled">Ablehnen</button>';
		} else if (o.status === 'accepted') { h += '<button type="button" class="k-go" data-act="preparing">Wird gekocht</button>'; }
		else if (o.status === 'preparing') { h += '<button type="button" class="k-go" data-act="ready">Fertig</button>'; }
		else if (o.status === 'ready') {
			// a driver may already have this one in his own, not-yet-started queue (order/driver.php) -
			// dispatch sees who, and can still force it along or pull it back regardless
			h += (o.driver_name ? driverBadge(o) + '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button>' : '') +
				(o.type === 'delivery' ? '<button type="button" class="k-go" data-act="delivering">Unterwegs (ohne Fahrer-App)</button>' : '<button type="button" class="k-go" data-act="done">Abgeholt</button>');
		}
		else if (o.status === 'delivering') { h += driverBadge(o) + '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button><button type="button" class="k-go" data-act="done">Geliefert</button>'; }
		else if (o.status === 'failed') { h += driverBadge(o) + '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button><button type="button" class="k-go secondary" data-act="cancelled">Stornieren</button>'; }
		return h + '</div></article>';
	}
	// diff-rendered by order id instead of a wholesale innerHTML replace every poll: a full rebuild used to wipe
	// out an in-progress "wirklich ablehnen?" confirm (its armed state lives on the button's own DOM node) and
	// reset every column's scroll position, both every 6 seconds even when nothing about that order changed
	var cardNodes = {}, cardSig = {};
	function render(orders) {
		var cols = { 'new': [], work: [], ready: [] };
		orders.forEach(function (o) { cols[column(o)].push(o); });
		var liveIds = {};
		Object.keys(cols).forEach(function (k) {
			var root = $('#col-' + k), scrollTop = root.scrollTop;
			if (!cols[k].length) {
				root.innerHTML = '<p class="k-empty">' + (k === 'new' ? 'Alles erledigt' : 'Nichts hier') + '</p>';
			} else {
				if (root.firstElementChild && root.firstElementChild.classList.contains('k-empty')) { root.innerHTML = ''; }
				cols[k].forEach(function (o) {
					liveIds[o.id] = true;
					var sig = JSON.stringify(o);
					if (!cardNodes[o.id] || cardSig[o.id] !== sig) {
						var tmp = document.createElement('div'); tmp.innerHTML = card(o);
						var fresh = tmp.firstElementChild;
						if (cardNodes[o.id] && cardNodes[o.id].parentNode) { cardNodes[o.id].replaceWith(fresh); }
						cardNodes[o.id] = fresh; cardSig[o.id] = sig;
					}
					if (cardNodes[o.id].parentNode !== root) { root.appendChild(cardNodes[o.id]); }
				});
			}
			root.scrollTop = scrollTop;
			$('#n-' + k).textContent = cols[k].length;
			// a column silently overflowing below the fold (a busy night queuing orders nobody is expected to
			// scroll for) used to give zero signal - a persistent hint below the list fixes that
			var of = $('#of-' + k);
			if (of) { of.hidden = root.scrollHeight <= root.clientHeight + 1; of.textContent = 'Weitere Bestellungen unten'; }
		});
		Object.keys(cardNodes).forEach(function (id) {
			if (!liveIds[id]) { if (cardNodes[id].parentNode) { cardNodes[id].remove(); } delete cardNodes[id]; delete cardSig[id]; }
		});
		$('#k-counts').innerHTML = '<span class="k-count">Neu <b>' + cols['new'].length + '</b></span><span class="k-count">In der Küche <b>' + cols.work.length + '</b></span><span class="k-count">Fertig <b>' + cols.ready.length + '</b></span>';
		document.title = (cols['new'].length ? '(' + cols['new'].length + ') ' : '') + 'Disposition';
	}

	// a message in the red bar on top (works in full screen, unlike alert)
	function notify(text) { var el = $('#k-offline'); el.textContent = text; el.hidden = false; setTimeout(function () { el.textContent = 'Keine Verbindung. Ich versuche es weiter ...'; el.hidden = true; }, 5000); }

	// ---- board loop
	function load() {
		fetch('ajax/shop_orders.php?op=board', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (r.status === 403) { location.href = '../PLC/index.php'; throw new Error('login'); }
			return r.json();
		}).then(function (r) {
			if (!r.ok) { throw new Error('bad'); }
			$('#k-offline').hidden = true; lastOk = Date.now();
			var ids = r.orders.filter(function (o) { return o.status === 'new'; }).map(function (o) { return o.id; });
			current = r.orders;
			Object.keys(acked).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete acked[k]; } });
			if (seen !== null && ids.some(function (id) { return seen.indexOf(id) < 0; })) { sound.notify(); }
			seen = ids;
			render(r.orders); sound.ack();
		}).catch(function (e) { if (e.message !== 'login' && Date.now() - lastOk > 20000) { $('#k-offline').hidden = false; } });
	}
	document.addEventListener('click', function (ev) {
		var cardEl = ev.target.closest('.k-card');
		if (cardEl && !acked[cardEl.dataset.id]) { acked[cardEl.dataset.id] = true; cardEl.classList.remove('is-new'); sound.ack(); }
		var bonBtn = ev.target.closest('[data-bon]'); if (bonBtn) { MonitorPrint.slip(bonBtn.dataset.bon, true); return; }
		var gl = ev.target.closest('[data-guestlink]');
		if (gl) {
			var url = gl.dataset.guestlink, done = function () { gl.textContent = 'Link kopiert'; setTimeout(function () { gl.textContent = 'Gast-Link kopieren'; }, 2500); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { notify(url); }); } else { notify(url); }
			return;
		}
		var rel = ev.target.closest('[data-release]');
		if (rel) {
			if (!rel.dataset.armed) { rel.dataset.armed = '1'; rel.textContent = 'Wirklich zurückgeben?'; setTimeout(function () { rel.dataset.armed = ''; rel.textContent = 'Zurück in den Pool'; }, 4000); return; }
			rel.disabled = true;
			var fd2 = new FormData(); fd2.append('op', 'release_to_pool'); fd2.append('token', TOKEN); fd2.append('id', rel.dataset.release);
			fetch('ajax/shop_orders.php', { method: 'POST', body: fd2, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
				if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); }
				load();
			}).catch(function () { notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); rel.disabled = false; });
			return;
		}
		var b = ev.target.closest('.k-go[data-act]'); if (!b) { return; }
		var card = b.closest('.k-card'), act = b.dataset.act, status = act === 'accept' ? 'accepted' : act;
		// no browser dialogs here: in full screen they stay invisible. Rejecting needs a second tap on the same button.
		if (act === 'cancelled' && !b.dataset.armed) {
			b.dataset.armed = '1'; b.textContent = 'Wirklich ablehnen?'; b.classList.add('danger');
			setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Ablehnen'; b.classList.remove('danger'); }, 4000);
			return;
		}
		b.disabled = true;
		var fd = new FormData(); fd.append('op', 'status'); fd.append('token', TOKEN); fd.append('id', card.dataset.id); fd.append('status', status); if (b.dataset.eta) { fd.append('eta', b.dataset.eta); }
		fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); }
			load();
		}).catch(function () { notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); b.disabled = false; });
	});
	load(); setInterval(load, 6000);

	// ---- Fahrer-Karte: every active driver's current position, plus the delivery (if any) he has.
	// Only built and polled while the panel is actually open, to not waste a Leaflet map on a monitor
	// where nobody ever looks at it.
	(function () {
		var toggle = $('#k-drivers-toggle'), panel = $('#k-drivers'), closeBtn = $('#k-drivers-close'); if (!toggle || !panel) { return; }
		var map = null, markers = {}, poll = null;
		function colorFor(id) { return ['#c9a259', '#62b6cb', '#8fbf7a', '#a99be8', '#e2b56b', '#c65a4f'][id % 6]; }
		function ensureMap(origin) {
			if (map) { return; }
			map = L.map('k-drivers-map').setView(origin ? [origin[0], origin[1]] : [52.1508, 9.9511], 13);
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 }).addTo(map);
		}
		function refresh() {
			fetch('ajax/shop_orders.php?op=drivers_live', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) {
				if (!r.ok) { return; }
				ensureMap(r.origin);
				var seenIds = {};
				r.drivers.forEach(function (d) {
					seenIds[d.id] = true;
					var label = esc(d.name) + (d.order ? ' · Lieferung #' + d.order.day_no : ' · ohne Lieferung');
					if (markers[d.id]) { markers[d.id].setLatLng([d.lat, d.lng]).setTooltipContent(label); }
					else {
						markers[d.id] = L.circleMarker([d.lat, d.lng], { radius: 9, color: colorFor(d.id), weight: 2, fillOpacity: 0.85 }).addTo(map).bindTooltip(label, { permanent: true, direction: 'top', className: 'k-drivers-tip' });
					}
				});
				Object.keys(markers).forEach(function (id) { if (!seenIds[id]) { map.removeLayer(markers[id]); delete markers[id]; } });
				$('#k-drivers-note').textContent = r.drivers.length ? '' : 'Gerade kein Fahrer mit aktuellem Standort.';
			}).catch(function () {});
		}
		toggle.addEventListener('click', function () {
			var open = panel.hidden;
			panel.hidden = !open; toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
			if (open) { refresh(); if (map) { setTimeout(function () { map.invalidateSize(); }, 50); } poll = setInterval(refresh, 10000); }
			else if (poll) { clearInterval(poll); poll = null; }
		});
		if (closeBtn) { closeBtn.addEventListener('click', function () { toggle.click(); }); }
	})();
})();
