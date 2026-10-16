/* Newsletter page (p=14), tab "Absender": the sender address of the mails by purpose (newsletter, orders, reservations) with its own mail server access and a test mail.
   Nothing saved = the mails go as before. The password is written here and never shown again (the server answers only whether one is set). */
(function () {
	'use strict';
	var page = document.getElementById('nl-page'), root = document.getElementById('nls-root'); if (!page || !root) { return; }
	var TOKEN = page.dataset.token, loaded = false;
	var HELP = {
		news: 'Alle Mails, die Werbung sind oder dazu gehören: Mailings, die Automatiken und die Bestätigungsmails zur Anmeldung. Empfehlung: eine eigene Adresse auf einer Unterdomain, zum Beispiel hallo@news.…, damit eine Beschwerde über den Newsletter die Bestellmails nicht mitzieht.',
		order: 'Bestellbestätigung, Status, Stempelkarte, Anmelde-Codes und die Bitte um Bewertung. Empfehlung: bestellung@ auf der Hauptdomain.',
		reservation: 'Bestätigung, Erinnerung und alles zur Reservierung. Empfehlung: reservierung@ auf der Hauptdomain.'
	};
	function el(tag, a, kids) {
		var e = document.createElement(tag), k;
		for (k in (a || {})) { if (k === 'text') { e.textContent = a[k]; } else { e.setAttribute(k, a[k]); } }
		(kids || []).forEach(function (c) { if (c) { e.appendChild(c); } });
		return e;
	}
	function post(op, data) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN);
		for (var k in (data || {})) { fd.append(k, data[k]); }
		return fetch('ajax/newsletter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function field(id, label, value, opts) {
		opts = opts || {};
		var input = el('input', { type: opts.type || 'text', id: id, autocomplete: opts.auto || 'off', spellcheck: 'false', maxlength: String(opts.max || 160) });
		if (opts.placeholder) { input.setAttribute('placeholder', opts.placeholder); }
		input.value = value === undefined || value === null ? '' : value;
		return el('div', { class: 'nls-f' }, [el('label', { for: id, text: label }), input]);
	}
	function card(s) {
		var p = s.purpose, id = function (n) { return 'nls-' + p + '-' + n; };
		var sec = el('select', { id: id('secure') }, [el('option', { value: 'tls', text: 'STARTTLS (Port 587)' }), el('option', { value: 'ssl', text: 'SSL (Port 465)' }), el('option', { value: '', text: 'ohne Verschlüsselung' })]);
		sec.value = s.secure;
		var msg = el('p', { class: 'detail-status', role: 'status', 'aria-live': 'polite' });
		var save = el('button', { type: 'button', class: 'sh-btn is-primary', text: 'Speichern' });
		var test = el('button', { type: 'button', class: 'sh-btn', text: 'Testmail senden' });
		var reset = el('button', { type: 'button', class: 'sh-btn', text: 'Zurücksetzen' });
		var to = el('input', { type: 'email', id: id('to'), placeholder: 'Testmail an ...', autocomplete: 'email', 'aria-label': 'Empfänger der Testmail' });
		var details = el('details', { class: 'nls-smtp' }, [
			el('summary', { text: 'Mailserver dieses Postfachs (SMTP)' }),
			el('p', { class: 'sh-help', text: 'Damit die Mails über das Postfach der Absenderadresse gehen: so passen Absender, Signatur (DKIM) und Domain zusammen. Die Angaben stehen im KAS beim Postfach. Ohne Angabe geht die Mail über den Server wie bisher.' }),
			el('div', { class: 'nls-grid' }, [
				field(id('host'), 'Mailserver', s.host, { max: 120, placeholder: 'z. B. w0188d2a.kasserver.com' }),
				field(id('port'), 'Port', String(s.port || 587), { max: 5 }),
				el('div', { class: 'nls-f' }, [el('label', { for: id('secure'), text: 'Verschlüsselung' }), sec]),
				field(id('user'), 'Benutzername (meist die Mailadresse)', s.user, { auto: 'off' }),
				field(id('pass'), 'Passwort', '', { type: 'password', auto: 'new-password', max: 200, placeholder: s.pass_set ? 'gespeichert, leer lassen zum Behalten' : '' })
			])
		]);
		var form = el('fieldset', { class: 'nls-card' }, [
			el('legend', { text: s.label }),
			el('p', { class: 'sh-help', text: HELP[p] || '' }),
			el('div', { class: 'nls-grid' }, [
				field(id('from'), 'Absenderadresse', s.from, { type: 'email', placeholder: 'z. B. hallo@news.amadeus-hildesheim.de' }),
				field(id('name'), 'Anzeigename', s.name, { max: 80, placeholder: 'z. B. Amadeus Hildesheim' }),
				field(id('reply'), 'Antwortadresse (leer = Absenderadresse)', s.reply, { type: 'email' })
			]),
			details,
			el('div', { class: 'nls-act' }, [save, reset, to, test]),
			msg
		]);
		if (s.host) { details.open = true; }
		function val(n) { return form.querySelector('#' + id(n)).value; }
		save.addEventListener('click', function () {
			save.disabled = true; msg.textContent = 'Einen Moment ...';
			post('sender_save', { purpose: p, from: val('from'), name: val('name'), reply: val('reply'), host: val('host'), port: val('port'), secure: val('secure'), user: val('user'), pass: val('pass') }).then(function (r) {
				save.disabled = false; msg.textContent = r.ok ? 'Gespeichert.' : (r.error || 'Das hat nicht geklappt.'); if (r.ok) { form.querySelector('#' + id('pass')).value = ''; }
			}).catch(function () { save.disabled = false; msg.textContent = 'Keine Verbindung.'; });
		});
		var armed = false;
		reset.addEventListener('click', function () {
			if (!armed) { armed = true; reset.textContent = 'Wirklich zurücksetzen?'; setTimeout(function () { armed = false; reset.textContent = 'Zurücksetzen'; }, 4000); return; }
			armed = false; reset.textContent = 'Zurücksetzen';
			post('sender_save', { purpose: p, clear: 1 }).then(function (r) { if (r.ok) { render(r.senders); } else { msg.textContent = r.error || 'Das hat nicht geklappt.'; } });
		});
		test.addEventListener('click', function () {
			if (!to.value.trim()) { msg.textContent = 'Bitte trag daneben die Adresse ein, an die die Testmail gehen soll.'; to.focus(); return; }
			test.disabled = true; msg.textContent = 'Die Testmail wird gesendet ...';
			post('sender_test', { purpose: p, to: to.value.trim() }).then(function (r) {
				test.disabled = false; msg.textContent = r.ok ? 'Die Testmail ist unterwegs. Schau im Posteingang und im Spam-Ordner nach.' : (r.error || 'Das hat nicht geklappt.');
			}).catch(function () { test.disabled = false; msg.textContent = 'Keine Verbindung.'; });
		});
		return form;
	}
	function render(list) {
		root.textContent = '';
		root.appendChild(el('p', { class: 'sh-help', text: 'Von welcher Adresse welche Mails kommen. Jeder Zweck kann eine eigene Absenderadresse haben, am besten mit eigenem Postfach: SPF, DKIM und DMARC gehören zur Domain der Absenderadresse, und der Ruf (ob Mails im Posteingang landen) hängt an ihr. Erst speichern, dann mit der Testmail prüfen. Solange hier nichts gespeichert ist, ändert sich nichts.' }));
		list.forEach(function (s) { root.appendChild(card(s)); });
	}
	function show() {
		if (loaded) { return; }
		root.textContent = 'Einen Moment ...';
		fetch('ajax/newsletter.php?op=sender_list', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { root.textContent = r.error || 'Das hat nicht geklappt.'; return; }
			loaded = true; render(r.senders);
		}).catch(function () { root.textContent = 'Keine Verbindung.'; });
	}
	window.NlSender = { show: show };
	var t = document.getElementById('nl-tab-sender');
	if (t && t.getAttribute('aria-selected') === 'true') { show(); }
}());
