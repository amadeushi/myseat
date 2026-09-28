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
	function payText(o) {
		if (o.pay === 'lieferando') { return '<span class="k-badge paid">bei Lieferando bezahlt</span>'; }
		if (o.pay === 'mollie') { return '<span class="k-badge paid">online bezahlt</span>'; }
		return '<span class="k-badge cash">' + (o.pay === 'cash' ? 'bar kassieren' : 'Karte kassieren') + ' ' + money(o.total) + '</span>';
	}
	// the driver gets a link to his own page for this order (start trip, share position, handed over); the guest sees him on the map
	function driverUrl(o) { return new URL('../order/driver.php?t=' + encodeURIComponent(o.token) + '&k=' + encodeURIComponent(o.dkey), location.href).href; }
	function driverBtns(o) {
		if (!o.dkey) { return ''; }
		var live = o.driver_age !== null && o.driver_age !== undefined ? '<span class="k-badge paid">Fahrer teilt Standort</span>' : '';
		return live + '<button type="button" class="k-go secondary" data-driver="' + o.id + '" data-url="' + esc(driverUrl(o)) + '">Fahrer-Link kopieren</button>' +
			'<a class="k-go secondary" target="_blank" rel="noopener" href="https://wa.me/?text=' + encodeURIComponent('Lieferung #' + o.day_no + ' ' + o.address + ': ' + driverUrl(o)) + '">per WhatsApp</a>';
	}
	function card(o) {
		var late = false, dueTxt = o.scheduled ? o.scheduled : o.due, sub = o.scheduled ? 'geplant' : (o.status === 'new' ? 'sofort' : 'bis');
		var ageMin = Math.max(0, Math.round((Date.now() / 1000 - o.created_ts) / 60));
		if (o.status === 'new' && ageMin >= 8) { late = true; }
		var h = '<article class="k-card' + (o.status === 'new' ? ' is-new' : '') + (late ? ' is-late' : '') + '" data-id="' + o.id + '"><div class="k-head"><span class="k-no">#' + o.day_no + '</span><span class="k-type ' + o.type + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			(o.source === 'lieferando' ? '<span class="k-badge lief">Lieferando</span>' : '') +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-due' + (late ? ' k-late' : '') + '">' + esc(dueTxt) + '<small>' + sub + (o.status === 'new' ? ' · vor ' + ageMin + ' Min' : '') + '</small></span></div>' +
			'<p class="k-who">' + esc(o.name) + ' · ' + esc(o.phone) + '</p>' + (o.address ? '<p class="k-addr">' + esc(o.address) + (o.address_note ? ' (' + esc(o.address_note) + ')' : '') + '</p>' : '') +
			'<ul class="k-items">' + o.items.map(function (it) {
				return '<li class="k-item"><span class="k-qty">' + it.qty + '×</span>' + esc(it.title) + (it.variation ? ' <span class="k-var">' + esc(it.variation) + '</span>' : '') +
					(it.options.length ? '<div class="k-opts">+ ' + it.options.map(esc).join(', ') + '</div>' : '') + (it.note ? '<span class="k-inote">' + esc(it.note) + '</span>' : '') + '</li>';
			}).join('') + '</ul>' + (o.note ? '<p class="k-onote">' + esc(o.note) + '</p>' : '') + '<div class="k-pay">' + payText(o) + '<button type="button" class="k-go secondary k-bonbtn" data-bon="' + o.id + '">Lieferschein drucken</button></div><div class="k-actions">';
		if (o.status === 'new') {
			h += '<span class="k-eta-l">Annehmen, fertig in:</span>' + [20, 30, 45, 60].map(function (m) { return '<button type="button" class="k-go eta" data-act="accept" data-eta="' + m + '">' + m + ' Min</button>'; }).join('') + '<button type="button" class="k-go secondary" data-act="cancelled">Ablehnen</button>';
		} else if (o.status === 'accepted') { h += '<button type="button" class="k-go" data-act="preparing">Wird gekocht</button>'; }
		else if (o.status === 'preparing') { h += '<button type="button" class="k-go" data-act="ready">Fertig</button>'; }
		else if (o.status === 'ready') { h += o.type === 'delivery' ? driverBtns(o) + '<button type="button" class="k-go" data-act="delivering">Unterwegs</button>' : '<button type="button" class="k-go" data-act="done">Abgeholt</button>'; }
		else if (o.status === 'delivering') { h += driverBtns(o) + '<button type="button" class="k-go" data-act="done">Geliefert</button>'; }
		return h + '</div></article>';
	}
	function render(orders) {
		var cols = { 'new': [], work: [], ready: [] };
		orders.forEach(function (o) { cols[column(o)].push(o); });
		Object.keys(cols).forEach(function (k) {
			$('#col-' + k).innerHTML = cols[k].length ? cols[k].map(card).join('') : '<p class="k-empty">' + (k === 'new' ? 'Keine neuen Bestellungen' : 'Nichts hier') + '</p>';
			$('#n-' + k).textContent = cols[k].length;
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
		var drv = ev.target.closest('[data-driver]');
		if (drv) {
			var url = drv.dataset.url, done = function () { drv.textContent = 'Link kopiert'; setTimeout(function () { drv.textContent = 'Fahrer-Link kopieren'; }, 2500); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { notify(url); }); } else { notify(url); }
			return;
		}
		var bonBtn = ev.target.closest('[data-bon]'); if (bonBtn) { MonitorPrint.slip(bonBtn.dataset.bon, true); return; }
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
})();
