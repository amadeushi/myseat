/* POS: "Bestellung erfassen" (web/content/orders_pos.page.php). One page, no reload - pick products off the same
   menu the guest shop uses (via a product picker modal for anything with variations/options), fill in the
   caller's details (with a quick "last ordered" shortcut by phone), and submit straight into the normal order
   flow (shop_create_manual_order()). Pricing here mirrors web/classes/shop.class.php's shop_price_line() exactly
   so the cart total matches what the server will charge - the server stays the only authority at submit time. */
(function () {
	'use strict';
	var page = document.getElementById('pos-page'); if (!page) { return; }
	var TOKEN = page.dataset.token;
	var catalog = [], cart = [], activeCat = null, seenCalls = {};

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function get(op, params) {
		var qs = new URLSearchParams(params || {}); qs.set('op', op);
		return fetch('ajax/shop_pos.php?' + qs.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
	}
	function post(op, data) {
		data = Object.assign({}, data, { op: op, token: TOKEN });
		return fetch('ajax/shop_pos.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
	}
	// a blocking error, a success, and "Einen Moment ..." used to render in identical gray text - staff mid-call
	// could easily miss that something actually failed, or think an order didn't save when it did
	function setMsg(text, kind) {
		var el = $('#pos-msg');
		el.textContent = text;
		el.classList.toggle('is-error', kind === 'error');
		el.classList.toggle('is-ok', kind === 'ok');
		el.classList.toggle('is-warn', kind === 'warn');
	}

	// ---- pricing, mirrors shop_price_line() so the cart shown here matches what the server will charge
	function priceLine(product, vid, opts) {
		var base = product.price, mult = 1, vtitle = '';
		if (product.variations.length) {
			var v = product.variations.filter(function (x) { return x.id === vid; })[0];
			if (v) { base = v.price; mult = v.mult; vtitle = v.title; }
		}
		var extras = 0, chosen = [];
		product.groups.forEach(function (g) {
			g.items.forEach(function (it) {
				var q = opts[it.id] || 0;
				if (q > 0) { extras += it.price * q; chosen.push({ group: g.title, title: it.title, qty: q, price: it.price }); }
			});
		});
		return { unit: base + Math.round(extras * mult), variation: vtitle, options: chosen };
	}
	function findProduct(id) { for (var i = 0; i < catalog.length; i++) { var p = catalog[i].products.filter(function (x) { return x.id === id; })[0]; if (p) { return p; } } return null; }

	// ---- menu / categories / products. One category shown at a time (compact, like before) - but a non-empty
	// search query replaces that with a flat, cross-category results list, so a match is never hidden just
	// because a different tab happens to be selected. Clearing the search returns to the active tab.
	function renderCats() {
		$('#pos-cats').innerHTML = catalog.map(function (c) {
			return '<button type="button" class="pos-cat' + (c.id === activeCat ? ' is-active' : '') + '" data-cat="' + c.id + '">' + esc(c.name) + '</button>';
		}).join('');
	}
	function itemHtml(p) {
		var price = p.variations.length ? Math.min.apply(null, p.variations.map(function (v) { return v.price; })) : p.price;
		return '<button type="button" class="pos-item" data-product="' + p.id + '"><span class="pos-item-title">' + esc(p.title) + '</span><span class="pos-item-price">' + money(price) + (p.variations.length ? '+' : '') + '</span></button>';
	}
	function renderProducts() {
		var root = $('#pos-products'), qWords = wordsOf($('#pos-search').value);
		if (qWords.length) {
			var matches = [];
			catalog.forEach(function (cat) { cat.products.forEach(function (p) { if (textMatchesQuery(p.title, qWords)) { matches.push(p); } }); });
			$('#pos-search-empty').hidden = matches.length > 0;
			root.innerHTML = matches.length ? '<div class="pos-grid">' + matches.map(itemHtml).join('') + '</div>' : '';
			return;
		}
		$('#pos-search-empty').hidden = true;
		var cat = catalog.filter(function (c) { return c.id === activeCat; })[0];
		root.innerHTML = (cat && cat.products.length) ? '<div class="pos-grid">' + cat.products.map(itemHtml).join('') + '</div>' : '<p class="orders-empty">Keine Gerichte in dieser Kategorie.</p>';
	}
	function loadMenu() {
		get('menu').then(function (r) {
			if (!r.ok) { $('#pos-products').innerHTML = '<p class="orders-empty">Speisekarte konnte nicht geladen werden.</p>'; return; }
			catalog = r.categories.map(function (c) { return { id: +c.id, name: c.name, products: c.products.map(function (p) { return Object.assign({}, p, { id: +p.id }); }) }; });
			activeCat = catalog.length ? catalog[0].id : null;
			renderCats(); renderProducts();
		});
	}

	// ---- search over the product tiles (ported from order/shop.js, same typo-tolerant matching so staff get the
	// same forgiving search the guest menu already has - no server round trip, just filters what is on screen)
	function norm(s) { return String(s || '').toLowerCase(); }
	function wordsOf(s) { return norm(s).replace(/[^a-z0-9äöüß]+/g, ' ').trim().split(/\s+/).filter(Boolean); }
	function editDistance(a, b) {
		if (a === b) { return 0; }
		var al = a.length, bl = b.length; if (!al) { return bl; } if (!bl) { return al; }
		var d = [], i, j;
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
	function fuzzyInside(q, w) {
		for (var len = q.length - 1; len <= q.length + 1; len++) {
			if (len < 1 || len > w.length) { continue; }
			if (editDistance(q, w.substr(0, len)) <= 1 || editDistance(q, w.substr(w.length - len, len)) <= 1) { return true; }
		}
		return false;
	}
	function wordMatches(q, w) {
		if (w.length >= 3 && q.indexOf(w) >= 0) { return true; }
		if (q.length >= 3 && w.indexOf(q) >= 0) { return true; }
		var maxLen = Math.max(q.length, w.length);
		if (maxLen < 4) { return false; }
		if (editDistance(q, w) <= typoLimit(maxLen)) { return true; }
		if (q.length >= 5 && w.length - q.length >= 3 && w.length - q.length <= 14) { return fuzzyInside(q, w); }
		return false;
	}
	function textMatchesQuery(text, qWords) {
		var words = wordsOf(text);
		return qWords.every(function (q) { return words.some(function (w) { return wordMatches(q, w); }); });
	}
	(function initSearch() {
		var searchIn = $('#pos-search'), searchClear = $('#pos-search-clear'), timer;
		searchIn.addEventListener('input', function () {
			if (searchClear) { searchClear.hidden = !this.value; }
			clearTimeout(timer); timer = setTimeout(renderProducts, 60);
		});
		searchIn.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && this.value) { this.value = ''; if (searchClear) { searchClear.hidden = true; } renderProducts(); } });
		if (searchClear) { searchClear.addEventListener('click', function () { searchIn.value = ''; searchClear.hidden = true; renderProducts(); searchIn.focus(); }); }
	})();

	// ---- product dialog (ported from order/shop.js's buildDialog): required choices first in one highlighted
	// block with a "Pflicht"/"Gewählt" badge, one tap per chip; optional extras collapsed below, one line each -
	// the exact distinction the till staff asked for, instead of a flat list of fieldsets. Tapping an existing
	// cart line reopens this same dialog pre-filled (editIndex set) instead of forcing delete-and-re-add.
	var dlg = $('#pos-dlg');
	var ICON_CHECK = '<svg class="pd-check" viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var ICON_CHEV = '<svg class="pd-chev" viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	function cleanTitle(t) { return String(t).replace(/\s*\((?:eine |1 )?Auswahlm(?:ö|oe)glichkeit(?:en)?\)\s*$/i, '').trim(); }
	function buildPosDialog(p, editIndex) {
		var editing = typeof editIndex === 'number', existing = editing ? cart[editIndex] : null;
		var sel = { vid: existing ? existing.vid : (p.variations.length ? p.variations[0].id : 0), opts: existing ? JSON.parse(JSON.stringify(existing.opts)) : {}, qty: existing ? existing.qty : 1 }, tried = false;
		var addLabel = editing ? 'Aktualisieren' : 'In den Warenkorb', noteVal = existing ? existing.note : '';
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
		function shown(g, it) { return g.min > 0 ? it.title : it.title.replace(/^extra\s+/i, ''); }
		function isWide(g) { return g.min > 0 ? (g.items.length > 6 || g.items.some(function (it) { return it.title.length > 24; })) : g.items.some(function (it) { return shown(g, it).length > 34; }); }
		function chip(g, it) {
			if (it.max > 1) {
				return '<div class="pd-chip has-step" data-g="' + g.id + '" data-id="' + it.id + '"><span class="pd-chip-name">' + esc(it.title) + '</span><span class="pd-chip-price" data-price="' + it.price + '"></span>' +
					'<span class="pd-step"><button type="button" class="qty-btn" data-d="-1" aria-label="Weniger ' + esc(it.title) + '">&minus;</button><span class="qty-num">0</span><button type="button" class="qty-btn" data-d="1" aria-label="Mehr ' + esc(it.title) + '">+</button></span></div>';
			}
			return '<button type="button" class="pd-chip" data-g="' + g.id + '" data-id="' + it.id + '" aria-pressed="false"><span class="pd-chip-name">' + esc(shown(g, it)) + '</span><span class="pd-chip-price" data-price="' + it.price + '"></span>' + ICON_CHECK + '</button>';
		}
		function chips(g) { return '<div class="pd-chips' + (isWide(g) ? ' is-list' : '') + '" role="group" aria-label="' + esc(cleanTitle(g.title)) + '">' + g.items.map(function (it) { return chip(g, it); }).join('') + '</div>'; }
		function badge() { return '<span class="pd-badge" data-badge>Pflicht</span>'; }

		var h = '<div class="pd-head"><h2 id="pd-title">' + esc(p.title) + '</h2><button type="button" class="pd-close" aria-label="Schließen">&times;</button></div>' +
			'<p class="pd-call" id="pd-call" role="status" aria-live="assertive" hidden></p><div class="pd-body">';
		if (p.variations.length || req.length) {
			h += '<div class="pd-required">';
			if (p.variations.length) {
				h += '<section class="pd-req" data-var="1"><header><h3>Variante</h3>' + badge() + '</header><div class="pd-chips' + (p.variations.length > 6 || p.variations.some(function (v) { return v.title.length > 24; }) ? ' is-list' : '') + '" role="group" aria-label="Variante">' +
					p.variations.map(function (v) { return '<button type="button" class="pd-chip" data-v="' + v.id + '" aria-pressed="false"><span class="pd-chip-name">' + esc(v.title) + '</span><span class="pd-chip-price">' + money(v.price) + '</span>' + ICON_CHECK + '</button>'; }).join('') + '</div></section>';
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
		h += '<div class="pd-note"><button type="button" class="pd-note-toggle" aria-expanded="' + (noteVal ? 'true' : 'false') + '" aria-controls="pd-note-box"' + (noteVal ? ' hidden' : '') + '>Hinweis für die Küche hinzufügen</button>' +
			'<div id="pd-note-box" class="pd-note-box"' + (noteVal ? '' : ' hidden') + '><label class="pd-note-label" for="pd-note">Hinweis für die Küche</label><textarea id="pd-note" maxlength="200" placeholder="zum Beispiel ohne Zwiebeln">' + esc(noteVal) + '</textarea></div></div></div>' +
			'<div class="pd-foot"><span class="pd-step"><button type="button" class="qty-btn" id="pd-minus" aria-label="Weniger">&minus;</button><span class="qty-num" id="pd-qty">1</span><button type="button" class="qty-btn" id="pd-plus" aria-label="Mehr">+</button></span>' +
			'<button type="button" class="pd-add" id="pd-add"><span id="pd-add-label">' + addLabel + '</span><span id="pd-price"></span></button></div><p class="pd-live" role="status" aria-live="polite"></p>';
		dlg.innerHTML = h;

		function groupOf(id) { return p.groups.filter(function (g) { return g.id === id; })[0]; }
		function refresh() {
			var m = mult();
			$$('.pd-chip-price[data-price]', dlg).forEach(function (el) { var pr = +el.dataset.price; el.textContent = pr ? '+' + money(Math.round(pr * m)) : ''; });
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
			$$('.pd-req[data-g]', dlg).forEach(function (sec) {
				var g = groupOf(+sec.dataset.g), sum = groupSum(g), done = sum >= g.min, bd = $('[data-badge]', sec);
				sec.classList.toggle('is-done', done); sec.classList.toggle('is-missing', !done && tried);
				bd.innerHTML = done ? ICON_CHECK + 'Gewählt' : (tried ? 'Bitte wählen' : 'Pflicht');
				$('[data-need]', sec).textContent = (g.min > 1 || g.max > 1) ? (g.max > 0 && g.max !== g.min ? sum + ' von ' + g.min + (g.max > g.min ? '–' + g.max : '') : sum + ' von ' + g.min) : '';
			});
			var vs = $('.pd-req[data-var]', dlg); if (vs) { vs.classList.add('is-done'); $('[data-badge]', vs).innerHTML = ICON_CHECK + 'Gewählt'; }
			$$('.pd-ext', dlg).forEach(function (sec) {
				var g = groupOf(+sec.dataset.g), names = [], sum = groupSum(g), sm = $('[data-sum]', sec), cnt = $('[data-count]', sec);
				g.items.forEach(function (it) { var q = sel.opts[it.id]; if (q) { names.push((q > 1 ? q + '× ' : '') + shown(g, it)); } });
				sec.classList.toggle('has-choice', sum > 0);
				sm.textContent = names.length ? names.join(', ') : (+sm.dataset.from ? 'ab +' + money(Math.round(+sm.dataset.from * m)) : '');
				cnt.hidden = sum === 0; cnt.textContent = sum;
				var lim = $('[data-limit]', sec); if (lim) { lim.textContent = g.max > 0 ? sum + ' von ' + g.max + ' gewählt' : ''; }
			});
			$('#pd-qty').textContent = sel.qty;
			var miss = missingGroups().length, add = $('#pd-add');
			add.classList.toggle('is-blocked', miss > 0);
			$('#pd-add-label').textContent = miss ? 'Noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') : addLabel;
			$('#pd-price').textContent = miss ? '' : money(unit() * sel.qty);
			$('.pd-live', dlg).textContent = tried && miss ? 'Bitte noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') + ' wählen.' : '';
		}
		function step(c, d) { var id = +c.dataset.id; sel.opts[id] = Math.max(0, (sel.opts[id] || 0) + d); if (!sel.opts[id]) { delete sel.opts[id]; } refresh(); }
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
					if (first) { first.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); }
					return;
				}
				commit();
			}
		};
		function commit() {
			var priced = priceLine(p, sel.vid, sel.opts);
			var line = { pid: p.id, vid: sel.vid, opts: JSON.parse(JSON.stringify(sel.opts)), qty: sel.qty, note: ($('#pd-note', dlg) || { value: '' }).value.trim(), title: p.title, variation: priced.variation, options: priced.options, unit: priced.unit };
			if (editing) { cart[editIndex] = line; } else { cart.push(line); }
			dlg.close(); renderCart();
		}
		refresh();
		showCallInto($('#pd-call', dlg));
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}

	// ---- cart (kept in sessionStorage too, so an accidental reload doesn't lose what the caller already ordered)
	var CART_KEY = 'pos_cart';
	function saveCart() { try { sessionStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} }
	function loadCart() {
		try { var raw = sessionStorage.getItem(CART_KEY); if (raw) { var parsed = JSON.parse(raw); if (Array.isArray(parsed)) { cart = parsed; } } } catch (e) {}
	}
	var CART_MAX = 60, CART_WARN_AT = 50;
	function renderCart() {
		saveCart();
		var root = $('#pos-cart');
		if (!cart.length) { root.innerHTML = '<p class="orders-empty">Noch nichts im Warenkorb.</p>'; return; }
		var total = 0;
		// a note field is always editable here, even for a plain product with no variations/options at all
		// (e.g. "ohne Zwiebeln" on a pizza that otherwise needs no modal) - typing it updates the cart array
		// directly, without re-rendering the whole list and losing focus mid-keystroke. A line whose product
		// still has variations/options can be tapped to reopen the same dialog pre-filled instead of forcing
		// delete-and-re-add for a single wrong chip.
		root.innerHTML = cart.map(function (l, i) {
			var line = l.unit * l.qty, prod = findProduct(l.pid), editable = !!(prod && (prod.variations.length || prod.groups.length));
			total += line;
			return '<article class="pos-line' + (editable ? ' is-editable' : '') + '" data-index="' + i + '"' + (editable ? ' role="button" tabindex="0" aria-label="' + esc(l.title) + ' bearbeiten"' : '') + '><p>' + l.qty + '× ' + esc(l.title) + (l.variation ? ' <span class="pos-line-var">' + esc(l.variation) + '</span>' : '') + '</p>' +
				(l.options.length ? '<p class="pos-line-opts">' + l.options.map(function (o) { return (o.qty > 1 ? o.qty + '× ' : '') + esc(o.title); }).join(', ') + '</p>' : '') +
				'<input type="text" class="pos-line-note" data-note="' + i + '" value="' + esc(l.note) + '" placeholder="Notiz, z.B. ohne Zwiebeln" maxlength="200"/>' +
				'<p class="pos-line-sum">' + money(line) + '<button type="button" class="pos-line-del" data-remove="' + i + '" aria-label="Entfernen">×</button></p></article>';
		}).join('') + '<p class="pos-cart-total">Gesamt (ohne Liefergebühr) <b>' + money(total) + '</b></p>' +
			(cart.length >= CART_WARN_AT ? '<p class="pos-cart-warn">' + cart.length + ' von maximal ' + CART_MAX + ' Positionen.</p>' : '');
		$$('[data-remove]', root).forEach(function (b) { b.addEventListener('click', function (ev) { ev.stopPropagation(); cart.splice(+b.dataset.remove, 1); renderCart(); }); });
		$$('[data-note]', root).forEach(function (el) { el.addEventListener('click', function (ev) { ev.stopPropagation(); }); el.addEventListener('input', function () { cart[+el.dataset.note].note = el.value; }); });
	}
	function editCartLine(idx) {
		var l = cart[idx], prod = findProduct(l.pid);
		if (prod && (prod.variations.length || prod.groups.length)) { buildPosDialog(prod, idx); }
	}

	// ---- address / zone
	var zoneTimer = null;
	function checkZone() {
		var f = $('#pos-form');
		if (f.type.value !== 'delivery') { $('#pos-zone').textContent = ''; return; }
		var street = f.street.value.trim(), zip = f.zip.value.trim(), city = f.city.value.trim();
		if (street.length < 3) { $('#pos-zone').textContent = ''; return; }
		get('zone', { street: street, zip: zip, city: city }).then(function (r) {
			if (!r.ok) {
				if (r.reason === 'ambiguous' && r.candidates.length) {
					$('#pos-zone').innerHTML = 'Mehrere Treffer: ' + r.candidates.map(function (c, i) { return '<button type="button" class="pos-zone-pick" data-i="' + i + '">' + esc(c.road) + ', ' + esc(c.postcode) + '</button>'; }).join(' ');
					$$('.pos-zone-pick', $('#pos-zone')).forEach(function (b) {
						b.addEventListener('click', function () { var c = r.candidates[+b.dataset.i]; f.zip.value = c.postcode; checkZone(); });
					});
					return;
				}
				$('#pos-zone').textContent = r.error; return;
			}
			if (r.postcode && !zip) { f.zip.value = r.postcode; }
			$('#pos-zone').textContent = r.zone.name + ' · Liefergebühr ' + money(r.zone.fee);
		});
	}
	$$('#pos-address input').forEach(function (i) { i.addEventListener('input', function () { clearTimeout(zoneTimer); zoneTimer = setTimeout(checkZone, 500); }); });
	function syncPhoneRequired() {
		var pickup = $('input[name=type]:checked', page).value === 'pickup';
		$('#pos-no-phone-label').hidden = !pickup;
		var noPhone = pickup && $('#pos-no-phone').checked;
		$('#pos-phone').required = !noPhone;
		$('#pos-phone').disabled = noPhone;
	}
	$$('input[name=type]', page).forEach(function (r) { r.addEventListener('change', function () { $('#pos-address').hidden = r.value !== 'delivery' && r.checked; if (r.checked) { checkZone(); if (r.value === 'delivery') { $('#pos-no-phone').checked = false; } syncPhoneRequired(); } }); });
	$('#pos-no-phone').addEventListener('change', syncPhoneRequired);

	// ---- guest history ("zuletzt bestellt")
	function renderHistory(orders) {
		var root = $('#pos-history');
		if (!orders.length) { root.innerHTML = ''; return; }
		root.innerHTML = '<p class="pos-history-head">Zuletzt bestellt:</p>' + orders.map(function (o, i) {
			return '<div class="pos-history-row"><span>' + esc(o.created) + ' · ' + money(o.total) + ' · ' + o.items.length + ' Position' + (o.items.length === 1 ? '' : 'en') + '</span>' +
				'<button type="button" class="button_dark pos-history-use" data-i="' + i + '">Übernehmen</button></div>';
		}).join('');
		$$('.pos-history-use', root).forEach(function (b) {
			b.addEventListener('click', function () {
				var o = orders[+b.dataset.i], f = $('#pos-form'), skipped = [];
				f.name.value = o.name;
				if (o.type === 'delivery') { f.street.value = o.street; f.zip.value = o.zip; f.city.value = o.city; f.address_note.value = o.address_note || ''; $('input[name=type][value=delivery]').checked = true; checkZone(); }
				o.items.forEach(function (hi) {
					var prod = null;
					catalog.forEach(function (c) { c.products.forEach(function (p) { if (p.title.toLowerCase() === hi.title.toLowerCase()) { prod = p; } }); });
					if (!prod) { skipped.push(hi.title); return; }
					var vid = 0;
					if (hi.variation) { var v = prod.variations.filter(function (x) { return x.title.toLowerCase() === hi.variation.toLowerCase(); })[0]; if (v) { vid = v.id; } else { skipped.push(hi.title + ' (' + hi.variation + ')'); return; } }
					var opts = {};
					(hi.options || []).forEach(function (ho) {
						var found = false;
						prod.groups.forEach(function (g) { g.items.forEach(function (it) { if (it.title.toLowerCase() === ho.title.toLowerCase()) { opts[it.id] = ho.qty; found = true; } }); });
						if (!found) { skipped.push(hi.title + ' – ' + ho.title); }
					});
					var priced = priceLine(prod, vid, opts);
					cart.push({ pid: prod.id, vid: vid, opts: opts, qty: hi.qty, note: hi.note || '', title: prod.title, variation: priced.variation, options: priced.options, unit: priced.unit });
				});
				renderCart();
				if (skipped.length) { setMsg('Nicht automatisch übernommen (bitte manuell hinzufügen): ' + skipped.join(', '), 'warn'); } else { setMsg(''); }
			});
		});
	}
	var phoneTimer = null;
	$('#pos-phone').addEventListener('input', function () {
		clearTimeout(phoneTimer);
		var phone = this.value.trim();
		phoneTimer = setTimeout(function () {
			if (phone.length < 6) { $('#pos-history').innerHTML = ''; return; }
			get('guest_history', { phone: phone }).then(function (r) { if (r.ok) { renderHistory(r.orders); } });
		}, 500);
	});

	// ---- incoming-call banner (Sipgate). A native <dialog> opened via showModal() makes everything outside it
	// inert, so the page-level #pos-call banner alone would go silent/invisible while the product dialog is
	// open - the exact moment a second caller is most likely. The same banner markup is therefore also rendered
	// into the open dialog's own #pd-call placeholder (not inert, since it's inside the dialog).
	var activeCall = null;
	function callBannerHtml(call) {
		return 'Anruf von <strong>' + esc(call.phone) + '</strong> <button type="button" class="button_dark" data-call-use="' + call.id + '">Übernehmen</button> <button type="button" class="offer-delete" data-call-dismiss="' + call.id + '">Ausblenden</button>';
	}
	function showCallInto(el) {
		if (!el) { return; }
		if (activeCall) { el.hidden = false; el.innerHTML = callBannerHtml(activeCall); } else { el.hidden = true; el.innerHTML = ''; }
	}
	function pollCall() {
		get('last_call').then(function (r) {
			if (r.ok && r.call && !seenCalls[r.call.id]) {
				activeCall = r.call;
				showCallInto($('#pos-call'));
				showCallInto($('#pd-call', dlg));
			}
		}).catch(function () {});
	}
	function dismissCall(id) {
		seenCalls[id] = true; activeCall = null;
		showCallInto($('#pos-call')); showCallInto($('#pd-call', dlg));
	}
	pollCall(); setInterval(pollCall, 5000);

	// ---- submit
	page.addEventListener('click', function (ev) {
		var callUse = ev.target.closest('[data-call-use]');
		if (callUse && activeCall) {
			$('#pos-phone').value = activeCall.phone; $('#pos-phone').dispatchEvent(new Event('input'));
			dismissCall(activeCall.id);
			return;
		}
		var callDismiss = ev.target.closest('[data-call-dismiss]');
		if (callDismiss) { dismissCall(callDismiss.dataset.callDismiss); return; }
		var catBtn = ev.target.closest('[data-cat]');
		if (catBtn) {
			activeCat = +catBtn.dataset.cat;
			var s = $('#pos-search'); if (s.value) { s.value = ''; $('#pos-search-clear').hidden = true; }
			renderCats(); renderProducts();
			return;
		}
		var prodBtn = ev.target.closest('[data-product]');
		if (prodBtn) {
			var p = findProduct(+prodBtn.dataset.product); if (!p) { return; }
			if (!p.variations.length && !p.groups.length) { cart.push({ pid: p.id, vid: 0, opts: {}, qty: 1, note: '', title: p.title, variation: '', options: [], unit: p.price }); renderCart(); return; }
			buildPosDialog(p);
			return;
		}
		var lineEl = ev.target.closest('.pos-line.is-editable');
		if (lineEl) { editCartLine(+lineEl.dataset.index); }
	});
	page.addEventListener('keydown', function (ev) {
		if (ev.key !== 'Enter' && ev.key !== ' ') { return; }
		if (ev.target.closest('.pos-line-note') || ev.target.closest('.pos-line-del')) { return; }
		var lineEl = ev.target.closest('.pos-line.is-editable[role="button"]');
		if (lineEl) { ev.preventDefault(); lineEl.click(); }
	});
	$('#pos-form').addEventListener('submit', function (ev) {
		ev.preventDefault();
		var f = ev.target;
		if (!cart.length) { setMsg('Der Warenkorb ist leer.', 'error'); return; }
		if (cart.length > CART_MAX) { setMsg('Zu viele Positionen (' + cart.length + ' von maximal ' + CART_MAX + '). Bitte in zwei Bestellungen aufteilen.', 'error'); return; }
		var payload = {
			type: f.type.value, name: f.name.value, phone: f.phone.value, no_phone: f.no_phone.checked, street: f.street.value, zip: f.zip.value, city: f.city.value,
			address_note: f.address_note.value, payment: f.payment.value, note: f.note.value,
			lines: cart.map(function (l) { return { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note }; }),
		};
		setMsg('Einen Moment ...');
		post('create', payload).then(function (r) {
			if (!r.ok) {
				if (r.reason === 'ambiguous' && r.candidates && r.candidates.length) { setMsg('Adresse mehrdeutig - bitte oben eine der vorgeschlagenen Adressen wählen.', 'error'); checkZone(); return; }
				setMsg(r.error, 'error'); return;
			}
			setMsg('Bestellung ' + r.order.number + ' angelegt.', 'ok');
			cart = []; renderCart(); f.reset(); $('#pos-history').innerHTML = ''; $('#pos-address').hidden = false; syncPhoneRequired(); checkZone();
		}).catch(function () { setMsg('Keine Verbindung. Bitte versuche es noch einmal.', 'error'); });
	});

	loadCart(); renderCart(); loadMenu(); syncPhoneRequired();
})();
