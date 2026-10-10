/* Order chat: shows the messages of the server and sends what the guest pressed or typed. Nothing is decided here: the server offers the buttons and checks every answer. */
(function () {
	'use strict';
	var body = document.body, token = body.getAttribute('data-token');
	var log = document.getElementById('chat-log'), form = document.getElementById('chat-form'), input = document.getElementById('chat-text'), label = document.getElementById('chat-text-label');
	var err = document.getElementById('chat-error'), sendBtn = form.querySelector('.chat-send'), busy = false;
	var PLACEHOLDER = { text: 'Deine Antwort', search: 'Gericht suchen', code: 'Code eingeben', number: 'Zahl eingeben' };
	var cartBar = document.getElementById('chat-cart');

	function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (txt != null) { e.textContent = txt; } return e; }
	function showError(t) { err.textContent = t || ''; err.hidden = !t; }

	function post(data) {
		data.token = token;
		return fetch('chat_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.catch(function () { return { ok: false, error: 'Keine Verbindung. Bitte prüfe dein Netz und versuche es noch einmal.' }; });
	}

	// "Pizza Salami · ab 9,50 €" -> name left, price right (tabular figures)
	function buttonLabel(btn, text) {
		var i = text.lastIndexOf(' · ');
		if (i > 0 && /€\s*$/.test(text)) { btn.appendChild(el('span', 'chat-btn-name', text.slice(0, i))); btn.appendChild(el('span', 'chat-btn-price', text.slice(i + 3))); }
		else { btn.appendChild(el('span', 'chat-btn-name', text)); }
	}

	function render(m, fresh) {
		var wrap = el('div', 'chat-msg ' + (m.role === 'guest' ? 'is-guest' : 'is-bot') + (fresh ? ' is-new' : ''));
		wrap.appendChild(el('p', 'chat-text', m.text));
		if (m.role === 'bot') {
			if (m.links && m.links.length) {
				var ls = el('div', 'chat-links');
				m.links.forEach(function (l) { var a = el('a', 'chat-link-btn', l.l); a.href = l.url; ls.appendChild(a); });
				wrap.appendChild(ls);
			}
			if (m.buttons && m.buttons.length) {
				var box = el('div', 'chat-answers'); box.setAttribute('role', 'group'); box.setAttribute('aria-label', 'Antworten');
				m.buttons.forEach(function (b) {
					var btn = el('button', 'chat-btn' + ((b.v === 'confirm' || b.v === 'co') ? ' is-primary' : '')); btn.type = 'button'; btn.setAttribute('data-v', b.v);
					buttonLabel(btn, b.l);
					btn.addEventListener('click', function () { answer({ btn: b.v }); });
					box.appendChild(btn);
				});
				wrap.appendChild(box);
			}
		}
		log.appendChild(wrap);
		return wrap;
	}

	function setInput(kind) {
		form.hidden = !kind;
		if (!kind) { return; }
		input.value = ''; input.placeholder = PLACEHOLDER[kind] || PLACEHOLDER.text;
		label.textContent = input.placeholder;
		input.setAttribute('inputmode', kind === 'number' ? 'numeric' : 'text');
		input.setAttribute('autocomplete', kind === 'code' ? 'one-time-code' : 'off');
	}

	// new messages: scroll so that the start of the first new bot message (when a conversation is resumed: of the last one) is visible (not the bottom of a long button list) and move reading focus there;
	// a message that wants typed input puts the cursor in the field
	function showMessages(list, fresh) {
		var first = null, kind = '';
		list.forEach(function (m) { var w = render(m, fresh); if (m.role === 'bot') { first = (fresh && first) ? first : w; kind = m.input || ''; } });
		setInput(kind);
		if (!first) { return; }
		first.setAttribute('tabindex', '-1');
		first.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
		if (kind) { input.focus({ preventScroll: true }); } else if (fresh) { first.focus({ preventScroll: true }); }
	}

	function setCart(c) {
		var n = c ? c.count : 0;
		cartBar.hidden = !n;
		if (n) { document.getElementById('chat-cart-count').textContent = n; document.getElementById('chat-cart-total').textContent = c.total; cartBar.setAttribute('aria-label', 'Warenkorb ansehen, ' + n + (n === 1 ? ' Artikel, ' : ' Artikel, ') + c.total); }
	}
	cartBar.addEventListener('click', function () { answer({ btn: 'cart' }); });

	function lock(on) {
		busy = on; sendBtn.disabled = on;
		Array.prototype.forEach.call(log.querySelectorAll('.chat-btn'), function (b) { b.disabled = on; }); cartBar.disabled = on;
	}

	function answer(ev, isCode) {
		if (busy) { return; }
		showError('');
		var btnEl = ev.btn != null ? document.querySelector('.chat-btn[data-v="' + ev.btn + '"]') : null;
		var nameEl = btnEl && btnEl.querySelector('.chat-btn-name'), priceEl = btnEl && btnEl.querySelector('.chat-btn-price');
		var shown = ev.btn === 'cart' ? 'Warenkorb' : ev.text != null ? (isCode ? '••••••' : ev.text) : ((nameEl ? nameEl.textContent : '') + (priceEl ? ' · ' + priceEl.textContent : ''));
		lock(true);
		// the answered buttons go away at once, the guest's line stays
		Array.prototype.forEach.call(log.querySelectorAll('.chat-answers'), function (a) { a.parentNode.removeChild(a); });
		render({ role: 'guest', text: shown }, true);
		ev.op = 'send';
		post(ev).then(function (r) {
			lock(false);
			if (!r.ok) { showError(r.error || 'Das hat nicht geklappt.'); return; }
			setCart(r.cart); showMessages(r.messages, true);
		});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var t = input.value.trim(); if (!t || busy) { return; }
		answer({ text: t }, /^Code/.test(input.placeholder));
	});
	document.getElementById('chat-reset').addEventListener('click', function () {
		if (busy) { return; }
		lock(true); showError('');
		post({ op: 'reset' }).then(function (r) { lock(false); if (!r.ok) { showError(r.error); return; } log.textContent = ''; setCart(r.cart); showMessages(r.messages, false); });
	});

	lock(true);
	post({ op: 'open' }).then(function (r) { lock(false); if (!r.ok) { showError(r.error || 'Der Chat ist gerade nicht erreichbar.'); return; } setCart(r.cart); showMessages(r.messages, false); });
})();
