/* Customer page (p=13): list with search and views on the left, the card of one customer on the right, what staff does by hand (note, marks, stamps, vouchers, account,
   link, delete). Everything goes through web/ajax/shop_customers.php; the server works out the customers and checks the rights again. */
(function () {
	'use strict';
	var page = document.getElementById('cu-page'); if (!page) { return; }
	var token = page.dataset.token, canAdmin = page.dataset.admin === '1', URL_AJAX = 'ajax/shop_customers.php';
	function $(s, r) { return (r || document).querySelector(s); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function dmy(s) { return s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' + s.slice(0, 4) : ''; }
	function dm(s) { return s ? s.slice(8, 10) + '.' + s.slice(5, 7) + '.' : ''; }
	function ago(s) { if (!s) { return ''; } var d = Math.floor((Date.now() - new Date(s.slice(0, 10) + 'T12:00:00').getTime()) / 864e5); return d <= 0 ? 'heute' : (d === 1 ? 'gestern' : 'vor ' + d + ' Tagen'); }
	var VIEWS = [['all', 'Alle'], ['regular', 'Stammgäste'], ['new', 'Neu'], ['sleeping', 'Schlafend'], ['almost', 'Stempel fast voll'], ['voucher', 'Gutschein offen'], ['account', 'Mit Konto'], ['note', 'Mit Hinweis'], ['problem', 'Auffällig']];
	var FLAGS = { stamm: 'Stammgast', vip: 'VIP', allergie: 'Allergie', vorsicht: 'Vorsicht', passend: 'Nur passend bar' };
	var STATUS = { new: 'neu', accepted: 'angenommen', preparing: 'in der Küche', ready: 'fertig', delivering: 'unterwegs', done: 'erledigt', cancelled: 'storniert', failed: 'fehlgeschlagen' };
	var REASONS = ['Kulanz', 'Reklamation', 'Korrektur', 'Sonstiges'];
	var S = { notify: true, stampBase: 0, editStamp: 0, editBase: 0, view: 'all', q: '', rows: [], total: 0, counts: {}, unassigned: 0, sel: null, card: null, panel: '', draft: null, reason: '', amount: 0, days: 30, kb: -1 }, listSeq = 0, cardSeq = 0, msgTimer = null;

	function get(op, params) {
		var u = URL_AJAX + '?op=' + op; Object.keys(params || {}).forEach(function (k) { u += '&' + k + '=' + encodeURIComponent(params[k]); });
		return fetch(u, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
	}
	function post(op, body) {
		body = body || {}; body.op = op; body.token = token;
		return fetch(URL_AJAX, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
	}
	function say(t, kind) {
		var m = $('#cu-msg'); if (!m) { return; } m.textContent = t || ''; m.className = 'cu-msg' + (kind ? ' is-' + kind : '');
		clearTimeout(msgTimer); if (t && kind === 'ok') { msgTimer = setTimeout(function () { m.textContent = ''; }, 5000); }
	}

	// ---- list
	function renderViews() {
		$('#cu-views').innerHTML = VIEWS.map(function (v) { var n = S.counts[v[0]]; return '<button type="button" class="cu-chip' + (S.view === v[0] ? ' is-on' : '') + '" data-view="' + v[0] + '" aria-pressed="' + (S.view === v[0]) + '">' + esc(v[1]) + (n != null ? ' <b>' + n + '</b>' : '') + '</button>'; }).join('');
	}
	function rowHtml(r, i) {
		var tags = '';
		if (r.blocked) { tags += '<span class="cu-tag is-bad">gesperrt</span>'; }
		r.flags.forEach(function (f) { tags += '<span class="cu-tag' + (f === 'vorsicht' || f === 'allergie' ? ' is-warn' : '') + '">' + esc(FLAGS[f] || f) + '</span>'; });
		var right = (r.account ? '<span class="cu-stamps" title="Stempel auf der Karte">' + r.stamps + '/' + r.goal + '</span>' : '') + (r.voucher ? '<span class="cu-tag is-gold">' + esc(money(r.voucher)) + '</span>' : '');
		return '<button type="button" role="listitem" class="cu-row' + (S.sel === r.id ? ' is-sel' : '') + (S.kb === i ? ' is-kb' : '') + '" data-id="' + r.id + '"><span class="cu-row-main"><b>' + esc(r.name) + '</b><span>' + esc(r.phone || r.email || '') + '</span></span>' +
			'<span class="cu-row-tags">' + tags + '</span><span class="cu-row-right">' + right + '<span class="cu-row-n">' + r.n + '× · ' + esc(r.last ? ago(r.last) : 'keine Bestellung') + '</span></span></button>';
	}
	function renderList() {
		renderViews();
		$('#cu-rows').innerHTML = S.rows.length ? S.rows.map(rowHtml).join('') : '<p class="cu-empty">' + (S.q ? 'Niemand gefunden. Versuch einen Teil der Nummer oder des Namens.' : 'In dieser Ansicht ist niemand.') + '</p>';
		$('#cu-more').hidden = S.rows.length >= S.total;
		$('#cu-foot').textContent = S.unassigned ? S.unassigned + ' Bestellungen ohne Telefonnummer (z. B. Lieferando) sind keine Kunden.' : '';
		$('#cu-sum').textContent = (S.counts.all || 0) + ' Kunden · ' + (S.counts.account || 0) + ' mit Konto';
	}
	function loadList(append) {
		var seq = ++listSeq, off = append ? S.rows.length : 0;
		return get('list', { q: S.q, view: S.view, offset: off }).then(function (r) {
			if (seq !== listSeq || !r.ok) { return; }
			S.rows = append ? S.rows.concat(r.rows) : r.rows; S.total = r.total; S.counts = r.counts; S.unassigned = r.unassigned; renderList();
		}).catch(function () {});
	}
	var qTimer = null;
	$('#cu-q').addEventListener('input', function () { S.q = this.value; clearTimeout(qTimer); qTimer = setTimeout(function () { S.kb = -1; loadList(false); }, 220); });
	$('#cu-views').addEventListener('click', function (ev) { var b = ev.target.closest('[data-view]'); if (b) { S.view = b.dataset.view; S.kb = -1; loadList(false); } });
	$('#cu-more').addEventListener('click', function () { loadList(true); });
	$('#cu-rows').addEventListener('click', function (ev) { var b = ev.target.closest('[data-id]'); if (b) { openCard(b.dataset.id); } });

	// ---- the card
	function dots(open, goal) { var h = ''; for (var i = 0; i < goal; i++) { h += '<i class="' + (i < open ? 'is-on' : '') + '"></i>'; } return '<span class="cu-dots" role="img" aria-label="' + open + ' von ' + goal + ' Stempeln">' + h + '</span>'; }
	function reasonChips(cur, key) { return '<div class="cu-chips" role="group" aria-label="Grund">' + REASONS.map(function (r) { return '<button type="button" class="cu-chip' + (cur === r ? ' is-on' : '') + '" data-reason="' + key + '" data-v="' + r + '" aria-pressed="' + (cur === r) + '">' + r + '</button>'; }).join('') + '</div>'; }
	// "tell the guest": the stamp goes by mail, the voucher by mail or SMS; without an address the tick says so and stays off
	function notifyBox(c, kind) {
		var ct = c.contact || {}, can = kind === 'stamp' ? !!ct.mail : (!!ct.mail || !!ct.sms);
		if (!can) { return '<p class="cu-hint">' + (kind === 'stamp' ? 'Keine E-Mail-Adresse bekannt, der Gast wird nicht benachrichtigt (eine volle Karte meldet sich nur bei Adresse oder SMS).' : 'Weder E-Mail-Adresse noch SMS-Nummer bekannt, der Gast wird nicht benachrichtigt.') + '</p>'; }
		return '<label class="cu-check"><input type="checkbox" id="cu-notify"' + (S.notify ? ' checked' : '') + '/> Gast benachrichtigen (' + (ct.mail ? 'Mail an ' + esc(ct.mail) : 'SMS') + ')</label>';
	}
	function sec(title, body, cls) { return '<section class="cu-sec' + (cls ? ' ' + cls : '') + '"><h5>' + esc(title) + '</h5>' + body + '</section>'; }

	function cardHtml(c) {
		var h = '<header class="cu-head"><div class="cu-head-main"><h4>' + esc(c.name) + '</h4><p>' + esc([c.phone, c.email].filter(Boolean).join(' · ') || 'Keine Kontaktdaten') + '</p>' +
			'<p class="cu-head-s">' + c.n + (c.n === 1 ? ' Bestellung' : ' Bestellungen') + (c.last ? ' · zuletzt ' + dmy(c.last) + ' (' + ago(c.last) + ')' : '') + '</p></div>' +
			'<div class="cu-head-acts"><a class="cu-btn is-gold" href="main_page.php?p=12&phone=' + encodeURIComponent(c.phone || '') + '">Bestellung erfassen</a></div></header>';
		var tags = '';
		if (c.accounts.length) { tags += '<span class="cu-tag">Konto</span>'; } if (c.blocked) { tags += '<span class="cu-tag is-bad">Konto gesperrt</span>'; }
		c.flags.forEach(function (f) { tags += '<span class="cu-tag' + (f === 'vorsicht' || f === 'allergie' ? ' is-warn' : '') + '">' + esc(FLAGS[f] || f) + '</span>'; });
		if (c.bad >= 2) { tags += '<span class="cu-tag is-warn">' + c.bad + ' Stornos / fehlgeschlagen</span>'; }
		if (tags) { h += '<div class="cu-tags">' + tags + '</div>'; }
		if (c.note) { h += '<p class="cu-note-big">' + esc(c.note) + '</p>'; }
		h += '<p class="cu-msg" id="cu-msg" role="status" aria-live="polite"></p>';

		// stamp card and vouchers
		var st = '<div class="cu-stamp">' + dots(c.stamps_open, c.goal) + '<span class="cu-stamp-t"><b>' + c.stamps_open + ' von ' + c.goal + '</b>' + (c.stamp_until ? ' · verfällt ' + dmy(c.stamp_until) : '') + '</span></div>';
		if (!c.accounts.length) { st += '<p class="cu-hint">Ohne Kundenkonto sammelt dieser Kunde im Shop keine Stempel. Von Hand geht es trotzdem.</p>'; }
		st += c.vouchers.length ? '<div class="cu-list2">' + c.vouchers.map(function (v) {
			return '<div class="cu-li"><span><b>' + esc(money(v.value)) + '</b> · ' + esc(v.code) + (v.until ? ' · bis ' + dmy(v.until) : '') + '</span><span class="cu-li-s is-' + v.state + '">' + ({ live: 'gültig', used: 'eingelöst', blocked: 'gesperrt', expired: 'abgelaufen' }[v.state]) + '</span>' +
				((v.state === 'live' || v.state === 'blocked') ? '<button type="button" class="cu-mini" data-act="coupon-toggle" data-v="' + v.id + '">' + (v.state === 'live' ? 'Sperren' : 'Freigeben') + '</button>' : '') + '</div>'; }).join('') + '</div>' : '';
		st += '<div class="cu-acts"><button type="button" class="cu-btn" data-act="panel" data-v="stamp">+ Stempel</button><button type="button" class="cu-btn" data-act="panel" data-v="coupon">Gutschein ausstellen</button></div>';
		if (S.panel === 'stamp') {
			var chips = [1000, 1500, 2000];
			st += '<div class="cu-form"><p><b>Stempel gutschreiben</b> · ein Stempel auf der Karte. Bei voller Karte ist der Gutschein ' + c.percent + ' % der Grundbeträge aller Stempel darauf.</p>' +
				'<p class="cu-lbl">Grundbetrag des Stempels (vorgeschlagen: ' + esc(money(c.suggest)) + ', der Durchschnitt der Karte)</p><div class="cu-chips" role="group" aria-label="Grundbetrag">' +
				chips.map(function (v) { return '<button type="button" class="cu-chip' + (S.stampBase === v ? ' is-on' : '') + '" data-sbase="' + v + '" aria-pressed="' + (S.stampBase === v) + '">' + esc(money(v).replace(',00', '')) + '</button>'; }).join('') +
				'<input type="text" id="cu-sbase" inputmode="decimal" placeholder="anderer Betrag" aria-label="Grundbetrag in Euro" value="' + (S.stampBase && chips.indexOf(S.stampBase) < 0 ? esc((S.stampBase / 100).toFixed(2).replace('.', ',')) : '') + '"/></div>' + reasonChips(S.reason, 'r') + notifyBox(c, 'stamp') +
				'<div class="cu-acts"><button type="button" class="cu-btn is-gold" data-act="stamp-add">Gutschreiben</button><button type="button" class="cu-btn" data-act="panel" data-v="">Abbrechen</button></div></div>';
		}
		if (S.panel === 'coupon') {
			st += '<div class="cu-form"><p><b>Gutschein ausstellen</b> · gilt ab einem Warenwert in Höhe des Betrags, wird einmal eingelöst.</p><div class="cu-chips" role="group" aria-label="Betrag">' +
				[500, 1000, 1500, 2000].map(function (v) { return '<button type="button" class="cu-chip' + (S.amount === v ? ' is-on' : '') + '" data-amount="' + v + '" aria-pressed="' + (S.amount === v) + '">' + esc(money(v).replace(',00', '')) + '</button>'; }).join('') +
				'<input type="text" id="cu-amt" inputmode="decimal" placeholder="anderer Betrag" aria-label="Betrag in Euro" value="' + (S.amount && [500, 1000, 1500, 2000].indexOf(S.amount) < 0 ? esc((S.amount / 100).toFixed(2).replace('.', ',')) : '') + '"/></div>' +
				'<div class="cu-chips" role="group" aria-label="Gültig für">' + [14, 30, 60, 90].map(function (d) { return '<button type="button" class="cu-chip' + (S.days === d ? ' is-on' : '') + '" data-days="' + d + '" aria-pressed="' + (S.days === d) + '">' + d + ' Tage</button>'; }).join('') + '</div>' + reasonChips(S.reason, 'r') + notifyBox(c, 'coupon') +
				'<div class="cu-acts"><button type="button" class="cu-btn is-gold" data-act="coupon-issue">Ausstellen</button><button type="button" class="cu-btn" data-act="panel" data-v="">Abbrechen</button></div></div>';
		}
		var open = c.stamps.filter(function (s) { return s.state === 'open'; });
		if (open.length) { st += '<details class="cu-det"' + (S.editStamp ? ' open' : '') + '><summary>Stempel im Einzelnen</summary><div class="cu-list2">' + open.map(function (s) {
			var edit = S.editStamp === s.id;
			return '<div class="cu-li"><span>' + dmy(s.at) + (s.manual ? ' · von Hand' : ' · Bestellung') + ' · bis ' + dmy(s.until) + ' · Grundbetrag <b>' + esc(money(s.base)) + '</b></span>' +
				(s.manual ? '<button type="button" class="cu-mini" data-act="stamp-edit" data-v="' + s.id + '" data-b="' + s.base + '">' + (edit ? 'Abbrechen' : 'Betrag ändern') + '</button>' : '') + '<button type="button" class="cu-mini" data-act="stamp-remove" data-v="' + s.id + '">Zurücknehmen</button></div>' +
				(edit ? '<div class="cu-li cu-li-edit"><input type="text" id="cu-ebase" inputmode="decimal" aria-label="Neuer Grundbetrag in Euro" value="' + esc((S.editBase / 100).toFixed(2).replace('.', ',')) + '"/><button type="button" class="cu-mini is-gold" data-act="stamp-base-save" data-v="' + s.id + '">Speichern</button></div>' : ''); }).join('') + '</div></details>'; }
		h += sec('Stempelkarte und Gutscheine', st);

		// numbers
		var pay = Object.keys(c.pay).map(function (k) { return ({ cash: 'bar', card_door: 'Karte', mollie: 'online', lieferando: 'Lieferando' }[k] || k) + ' ' + c.pay[k]; }).join(' · ');
		h += sec('Zahlen', '<dl class="cu-dl"><div><dt>Umsatz</dt><dd>' + money(c.rev) + '</dd></div><div><dt>Ø Bestellung</dt><dd>' + money(c.avg) + '</dd></div><div><dt>Erste Bestellung</dt><dd>' + (c.first ? dmy(c.first) : '–') + '</dd></div><div><dt>Storniert</dt><dd>' + c.bad + '</dd></div></dl>' +
			(c.top.length ? '<p class="cu-line"><span>Am liebsten</span> ' + c.top.map(function (t) { return esc(t[0]) + ' (' + t[1] + '×)'; }).join(', ') + '</p>' : '') + (pay ? '<p class="cu-line"><span>Zahlart</span> ' + esc(pay) + '</p>' : ''));

		// note and marks
		var d = S.draft || { note: c.note, flags: c.flags.slice() };
		h += sec('Notiz und Merkmale', '<div class="cu-chips" role="group" aria-label="Merkmale">' + Object.keys(FLAGS).map(function (f) { var on = d.flags.indexOf(f) >= 0; return '<button type="button" class="cu-chip' + (on ? ' is-on' : '') + '" data-flag="' + f + '" aria-pressed="' + on + '">' + esc(FLAGS[f]) + '</button>'; }).join('') + '</div>' +
			'<textarea id="cu-note" rows="3" maxlength="1000" placeholder="Z. B. klingelt bei Müller, 2. OG links. Wird an der Kasse beim Anruf gezeigt." aria-label="Notiz zum Kunden">' + esc(d.note) + '</textarea>' +
			'<div class="cu-acts"><button type="button" class="cu-btn is-gold" data-act="note-save">Speichern</button></div>');

		// accounts
		if (c.accounts.length) {
			h += sec('Kundenkonto', c.accounts.map(function (a) {
				return '<div class="cu-acc"><p><b>' + esc([a.phone, a.mail].filter(Boolean).join(' · ') || 'Konto ' + a.id) + '</b>' + (a.blocked ? ' <span class="cu-tag is-bad">gesperrt</span>' : '') + '</p><p class="cu-hint">angelegt ' + dmy(a.created) + (a.login ? ' · zuletzt angemeldet ' + dmy(a.login) + ' ' + a.login.slice(11) : ' · noch nie angemeldet') + (a.address ? ' · ' + esc(a.address) : '') + '</p>' +
					(canAdmin ? '<div class="cu-acts"><button type="button" class="cu-btn" data-act="acc-block" data-v="' + a.id + '" data-on="' + (a.blocked ? 0 : 1) + '">' + (a.blocked ? 'Entsperren' : 'Sperren') + '</button><button type="button" class="cu-btn" data-act="acc-reset" data-v="' + a.id + '">Überall abmelden</button></div>' : '') + '</div>'; }).join(''));
		}
		// orders
		h += sec('Bestellungen', c.orders.length ? '<div class="cu-list2">' + c.orders.map(function (o) {
			return '<a class="cu-li cu-li-a" href="main_page.php?p=9&date=' + esc(o.at.slice(0, 10)) + '"><span><b>#' + o.day_no + '</b> · ' + dm(o.at) + ' ' + esc(o.at.slice(11, 16)) + ' · ' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + (o.source === 'lieferando' ? ' · Lieferando' : '') + '</span><span class="cu-li-s">' + esc(STATUS[o.status] || o.status) + '</span><b>' + esc(money(o.total)) + '</b></a>'; }).join('') + '</div>' : '<p class="cu-hint">Noch keine Bestellung.</p>');
		if (c.log.length) { h += sec('Verlauf', '<div class="cu-list2">' + c.log.map(function (l) { return '<div class="cu-li"><span>' + esc(l.detail) + '</span><span class="cu-li-s">' + dmy(l.at.slice(0, 10)) + ' ' + esc(l.at.slice(11)) + ' · ' + esc(l.by) + '</span></div>'; }).join('') + '</div>'); }
		// more actions
		if (canAdmin) {
			var more = '<div class="cu-acts">' + (c.links.length ? '<button type="button" class="cu-btn" data-act="unlink">Zusammenführung aufheben</button>' : '<button type="button" class="cu-btn" data-act="panel" data-v="link">Mit anderem Kunden zusammenführen</button>') +
				'<a class="cu-btn" href="kunde_auskunft.php?c=' + esc(c.id) + '" target="_blank" rel="noopener">Auskunft drucken</a></div>';
			if (S.panel === 'link') { more += '<div class="cu-form"><p><b>Dieselbe Person</b> unter zweiter Nummer oder Mailadresse: beide werden hier als ein Kunde gezeigt. Stempel und Konten bleiben, wie sie sind.</p><input type="search" id="cu-link-q" placeholder="Name oder Nummer des anderen Kunden" autocomplete="off"/><div class="cu-list2" id="cu-link-res"></div></div>'; }
			more += '<div class="cu-danger"><p><b>Daten löschen</b> · Name, Kontakt, Adresse, Konto, Stempel, Gutscheine und Notizen werden entfernt. Die Bestellungen bleiben für die Buchhaltung, ohne Personenbezug. Das geht nicht rückgängig.</p>' +
				(S.panel === 'erase' ? '<label class="cu-lbl" for="cu-erase-in">Zur Bestätigung den Namen oder die Telefonnummer eintippen</label><input type="text" id="cu-erase-in" autocomplete="off"/><div class="cu-acts"><button type="button" class="cu-btn is-danger" data-act="erase">Endgültig löschen</button><button type="button" class="cu-btn" data-act="panel" data-v="">Abbrechen</button></div>' : '<div class="cu-acts"><button type="button" class="cu-btn is-danger-o" data-act="panel" data-v="erase">Daten löschen ...</button></div>') + '</div>';
			h += sec('Weitere Aktionen', more);
		}
		return h;
	}
	function renderCard() {
		var root = $('#cu-card'), c = S.card;
		if (!c) { root.innerHTML = '<div class="cu-none"><p><b>Kunde wählen</b></p><p>Links suchen oder aus der Liste öffnen. Mit den Pfeiltasten springst du durch die Liste.</p></div>'; return; }
		var scroll = root.scrollTop; root.innerHTML = cardHtml(c); root.scrollTop = scroll;
	}
	function openCard(id, keepPanel) {
		S.sel = id; if (!keepPanel) { S.panel = ''; S.editStamp = 0; S.draft = null; S.reason = ''; S.amount = 0; S.days = 30; }
		try { history.replaceState(null, '', 'main_page.php?p=13&c=' + id); } catch (e) {}
		var seq = ++cardSeq;
		return get('card', { id: id }).then(function (r) {
			if (seq !== cardSeq) { return; }
			if (!r.ok) { S.card = null; S.sel = null; renderCard(); renderList(); return; }
			S.card = r.card; canAdmin = !!r.can_admin; renderCard(); renderList();
			var row = $('.cu-row.is-sel'); if (row && row.scrollIntoView) { row.scrollIntoView({ block: 'nearest' }); }
		}).catch(function () { say('Keine Verbindung.', 'error'); });
	}
	function mutate(op, body, okText, after) {
		body = body || {}; body.id = S.sel;
		return post(op, body).then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', 'error'); return; }
			S.panel = ''; S.draft = null; S.reason = '';
			var id = (r.id && op === 'link') ? r.id : S.sel; if (after) { after(r); }
			return Promise.all([openCard(id, false), loadList(false)]).then(function () { say(okText + (r.code ? ' ' + r.code : ''), 'ok'); });
		}).catch(function () { say('Keine Verbindung. Bitte versuche es noch einmal.', 'error'); });
	}
	// a second tap confirms (a button that changes something for good asks once)
	function armed(btn, ask, go) {
		if (btn.dataset.armed === '1') { go(); return; }
		var old = btn.textContent; btn.dataset.armed = '1'; btn.textContent = ask; btn.classList.add('is-armed');
		setTimeout(function () { if (btn.isConnected) { btn.dataset.armed = ''; btn.textContent = old; btn.classList.remove('is-armed'); } }, 4000);
	}
	function keepDraft() { var n = $('#cu-note'); if (n) { S.draft = { note: n.value, flags: (S.draft ? S.draft.flags : S.card.flags).slice() }; } }

	$('#cu-card').addEventListener('click', function (ev) {
		var c = S.card; if (!c) { return; }
		var f = ev.target.closest('[data-flag]');
		if (f) { keepDraft(); var d = S.draft || { note: c.note, flags: c.flags.slice() }; var i = d.flags.indexOf(f.dataset.flag); if (i >= 0) { d.flags.splice(i, 1); } else { d.flags.push(f.dataset.flag); } S.draft = d; renderCard(); return; }
		var rs = ev.target.closest('[data-reason]'); if (rs) { keepDraft(); S.reason = S.reason === rs.dataset.v ? '' : rs.dataset.v; renderCard(); return; }
		var am = ev.target.closest('[data-amount]'); if (am) { keepDraft(); S.amount = S.amount === +am.dataset.amount ? 0 : +am.dataset.amount; renderCard(); return; }
		var sb = ev.target.closest('[data-sbase]'); if (sb) { keepDraft(); S.stampBase = +sb.dataset.sbase; renderCard(); return; }
		var dy = ev.target.closest('[data-days]'); if (dy) { keepDraft(); S.days = +dy.dataset.days; renderCard(); return; }
		var b = ev.target.closest('[data-act]'); if (!b) { return; }
		var act = b.dataset.act, v = b.dataset.v;
		if (act === 'panel') { keepDraft(); S.panel = S.panel === v ? '' : v; if (S.panel === 'stamp') { S.stampBase = c.suggest; } renderCard(); var li = $('#cu-link-q'); if (li) { li.focus(); } var ei = $('#cu-erase-in'); if (ei) { ei.focus(); } return; }
		if (act === 'note-save') { var n = $('#cu-note'); mutate('note', { note: n ? n.value : '', flags: (S.draft ? S.draft.flags : c.flags) }, 'Gespeichert.'); return; }
		if (act === 'stamp-add') {
			var sin = $('#cu-sbase'), sbv = S.stampBase; if (sin && sin.value.trim() !== '') { var sx = parseFloat(sin.value.replace(',', '.')); sbv = isFinite(sx) ? Math.round(sx * 100) : 0; }
			if (sbv < 100) { say('Bitte einen Grundbetrag wählen oder eintippen.', 'error'); return; }
			var nb = $('#cu-notify'); mutate('stamp_add', { base: sbv, reason: S.reason, notify: !!(nb && nb.checked) }, 'Stempel gutgeschrieben.'); return;
		}
		if (act === 'stamp-edit') { S.editStamp = S.editStamp === +v ? 0 : +v; S.editBase = +b.dataset.b; keepDraft(); renderCard(); var ei2 = $('#cu-ebase'); if (ei2) { ei2.focus(); ei2.select(); } return; }
		if (act === 'stamp-base-save') {
			var ein = $('#cu-ebase'), ev2 = ein ? parseFloat(ein.value.replace(',', '.')) : NaN, cents = isFinite(ev2) ? Math.round(ev2 * 100) : 0;
			if (cents < 100) { say('Bitte einen Betrag zwischen 1,00 und 100,00 Euro eintippen.', 'error'); return; }
			S.editStamp = 0; mutate('stamp_base', { stamp: +v, base: cents }, 'Grundbetrag geändert.'); return;
		}
		if (act === 'stamp-remove') { armed(b, 'Wirklich zurücknehmen?', function () { mutate('stamp_remove', { stamp: +v, reason: 'Korrektur' }, 'Stempel zurückgenommen.'); }); return; }
		if (act === 'coupon-issue') {
			var inp = $('#cu-amt'), amt = S.amount; if (inp && inp.value.trim() !== '') { var x = parseFloat(inp.value.replace(',', '.')); amt = isFinite(x) ? Math.round(x * 100) : 0; }
			if (amt < 100) { say('Bitte einen Betrag wählen oder eintippen.', 'error'); return; }
			var nb2 = $('#cu-notify'); mutate('coupon_issue', { value: amt, days: S.days, reason: S.reason, notify: !!(nb2 && nb2.checked) }, 'Gutschein ausgestellt:'); return;
		}
		if (act === 'coupon-toggle') { mutate('coupon_toggle', { coupon: +v }, 'Gutschein geändert.'); return; }
		if (act === 'acc-block') { var on = b.dataset.on === '1'; armed(b, on ? 'Wirklich sperren?' : 'Wirklich entsperren?', function () { mutate('account_block', { account: +v, on: on }, on ? 'Konto gesperrt.' : 'Konto entsperrt.'); }); return; }
		if (act === 'acc-reset') { armed(b, 'Überall abmelden?', function () { mutate('account_reset', { account: +v }, 'Der Kunde ist überall abgemeldet.'); }); return; }
		if (act === 'unlink') { armed(b, 'Wirklich aufheben?', function () { mutate('unlink', {}, 'Zusammenführung aufgehoben.'); }); return; }
		if (act === 'erase') { var e = $('#cu-erase-in'); mutate('erase', { confirm: e ? e.value : '' }, 'Gelöscht.', function () { S.card = null; S.sel = null; }); return; }
		if (act === 'link-pick') { mutate('link', { other: v }, 'Zusammengeführt.'); }
	});
	$('#cu-card').addEventListener('input', function (ev) {
		if (ev.target.id === 'cu-notify') { S.notify = ev.target.checked; return; }
		if (ev.target.id === 'cu-amt') { S.amount = 0; return; }
		if (ev.target.id === 'cu-sbase') { S.stampBase = 0; return; }
		if (ev.target.id === 'cu-link-q') {
			var q = ev.target.value.trim(); clearTimeout(qTimer);
			qTimer = setTimeout(function () {
				if (q.length < 2) { $('#cu-link-res').innerHTML = ''; return; }
				get('list', { q: q, view: 'all' }).then(function (r) {
					if (!r.ok) { return; }
					$('#cu-link-res').innerHTML = r.rows.filter(function (x) { return x.id !== S.sel; }).slice(0, 6).map(function (x) { return '<div class="cu-li"><span><b>' + esc(x.name) + '</b> · ' + esc(x.phone || x.email) + ' · ' + x.n + '×</span><button type="button" class="cu-mini" data-act="link-pick" data-v="' + x.id + '">Zusammenführen</button></div>'; }).join('') || '<p class="cu-hint">Niemand gefunden.</p>';
				}).catch(function () {});
			}, 220);
		}
	});

	// ---- keyboard: arrows move through the list, "/" or typing outside a field goes to the search
	document.addEventListener('keydown', function (ev) {
		var t = ev.target, typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
		if (ev.key === '/' && !typing) { ev.preventDefault(); $('#cu-q').focus(); return; }
		if ((ev.key === 'ArrowDown' || ev.key === 'ArrowUp') && (!typing || t.id === 'cu-q') && S.rows.length) {
			ev.preventDefault();
			S.kb = Math.max(0, Math.min(S.rows.length - 1, (S.kb < 0 ? S.rows.findIndex(function (r) { return r.id === S.sel; }) : S.kb) + (ev.key === 'ArrowDown' ? 1 : -1)));
			openCard(S.rows[S.kb].id); return;
		}
		if (ev.key === 'Enter' && t && t.id === 'cu-q' && S.rows.length) { ev.preventDefault(); openCard(S.rows[0].id); }
	});

	// the page fills the window: the list and the card scroll on their own
	function fit() { var g = $('#cu-grid'); if (!g) { return; } var top = g.getBoundingClientRect().top + window.scrollY; g.style.height = Math.max(480, window.innerHeight - g.getBoundingClientRect().top - 14) + 'px'; }
	window.addEventListener('resize', fit); fit(); setTimeout(fit, 300);

	loadList(false).then(function () { if (page.dataset.open) { openCard(page.dataset.open); } });
}());
