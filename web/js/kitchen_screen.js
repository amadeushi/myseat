/* Kitchen screen: one tall column per accepted order, oldest first. Finish = "Fertig". No guest data, no rejecting.
   New orders play the sound (repeated until somebody taps the column) and can be printed on a slip automatically. */
(function () {
	'use strict';
	var TOKEN = document.body.dataset.token, board = document.getElementById('ks-board'), lastOk = Date.now(), orders = [], acked = {}, seen = null, arrivedSeen = null, printed = {};
	var cols = 5, rows = 1, autoPrint = false;
	// the "Erledigt" column: what the kitchen has finished in the last two hours (so a printed slip can be traced after the order has left the board)
	var done = [], doneOpen = 0, doneSig = '', showDone = true;
	try { showDone = localStorage.getItem('ksDone') !== '0'; } catch (e) {}
	// acked used to live only in memory: any reload (crash, nightly restart) wiped it, so every still-active
	// order would pulse as "fresh" again even though none of them were new - persisted the same way `printed` is
	try {
		cols = parseInt(localStorage.getItem('ksCols'), 10) === 4 ? 4 : 5; rows = localStorage.getItem('ksRows') === '2' ? 2 : 1; autoPrint = localStorage.getItem('ksAutoPrint') === '1';
		printed = JSON.parse(sessionStorage.getItem('ksPrinted') || '{}') || {};
		acked = JSON.parse(sessionStorage.getItem('ksAcked') || '{}') || {};
	} catch (e) {}
	function saveAcked() { try { sessionStorage.setItem('ksAcked', JSON.stringify(acked)); } catch (e) {} }

	// the print slip is a drawn symbol next to "Fertig" (same drawing as on the dispatch screen), not a text button of its own row
	var ICON_PRINT = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M7 9V4h10v5M7 17H5a1.500 1.500 0 0 1-1.500-1.500v-5A1.500 1.500 0 0 1 5 9h14a1.500 1.500 0 0 1 1.500 1.500v5A1.500 1.500 0 0 1 19 17h-2M7 14h10v6H7z" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	// the loupe: one column shows its whole order by shrinking the dish list (never below MIN_ZOOM, then it scrolls again)
	var ICON_ZOOM = '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><circle cx="10.500" cy="10.500" r="6" fill="none" stroke="currentColor" stroke-width="1.800"/><path d="M15 15l5.500 5.500M8 10.500h5" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var MIN_ZOOM = 0.6, fit = {};
	try { fit = JSON.parse(sessionStorage.getItem('ksFit') || '{}') || {}; } catch (e) {}
	function saveFit() { try { sessionStorage.setItem('ksFit', JSON.stringify(fit)); } catch (e) {} }
	function $(s) { return document.querySelector(s); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	// paging: a page holds as many orders as there are columns; the orders are sorted by when they have to leave the kitchen
	var page = 0, lastAct = Date.now(), unseen = {}, IDLE_BACK_MS = 45000;
	// the pages: the bons of a tour (they stand together in `orders`, same common time) are one block that is never split across two pages; a block that does not fit in the rest of a page starts the next one
	// two rows (button "2 Zeilen", not on a narrow screen): a page holds two rows of `cols` bons; a tour stays in one row, a block that does not fit in the rest of a row starts the next row (or the next page)
	function effRows() { return (rows === 2 && window.innerWidth > 1000) ? 2 : 1; }
	function layout() {
		var R = effRows(), pages = [[]], rowIdx = 0, used = 0, i = 0;
		while (i < orders.length) {
			var o = orders[i], n = 1;
			if (o.tour) { while (i + n < orders.length && orders[i + n].tour === o.tour) { n++; } }
			if (used > 0 && used + n > cols) { rowIdx++; used = 0; }
			if (rowIdx >= R) { pages.push([]); rowIdx = 0; used = 0; }
			for (var k = 0; k < n; k++) { pages[pages.length - 1].push(orders[i + k]); }
			used += n; i += n;
			if (used >= cols) { rowIdx++; used = 0; }
		}
		var pageOf = {};
		pages.forEach(function (p, pi) { p.forEach(function (o) { pageOf[o.id] = pi; }); });
		return { pages: pages, pageOf: pageOf };
	}
	function visible() { return layout().pages[page] || []; }
	// per tour: the bons the others are still waiting for ("wartet auf #14"), put on every order of the tour
	function annotate() {
		orders.forEach(function (o) {
			o.wait_for = o.tour ? orders.filter(function (x) { return x.tour === o.tour && x.id !== o.id && !x.tour_wait; }).map(function (x) { return '#' + x.day_no; }).join(', ') : '';
		});
	}
	// only the orders of the page on screen keep the sound going; an order on another page rings once and flashes in the bar
	var sound = MonitorSound.create({ key: 'kitchen_screen', icons: true, mount: $('#k-tools'), pending: function () { return visible().filter(function (o) { return !acked[o.id]; }).length; } });

	// ---- toolbar
	function tick() { var d = new Date(); $('#k-clock').textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
	tick(); setInterval(tick, 10000);
	function syncBar() {
		$('#k-cols').textContent = cols + ' Spalten';
		var rb = $('#k-rows'); rb.setAttribute('aria-pressed', rows === 2 ? 'true' : 'false'); rb.textContent = rows === 2 ? '2 Zeilen: an' : '2 Zeilen';
		var a = $('#k-auto'); a.setAttribute('aria-pressed', autoPrint ? 'true' : 'false'); a.textContent = 'Bon bei Fertig: ' + (autoPrint ? 'an' : 'aus');
		board.style.setProperty('--cols', cols);
		var db = $('#k-done'); db.setAttribute('aria-pressed', showDone ? 'true' : 'false'); db.textContent = 'Erledigt' + (done.length ? ' (' + done.length + ')' : '');
		$('#ks-done').hidden = !showDone;
	}
	$('#k-cols').addEventListener('click', function () { cols = cols === 5 ? 4 : 5; try { localStorage.setItem('ksCols', cols); } catch (e) {} syncBar(); render(); });
	$('#k-rows').addEventListener('click', function () { rows = rows === 2 ? 1 : 2; try { localStorage.setItem('ksRows', rows); } catch (e) {} page = 0; syncBar(); render(); });
	$('#k-auto').addEventListener('click', function () { autoPrint = !autoPrint; try { localStorage.setItem('ksAutoPrint', autoPrint ? '1' : '0'); } catch (e) {} syncBar(); });
	$('#k-done').addEventListener('click', function () { showDone = !showDone; try { localStorage.setItem('ksDone', showDone ? '1' : '0'); } catch (e) {} doneSig = ''; syncBar(); renderDone(); });
	// the kitchen monitor has no keyboard (no F5): a reload from the screen, e.g. after an update
	$('#k-reload').addEventListener('click', function () { location.reload(); });
	$('#k-full').addEventListener('click', function () { var d = document.documentElement; if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); } });
	syncBar();

	// ---- columns
	var LATE_AFTER_MIN = 20, SOON_MIN = 5;
	// when the food has to leave the kitchen (delivery: due time minus the drive), and how long that is from now
	function outState(o) {
		var m = Math.floor((o.out_ts - Date.now() / 1000) / 60);
		return { cls: m < 0 ? ' is-over' : (m <= SOON_MIN ? ' is-soon' : ''), text: m < 0 ? '\u2212' + (-m) + ' Min' : (m === 0 ? 'jetzt' : 'in ' + m + ' Min') };
	}
	// an order with a promised time (wish time of the guest, or the time Lieferando confirmed) is late only when the food has to leave the kitchen and does not;
	// for the others (as soon as possible) it is late when it has been waiting in the kitchen for 20 minutes
	function promised(o) { return o.scheduled !== '' || o.source === 'lieferando'; }
	function isLate(o) { return promised(o) ? (Date.now() / 1000 > o.out_ts) : (Date.now() / 1000 - o.accepted_ts) / 60 >= LATE_AFTER_MIN; }
	// the same time block on every card, deliveries and pickups, in the same place and the same height, so the eye finds the one time that counts at once: "Raus bis" (delivery: when the food has
	// to leave the kitchen) or "Abholung um" (pickup: when the guest comes), big, with the minutes left. Above it the tag "Sofort" (no time given) or "Verspätet", and for a delivery the time at
	// the guest as a small line; the minutes show a minus sign when the time has passed (the text is short on purpose, it must never wrap)
	function timeBlock(o) {
		var s = outState(o), pickup = o.type !== 'delivery', platform = o.source === 'lieferando' || o.source === 'uber_eats', late = isLate(o);
		var tag = late ? '<span class="ks-tag is-late">Verspätet</span>' : ((o.asap && o.source !== 'lieferando') ? '<span class="ks-tag">Sofort</span>' : '');
		var guest = pickup ? '' : '<span class="ks-tg">' + (o.scheduled && !platform ? 'Wunschzeit' : 'Lieferung') + ' ' + esc(o.scheduled ? o.scheduled : o.due) + '</span>';
		// "Sofort" (no time wanted): nothing to wait for, so the times shrink to small lines and the word itself is the big thing
		var asap = !!(o.asap && o.source !== 'lieferando');
		if (asap) {
			var small = (pickup ? 'Abholung' : 'Raus') + ' ' + (pickup ? 'um' : 'bis') + ' ' + esc(o.out);
			return '<div class="ks-out is-asap" data-out="' + o.out_ts + '"><div class="ks-t0">' + (late ? '<span class="ks-tag is-late">Verspätet</span>' : '') + guest + '</div>' +
				'<div class="ks-t1"><span class="ks-tl">' + small + '</span><small>' + s.text + '</small></div><b>Sofort</b></div>';
		}
		return '<div class="ks-out' + s.cls + '" data-out="' + o.out_ts + '"' + (!pickup && o.drive_min ? ' title="Lieferzeit minus ' + o.drive_min + ' Min Fahrt"' : '') + '><div class="ks-t0">' + tag + guest + '</div>' +
			'<div class="ks-t1"><span class="ks-tl">' + (pickup ? 'Abholung um' : 'Raus bis') + '</span><small>' + s.text + '</small></div><b>' + esc(o.out) + '</b></div>';
	}
	function card(o) {
		var late = isLate(o);
		// "angenommen" vs. "wird gekocht" used to be invisible here - a cook couldn't tell whether dispatch had
		// already started an order without opening disposition.php; lateness escalates through amber + text,
		// never through the failure color alone, matching the fix already made on the dispatch board
		// the buttons sit at the top of the column, directly under its head: the lower edge of a kitchen monitor is often hidden or hard to read
		var actions = '<div class="ks-actions"><button type="button" class="k-go ks-done" data-done="' + o.id + '">Fertig</button><button type="button" class="k-icon ks-bon" data-bon="' + o.id + '" aria-label="Bon drucken" title="Bon drucken">' + ICON_PRINT + '</button><button type="button" class="k-icon ks-zoom" data-zoom="' + o.id + '" aria-pressed="' + (fit[o.id] ? 'true' : 'false') + '" aria-label="Ganze Bestellung zeigen" title="Ganze Bestellung zeigen">' + ICON_ZOOM + '</button></div>';
		// a bon of a tour: the big time sits in the frame of the tour, here only the own time and the tour time; a bon already reported finished waits dimmed for the others
		if (o.tour && o.tour_wait) { actions = '<div class="ks-actions"><button type="button" class="k-go ks-done" disabled>Fertig gemeldet</button><button type="button" class="k-icon ks-bon" data-bon="' + o.id + '" aria-label="Bon drucken" title="Bon drucken">' + ICON_PRINT + '</button></div>'; }
		var tourInfo = o.tour ? '<p class="ks-was">' + (o.tour_wait ? 'wartet auf ' + esc(o.wait_for) : 'vorher raus ' + esc(o.was) + ' \u2192 Tour ' + esc(o.out)) + '</p>' : '';
		return '<article class="ks-card' + (acked[o.id] ? '' : ' is-fresh') + (late ? ' is-late' : '') + (o.arrived ? ' is-here' : '') + (fit[o.id] ? ' is-fit' : '') + (o.tour_wait ? ' is-wait' : '') + '" data-id="' + o.id + '"><header class="ks-head"><span class="ks-no">#' + o.day_no + '</span><div class="ks-chips"><span class="k-type ' + esc(o.type) + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			(o.source === 'lieferando' ? '<span class="k-badge lief">Lieferando</span>' : '') + (o.source === 'uber_eats' ? '<span class="k-badge uber">Uber Eats</span>' : '') + (o.source === 'chat' ? '<span class="k-badge chat">Chat</span>' : '') +
			(o.arrived ? '<span class="k-badge here">Gast ist da</span>' : '') + (o.status === 'preparing' ? '<span class="k-badge">Wird gekocht</span>' : '') + (o.test ? '<span class="k-badge">Test</span>' : '') + '</div></header>' + actions +
			((o.name || o.zip) ? '<p class="ks-who" title="' + esc(o.name) + '"><span class="ks-wname">' + esc(o.name) + '</span>' + (o.zip ? '<span class="ks-zip">' + esc(o.zip) + '</span>' : '') + '</p>' : '') + (o.tour ? tourInfo : timeBlock(o)) +
			'<div class="ks-items"><ul class="ks-list">' + o.items.map(function (it, i) {
				return '<li class="ks-item"><span class="ks-qty">' + it.qty + '×</span><span class="ks-pos">Pos ' + (i + 1) + '</span><span class="ks-title">' + esc(it.title) + '</span>' + (it.variation ? '<span class="ks-var">' + esc(it.variation) + '</span>' : '') +
					it.options.map(function (op) { return '<span class="ks-opt">+ ' + esc(op) + '</span>'; }).join('') + (it.note ? '<span class="ks-note">' + esc(it.note) + '</span>' : '') + '</li>';
			}).join('') + '</ul></div>' + (o.note ? '<p class="ks-onote">' + esc(o.note) + '</p>' : '') +
			'</article>';
	}
	// diff-rendered by order id instead of a wholesale innerHTML replace on every change: a full rebuild used to
	// wipe out an in-progress "Wirklich fertig?" confirm (its armed state lives on the button's own DOM
	// node) and reset the scroll position inside any card's item list, the instant ANY other order changed -
	// including the frequent, routine case of simply acking one card. Same fix as disposition.js this session.
	var cardNodes = {}, cardSig = {};
	// shrink the dish list in steps until it fits the room the column has; at MIN_ZOOM it stops and the list scrolls again
	function fitCard(c) {
		var box = c.querySelector('.ks-items'), list = c.querySelector('.ks-list'), btn = c.querySelector('[data-zoom]'); if (!box || !list) { return; }
		var on = c.classList.contains('is-fit'), z = 1, room = box.clientHeight;
		list.style.zoom = ''; c.classList.remove('is-scroll');
		if (on) {
			while (list.getBoundingClientRect().height > room + 1 && z > MIN_ZOOM + 0.001) { z = Math.round((z - 0.04) * 100) / 100; list.style.zoom = z; }
			if (list.getBoundingClientRect().height > room + 1) { c.classList.add('is-scroll'); }
		}
		if (btn) {
			var tip = !on ? 'Ganze Bestellung zeigen' : (c.classList.contains('is-scroll') ? 'Zu lang für eine Ansicht, scrollen. Nochmal tippen für normale Größe' : 'Normale Größe');
			btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.setAttribute('aria-label', tip); btn.title = tip;
		}
	}
	window.addEventListener('resize', function () { Object.keys(cardNodes).forEach(function (id) { fitCard(cardNodes[id]); }); });
	// the frame of a tour: one element per letter that stays (so the cards inside keep their state), its head is drawn again every time
	var boxes = {};
	function tourBox(letter) {
		if (!boxes[letter]) {
			var el = document.createElement('section'); el.className = 'ks-tourbox tr-' + letter; el.setAttribute('aria-label', 'Tour ' + letter);
			el.innerHTML = '<div class="ks-tourhead"></div><div class="ks-tourin"></div>'; boxes[letter] = el;
		}
		return boxes[letter];
	}
	function tourHead(letter, members) {
		var o = members[0], st = outState(o), late = members.some(isLate), waits = members.filter(function (x) { return x.tour_wait; }).length;
		return '<span class="lh"><span class="ks-tmark" aria-hidden="true">' + esc(letter) + '</span><span>Tour ' + esc(letter) + ' \u00b7 ' + members.length + ' Bons</span>' + (late ? '<span class="ks-tag is-late">Versp\u00e4tet</span>' : '') + '</span>' +
			'<span class="rl">zusammen raus bis<small>' + esc(st.text) + (waits ? ' \u00b7 ' + waits + ' von ' + members.length + ' fertig' : '') + '</small></span><b>' + esc(o.out) + '</b>';
	}
	function render() {
		annotate();
		var L = layout(), pages = L.pages.length;
		board.classList.toggle('is-rows2', effRows() === 2); board.style.setProperty('--rows', effRows());
		if (page > pages - 1) { page = pages - 1; }
		var shown = L.pages[page] || [];
		var liveIds = {}, desired = [];
		if (!shown.length) {
			board.innerHTML = '<p class="ks-empty">Keine offenen Bestellungen.<br><span>Alles fertig.</span></p>';
			boxes = {};
		} else {
			if (board.firstElementChild && board.firstElementChild.classList.contains('ks-empty')) { board.innerHTML = ''; }
			shown.forEach(function (o) {
				liveIds[o.id] = true;
				var sig = JSON.stringify(o) + '|' + (acked[o.id] ? 1 : 0);
				if (!cardNodes[o.id] || cardSig[o.id] !== sig) {
					var tmp = document.createElement('div'); tmp.innerHTML = card(o);
					var fresh = tmp.firstElementChild;
					if (cardNodes[o.id] && cardNodes[o.id].parentNode) { cardNodes[o.id].replaceWith(fresh); }
					cardNodes[o.id] = fresh; cardSig[o.id] = sig;
				} else {
					var ol = cardNodes[o.id].querySelector('.ks-out');
					if (ol) { ol.outerHTML = timeBlock(o); }
					cardNodes[o.id].classList.toggle('is-late', isLate(o));
				}
			});
			// place: a tour is one frame with its cards in it, everything else stands in the board as before
			var i = 0;
			while (i < shown.length) {
				var o0 = shown[i], n = 1;
				if (o0.tour) {
					while (i + n < shown.length && shown[i + n].tour === o0.tour) { n++; }
					var members = shown.slice(i, i + n), box = tourBox(o0.tour), inner = box.querySelector('.ks-tourin');
					box.style.gridColumn = 'span ' + n; inner.style.gridTemplateColumns = 'repeat(' + n + ', minmax(0, 1fr))';
					box.querySelector('.ks-tourhead').innerHTML = tourHead(o0.tour, members);
					members.forEach(function (m, k) { if (inner.children[k] !== cardNodes[m.id]) { inner.insertBefore(cardNodes[m.id], inner.children[k] || null); } });
					desired.push(box);
				} else {
					desired.push(cardNodes[o0.id]);
				}
				i += n;
			}
			Array.prototype.slice.call(board.children).forEach(function (c) { if (desired.indexOf(c) < 0) { c.remove(); } });
			desired.forEach(function (node, k) { if (board.children[k] !== node) { board.insertBefore(node, board.children[k] || null); } });
			shown.forEach(function (o) { if (fit[o.id] || cardNodes[o.id].classList.contains('is-fit')) { fitCard(cardNodes[o.id]); } });
		}
		Object.keys(cardNodes).forEach(function (id) {
			if (!liveIds[id]) { if (cardNodes[id].parentNode) { cardNodes[id].remove(); } delete cardNodes[id]; delete cardSig[id]; }
		});
		renderNav(pages);
		$('#k-counts').innerHTML = '<span class="k-count">Offen <b>' + orders.length + '</b></span>' + (pages > 1 ? '<span class="k-count">Seite <b>' + (page + 1) + '/' + pages + '</b></span>' : '');
		document.title = (orders.length ? '(' + orders.length + ') ' : '') + 'K\u00fcchenbildschirm';
	}

	// ---- Erledigt: the finished orders of the last two hours, newest first. A tap opens one: its dishes with the same "Pos n" as on the slips, and the slips again.
	function renderDone() {
		var el = $('#ks-done'); if (!el || !showDone) { return; }
		var sig = JSON.stringify([done, doneOpen]); if (sig === doneSig) { return; }
		var top = el.scrollTop; doneSig = sig;
		var h = '<h2 class="ksd-h">Erledigt<small>letzte 2 Std</small></h2>';
		if (!done.length) { h += '<p class="ksd-empty">Noch nichts fertig gemeldet.</p>'; }
		else {
			h += '<ul class="ksd-list">' + done.map(function (o) {
				var open = doneOpen === o.id;
				var row = '<li class="ksd-row' + (open ? ' is-open' : '') + '"><button type="button" class="ksd-head" data-dopen="' + o.id + '" aria-expanded="' + (open ? 'true' : 'false') + '"><span class="ksd-no">#' + o.day_no + '</span>' +
					'<span class="ksd-main"><b>' + esc(o.name || 'ohne Namen') + '</b><small>' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + ' · fertig ' + esc(o.finished) + ' · ' + o.items.length + ' Pos</small></span></button>';
				if (open) {
					row += '<div class="ksd-body">' + o.items.map(function (it, i) {
						return '<div class="ksd-item"><span class="ksd-pos">Pos ' + (i + 1) + '</span><span class="ksd-t"><b>' + it.qty + '×</b> ' + esc(it.title) + (it.variation ? '<span class="ksd-sub">' + esc(it.variation) + '</span>' : '') +
							it.options.map(function (op) { return '<span class="ksd-sub">+ ' + esc(op) + '</span>'; }).join('') + (it.note ? '<span class="ksd-note">' + esc(it.note) + '</span>' : '') + '</span></div>';
					}).join('') + (o.note ? '<p class="ksd-note">' + esc(o.note) + '</p>' : '') +
						'<div class="ksd-acts"><button type="button" class="k-btn" data-dbon="' + o.id + '">Küchenbon</button>' + (o.type === 'delivery' ? '<button type="button" class="k-btn" data-dfull="' + o.id + '">Lieferzettel</button>' : '') + '</div></div>';
				}
				return row + '</li>';
			}).join('') + '</ul>';
		}
		el.innerHTML = h; el.scrollTop = top;
	}
	$('#ks-done').addEventListener('click', function (ev) {
		var op = ev.target.closest('[data-dopen]'); if (op) { var id = +op.dataset.dopen; doneOpen = doneOpen === id ? 0 : id; doneSig = ''; renderDone(); return; }
		var b = ev.target.closest('[data-dbon],[data-dfull]'); if (!b) { return; }
		var full = !!b.dataset.dfull; MonitorPrint.slip(+(full ? b.dataset.dfull : b.dataset.dbon), full, TOKEN);
		var t = b.textContent; b.textContent = 'Gesendet'; setTimeout(function () { b.textContent = t; }, 2500);
	});

	// ---- the bar at the bottom: back, one chip for every order on another page (number, "Sofort", when it has to leave), forward.
	// Every target is big (a trackball is the only input) and nothing needs a swipe, a hover or the keyboard.
	var CHEV_L = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var CHEV_R = '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function chip(o, pg) {
		var st = outState(o);
		var sub = st.cls === ' is-over' ? 'überfällig' : 'raus ' + (o.out || o.due);
		return '<button type="button" class="ks-chip' + (o.tour ? ' tr-' + o.tour : '') + (o.asap ? ' is-asap' : '') + (st.cls === ' is-over' ? ' is-over' : (st.cls === ' is-soon' ? ' is-soon' : '')) + (unseen[o.id] ? ' is-new' : '') + '" data-goto="' + pg + '" aria-label="Bestellung ' + o.day_no + ' auf Seite ' + (pg + 1) + (o.asap ? ', sofort' : '') + (o.tour ? ', Tour ' + o.tour : '') + ', ' + sub + '">' +
			(o.tour ? '<span class="ks-cm" aria-hidden="true">' + esc(o.tour) + '</span>' : '') + '<b>#' + o.day_no + '</b>' + (o.asap ? '<span>Sofort</span>' : '') + '<small>' + esc(sub) + '</small></button>';
	}
	function renderNav(pages) {
		var nav = $('#ks-nav'); if (!nav) { return; }
		nav.hidden = pages <= 1;
		if (pages <= 1) { nav.innerHTML = ''; return; }
		var chips = [];
		var L = layout();
		orders.forEach(function (o) { if (L.pageOf[o.id] !== page) { chips.push(chip(o, L.pageOf[o.id])); } });
		nav.innerHTML = '<button type="button" class="ks-navbtn" data-page-prev' + (page === 0 ? ' disabled' : '') + '>' + CHEV_L + '<span>Zurück</span></button>' +
			'<div class="ks-navmid"><span class="ks-pg" aria-live="polite">Seite ' + (page + 1) + ' von ' + pages + '</span><div class="ks-chips">' + chips.join('') + '</div></div>' +
			'<button type="button" class="ks-navbtn" data-page-next' + (page >= pages - 1 ? ' disabled' : '') + '><span>Weiter</span>' + CHEV_R + '</button>';
	}
	// showing a page counts as hearing its orders: the sound stops, the flashing chips of that page stop
	function goPage(n) {
		var pages = layout().pages.length;
		page = Math.max(0, Math.min(pages - 1, n)); lastAct = Date.now();
		visible().forEach(function (o) { acked[o.id] = true; delete unseen[o.id]; }); saveAcked();
		render(); sound.ack();
	}
	$('#ks-nav').addEventListener('click', function (ev) {
		var g = ev.target.closest('[data-goto]'); if (g) { goPage(+g.dataset.goto); return; }
		if (ev.target.closest('[data-page-prev]')) { goPage(page - 1); return; }
		if (ev.target.closest('[data-page-next]')) { goPage(page + 1); }
	});
	// bonus for other input: arrow keys and the scroll ring page too (never required)
	var wheelAt = 0;
	document.addEventListener('keydown', function (ev) { if (ev.key === 'ArrowRight') { goPage(page + 1); } else if (ev.key === 'ArrowLeft') { goPage(page - 1); } });
	document.addEventListener('wheel', function (ev) { var t = Date.now(); if (Math.abs(ev.deltaY) < 4 || t - wheelAt < 700) { return; } wheelAt = t; goPage(page + (ev.deltaY > 0 ? 1 : -1)); }, { passive: true });
	['pointerdown', 'pointermove', 'keydown'].forEach(function (e) { document.addEventListener(e, function () { lastAct = Date.now(); }, { passive: true }); });
	// nobody touched anything for a while: back to the page with what is most urgent
	setInterval(function () { if (page > 0 && Date.now() - lastAct > IDLE_BACK_MS) { page = 0; render(); } }, 5000);

	// ---- loading
	function load() {
		fetch('ajax/shop_orders.php?op=kitchen', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (r.status === 403) { location.href = '../PLC/index.php'; throw new Error('login'); }
			return r.json();
		}).then(function (r) {
			if (!r.ok) { throw new Error('bad'); }
			$('#k-offline').hidden = true; lastOk = Date.now();
			var ids = r.orders.map(function (o) { return o.id; });
			var fresh = seen === null ? [] : r.orders.filter(function (o) { return seen.indexOf(o.id) < 0; });
			orders = r.orders; seen = ids;
			done = r.done || []; if (doneOpen && !done.some(function (d) { return d.id === doneOpen; })) { doneOpen = 0; } syncBar(); renderDone();
			annotate(); var LP = layout().pageOf;
			fresh.forEach(function (o) { if (LP[o.id] !== undefined && LP[o.id] !== page) { unseen[o.id] = 1; } });
			Object.keys(unseen).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete unseen[k]; } });
			Object.keys(acked).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete acked[k]; } }); saveAcked();
			Object.keys(fit).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete fit[k]; } }); saveFit();
			// a pickup guest tapped "Ich bin da" while the order is still in the kitchen: ring once
			var arr = r.orders.filter(function (o) { return o.arrived; }).map(function (o) { return o.id; });
			var arrNew = arrivedSeen !== null && arr.some(function (id) { return arrivedSeen.indexOf(id) < 0; });
			arrivedSeen = arr;
			if (fresh.length || arrNew) {
				sound.notify();
			}
			render(); sound.ack();
		}).catch(function (e) { if (e.message !== 'login' && Date.now() - lastOk > 20000) { $('#k-offline').hidden = false; } });
	}
	function notify(text) { var el = $('#k-offline'); el.textContent = text; el.hidden = false; setTimeout(function () { el.textContent = 'Keine Verbindung. Ich versuche es weiter ...'; el.hidden = true; }, 5000); }

	board.addEventListener('click', function (ev) {
		var c = ev.target.closest('.ks-card'); if (!c) { return; }
		var id = +c.dataset.id;
		if (!acked[id]) { acked[id] = true; saveAcked(); c.classList.remove('is-fresh'); sound.ack(); }
		var zm = ev.target.closest('[data-zoom]');
		if (zm) { if (fit[id]) { delete fit[id]; } else { fit[id] = 1; } saveFit(); c.classList.toggle('is-fit', !!fit[id]); fitCard(c); return; }
		var bon = ev.target.closest('[data-bon]'); if (bon) { MonitorPrint.slip(id, false, TOKEN); return; }
		var done = ev.target.closest('[data-done]');
		if (done) {
			// a second tap within 4 seconds finishes: no dialog, works in full screen
			if (!done.dataset.armed) { done.dataset.armed = '1'; done.textContent = 'Wirklich fertig?'; setTimeout(function () { done.dataset.armed = ''; done.textContent = 'Fertig'; }, 4000); return; }
			done.disabled = true;
			var fd = new FormData(); fd.append('op', 'status'); fd.append('token', TOKEN); fd.append('id', id); fd.append('status', 'ready');
			fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
				if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); }
				else if (autoPrint) { var po = orders.filter(function (x) { return x.id === id; })[0]; MonitorPrint.slip(id, !!po && po.type === 'delivery', TOKEN); } // finished: the delivery slip for a delivery, the slip for a pickup
				load();
			}).catch(function () { notify('Das hat nicht geklappt.'); done.disabled = false; });
		}
	});
	load(); setInterval(load, 5000); setInterval(function () { if (orders.length) { render(); } }, 30000);
})();
