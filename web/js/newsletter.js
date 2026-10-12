/* Newsletter page of the backend (p=14): counts as filters, search, the list of subscribers, the proof of one subscriber, erasing one. All data comes from ajax/newsletter.php. */
(function () {
	'use strict';
	var page = document.getElementById('nl-page'); if (!page) { return; }
	var TOKEN = page.dataset.token, filter = 'all', q = '', timer = null, open = {}, data = null;
	var $ = function (s) { return document.getElementById(s); };
	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	var SOURCE = { shop: 'Bestellablauf', chat: 'Chat', konto: 'Kundenkonto', widget: 'Reservierung', backend: 'Eingabe im Backend', 'import': 'Import' };
	var EVENT = { request: 'Angefordert (Bestätigungsmail geschickt)', confirm: 'Bestätigt', change: 'Geändert', unsubscribe: 'Abgemeldet' };
	var TOPIC = { news: 'Neuigkeiten', stamps: 'Stempel-Erinnerungen' };
	function say(text, bad) { var m = $('nl-msg'); m.textContent = text || ''; m.classList.toggle('is-error', !!bad); }

	function counts(c) {
		var items = [['all', 'Alle', c.total], ['news', 'Neuigkeiten', c.news], ['stamps', 'Stempel-Erinnerungen', c.stamps], ['pending', 'Bestätigung ausstehend', c.pending], ['unsub', 'Abgemeldet', c.unsub], ['blocked', 'Gesperrt', c.blocked]];
		$('nl-counts').innerHTML = items.map(function (i) { return '<button type="button" class="nl-count" data-filter="' + i[0] + '" aria-pressed="' + (filter === i[0]) + '"><b>' + (+i[2] || 0) + '</b><span>' + i[1] + '</span></button>'; }).join('');
	}
	function chips(r) {
		var o = [];
		if (r.blocked) { return '<span class="offer-badge">gesperrt</span>'; }
		if (r.news) { o.push('<span class="offer-badge sms-badge-on">Neuigkeiten</span>'); } else if (r.want_news) { o.push('<span class="offer-badge">Neuigkeiten, wartet</span>'); }
		if (r.stamps) { o.push('<span class="offer-badge sms-badge-on">Stempel</span>'); } else if (r.want_stamps) { o.push('<span class="offer-badge">Stempel, wartet</span>'); }
		return o.length ? o.join(' ') : '<span class="offer-badge">keine</span>';
	}
	function stand(r) { return r.blocked ? 'nicht bestätigt' : (r.unsub ? 'abgemeldet am ' + r.unsub : (r.confirmed ? 'bestätigt am ' + r.confirmed : 'seit ' + r.created)); }
	function proofHtml(list) {
		if (!list || !list.length) { return '<p class="sh-help">Kein Nachweis gespeichert.</p>'; }
		return '<table class="nl-proof"><thead><tr><th scope="col">Zeit</th><th scope="col">Was</th><th scope="col">Mails</th><th scope="col">Quelle</th><th scope="col">Adresse des Besuchers</th><th scope="col">Text und Browser</th></tr></thead><tbody>' + list.map(function (p) {
			return '<tr><td>' + esc(p.at) + '</td><td>' + esc(EVENT[p.event] || p.event) + '</td><td>' + esc(p.topics.split(',').filter(Boolean).map(function (t) { return TOPIC[t] || t; }).join(', ')) + '</td><td>' + esc(SOURCE[p.source] || p.source) + '</td><td>' + esc(p.ip) + '</td><td>' + esc(p.detail) + (p.ua ? '<br><small>' + esc(p.ua) + '</small>' : '') + '</td></tr>';
		}).join('') + '</tbody></table>';
	}
	function draw() {
		if (!data) { return; }
		counts(data.counts);
		var lg = $('nl-legacy');
		if (data.legacy > 0) { lg.hidden = false; lg.textContent = data.legacy + (data.legacy === 1 ? ' Adresse hat' : ' Adressen haben') + ' früher bei einer Reservierung den alten Haken gesetzt, aber nie per Mail bestätigt. Sie stehen nicht auf der Liste und bekommen nichts, bis sie selbst bestätigen.'; } else { lg.hidden = true; }
		var rows = data.rows;
		$('nl-empty').hidden = rows.length > 0 || q !== '' || filter !== 'all';
		$('nl-table').hidden = rows.length === 0 && q === '' && filter === 'all';
		$('nl-rows').innerHTML = rows.map(function (r) {
			var tr = '<tr data-id="' + r.id + '"><td><strong>' + esc(r.email) + '</strong>' + (r.name ? '<br><small>' + esc(r.name) + '</small>' : '') + '</td><td>' + chips(r) + '</td><td>' + esc(SOURCE[r.source] || r.source || '') + '</td><td>' + esc(stand(r)) + '</td>' +
				'<td class="nl-act"><button type="button" class="sh-btn" data-proof="' + r.id + '" aria-expanded="' + (!!open[r.id]) + '">Nachweis</button><button type="button" class="sh-btn nl-erase" data-erase="' + r.id + '">Löschen</button></td></tr>';
			if (open[r.id]) { tr += '<tr class="nl-proofrow"><td colspan="5">' + (open[r.id].loading ? 'Einen Moment ...' : proofHtml(open[r.id].rows)) + '</td></tr>'; }
			return tr;
		}).join('');
	}
	function load() {
		fetch('ajax/newsletter.php?op=list&filter=' + encodeURIComponent(filter) + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', true); return; }
			say(''); data = r; draw();
		}).catch(function () { say('Keine Verbindung.', true); });
	}
	page.addEventListener('click', function (ev) {
		var f = ev.target.closest('[data-filter]'); if (f) { filter = f.dataset.filter; load(); return; }
		var p = ev.target.closest('[data-proof]');
		if (p) {
			var id = p.dataset.proof;
			if (open[id]) { delete open[id]; draw(); return; }
			open[id] = { loading: true }; draw();
			fetch('ajax/newsletter.php?op=proof&id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) { if (open[id]) { open[id] = { rows: r.proof || [] }; draw(); } }).catch(function () { delete open[id]; say('Keine Verbindung.', true); draw(); });
			return;
		}
		var e = ev.target.closest('[data-erase]');
		if (e) {
			if (!e.dataset.armed) { e.dataset.armed = '1'; e.textContent = 'Wirklich löschen?'; setTimeout(function () { e.dataset.armed = ''; e.textContent = 'Löschen'; }, 4000); return; }
			var fd = new FormData(); fd.append('op', 'erase'); fd.append('token', TOKEN); fd.append('id', e.dataset.erase);
			fetch('ajax/newsletter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', true); return; } delete open[e.dataset.erase]; say('Gelöscht, samt Nachweis.'); load(); }).catch(function () { say('Keine Verbindung.', true); });
		}
	});
	$('nl-q').addEventListener('input', function () { clearTimeout(timer); var v = this.value; timer = setTimeout(function () { q = v; load(); }, 250); });
	load();
})();
