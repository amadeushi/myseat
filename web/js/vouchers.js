/* Gutscheine page of the backend (p=15): redeem with PIN, stock, sell a numbered blank, import the old list, PINs. All data comes from ajax/shop_vouchers.php. */
(function () {
	'use strict';
	var page = document.getElementById('gv-page'); if (!page) { return; }
	var TOKEN = page.dataset.token, API = 'ajax/shop_vouchers.php';
	var $ = function (s) { return document.getElementById(s); };
	function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function eur(c) { return (c / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }
	var STATE = { open: 'offen', used: 'eingelöst', expired: 'abgelaufen', void: 'gesperrt', pending: 'Zahlung offen' };
	var KIND = { pdf: 'PDF-Gutschein', print: 'Vordruck', import: 'Übernahme aus dem alten System' };
	var MOVE = { issue: 'Ausgestellt', redeem: 'Eingelöst', refund: 'Gutschrift', adjust: 'Korrektur', void: 'Gesperrt', import: 'Übernommen' };
	var staff = [], me = null, stockState = 'all', stockQ = '', timer = null, open = {}, current = null, stockData = null, importText = '';

	function say(id, text, bad) { var m = $(id); m.textContent = text || ''; m.classList.toggle('is-error', !!bad); }
	function get(params) { return fetch(API + '?' + params, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
	function post(op, fields) {
		var fd = new FormData(); fd.append('token', TOKEN); fd.append('op', op);
		Object.keys(fields || {}).forEach(function (k) { fd.append(k, fields[k]); });
		return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function fail(id) { return function () { say(id, 'Keine Verbindung. Bitte versuche es noch einmal.', true); }; }

	/* ---- tabs ---- */
	var tabs = Array.prototype.slice.call(page.querySelectorAll('[role=tab]'));
	function selectTab(t) {
		tabs.forEach(function (x) {
			var on = x === t; x.setAttribute('aria-selected', on); x.tabIndex = on ? 0 : -1;
			$(x.getAttribute('aria-controls')).hidden = !on;
		});
		if (t.id === 'gv-tab-stock') { loadStock(); } else if (t.id === 'gv-tab-pins') { drawPins(); } else if (t.id === 'gv-tab-orders') { loadOrders(); } else if (t.id === 'gv-tab-settings') { loadSettings(); }
		if (t.id === 'gv-tab-redeem') { $('gv-number').focus(); }
	}
	tabs.forEach(function (t, i) {
		t.addEventListener('click', function () { selectTab(t); });
		t.addEventListener('keydown', function (e) {
			var j = e.key === 'ArrowRight' ? i + 1 : (e.key === 'ArrowLeft' ? i - 1 : -1); if (j < 0) { return; }
			var n = tabs[(j + tabs.length) % tabs.length]; n.focus(); selectTab(n); e.preventDefault();
		});
	});

	/* ---- redeem ---- */
	function movesHtml(list) {
		return (list || []).slice().reverse().map(function (m) {
			return '<tr><td>' + esc(m.at) + '</td><td>' + esc(MOVE[m.kind] || m.kind) + '</td><td>' + (m.amount ? esc(eur(m.amount)) : '') + '</td><td>' + esc(eur(m.balance)) + '</td><td>' + esc(m.who) + '</td><td>' + esc(m.ref) + '</td></tr>';
		}).join('');
	}
	function drawStaffSelect() {
		var withPin = staff.filter(function (s) { return +s.has_pin; });
		$('gv-staff').innerHTML = withPin.length ? withPin.map(function (s) { return '<option value="' + s.id + '"' + (+s.id === +me ? ' selected' : '') + '>' + esc(s.realname || s.username) + '</option>'; }).join('') : '<option value="">Noch keine PIN angelegt (Reiter PINs)</option>';
	}
	function showCard(v, moves) {
		current = v; $('gv-card').hidden = false;
		$('gv-card-number').textContent = v.number; var s = $('gv-card-state'); s.textContent = STATE[v.state] || v.state; s.className = 'offer-badge' + (v.state === 'open' ? ' sms-badge-on' : '');
		$('gv-card-balance').textContent = eur(v.balance); $('gv-card-face').textContent = eur(v.face); $('gv-card-valid').textContent = v.valid_until || 'unbegrenzt'; $('gv-card-kind').textContent = KIND[v.kind] || v.kind;
		var w = $('gv-card-warn'); w.hidden = !v.blocked; w.textContent = v.blocked || '';
		$('gv-redeem-form').hidden = !!v.blocked; $('gv-amount').value = ''; $('gv-amount').placeholder = 'alles (' + eur(v.balance) + ')'; $('gv-pin').value = '';
		$('gv-card-moves').innerHTML = movesHtml(moves);
	}
	$('gv-lookup-form').addEventListener('submit', function (e) {
		e.preventDefault(); var n = $('gv-number').value.trim(); if (!n) { say('gv-redeem-msg', 'Bitte die Gutscheinnummer eintippen.', true); return; }
		say('gv-redeem-msg', 'Einen Moment ...');
		get('op=lookup&number=' + encodeURIComponent(n)).then(function (r) {
			if (!r.ok) { $('gv-card').hidden = true; current = null; say('gv-redeem-msg', r.error, true); return; }
			say('gv-redeem-msg', ''); showCard(r.voucher, r.moves); if (!r.voucher.blocked) { $('gv-amount').focus(); }
		}).catch(fail('gv-redeem-msg'));
	});
	$('gv-redeem-form').addEventListener('submit', function (e) {
		e.preventDefault(); if (!current) { return; }
		var btn = $('gv-redeem'); btn.disabled = true; say('gv-redeem-msg', 'Einen Moment ...');
		post('redeem', { number: current.number, amount: $('gv-amount').value, staff: $('gv-staff').value, pin: $('gv-pin').value, ref: $('gv-ref').value }).then(function (r) {
			btn.disabled = false; $('gv-pin').value = '';
			if (!r.ok) { say('gv-redeem-msg', r.error || 'Das hat nicht geklappt.', true); get('op=lookup&number=' + encodeURIComponent(current.number)).then(function (x) { if (x.ok) { showCard(x.voucher, x.moves); } }); return; }
			var last = r.moves[r.moves.length - 1];
			showCard(r.voucher, r.moves); $('gv-ref').value = '';
			say('gv-redeem-msg', eur(-last.amount) + ' eingelöst. Auf dem Gutschein bleiben ' + eur(r.voucher.balance) + '.');
		}).catch(function () { btn.disabled = false; fail('gv-redeem-msg')(); });
	});

	/* ---- stock ---- */
	function drawStock() {
		var d = stockData; if (!d) { return; }
		var s = d.stats;
		$('gv-stats').innerHTML = [['Noch einzulösen', s.open], ['Verkauft insgesamt', s.sold], ['Eingelöst insgesamt', s.redeemed], ['Abgelaufen (Rest)', s.expired]].map(function (i) { return '<li><b>' + esc(eur(i[1])) + '</b><span>' + esc(i[0]) + '</span></li>'; }).join('');
		var c = d.counts, items = [['all', 'Alle', c.all], ['open', 'Offen', c.open], ['used', 'Eingelöst', c.used], ['expired', 'Abgelaufen', c.expired], ['void', 'Gesperrt', c.void], ['pending', 'Zahlung offen', c.pending]];
		$('gv-counts').innerHTML = items.map(function (i) { return '<button type="button" class="nl-count" data-state="' + i[0] + '" aria-pressed="' + (stockState === i[0]) + '"><b>' + (+i[2] || 0) + '</b><span>' + i[1] + '</span></button>'; }).join('');
		$('gv-empty').hidden = d.rows.length > 0; $('gv-table').hidden = d.rows.length === 0;
		$('gv-rows').innerHTML = d.rows.map(function (r) {
			var o = open[r.id];
			var tr = '<tr data-id="' + r.id + '"><td class="gv-mono"><strong>' + esc(r.number) + '</strong><br><small>' + esc(KIND[r.kind] || r.kind) + '</small></td><td>' + esc(eur(r.balance)) + '<br><small>von ' + esc(eur(r.face)) + '</small></td><td>' + esc(r.valid_until || 'unbegrenzt') + '</td><td>' + esc(r.buyer_name || '') + (r.recipient ? '<br><small>für ' + esc(r.recipient) + '</small>' : '') + '</td>' +
				'<td><span class="offer-badge' + (r.state === 'open' ? ' sms-badge-on' : '') + '">' + esc(STATE[r.state] || r.state) + '</span></td><td class="nl-act"><button type="button" class="sh-btn" data-act="moves" data-id="' + r.id + '" aria-expanded="' + (!!(o && o.kind === 'moves')) + '">Verlauf</button>' +
				(r.state === 'void' ? '<button type="button" class="sh-btn" data-act="unvoid" data-id="' + r.id + '">Entsperren</button>' : '<button type="button" class="sh-btn" data-act="void" data-id="' + r.id + '">Sperren</button>') + '<button type="button" class="sh-btn" data-act="adjust" data-id="' + r.id + '">Korrigieren</button><button type="button" class="sh-btn" data-act="valid" data-id="' + r.id + '">Gültigkeit</button>' + (r.kind !== 'import' ? '<a class="sh-btn" href="' + API + '?op=label&id=' + r.id + '" target="_blank" rel="noopener">Etikett</a>' : '') + '</td></tr>';
			if (o) { tr += '<tr class="nl-proofrow"><td colspan="6">' + panelHtml(r, o) + '</td></tr>'; }
			return tr;
		}).join('');
	}
	function panelHtml(r, o) {
		if (o.kind === 'moves') { return o.loading ? 'Einen Moment ...' : '<table class="nl-proof"><thead><tr><th scope="col">Zeit</th><th scope="col">Was</th><th scope="col">Betrag</th><th scope="col">Rest</th><th scope="col">Wer</th><th scope="col">Notiz</th></tr></thead><tbody>' + movesHtml(o.rows) + '</tbody></table>'; }
		if (o.kind === 'void' || o.kind === 'unvoid') {
			return '<form class="gv-form gv-inline" data-form="' + o.kind + '" data-id="' + r.id + '"><div class="gv-field"><label>Grund</label><input type="text" name="reason" maxlength="100" autocomplete="off" placeholder="' + (o.kind === 'void' ? 'z. B. verloren gemeldet' : 'z. B. wieder aufgetaucht') + '"/></div><button type="submit" class="sh-btn is-primary">' + (o.kind === 'void' ? 'Sperren' : 'Entsperren') + '</button><button type="button" class="sh-btn" data-act="close" data-id="' + r.id + '">Abbrechen</button></form>';
		}
		if (o.kind === 'valid') {
			return '<form class="gv-form gv-inline" data-form="valid" data-id="' + r.id + '"><div class="gv-field"><label>Gültig bis (TT.MM.JJJJ)</label><input type="text" name="valid" maxlength="10" autocomplete="off" value="' + esc(r.valid_until) + '"/></div><div class="gv-field"><label>Grund (Pflicht)</label><input type="text" name="reason" maxlength="100" autocomplete="off" placeholder="z. B. Kulanz, Gutschein aus 2020"/></div><button type="submit" class="sh-btn is-primary">Speichern</button><button type="button" class="sh-btn" data-act="close" data-id="' + r.id + '">Abbrechen</button></form>';
		}
		return '<form class="gv-form gv-inline" data-form="adjust" data-id="' + r.id + '"><div class="gv-field"><label>Neues Guthaben in Euro</label><input type="text" name="balance" inputmode="decimal" maxlength="9" autocomplete="off" value="' + esc((r.balance / 100).toFixed(2).replace('.', ',')) + '"/></div><div class="gv-field"><label>Grund (Pflicht)</label><input type="text" name="reason" maxlength="100" autocomplete="off" placeholder="z. B. Rechenfehler beim Einlösen"/></div><button type="submit" class="sh-btn is-primary">Speichern</button><button type="button" class="sh-btn" data-act="close" data-id="' + r.id + '">Abbrechen</button></form>';
	}
	function loadStock() {
		get('op=load&state=' + encodeURIComponent(stockState) + '&q=' + encodeURIComponent(stockQ)).then(function (r) {
			if (!r.ok) { say('gv-stock-msg', r.error, true); return; }
			stockData = r; staff = r.staff; me = r.me; drawStaffSelect(); say('gv-stock-msg', ''); drawStock();
		}).catch(fail('gv-stock-msg'));
	}
	$('gv-counts').addEventListener('click', function (e) { var b = e.target.closest('[data-state]'); if (!b) { return; } stockState = b.dataset.state; loadStock(); });
	$('gv-q').addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { stockQ = $('gv-q').value.trim(); loadStock(); }, 250); });
	$('gv-export').addEventListener('click', function () { this.href = API + '?op=export'; });
	$('gv-rows').addEventListener('click', function (e) {
		var b = e.target.closest('[data-act]'); if (!b) { return; }
		var id = +b.dataset.id, act = b.dataset.act;
		if (act === 'close' || (open[id] && open[id].kind === act)) { delete open[id]; drawStock(); return; }
		open = {}; open[id] = { kind: act };
		if (act === 'moves') { open[id].loading = true; get('op=moves&id=' + id).then(function (r) { if (open[id]) { open[id] = { kind: 'moves', rows: r.moves || [] }; drawStock(); } }); }
		drawStock();
		var f = $('gv-rows').querySelector('.gv-inline input'); if (f) { f.focus(); }
	});
	$('gv-rows').addEventListener('submit', function (e) {
		e.preventDefault(); var f = e.target, op = f.dataset.form, fields = { id: f.dataset.id, reason: f.elements.reason.value };
		if (op === 'adjust') { fields.balance = f.elements.balance.value; } if (op === 'valid') { fields.valid = f.elements.valid.value; }
		post(op, fields).then(function (r) { if (!r.ok) { say('gv-stock-msg', r.error || 'Das hat nicht geklappt.', true); return; } open = {}; say('gv-stock-msg', 'Gespeichert.'); loadStock(); }).catch(fail('gv-stock-msg'));
	});

	/* ---- sell a blank ---- */
	$('gv-issue-form').addEventListener('submit', function (e) {
		e.preventDefault(); var btn = $('gv-issue'); btn.disabled = true; say('gv-issue-msg', 'Einen Moment ...'); $('gv-issue-done').hidden = true;
		post('issue', { number: $('gv-i-number').value, face: $('gv-i-face').value, valid: $('gv-i-valid').value, buyer_name: $('gv-i-buyer').value, buyer_email: $('gv-i-email').value, recipient: $('gv-i-recipient').value, note: $('gv-i-note').value }).then(function (r) {
			btn.disabled = false; if (!r.ok) { say('gv-issue-msg', r.error || 'Das hat nicht geklappt.', true); return; }
			say('gv-issue-msg', '');
			var d = $('gv-issue-done'); d.hidden = false;
			d.innerHTML = '<p class="gv-done-title">Gutschein angelegt: ' + esc(eur(r.voucher.face)) + '</p><dl class="gv-facts"><div><dt>Nummer</dt><dd class="gv-mono">' + esc(r.voucher.number) + '</dd></div><div><dt>Sicherheitscode</dt><dd class="gv-mono gv-code">' + esc(r.code) + '</dd></div><div><dt>Gültig bis</dt><dd>' + esc(r.voucher.valid_until) + '</dd></div></dl><p><a class="sh-btn is-primary" href="' + API + '?op=label&id=' + r.voucher.id + '" target="_blank" rel="noopener">Etikett drucken (Dymo 54 × 101 mm)</a></p><p class="sh-help">Druck das Etikett aus (Dymo, 54 × 101 mm) und klebe es auf den Vordruck: Es zeigt dem Gast seinen Code für den Webshop und trägt den QR-Code für das Team. Aus dem Bestand lässt sich das Etikett jederzeit noch einmal drucken. Im Restaurant genügt die Nummer mit PIN.</p>';
			['gv-i-number', 'gv-i-face', 'gv-i-valid', 'gv-i-buyer', 'gv-i-email', 'gv-i-recipient', 'gv-i-note'].forEach(function (id) { $(id).value = ''; });
		}).catch(function () { btn.disabled = false; fail('gv-issue-msg')(); });
	});

	/* ---- import ---- */
	$('gv-file').addEventListener('change', function () {
		var f = this.files[0]; if (!f) { return; }
		if (f.size > 500000) { say('gv-imp-msg', 'Die Datei ist zu groß.', true); return; }
		var rd = new FileReader(); rd.onload = function () { $('gv-text').value = String(rd.result); $('gv-filename').textContent = f.name; $('gv-imp-preview').hidden = true; }; rd.readAsText(f, 'utf-8');
	});
	$('gv-text').addEventListener('input', function () { $('gv-imp-preview').hidden = true; });
	var ROWSTATE = { new: 'wird angelegt', dup_file: 'doppelt in der Liste', dup_db: 'gibt es schon', error: 'Fehler' };
	function money(c) { return c === null ? '' : eur(c); }
	$('gv-imp-check').addEventListener('click', function () {
		importText = $('gv-text').value; if (!importText.trim()) { say('gv-imp-msg', 'Bitte eine Datei wählen oder die Liste einfügen.', true); return; }
		say('gv-imp-msg', 'Einen Moment ...');
		post('import_check', { text: importText, valid: $('gv-imp-valid').value }).then(function (r) {
			if (!r.ok) { say('gv-imp-msg', r.error, true); return; }
			say('gv-imp-msg', ''); var s = r.sum;
			$('gv-imp-sum').innerHTML = '<li class="k-new"><b>' + s.new + '</b> neue Gutscheine mit zusammen ' + esc(eur(s.cents)) + ' Restguthaben</li><li><b>' + s.dup_db + '</b> gibt es schon</li><li><b>' + s.dup_file + '</b> doppelt in der Liste</li><li><b>' + s.error + '</b> mit Fehler</li>';
			$('gv-imp-sample').innerHTML = r.rows.map(function (x) { return '<tr><td>' + x.line + '</td><td class="gv-mono">' + esc(x.number) + '</td><td>' + esc(money(x.balance)) + '</td><td>' + esc(money(x.face)) + '</td><td>' + esc(x.valid || '') + '</td><td>' + esc(ROWSTATE[x.status]) + (x.error ? ': ' + esc(x.error) : '') + '</td></tr>'; }).join('');
			$('gv-imp-note').textContent = r.total > r.rows.length ? 'Gezeigt sind die ersten ' + r.rows.length + ' von ' + r.total + ' Zeilen.' : '';
			$('gv-imp-run').disabled = s.new < 1; $('gv-imp-preview').hidden = false;
		}).catch(fail('gv-imp-msg'));
	});
	$('gv-imp-run').addEventListener('click', function () {
		var b = this; b.disabled = true; say('gv-imp-msg', 'Einen Moment ...');
		post('import_run', { text: importText, valid: $('gv-imp-valid').value }).then(function (r) {
			if (!r.ok) { b.disabled = false; say('gv-imp-msg', r.error, true); return; }
			$('gv-imp-preview').hidden = true; $('gv-text').value = ''; $('gv-filename').textContent = 'oder die Liste hier einfügen';
			say('gv-imp-msg', r.imported + ' Gutscheine übernommen' + (r.skipped ? ', ' + r.skipped + ' übersprungen' : '') + '. Du findest sie im Bestand.');
		}).catch(function () { b.disabled = false; fail('gv-imp-msg')(); });
	});

	/* ---- PINs ---- */
	function drawPins() {
		$('gv-pin-rows').innerHTML = staff.map(function (s) {
			var name = esc(s.realname || s.username);
			return '<tr data-user="' + s.id + '"><td>' + name + (s.realname ? '<br><small>' + esc(s.username) + '</small>' : '') + '</td><td>' + (+s.has_pin ? '<span class="offer-badge sms-badge-on">gesetzt</span>' : '<span class="offer-badge">keine</span>') + '</td><td class="nl-act"><label class="sh-vh" for="gv-pin-' + s.id + '">Neue PIN für ' + name + '</label><input type="password" id="gv-pin-' + s.id + '" class="gv-pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="new-password" placeholder="4 bis 8 Ziffern"/><button type="button" class="sh-btn is-primary" data-pin="set">' + (+s.has_pin ? 'Ändern' : 'Setzen') + '</button>' + (+s.has_pin ? '<button type="button" class="sh-btn" data-pin="remove">Entfernen</button>' : '') + '</td></tr>';
		}).join('');
	}
	$('gv-pin-rows').addEventListener('click', function (e) {
		var b = e.target.closest('[data-pin]'); if (!b) { return; }
		var tr = b.closest('tr'), uid = tr.dataset.user, input = tr.querySelector('input');
		var req = b.dataset.pin === 'set' ? post('pin_set', { user: uid, pin: input.value }) : post('pin_remove', { user: uid });
		req.then(function (r) {
			if (!r.ok) { say('gv-pin-msg', r.error || 'Das hat nicht geklappt.', true); return; }
			staff = r.staff; drawStaffSelect(); drawPins(); say('gv-pin-msg', b.dataset.pin === 'set' ? 'PIN gespeichert.' : 'PIN entfernt.');
		}).catch(fail('gv-pin-msg'));
	});

	/* ---- orders of the voucher shop ---- */
	var oFilter = 'todo', oData = null, oOpen = {};
	var OSTATE = { pending: 'Zahlung offen', paid: 'bezahlt', canceled: 'abgebrochen', withdrawn: 'widerrufen' };
	var FORM = { pdf: 'PDF', post: 'Post', pickup: 'Abholung' };
	function loadOrders(keepMsg) {
		get('op=orders&filter=' + encodeURIComponent(oFilter)).then(function (r) {
			if (!r.ok) { say('gv-o-msg', r.error, true); return; }
			oData = r; if (!keepMsg) { say('gv-o-msg', ''); } drawOrders();
		}).catch(fail('gv-o-msg'));
	}
	function orderStand(o) {
		if (o.status === 'paid') {
			if (o.delivery === 'pdf') { return 'bezahlt, PDF verschickt'; }
			if (o.fulfilled) { return o.ep ? 'Postkarte verschickt' : 'erledigt'; }
			var EP = { queued: 'Postkarte bei EchtPost bestellt', sending: 'Postkarte wird bestellt …', error: 'Postkarte nicht bestellt: ' + o.ep_error, unclear: 'Postkarte unklar: ' + o.ep_error, canceled: 'Postkarte zurückgerufen' };
			return EP[o.ep] || 'zu erledigen';
		}
		if (o.status === 'withdrawn') { return 'widerrufen am ' + o.withdrawn_at + (o.refunded ? ', erstattet' : ', Erstattung offen'); }
		return OSTATE[o.status] || o.status;
	}
	function orderPanel(o, p) {
		if (p.kind === 'fulfil') {
			return '<form class="gv-form gv-inline" data-oform="fulfil" data-id="' + o.id + '"><div class="gv-field"><label>Aufgedruckte Nummer des Vordrucks</label><input type="text" name="number" class="gv-mono" maxlength="30" autocapitalize="characters" autocomplete="off"/></div><button type="submit" class="sh-btn is-primary">Erledigt, Käufer benachrichtigen</button><button type="button" class="sh-btn" data-oact="close" data-id="' + o.id + '">Abbrechen</button></form>' +
				'<p class="sh-help">Sicherheitscode zum Aufschreiben auf den Vordruck: <strong class="gv-mono gv-code">' + esc(o.code) + '</strong>' + (o.ship ? '<br>Versand an: ' + esc(o.ship) : '') + '</p>';
		}
		if (p.kind === 'refund') {
			return '<p class="sh-help">Der Gutschein ist gesperrt. Jetzt ' + esc(eur(o.total)) + ' an den Käufer zurücküberweisen? Das läuft über Mollie auf dasselbe Zahlungsmittel und lässt sich nicht zurücknehmen.</p><button type="button" class="sh-btn is-primary" data-oact="refund-do" data-id="' + o.id + '">Ja, ' + esc(eur(o.total)) + ' erstatten</button> <button type="button" class="sh-btn" data-oact="close" data-id="' + o.id + '">Abbrechen</button>';
		}
		var rows = [['Gutschein', o.voucher], ['Motiv', o.motif.indexOf('own:') === 0 ? 'eigenes Bild des Käufers' : o.motif], ['Für', o.recipient], ['Von', o.from], ['Nachricht', o.message], ['Telefon', o.phone], ['Versand an', o.ship], ['Bezahlt am', o.paid_at], ['Mail verschickt', o.mail ? 'ja' : 'nein'], ['Widerrufsrecht', o.lapse ? 'erlischt mit Bereitstellung (Zustimmung liegt vor)' : '14 Tage']];
		return '<dl class="gv-facts">' + rows.filter(function (r) { return r[1]; }).map(function (r) { return '<div><dt>' + r[0] + '</dt><dd>' + esc(r[1]) + '</dd></div>'; }).join('') + '</dl>';
	}
	function drawOrders() {
		var d = oData, c = d.counts;
		var items = [['todo', 'Zu erledigen', c.todo], ['refund', 'Erstattung offen', c.refund], ['paid', 'Bezahlt', c.paid], ['withdrawn', 'Widerrufen', c.withdrawn], ['pending', 'Zahlung offen', c.pending], ['all', 'Alle', c.all]];
		$('gv-o-counts').innerHTML = items.map(function (i) { return '<button type="button" class="nl-count" data-ofilter="' + i[0] + '" aria-pressed="' + (oFilter === i[0]) + '"><b>' + (+i[2] || 0) + '</b><span>' + i[1] + '</span></button>'; }).join('');
		$('gv-o-empty').hidden = d.rows.length > 0; $('gv-o-table').hidden = d.rows.length === 0;
		$('gv-o-rows').innerHTML = d.rows.map(function (o) {
			var p = oOpen[o.id], todo = !!o.todo, refundable = o.status === 'withdrawn' && !o.refunded, resend = todo && (o.ep === 'error' || o.ep === 'unclear');
			var tr = '<tr><td><strong>' + esc(o.number) + '</strong><br><small>' + esc(o.created) + '</small></td><td>' + esc(o.buyer) + '<br><small>' + esc(o.email) + '</small></td><td>' + esc(eur(o.amount)) + '<br><small>' + esc(FORM[o.delivery] || o.delivery) + ' · gesamt ' + esc(eur(o.total)) + '</small></td>' +
				'<td><span class="offer-badge' + (todo || refundable ? '' : (o.status === 'paid' ? ' sms-badge-on' : '')) + '">' + esc(orderStand(o)) + '</span></td><td class="nl-act"><button type="button" class="sh-btn" data-oact="details" data-id="' + o.id + '">Details</button>' +
				(resend ? '<button type="button" class="sh-btn is-primary" data-oact="resend" data-again="' + (o.ep === 'unclear' ? 1 : 0) + '" data-id="' + o.id + '">Postkarte noch einmal bestellen</button>' : '') +
				(todo && o.own && !o.ep ? '<a class="sh-btn is-primary" href="' + API + '?op=order_print&id=' + o.id + '" target="_blank" rel="noopener">Karte drucken</a>' : '') +
				(todo && o.ep !== 'sending' && o.ep !== 'unclear' ? '<button type="button" class="sh-btn' + (resend || (o.own && !o.ep) ? '' : ' is-primary') + '" data-oact="fulfil" data-id="' + o.id + '">' + (resend ? 'Von Hand erledigen' : (o.own && !o.ep ? 'Verschickt / bereitgelegt' : 'Erledigen')) + '</button>' :'') + (refundable ? '<button type="button" class="sh-btn is-primary" data-oact="refund" data-id="' + o.id + '">Erstatten</button>' : '') + '</td></tr>';
			if (p) { tr += '<tr class="nl-proofrow"><td colspan="5">' + orderPanel(o, p) + '</td></tr>'; }
			return tr;
		}).join('');
	}
	$('gv-o-counts').addEventListener('click', function (e) { var b = e.target.closest('[data-ofilter]'); if (!b) { return; } oFilter = b.dataset.ofilter; oOpen = {}; loadOrders(); });
	$('gv-o-rows').addEventListener('click', function (e) {
		var b = e.target.closest('[data-oact]'); if (!b) { return; }
		var id = +b.dataset.id, act = b.dataset.oact;
		if (act === 'refund-do') {
			b.disabled = true;
			post('order_refund', { id: id }).then(function (r) { if (!r.ok) { b.disabled = false; say('gv-o-msg', r.error || 'Das hat nicht geklappt.', true); return; } oOpen = {}; say('gv-o-msg', 'Die Erstattung ist bei Mollie ausgelöst.'); loadOrders(true); }).catch(fail('gv-o-msg'));
			return;
		}
		if (act === 'resend') {
			if (b.dataset.again === '1' && !window.confirm('EchtPost hat nicht geantwortet, die Karte könnte schon bestellt sein. Hast du im EchtPost-Konto nachgesehen, dass es sie nicht gibt?')) { return; }
			b.disabled = true;
			post('order_resend', { id: id, again: b.dataset.again }).then(function (r) { if (!r.ok) { b.disabled = false; say('gv-o-msg', r.error || 'Das hat nicht geklappt.', true); loadOrders(true); return; } say('gv-o-msg', 'Die Postkarte ist bei EchtPost bestellt.'); loadOrders(true); }).catch(fail('gv-o-msg'));
			return;
		}
		var row = (oData ? oData.rows : []).filter(function (x) { return x.id === id; })[0];
		if (act === 'fulfil' && row && row.own) {   // a card printed from the shop file has its own number: only the mail to the buyer is left
			if (!window.confirm('Ist die Karte verschickt bzw. liegt sie bereit? Der Käufer bekommt jetzt eine Mail.')) { return; }
			b.disabled = true;
			post('order_fulfil', { id: id, number: '' }).then(function (r) { if (!r.ok) { b.disabled = false; say('gv-o-msg', r.error || 'Das hat nicht geklappt.', true); return; } oOpen = {}; say('gv-o-msg', 'Erledigt. Der Käufer hat eine Mail bekommen.'); loadOrders(true); }).catch(fail('gv-o-msg'));
			return;
		}
		if (act === 'close' || (oOpen[id] && oOpen[id].kind === act)) { delete oOpen[id]; drawOrders(); return; }
		oOpen = {}; oOpen[id] = { kind: act }; drawOrders();
		var f = $('gv-o-rows').querySelector('.gv-inline input'); if (f) { f.focus(); }
	});
	$('gv-o-rows').addEventListener('submit', function (e) {
		e.preventDefault(); var f = e.target;
		post('order_fulfil', { id: f.dataset.id, number: f.elements.number.value }).then(function (r) { if (!r.ok) { say('gv-o-msg', r.error || 'Das hat nicht geklappt.', true); return; } oOpen = {}; say('gv-o-msg', 'Erledigt. Der Käufer hat eine Mail bekommen.'); loadOrders(true); }).catch(fail('gv-o-msg'));
	});

	/* ---- settings of the shop ---- */
	function drawEp(ep) {
		$('gv-ep-state').textContent = ep && ep.set ? 'Schlüssel gespeichert (' + ep.masked + ', ' + (ep.mode === 'test' ? 'Test: es wird nichts gedruckt' : 'live') + '). Er wird nicht mehr angezeigt.' : 'Noch kein Schlüssel gespeichert. Ohne Schlüssel bleibt es beim Vordruck.';
	}
	$('gv-ep-save').addEventListener('click', function () {
		var k = $('gv-ep-key').value.trim(); if (!k) { say('gv-ep-msg', 'Bitte den Schlüssel einfügen.', true); return; }
		post('ep_save', { key: k }).then(function (r) { if (!r.ok) { say('gv-ep-msg', r.error, true); return; } $('gv-ep-key').value = ''; drawEp(r.ep); say('gv-ep-msg', 'Gespeichert.'); }).catch(fail('gv-ep-msg'));
	});
	$('gv-ep-test').addEventListener('click', function () {
		say('gv-ep-msg', 'Ich frage EchtPost …');
		post('ep_test', {}).then(function (r) { say('gv-ep-msg', r.ok ? 'Verbunden' + (r.account ? ' (' + r.account + ')' : '') + (r.credits !== '' ? ', Guthaben ' + r.credits + ' €' : '') + (r.mode === 'test' ? ', Testmodus' : '') + '.' : r.error, !r.ok); }).catch(fail('gv-ep-msg'));
	});
	$('gv-ep-clear').addEventListener('click', function () {
		if (!window.confirm('Schlüssel löschen? Die Karten per Post laufen dann wieder über den Vordruck.')) { return; }
		post('ep_clear', {}).then(function (r) { if (!r.ok) { say('gv-ep-msg', r.error, true); return; } drawEp(r.ep); if ($('gv-set-form').querySelector('input[name=gv_post_mode]:checked').value === 'echtpost') { $('gv-set-form').querySelector('input[name=gv_post_mode][value=print]').checked = true; } say('gv-ep-msg', 'Gelöscht.'); }).catch(fail('gv-ep-msg'));
	});

	function loadSettings() {
		get('op=settings').then(function (r) {
			if (!r.ok) { say('gv-set-msg', r.error, true); return; }
			var f = $('gv-set-form'), s = r.s, off = s.gv_motifs_off ? s.gv_motifs_off.split(',') : [];
			['gv_on', 'gv_pay_on', 'gv_terms_ok', 'gv_pdf_on', 'gv_post_on', 'gv_pickup_on', 'gv_own_motif'].forEach(function (k) { f.elements[k].checked = s[k] === '1'; });
			['gv_amounts', 'gv_min_eur', 'gv_max_eur', 'gv_post_fee', 'gv_site_host'].forEach(function (k) { f.elements[k].value = s[k] || ''; });
			f.querySelector('input[name=gv_withdraw_mode][value=' + (s.gv_withdraw_mode === 'lapse' ? 'lapse' : 'days14') + ']').checked = true;
			f.querySelector('input[name=gv_post_mode][value=' + (s.gv_post_mode === 'echtpost' || s.gv_post_mode === 'blank' ? s.gv_post_mode : 'print') + ']').checked = true;
			drawEp(r.ep); $('gv-ep-hook').textContent = r.ep_hook;
			$('gv-s-motifs').innerHTML = r.motifs.map(function (m) { return '<label class="co-check"><input type="checkbox" data-motif="' + esc(m.key) + '"' + (off.indexOf(m.key) < 0 ? ' checked' : '') + '/> ' + esc(m.label) + ' <small>(' + esc(m.group) + ')</small></label>'; }).join('');
			$('gv-set-link').innerHTML = 'Seite für Gäste: <a href="' + esc(r.shop_url) + '" target="_blank" rel="noopener">' + esc(r.shop_url) + '</a> (du siehst sie auch, solange der Shop noch zu ist).';
			say('gv-set-msg', '');
		}).catch(fail('gv-set-msg'));
	}
	$('gv-set-form').addEventListener('submit', function (e) {
		e.preventDefault(); var f = e.target, d = {};
		['gv_on', 'gv_pay_on', 'gv_terms_ok', 'gv_pdf_on', 'gv_post_on', 'gv_pickup_on', 'gv_own_motif'].forEach(function (k) { d[k] = f.elements[k].checked ? 1 : 0; });
		['gv_amounts', 'gv_min_eur', 'gv_max_eur', 'gv_post_fee', 'gv_site_host'].forEach(function (k) { d[k] = f.elements[k].value; });
		d.gv_withdraw_mode = f.querySelector('input[name=gv_withdraw_mode]:checked').value;
		d.gv_post_mode = f.querySelector('input[name=gv_post_mode]:checked').value;
		d.gv_motifs_off = Array.prototype.filter.call(f.querySelectorAll('input[data-motif]'), function (i) { return !i.checked; }).map(function (i) { return i.dataset.motif; }).join(',');
		if (d.gv_on && !d.gv_terms_ok) { say('gv-set-msg', 'Der Shop öffnet erst, wenn auch "Texte geprüft" gesetzt ist. Gespeichert wird trotzdem.', false); }
		post('settings_save', { data: JSON.stringify(d) }).then(function (r) { if (!r.ok) { say('gv-set-msg', r.error || 'Das hat nicht geklappt.', true); return; } if (!(d.gv_on && !d.gv_terms_ok)) { say('gv-set-msg', 'Gespeichert.'); } }).catch(fail('gv-set-msg'));
	});

	/* ---- start: the staff list is needed by the redeem form ---- */
	get('op=load&state=all').then(function (r) { if (r.ok) { stockData = r; staff = r.staff; me = r.me; drawStaffSelect(); } });
	$('gv-number').focus();
})();
