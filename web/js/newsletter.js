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
			say(''); data = r; draw(); batches(r.batches);
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

	/* ---- Import: choose or paste a list, check it (duplicates), import it, take a batch back ---- */
	var impFile = '', checked = false;
	var KIND = { 'new': 'wird aufgenommen', upgrade: 'war gesperrt, wird freigegeben', dupfile: 'doppelt in der Datei', have: 'schon angemeldet', pending: 'wartet auf Bestätigung', unsub: 'hat sich abgemeldet, bleibt abgemeldet', blockedhave: 'schon gesperrt vorhanden', invalid: 'ungültig' };
	function impMode() { return document.querySelector('input[name="nl-mode"]:checked').value; }
	function impMsg(t, bad) { var m = $('nl-imp-msg'); m.textContent = t || ''; m.classList.toggle('is-error', !!bad); }
	function impReset() { checked = false; $('nl-preview').hidden = true; impMsg(''); }
	function impPost(op, extra) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN); fd.append('text', $('nl-text').value); fd.append('mode', impMode()); fd.append('file', impFile);
		fd.append('origin', $('nl-origin').value); if ($('nl-attest-box').checked) { fd.append('attest', '1'); }
		for (var k in (extra || {})) { fd.append(k, extra[k]); }
		return fetch('ajax/newsletter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function batches(list) {
		$('nl-batches').hidden = !list || !list.length;
		$('nl-batch-list').innerHTML = (list || []).map(function (b) { return '<li><span>Import ' + b.batch + ', ' + esc(b.at) + ': ' + b.n + ' Adressen, ' + (b.confirmed ? 'mit Einwilligung' : 'gesperrt') + '</span> <button type="button" class="sh-btn nl-erase" data-undo="' + b.batch + '">Rückgängig</button></li>'; }).join('');
	}
	$('nl-file').addEventListener('change', function () {
		var f = this.files && this.files[0]; if (!f) { return; }
		if (f.size > 3000000) { impMsg('Die Datei ist größer als 3 MB. Bitte teile sie.', true); return; }
		var rd = new FileReader();
		rd.onload = function () { $('nl-text').value = rd.result; impFile = f.name; $('nl-filename').textContent = f.name; impReset(); };
		rd.onerror = function () { impMsg('Die Datei konnte nicht gelesen werden.', true); };
		rd.readAsText(f, 'UTF-8');
	});
	$('nl-text').addEventListener('input', function () { impReset(); });
	Array.prototype.forEach.call(document.querySelectorAll('input[name="nl-mode"]'), function (r) { r.addEventListener('change', function () { $('nl-attest').hidden = impMode() !== 'confirmed'; impReset(); }); });
	$('nl-check').addEventListener('click', function () {
		impMsg('Einen Moment ...');
		impPost('import_preview').then(function (r) {
			if (!r.ok) { impMsg(r.error || 'Das hat nicht geklappt.', true); return; }
			var c = r.counts, conf = impMode() === 'confirmed';
			var items = [['new', c['new'], conf ? 'neu, bekommen Neuigkeiten' : 'neu, werden gesperrt abgelegt'], ['upgrade', c.upgrade, 'waren gesperrt und werden freigegeben'], ['dupfile', c.dupfile, 'doppelt in der Datei, übersprungen'], ['have', c.have, 'schon angemeldet, übersprungen'], ['pending', c.pending, 'warten schon auf ihre Bestätigung, übersprungen'], ['blockedhave', c.blockedhave, 'schon gesperrt vorhanden, übersprungen'], ['unsub', c.unsub, 'hatten sich abgemeldet, bleiben abgemeldet'], ['invalid', c.invalid, 'ungültige Adresse, übersprungen']];
			$('nl-sum').innerHTML = '<li class="is-total"><b>' + c.total + '</b> Zeilen gelesen</li>' + items.filter(function (i) { return i[1] > 0; }).map(function (i) { return '<li class="k-' + i[0] + '"><b>' + i[1] + '</b> ' + i[2] + '</li>'; }).join('');
			$('nl-sample').innerHTML = r.sample.map(function (x) { return '<tr><td>' + x.line + '</td><td>' + esc(x.email) + '</td><td>' + esc(x.name) + '</td><td><span class="nl-k k-' + x.kind + '">' + esc(KIND[x.kind] || x.kind) + '</span>' + (x.note ? '<br><small>' + esc(x.note) + '</small>' : '') + '</td></tr>'; }).join('');
			$('nl-sample-note').textContent = r.sample.length < c.total ? 'Die Tabelle zeigt einige Beispiele und alle auffälligen Zeilen (bis 60). Der Import folgt derselben Prüfung.' : '';
			$('nl-run').disabled = (c['new'] + c.upgrade) === 0; $('nl-run').textContent = (c['new'] + c.upgrade) === 0 ? 'Nichts zu importieren' : (c['new'] + c.upgrade) + ' Adressen importieren';
			$('nl-preview').hidden = false; checked = true; impMsg('');
		}).catch(function () { impMsg('Keine Verbindung.', true); });
	});
	$('nl-run').addEventListener('click', function () {
		if (!checked) { return; }
		if (impMode() === 'confirmed' && (!$('nl-attest-box').checked || $('nl-origin').value.trim().length < 10)) { impMsg('Bitte nenne die Herkunft der Einwilligung und setze den Haken.', true); $('nl-origin').focus(); return; }
		this.disabled = true; impMsg('Importiere ...');
		var btn = this;
		impPost('import_run').then(function (r) {
			if (!r.ok) { btn.disabled = false; impMsg(r.error || 'Das hat nicht geklappt.', true); return; }
			impMsg('Fertig: ' + r.added + ' Adressen aufgenommen' + (r.upgraded ? ', ' + r.upgraded + ' freigegeben' : '') + ' (Import ' + r.batch + ').');
			$('nl-preview').hidden = true; checked = false; $('nl-text').value = ''; impFile = ''; $('nl-filename').textContent = 'oder die Liste hier einfügen'; $('nl-file').value = ''; load();
		}).catch(function () { btn.disabled = false; impMsg('Keine Verbindung.', true); });
	});
	$('nl-batch-list').addEventListener('click', function (ev) {
		var u = ev.target.closest('[data-undo]'); if (!u) { return; }
		if (!u.dataset.armed) { u.dataset.armed = '1'; u.textContent = 'Wirklich zurücknehmen?'; setTimeout(function () { u.dataset.armed = ''; u.textContent = 'Rückgängig'; }, 4000); return; }
		var fd = new FormData(); fd.append('op', 'import_undo'); fd.append('token', TOKEN); fd.append('batch', u.dataset.undo);
		fetch('ajax/newsletter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (!r.ok) { impMsg(r.error || 'Das hat nicht geklappt.', true); return; } impMsg(r.removed + ' Adressen zurückgenommen. Wer inzwischen selbst etwas geändert hat, bleibt erhalten.'); load(); }).catch(function () { impMsg('Keine Verbindung.', true); });
	});
	load();
})();
