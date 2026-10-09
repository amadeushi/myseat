/* Guest account of the order page: sign-in by code or link (api.php: login_request / login_verify), the account dialog with orders
   ("Nochmal bestellen"), favorites and the stamp card, hearts on dishes and cart lines, and the row of favorites above the menu.
   Needs window.AmadeusShop (shop.js) and AmadeusStamp (stempel.js). The server decides everything; this only shows it. */
(function () {
	'use strict';
	var body = document.body, TOKEN = body.dataset.token, SH = window.AmadeusShop;
	if (body.dataset.account !== '1' || !SH) { return; }
	var dlg = document.getElementById('acc-dialog'), btn = document.getElementById('acc-btn'), favBox = document.getElementById('acc-favs');
	if (!dlg) { return; }
	var esc = SH.esc, fmt = SH.fmt;
	var me = null, pending = null, tab = 'orders', timer = null, trigger = null;
	// where to go after signing in (the checkout or the status page of an order sent the guest here): only these two pages
	var NEXT = ''; try { var nx = new URLSearchParams(location.search).get('next') || ''; if (/^(checkout\.php|status\.php\?t=[a-f0-9]{32})$/.test(nx)) { NEXT = nx; } } catch (e) {}

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function json(r) { return r.json(); }
	function api(op, data) {
		if (op === 'me') { return fetch('api.php?op=me&type=' + SH.mode(), { credentials: 'same-origin' }).then(json); }
		var b = { op: op, token: TOKEN }; Object.keys(data || {}).forEach(function (k) { b[k] = data[k]; });
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(b) }).then(json);
	}
	var NETERR = 'Das hat gerade nicht geklappt. Bitte prüfe deine Verbindung und versuche es noch einmal.';
	function signedIn() { return !!(me && me.signed_in); }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function when(s) {
		var t = new Date(String(s).replace(' ', 'T')); if (isNaN(t.getTime())) { return s; }
		var days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'], y = t.getFullYear() !== new Date().getFullYear() ? '.' + t.getFullYear() : '';
		return days[t.getDay()] + ' ' + pad(t.getDate()) + '.' + pad(t.getMonth() + 1) + '.' + y + ', ' + pad(t.getHours()) + ':' + pad(t.getMinutes()) + ' Uhr';
	}
	function keyOf(l) { var o = l.opts || {}; return [l.pid, l.vid || 0, Object.keys(o).sort().map(function (k) { return k + ':' + o[k]; }).join(','), (l.note || '').trim()].join('|'); }
	function findFav(l) { var k = keyOf(l), hit = null; ((me && me.favs) || []).forEach(function (f) { if (f.ok && keyOf(f.line) === k) { hit = f; } }); return hit; }

	// ---- the button in the header and the row of favorites above the menu
	function setMe(r) {
		me = r;
		// the header says who is signed in (a first name), so a guest sees at a glance that this browser knows her; the device remembers that there was an account (the checkout warns when it is gone)
		var lab = $('#acc-btn-label'); if (lab) { var fn = signedIn() && me.contact && me.contact.name ? String(me.contact.name).trim().split(/\s+/)[0] : ''; lab.textContent = signedIn() ? (fn ? 'Hallo ' + fn : 'Mein Konto') : 'Anmelden'; }
		if (signedIn()) { try { localStorage.setItem('amadeusHadAccount', '1'); } catch (e) {} }
		renderFavRow(); markHearts(); stampHint(); if (!signedIn()) { applied = ''; } else { applyAddress(); }
	}
	function refresh() { return api('me').then(function (r) { if (r && r.ok) { setMe(r); } return r; }).catch(function () {}); }
	function renderFavRow() {
		if (!favBox) { return; }
		var list = signedIn() ? me.favs.filter(function (f) { return f.ok; }) : [];
		if (!list.length || !SH.accepting) { favBox.hidden = true; favBox.innerHTML = ''; return; }
		favBox.innerHTML = '<div class="acc-favs-in"><h2>Deine Favoriten</h2><div class="acc-favs-list">' + list.map(function (f) {
			var l = f.line;
			return '<button type="button" class="acc-favchip" data-favchip="' + f.id + '"><span>' + esc(l.title) + (l.vtitle ? ' <em>' + esc(l.vtitle) + '</em>' : '') + '</span><small>' + (l.optText ? esc(l.optText) + ' · ' : '') + fmt(l.unit) + ' · in den Warenkorb</small></button>';
		}).join('') + '</div></div>';
		favBox.hidden = false;
	}

	// ---- hearts: on dishes without choices (the menu) and on every cart line (also a configured pizza, saved just as it is)
	function injectMenuHearts() {
		$$('.shop-item[data-choices="0"]').forEach(function (li) {
			if ($('.fav-btn', li)) { return; }
			var price = $('.shop-price', li); if (!price) { return; }
			var b = document.createElement('button'); b.type = 'button'; b.className = 'fav-btn'; b.dataset.favPid = li.dataset.id; b.setAttribute('aria-pressed', 'false');
			b.setAttribute('aria-label', li.dataset.title + ' als Favorit merken'); b.innerHTML = SH.heart; price.after(b);
		});
	}
	function markHearts() {
		var cart = SH.cart();
		$$('.fav-btn[data-fav-i]').forEach(function (b) {
			var l = cart[+b.dataset.favI]; if (!l) { return; }
			setHeart(b, !!findFav(l), l.title);
		});
		$$('.fav-btn[data-fav-pid]').forEach(function (b) {
			var li = b.closest('.shop-item'); setHeart(b, !!findFav({ pid: +b.dataset.favPid, vid: 0, opts: {}, note: '' }), li ? li.dataset.title : '');
		});
	}
	function setHeart(b, on, title) {
		b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.classList.toggle('is-on', on);
		b.setAttribute('aria-label', title + (on ? ' aus den Favoriten entfernen' : ' als Favorit merken'));
	}
	function toggleFav(line) {
		if (!signedIn()) { pending = function () { toggleFav(line); }; viewLogin('login'); openDlg(); return; }
		var have = findFav(line);
		var p = have ? api('fav_remove', { id: have.id }) : api('fav_add', { line: { pid: line.pid, vid: line.vid || 0, opts: line.opts || {}, note: line.note || '' } });
		p.then(function (r) {
			if (!r.ok) { SH.toast(r.error || NETERR); return; }
			me.favs = r.favs; renderFavRow(); markHearts();
			SH.toast(have ? 'Aus den Favoriten entfernt' : 'Als Favorit gemerkt');
		}).catch(function () { SH.toast(NETERR); });
	}

	// ---- the dialog
	// on a phone the window must stay inside what can be seen (address bar, on-screen keyboard) and the page behind must not scroll along:
	// the visible height and top of the visual viewport go into two variables the style sheet uses, and the page is locked while the window is open
	var root = document.documentElement, vv = window.visualViewport;
	function syncViewport() { root.style.setProperty('--vvh', (vv ? vv.height : window.innerHeight) + 'px'); root.style.setProperty('--vvt', (vv ? vv.offsetTop : 0) + 'px'); }
	function trackViewport(on) {
		root.classList.toggle('acc-lock', on);
		if (on) { syncViewport(); if (vv) { vv.addEventListener('resize', syncViewport); vv.addEventListener('scroll', syncViewport); } }
		else { if (vv) { vv.removeEventListener('resize', syncViewport); vv.removeEventListener('scroll', syncViewport); } root.style.removeProperty('--vvh'); root.style.removeProperty('--vvt'); }
	}
	function openDlg() {
		if (dlg.open) { return; }
		trigger = document.activeElement;
		trackViewport(true);
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}
	dlg.addEventListener('close', function () { clearInterval(timer); pending = null; trackViewport(false); if (trigger && typeof trigger.focus === 'function') { trigger.focus({ preventScroll: true }); } trigger = null; });
	dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { dlg.close(); } });
	function focusFirst(sel) { var el = $(sel, dlg); if (el) { el.focus({ preventScroll: true }); } }

	function viewLogin(mode, value) {
		clearInterval(timer);
		var link = mode === 'link', smsOk = !me || me.sms !== false, needPhone = link && me && me.account && !me.account.has_phone;
		var label = link ? (needPhone ? 'Handynummer' : 'E-Mail-Adresse') : (smsOk ? 'E-Mail-Adresse oder Handynummer' : 'E-Mail-Adresse');
		var type = needPhone ? 'tel' : ((link || !smsOk) ? 'email' : 'text');
		dlg.innerHTML = '<form class="gb-form acc-form" novalidate><h2 id="acc-title">' + (link ? 'Weitere Angabe bestätigen' : 'Anmelden') + '</h2>' +
			'<p>' + (link ? 'Wir schicken dir einen Code. Danach gehören auch die Bestellungen und Stempel unter dieser Angabe zu deinem Konto.'
				: 'Mit deiner ' + (smsOk ? 'E-Mail-Adresse oder Handynummer' : 'E-Mail-Adresse') + '. Wir schicken dir einen Code, ein Passwort brauchst du nicht.') + '</p>' +
			'<label class="co-f"><span>' + label + '</span><input type="' + type + '" name="t" maxlength="160" autocomplete="' + (needPhone ? 'tel' : 'email') + '" autocapitalize="off" spellcheck="false" enterkeyhint="send" value="' + esc(value || '') + '"/></label>' +
			'<p class="gb-err" role="alert"></p><div class="gb-actions"><button type="button" class="gb-btn ghost" data-acc-cancel>Abbrechen</button><button type="submit" class="gb-btn solid">Code senden</button></div>' +
			'<p class="acc-fine">Danach siehst du deine Bestellungen, Lieblingsgerichte und deine Stempelkarte. Du kannst dein Konto jederzeit wieder löschen.</p></form>';
		var f = $('form', dlg), err = $('.gb-err', dlg), busy = false;
		f.onsubmit = function (ev) {
			ev.preventDefault(); if (busy) { return; }
			var v = f.t.value.trim(); if (!v) { err.textContent = 'Bitte gib ' + (link ? 'die Angabe' : 'deine E-Mail-Adresse' + (smsOk ? ' oder Handynummer' : '')) + ' ein.'; f.t.focus(); return; }
			busy = true; err.textContent = ''; $('.solid', f).disabled = true;
			api(link ? 'link_request' : 'login_request', { target: v }).then(function (r) {
				busy = false; $('.solid', f).disabled = false;
				if (!r.ok) { err.textContent = r.error || NETERR; return; }
				viewCode(v, r.mask, link, r.minutes);
			}).catch(function () { busy = false; $('.solid', f).disabled = false; err.textContent = NETERR; });
		};
		focusFirst('input[name="t"]');
	}

	function viewCode(target, mask, link, minutes) {
		clearInterval(timer);
		dlg.innerHTML = '<form class="gb-form acc-form" novalidate><h2 id="acc-title">Code eingeben</h2>' +
			'<p>Wir haben einen Code an <strong>' + esc(mask) + '</strong> geschickt. Er gilt ' + (minutes || 10) + ' Minuten. Du kannst auch auf den Link in der Nachricht tippen.</p>' +
			'<label class="co-f"><span>6-stelliger Code</span><input class="acc-code" type="text" name="c" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" aria-describedby="acc-code-err"/></label>' +
			'<p class="gb-err" id="acc-code-err" role="alert"></p><div class="gb-actions"><button type="button" class="gb-btn ghost" data-acc-back>Andere Angabe</button><button type="submit" class="gb-btn solid">' + (link ? 'Bestätigen' : 'Anmelden') + '</button></div>' +
			'<button type="button" class="acc-resend" data-acc-resend disabled></button></form>';
		var f = $('form', dlg), err = $('.gb-err', dlg), rs = $('[data-acc-resend]', dlg), busy = false, left = 30;
		function tick() { if (left > 0) { rs.disabled = true; rs.textContent = 'Code erneut senden (' + left + ')'; left--; } else { rs.disabled = false; rs.textContent = 'Code erneut senden'; clearInterval(timer); } }
		tick(); timer = setInterval(tick, 1000);
		rs.onclick = function () {
			rs.disabled = true; err.textContent = '';
			api(link ? 'link_request' : 'login_request', { target: target }).then(function (r) {
				if (!r.ok) { err.textContent = r.error || NETERR; rs.disabled = false; return; }
				SH.toast('Ein neuer Code ist unterwegs'); left = 30; tick(); timer = setInterval(tick, 1000); f.c.value = ''; f.c.focus();
			}).catch(function () { err.textContent = NETERR; rs.disabled = false; });
		};
		$('[data-acc-back]', dlg).onclick = function () { viewLogin(link ? 'link' : 'login', target); };
		function submit() {
			if (busy) { return; }
			var c = f.c.value.replace(/\D/g, ''); if (c.length !== 6) { err.textContent = 'Der Code hat 6 Ziffern.'; f.c.focus(); return; }
			busy = true; err.textContent = ''; $('.solid', f).disabled = true;
			api('login_verify', { target: target, code: c }).then(function (r) {
				busy = false; $('.solid', f).disabled = false;
				if (!r.ok) { err.textContent = r.error || NETERR; f.c.value = ''; f.c.focus(); if (r.expired) { left = 0; tick(); } return; }
				setMe(r); done(link);
			}).catch(function () { busy = false; $('.solid', f).disabled = false; err.textContent = NETERR; });
		}
		f.onsubmit = function (ev) { ev.preventDefault(); submit(); };
		f.c.addEventListener('input', function () { f.c.value = f.c.value.replace(/\D/g, '').slice(0, 6); if (f.c.value.length === 6) { submit(); } });
		focusFirst('input[name="c"]');
	}
	function done(link) {
		clearInterval(timer);
		if (link) { SH.toast('Bestätigt, die Angabe gehört jetzt zu deinem Konto'); viewAccount(); return; }
		SH.toast('Du bist angemeldet');
		if (NEXT) { setTimeout(function () { location.href = NEXT; }, 700); return; }
		if (pending) { var p = pending; pending = null; dlg.close(); p(); return; }
		viewAccount();
	}

	// ---- the account: orders, favorites, stamp card
	var TRASH = '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l.7 10h6.6L14 6M8.5 9v4.5M11.5 9v4.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function orderChip(o) {
		var m = { 'new': 'Eingegangen', accepted: 'Angenommen', preparing: 'In Arbeit', ready: 'Fertig', delivering: 'Unterwegs' };
		return m[o.status] ? '<a class="acc-chip" href="status.php?t=' + esc(o.token) + '">' + m[o.status] + '</a>' : '';
	}
	function panelOrders() {
		if (!me.orders.length) { return '<p class="acc-empty">Noch keine Bestellung hier. Nach deiner ersten Bestellung findest du sie an dieser Stelle und kannst sie mit einem Tipp wiederholen.</p>'; }
		return me.orders.map(function (o) {
			return '<article class="acc-order" data-order="' + o.id + '"><header><div><strong>' + esc(when(o.at)) + '</strong><span>' + (o.type === 'pickup' ? 'Abholung' : 'Lieferung') + ' · ' + fmt(o.total) + '</span></div>' + orderChip(o) + '</header>' +
				'<ul class="acc-items">' + o.items.map(function (it) { return '<li>' + it.qty + '× ' + esc(it.title) + (it.variation ? ' <span>(' + esc(it.variation) + ')</span>' : '') + (it.opts ? '<small>' + esc(it.opts) + '</small>' : '') + '</li>'; }).join('') + '</ul>' +
				'<div class="acc-order-act">' + (SH.accepting ? '<button type="button" class="gb-btn solid" data-reorder="' + o.id + '">Nochmal bestellen</button>' : '<p class="acc-fine">Zurzeit nehmen wir keine Bestellungen an.</p>') + '<a href="status.php?t=' + esc(o.token) + '">Bestellung ansehen</a></div></article>';
		}).join('');
	}
	function panelFavs() {
		if (!me.favs.length) { return '<p class="acc-empty">Tippe bei einem Gericht oder im Warenkorb auf das Herz, dann liegt es hier für den nächsten Hunger bereit.</p>'; }
		return '<ul class="acc-favlist">' + me.favs.map(function (f) {
			if (!f.ok) { return '<li class="acc-fav is-gone"><div><strong>Ein Favorit</strong><small>Zurzeit nicht auf der Karte</small></div><div class="acc-fav-act"><button type="button" class="acc-icon" data-fav-del="' + f.id + '" aria-label="Favorit entfernen">' + TRASH + '</button></div></li>'; }
			var l = f.line;
			return '<li class="acc-fav"><div><strong>' + esc(l.title) + (l.vtitle ? ' <span>(' + esc(l.vtitle) + ')</span>' : '') + '</strong>' + (l.optText ? '<small>' + esc(l.optText) + '</small>' : '') + (l.note ? '<small>Hinweis: ' + esc(l.note) + '</small>' : '') + '<b>' + fmt(l.unit) + '</b></div>' +
				'<div class="acc-fav-act">' + (SH.accepting ? '<button type="button" class="gb-btn solid" data-fav-cart="' + f.id + '">In den Warenkorb</button>' : '') + '<button type="button" class="acc-icon" data-fav-del="' + f.id + '" aria-label="' + esc(l.title) + ' aus den Favoriten entfernen">' + TRASH + '</button></div></li>';
		}).join('') + '</ul>';
	}

	// ---- the delivery address of the account: kept only when we deliver there, and put into the menu page and the checkout
	function panelAddr() {
		var a = me.address || { street: '', zip: '', city: 'Hildesheim', note: '' }, c = me.contact || {};
		return '<form class="acc-addr" data-addr-form novalidate><p class="acc-fine">Name, Telefonnummer und Lieferadresse sind beim Anmelden gleich im Shop und in der Kasse eingetragen, die Adresse mit Liefergebühr und Mindestbestellwert.</p>' +
			'<label class="co-f"><span>Name</span><input type="text" name="name" maxlength="80" autocomplete="name" value="' + esc(c.name || '') + '"/></label>' +
			'<label class="co-f"><span>Telefon</span><input type="tel" name="phone" inputmode="tel" maxlength="30" autocomplete="tel" value="' + esc(c.phone || '') + '"/></label>' +
			'<label class="co-f"><span>Straße und Hausnummer</span><input type="text" name="street" maxlength="120" autocomplete="street-address" value="' + esc(a.street) + '"/></label>' +
			'<div class="acc-addr-row"><label class="co-f"><span>PLZ</span><input type="text" name="zip" inputmode="numeric" maxlength="10" autocomplete="postal-code" value="' + esc(a.zip) + '"/></label>' +
			'<label class="co-f"><span>Ort</span><input type="text" name="city" maxlength="80" autocomplete="address-level2" value="' + esc(a.city) + '"/></label></div>' +
			'<label class="co-f"><span>Hinweis für den Fahrer (optional)</span><input type="text" name="note" maxlength="200" placeholder="z. B. 2. Etage, Klingel Müller" value="' + esc(a.note) + '"/></label>' +
			'<p class="acc-addr-msg" id="acc-addr-msg" role="status"></p>' +
			'<div class="gb-actions">' + (me.address ? '<button type="button" class="gb-btn ghost" data-addr-del>Adresse entfernen</button>' : '') + '<button type="submit" class="gb-btn solid">Speichern</button></div></form>';
	}
	function addrMsg(cls, t) { var m = $('#acc-addr-msg', dlg); if (m) { m.className = 'acc-addr-msg' + (cls ? ' ' + cls : ''); m.textContent = t; } }
	function saveAddr(data) {
		var btn = $('[data-addr-form] .solid', dlg); if (btn) { btn.disabled = true; } addrMsg('', 'Adresse wird geprüft ...');
		api('addr_save', data).then(function (r) {
			if (btn) { btn.disabled = false; }
			if (!r.ok) { addrMsg('bad', r.error || NETERR); return; }
			var zone = r.zone, removed = r.removed; setMe(r); applied = ''; applyAddress(true); viewAccount(true);
			addrMsg(zone ? 'ok' : '', zone ? '✓ Gespeichert. Wir liefern zu dir · ' + fmt(zone.fee) + ' Liefergebühr, ab ' + fmt(zone.min) + ' Mindestbestellwert' : (removed ? 'Gespeichert. Es ist keine Lieferadresse hinterlegt.' : 'Gespeichert.'));
		}).catch(function () { if (btn) { btn.disabled = false; } addrMsg('bad', NETERR); });
	}
	// signed in with an address: it goes into the "liefert ihr zu mir" check of the menu page, which then shows fee and minimum
	var applied = '';
	function applyAddress(force) {
		var s = $('#sz-street'), z = $('#sz-zip'), c = $('#sz-city');
		if (!signedIn() || !me.address || !s || !z || !c) { return; }
		var key = [me.address.street, me.address.zip, me.address.city].join('|');
		if (applied === key && !force) { return; }
		applied = key; s.value = me.address.street; z.value = me.address.zip; c.value = me.address.city;
		s.dispatchEvent(new Event('input', { bubbles: true }));
	}
	function viewAccount(keepTab) {
		clearInterval(timer);
		if (!keepTab) { tab = me.orders.length ? 'orders' : 'stamp'; }
		var who = [me.account.mask_mail, me.account.mask_phone].filter(Boolean).join(' · ');
		var tabs = [['orders', 'Bestellungen'], ['favs', 'Favoriten' + (me.favs.length ? ' (' + me.favs.length + ')' : '')], ['addr', 'Meine Daten'], ['stamp', 'Stempel']];
		var add = !me.account.has_mail ? 'E-Mail-Adresse ergänzen' : (!me.account.has_phone && me.sms !== false ? 'Handynummer ergänzen' : '');
		dlg.innerHTML = '<div class="acc-view"><div class="acc-head"><div><h2 id="acc-title" tabindex="-1">Mein Konto</h2><p class="acc-who">Angemeldet mit ' + esc(who) + '</p></div><button type="button" class="acc-x" data-acc-cancel aria-label="Schließen">&times;</button></div>' +
			'<div class="acc-tabs" role="tablist" aria-label="Dein Konto">' + tabs.map(function (t) { return '<button type="button" role="tab" id="acc-tab-' + t[0] + '" aria-selected="' + (tab === t[0]) + '" aria-controls="acc-panel" tabindex="' + (tab === t[0] ? '0' : '-1') + '" data-tab="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
			'<div class="acc-panel" id="acc-panel" role="tabpanel" aria-labelledby="acc-tab-' + tab + '">' + (tab === 'orders' ? panelOrders() : (tab === 'favs' ? panelFavs() : (tab === 'addr' ? panelAddr() : '<div id="acc-stamp"></div>'))) + '</div>' +
			'<details class="acc-more"><summary>Konto verwalten</summary><div class="acc-more-in">' + (add ? '<button type="button" class="gb-btn ghost" data-acc-link>' + add + '</button>' : '') +
			'<button type="button" class="gb-btn ghost" data-acc-logout>Abmelden</button><button type="button" class="gb-btn ghost" data-acc-logout-all>Auf allen Geräten abmelden</button>' +
			'<button type="button" class="gb-btn ghost acc-danger" data-acc-delete>Konto löschen</button></div></details></div>';
		if (tab === 'stamp') {
			var box = $('#acc-stamp', dlg);
			if (me.stamp && me.stamp.on) { AmadeusStamp.render(box, me.stamp, { known: true, next: true }); } else { box.innerHTML = '<p class="acc-empty">Die Stempelkarte gibt es zurzeit nicht.</p>'; }
		}
		if (!keepTab) { var h = $('#acc-title', dlg); if (h) { h.focus({ preventScroll: true }); } }
	}

	function signedOut(msg) { setMe({ ok: true, signed_in: false, sms: !me || me.sms !== false }); dlg.close(); SH.toast(msg); }
	dlg.addEventListener('click', function (ev) {
		var t = ev.target;
		if (t.closest('[data-acc-cancel]')) { dlg.close(); return; }
		var tb = t.closest('[data-tab]'); if (tb && signedIn()) { tab = tb.dataset.tab; viewAccount(true); var again = $('#acc-tab-' + tab, dlg); if (again) { again.focus(); } return; }
		if (t.closest('[data-acc-link]')) { viewLogin('link'); return; }
		var ad = t.closest('[data-addr-del]'); if (ad) { var af = ad.closest('form'); saveAddr({ street: '', zip: '', city: '', note: '', name: af.name.value, phone: af.phone.value }); return; }
		var ro = t.closest('[data-reorder]');
		if (ro) {
			ro.disabled = true;
			api('reorder', { order_id: +ro.dataset.reorder }).then(function (r) {
				var card = ro.closest('.acc-order'), act = $('.acc-order-act', card);
				if (!r.ok) { ro.disabled = false; act.insertAdjacentHTML('beforebegin', '<p class="gb-err" role="alert">' + esc(r.error || NETERR) + '</p>'); return; }
				SH.addMany(r.lines);
				var notes = [];
				if (r.gone && r.gone.length) { notes.push('Nicht mehr auf der Karte: ' + r.gone.join(', ') + '.'); }
				if (r.changed && r.changed.length) { notes.push('Neuer Preis: ' + r.changed.join(', ') + '.'); }
				if (!notes.length) { dlg.close(); SH.openCart(); return; }
				act.innerHTML = '<p class="acc-note" role="status">Der Rest liegt im Warenkorb. ' + esc(notes.join(' ')) + '</p><button type="button" class="gb-btn solid" data-acc-tocart>Zum Warenkorb</button>';
			}).catch(function () { ro.disabled = false; SH.toast(NETERR); });
			return;
		}
		if (t.closest('[data-acc-tocart]')) { dlg.close(); SH.openCart(); return; }
		var fc = t.closest('[data-fav-cart]');
		if (fc) { var f1 = me.favs.filter(function (f) { return String(f.id) === fc.dataset.favCart && f.ok; })[0]; if (f1) { SH.addMany([f1.line]); SH.toast(f1.line.title + ' liegt im Warenkorb'); } return; }
		var fd = t.closest('[data-fav-del]');
		if (fd) { api('fav_remove', { id: +fd.dataset.favDel }).then(function (r) { if (r.ok) { me.favs = r.favs; renderFavRow(); markHearts(); viewAccount(true); } }).catch(function () { SH.toast(NETERR); }); return; }
		if (t.closest('[data-acc-logout-all]')) { api('logout_all').then(function () { signedOut('Du bist auf allen Geräten abgemeldet'); }).catch(function () { SH.toast(NETERR); }); return; }
		if (t.closest('[data-acc-logout]')) { api('logout').then(function () { signedOut('Du bist abgemeldet'); }).catch(function () { SH.toast(NETERR); }); return; }
		var del = t.closest('[data-acc-delete]');
		if (del) {
			if (!del.dataset.armed) { del.dataset.armed = '1'; del.textContent = 'Wirklich löschen? Konto und Favoriten sind dann weg.'; setTimeout(function () { del.dataset.armed = ''; del.textContent = 'Konto löschen'; }, 5000); return; }
			api('acc_delete').then(function () { signedOut('Dein Konto wurde gelöscht'); }).catch(function () { SH.toast(NETERR); });
		}
	});
	dlg.addEventListener('submit', function (ev) {
		var f = ev.target.closest && ev.target.closest('[data-addr-form]'); if (!f) { return; }
		ev.preventDefault(); saveAddr({ street: f.street.value, zip: f.zip.value, city: f.city.value, note: f.note.value, name: f.name.value, phone: f.phone.value });
	});
	// arrow keys move between the tabs
	dlg.addEventListener('keydown', function (ev) {
		var tb = ev.target.closest && ev.target.closest('[role="tab"]'); if (!tb || (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft')) { return; }
		var all = $$('[role="tab"]', dlg), i = all.indexOf(tb) + (ev.key === 'ArrowRight' ? 1 : -1);
		tab = all[(i + all.length) % all.length].dataset.tab; viewAccount(true); var n = $('#acc-tab-' + tab, dlg); if (n) { n.focus(); } ev.preventDefault();
	});

	// ---- page events
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		if (btn && t.closest('#acc-btn')) { if (signedIn()) { viewAccount(); } else { viewLogin('login'); } openDlg(); return; }
		if (t.closest('[data-acc-open]')) { viewLogin('login'); openDlg(); return; }
		var h = t.closest('.fav-btn[data-fav-i]');
		if (h) { var l = SH.cart()[+h.dataset.favI]; if (l) { toggleFav(l); } return; }
		var hp = t.closest('.fav-btn[data-fav-pid]');
		if (hp) { toggleFav({ pid: +hp.dataset.favPid, vid: 0, opts: {}, note: '' }); return; }
		var chip = t.closest('[data-favchip]');
		if (chip) { var f = (me.favs || []).filter(function (x) { return String(x.id) === chip.dataset.favchip && x.ok; })[0]; if (f) { SH.addMany([f.line]); SH.toast(f.line.title + ' liegt im Warenkorb'); } }
	});
	// quiet note in the cart: what the stamp card would have given for this cart, shown only to a guest who is not signed in
	function stampHint() {
		var el = $('[data-stamp-hint]'); if (!el) { return; }
		var pc = +body.dataset.stamp || 0, lost = Math.round(SH.subtotal() * pc / 100);
		if (signedIn() && me.account) {
			var st = me.stamp, nm = (me.contact && me.contact.name) ? me.contact.name : (me.account.mask_mail || me.account.mask_phone || 'deinem Konto');
			el.innerHTML = '<span class="cart-acc-dot" aria-hidden="true"></span>Angemeldet als <strong>' + esc(nm) + '</strong>' + (st && st.goal ? ' · ' + (st.count || 0) + ' von ' + st.goal + ' Stempeln' : ''); el.hidden = false; return;
		}
		if (signedIn() || !me || pc <= 0 || lost <= 0) { el.hidden = true; return; }
		el.innerHTML = 'Ohne Kundenkonto entgehen dir bei dieser Bestellung ca. <strong>' + SH.fmt(lost) + '</strong> Stempel-Guthaben. <button type="button" class="cart-stamp-btn" data-acc-open>Jetzt anmelden</button>';
		el.hidden = false;
	}
	document.addEventListener('amadeus:cart', markHearts);
	document.addEventListener('amadeus:cart', stampHint);

	injectMenuHearts();
	refresh().then(function () {
		var q = ''; try { q = new URLSearchParams(location.search).get('konto') || ''; } catch (e) {}
		if (!q) { return; }
		try { history.replaceState(null, '', location.pathname + location.search.replace(/([?&])konto=1&?/, '$1').replace(/[?&]$/, '') + location.hash); } catch (e) {}
		if (me && me.ok && signedIn() && NEXT) { location.href = NEXT; return; }
		if (me && me.ok) { if (signedIn()) { viewAccount(); } else { viewLogin('login'); } openDlg(); }
	});
})();
