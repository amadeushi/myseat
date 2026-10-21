/* Gutschein-Atelier (order/gutschein.php): live preview of the card, amount and delivery choices, the sum, upload of an own picture. The server checks everything again on the order. */
(function () {
	'use strict';
	var form = document.getElementById('gv-form'); if (!form) { return; }
	var $ = function (id) { return document.getElementById(id); };
	var fee = +form.dataset.fee || 0, min = +form.dataset.min, max = +form.dataset.max, token = form.dataset.token;
	var img = $('gv-prev'), frame = $('gv-frame'), timer = null, seq = 0;
	function eur(c) { return (c / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }); }
	function checked(name) { var e = form.querySelector('input[name="' + name + '"]:checked'); return e ? e.value : ''; }
	function amount() {
		var c = checked('amount_choice');
		var v = c === 'custom' ? $('gv-amount-custom').value.replace(/[^0-9]/g, '') : c;
		var n = parseInt(v, 10);
		return (n >= min && n <= max) ? n : 0;
	}
	function delivery() { return checked('delivery'); }

	function preview() {
		var a = amount() || 50;
		var q = 'motif=' + encodeURIComponent(checked('motif') || 'kuchen') + '&amount=' + a + '&recipient=' + encodeURIComponent(form.elements.recipient.value) + '&from=' + encodeURIComponent(form.elements.from.value) + '&message=' + encodeURIComponent(form.elements.message.value);
		var mine = ++seq; frame.classList.add('is-busy');
		var n = new Image();
		n.onload = function () { if (mine === seq) { img.src = n.src; frame.classList.remove('is-busy'); } };
		n.onerror = function () { if (mine === seq) { frame.classList.remove('is-busy'); } };
		n.src = 'gutschein_preview.php?' + q;
	}
	function later() { clearTimeout(timer); timer = setTimeout(preview, 280); }

	function sum() {
		var a = amount(), f = delivery() === 'post' ? fee : 0;
		$('gv-sum-amount').textContent = a ? eur(a * 100) : '–';
		$('gv-sum-fee-row').hidden = f === 0; $('gv-sum-fee').textContent = f ? eur(f) : '';
		$('gv-sum-total').textContent = a ? eur(a * 100 + f) : '–';
	}
	function layout() {
		var d = delivery();
		$('gv-ship').hidden = d !== 'post';
		var l = $('gv-lapse'); if (l) { l.hidden = d !== 'pdf'; }
		$('gv-custom').hidden = checked('amount_choice') !== 'custom';
		sum();
	}
	function count() { $('gv-count').textContent = form.elements.message.value.length + ' / 160'; }

	form.addEventListener('change', function (e) {
		var n = e.target.name;
		if (n === 'amount_choice' || n === 'delivery') { layout(); if (n === 'amount_choice' && e.target.value === 'custom') { $('gv-amount-custom').focus(); } }
		if (n === 'amount_choice' || n === 'motif') { later(); }
	});
	form.addEventListener('input', function (e) {
		var n = e.target.name;
		if (n === 'amount_custom') { sum(); later(); }
		else if (n === 'recipient' || n === 'from' || n === 'message') { count(); later(); }
	});

	/* own picture */
	var file = $('gv-own-file'), msg = $('gv-own-msg');
	function say(t, bad) { if (msg) { msg.textContent = t || ''; msg.classList.toggle('is-error', !!bad); } }
	if (file) {
		file.addEventListener('change', function () {
			var f = file.files[0]; if (!f) { return; }
			if (f.size > 12 * 1024 * 1024) { say('Das Bild ist zu groß (höchstens 12 MB).', true); file.value = ''; return; }
			say('Dein Bild wird hochgeladen ...');
			var fd = new FormData(); fd.append('file', f); fd.append('token', token);
			fetch('gutschein_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
				file.value = '';
				if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', true); return; }
				say('Dein Bild ist da.');
				var lab = document.createElement('label'); lab.className = 'gv-motif';
				var inp = document.createElement('input'); inp.type = 'radio'; inp.name = 'motif'; inp.value = r.motif; inp.checked = true;
				var sp = document.createElement('span'); sp.textContent = 'Dein Bild';
				lab.appendChild(inp); lab.appendChild(sp);
				$('gv-own-list').insertBefore(lab, $('gv-own-pick'));
				preview();
			}).catch(function () { file.value = ''; say('Keine Verbindung. Bitte versuche es noch einmal.', true); });
		});
	}

	form.addEventListener('submit', function () { var b = $('gv-submit'); if (b && !b.disabled) { setTimeout(function () { b.disabled = true; b.textContent = 'Einen Moment ...'; }, 0); } });
	layout(); count();
	var e = $('gv-err'); if (e) { e.scrollIntoView({ block: 'center' }); }
})();
