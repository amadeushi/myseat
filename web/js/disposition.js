/* Dispatch screen: loads the board every few seconds, plays a sound for a new order, moves orders on with one tap.
   Landscape: three columns (new, in the kitchen, ready). Portrait (a monitor on its side): bands from top to bottom by urgency - new (decide),
   ready and waiting for a driver, on the road - with the kitchen as one line; every band pages with big buttons (a trackball is the input). */
(function () {
	'use strict';
	var TOKEN = document.body.dataset.token, seen = null, lastOk = Date.now();

	function $(s, r) { return (r || document).querySelector(s); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function nowS() { return Date.now() / 1000; }

	// ---- layout: a big monitor on its side (from about 1000 x 1500) gets the bands (the class is set here so the style sheet and this script agree);
	// a smaller portrait screen (tablet) keeps the single scrolling column of the style sheet
	var PQ = window.matchMedia('(orientation: portrait) and (min-width: 1000px) and (min-height: 1500px)'), P = false;
	function applyLayout() {
		P = PQ.matches; document.body.classList.toggle('is-portrait', P);
		var h = $('#kc-ready'); if (h && h.firstChild) { h.firstChild.nodeValue = P ? 'Fertig, wartet auf Fahrer ' : 'Fertig '; }
	}
	applyLayout();

	// ---- state
	var acked = {}, current = [], drivers = [], pause = null, driveMin = 15;
	var MAXPAGE = { 'new': 3, ready: 4, out: 4 }, PAGE = { 'new': 3, ready: 4, out: 4 }, page = { 'new': 0, ready: 0, out: 0 }, lastAct = Date.now(), IDLE_BACK_MS = 45000;
	var workOpen = false, assignOpen = {}, assignAll = {}, retryOpen = {}, newFlash = false;

	// ---- automatic slip: "Bon bei Annahme". Every order that is accepted (by the dispatcher here, or at once when it comes from the till, Lieferando or Uber Eats) prints its delivery slip through the browser, once.
	// What is on the board when the page is opened counts as printed already; the list is kept in the session, so a reload does not print again. The slips go out four seconds apart (a new print replaces the one before in the frame).
	var autoPrint = false, printed = {}, printBase = false, printQueue = [], printBusy = false;
	try { autoPrint = localStorage.getItem('dpAutoPrint') === '1'; var pj = sessionStorage.getItem('dpPrinted'); if (pj) { printed = JSON.parse(pj) || {}; printBase = true; } } catch (e) {}
	function syncAuto() { var a = $('#k-auto'); if (!a) { return; } a.setAttribute('aria-pressed', autoPrint ? 'true' : 'false'); a.textContent = 'Bon bei Annahme: ' + (autoPrint ? 'an' : 'aus'); }
	function pumpPrint() {
		if (printBusy || !printQueue.length) { return; }
		printBusy = true; MonitorPrint.slip(printQueue.shift(), true);
		setTimeout(function () { printBusy = false; pumpPrint(); }, 4000);
	}
	function autoPrintCheck(orders) {
		orders.forEach(function (o) {
			if (['accepted', 'preparing', 'ready', 'delivering', 'done'].indexOf(o.status) < 0 || printed[o.id]) { return; }
			printed[o.id] = 1;
			if (printBase && autoPrint) { printQueue.push(o.id); }
		});
		printBase = true;
		try { sessionStorage.setItem('dpPrinted', JSON.stringify(printed)); } catch (e) {}
		pumpPrint();
	}

	function column(o) {
		if (P) { return o.status === 'new' ? 'new' : (o.status === 'accepted' || o.status === 'preparing' ? 'work' : (o.status === 'ready' ? 'ready' : (o.status === 'delivering' ? 'out' : 'fail'))); }
		return o.status === 'new' ? 'new' : (o.status === 'accepted' || o.status === 'preparing' ? 'work' : 'ready');
	}
	function outTs(o) { return o.due_ts - (o.type === 'delivery' ? driveMin * 60 : 0); }
	function bandList(k) {
		var l = current.filter(function (o) { return column(o) === k; });
		if (k === 'ready') { l.sort(function (a, b) { return outTs(a) - outTs(b) || (a.ready_ts || a.created_ts) - (b.ready_ts || b.created_ts) || a.id - b.id; }); } // what is most overdue stands first
		if (k === 'out') { l.sort(function (a, b) { return a.due_ts - b.due_ts || a.id - b.id; }); }
		if (k === 'work') { l.sort(function (a, b) { return outTs(a) - outTs(b) || a.id - b.id; }); }
		return l;
	}
	function shown(k) { var l = bandList(k); return (P && PAGE[k]) ? l.slice(page[k] * PAGE[k], page[k] * PAGE[k] + PAGE[k]) : l; }
	function pagesOf(k) { return Math.max(1, Math.ceil(bandList(k).length / PAGE[k])); }

	// ---- sound: shared module (choice of sounds, volume, repeat until somebody taps the order). Only the new orders on screen keep it going.
	var sound = MonitorSound.create({ key: 'dispatch', mount: $('#k-tools'), pending: function () { return shown('new').filter(function (o) { return !acked[o.id]; }).length; } });
	syncAuto();
	$('#k-auto').addEventListener('click', function () { autoPrint = !autoPrint; try { localStorage.setItem('dpAutoPrint', autoPrint ? '1' : '0'); } catch (e) {} syncAuto(); });
	$('#k-full').addEventListener('click', function () { var d = document.documentElement; if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); } });

	// ---- clock
	function tick() { var d = new Date(); $('#k-clock').textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
	tick(); setInterval(tick, 10000);

	// ---- cards
	function failReasonText(o) { return '<p class="k-onote k-fail-reason">Fehlgeschlagen: ' + esc(o.fail_reason || 'kein Grund angegeben') + '</p>'; }
	function payText(o) {
		if (o.pay === 'lieferando') { return '<span class="k-badge paid">bei Lieferando bezahlt</span>'; }
		if (o.pay === 'uber_eats') { return '<span class="k-badge paid">bei Uber Eats bezahlt</span>'; }
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
	// print slip and guest link as two drawn symbols (same stroke) instead of two text buttons on every card
	var ICON_PRINT = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M7 9V4h10v5M7 17H5a1.5 1.5 0 0 1-1.500-1.500v-5A1.500 1.500 0 0 1 5 9h14a1.500 1.500 0 0 1 1.500 1.500v5A1.500 1.500 0 0 1 19 17h-2M7 14h10v6H7z" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_LINK = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_CHECK = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M5 12.500l4.500 4.500L19 7.500" fill="none" stroke="currentColor" stroke-width="2.400" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function moreHtml(o) {
		return '<span class="k-icons"><button type="button" class="k-icon" data-bon="' + o.id + '" aria-label="Lieferschein drucken" title="Lieferschein drucken">' + ICON_PRINT + '</button>' +
			'<button type="button" class="k-icon" data-guestlink="' + esc(guestUrl(o)) + '" aria-label="Gast-Link kopieren" title="Gast-Link kopieren">' + ICON_LINK + '</button></span>';
	}
	function card(o) {
		var late = false, dueTxt = o.scheduled ? o.scheduled : o.due, sub = o.scheduled ? 'geplant' : (o.status === 'new' ? 'sofort' : 'bis');
		var ageMin = Math.max(0, Math.round((nowS() - o.created_ts) / 60));
		if (o.status === 'new' && ageMin >= 8) { late = true; }
		// "late" (still just waiting) and "failed" (actually broken) used to share the same danger-red ring -
		// late now escalates through amber plus this text label, never through the failure color alone
		var h = '<article class="k-card' + (o.status === 'new' ? ' is-new' : '') + (late ? ' is-late' : '') + (o.status === 'failed' ? ' is-failed' : '') + '" data-id="' + o.id + '"><div class="k-head"><span class="k-no">#' + o.day_no + '</span><span class="k-type ' + esc(o.type) + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			(o.source === 'lieferando' ? '<span class="k-badge lief">Lieferando</span>' : '') + (o.source === 'uber_eats' ? '<span class="k-badge uber">Uber Eats</span>' : '') +
			(o.source === 'phone' ? '<span class="k-badge">Telefon</span>' : '') +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-due' + (late ? ' k-late' : '') + '">' + esc(dueTxt) + '<small>' + sub + (o.status === 'new' ? ' · vor ' + ageMin + ' Min' : '') + (late ? ' · VERSPÄTET' : '') + '</small></span></div>' +
			'<p class="k-who">' + esc(o.name) + (o.phone ? ' · ' + esc(o.phone) : '') + '</p>' + (o.address ? '<p class="k-addr">' + esc(o.address) + (o.address_note ? ' (' + esc(o.address_note) + ')' : '') + '</p>' : '') +
			(o.status === 'failed' ? failReasonText(o) : '') +
			'<ul class="k-items">' + o.items.map(function (it) {
				return '<li class="k-item"><span class="k-qty">' + it.qty + '×</span>' + esc(it.title) + (it.variation ? ' <span class="k-var">' + esc(it.variation) + '</span>' : '') +
					(it.options.length ? '<div class="k-opts">+ ' + it.options.map(esc).join(', ') + '</div>' : '') + (it.note ? '<span class="k-inote">' + esc(it.note) + '</span>' : '') + '</li>';
			}).join('') + '</ul>' + (o.note ? '<p class="k-onote">' + esc(o.note) + '</p>' : '') + '<div class="k-pay">' + payText(o) + moreHtml(o) + '</div>';
		var pay = h.slice(h.lastIndexOf('<div class="k-pay">')); h = h.slice(0, h.lastIndexOf('<div class="k-pay">'));
		var side = P && o.status === 'new'; // on its side the decision stands to the right of the order: payment and the buttons in one column
		h += (side ? '<div class="k-side">' : pay) + '<div class="k-actions">';
		if (o.status === 'new') {
			// a wish time stands as the guest chose it: no minutes to give, one button accepts for that time
			h += o.scheduled
				? '<span class="k-eta-l">Wunschzeit ' + esc(o.scheduled) + ' Uhr:</span><button type="button" class="k-go eta" data-act="accept">Annehmen</button><button type="button" class="k-go secondary" data-act="cancelled">Ablehnen</button>'
				: '<span class="k-eta-l">Annehmen, fertig in:</span>' + [20, 30, 45, 60].map(function (m) { return '<button type="button" class="k-go eta" data-act="accept" data-eta="' + m + '">' + m + ' Min</button>'; }).join('') + '<button type="button" class="k-go secondary" data-act="cancelled">Ablehnen</button>';
		} else if (o.status === 'accepted') { h += '<button type="button" class="k-go" data-act="preparing">Wird gekocht</button>'; }
		else if (o.status === 'preparing') { h += '<button type="button" class="k-go" data-act="ready">Fertig</button>'; }
		else if (o.status === 'ready') {
			// a driver may already have this one in his own, not-yet-started queue (order/driver.php) -
			// dispatch sees who, and can still force it along or pull it back regardless
			h += (o.driver_name ? driverBadge(o) + '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button>' : '') +
				(o.type === 'delivery' ? '<button type="button" class="k-go" data-act="delivering">Unterwegs (ohne Fahrer-App)</button>' : '<button type="button" class="k-go" data-act="done">Abgeholt</button>');
		}
		else if (o.status === 'delivering') { h += driverBadge(o) + '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button><button type="button" class="k-go" data-act="done">Geliefert</button>'; }
		else if (o.status === 'failed') { h += driverBadge(o) + '<button type="button" class="k-go" data-retry-open="' + o.id + '">Nochmal zustellen</button><button type="button" class="k-go secondary" data-act="cancelled">Stornieren</button>'; }
		return h + '</div>' + (side ? pay + '</div>' : '') + (o.status === 'failed' && retryOpen[o.id] ? retryPanel(o) : '') + '</article>';
	}

	// ---- portrait: rows of the lower bands
	function minutesSince(ts) { return ts ? Math.max(0, Math.floor((nowS() - ts) / 60)) : 0; }
	function shortAddr(o) { return o.address ? esc(o.address.replace(/,\s*$/, '')) : ''; }
	// how a driver is doing, for the choice when handing a delivery over
	function driverInfo(d) {
		var onRoad = current.some(function (o) { return o.driver_id === d.id && o.status === 'delivering'; });
		var queued = current.filter(function (o) { return o.driver_id === d.id && o.status === 'ready'; }).length;
		var pos = d.seen_min === null ? 'kein Standort' : (d.seen_min <= 10 ? '' : 'Standort vor ' + d.seen_min + ' Min');
		return [onRoad ? 'fährt gerade' : 'frei', queued ? queued + ' vorgemerkt' : '', pos].filter(Boolean).join(' · ');
	}
	function assignPanel(o) {
		var all = !!assignAll[o.id], list = drivers.filter(function (d) { return all || (d.seen_min !== null && d.seen_min <= 10); });
		var h = '<div class="k-assign" role="group" aria-label="Fahrer für Bestellung ' + o.day_no + '"><p class="k-assign-t">Fahrer wählen</p>';
		if (!list.length) { h += '<p class="k-assign-none">Kein Fahrer mit aktuellem Standort. Die Fahrer müssen die Fahrer-App geöffnet haben.</p>'; }
		h += '<div class="k-assign-list">' + list.map(function (d) {
			return '<button type="button" class="k-go k-assign-btn' + (o.driver_id === d.id ? ' is-current' : '') + '" data-assign-driver="' + d.id + '" data-order="' + o.id + '"><b>' + esc(d.name) + '</b><small>' + esc(driverInfo(d)) + '</small></button>';
		}).join('') + '</div><div class="k-assign-foot">' + (!all && drivers.length > list.length ? '<button type="button" class="k-linkbtn" data-assign-all="' + o.id + '">Auch Fahrer ohne Standort zeigen</button>' : '') +
			'<button type="button" class="k-go secondary" data-assign-close="' + o.id + '">Abbrechen</button></div></div>';
		return h;
	}
	function rowReady(o) {
		var wait = minutesSince(o.ready_ts || o.created_ts), delivery = o.type === 'delivery', free = delivery && !o.driver_id;
		var lvl = free ? (wait >= 10 ? ' is-over' : (wait >= 5 ? ' is-soon' : '')) : '';
		var txt = delivery ? (o.driver_id ? 'vorgemerkt von ' + esc(o.driver_name) : 'wartet auf Fahrer seit ' + wait + ' Min') : 'abholbereit seit ' + wait + ' Min';
		var h = '<article class="k-row k-row-ready' + lvl + '" data-id="' + o.id + '"><div class="k-row-main"><span class="k-no">#' + o.day_no + '</span><span class="k-type ' + esc(o.type) + '">' + (delivery ? 'Lieferung' : 'Abholung') + '</span>' +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-rwho">' + esc(o.name) + '</span>' + (delivery ? '<span class="k-raddr">' + shortAddr(o) + '</span>' : '') + '</div>' +
			'<div class="k-row-wait"><b>' + txt + '</b>' + (lvl === ' is-over' ? '<span class="k-lvl">DRINGEND</span>' : (lvl === ' is-soon' ? '<span class="k-lvl">bald zu lang</span>' : '')) + '</div><div class="k-row-act">';
		if (delivery) {
			h += '<button type="button" class="k-go" data-assign-open="' + o.id + '">' + (o.driver_id ? 'Umteilen' : 'Fahrer zuteilen') + '</button>' +
				(o.driver_id ? '<button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button>' : '<button type="button" class="k-go secondary" data-act="delivering">Unterwegs ohne App</button>');
		} else { h += '<button type="button" class="k-go" data-act="done">Abgeholt</button>'; }
		h += moreHtml(o) + '</div>' + (assignOpen[o.id] ? assignPanel(o) : '') + '</article>';
		return h;
	}
	function rowOut(o) {
		var m = Math.floor((o.due_ts - nowS()) / 60), lvl = m < 0 ? ' is-over' : (m <= 5 ? ' is-soon' : '');
		var since = minutesSince(o.updated_ts);
		return '<article class="k-row k-row-out' + lvl + '" data-id="' + o.id + '"><div class="k-row-main"><span class="k-no">#' + o.day_no + '</span><span class="k-rwho">' + (o.driver_name ? esc(o.driver_name) : 'ohne Fahrer-App') + '</span>' +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-raddr">' + shortAddr(o) + '</span></div>' +
			'<div class="k-row-wait"><b>Lieferzeit ' + esc(o.due) + '</b><span class="k-lvl">' + (m < 0 ? (-m) + ' Min zu spät' : 'in ' + m + ' Min') + '</span><small>unterwegs seit ' + since + ' Min</small></div>' +
			'<div class="k-row-act"><button type="button" class="k-go" data-act="done">Geliefert</button><button type="button" class="k-go secondary" data-release="' + o.id + '">Zurück in den Pool</button>' +
			'<button type="button" class="k-go secondary" data-mapopen>Karte</button>' + moreHtml(o) + '</div></article>';
	}
	// a failed delivery is fetched back: check and correct the address details, then it goes into the pool again
	function retryPanel(o) {
		function f(name, label, val, wide, max) { return '<label class="k-retry-f' + (wide ? ' wide' : '') + '"><span>' + label + '</span><input type="text" name="' + name + '" value="' + esc(val || '') + '" maxlength="' + max + '" autocomplete="off"/></label>'; }
		return '<div class="k-assign k-retry" role="group" aria-label="Lieferung ' + o.day_no + ' nochmal zustellen"><p class="k-assign-t">Nochmal zustellen · Grund: ' + esc(o.fail_reason || 'kein Grund angegeben') + '</p>' +
			'<div class="k-retry-grid">' + f('street', 'Straße und Hausnummer', o.street, true, 160) + f('zip', 'PLZ', o.zip, false, 10) + f('city', 'Ort', o.city, false, 80) + f('note', 'Hinweis für den Fahrer', o.address_note, true, 200) + f('phone', 'Telefon des Gastes', o.phone, true, 40) + '</div>' +
			'<p class="k-retry-msg" role="status"></p><div class="k-assign-foot"><button type="button" class="k-go" data-retry-go="' + o.id + '">Zurückholen und neu zustellen</button><button type="button" class="k-go secondary" data-retry-close="' + o.id + '">Abbrechen</button></div></div>';
	}
	function rowFail(o) {
		return '<article class="k-row k-row-fail is-over" data-id="' + o.id + '"><div class="k-row-main"><span class="k-no">#' + o.day_no + '</span><span class="k-rwho">' + esc(o.name) + '</span>' + (o.test ? '<span class="k-badge">Test</span>' : '') + '<span class="k-raddr">' + shortAddr(o) + '</span></div>' +
			'<div class="k-row-wait"><b>Fehlgeschlagen</b><small>' + esc(o.fail_reason || 'kein Grund angegeben') + (o.driver_name ? ' · Fahrer ' + esc(o.driver_name) : '') + '</small></div>' +
			'<div class="k-row-act"><button type="button" class="k-go" data-retry-open="' + o.id + '">Nochmal zustellen</button><button type="button" class="k-go secondary" data-act="cancelled">Stornieren</button></div>' + (retryOpen[o.id] ? retryPanel(o) : '') + '</article>';
	}
	function rowWork(o) {
		var m = Math.floor((outTs(o) - nowS()) / 60), lvl = m < 0 ? ' is-over' : (m <= 5 ? ' is-soon' : '');
		var sum = o.items.slice(0, 3).map(function (it) { return it.qty + '× ' + it.title; }).join(', ') + (o.items.length > 3 ? ' +' + (o.items.length - 3) : '');
		var d = new Date(outTs(o) * 1000), hhmm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
		return '<article class="k-row k-row-work' + lvl + '" data-id="' + o.id + '"><div class="k-row-main"><span class="k-no">#' + o.day_no + '</span><span class="k-type ' + esc(o.type) + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span><span class="k-rwho">' + esc(sum) + '</span></div>' +
			'<div class="k-row-wait"><b>raus bis ' + hhmm + '</b><span class="k-lvl">' + (m < 0 ? (-m) + ' Min überfällig' : 'in ' + m + ' Min') + '</span><small>' + (o.status === 'preparing' ? 'wird gekocht' : 'angenommen') + '</small></div>' +
			'<div class="k-row-act">' + (o.status === 'accepted' ? '<button type="button" class="k-go secondary" data-act="preparing">Wird gekocht</button>' : '<button type="button" class="k-go secondary" data-act="ready">Fertig</button>') + '</div></article>';
	}

	// ---- diff-rendered by order id instead of a wholesale innerHTML replace every poll: a full rebuild used to wipe
	// out an in-progress "wirklich ablehnen?" confirm (its armed state lives on the button's own DOM node) and
	// reset every column's scroll position, both every 6 seconds even when nothing about that order changed
	var cardNodes = {}, cardSig = {};
	function syncList(root, items, build, extra, emptyText, live) {
		var scrollTop = root.scrollTop;
		if (!items.length) { root.innerHTML = '<p class="k-empty">' + emptyText + '</p>'; return; }
		if (root.firstElementChild && root.firstElementChild.classList.contains('k-empty')) { root.innerHTML = ''; }
		items.forEach(function (o) {
			live[o.id] = true;
			var sig = JSON.stringify(o) + '|' + (extra ? extra(o) : '');
			if (!cardNodes[o.id] || cardSig[o.id] !== sig) {
				var tmp = document.createElement('div'); tmp.innerHTML = build(o);
				var fresh = tmp.firstElementChild;
				if (cardNodes[o.id] && cardNodes[o.id].parentNode) { cardNodes[o.id].replaceWith(fresh); }
				cardNodes[o.id] = fresh; cardSig[o.id] = sig;
			}
		});
		items.forEach(function (o, i) { var n = cardNodes[o.id]; if (root.children[i] !== n) { root.insertBefore(n, root.children[i] || null); } });
		root.scrollTop = scrollTop;
	}
	function dropStale(live) {
		Object.keys(cardNodes).forEach(function (id) {
			if (!live[id]) { if (cardNodes[id].parentNode) { cardNodes[id].remove(); } delete cardNodes[id]; delete cardSig[id]; }
		});
	}
	function resetNodes() { cardNodes = {}; cardSig = {}; ['new', 'work', 'ready', 'out', 'fail'].forEach(function (k) { var r = $('#col-' + k); if (r) { r.innerHTML = ''; } }); }

	var CHEV_L = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var CHEV_R = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function bandNav(k, pages) {
		var nav = $('#nav-' + k); if (!nav) { return; }
		nav.hidden = !P || pages <= 1;
		if (nav.hidden) { nav.innerHTML = ''; return; }
		var total = bandList(k).length, from = page[k] * PAGE[k] + 1, to = Math.min(total, from + PAGE[k] - 1);
		nav.className = 'k-bandnav' + (k === 'new' && newFlash ? ' is-new' : '');
		nav.innerHTML = '<button type="button" class="k-navbtn" data-bpage="' + k + '" data-dir="-1"' + (page[k] === 0 ? ' disabled' : '') + '>' + CHEV_L + '<span>Zurück</span></button>' +
			'<span class="k-navmid" aria-live="polite">' + from + ' bis ' + to + ' von ' + total + (k === 'new' && newFlash ? ' · neue Bestellung' : '') + '</span>' +
			'<button type="button" class="k-navbtn" data-bpage="' + k + '" data-dir="1"' + (page[k] >= pages - 1 ? ' disabled' : '') + '><span>Weiter</span>' + CHEV_R + '</button>';
	}

	function render() {
		var live = {}, byCol = { 'new': 0, work: 0, ready: 0, out: 0, fail: 0 };
		current.forEach(function (o) { byCol[column(o)]++; });
		if (!P) {
			['new', 'work', 'ready'].forEach(function (k) {
				var root = $('#col-' + k);
				syncList(root, bandList(k), card, k === 'ready' ? function (o) { return retryOpen[o.id] ? 'r' : ''; } : null, k === 'new' ? 'Alles erledigt' : 'Nichts hier', live);
				$('#n-' + k).textContent = byCol[k];
				// a column silently overflowing below the fold (a busy night queuing orders nobody is expected to
				// scroll for) used to give zero signal - a persistent hint below the list fixes that
				var of = $('#of-' + k);
				if (of) { of.hidden = root.scrollHeight <= root.clientHeight + 1; of.textContent = 'Weitere Bestellungen unten'; }
			});
			dropStale(live);
			$('#k-counts').innerHTML = '<span class="k-count">Neu <b>' + byCol['new'] + '</b></span><span class="k-count">In der Küche <b>' + byCol.work + '</b></span><span class="k-count">Fertig <b>' + byCol.ready + '</b></span>';
		} else {
			['new', 'ready', 'out'].forEach(function (k) {
				var pages = pagesOf(k); if (page[k] > pages - 1) { page[k] = pages - 1; }
				var rb = { 'new': card, ready: rowReady, out: rowOut }[k];
				syncList($('#col-' + k), shown(k), rb, k === 'ready' ? function (o) { return (assignOpen[o.id] ? 'a' + (assignAll[o.id] ? 'x' : '') + JSON.stringify(drivers) : '') + Math.floor(nowS() / 60); } : (k === 'out' ? function () { return Math.floor(nowS() / 60); } : null),
					k === 'new' ? 'Alles erledigt' : (k === 'ready' ? 'Nichts wartet' : 'Niemand unterwegs'), live);
				bandNav(k, pages); $('#n-' + k).textContent = byCol[k];
			});
			// the failed ones stand on top, outside the paging
			syncList($('#col-fail'), bandList('fail'), rowFail, function (o) { return retryOpen[o.id] ? 'r' : ''; }, '', live); $('#n-fail').textContent = byCol.fail;
			$('#sec-fail').hidden = !byCol.fail;
			// the kitchen is one line (the kitchen has its own monitor); one tap opens the list
			var work = bandList('work'), wroot = $('#col-work'), wsig = JSON.stringify(work) + '|' + workOpen + '|' + Math.floor(nowS() / 60);
			if (wroot.dataset.sig !== wsig) {
				wroot.dataset.sig = wsig;
				var worst = work[0], line = 'Nichts in der Küche';
				if (worst) { var wm = Math.floor((outTs(worst) - nowS()) / 60); line = work.length + ' in der Küche · dringendste: #' + worst.day_no + ' ' + (wm < 0 ? (-wm) + ' Min überfällig' : 'raus in ' + wm + ' Min'); }
				wroot.innerHTML = '<button type="button" class="k-worksum' + (worst && Math.floor((outTs(worst) - nowS()) / 60) < 0 ? ' is-over' : '') + '" data-worktoggle aria-expanded="' + workOpen + '"' + (worst ? '' : ' disabled') + '><span>' + esc(line) + '</span><span class="k-worksum-act">' + (workOpen ? 'Liste zuklappen' : 'Liste zeigen') + '</span></button>' +
					(workOpen ? '<div class="k-worklist">' + work.map(rowWork).join('') + '</div>' : '');
			}
			$('#n-work').textContent = byCol.work;
			dropStale(live);
			fitPages();
			var wait = bandList('ready').filter(function (o) { return o.type === 'delivery' && !o.driver_id; }).length;
			$('#k-counts').innerHTML = '<span class="k-count">Neu <b>' + byCol['new'] + '</b></span><span class="k-count">Küche <b>' + byCol.work + '</b></span><span class="k-count' + (wait ? ' k-warn' : '') + '">Wartet <b>' + byCol.ready + '</b></span><span class="k-count">Unterwegs <b>' + byCol.out + '</b></span>' + (byCol.fail ? '<span class="k-count k-bad">Fehlgeschlagen <b>' + byCol.fail + '</b></span>' : '');
		}
		document.title = (byCol['new'] ? '(' + byCol['new'] + ') ' : '') + 'Disposition';
	}

	// how many orders a band shows at once depends on the height of the monitor: too many for the band -> one less, room for one more -> one more
	var fitting = false;
	function fitStep() {
		var changed = false;
		['new', 'ready', 'out'].forEach(function (k) {
			var root = $('#col-' + k), kids = root.children, total = bandList(k).length; if (!kids.length || kids[0].classList.contains('k-empty')) { return; }
			var used = 0, hMax = 0, i, old = PAGE[k];
			for (i = 0; i < kids.length; i++) { used += kids[i].offsetHeight + 8; hMax = Math.max(hMax, kids[i].offsetHeight); }
			var avail = root.clientHeight - 8;
			if (used > avail + 1 && PAGE[k] > 1) { PAGE[k]--; }
			else if (PAGE[k] < MAXPAGE[k] && total > kids.length && avail - used >= hMax + 8) { PAGE[k]++; }
			if (PAGE[k] !== old) { page[k] = Math.floor(page[k] * old / PAGE[k]); changed = true; } // the first order on screen stays on screen
		});
		return changed;
	}
	function fitPages() {
		if (fitting) { return; }
		fitting = true;
		for (var n = 0; n < 4; n++) { if (!fitStep()) { break; } render(); }
		fitting = false;
	}

	// ---- paging of a band: showing a page counts as hearing its new orders
	function goBand(k, n) {
		var pages = pagesOf(k); page[k] = Math.max(0, Math.min(pages - 1, n)); lastAct = Date.now();
		if (k === 'new') { newFlash = false; shown('new').forEach(function (o) { acked[o.id] = true; }); }
		render(); sound.ack();
	}
	['pointerdown', 'pointermove', 'keydown'].forEach(function (e) { document.addEventListener(e, function () { lastAct = Date.now(); }, { passive: true }); });
	// nobody touched anything for a while: every band back to its first page, where the oldest waits
	setInterval(function () { if (P && Date.now() - lastAct > IDLE_BACK_MS && (page['new'] || page.ready || page.out)) { page['new'] = page.ready = page.out = 0; render(); } }, 5000);
	// the minute counters ("wartet seit") move on without a new board
	setInterval(function () { if (P) { render(); } }, 30000);

	// ---- pause of the orders: a switch per kind, off = paused for the chosen time
	function drawPause() {
		if (!pause) { return; }
		['delivery', 'pickup'].forEach(function (k) {
			var row = $('.k-pause-item[data-kind="' + k + '"]'), s = pause[k]; if (!row || !s) { return; }
			row.classList.toggle('is-paused', !!s.paused);
			$('[data-pause-switch]', row).checked = !s.paused;
			$('[data-pause-for]', row).hidden = !!s.paused;
			$('[data-pause-note]', row).textContent = s.paused ? 'pausiert' + (s.until ? ' bis ' + s.until : '') : '';
		});
	}
	var pauseBox = $('#k-pause');
	if (pauseBox) {
		pauseBox.addEventListener('change', function (ev) {
			var sw = ev.target.closest('[data-pause-switch]'); if (!sw) { return; }
			var row = sw.closest('.k-pause-item'), on = !sw.checked, fd = new FormData();
			fd.append('op', 'pause_set'); fd.append('token', TOKEN); fd.append('kind', row.dataset.kind); fd.append('on', on ? 1 : 0); fd.append('minutes', $('[data-pause-for]', row).value);
			sw.disabled = true;
			fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
				sw.disabled = false;
				if (!r.ok) { sw.checked = !sw.checked; notify(r.error || 'Das hat nicht geklappt.'); return; }
				pause = r.pause; drawPause();
			}).catch(function () { sw.disabled = false; sw.checked = !sw.checked; notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); });
		});
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
			autoPrintCheck(r.orders);
			current = r.orders; drivers = r.drivers || []; pause = r.pause || pause; driveMin = r.drive_min !== undefined ? r.drive_min : driveMin;
			Object.keys(acked).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete acked[k]; } });
			var fresh = seen !== null && ids.some(function (id) { return seen.indexOf(id) < 0; });
			if (fresh) {
				sound.notify();
				// a new order is a decision: unless somebody is just working the screen, show the page it is on, else flash the bar
				if (P) {
					var nl = bandList('new'), idx = -1;
					nl.forEach(function (o, i) { if (seen.indexOf(o.id) < 0 && idx < 0) { idx = i; } });
					var tp = Math.floor(idx / PAGE['new']);
					if (idx >= 0 && tp !== page['new']) { if (Date.now() - lastAct > 5000) { page['new'] = tp; } else { newFlash = true; } }
				}
			}
			seen = ids;
			drawPause(); render(); sound.ack();
		}).catch(function (e) { if (e.message !== 'login' && Date.now() - lastOk > 20000) { $('#k-offline').hidden = false; } });
	}
	function post(fields) {
		var fd = new FormData(); fd.append('token', TOKEN); Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
		return fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	document.addEventListener('click', function (ev) {
		var cardEl = ev.target.closest('.k-card');
		if (cardEl && !acked[cardEl.dataset.id]) { acked[cardEl.dataset.id] = true; cardEl.classList.remove('is-new'); sound.ack(); }
		var bp = ev.target.closest('[data-bpage]'); if (bp) { goBand(bp.dataset.bpage, page[bp.dataset.bpage] + (+bp.dataset.dir)); return; }
		if (ev.target.closest('[data-worktoggle]')) { workOpen = !workOpen; render(); return; }
		var ao = ev.target.closest('[data-assign-open]'); if (ao) { var oid = ao.dataset.assignOpen; assignOpen[oid] = !assignOpen[oid]; render(); return; }
		var ac = ev.target.closest('[data-assign-close]'); if (ac) { assignOpen[ac.dataset.assignClose] = false; render(); return; }
		var aa = ev.target.closest('[data-assign-all]'); if (aa) { assignAll[aa.dataset.assignAll] = true; render(); return; }
		var ro = ev.target.closest('[data-retry-open]'); if (ro) { retryOpen[ro.dataset.retryOpen] = !retryOpen[ro.dataset.retryOpen]; render(); var inp = $('.k-retry input[name="street"]'); if (inp) { inp.focus(); } return; }
		var rc = ev.target.closest('[data-retry-close]'); if (rc) { retryOpen[rc.dataset.retryClose] = false; render(); return; }
		var rg = ev.target.closest('[data-retry-go]');
		if (rg) { retryGo(rg); return; }
		var ad = ev.target.closest('[data-assign-driver]');
		if (ad) {
			ad.disabled = true;
			post({ op: 'assign', id: ad.dataset.order, driver: ad.dataset.assignDriver }).then(function (r) {
				if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); ad.disabled = false; return; }
				assignOpen[ad.dataset.order] = false; load();
			}).catch(function () { notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); ad.disabled = false; });
			return;
		}
		var mo = ev.target.closest('[data-mapopen]'); if (mo) { var host = mo.closest('[data-id]'); location.href = 'fahrerkarte.php' + (host && host.dataset.id ? '?o=' + encodeURIComponent(host.dataset.id) : ''); return; }
		var bonBtn = ev.target.closest('[data-bon]'); if (bonBtn) { MonitorPrint.slip(bonBtn.dataset.bon, true); return; }
		var gl = ev.target.closest('[data-guestlink]');
		if (gl) {
			var url = gl.dataset.guestlink, done = function () { gl.innerHTML = ICON_CHECK; gl.classList.add('is-done'); gl.setAttribute('aria-label', 'Link kopiert'); gl.title = 'Link kopiert'; setTimeout(function () { gl.innerHTML = ICON_LINK; gl.classList.remove('is-done'); gl.setAttribute('aria-label', 'Gast-Link kopieren'); gl.title = 'Gast-Link kopieren'; }, 2500); };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, function () { notify(url); }); } else { notify(url); }
			return;
		}
		var rel = ev.target.closest('[data-release]');
		if (rel) {
			if (!rel.dataset.armed) { rel.dataset.armed = '1'; rel.textContent = 'Wirklich zurückgeben?'; setTimeout(function () { rel.dataset.armed = ''; rel.textContent = 'Zurück in den Pool'; }, 4000); return; }
			rel.disabled = true;
			post({ op: 'release_to_pool', id: rel.dataset.release }).then(function (r) {
				if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); }
				load();
			}).catch(function () { notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); rel.disabled = false; });
			return;
		}
		var b = ev.target.closest('.k-go[data-act]'); if (!b) { return; }
		var host = b.closest('[data-id]'), act = b.dataset.act, status = act === 'accept' ? 'accepted' : act;
		// no browser dialogs here: in full screen they stay invisible. Rejecting needs a second tap on the same button.
		if (act === 'cancelled' && !b.dataset.armed) {
			b.dataset.armed = '1'; b.textContent = 'Wirklich ablehnen?'; b.classList.add('danger');
			setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Ablehnen'; b.classList.remove('danger'); }, 4000);
			return;
		}
		b.disabled = true;
		var f = { op: 'status', id: host.dataset.id, status: status }; if (b.dataset.eta) { f.eta = b.dataset.eta; }
		post(f).then(function (r) {
			if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); }
			load();
		}).catch(function () { notify('Das hat nicht geklappt. Bitte versuche es noch einmal.'); b.disabled = false; });
	});
	function retryGo(btn) {
		var box = btn.closest('.k-retry'), msg = $('.k-retry-msg', box), id = btn.dataset.retryGo;
		var f = { op: 'retry', id: id }; ['street', 'zip', 'city', 'note', 'phone'].forEach(function (n) { f[n] = $('input[name="' + n + '"]', box).value; });
		btn.disabled = true; msg.textContent = 'Adresse wird geprüft ...';
		post(f).then(function (r) {
			btn.disabled = false;
			if (!r.ok) { msg.textContent = r.error || 'Das hat nicht geklappt.'; return; }
			retryOpen[id] = false; load();
		}).catch(function () { btn.disabled = false; msg.textContent = 'Das hat nicht geklappt. Bitte versuche es noch einmal.'; });
	}
	document.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && ev.target.closest && ev.target.closest('.k-retry input')) { var g = $('[data-retry-go]', ev.target.closest('.k-retry')); if (g && !g.disabled) { retryGo(g); } } });
	var onLayout = function () { applyLayout(); resetNodes(); page['new'] = page.ready = page.out = 0; render(); };
	if (PQ.addEventListener) { PQ.addEventListener('change', onLayout); } else if (PQ.addListener) { PQ.addListener(onLayout); }
	load(); setInterval(load, 6000);
})();
