/* Daily report page: the slips go to the receipt printer on the Raspberry Pi when its agent runs, otherwise through the print dialog of the browser. */
(function () {
	'use strict';
	var token = document.body.dataset.token, date = document.body.dataset.date, msg = document.getElementById('tb-msg');
	function say(t, cls) { msg.textContent = t; msg.className = 'tb-msg' + (cls ? ' ' + cls : ''); }
	function browserPrint(kind) {
		var cls = 'print-' + kind;
		document.body.classList.add(cls);
		var done = function () { document.body.classList.remove(cls); window.removeEventListener('afterprint', done); };
		window.addEventListener('afterprint', done);
		window.print();
	}
	function toPrinter(kind, btn) {
		if (btn) { btn.disabled = true; }
		var fd = new FormData(); fd.append('op', 'report_print'); fd.append('token', token); fd.append('date', date); fd.append('kind', kind);
		say('Wird gesendet ...');
		fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (btn) { btn.disabled = false; }
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', 'is-warn'); return; }
			if (r.queued) { say(kind === 'both' ? 'Beide Zettel sind beim Bondrucker.' : 'Der Zettel ist beim Bondrucker.', 'is-ok'); return; }
			say('Der Bondrucker ist gerade nicht erreichbar. Es öffnet sich der Druckdialog.', 'is-warn'); browserPrint(kind);
		}).catch(function () { if (btn) { btn.disabled = false; } say('Keine Verbindung. Es öffnet sich der Druckdialog.', 'is-warn'); browserPrint(kind); });
	}
	document.addEventListener('click', function (ev) {
		var p = ev.target.closest('[data-print]'); if (p) { toPrinter(p.dataset.print, p); return; }
		var b = ev.target.closest('[data-browser]'); if (b) { browserPrint(b.dataset.browser); return; }
		if (ev.target.closest('#tb-both')) { toPrinter('both', ev.target.closest('#tb-both')); }
	});
}());
