/* Order chat: shows the messages of the server and sends what the guest pressed or typed. Nothing is decided here: the server offers the buttons and checks every answer. */
(function () {
	'use strict';
	var body = document.body, token = body.getAttribute('data-token');
	var log = document.getElementById('chat-log'), form = document.getElementById('chat-form'), input = document.getElementById('chat-text'), label = document.getElementById('chat-text-label');
	var err = document.getElementById('chat-error'), sendBtn = form.querySelector('.chat-send'), busy = false;
	var PLACEHOLDER = { text: 'Deine Antwort', search: 'Gericht suchen', ask: 'Schreib, was du möchtest', code: 'Code eingeben', number: 'Zahl eingeben' };
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
		var line = el('p', 'chat-text');
		line.appendChild(el('span', 'sr-only', m.role === 'guest' ? 'Du: ' : 'Assistent: '));   // who speaks (the side and the colour tell it only to the eye)
		line.appendChild(document.createTextNode(m.text));
		wrap.appendChild(line);
		if (m.role === 'bot') {
			if (m.links && m.links.length) {
				var ls = el('div', 'chat-links');
				m.links.forEach(function (l) { var a = el('a', 'chat-link-btn', l.l); a.href = l.url; ls.appendChild(a); });
				wrap.appendChild(ls);
			}
			if (m.buttons && m.buttons.length) {
				// short labels without a price sit side by side as chips; a long list of dishes shows its first six and "Mehr anzeigen", so a menu never fills the window
				var chips = m.buttons.every(function (b) { return b.v === 'confirm' || b.v === 'co' || b.v === 'last' || (b.l.length <= 24 && b.l.indexOf(' · ') < 0); });
				var box = el('div', 'chat-answers' + (chips ? ' is-chips' : '')); box.setAttribute('role', 'group'); box.setAttribute('aria-label', 'Antworten');
				var longList = m.buttons.filter(function (b) { return /^(prod|opt|cand|fill):/.test(b.v); }).length > 7, shown = 0, hiddenBtns = [];
				m.buttons.forEach(function (b) {
					var btn = el('button', 'chat-btn' + ((b.v === 'confirm' || b.v === 'co' || b.v === 'last') ? ' is-primary' : '')); btn.type = 'button'; btn.setAttribute('data-v', b.v);
					buttonLabel(btn, b.l);
					btn.addEventListener('click', function () { answer({ btn: b.v }); });
					if (longList && /^(prod|opt|cand|fill):/.test(b.v)) { if (++shown > 6) { btn.hidden = true; hiddenBtns.push(btn); } }
					box.appendChild(btn);
				});
				if (hiddenBtns.length) {
					var more = el('button', 'chat-btn is-more'); more.type = 'button'; more.appendChild(el('span', 'chat-btn-name', 'Mehr anzeigen (' + hiddenBtns.length + ')'));
					more.addEventListener('click', function () { hiddenBtns.forEach(function (h) { h.hidden = false; }); more.parentNode.removeChild(more); hiddenBtns[0].focus(); });
					var firstHidden = hiddenBtns[0]; box.insertBefore(more, firstHidden);
				}
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

	// inside the chat bubble of the order page: after every answer the page gets the cart of the chat, so both show the same
	var EMBED = document.body.classList.contains('is-embed') && window.parent !== window;
	function tellParent(r) {
		if (!EMBED || !r.export) { return; }
		parent.postMessage({ type: 'myseat-chat-cart', mode: r.export.mode, lines: r.export.lines, ordered: !!r.ordered }, location.origin);
	}
	// the page asks for the focus when the bubble opens, Escape asks the page to close it
	var wantFocus = false, ready = false;
	function focusChat() {
		if (!ready) { wantFocus = true; return; }
		var lastBot = log.querySelector('.chat-msg.is-bot:last-of-type');
		if (!form.hidden) { input.focus({ preventScroll: true }); } else if (lastBot) { lastBot.setAttribute('tabindex', '-1'); lastBot.focus({ preventScroll: true }); }
	}
	if (EMBED) {
		window.addEventListener('message', function (ev) { if (ev.origin === location.origin && ev.data && ev.data.type === 'myseat-chat-focus') { focusChat(); } });
		document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { parent.postMessage({ type: 'myseat-chat-close' }, location.origin); } });
	}

	function lock(on) {
		busy = on; sendBtn.disabled = on;
		Array.prototype.forEach.call(log.querySelectorAll('.chat-btn'), function (b) { b.disabled = on; }); cartBar.disabled = on;
	}

	// while the server thinks (the AI may take several seconds): three moving dots on the side of the bot, after a short delay so that a quick answer does not flicker;
	// after a while the text says that it takes longer, so nobody believes the chat hung
	var waitEl = null, waitT1 = null, waitT2 = null, waitSlot = document.getElementById('chat-wait-slot');
	function waitStart() {
		waitStop();
		waitT1 = setTimeout(function () {
			waitEl = el('div', 'chat-wait');
			var dots = el('span', 'chat-dots'); dots.setAttribute('aria-hidden', 'true'); dots.appendChild(el('i')); dots.appendChild(el('i')); dots.appendChild(el('i'));
			var txt = el('span', 'chat-wait-text', 'Einen Moment …');
			waitEl.appendChild(dots); waitEl.appendChild(txt); waitSlot.appendChild(waitEl);   // the slot is a status region of its own, next to the log
			waitEl.scrollIntoView({ block: 'end', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
			waitT2 = setTimeout(function () { txt.textContent = 'Das dauert einen Augenblick länger …'; }, 7000);
		}, 350);
	}
	function waitStop() {
		clearTimeout(waitT1); clearTimeout(waitT2);
		if (waitEl && waitEl.parentNode) { waitEl.parentNode.removeChild(waitEl); }
		waitEl = null;
	}

	function answer(ev, isCode) {
		if (busy) { return; }
		showError('');
		var btnEl = ev.btn != null ? document.querySelector('.chat-btn[data-v="' + ev.btn + '"]') : null;
		var nameEl = btnEl && btnEl.querySelector('.chat-btn-name'), priceEl = btnEl && btnEl.querySelector('.chat-btn-price');
		var shown = ev.btn === 'cart' ? 'Warenkorb' : ev.text != null ? (isCode ? '••••••' : ev.text) : ((nameEl ? nameEl.textContent : '') + (priceEl ? ' · ' + priceEl.textContent : ''));
		lock(true);
		// the answered buttons go away at once and the guest's line stays; they are kept aside so that they come back when the answer does not get through
		var removed = [];
		Array.prototype.forEach.call(log.querySelectorAll('.chat-answers'), function (a) { removed.push({ parent: a.parentNode, node: a, next: a.nextSibling }); a.parentNode.removeChild(a); });
		var echo = render({ role: 'guest', text: shown }, true);
		ev.op = 'send'; if (EMBED) { ev.embed = true; }
		waitStart();
		post(ev).then(function (r) {
			waitStop();
			if (!r.ok) {
				if (echo.parentNode) { echo.parentNode.removeChild(echo); }
				removed.forEach(function (x) { x.parent.insertBefore(x.node, x.next && x.next.parentNode === x.parent ? x.next : null); });
				lock(false);
				showError(r.error || 'Das hat nicht geklappt.');
				var again = log.querySelector('.chat-answers .chat-btn:not([hidden])'); if (again) { again.focus({ preventScroll: true }); } else if (!form.hidden) { input.focus({ preventScroll: true }); }
				return;
			}
			lock(false);
			if (r.ordered && !EMBED) { clearShopCart(); }
			tellParent(r);
			if (r.reset) { log.textContent = ''; }   // a finished order or a new start: the window begins again
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

	// the way back to the menu takes the cart of the chat along (the cart of the order page is kept in the browser under this key)
	var CART_KEY = 'amadeusCartV2';
	function clearShopCart() { try { var d = JSON.parse(localStorage.getItem(CART_KEY) || '{}'); d.cart = []; localStorage.setItem(CART_KEY, JSON.stringify(d)); } catch (e) {} }
	var menuLink = document.getElementById('chat-menu');
	if (menuLink) {
		menuLink.addEventListener('click', function (e) {
			e.preventDefault();
			var go = function () { location.href = menuLink.href; };
			post({ op: 'export' }).then(function (r) {
				if (r.ok && r.lines && r.lines.length) { try { var d = JSON.parse(localStorage.getItem(CART_KEY) || '{}'); localStorage.setItem(CART_KEY, JSON.stringify({ mode: r.mode, cart: r.lines, extra: d.extra || '' })); } catch (x) {} }
				go();
			});
		});
	}

	lock(true);
	post({ op: 'open' }).then(function (r) { lock(false); if (!r.ok) { showError(r.error || 'Der Chat ist gerade nicht erreichbar.'); return; } setCart(r.cart); showMessages(r.messages, false); ready = true; if (wantFocus) { focusChat(); } });
})();
