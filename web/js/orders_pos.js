/* The till: "Bestellung erfassen" (web/content/orders_pos.page.php). One page, no reload. The menu on the left, the Bon on the right in the order of the call:
   Wer (type, phone, name - the caller is recognised and his name, address and last order are there), Was (the lines), Wohin (address with zone, district and
   way), Wann (what to tell the caller: how long it takes now, or a wish time) and Zahlung (cash with the change). The sum and the button stay in sight.
   Dishes with choices open the same dialog as in the guest shop. Everything is sent to shop_create_manual_order() (ajax/shop_pos.php); the server stays the
   only authority for prices, zone and time. Pricing here mirrors shop_price_line() so the Bon matches what will be charged. */
(function () {
	'use strict';
	var page = document.getElementById('pos-page'); if (!page) { return; }
	var TOKEN = page.dataset.token;
	var catalog = [], cart = [], activeCat = null, seenCalls = {};
	var S = { quote: null, when: null, zone: null, zoneErr: '', customer: null, popular: [], payWith: 0, prefilled: false };
	var f = document.getElementById('pos-form');

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function money(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function num1(n) { return n.toFixed(1).replace('.', ','); }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
	function type() { return f.elements.type.value; }
	function pay() { return f.elements.payment.value; }
	function get(op, params) {
		var qs = new URLSearchParams(params || {}); qs.set('op', op);
		return fetch('ajax/shop_pos.php?' + qs.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
	}
	function post(op, data) {
		data = Object.assign({}, data, { op: op, token: TOKEN });
		return fetch('ajax/shop_pos.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
	}
	// a blocking error, a success, and "Einen Moment ..." must not look the same - staff mid-call could miss that something failed
	function setMsg(text, kind) {
		var el = $('#pos-msg');
		el.textContent = text;
		el.classList.toggle('is-error', kind === 'error');
		el.classList.toggle('is-ok', kind === 'ok');
		el.classList.toggle('is-warn', kind === 'warn');
	}
	var ICON_OK = '<svg class="kx-ic" viewBox="0 0 16 16" width="20" height="20" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

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

	// ---- menu: "Beliebt" (the most ordered of the last 30 days) and "Dieser Gast" (what the caller orders) first, then the categories. A search replaces the
	// category by a flat result list over all of them. The arrow keys move through what is shown, Enter puts it into the Bon.
	var kbIndex = 0;
	function pseudoCats() {
		var list = [];
		if (S.popular.length) { list.push({ id: 'pop', name: 'Beliebt' }); }
		if (S.customer && S.customer.fav && S.customer.fav.length) { list.push({ id: 'fav', name: 'Dieser Gast' }); }
		return list;
	}
	function renderCats() {
		$('#pos-cats').innerHTML = pseudoCats().concat(catalog).map(function (c) {
			return '<button type="button" class="kx-cat' + (c.id === activeCat ? ' is-active' : '') + '" data-cat="' + c.id + '" role="tab" aria-selected="' + (c.id === activeCat ? 'true' : 'false') + '">' + esc(c.name) + '</button>';
		}).join('');
	}
	function itemHtml(p) {
		var price = p.variations.length ? Math.min.apply(null, p.variations.map(function (v) { return v.price; })) : p.price;
		return '<button type="button" class="kx-item" data-product="' + p.id + '"><span class="kx-item-t">' + esc(p.title) + '</span><span class="kx-item-p">' + (p.variations.length ? 'ab ' : '') + money(price) + '</span></button>';
	}
	function productsOf(catId) {
		if (catId === 'pop' || catId === 'fav') {
			var ids = catId === 'pop' ? S.popular : (S.customer ? S.customer.fav : []);
			return ids.map(findProduct).filter(Boolean);
		}
		var cat = catalog.filter(function (c) { return c.id === catId; })[0];
		return cat ? cat.products : [];
	}
	function markKb() {
		var items = $$('.kx-item', $('#pos-products'));
		items.forEach(function (it, i) { it.classList.toggle('is-kb', i === kbIndex && !!$('#pos-search').value.trim()); });
		if (items[kbIndex] && $('#pos-search').value.trim()) { items[kbIndex].scrollIntoView({ block: 'nearest' }); }
	}
	function renderProducts() {
		var root = $('#pos-products'), qWords = wordsOf($('#pos-search').value);
		kbIndex = 0;
		if (qWords.length) {
			var matches = [];
			catalog.forEach(function (cat) { cat.products.forEach(function (p) { if (textMatchesQuery(p.title, qWords)) { matches.push(p); } }); });
			$('#pos-search-empty').hidden = matches.length > 0;
			root.innerHTML = matches.length ? '<div class="kx-grid-p">' + matches.map(itemHtml).join('') + '</div>' : '';
			markKb(); return;
		}
		$('#pos-search-empty').hidden = true;
		var list = productsOf(activeCat);
		root.innerHTML = list.length ? '<div class="kx-grid-p">' + list.map(itemHtml).join('') + '</div>' : '<p class="kx-empty">Keine Gerichte in dieser Kategorie.</p>';
	}
	function pickDefaultCat() {
		var pc = pseudoCats(); activeCat = pc.length ? pc[0].id : (catalog.length ? catalog[0].id : null);
	}
	function loadMenu() {
		get('menu').then(function (r) {
			if (!r.ok) { $('#pos-products').innerHTML = '<p class="kx-empty">Speisekarte konnte nicht geladen werden.</p>'; return; }
			catalog = r.categories.map(function (c) { return { id: +c.id, name: c.name, products: c.products.map(function (p) { return Object.assign({}, p, { id: +p.id }); }) }; });
			get('popular').then(function (pr) { if (pr.ok) { S.popular = pr.ids; } }).catch(function () {}).then(function () { pickDefaultCat(); renderCats(); renderProducts(); });
		});
	}
	// a dish without choices goes straight into the Bon (the same dish again raises the quantity); one with choices opens the dialog
	function addProduct(p) {
		if (p.variations.length || p.groups.length) { buildPosDialog(p); return; }
		var same = cart.filter(function (l) { return l.pid === p.id && !l.note; })[0];
		if (same) { same.qty = Math.min(50, same.qty + 1); } else { cart.push({ pid: p.id, vid: 0, opts: {}, qty: 1, note: '', title: p.title, variation: '', options: [], unit: p.price }); }
		renderCart(); backToSearch();
	}
	function backToSearch() { var s = $('#pos-search'); s.value = ''; $('#pos-search-clear').hidden = true; renderProducts(); s.focus(); }

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
	dlg.addEventListener('close', function () { var s = $('#pos-search'); if (s) { s.focus(); } });

	// ---- the Bon: lines with a stepper (the quantity is the thing staff change most), kept in sessionStorage too so a reload does not lose what the caller ordered
	var CART_KEY = 'pos_cart';
	function saveCart() { try { sessionStorage.setItem(CART_KEY, JSON.stringify(cart)); } catch (e) {} }
	function loadCart() {
		try { var raw = sessionStorage.getItem(CART_KEY); if (raw) { var parsed = JSON.parse(raw); if (Array.isArray(parsed)) { cart = parsed; } } } catch (e) {}
	}
	var CART_MAX = 60, CART_WARN_AT = 50;
	function subtotal() { return cart.reduce(function (s, l) { return s + l.unit * l.qty; }, 0); }
	function renderCart() {
		saveCart();
		var root = $('#pos-cart');
		if (!cart.length) { root.innerHTML = '<p class="kx-empty">Noch nichts im Bon. Gericht tippen oder anklicken.</p>'; renderSum(); renderAlerts(); return; }
		root.innerHTML = cart.map(function (l, i) {
			var prod = findProduct(l.pid), editable = !!(prod && (prod.variations.length || prod.groups.length));
			return '<article class="kx-line" data-index="' + i + '"><div class="kx-step" role="group" aria-label="Menge ' + esc(l.title) + '"><button type="button" data-q="-1" aria-label="Weniger"' + (l.qty <= 1 ? ' disabled' : '') + '>&minus;</button><b>' + l.qty + '</b><button type="button" data-q="1" aria-label="Mehr">+</button></div>' +
				'<div class="kx-line-main">' + (editable ? '<button type="button" class="kx-line-t is-editable" data-edit="' + i + '">' : '<span class="kx-line-t">') + esc(l.title) + (l.variation ? ' <span class="kx-var">' + esc(l.variation) + '</span>' : '') + (editable ? '</button>' : '</span>') +
				(l.options.length ? '<span class="kx-opts">' + l.options.map(function (o) { return (o.qty > 1 ? o.qty + '× ' : '') + esc(o.title); }).join(', ') + '</span>' : '') +
				'<input type="text" class="kx-line-note" data-note="' + i + '" value="' + esc(l.note) + '" placeholder="Notiz, z.B. ohne Zwiebeln" maxlength="200" aria-label="Notiz zu ' + esc(l.title) + '"/></div>' +
				'<div class="kx-line-sum"><b>' + money(l.unit * l.qty) + '</b><button type="button" class="kx-del" data-remove="' + i + '" aria-label="' + esc(l.title) + ' entfernen">&times;</button></div></article>';
		}).join('') + (cart.length >= CART_WARN_AT ? '<p class="kx-warn-line">' + cart.length + ' von maximal ' + CART_MAX + ' Positionen.</p>' : '');
		renderSum(); renderAlerts();
		var last = $('#pos-cart').lastElementChild; if (last && cart.length) { last.scrollIntoView({ block: 'nearest' }); }
	}
	$('#pos-cart').addEventListener('click', function (ev) {
		var q = ev.target.closest('[data-q]');
		if (q) { var l = cart[+q.closest('.kx-line').dataset.index]; l.qty = Math.max(1, Math.min(50, l.qty + +q.dataset.q)); renderCart(); return; }
		var rm = ev.target.closest('[data-remove]'); if (rm) { cart.splice(+rm.dataset.remove, 1); renderCart(); return; }
		var ed = ev.target.closest('[data-edit]'); if (ed) { editCartLine(+ed.dataset.edit); }
	});
	$('#pos-cart').addEventListener('input', function (ev) { var n = ev.target.closest('[data-note]'); if (n) { cart[+n.dataset.note].note = n.value; saveCart(); } });
	function editCartLine(idx) {
		var l = cart[idx], prod = findProduct(l.pid);
		if (prod && (prod.variations.length || prod.groups.length)) { buildPosDialog(prod, idx); }
	}

	// ---- Wann: what to tell the caller (how long it takes now, from the settings and the kitchen) and a wish time
	var STATUS_T = { 'new': 'neu', accepted: 'angenommen', preparing: 'in der Küche', ready: 'fertig', delivering: 'unterwegs' };
	function loadQuote() {
		get('quote', { type: type() }).then(function (r) { if (r.ok) { S.quote = r; renderTime(); renderAlerts(); renderSum(); } }).catch(function () {});
	}
	function slotLabel(s) { return s.slice(0, 10) === ymd(new Date()) ? s.slice(11, 16) : 'morgen ' + s.slice(11, 16); }
	function renderTime() {
		var q = S.quote; if (!q) { return; }
		var kitchen = q.extra ? q.base + ' Min Grundzeit und ' + q.extra + ' Min, weil ' + q.backlog + ' Bestellungen in der Küche sind' : q.base + ' Min Grundzeit, ' + (q.backlog ? q.backlog + (q.backlog === 1 ? ' Bestellung' : ' Bestellungen') + ' in der Küche' : 'die Küche ist frei');
		$('#kx-quote').innerHTML = S.when
			? '<span class="kx-say">Wunschzeit <b>' + esc(slotLabel(S.when)) + ' Uhr</b></span><span class="kx-why">Das sagst du dem Gast zu.</span>'
			: '<span class="kx-say">Jetzt ca. <b>' + q.min + ' Min</b> · ' + (type() === 'delivery' ? 'bei dir' : 'abholbereit') + ' gegen <b>' + esc(q.eta) + ' Uhr</b></span><span class="kx-why">' + esc(kitchen) + '</span>';
		var h = '<button type="button" class="kx-chip' + (!S.when ? ' is-on' : '') + '" data-when="">Jetzt</button>' +
			q.slots.slice(0, 6).map(function (s) { return '<button type="button" class="kx-chip' + (S.when === s ? ' is-on' : '') + '" data-when="' + s + '">' + esc(slotLabel(s)) + '</button>'; }).join('') +
			'<button type="button" class="kx-chip' + (S.when && q.slots.indexOf(S.when) < 0 ? ' is-on' : '') + '" data-when="custom">Andere Zeit</button>';
		$('#kx-whens').innerHTML = h;
		$('#kx-foot-say').innerHTML = S.when ? 'Wunschzeit <b>' + esc(slotLabel(S.when)) + ' Uhr</b>' : (type() === 'delivery' ? 'Lieferung' : 'Abholung') + ' jetzt ca. <b>' + q.min + ' Min</b> · gegen <b>' + esc(q.eta) + ' Uhr</b>';
	}
	$('#kx-whens').addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-when]'); if (!b) { return; }
		var v = b.dataset.when;
		if (v === 'custom') { var c = $('#kx-custom'); c.hidden = !c.hidden; if (!c.hidden) { $('#kx-time').focus(); } return; }
		$('#kx-custom').hidden = true; S.when = v || null; renderTime(); renderAlerts(); renderSum();
	});
	function customWhen() {
		var t = $('#kx-time').value; if (!t) { return; }
		var d = new Date(); d.setDate(d.getDate() + +$('#kx-day').value);
		var w = ymd(d) + ' ' + t;
		if (new Date(w.replace(' ', 'T')).getTime() < Date.now() + 120000) { setMsg('Die Uhrzeit liegt in der Vergangenheit.', 'warn'); return; }
		setMsg(''); S.when = w; renderTime(); renderAlerts(); renderSum();
	}
	$('#kx-time').addEventListener('change', customWhen); $('#kx-day').addEventListener('change', customWhen);

	// ---- hints at the top of the Bon: what the caller must hear before saying yes
	function renderAlerts() {
		var a = [], q = S.quote, t = type(), lab = t === 'delivery' ? 'Lieferung' : 'Abholung';
		if (q) {
			if (q.paused) { a.push(['danger', lab + ' ist pausiert' + (q.paused_until ? ' bis ' + q.paused_until + ' Uhr' : '') + '. Dem Gast sagen, dass es nicht sofort geht.']); }
			else if (!q.open) { a.push(['warn', 'Gerade keine Bestellzeit für ' + lab + (q.note ? ' (' + q.note + ')' : '') + (q.next ? ', nächste ab ' + q.next + ' Uhr' : '') + '. Wunschzeit wählen oder ablehnen.']); }
		}
		if (t === 'delivery' && S.zoneErr) { a.push(['danger', S.zoneErr]); }
		if (S.customer && S.customer.open && S.customer.open.length) {
			a.push(['warn', 'Hat heute schon eine Bestellung: ' + S.customer.open.map(function (o) { return '#' + o.day_no + ' ' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + ' (' + (STATUS_T[o.status] || o.status) + ', ' + o.time + ' Uhr)'; }).join(', ') + '. Ist das eine zweite?']);
		}
		if (t === 'delivery' && cart.length) {
			var min = S.zone && S.zone.min > 0 ? S.zone.min : (q ? q.min_order_cents : 0), sub = subtotal();
			if (min > 0 && sub < min) { a.push(['warn', 'Noch ' + money(min - sub) + ' bis zum Mindestbestellwert (' + money(min) + ').']); }
		}
		if (S.when && q && S.when.slice(0, 10) === ymd(new Date()) && q.windows.length) {
			var hm = S.when.slice(11, 16), inside = q.windows.some(function (w) { return hm >= w[0] && hm <= w[1]; });
			if (!inside) { a.push(['warn', 'Die Wunschzeit liegt außerhalb der Bestellzeiten (' + q.windows.map(function (w) { return w[0] + '–' + w[1]; }).join(', ') + ').']); }
		}
		$('#kx-alerts').innerHTML = a.map(function (x) { return '<p class="kx-alert is-' + x[0] + '">' + esc(x[1]) + '</p>'; }).join('');
	}

	// ---- Wohin: zone and fee while typing, then district and driving way (slower, two outside services)
	var zoneTimer = null, zoneSeq = 0;
	function checkZone() {
		var seq = ++zoneSeq, z = $('#pos-zone');
		if (type() !== 'delivery') { S.zone = null; S.zoneErr = ''; z.innerHTML = ''; renderAlerts(); renderSum(); return; }
		var street = f.elements.street.value.trim(), zip = f.elements.zip.value.trim(), city = f.elements.city.value.trim();
		if (street.length < 3) { S.zone = null; S.zoneErr = ''; z.innerHTML = ''; renderAlerts(); renderSum(); return; }
		get('zone', { street: street, zip: zip, city: city }).then(function (r) {
			if (seq !== zoneSeq) { return; }
			if (!r.ok) {
				S.zone = null; renderSum();
				if (r.reason === 'ambiguous' && r.candidates && r.candidates.length) {
					S.zoneErr = '';
					z.innerHTML = '<p class="kx-zone-l">Mehrere Treffer, bitte wählen:</p>' + r.candidates.map(function (c, i) { return '<button type="button" class="kx-pick" data-i="' + i + '">' + esc(c.road) + ', ' + esc(c.postcode) + '</button>'; }).join(' ');
					$$('.kx-pick', z).forEach(function (b) { b.addEventListener('click', function () { f.elements.zip.value = r.candidates[+b.dataset.i].postcode; checkZone(); }); });
				} else { S.zoneErr = r.error || 'Die Adresse wurde nicht gefunden.'; z.innerHTML = ''; }
				renderAlerts(); return;
			}
			S.zoneErr = ''; S.zone = { fee: r.zone.fee, min: r.zone.min || 0, name: r.zone.name };
			if (r.postcode && !zip) { f.elements.zip.value = r.postcode; }
			z.innerHTML = '<p class="kx-zone-l"><b>' + esc(r.zone.name) + '</b> · Liefergebühr <b>' + money(r.zone.fee) + '</b>' + (S.zone.min ? ' · Mindestbestellwert ' + money(S.zone.min) : '') + '</p><p class="kx-zone-l" id="kx-zone-i">Stadtteil und Strecke werden gesucht ...</p>';
			renderAlerts(); renderSum();
			get('zone_info', { lat: r.lat, lng: r.lng, street: street, zip: zip }).then(function (i) {
				if (seq !== zoneSeq) { return; }
				var el = $('#kx-zone-i'); if (!el) { return; }
				var parts = []; if (i.suburb) { parts.push('<b>' + esc(i.suburb) + '</b>'); } if (i.km != null) { parts.push(num1(i.km) + ' km' + (i.min ? ' · ca. ' + i.min + ' Min Fahrt' : '')); }
				el.innerHTML = parts.length ? parts.join(' · ') : ''; if (!parts.length) { el.remove(); }
			}).catch(function () { var el = $('#kx-zone-i'); if (el) { el.remove(); } });
		}).catch(function () {});
	}
	$$('#pos-address input').forEach(function (i) { i.addEventListener('input', function () { clearTimeout(zoneTimer); zoneTimer = setTimeout(checkZone, 600); }); });
	function syncType() {
		var pickup = type() === 'pickup';
		$('#pos-address').hidden = pickup; $('#pos-no-phone-label').hidden = !pickup;
		if (!pickup) { $('#pos-no-phone').checked = false; }
		var noPhone = pickup && $('#pos-no-phone').checked; f.elements.phone.disabled = noPhone;
		checkZone(); loadQuote(); renderAlerts(); renderSum();
	}
	$$('input[name=type]', f).forEach(function (r) { r.addEventListener('change', syncType); });
	$('#pos-no-phone').addEventListener('change', syncType);

	// ---- Wer: the caller is recognised by the number (name, address, last order, how often, an open order)
	var phoneTimer = null, lastLookup = '';
	function lookup() {
		var phone = f.elements.phone.value.trim();
		if (phone.replace(/\D/g, '').length < 6) { S.customer = null; lastLookup = ''; renderCust(); renderCats(); renderAlerts(); return; }
		if (phone === lastLookup) { return; }
		lastLookup = phone;
		get('customer', { phone: phone }).then(function (r) {
			if (!r.ok || f.elements.phone.value.trim() !== phone) { return; }
			S.customer = r; applyCustomer(r); renderCust(); renderCats(); renderAlerts();
		}).catch(function () {});
	}
	f.elements.phone.addEventListener('input', function () { clearTimeout(phoneTimer); phoneTimer = setTimeout(lookup, 400); });
	function applyCustomer(r) {
		var o = r.orders[0]; S.prefilled = false; if (!o) { return; }
		if (!f.elements.name.value.trim()) { f.elements.name.value = o.name; S.prefilled = true; }
		if (type() === 'delivery' && o.type === 'delivery' && !f.elements.street.value.trim()) {
			f.elements.street.value = o.street; f.elements.zip.value = o.zip; f.elements.city.value = o.city; f.elements.address_note.value = o.address_note || ''; S.prefilled = true; checkZone();
		}
	}
	function orderSummary(o) { return o.items.map(function (i) { return i.qty + '× ' + i.title; }).join(', '); }
	function renderCust() {
		var c = S.customer, root = $('#kx-cust');
		if (!c) { root.innerHTML = ''; return; }
		if (!c.n) { root.innerHTML = '<p class="kx-known is-new"><b>Neukunde</b> · noch keine Bestellung unter dieser Nummer.</p>'; return; }
		var last = c.orders[0], h = '<p class="kx-known"><b>Stammkunde</b> · ' + c.n + (c.n === 1 ? ' Bestellung' : ' Bestellungen') + (last ? ' · zuletzt ' + esc(last.created.slice(8, 10) + '.' + last.created.slice(5, 7) + '.') : '') + (S.prefilled ? ' · Name und Adresse aus der letzten Bestellung' : '') + '</p>';
		if (last && last.items.length) { h += '<button type="button" class="kx-last" data-i="0"><span class="kx-last-l">Wie letztes Mal</span><span class="kx-last-t">' + esc(orderSummary(last)) + '</span><b>' + money(last.total) + '</b></button>'; }
		if (c.orders.length > 1) { h += '<details class="kx-older"><summary>Frühere Bestellungen</summary>' + c.orders.slice(1).map(function (o, i) { return '<div class="kx-older-row"><span>' + esc(o.created.slice(8, 10) + '.' + o.created.slice(5, 7) + '.') + ' · ' + esc(orderSummary(o)) + ' · ' + money(o.total) + '</span><button type="button" class="kx-mini" data-i="' + (i + 1) + '">Übernehmen</button></div>'; }).join('') + '</details>'; }
		root.innerHTML = h;
	}
	// a past order goes into the Bon (dishes by name; what no longer exists on the menu is named, so nothing is missed silently)
	function applyOrder(o) {
		var skipped = [];
		o.items.forEach(function (hi) {
			var prod = null;
			catalog.forEach(function (c) { c.products.forEach(function (p) { if (p.title.toLowerCase() === hi.title.toLowerCase()) { prod = p; } }); });
			if (!prod) { skipped.push(hi.title); return; }
			var vid = 0;
			if (hi.variation) { var v = prod.variations.filter(function (x) { return x.title.toLowerCase() === hi.variation.toLowerCase(); })[0]; if (v) { vid = v.id; } else { skipped.push(hi.title + ' (' + hi.variation + ')'); return; } }
			var opts = {};
			(hi.options || []).forEach(function (ho) {
				var found = false;
				prod.groups.forEach(function (g) { g.items.forEach(function (it) { if (it.title.toLowerCase() === String(ho.title).toLowerCase()) { opts[it.id] = ho.qty || 1; found = true; } }); });
				if (!found) { skipped.push(hi.title + ' – ' + ho.title); }
			});
			var priced = priceLine(prod, vid, opts);
			cart.push({ pid: prod.id, vid: vid, opts: opts, qty: hi.qty, note: hi.note || '', title: prod.title, variation: priced.variation, options: priced.options, unit: priced.unit });
		});
		renderCart();
		if (skipped.length) { setMsg('Nicht automatisch übernommen (bitte von Hand ergänzen): ' + skipped.join(', '), 'warn'); } else { setMsg(''); }
	}
	$('#kx-cust').addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-i]'); if (!b || !S.customer) { return; }
		var o = S.customer.orders[+b.dataset.i]; if (o) { applyOrder(o); backToSearch(); }
	});

	// ---- Zahlung: cash with "the guest pays with ...", the change is shown for the caller and goes onto the delivery slip
	function total() { return subtotal() + (type() === 'delivery' && S.zone ? S.zone.fee : 0); }
	function renderCash() {
		var box = $('#kx-cash'), cash = pay() === 'cash'; box.hidden = !cash;
		if (!cash) { S.payWith = 0; return; }
		var t = total(), opts = [], chips = '';
		if (t > 0) {
			[5, 10, 20, 50, 100].forEach(function (d) { var v = Math.ceil(t / (d * 100)) * d * 100; if (opts.indexOf(v) < 0) { opts.push(v); } });
			chips = '<button type="button" class="kx-chip' + (S.payWith === 0 ? ' is-on' : '') + '" data-cash="0">passend</button>' + opts.slice(0, 4).map(function (v) { return '<button type="button" class="kx-chip' + (S.payWith === v ? ' is-on' : '') + '" data-cash="' + v + '">' + esc(money(v).replace(',00', '')) + '</button>'; }).join('');
		}
		$('#kx-cash-chips').innerHTML = chips;
		$('#kx-change').textContent = S.payWith && S.payWith >= t ? 'Rückgeld ' + money(S.payWith - t) : (S.payWith ? 'Es fehlen ' + money(t - S.payWith) : '');
		$('#kx-change').classList.toggle('is-short', !!S.payWith && S.payWith < t);
	}
	$('#kx-cash-chips').addEventListener('click', function (ev) { var b = ev.target.closest('[data-cash]'); if (b) { S.payWith = +b.dataset.cash; $('#kx-cash-in').value = ''; renderCash(); } });
	$('#kx-cash-in').addEventListener('input', function () { var n = parseFloat(this.value.replace(',', '.')); S.payWith = isFinite(n) && n > 0 ? Math.round(n * 100) : 0; renderCash(); });
	$$('input[name=payment]', f).forEach(function (r) { r.addEventListener('change', renderCash); });

	// ---- the sum, the line to read back to the caller and the button
	function renderSum() {
		var sub = subtotal(), del = type() === 'delivery', fee = del && S.zone ? S.zone.fee : 0, t = sub + fee;
		$('#kx-sum').innerHTML = '<div><dt>Zwischensumme</dt><dd>' + money(sub) + '</dd></div>' + (del ? '<div><dt>Lieferung</dt><dd>' + (S.zone ? money(fee) : '<span class="kx-dash">Adresse fehlt</span>') + '</dd></div>' : '') +
			'<div class="is-total"><dt>Gesamt</dt><dd>' + money(t) + '</dd></div>';
		$('#kx-go-t').textContent = cart.length ? money(t) : '';
		$('#kx-go').disabled = !cart.length;
		var q = S.quote, when = S.when ? 'um ' + slotLabel(S.when) + ' Uhr' : (q ? 'in etwa ' + q.min + ' Minuten, gegen ' + q.eta + ' Uhr' : '');
		$('#kx-read-t').textContent = cart.length ? 'Ich wiederhole: ' + cart.map(function (l) { return l.qty + ' mal ' + l.title + (l.variation ? ', ' + l.variation : '') + (l.options.length ? ' mit ' + l.options.map(function (o) { return o.title; }).join(' und ') : ''); }).join('; ') + '. Zusammen ' + money(t) + (del && S.zone ? ' mit Lieferung' : '') +
			(del ? ', nach ' + (f.elements.street.value.trim() || '(Adresse)') : ', zum Abholen') + ', ' + when + '. Zahlung ' + (pay() === 'cash' ? 'bar' : 'mit Karte') + '.' : 'Noch nichts im Bon.';
		renderCash();
	}

	// ---- a call comes in (Sipgate): the banner names the caller when the number is known; one click puts the number in and everything else follows
	var activeCall = null;
	function callBannerHtml(call) {
		return 'Anruf von <strong>' + (call.name ? esc(call.name) + ' · ' : '') + esc(call.phone) + '</strong> <button type="button" class="kx-btn is-gold" data-call-use="' + call.id + '">Übernehmen</button> <button type="button" class="kx-btn" data-call-dismiss="' + call.id + '">Schließen</button>';
	}
	function showCallInto(el) {
		if (!el) { return; }
		if (activeCall) { el.hidden = false; el.innerHTML = callBannerHtml(activeCall); } else { el.hidden = true; el.innerHTML = ''; }
	}
	function pollCall() {
		get('last_call').then(function (r) {
			if (r.ok && r.call && !seenCalls[r.call.id] && (!activeCall || activeCall.id !== r.call.id)) {
				activeCall = r.call; showCallInto($('#pos-call')); showCallInto($('#pd-call', dlg));
				get('customer', { phone: r.call.phone }).then(function (c) { if (c.ok && c.orders && c.orders[0] && activeCall && activeCall.id === r.call.id) { activeCall.name = c.orders[0].name; showCallInto($('#pos-call')); showCallInto($('#pd-call', dlg)); } }).catch(function () {});
			}
		}).catch(function () {});
	}
	function dismissCall(id) { seenCalls[id] = true; activeCall = null; showCallInto($('#pos-call')); showCallInto($('#pd-call', dlg)); }
	pollCall(); setInterval(pollCall, 5000);

	page.addEventListener('click', function (ev) {
		var callUse = ev.target.closest('[data-call-use]');
		if (callUse && activeCall) {
			if (dlg.open) { dlg.close(); }
			f.elements.phone.value = activeCall.phone; lastLookup = ''; lookup(); dismissCall(activeCall.id); (f.elements.name.value ? $('#pos-search') : f.elements.name).focus();
			return;
		}
		var callDismiss = ev.target.closest('[data-call-dismiss]'); if (callDismiss) { dismissCall(callDismiss.dataset.callDismiss); return; }
		var catBtn = ev.target.closest('[data-cat]');
		if (catBtn) {
			activeCat = isNaN(+catBtn.dataset.cat) ? catBtn.dataset.cat : +catBtn.dataset.cat;
			var s = $('#pos-search'); if (s.value) { s.value = ''; $('#pos-search-clear').hidden = true; }
			renderCats(); renderProducts(); return;
		}
		var prodBtn = ev.target.closest('[data-product]');
		if (prodBtn) { var p = findProduct(+prodBtn.dataset.product); if (p) { addProduct(p); } }
	});

	// ---- keyboard: type anywhere = search, arrows + Enter = pick, F2 phone, F3 search, Ctrl+Enter = place the order
	var search = $('#pos-search');
	search.addEventListener('keydown', function (ev) {
		var items = $$('.kx-item', $('#pos-products'));
		if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
			if (!items.length) { return; } ev.preventDefault();
			if (!search.value.trim()) { items[0].focus(); return; }
			kbIndex = Math.max(0, Math.min(items.length - 1, kbIndex + (ev.key === 'ArrowDown' ? 1 : -1))); markKb();
		} else if (ev.key === 'Enter') {
			if (ev.ctrlKey || ev.metaKey) { return; }
			ev.preventDefault(); var it = items[kbIndex] || items[0]; if (it && search.value.trim()) { it.click(); }
		}
	});
	$('#pos-products').addEventListener('keydown', function (ev) {
		var items = $$('.kx-item', this), i = items.indexOf(document.activeElement); if (i < 0) { return; }
		var cols = Math.max(1, Math.round(this.firstElementChild ? this.firstElementChild.clientWidth / items[0].offsetWidth : 1)), to = -1;
		if (ev.key === 'ArrowRight') { to = i + 1; } else if (ev.key === 'ArrowLeft') { to = i - 1; } else if (ev.key === 'ArrowDown') { to = i + cols; } else if (ev.key === 'ArrowUp') { to = i - cols; }
		if (to >= 0 && to < items.length) { ev.preventDefault(); items[to].focus(); } else if (ev.key === 'ArrowUp' && to < 0) { ev.preventDefault(); search.focus(); }
	});
	document.addEventListener('keydown', function (ev) {
		if (ev.key === 'F2') { ev.preventDefault(); f.elements.phone.focus(); f.elements.phone.select(); return; }
		if (ev.key === 'F3') { ev.preventDefault(); search.focus(); search.select(); return; }
		if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); f.requestSubmit(); return; }
		if (dlg.open) { return; }
		if (ev.key === 'Escape') { if (search.value) { search.value = ''; $('#pos-search-clear').hidden = true; renderProducts(); } return; }
		var t = ev.target, typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
		if (!typing && ev.key.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey) { search.focus(); }
	});

	// ---- placing the order; afterwards the confirmation with "zurücknehmen" and the till is ready for the next call
	function fail(msg, field, sec) {
		setMsg(msg, 'error');
		if (field) { field.focus(); }
		$$('.kx-sec', f).forEach(function (s) { s.classList.toggle('is-err', s === sec); });
	}
	f.addEventListener('submit', function (ev) {
		ev.preventDefault();
		$$('.kx-sec', f).forEach(function (s) { s.classList.remove('is-err'); });
		var el = f.elements, pickup = type() === 'pickup', noPhone = pickup && el.no_phone.checked;
		if (!cart.length) { return fail('Der Bon ist leer.', search); }
		if (cart.length > CART_MAX) { return fail('Zu viele Positionen (' + cart.length + ' von maximal ' + CART_MAX + '). Bitte in zwei Bestellungen aufteilen.'); }
		if (!noPhone && el.phone.value.replace(/\D/g, '').length < 6) { return fail('Bitte eine Telefonnummer angeben.', el.phone, el.phone.closest('.kx-sec')); }
		if (el.name.value.trim().length < 2) { return fail('Bitte einen Namen angeben.', el.name, el.name.closest('.kx-sec')); }
		if (!pickup && el.street.value.trim().length < 3) { return fail('Bitte die Adresse angeben.', el.street, $('#pos-address')); }
		var payload = {
			type: type(), name: el.name.value, phone: el.phone.value, no_phone: !!el.no_phone.checked, street: el.street.value, zip: el.zip.value, city: el.city.value,
			address_note: el.address_note.value, payment: pay(), note: el.note.value, when: S.when || '', pay_with: pay() === 'cash' ? S.payWith : 0,
			lines: cart.map(function (l) { return { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note }; })
		};
		$('#kx-go').disabled = true; setMsg('Einen Moment ...');
		post('create', payload).then(function (r) {
			if (!r.ok) {
				$('#kx-go').disabled = !cart.length;
				if (r.reason === 'ambiguous' && r.candidates && r.candidates.length) { fail('Adresse mehrdeutig, bitte oben eine der vorgeschlagenen Adressen wählen.', null, $('#pos-address')); checkZone(); return; }
				return fail(r.error || 'Das hat nicht geklappt.');
			}
			var o = r.order, due = o.scheduled_at || o.eta_at;
			showDone(o, due);
			resetBon(); loadRecent(); loadQuote();
		}).catch(function () { $('#kx-go').disabled = !cart.length; fail('Keine Verbindung. Bitte versuche es noch einmal.'); });
	});
	function resetBon() {
		cart = []; saveCart(); f.reset(); S.when = null; S.zone = null; S.zoneErr = ''; S.customer = null; S.payWith = 0; S.prefilled = false; lastLookup = ''; zoneSeq++;
		$('#pos-zone').innerHTML = ''; $('#kx-custom').hidden = true; $('#kx-cash-in').value = ''; $('#pos-search').value = ''; $('#pos-search-clear').hidden = true;
		renderCart(); renderCust(); pickDefaultCat(); renderCats(); renderProducts(); syncType(); setMsg('');
		f.elements.phone.focus();
	}
	var doneTimer = null;
	function showDone(o, due) {
		var box = $('#kx-done'); clearTimeout(doneTimer);
		box.innerHTML = '<p class="kx-done-t">' + ICON_OK + 'Bestellung <b>#' + o.day_no + '</b> angelegt</p><p class="kx-done-s">' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + ' ' + (o.scheduled_at ? 'um ' : 'gegen ') + '<b>' + esc(String(due).slice(11, 16)) + ' Uhr</b> · ' + money(o.total_cents) + '</p>' +
			'<div class="kx-done-a"><button type="button" class="kx-btn is-gold" data-next>Nächste Bestellung</button><button type="button" class="kx-btn" data-undo="' + o.id + '">Zurücknehmen</button></div>';
		box.hidden = false; $('#kx-scroll').scrollTop = 0;
		doneTimer = setTimeout(function () { box.hidden = true; }, 60000);
	}
	function undo(id, btn) {
		btn.disabled = true;
		post('cancel', { id: id }).then(function (r) { if (r.ok) { $('#kx-done').hidden = true; setMsg('Die Bestellung wurde zurückgenommen.', 'ok'); loadRecent(); } else { setMsg(r.error || 'Das hat nicht geklappt.', 'error'); btn.disabled = false; } }).catch(function () { btn.disabled = false; setMsg('Keine Verbindung.', 'error'); });
	}
	$('#kx-done').addEventListener('click', function (ev) {
		if (ev.target.closest('[data-next]')) { this.hidden = true; f.elements.phone.focus(); return; }
		var u = ev.target.closest('[data-undo]'); if (u) { armed(u, 'Wirklich zurücknehmen?', 'Zurücknehmen', function () { undo(+u.dataset.undo, u); }); }
	});
	function armed(btn, confirmText, idleText, go) {
		if (!btn.dataset.armed) { btn.dataset.armed = '1'; btn.textContent = confirmText; setTimeout(function () { btn.dataset.armed = ''; btn.textContent = idleText; }, 4000); return; }
		go();
	}
	function loadRecent() {
		get('recent').then(function (r) {
			if (!r.ok) { return; }
			$('#kx-recent > summary').textContent = 'Zuletzt erfasst' + (r.orders.length ? ' (' + r.orders.length + ')' : '');
			$('#kx-recent-l').innerHTML = r.orders.length ? r.orders.map(function (o) {
				return '<div class="kx-rec"><span><b>#' + o.day_no + '</b> · ' + esc(o.name) + ' · ' + money(o.total) + '</span><span class="kx-rec-s">' + esc(o.time) + ' Uhr · ' + (o.type === 'delivery' ? 'Lieferung' : 'Abholung') + (o.due ? ' ' + (o.scheduled ? 'um ' : 'gegen ') + esc(o.due) : '') + ' · ' + esc(STATUS_T[o.status] || o.status) + '</span>' +
					(o.can_cancel ? '<button type="button" class="kx-mini" data-undo="' + o.id + '">Zurücknehmen</button>' : '') + '</div>';
			}).join('') : '<p class="kx-empty">Heute noch nichts an der Kasse erfasst.</p>';
		}).catch(function () {});
	}
	$('#kx-recent-l').addEventListener('click', function (ev) { var u = ev.target.closest('[data-undo]'); if (u) { armed(u, 'Wirklich?', 'Zurücknehmen', function () { undo(+u.dataset.undo, u); }); } });

	// ---- Kassenmodus: the backend header and menu go away, the Bon gets the whole height
	var modeBtn = $('#kx-mode');
	function setMode(on) { document.body.classList.toggle('kx-mode', on); modeBtn.setAttribute('aria-pressed', String(on)); modeBtn.textContent = on ? 'Kassenmodus beenden' : 'Kassenmodus'; if (typeof fit === 'function') { setTimeout(fit, 0); } try { localStorage.setItem('kxMode', on ? '1' : '0'); } catch (e) {} }
	modeBtn.addEventListener('click', function () { setMode(!document.body.classList.contains('kx-mode')); });
	try { if (localStorage.getItem('kxMode') === '1') { setMode(true); } } catch (e) {}

	// the till fills the window: menu and Bon scroll on their own, the sum and the button stay at the bottom
	var grid = $('.kx-grid');
	function fit() { grid.style.height = Math.max(520, window.innerHeight - grid.getBoundingClientRect().top - 14 + window.scrollY * 0) + 'px'; }
	window.addEventListener('resize', fit);

	loadCart(); renderCart(); loadMenu(); syncType(); loadRecent(); setInterval(loadQuote, 60000); fit(); setTimeout(fit, 300);
	$('#pos-phone').focus();
})();
