/* Order page: mode (delivery / pickup), category highlight, product dialog, cart. The cart is a list of choices kept in
   the browser; the server recomputes every price when the order is placed. */
(function () {
	'use strict';
	var body = document.body, ACCEPT = body.dataset.accepting === '1', KEY = 'amadeusCartV2', TOKEN = body.dataset.token, ACCOUNT = body.dataset.account === '1';
	var HEART = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 21s-7-4.35-9.33-8.9C1.07 8.9 3.2 5 6.9 5c2 0 3.6 1.1 5.1 3.1C13.5 6.1 15.1 5 17.1 5c3.7 0 5.83 3.9 4.23 7.1C19 16.65 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>';
	var state = { mode: 'delivery', cart: [], info: null, zone: null, group: null, gv: null, noteEdit: null, noteDraft: '', noteFocus: false };

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function toast(t) { var el = $('#shop-toast'); el.textContent = t; el.classList.add('is-on'); clearTimeout(toast.t); toast.t = setTimeout(function () { el.classList.remove('is-on'); }, 1800); }

	// ---- storage
	function load() {
		try { var d = JSON.parse(localStorage.getItem(KEY) || '{}'); if (d && Array.isArray(d.cart)) { state.cart = d.cart; } if (d && (d.mode === 'pickup' || d.mode === 'delivery')) { state.mode = d.mode; } } catch (e) {}
	}
	function save() { try { localStorage.setItem(KEY, JSON.stringify({ mode: state.mode, cart: state.cart })); } catch (e) {} }

	// ---- opening state text under the mode switch
	function renderStatus() {
		var el = $('#shop-status'); if (!el || !state.info) { return; }
		var s = state.info[state.mode], label = state.mode === 'delivery' ? 'Lieferung' : 'Abholung';
		el.classList.toggle('is-open', s.open); el.classList.toggle('is-closed', !s.open);
		if (s.open) { var mo = state.mode === 'delivery' ? state.info.min_delivery : state.info.min_pickup; el.textContent = label + ' offen bis ' + s.until + ' Uhr · in etwa ' + s.lead + ' Min' + (mo > 0 ? ' · ab ' + fmt(mo) : ''); }
		else if (s.paused) { el.textContent = label + ' gerade pausiert' + (s.paused_until ? ' bis etwa ' + s.paused_until + ' Uhr' : ''); }
		else if (s.note) { el.textContent = label + ' heute nicht möglich (' + s.note + ')' + (s.next ? ', wieder ' + s.next : ''); }
		else { el.textContent = s.next ? label + ' wieder ' + s.next : label + ' zurzeit nicht möglich'; }
	}
	function loadState() {
		fetch('api.php?op=state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.ok) { state.info = r; renderStatus(); renderCart(); } }).catch(function () {});
	}
	function setMode(m) {
		state.mode = m; save();
		$$('.mode-btn').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === m ? 'true' : 'false'); });
		var zone = $('#shop-zone'); if (zone) { zone.hidden = m !== 'delivery'; }
		renderStatus(); renderCart();
	}

	// ---- delivery-zone quick check on the menu page: same 'zone' endpoint checkout.php uses, so a guest can learn
	// before building a cart whether delivery is possible, instead of finding out only at checkout
	var GUEST = 'amadeusGuestV1';
	function saveGuestAddress(a) {
		try {
			var d = JSON.parse(localStorage.getItem(GUEST) || '{}'); if (!d || typeof d !== 'object') { d = {}; }
			d.street = a.street; d.zip = a.zip; d.city = a.city;
			localStorage.setItem(GUEST, JSON.stringify(d));
		} catch (e) {}
	}
	function setDeliveryLocked(locked) {
		var btn = $('.mode-btn[data-mode="delivery"]');
		if (btn) { btn.disabled = locked; btn.classList.toggle('is-locked', locked); }
		if (locked && state.mode === 'delivery') { setMode('pickup'); }
	}
	function renderZoneResult(cls, text) {
		var el = $('#shop-zone-result'); if (!el) { return; }
		el.className = 'shop-zone-result' + (cls ? ' ' + cls : '');
		el.textContent = text;
	}
	function closeShopZone() {
		var box = $('#shop-zone-box'), toggle = $('#shop-zone-toggle'), backdrop = $('#shop-zone-backdrop');
		if (box) { box.hidden = true; }
		if (backdrop) { backdrop.hidden = true; }
		if (toggle) { toggle.setAttribute('aria-expanded', 'false'); }
	}
	// two or more real, deliverable streets matched the same typed text - the guest picks instead of the
	// app silently guessing between them (see shop_find_zone()'s 'ambiguous' reason)
	function renderCandidates(list) {
		var box = $('#sz-candidates'); if (!box) { return; }
		if (!list || !list.length) { box.hidden = true; box.innerHTML = ''; return; }
		box.innerHTML = list.map(function (c, i) {
			return '<button type="button" class="co-candidate" data-i="' + i + '"><strong>' + esc(c.road) + '</strong><small>' + esc(c.postcode) + ' ' + esc(c.city) + '</small></button>';
		}).join('');
		box.hidden = false;
		$$('.co-candidate', box).forEach(function (btn, i) {
			btn.addEventListener('click', function () {
				var c = list[i], s = $('#sz-street'), z = $('#sz-zip'), cty = $('#sz-city');
				if (!s || !z) { return; }
				z.value = c.postcode;
				zoneKey = s.value.trim() + '|' + z.value.trim() + '|' + cty.value.trim();
				state.zone = c.zone;
				renderZoneResult('ok', '✓ Wir liefern zu dir · ' + fmt(c.zone.fee) + ' Liefergebühr, ab ' + fmt(c.zone.min));
				setDeliveryLocked(false); setW3wVisible(false);
				box.hidden = true; box.innerHTML = '';
				saveGuestAddress({ street: s.value.trim(), zip: z.value.trim(), city: cty.value.trim() });
				renderCart();
			});
		});
	}
	// the what3words escape hatch only appears once a typed address is not found (not when it is
	// simply outside the delivery area - a code will not help there either)
	function setW3wVisible(show) {
		var t = $('#sz-w3w-toggle'), b = $('#sz-w3w-box');
		if (t) { t.hidden = !show; }
		if (!show && b) { b.hidden = true; }
	}
	var zoneTimer, zoneKey = '';
	function checkShopZone() {
		clearTimeout(zoneTimer);
		var s = $('#sz-street'), z = $('#sz-zip'), c = $('#sz-city'); if (!s || !z || !c) { return; }
		var street = s.value.trim(), zip = z.value.trim(), city = c.value.trim(), key = street + '|' + zip + '|' + city;
		// a house number is required before asking at all - a street name alone ("Goschentor") is too vague a
		// query and Nominatim/Google may fuzzy-match it to a different, wrong real street while still typing
		if (!street || !city || !/\d/.test(street)) { renderZoneResult('', ''); setDeliveryLocked(false); setW3wVisible(false); renderCandidates(null); state.zone = null; renderCart(); return; }
		if (key === zoneKey) { return; }
		renderZoneResult('', 'Adresse wird geprüft ...');
		renderCandidates(null);
		zoneTimer = setTimeout(function () {
			fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'zone', token: TOKEN, street: street, zip: zip, city: city }) })
				.then(function (r) { return r.json(); }).then(function (r) {
					zoneKey = key;
					// the result shows next to the toggle regardless of the box's own open/closed state - the box
					// itself is left open here on purpose (see initShopZone): closing it on every resolved check
					// used to snap it shut mid-keystroke on mobile as soon as all three fields held some value
					if (r.ok) {
						// the PLZ comes straight from the same lookup that just confirmed the address - it always
						// takes over here (even a wrong one already in the field, e.g. from browser autofill),
						// since a confirmed match knows better. The street itself is never rewritten: a guest
						// should never see their own typed text silently replaced by something else
						if (r.postcode) { z.value = r.postcode; }
						state.zone = r.zone; renderZoneResult('ok', '✓ Wir liefern zu dir · ' + fmt(r.zone.fee) + ' Liefergebühr, ab ' + fmt(r.zone.min)); setDeliveryLocked(false); setW3wVisible(false);
					} else if (r.reason === 'ambiguous') {
						state.zone = null; renderZoneResult('', 'Mehrere passende Adressen - bitte auswählen:'); setDeliveryLocked(true); setW3wVisible(false); renderCandidates(r.candidates);
					} else {
						state.zone = null; renderZoneResult('', ''); setDeliveryLocked(true); toast(r.error); setW3wVisible(r.reason === 'not_found' && r.w3w_available);
					}
					saveGuestAddress({ street: street, zip: z.value.trim(), city: city });
					renderCart();
				}).catch(function () { renderZoneResult('bad', 'Adresse konnte nicht geprüft werden'); });
		}, 500);
	}
	// same idea as checkShopZone(), for a what3words code instead of street/zip/city - purely
	// informational here (this widget never places an order), checkout.js re-checks independently
	var w3wTimer, w3wKey = '';
	function checkShopZoneW3W() {
		clearTimeout(w3wTimer);
		var w = $('#sz-w3w'); if (!w) { return; }
		var words = w.value.trim();
		if (!words) { renderZoneResult('', ''); setDeliveryLocked(false); state.zone = null; renderCart(); return; }
		if (words === w3wKey) { return; }
		renderZoneResult('', 'Code wird geprüft ...');
		w3wTimer = setTimeout(function () {
			fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'zone', token: TOKEN, words: words }) })
				.then(function (r) { return r.json(); }).then(function (r) {
					w3wKey = words;
					if (r.ok) { state.zone = r.zone; renderZoneResult('ok', '✓ Wir liefern zu dir · ' + fmt(r.zone.fee) + ' Liefergebühr, ab ' + fmt(r.zone.min)); setDeliveryLocked(false); }
					else { state.zone = null; renderZoneResult('', ''); setDeliveryLocked(true); toast(r.error); }
					renderCart();
				}).catch(function () { renderZoneResult('bad', 'Code konnte nicht geprüft werden'); });
		}, 500);
	}
	function initShopZone() {
		var zone = $('#shop-zone'), toggle = $('#shop-zone-toggle'), box = $('#shop-zone-box'), backdrop = $('#shop-zone-backdrop'); if (!zone || !toggle || !box) { return; }
		toggle.addEventListener('click', function () {
			var open = box.hidden;
			box.hidden = !open; toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (backdrop) { backdrop.hidden = !open; }
			if (open) { $('#sz-street').focus(); }
		});
		box.addEventListener('input', function (ev) {
			if (/^(sz-street|sz-zip|sz-city)$/.test(ev.target.id)) { checkShopZone(); }
			if (ev.target.id === 'sz-w3w') { checkShopZoneW3W(); }
		});
		var w3wToggle = $('#sz-w3w-toggle'), w3wBox = $('#sz-w3w-box');
		if (w3wToggle && w3wBox) { w3wToggle.addEventListener('click', function () { w3wBox.hidden = false; $('#sz-w3w').focus(); }); }
		document.addEventListener('click', function (ev) { if (!box.hidden && !zone.contains(ev.target)) { closeShopZone(); } });
		document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !box.hidden) { closeShopZone(); toggle.focus(); } });
	}

	// ---- categories: highlight the one in view
	// ---- search: filters the dishes already on the page by name and description, no server round trip.
	// Small typos are ignored: each search word only has to be close to some word of the dish (substring or a short edit distance).
	function norm(s) { return String(s || '').toLowerCase(); }
	function wordsOf(s) { return norm(s).replace(/[^a-z0-9äöüß]+/g, ' ').trim().split(/\s+/).filter(Boolean); }
	// edit distance that counts a swap of two neighbouring letters ("lahcs" for "lachs") as one mistake, not two, since that is
	// the single most common typo; otherwise the usual insert/delete/substitute
	function editDistance(a, b) {
		if (a === b) { return 0; }
		var al = a.length, bl = b.length; if (!al) { return bl; } if (!bl) { return al; }
		var d = []; var i, j;
		for (i = 0; i <= al; i++) { d[i] = [i]; }
		for (j = 0; j <= bl; j++) { d[0][j] = j; }
		for (i = 1; i <= al; i++) {
			for (j = 1; j <= bl; j++) {
				var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
				d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
				if (i > 1 && j > 1 && a.charAt(i - 1) === b.charAt(j - 2) && a.charAt(i - 2) === b.charAt(j - 1)) { d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1); }
			}
		}
		return d[al][bl];
	}
	function typoLimit(n) { return n <= 7 ? 1 : (n <= 12 ? 2 : 3); }
	// a mistyped word may sit inside a longer German compound ("Sambalschnitzel" for "schnitzel"): slide the shorter word
	// across the longer one and allow a typo in that window, instead of comparing the whole (very differently long) words
	function fuzzyInside(q, w) {
		// only the start or the end of the compound, e.g. "lachs" in "Lachsfilet", "schnitzel" in "Jägerschnitzel", and only
		// a single-letter typo: wider windows or more tolerance turn up unrelated coincidences from other words on the menu
		for (var len = q.length - 1; len <= q.length + 1; len++) {
			if (len < 1 || len > w.length) { continue; }
			if (editDistance(q, w.substr(0, len)) <= 1 || editDistance(q, w.substr(w.length - len, len)) <= 1) { return true; }
		}
		return false;
	}
	function wordMatches(q, w) {
		// one contains the other: partial typing, plurals, compound words - but only once the shorter side has real substance,
		// else short words like "la" or "1" would match almost anything
		if (w.length >= 3 && q.indexOf(w) >= 0) { return true; }
		if (q.length >= 3 && w.indexOf(q) >= 0) { return true; }
		var maxLen = Math.max(q.length, w.length);
		if (maxLen < 4) { return false; } // too short to guess a typo safely
		if (editDistance(q, w) <= typoLimit(maxLen)) { return true; }
		if (q.length >= 5 && w.length - q.length >= 3 && w.length - q.length <= 14) { return fuzzyInside(q, w); }
		return false;
	}
	function textMatchesQuery(text, qWords) {
		var words = wordsOf(text);
		return qWords.every(function (q) { return words.some(function (w) { return wordMatches(q, w); }); });
	}
	var searchIn = $('#shop-search'), searchClear = $('#shop-search-clear'), searchEmpty = $('#shop-search-empty');
	function applySearch(q) {
		var qWords = wordsOf(q), any = false;
		$$('.shop-cat').forEach(function (sec) {
			var hits = 0;
			$$('.shop-item', sec).forEach(function (li) {
				var desc = $('.shop-item-text p', li), text = li.dataset.title + ' ' + (desc ? desc.textContent : '');
				var show = !qWords.length || textMatchesQuery(text, qWords);
				li.hidden = !show; if (show) { hits++; any = true; }
			});
			sec.hidden = qWords.length > 0 && hits === 0;
		});
		if (searchEmpty) { searchEmpty.hidden = !(qWords.length && !any); }
		$$('#shop-cats').forEach(function (nav) { nav.classList.toggle('is-filtered', qWords.length > 0); });
	}
	function initSearch() {
		if (!searchIn) { return; }
		var searchTimer;
		searchIn.addEventListener('input', function () {
			if (searchClear) { searchClear.hidden = !this.value; }
			var v = this.value; clearTimeout(searchTimer); searchTimer = setTimeout(function () { applySearch(v); }, 60);
		});
		searchIn.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && this.value) { this.value = ''; if (searchClear) { searchClear.hidden = true; } applySearch(''); } });
		if (searchClear) { searchClear.addEventListener('click', function () { searchIn.value = ''; searchClear.hidden = true; applySearch(''); searchIn.focus(); }); }
	}

	function watchCategories() {
		var links = $$('#shop-cats a'); if (!links.length || !('IntersectionObserver' in window)) { return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (!e.isIntersecting) { return; }
				links.forEach(function (a) { var on = a.dataset.cat === e.target.id.replace('cat-', ''); a.classList.toggle('is-active', on); if (on && window.innerWidth < 1024) { a.scrollIntoView({ inline: 'center', block: 'nearest' }); } });
			});
		}, { rootMargin: '-130px 0px -70% 0px' });
		$$('.shop-cat').forEach(function (s) { io.observe(s); });
	}

	// ---- cart
	function lineKey(l) { return [l.pid, l.vid, Object.keys(l.opts).sort().map(function (k) { return k + ':' + l.opts[k]; }).join(','), l.note].join('|'); }
	function addLine(l) {
		if (state.group) { gAdd(l); return; }
		var k = lineKey(l), hit = state.cart.filter(function (x) { return lineKey(x) === k; })[0];
		if (hit) { hit.qty = Math.min(50, hit.qty + l.qty); } else { state.cart.push(l); }
		save(); renderCart(); toast('Zum Warenkorb hinzugefügt');
	}
	// a changed line may now equal another one: they become one line with the sum of the amounts
	function replaceLine(i, l) {
		state.cart[i] = l;
		var k = lineKey(l);
		for (var j = state.cart.length - 1; j >= 0; j--) { if (j !== i && lineKey(state.cart[j]) === k) { l.qty = Math.min(50, l.qty + state.cart[j].qty); state.cart.splice(j, 1); if (j < i) { i--; } } }
		save(); renderCart(); toast('Änderung gespeichert');
	}
	// in a shared basket the lines come from the server (everybody's), otherwise from this browser
	function cartLines() {
		if (!state.group) { return state.cart; }
		var out = [];
		if (state.gv && state.gv.members) { state.gv.members.forEach(function (m) { m.lines.forEach(function (l) { if (!l.unavailable) { out.push(l); } }); }); }
		return out;
	}
	function subtotal() { return cartLines().reduce(function (s, l) { return s + l.unit * l.qty; }, 0); }
	function count() { return cartLines().reduce(function (s, l) { return s + l.qty; }, 0); }
	function minOrder() { if (!state.info) { return 0; } return state.mode === 'delivery' ? (state.zone ? state.zone.min : state.info.min_delivery) : state.info.min_pickup; }

	var ICON_TRASH = '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l.7 10h6.6L14 6M8.5 9v4.5M11.5 9v4.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	// "Noch etwas dazu?": learned from past orders (what is bought together with what is already in the cart), falling
	// back to a simple guess (drinks, sides, desserts, dips) until enough orders have happened or while the guess loads
	var upsellCache = { sig: '', items: [], learned: false };
	function cartSig() { return state.cart.map(function (l) { return l.pid; }).sort(function (a, b) { return a - b; }).join(','); }
	function refreshUpsell() {
		var sig = cartSig(); if (sig === upsellCache.sig || !TOKEN) { return; }
		fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'upsell', token: TOKEN, lines: state.cart.map(function (l) { return { pid: l.pid }; }) }) })
			.then(function (r) { return r.json(); }).then(function (r) { var learned = r.ok && r.learned; upsellCache = { sig: sig, items: learned ? r.items : [], learned: learned }; renderCart(); }).catch(function () { upsellCache = { sig: sig, items: [], learned: false }; });
	}
	function guessUpsell() {
		var inCart = {}; state.cart.forEach(function (l) { inCart[l.pid] = true; });
		var out = [];
		$$('.shop-cat').forEach(function (sec) {
			if (out.length >= 3 || !/getr(ä|ae)nk|drink|dessert|nachspeise|nachtisch|beilage|dip|so(ß|ss)e|eis\b/i.test($('h2', sec).textContent)) { return; }
			var li = $$('.shop-item', sec).filter(function (x) { return !inCart[x.dataset.id]; }).sort(function (a, b) { return (a.dataset.choices === '1') - (b.dataset.choices === '1'); })[0];
			if (li) { out.push(li); }
		});
		return out;
	}
	function upsell() {
		if (upsellCache.items.length) { return upsellCache.items.map(function (it) { return $('.shop-item[data-id="' + it.id + '"]'); }).filter(Boolean); }
		return guessUpsell();
	}
	// a note for the kitchen on any cart line (dishes without choices have no product dialog, so this is their only place for it)
	function noteControl(l, i) {
		if (state.noteEdit === i) {
			return '<div class="cart-noteform pd-note"><label class="pd-note-label" for="cn-' + i + '">Hinweis für die Küche</label><textarea id="cn-' + i + '" data-cn maxlength="200" placeholder="zum Beispiel ohne Zwiebeln">' + esc(state.noteDraft) + '</textarea>' +
				'<div class="cart-noteact"><button type="button" class="gb-btn solid" data-note-save="' + i + '">Speichern</button><button type="button" class="gb-btn ghost" data-note-cancel>Abbrechen</button></div></div>';
		}
		return '<button type="button" class="cart-note-btn" data-note="' + i + '">' + (l.note ? 'Hinweis ändern' : 'Hinweis für die Küche hinzufügen') + '</button>';
	}
	function renderCart() {
		if (state.noteEdit !== null && !state.cart[state.noteEdit]) { state.noteEdit = null; }
		var box = $('#cart-body'); if (!box) { return; }
		var n = count(), sub = subtotal(), min = minOrder(), below = min > 0 && sub < min;
		var bar = $('#cartbar'); if (bar) { bar.hidden = n === 0 && !state.group; $('#cartbar-count').textContent = n; $('#cartbar-total').textContent = fmt(sub); $('.cartbar-label', bar).textContent = state.group ? 'Gemeinsamer Warenkorb' : (below ? 'Noch ' + fmt(min - sub) + ' bis zum Mindestbestellwert' : 'Warenkorb ansehen'); }
		var gtop = $('#gb-top'); if (gtop) { gtop.hidden = !!state.group; }
		// what the guest already has, right in the menu
		var per = {}; cartLines().forEach(function (l) { per[l.pid] = (per[l.pid] || 0) + l.qty; });
		$$('.shop-item').forEach(function (li) {
			var m = $('.shop-inbag', li), q = per[li.dataset.id] || 0;
			if (q && !m) { m = document.createElement('span'); m.className = 'shop-inbag'; $('.shop-price', li).after(m); }
			if (m) { m.textContent = q ? q + '× im Warenkorb' : ''; m.hidden = !q; }
		});
		if (state.group) { renderGroupCart(box, sub, min, below); return; }
		if (!n) { box.innerHTML = '<p class="cart-empty">Dein Warenkorb ist noch leer.<br/>Such dir etwas Feines aus, wir kochen es frisch für dich.</p>' + groupInvite(); return; }
		var s0 = state.info ? state.info[state.mode] : null, h = '';
		if (s0) { h += '<p class="cart-eta">' + (state.mode === 'delivery' ? 'Lieferung' : 'Abholung') + (s0.open ? ' in etwa ' + s0.lead + ' Minuten' : (s0.paused ? ' gerade pausiert' + (s0.paused_until ? ' bis etwa ' + s0.paused_until + ' Uhr' : '') : ' zurzeit nicht möglich' + (s0.next ? ', ' + s0.next : ''))) + '</p>'; }
		if (min > 0) {
			h += '<div class="cart-goal' + (below ? '' : ' is-met') + '"><div class="cart-goal-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' + min + '" aria-valuenow="' + Math.min(sub, min) + '"><span style="--p:' + Math.min(1, sub / min).toFixed(3) + '"></span></div><p>' +
				(below ? 'Noch <strong>' + fmt(min - sub) + '</strong> bis zum Mindestbestellwert von ' + fmt(min) : 'Mindestbestellwert erreicht') + '</p></div>';
		}
		h += state.cart.map(function (l, i) {
			return '<div class="cart-line"><h3>' + esc(l.title) + (l.vtitle ? ' <span class="cart-opts">(' + esc(l.vtitle) + ')</span>' : '') + '</h3><span class="cart-lineprice">' + fmt(l.unit * l.qty) + '</span>' +
				(l.optText ? '<p class="cart-opts">' + esc(l.optText) + '</p>' : '') + (l.note ? '<p class="cart-opts">Hinweis: ' + esc(l.note) + '</p>' : '') + noteControl(l, i) +
				'<div class="cart-qty"><button type="button" class="qty-btn' + (l.qty === 1 ? ' is-trash' : '') + '" data-i="' + i + '" data-d="-1" aria-label="' + (l.qty === 1 ? 'Entfernen' : 'Weniger') + '">' + (l.qty === 1 ? ICON_TRASH : '&minus;') + '</button><span class="qty-num" aria-live="polite">' + l.qty + '</span><button type="button" class="qty-btn" data-i="' + i + '" data-d="1" aria-label="Mehr">+</button>' + (ACCOUNT ? '<button type="button" class="fav-btn" data-fav-i="' + i + '" aria-pressed="false" aria-label="' + esc(l.title) + ' als Favorit merken">' + HEART + '</button>' : '') + (l.ch ? '<button type="button" class="cart-edit" data-edit="' + i + '">Ändern</button>' : '') + '</div></div>';
		}).join('');
		refreshUpsell();
		var ups = upsell();
		if (ups.length) {
			h += '<div class="cart-up"><h3>Noch etwas dazu?</h3><div class="cart-up-list">' + ups.map(function (li) {
				return '<button type="button" class="cart-up-item" data-up="' + li.dataset.id + '"><span>' + esc(li.dataset.title) + '</span><small>' + (li.dataset.choices === '1' ? 'auswählen' : '+ ' + fmt(+li.dataset.price)) + '</small></button>';
			}).join('') + '</div></div>';
		}
		h += '<div class="cart-sum"><div class="cart-row"><span>Zwischensumme</span><span>' + fmt(sub) + '</span></div>' +
			(state.mode === 'delivery' ? '<div class="cart-row muted"><span>Liefergebühr</span><span>' + (state.zone ? fmt(state.zone.fee) : 'nach Adresse') + '</span></div>' : '') + '</div>';
		if (ACCOUNT && +body.dataset.stamp > 0) { h += '<p class="cart-stamp" data-stamp-hint hidden></p>'; }
		h += '<a class="cart-go" href="checkout.php"' + (below ? ' aria-disabled="true" tabindex="-1"' : '') + '>' + (below ? 'Noch ' + fmt(min - sub) + ' bis zur Kasse' : 'Zur Kasse · ' + fmt(sub)) + '</a>' +
			'<p class="cart-trust">Frisch für dich zubereitet. Bezahlen kannst du online, bar oder mit Karte.</p>' +
			'<button type="button" class="cart-clear" data-clear>Warenkorb leeren</button>' + groupInvite();
		box.innerHTML = h;
		if (state.noteEdit !== null && state.noteFocus) { var cn = $('[data-cn]', box); if (cn) { cn.focus(); cn.setSelectionRange(cn.value.length, cn.value.length); } state.noteFocus = false; }
		document.dispatchEvent(new CustomEvent('amadeus:cart'));
	}
	// ---- shared basket ("Gemeinsam bestellen"): everybody fills one basket on the server, one person (the organizer) orders and pays.
	// This browser only keeps who it is in that basket (token + secret member id); lines, names and prices come from the server.
	var GKEY = 'amadeusBasketV1', gSeq = 0, gApplied = 0, gRev = '';
	function gLoad() {
		try { var d = JSON.parse(localStorage.getItem(GKEY) || 'null'); if (d && /^[a-f0-9]{24}$/.test(d.token) && /^[a-f0-9]{32}$/.test(d.me)) { state.group = d; } } catch (e) {}
	}
	function gSave() { try { if (state.group) { localStorage.setItem(GKEY, JSON.stringify(state.group)); } else { localStorage.removeItem(GKEY); } } catch (e) {} }
	function gPost(op, data) {
		data = data || {}; data.op = op; data.token = TOKEN;
		if (state.group) { if (data.g === undefined) { data.g = state.group.token; } if (data.me === undefined) { data.me = state.group.me; } }
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
	}
	// the short link (YOURLS) once there is one, else the long one
	function gLink() { return (state.gv && state.gv.short) || (location.href.split('#')[0].split('?')[0] + '?g=' + state.group.token); }
	var gLinkAsked = '';
	function gAskShort() {
		if (!state.group || (state.gv && state.gv.short) || gLinkAsked === state.group.token) { return; }
		gLinkAsked = state.group.token;
		gPost('basket_link').then(function (r) { if (r.ok && r.link && state.gv) { state.gv.short = r.link; renderCart(); } }).catch(function () {});
	}
	// the newest answer wins; "same" means nothing changed since the revision this page already shows
	function gRefresh(force) {
		if (!state.group) { return Promise.resolve(); }
		var seq = ++gSeq;
		return gPost('basket_view', { rev: force ? '' : gRev }).then(function (r) {
			if (!state.group || seq < gApplied) { return; }
			gApplied = seq;
			if (r.gone) { gEnd('Die gemeinsame Bestellung ist beendet.'); return; }
			if (!r.ok) { return; }
			if (!r.joined) { gEnd('Du bist bei dieser gemeinsamen Bestellung nicht mehr dabei.'); return; }
			if (r.same) { return; }
			gRev = r.rev; state.gv = r; renderCart(); gAskShort();
		}).catch(function () {});
	}
	function gEnd(msg) { state.group = null; state.gv = null; gRev = ''; gSave(); if (msg) { toast(msg); } renderCart(); }
	function gAdd(l) {
		gPost('basket_add', { line: { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note } }).then(function (r) {
			toast(r.ok ? 'Zum gemeinsamen Warenkorb hinzugefügt' : (r.error || 'Das hat nicht geklappt.'));
			return gRefresh(true);
		}).catch(function () { toast('Das hat nicht geklappt.'); });
	}
	function gStart(name) {
		return gPost('basket_create', { name: name }).then(function (r) {
			if (!r.ok) { throw new Error(r.error); }
			state.group = { token: r.token, me: r.me, name: r.name }; gSave(); gRev = '';
			// what this guest already had in the own cart moves into the shared one
			var mine = state.cart.slice();
			return mine.reduce(function (p, l) { return p.then(function () { return gPost('basket_add', { line: { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note } }); }); }, Promise.resolve())
				.then(function () { state.cart = []; save(); return gRefresh(true); });
		});
	}
	function gJoin(token, name) {
		return gPost('basket_join', { g: token, name: name }).then(function (r) {
			if (!r.ok) { throw new Error(r.error); }
			state.group = { token: token, me: r.me, name: name }; gSave(); gRev = ''; return gRefresh(true);
		});
	}
	function gShare() {
		var url = gLink();
		if (navigator.share) { navigator.share({ title: 'Gemeinsam bestellen', text: 'Such dir etwas aus, wir bestellen zusammen:', url: url }).catch(function () {}); return; }
		var sel = function () { var i = $('[data-gb-link]'); if (i) { i.focus(); i.select(); } toast('Link markiert, bitte kopieren'); };
		if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(function () { toast('Link kopiert'); }, sel); } else { sel(); }
	}
	function gDialog(mode, token, ownerName) {
		var d = $('#group-dialog'); if (!d) { return; }
		var join = mode === 'join', saved = '';
		try { saved = String(JSON.parse(localStorage.getItem(GUEST) || '{}').name || '').split(' ')[0]; } catch (e) {}
		d.innerHTML = '<form class="gb-form" novalidate><h2>Gemeinsam bestellen</h2><p>' + (join
			? esc(ownerName) + ' hat dich zu einer gemeinsamen Bestellung eingeladen. Such dir dein Essen aus, ' + esc(ownerName) + ' schickt am Ende alles zusammen ab und bezahlt.'
			: 'Du bekommst einen Link für die anderen. Jede Person sucht sich ihre Sachen selbst aus. Am Ende schickst du alles zusammen ab und bezahlst.') +
			'</p><label class="co-f"><span>Dein Name (die anderen sehen ihn)</span><input type="text" name="n" maxlength="24" autocomplete="given-name" value="' + esc(saved) + '"/></label>' +
			'<p class="gb-err" role="alert"></p><div class="gb-actions"><button type="button" class="gb-btn ghost" data-gb-cancel>Abbrechen</button><button type="submit" class="gb-btn solid">' + (join ? 'Mitmachen' : 'Los geht’s') + '</button></div></form>';
		var f = $('form', d), err = $('.gb-err', d), busy = false;
		f.onsubmit = function (ev) {
			ev.preventDefault(); if (busy) { return; }
			var nm = f.n.value.trim(); if (!nm) { err.textContent = 'Bitte gib deinen Namen an.'; f.n.focus(); return; }
			busy = true; err.textContent = '';
			(join ? gJoin(token, nm) : gStart(nm)).then(function () { d.close(); cartOpen(true); }).catch(function (e) { busy = false; err.textContent = (e && e.message) || 'Das hat nicht geklappt.'; });
		};
		$('[data-gb-cancel]', d).onclick = function () { d.close(); };
		if (typeof d.showModal === 'function') { d.showModal(); } else { d.setAttribute('open', ''); }
		f.n.focus();
	}
	function groupInvite() {
		if (!ACCEPT) { return ''; }
		return '<div class="gb-invite"><h3>Zu mehreren bestellen?</h3><p>Ihr füllt einen gemeinsamen Warenkorb, jede Person sucht sich ihre Sachen selbst aus. Eine Person schickt ab und bezahlt.</p><button type="button" class="gb-btn" data-gb-new>Gemeinsam bestellen</button></div>';
	}
	function gLine(l) {
		return '<div class="cart-line"><h3>' + esc(l.title) + (l.vtitle ? ' <span class="cart-opts">(' + esc(l.vtitle) + ')</span>' : '') + '</h3><span class="cart-lineprice">' + (l.unavailable ? '' : fmt(l.unit * l.qty)) + '</span>' +
			(l.optText ? '<p class="cart-opts">' + esc(l.optText) + '</p>' : '') + (l.note ? '<p class="cart-opts">Hinweis: ' + esc(l.note) + '</p>' : '') +
			(l.can
				? '<div class="cart-qty"><button type="button" class="qty-btn' + (l.qty === 1 ? ' is-trash' : '') + '" data-gid="' + l.id + '" data-q="' + (l.qty - 1) + '" aria-label="' + (l.qty === 1 ? 'Entfernen' : 'Weniger') + '">' + (l.qty === 1 ? ICON_TRASH : '&minus;') + '</button><span class="qty-num" aria-live="polite">' + l.qty + '</span>' +
					(l.unavailable ? '' : '<button type="button" class="qty-btn" data-gid="' + l.id + '" data-q="' + (l.qty + 1) + '" aria-label="Mehr">+</button>') + '</div>'
				: (l.qty > 1 ? '<p class="cart-opts">Menge: ' + l.qty + '</p>' : '')) + '</div>';
	}
	function renderGroupCart(box, sub, min, below) {
		var v = state.gv;
		if (!v) { box.innerHTML = '<p class="cart-empty">Der gemeinsame Warenkorb wird geladen ...</p>'; return; }
		var done = v.status === 'ordered', locked = v.status === 'locked', np = v.members.length;
		var h = '<div class="gb-head"><p class="gb-kicker">Gemeinsame Bestellung</p><p class="gb-who">' + (v.owner ? 'Du bestellst und bezahlst. ' : esc(v.owner_name) + ' bestellt und bezahlt. ') + np + (np === 1 ? ' Person ist dabei.' : ' Personen sind dabei.') + '</p>';
		if (!done) { h += '<label class="gb-link"><span>Link zum Teilen</span><input type="text" readonly value="' + esc(gLink()) + '" data-gb-link/></label><button type="button" class="gb-btn" data-gb-share>Link teilen</button>'; }
		h += '</div>';
		if (done) { h += '<p class="gb-state is-done">Die Bestellung ist abgeschickt. Guten Appetit!</p>'; }
		else if (locked) { h += '<p class="gb-state">Der Warenkorb ist abgeschlossen. ' + (v.owner ? 'Du bist an der Kasse.' : esc(v.owner_name) + ' ist an der Kasse.') + '</p>'; }
		v.members.forEach(function (m) {
			h += '<section class="gb-member"><header><h3>' + esc(m.name) + (m.mine ? ' <small>(du)</small>' : '') + (m.owner && !m.mine ? ' <small>bezahlt</small>' : '') + '</h3><span>' + fmt(m.sum) + '</span></header>';
			h += m.lines.length ? m.lines.map(gLine).join('') : '<p class="gb-none">Noch nichts ausgewählt.</p>';
			h += '</section>';
		});
		h += '<div class="cart-sum"><div class="cart-row total"><span>Zusammen</span><span>' + fmt(sub) + '</span></div></div>';
		if (v.owner && !done) {
			var empty = count() === 0;
			h += '<button type="button" class="cart-go" data-gb-checkout' + (below || empty ? ' aria-disabled="true"' : '') + '>' + (empty ? 'Noch nichts im Warenkorb' : (below ? 'Noch ' + fmt(min - sub) + ' bis zur Kasse' : 'Abschließen und zur Kasse · ' + fmt(sub))) + '</button>';
			if (locked) { h += '<button type="button" class="gb-btn ghost" data-gb-reopen>Warenkorb wieder öffnen</button>'; }
		}
		if (!v.owner && !done) { h += '<p class="cart-trust">Du musst nichts weiter tun. ' + esc(v.owner_name) + ' schickt die Bestellung ab und bezahlt.</p>'; }
		if (done) { h += '<button type="button" class="gb-btn" data-gb-leave data-now>Fertig, neue Bestellung starten</button>'; }
		else if (v.owner) { h += '<button type="button" class="cart-clear" data-gb-end data-label="Gemeinsame Bestellung beenden">Gemeinsame Bestellung beenden</button>'; }
		else { h += '<button type="button" class="cart-clear" data-gb-leave data-label="Gemeinsame Bestellung verlassen">Gemeinsame Bestellung verlassen</button>'; }
		box.innerHTML = h;
	}
	// opened with an invitation link (?g=...): ask for a name first, or just carry on when this browser is already in
	function gInit() {
		gLoad();
		var q = ''; try { q = new URLSearchParams(location.search).get('g') || ''; } catch (e) {}
		if (!/^[a-f0-9]{24}$/.test(q)) { q = ''; }
		if (q) { try { history.replaceState(null, '', location.pathname); } catch (e) {} }
		if (q && !(state.group && state.group.token === q)) {
			gPost('basket_view', { g: q, me: '' }).then(function (r) {
				if (r.gone) { toast('Diese gemeinsame Bestellung gibt es nicht mehr.'); }
				else if (r.ok && r.joined === false) { if (r.status !== 'open') { toast('Hier wird gerade bestellt, du kannst nicht mehr beitreten.'); } else { gDialog('join', q, r.owner_name); } }
			}).catch(function () {});
		}
		if (state.group) { renderCart(); gRefresh(true); }
		setInterval(function () { if (state.group && !document.hidden) { gRefresh(false); } }, 6000);
		document.addEventListener('visibilitychange', function () { if (state.group && !document.hidden) { gRefresh(false); } });
	}
	document.addEventListener('focusin', function (ev) { if (ev.target && ev.target.matches && ev.target.matches('[data-gb-link]')) { ev.target.select(); } });

	function cartOpen(on) { var c = $('#shop-cart'); if (c) { c.classList.toggle('is-open', on); document.documentElement.style.overflow = on ? 'hidden' : ''; } }
	document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { var c = $('#shop-cart'); if (c && c.classList.contains('is-open')) { cartOpen(false); } } });

	var dlg = $('#product-dialog');
	var dlgTrigger = null;
	if (dlg) { dlg.addEventListener('close', function () { if (dlgTrigger && typeof dlgTrigger.focus === 'function') { dlgTrigger.focus(); } dlgTrigger = null; }); }
	var pizzaDlg = $('#pizza-dialog');
	if (pizzaDlg) { pizzaDlg.addEventListener('close', function () { if (dlgTrigger && typeof dlgTrigger.focus === 'function') { dlgTrigger.focus(); } dlgTrigger = null; }); }
	function openProduct(id, edit) {
		dlgTrigger = document.activeElement;
		fetch('api.php?op=product&id=' + encodeURIComponent(id), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { toast(r.error || 'Das hat nicht geklappt.'); return; }
			// a dish with the configurator switched on opens the pizza table instead (order/pizza.js); without it, or if it fails to load, the normal dialog does the same job
			if (r.product.configurator && window.PizzaLab && window.PizzaLab.open(r.product, edit, { fmt: fmt, esc: esc, cleanTitle: cleanTitle, add: addLine, replace: replaceLine })) { return; }
			buildDialog(r.product, edit);
		}).catch(function () { toast('Das hat nicht geklappt.'); });
	}
	// ---- product dialog: required choices first in one highlighted block (chips, one tap each), optional extras below,
	// collapsed to one line per group that shows what is chosen ("Soßen zum Dippen  Ketchup, Mayo  2")
	var ICON_CHECK = '<svg class="pd-check" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_CHEV = '<svg class="pd-chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	// the menu was imported with hints like "(eine Auswahlmöglichkeit)" in the titles: the badge says that already
	function cleanTitle(t) { return String(t).replace(/\s*\((?:eine |1 )?Auswahlm(?:ö|oe)glichkeit(?:en)?\)\s*$/i, '').trim(); }
	function buildDialog(p, edit) {
		// edit: {i, line} = change a line of the cart; choices that no longer exist on the menu are dropped
		var sel = { vid: p.variations.length ? p.variations[0].id : 0, opts: {}, qty: 1 }, tried = false, editing = !!edit;
		if (editing) {
			var ln = edit.line, ids = {};
			p.groups.forEach(function (g) { g.items.forEach(function (it) { ids[it.id] = it.max; }); });
			if (p.variations.some(function (v) { return v.id === ln.vid; })) { sel.vid = ln.vid; }
			Object.keys(ln.opts || {}).forEach(function (k) { if (ids[k]) { sel.opts[k] = Math.min(+ln.opts[k], ids[k]); } });
			sel.qty = ln.qty;
		}
		function cur() { return p.variations.filter(function (x) { return x.id === sel.vid; })[0]; }
		function mult() { var v = cur(); return v ? v.mult : 1; }
		function base() { var v = cur(); return v ? v.price : p.price; }
		function groupSum(g) { return g.items.reduce(function (s, it) { return s + (sel.opts[it.id] || 0); }, 0); }
		function unit() {
			var extra = 0; p.groups.forEach(function (g) { g.items.forEach(function (it) { extra += it.price * (sel.opts[it.id] || 0); }); });
			return base() + Math.round(extra * mult());
		}
		var req = p.groups.filter(function (g) { return g.min > 0; }), ext = p.groups.filter(function (g) { return g.min <= 0; });
		function missingGroups() { return req.filter(function (g) { return groupSum(g) < g.min; }); }
		// inside the "Extras" section a leading "extra " says nothing new (the cart and the kitchen keep the full name)
		function shown(g, it) { return g.min > 0 ? it.title : it.title.replace(/^extra\s+/i, ''); }
		function isWide(g) { return g.min > 0 ? (g.items.length > 6 || g.items.some(function (it) { return it.title.length > 24; })) : g.items.some(function (it) { return shown(g, it).length > 34; }); }
		function chip(g, it) {
			var single = g.max === 1;
			if (it.max > 1) {
				return '<div class="pd-chip has-step" data-g="' + g.id + '" data-id="' + it.id + '"><span class="pd-chip-name">' + esc(it.title) + '</span><span class="pd-chip-price" data-price="' + it.price + '"></span>' +
					'<span class="pd-step"><button type="button" class="qty-btn" data-d="-1" aria-label="Weniger ' + esc(it.title) + '">&minus;</button><span class="qty-num">0</span><button type="button" class="qty-btn" data-d="1" aria-label="Mehr ' + esc(it.title) + '">+</button></span></div>';
			}
			return '<button type="button" class="pd-chip" data-g="' + g.id + '" data-id="' + it.id + '" aria-pressed="false"' + (single && g.min > 0 ? ' data-radio="1"' : '') + '><span class="pd-chip-name">' + esc(shown(g, it)) + '</span><span class="pd-chip-price" data-price="' + it.price + '"></span>' + ICON_CHECK + '</button>';
		}
		function chips(g) { return '<div class="pd-chips' + (isWide(g) ? ' is-list' : '') + '" role="group" aria-label="' + esc(cleanTitle(g.title)) + '">' + g.items.map(function (it) { return chip(g, it); }).join('') + '</div>'; }
		function badge() { return '<span class="pd-badge" data-badge>Pflicht</span>'; }

		var h = '<div class="pd-head"><h2 id="pd-title">' + esc(p.title) + '</h2><button type="button" class="pd-close" aria-label="Schließen">&times;</button></div><div class="pd-body">';
		if (p.description) { h += '<p class="pd-desc">' + esc(p.description) + '</p>'; }
		if (p.allergens) { h += '<p class="pd-allergens">Enthält: ' + esc(p.allergens) + '</p>'; }
		if (p.variations.length || req.length) {
			h += '<div class="pd-required">';
			if (p.variations.length) {
				h += '<section class="pd-req" data-var="1"><header><h3>Variante</h3>' + badge() + '</header><div class="pd-chips' + (p.variations.length > 6 || p.variations.some(function (v) { return v.title.length > 24; }) ? ' is-list' : '') + '" role="group" aria-label="Variante">' +
					p.variations.map(function (v) { return '<button type="button" class="pd-chip" data-v="' + v.id + '" aria-pressed="false" data-radio="1"><span class="pd-chip-name">' + esc(v.title) + '</span><span class="pd-chip-price">' + fmt(v.price) + '</span>' + ICON_CHECK + '</button>'; }).join('') + '</div></section>';
			}
			req.forEach(function (g) {
				h += '<section class="pd-req" data-g="' + g.id + '"><header><h3>' + esc(cleanTitle(g.title)) + '</h3><span class="pd-need" data-need></span>' + badge() + '</header>' + chips(g) + '</section>';
			});
			h += '</div>';
		}
		if (ext.length) {
			h += '<div class="pd-extras"><h3 class="pd-extras-title">Extras</h3>';
			ext.forEach(function (g) {
				var priced = g.items.map(function (it) { return it.price; }).filter(function (x) { return x > 0; }), from = priced.length ? Math.min.apply(null, priced) : 0;
				h += '<section class="pd-ext" data-g="' + g.id + '"><button type="button" class="pd-ext-head" aria-expanded="false" aria-controls="ext-' + g.id + '"><span class="pd-ext-title">' + esc(cleanTitle(g.title)) + '</span>' +
					'<span class="pd-ext-sum" data-sum data-from="' + from + '"></span><span class="pd-count" data-count hidden></span>' + ICON_CHEV + '</button>' +
					'<div class="pd-ext-body" id="ext-' + g.id + '" inert><div class="pd-ext-in">' + (g.max > 1 ? '<p class="pd-limit" data-limit></p>' : '') + chips(g) + '</div></div></section>';
			});
			h += '</div>';
		}
		var oldNote = editing ? (edit.line.note || '') : '';
		h += '<div class="pd-note"><button type="button" class="pd-note-toggle" aria-expanded="false" aria-controls="pd-note-box"' + (oldNote ? ' hidden' : '') + '>Hinweis für die Küche hinzufügen</button>' +
			'<div id="pd-note-box" class="pd-note-box"' + (oldNote ? '' : ' hidden') + '><label class="pd-note-label" for="pd-note">Hinweis für die Küche</label><textarea id="pd-note" maxlength="200" placeholder="zum Beispiel ohne Zwiebeln">' + esc(oldNote) + '</textarea></div></div></div>' +
			'<div class="pd-foot"><span class="pd-step"><button type="button" class="qty-btn" id="pd-minus" aria-label="Weniger">&minus;</button><span class="qty-num" id="pd-qty">1</span><button type="button" class="qty-btn" id="pd-plus" aria-label="Mehr">+</button></span>' +
			'<button type="button" class="pd-add" id="pd-add"><span id="pd-add-label">' + (editing ? 'Änderung speichern' : 'In den Warenkorb') + '</span><span id="pd-price"></span></button></div><p class="pd-live" role="status" aria-live="polite"></p>';
		dlg.innerHTML = h;

		function groupOf(id) { return p.groups.filter(function (g) { return g.id === id; })[0]; }
		function refresh() {
			var m = mult();
			$$('.pd-chip-price[data-price]', dlg).forEach(function (el) { var pr = +el.dataset.price; el.textContent = pr ? '+' + fmt(Math.round(pr * m)) : ''; });
			$$('.pd-chip[data-v]', dlg).forEach(function (c) { c.setAttribute('aria-pressed', +c.dataset.v === sel.vid ? 'true' : 'false'); });
			p.groups.forEach(function (g) {
				var sum = groupSum(g), full = g.max > 1 && sum >= g.max;
				$$('.pd-chip[data-g="' + g.id + '"]', dlg).forEach(function (c) {
					var q = sel.opts[+c.dataset.id] || 0;
					if (c.classList.contains('has-step')) {
						$('.qty-num', c).textContent = q; c.classList.toggle('is-on', q > 0);
						$('[data-d="1"]', c).disabled = (g.max > 0 && sum >= g.max) || q >= g.items.filter(function (x) { return x.id === +c.dataset.id; })[0].max; $('[data-d="-1"]', c).disabled = q <= 0;
					} else { c.setAttribute('aria-pressed', q > 0 ? 'true' : 'false'); c.disabled = !q && full; }
				});
			});
			// required groups: badge says what is open; "Bitte wählen" only after a first try to order
			$$('.pd-req[data-g]', dlg).forEach(function (sec) {
				var g = groupOf(+sec.dataset.g), sum = groupSum(g), done = sum >= g.min, bd = $('[data-badge]', sec);
				sec.classList.toggle('is-done', done); sec.classList.toggle('is-missing', !done && tried);
				bd.innerHTML = done ? ICON_CHECK + 'Gewählt' : (tried ? 'Bitte wählen' : 'Pflicht');
				$('[data-need]', sec).textContent = (g.min > 1 || g.max > 1) ? (g.max > 0 && g.max !== g.min ? sum + ' von ' + g.min + (g.max > g.min ? '–' + g.max : '') : sum + ' von ' + g.min) : '';
			});
			var vs = $('.pd-req[data-var]', dlg); if (vs) { vs.classList.add('is-done'); $('[data-badge]', vs).innerHTML = ICON_CHECK + 'Gewählt'; }
			// optional groups: one line, shows the choice
			$$('.pd-ext', dlg).forEach(function (sec) {
				var g = groupOf(+sec.dataset.g), names = [], sum = groupSum(g), sm = $('[data-sum]', sec), cnt = $('[data-count]', sec);
				g.items.forEach(function (it) { var q = sel.opts[it.id]; if (q) { names.push((q > 1 ? q + '× ' : '') + shown(g, it)); } });
				sec.classList.toggle('has-choice', sum > 0);
				sm.textContent = names.length ? names.join(', ') : (+sm.dataset.from ? 'ab +' + fmt(Math.round(+sm.dataset.from * m)) : '');
				cnt.hidden = sum === 0; cnt.textContent = sum;
				var lim = $('[data-limit]', sec); if (lim) { lim.textContent = g.max > 0 ? sum + ' von ' + g.max + ' gewählt' : ''; }
			});
			$('#pd-qty').textContent = sel.qty;
			var miss = missingGroups().length, add = $('#pd-add');
			add.classList.toggle('is-blocked', miss > 0);
			$('#pd-add-label').textContent = miss ? 'Noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') : (editing ? 'Änderung speichern' : 'In den Warenkorb');
			$('#pd-price').textContent = miss ? '' : fmt(unit() * sel.qty);
			$('.pd-live', dlg).textContent = tried && miss ? 'Bitte noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') + ' wählen.' : '';
		}
		function step(c, d) {
			var id = +c.dataset.id; sel.opts[id] = Math.max(0, (sel.opts[id] || 0) + d); if (!sel.opts[id]) { delete sel.opts[id]; } refresh();
		}
		dlg.onclick = function (ev) {
			var t = ev.target;
			if (t === dlg || t.closest('.pd-close')) { dlg.close(); return; }
			var chipEl = t.closest('.pd-chip');
			if (chipEl && chipEl.dataset.v) { sel.vid = +chipEl.dataset.v; refresh(); return; }
			if (chipEl && chipEl.classList.contains('has-step')) { var sb = t.closest('.qty-btn'); if (sb) { step(chipEl, +sb.dataset.d); } return; }
			if (chipEl) {
				var g = groupOf(+chipEl.dataset.g), id = +chipEl.dataset.id, q = sel.opts[id] || 0;
				if (g.max === 1) {
					if (q) { if (g.min > 0) { return; } delete sel.opts[id]; } else { g.items.forEach(function (x) { delete sel.opts[x.id]; }); sel.opts[id] = 1; }
				} else if (q) { delete sel.opts[id]; } else if (!(g.max > 0 && groupSum(g) >= g.max)) { sel.opts[id] = 1; }
				refresh(); return;
			}
			var head = t.closest('.pd-ext-head');
			if (head) {
				var sec = head.closest('.pd-ext'), on = !sec.classList.contains('is-open');
				sec.classList.toggle('is-open', on); head.setAttribute('aria-expanded', on ? 'true' : 'false');
				if (on) { $('.pd-ext-body', sec).removeAttribute('inert'); } else { $('.pd-ext-body', sec).setAttribute('inert', ''); }
				return;
			}
			var nt = t.closest('.pd-note-toggle');
			if (nt) { var box = $('#pd-note-box', dlg); box.hidden = false; nt.hidden = true; $('#pd-note', dlg).focus(); return; }
			if (t.closest('#pd-minus')) { sel.qty = Math.max(1, sel.qty - 1); refresh(); return; }
			if (t.closest('#pd-plus')) { sel.qty = Math.min(50, sel.qty + 1); refresh(); return; }
			if (t.closest('#pd-add')) {
				var mg = missingGroups();
				if (mg.length) {
					tried = true; refresh();
					var first = $('.pd-req[data-g="' + mg[0].id + '"]', dlg);
					if (first) { first.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); first.classList.remove('is-flash'); void first.offsetWidth; first.classList.add('is-flash'); var fc = $('.pd-chip', first); if (fc) { fc.focus({ preventScroll: true }); } }
					return;
				}
				commit();
			}
		};
		function commit() {
			var v = cur(), parts = [];
			p.groups.forEach(function (g) { g.items.forEach(function (it) { var q = sel.opts[it.id]; if (q) { parts.push((q > 1 ? q + '× ' : '') + it.title); } }); });
			var line = { pid: p.id, title: p.title, vid: sel.vid, vtitle: v ? v.title : '', opts: JSON.parse(JSON.stringify(sel.opts)), optText: parts.join(', '), unit: unit(), qty: sel.qty, note: ($('#pd-note', dlg) || { value: '' }).value.trim(), ch: 1 };
			if (editing) { replaceLine(edit.i, line); } else { addLine(line); }
			dlg.close();
		}
		refresh();
		if (editing) { $$('.pd-ext.has-choice', dlg).forEach(function (sec) { sec.classList.add('is-open'); $('.pd-ext-head', sec).setAttribute('aria-expanded', 'true'); $('.pd-ext-body', sec).removeAttribute('inert'); }); }
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}

	// ---- events
	document.addEventListener('click', function (ev) {
		var t = ev.target;
		var mode = t.closest('.mode-btn'); if (mode) { setMode(mode.dataset.mode); return; }
		if (!ACCEPT) { return; }
		var item = t.closest('.shop-item');
		if (item && (t.closest('.shop-add') || t.closest('.shop-item-text'))) {
			if (item.dataset.choices === '1' || !t.closest('.shop-add')) { openProduct(item.dataset.id); }
			else { addLine({ pid: +item.dataset.id, title: item.dataset.title, vid: 0, vtitle: '', opts: {}, optText: '', unit: +item.dataset.price, qty: 1, note: '' }); }
			return;
		}
		if (t.closest('[data-gb-new]')) { gDialog('create'); return; }
		var gq = t.closest('[data-gid]');
		if (gq) { gPost('basket_qty', { id: +gq.dataset.gid, qty: +gq.dataset.q }).then(function (r) { if (!r.ok) { toast(r.error || 'Das hat nicht geklappt.'); } return gRefresh(true); }).catch(function () { toast('Das hat nicht geklappt.'); }); return; }
		if (t.closest('[data-gb-share]')) { gShare(); return; }
		var gco = t.closest('[data-gb-checkout]');
		if (gco) {
			if (gco.getAttribute('aria-disabled') === 'true') { return; }
			gPost('basket_status', { to: 'locked' }).then(function (r) { if (!r.ok) { toast(r.error || 'Das hat nicht geklappt.'); return gRefresh(true); } location.href = 'checkout.php?g=' + state.group.token; }).catch(function () { toast('Das hat nicht geklappt.'); });
			return;
		}
		if (t.closest('[data-gb-reopen]')) { gPost('basket_status', { to: 'open' }).then(function () { return gRefresh(true); }); return; }
		var glv = t.closest('[data-gb-leave],[data-gb-end]');
		if (glv) {
			var gend = glv.hasAttribute('data-gb-end');
			if (!glv.dataset.armed && !glv.hasAttribute('data-now')) {
				glv.dataset.armed = '1'; glv.textContent = gend ? 'Wirklich beenden? Alle Wünsche gehen verloren.' : 'Deine Gerichte bleiben im Warenkorb. Wirklich verlassen?';
				setTimeout(function () { glv.dataset.armed = ''; glv.textContent = glv.dataset.label || ''; }, 4000); return;
			}
			if (gend) { gPost('basket_close').then(function () { gEnd('Die gemeinsame Bestellung ist beendet.'); cartOpen(false); }); } else { gEnd(glv.hasAttribute('data-now') ? '' : 'Du hast die gemeinsame Bestellung verlassen.'); cartOpen(false); }
			return;
		}
		if (t.closest('#cartbar')) { cartOpen(true); return; }
		if (t.closest('#cart-close')) { cartOpen(false); return; }
		var nb = t.closest('[data-note]');
		if (nb) { var nl = state.cart[+nb.dataset.note]; if (nl) { state.noteEdit = +nb.dataset.note; state.noteDraft = nl.note || ''; state.noteFocus = true; renderCart(); } return; }
		if (t.closest('[data-note-cancel]')) { state.noteEdit = null; renderCart(); return; }
		var ns = t.closest('[data-note-save]');
		if (ns) { var ni = +ns.dataset.noteSave, nl2 = state.cart[ni]; state.noteEdit = null; if (nl2) { replaceLine(ni, Object.assign({}, nl2, { note: state.noteDraft.trim().slice(0, 200) })); } else { renderCart(); } return; }
		var ed = t.closest('[data-edit]');
		if (ed) { var el = state.cart[+ed.dataset.edit]; if (el) { openProduct(el.pid, { i: +ed.dataset.edit, line: el }); } return; }
		var up = t.closest('[data-up]');
		if (up) {
			var uli = $('.shop-item[data-id="' + up.dataset.up + '"]');
			if (uli) { if (uli.dataset.choices === '1') { openProduct(uli.dataset.id); } else { addLine({ pid: +uli.dataset.id, title: uli.dataset.title, vid: 0, vtitle: '', opts: {}, optText: '', unit: +uli.dataset.price, qty: 1, note: '' }); } }
			return;
		}
		var clr = t.closest('[data-clear]');
		if (clr) {
			if (!clr.dataset.armed) { clr.dataset.armed = '1'; clr.textContent = 'Wirklich alles entfernen?'; setTimeout(function () { clr.dataset.armed = ''; clr.textContent = 'Warenkorb leeren'; }, 4000); return; }
			state.cart = []; save(); renderCart(); cartOpen(false); return;
		}
		var q = t.closest('.qty-btn[data-i]');
		if (q) { var l = state.cart[+q.dataset.i]; l.qty += +q.dataset.d; if (l.qty <= 0) { state.cart.splice(+q.dataset.i, 1); state.noteEdit = null; } save(); renderCart(); return; }
		var rm = t.closest('.cart-remove'); if (rm) { state.cart.splice(+rm.dataset.i, 1); state.noteEdit = null; save(); renderCart(); }
	});

	document.addEventListener('input', function (ev) { if (ev.target.matches && ev.target.matches('textarea[data-cn]')) { state.noteDraft = ev.target.value; } });

	// what the guest account (konto.js) needs: put lines of an earlier order or a favorite into the cart, look at the cart
	window.AmadeusShop = {
		fmt: fmt, esc: esc, toast: toast, heart: HEART,
		accepting: ACCEPT,
		cart: function () { return state.cart; },
		subtotal: function () { return subtotal(); },
		mode: function () { return state.mode; },
		openCart: function () { cartOpen(true); },
		addMany: function (lines) {
			if (!ACCEPT) { return; }
			lines.forEach(function (l) {
				if (state.group) { gAdd(l); return; }
				var k = lineKey(l), hit = state.cart.filter(function (x) { return lineKey(x) === k; })[0];
				if (hit) { hit.qty = Math.min(50, hit.qty + l.qty); } else { state.cart.push(l); }
			});
			save(); renderCart();
		}
	};

	load();
	if (ACCEPT) { gInit(); }
	setMode(state.mode);
	watchCategories();
	initSearch();
	initShopZone();
	loadState();
	setInterval(loadState, 60000);
	if (ACCEPT) { renderCart(); }
})();
