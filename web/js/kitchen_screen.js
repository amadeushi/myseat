/* Kitchen screen: one tall column per accepted order, oldest first. Finish = "ausgegeben". No guest data, no rejecting.
   New orders play the sound (repeated until somebody taps the column) and can be printed on a slip automatically. */
(function () {
	'use strict';
	var TOKEN = document.body.dataset.token, board = document.getElementById('ks-board'), lastOk = Date.now(), orders = [], acked = {}, seen = null, printed = {};
	var cols = 5, autoPrint = false;
	// acked used to live only in memory: any reload (crash, nightly restart) wiped it, so every still-active
	// order would pulse as "fresh" again even though none of them were new - persisted the same way `printed` is
	try {
		cols = parseInt(localStorage.getItem('ksCols'), 10) === 4 ? 4 : 5; autoPrint = localStorage.getItem('ksAutoPrint') === '1';
		printed = JSON.parse(sessionStorage.getItem('ksPrinted') || '{}') || {};
		acked = JSON.parse(sessionStorage.getItem('ksAcked') || '{}') || {};
	} catch (e) {}
	function saveAcked() { try { sessionStorage.setItem('ksAcked', JSON.stringify(acked)); } catch (e) {} }

	function $(s) { return document.querySelector(s); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	var sound = MonitorSound.create({ key: 'kitchen_screen', mount: $('#k-tools'), pending: function () { return orders.filter(function (o) { return !acked[o.id]; }).length; } });

	// ---- toolbar
	function tick() { var d = new Date(); $('#k-clock').textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
	tick(); setInterval(tick, 10000);
	function syncBar() {
		$('#k-cols').textContent = cols + ' Spalten';
		var a = $('#k-auto'); a.setAttribute('aria-pressed', autoPrint ? 'true' : 'false'); a.textContent = 'Bon automatisch: ' + (autoPrint ? 'an' : 'aus');
		board.style.setProperty('--cols', cols);
	}
	$('#k-cols').addEventListener('click', function () { cols = cols === 5 ? 4 : 5; try { localStorage.setItem('ksCols', cols); } catch (e) {} syncBar(); render(); });
	$('#k-auto').addEventListener('click', function () { autoPrint = !autoPrint; try { localStorage.setItem('ksAutoPrint', autoPrint ? '1' : '0'); } catch (e) {} syncBar(); });
	$('#k-full').addEventListener('click', function () { var d = document.documentElement; if (document.fullscreenElement) { document.exitFullscreen(); } else if (d.requestFullscreen) { d.requestFullscreen(); } });
	syncBar();

	// ---- columns
	function since(ts) { var m = Math.max(0, Math.round((Date.now() / 1000 - ts) / 60)); return m < 1 ? 'gerade' : 'seit ' + m + ' Min'; }
	var LATE_AFTER_MIN = 20;
	function card(o) {
		var due = o.scheduled ? 'geplant ' + o.scheduled : o.due;
		var ageMin = Math.max(0, Math.round((Date.now() / 1000 - o.accepted_ts) / 60)), late = ageMin >= LATE_AFTER_MIN;
		// "angenommen" vs. "wird gekocht" used to be invisible here - a cook couldn't tell whether dispatch had
		// already started an order without opening disposition.php; lateness escalates through amber + text,
		// never through the failure color alone, matching the fix already made on the dispatch board
		return '<article class="ks-card' + (acked[o.id] ? '' : ' is-fresh') + (late ? ' is-late' : '') + '" data-id="' + o.id + '"><header class="ks-head"><span class="ks-no">#' + o.day_no + '</span><span class="k-type ' + esc(o.type) + '">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + '</span>' +
			'<span class="k-badge">' + (o.status === 'preparing' ? 'Wird gekocht' : 'Angenommen') + '</span>' +
			(o.test ? '<span class="k-badge">Test</span>' : '') + '</header><div class="ks-due' + (late ? ' k-late' : '') + '"><b>' + esc(due) + '</b><small>' + since(o.accepted_ts) + (late ? ' · VERSPÄTET' : '') + '</small></div>' +
			'<ul class="ks-items">' + o.items.map(function (it) {
				return '<li class="ks-item"><span class="ks-qty">' + it.qty + '×</span><span class="ks-title">' + esc(it.title) + '</span>' + (it.variation ? '<span class="ks-var">' + esc(it.variation) + '</span>' : '') +
					it.options.map(function (op) { return '<span class="ks-opt">+ ' + esc(op) + '</span>'; }).join('') + (it.note ? '<span class="ks-note">' + esc(it.note) + '</span>' : '') + '</li>';
			}).join('') + '</ul>' + (o.note ? '<p class="ks-onote">' + esc(o.note) + '</p>' : '') +
			'<footer class="ks-foot"><button type="button" class="k-btn ks-bon" data-bon="' + o.id + '">Bon drucken</button><button type="button" class="k-go ks-done" data-done="' + o.id + '">Fertig, ausgegeben</button></footer></article>';
	}
	// diff-rendered by order id instead of a wholesale innerHTML replace on every change: a full rebuild used to
	// wipe out an in-progress "wirklich ausgegeben?" confirm (its armed state lives on the button's own DOM
	// node) and reset the scroll position inside any card's item list, the instant ANY other order changed -
	// including the frequent, routine case of simply acking one card. Same fix as disposition.js this session.
	var cardNodes = {}, cardSig = {};
	function render() {
		var shown = orders.slice(0, cols), more = orders.slice(cols);
		var liveIds = {};
		if (!shown.length) {
			board.innerHTML = '<p class="ks-empty">Keine offenen Bestellungen.<br><span>Alles ausgegeben.</span></p>';
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
					var small = cardNodes[o.id].querySelector('.ks-due small'); if (small) { small.textContent = since(o.accepted_ts) + (cardNodes[o.id].classList.contains('is-late') ? ' · VERSPÄTET' : ''); }
				}
				if (cardNodes[o.id].parentNode !== board) { board.appendChild(cardNodes[o.id]); }
			});
		}
		Object.keys(cardNodes).forEach(function (id) {
			if (!liveIds[id]) { if (cardNodes[id].parentNode) { cardNodes[id].remove(); } delete cardNodes[id]; delete cardSig[id]; }
		});
		// a column silently dropping orders past the visible count used to give zero signal - a compact
		// "als Nächstes" strip (number + due time only, no full ticket) now surfaces what's still waiting
		var of = $('#ks-overflow');
		if (of) {
			of.hidden = !more.length;
			if (more.length) { of.textContent = 'Als Nächstes: ' + more.slice(0, 6).map(function (o) { return '#' + o.day_no; }).join(', ') + (more.length > 6 ? ' …' : ''); }
		}
		$('#k-counts').innerHTML = '<span class="k-count">Offen <b>' + orders.length + '</b></span>' + (more.length ? '<span class="k-count k-warn">Wartet noch <b>' + more.length + '</b></span>' : '');
		document.title = (orders.length ? '(' + orders.length + ') ' : '') + 'Küchenbildschirm';
	}

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
			Object.keys(acked).forEach(function (k) { if (ids.indexOf(+k) < 0) { delete acked[k]; } }); saveAcked();
			if (fresh.length) {
				sound.notify();
				if (autoPrint) { fresh.forEach(function (o, i) { if (!printed[o.id]) { printed[o.id] = 1; try { sessionStorage.setItem('ksPrinted', JSON.stringify(printed)); } catch (e) {} setTimeout(function () { MonitorPrint.slip(o.id, false); }, i * 1500); } }); }
			}
			render(); sound.ack();
		}).catch(function (e) { if (e.message !== 'login' && Date.now() - lastOk > 20000) { $('#k-offline').hidden = false; } });
	}
	function notify(text) { var el = $('#k-offline'); el.textContent = text; el.hidden = false; setTimeout(function () { el.textContent = 'Keine Verbindung. Ich versuche es weiter ...'; el.hidden = true; }, 5000); }

	board.addEventListener('click', function (ev) {
		var c = ev.target.closest('.ks-card'); if (!c) { return; }
		var id = +c.dataset.id;
		if (!acked[id]) { acked[id] = true; saveAcked(); c.classList.remove('is-fresh'); sound.ack(); }
		var bon = ev.target.closest('[data-bon]'); if (bon) { MonitorPrint.slip(id, false); return; }
		var done = ev.target.closest('[data-done]');
		if (done) {
			// a second tap within 4 seconds finishes: no dialog, works in full screen
			if (!done.dataset.armed) { done.dataset.armed = '1'; done.textContent = 'Wirklich ausgegeben?'; setTimeout(function () { done.dataset.armed = ''; done.textContent = 'Fertig, ausgegeben'; }, 4000); return; }
			done.disabled = true;
			var fd = new FormData(); fd.append('op', 'status'); fd.append('token', TOKEN); fd.append('id', id); fd.append('status', 'ready');
			fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (!r.ok) { notify(r.error || 'Das hat nicht geklappt.'); } load(); }).catch(function () { notify('Das hat nicht geklappt.'); done.disabled = false; });
		}
	});
	load(); setInterval(load, 5000); setInterval(function () { if (orders.length) { render(); } }, 30000);
})();
