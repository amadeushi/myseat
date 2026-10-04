/* Checkout: summary from the cart in the browser, address check (delivery zone), time choice, payment, order. The server
   recomputes prices, zone, times and payment options; this page only shows what the server will accept. */
(function () {
	'use strict';
	var KEY = 'amadeusCartV2', GUEST = 'amadeusGuestV1', TOKEN = document.body.dataset.token;
	var S = { mode: 'delivery', cart: [], info: null, zone: null, zoneKey: '', zoneAmbiguous: false, zoneWords: '', w3wKey: '', slots: null, tipPct: 0, tipCustom: '', when: 'asap', pay: '', busy: false, coupon: null, group: null, stamp: null, voucher: null, stampSig: '' };
	var GKEY = 'amadeusBasketV1';
	var form = document.getElementById('co-form');

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function post(op, data) {
		data = data || {}; data.op = op; data.token = TOKEN;
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
	}
	function load() {
		try { var d = JSON.parse(localStorage.getItem(KEY) || '{}'); if (d && Array.isArray(d.cart)) { S.cart = d.cart; } if (d && (d.mode === 'pickup' || d.mode === 'delivery')) { S.mode = d.mode; } } catch (e) {}
	}
	function saveMode() { try { var d = JSON.parse(localStorage.getItem(KEY) || '{}'); d.mode = S.mode; localStorage.setItem(KEY, JSON.stringify(d)); } catch (e) {} }

	// details the guest wants to keep on this device (never sent anywhere but with the order)
	var REMEMBER = ['name', 'phone', 'email', 'street', 'zip', 'city', 'address_note'];
	function prefill() {
		try {
			var d = JSON.parse(localStorage.getItem(GUEST) || 'null'); if (!d) { return; }
			REMEMBER.forEach(function (k) { if (d[k] && form.elements[k]) { form.elements[k].value = d[k]; } });
			form.elements.remember.checked = true;
		} catch (e) {}
	}
	function remember() {
		try {
			if (!form.elements.remember.checked) { localStorage.removeItem(GUEST); return; }
			var d = {}; REMEMBER.forEach(function (k) { d[k] = form.elements[k].value.trim(); }); localStorage.setItem(GUEST, JSON.stringify(d));
		} catch (e) {}
	}
	function clock(min) { var t = new Date(Date.now() + min * 60000); return ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2); }

	function subtotal() { return S.cart.reduce(function (s, l) { return s + l.unit * l.qty; }, 0); }
	function fee() { return S.mode === 'delivery' && S.zone ? S.zone.fee : 0; }
	function min() { if (S.mode === 'delivery') { return S.zone ? S.zone.min : (S.info ? S.info.min_delivery : 0); } return S.info ? S.info.min_pickup : 0; }
	function discount() { return S.coupon ? S.coupon.discount : (S.voucher ? S.voucher.discount : 0); }
	// tip as a percentage of the subtotal (not the delivery fee); "Wunschbetrag" overrides it with a free amount
	function tipCents() {
		if (S.tipPct === 'custom') { return Math.max(0, Math.round((parseFloat(String(S.tipCustom).replace(',', '.')) || 0) * 100)); }
		return Math.round(subtotal() * ((+S.tipPct || 0) / 100));
	}
	function total() { return subtotal() - discount() + fee() + tipCents(); }

	// ---- summary
	function renderSummary() {
		$('#co-lines').innerHTML = S.cart.map(function (l) {
			return '<div class="cart-line"><h3>' + l.qty + '× ' + esc(l.title) + (l.vtitle ? ' <span class="cart-opts">(' + esc(l.vtitle) + ')</span>' : '') + '</h3><span class="cart-lineprice">' + fmt(l.unit * l.qty) + '</span>' +
				(l.who ? '<p class="cart-opts">für ' + esc(l.who) + '</p>' : '') +
				(l.optText ? '<p class="cart-opts">' + esc(l.optText) + '</p>' : '') + (l.note ? '<p class="cart-opts">Hinweis: ' + esc(l.note) + '</p>' : '') + '</div>';
		}).join('');
		var h = '<div class="cart-row"><span>Zwischensumme</span><span>' + fmt(subtotal()) + '</span></div>';
		if (S.coupon) { h += '<div class="cart-row coupon"><span>Gutschein ' + esc(S.coupon.code) + ' <button type="button" class="co-coupon-off" id="co-coupon-off">entfernen</button></span><span>&minus;' + fmt(S.coupon.discount) + '</span></div>'; }
		else if (S.voucher && S.voucher.discount > 0) { h += '<div class="cart-row coupon"><span>Stempelkarten-Gutschein</span><span>&minus;' + fmt(S.voucher.discount) + '</span></div>'; }
		if (S.mode === 'delivery') { h += '<div class="cart-row muted"><span>Liefergebühr</span><span>' + (S.zone ? fmt(S.zone.fee) : 'nach Adresse') + '</span></div>'; }
		if (tipCents() > 0) { h += '<div class="cart-row muted"><span>Trinkgeld</span><span>' + fmt(tipCents()) + '</span></div>'; }
		h += '<div class="cart-row total"><span>Gesamt</span><span>' + fmt(total()) + '</span></div>';
		var m = min(); if (m > 0 && subtotal() < m) { h += '<p class="cart-min">Noch ' + fmt(m - subtotal()) + ' bis zum Mindestbestellwert von ' + fmt(m) + '.</p>'; }
		$('#co-totals').innerHTML = h;
		renderPay();
		refreshStamp();
	}

	// ---- stamp card: the guest is recognised by phone / e-mail of this order; a voucher of the card is taken off automatically
	var stampTimer;
	// the number of stamps is only shown to the signed-in guest (the server only fills it in for the owner of the typed phone / e-mail)
	var ACCOUNT = document.body.dataset.account === '1';
	function stampAuthed() { return !!(S.stamp && S.stamp.authed); }
	function drawStamp() {
		var el = $('#co-stamp'); if (!window.AmadeusStamp || !el) { return; }
		AmadeusStamp.render(el, S.stamp, { known: stampAuthed(), next: true, cart: true });
		if (ACCOUNT && !stampAuthed() && el.firstChild) {
			var p = document.createElement('p'), pc = +(S.stamp && S.stamp.percent) || 0, lost = Math.round(Math.max(0, subtotal() - discount()) * pc / 100);
			p.className = 'stp-hint';
			p.innerHTML = S.signedIn ? 'Diese Nummer oder E-Mail gehört nicht zu deinem Konto, dafür gibt es keinen Stempel.'
				: (lost > 0 ? 'Ohne Kundenkonto entgehen dir bei dieser Bestellung ca. <strong>' + fmt(lost) + '</strong> Stempel-Guthaben. ' : '') + '<a href="./?konto=1">Jetzt anmelden</a>';
			el.firstChild.appendChild(p);
		}
	}
	// signed in: name, phone, e-mail and address of the last order fill the form (only fields that are still empty)
	function accountPrefill() {
		if (!ACCOUNT) { return; }
		fetch('api.php?op=me&type=' + S.mode, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok || !r.signed_in) { return; }
			S.signedIn = true;
			// the number or address typed to sign in comes first, then what the last order held
			var p = Object.assign({}, r.profile || {}), c = r.contact || {}, filled = false;
			if (c.phone) { p.phone = c.phone; }
			if (c.name) { p.name = c.name; }
			if (c.mail) { p.email = c.mail; }
			REMEMBER.forEach(function (k) { var el = form.elements[k]; if (p[k] && el && !el.value) { el.value = p[k]; filled = true; } });
			// the delivery address saved in the account replaces what the browser remembered
			var ad = r.address;
			if (ad && !S.addrApplied) {
				S.addrApplied = true;
				[['street', 'street'], ['zip', 'zip'], ['city', 'city'], ['address_note', 'note']].forEach(function (m) { var el = form.elements[m[0]]; if (el && ad[m[1]] !== undefined && ad[m[1]] !== '') { el.value = ad[m[1]]; filled = true; } });
			}
			if (filled && S.cart.length && S.mode === 'delivery') { checkZone(); }
			S.stampSig = ''; refreshStamp();
		}).catch(function () {});
	}
	function refreshStamp() {
		clearTimeout(stampTimer);
		if (!$('#co-stamp') || !S.cart.length) { return; }
		var f = form.elements, sig = [f.phone.value.trim(), f.email.value.trim(), subtotal(), S.mode].join('|');
		if (sig === S.stampSig) { return; }
		S.stampSig = sig;
		post('stamp_state', { phone: f.phone.value, email: f.email.value, type: S.mode, lines: S.cart.map(function (l) { return { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note }; }) }).then(function (r) {
			if (!r.ok) { return; }
			S.stamp = r; S.voucher = (r.voucher && r.voucher.discount > 0) ? r.voucher : null;
			drawStamp(); renderSummary();
		}).catch(function () {});
	}
	// why the button is still disabled, in words
	function reason() {
		if (!S.cart.length) { return 'Dein Warenkorb ist leer.'; }
		if (S.info && !S.info.accepting) { return 'Wir nehmen gerade keine Bestellungen an.'; }
		var pz = S.info && S.info[S.mode];
		if (pz && pz.paused) { return (S.mode === 'delivery' ? 'Die Lieferung' : 'Die Abholung') + ' ist gerade pausiert' + (pz.paused_until ? ' bis etwa ' + pz.paused_until + ' Uhr' : '') + '.'; }
		if (subtotal() < min()) { return 'Noch ' + fmt(min() - subtotal()) + ' bis zum Mindestbestellwert von ' + fmt(min()) + '.'; }
		if (S.mode === 'delivery' && !S.zone) {
			var street = form.elements.street.value.trim(), zip = form.elements.zip.value.trim(), city = form.elements.city.value.trim();
			var w3w = $('#co-w3w'), words = w3w ? w3w.value.trim() : '';
			if (!street && !zip && !city && !words) { return 'Bitte gib deine Lieferadresse an.'; }
			if (S.zoneAmbiguous) { return 'Bitte wähle deine Adresse aus der Liste aus.'; }
			if (words && S.w3wKey === words) { return 'Wir liefern leider nicht zu dieser Adresse.'; }
			if (street && city && S.zoneKey === street + '|' + zip + '|' + city) { return 'Wir liefern leider nicht zu dieser Adresse.'; }
			return 'Wir prüfen noch, ob wir zu deiner Adresse liefern.';
		}
		var dayEl = $('select[name=day]'), timeEl = $('select[name=time]');
		if (S.when !== 'asap' && !(dayEl && timeEl && timeEl.value)) { return 'Bitte wähle eine Zeit.'; }
		if (!form.elements.name.value.trim()) { return 'Bitte gib deinen Namen an.'; }
		if (!form.elements.phone.value.trim()) { return 'Bitte gib deine Telefonnummer an.'; }
		if (!S.pay) { return 'Bitte wähle eine Zahlungsart.'; }
		return '';
	}
	function updateSubmit() {
		var why = reason(), btn = $('#co-submit');
		btn.disabled = !!why || S.busy;
		if (!S.busy) { btn.textContent = 'Zahlungspflichtig bestellen · ' + fmt(total()); }
		$('#co-why').textContent = S.busy ? '' : why;
		updateProgress();
	}
	// what is still needed before the order can be placed, as a short checklist (delivery address only
	// while ordering for delivery; the time step counts as done once "as soon as possible" or a slot is picked)
	function checklist() {
		var items = [];
		if (S.mode === 'delivery') { items.push(!!(S.zoneWords || (form.elements.street.value.trim() && form.elements.zip.value.trim() && form.elements.city.value.trim())) && !!S.zone); }
		var dayEl = $('select[name=day]'), timeEl = $('select[name=time]');
		items.push(S.when === 'asap' || !!(dayEl && timeEl && timeEl.value));
		items.push(!!(form.elements.name.value.trim() && form.elements.phone.value.trim()));
		items.push(!!S.pay);
		return items;
	}
	function updateProgress() {
		if (!S.cart.length) { return; }
		var items = checklist(), done = items.filter(Boolean).length, left = items.length - done;
		$('#co-progress-fill').style.transform = 'scaleX(' + (items.length ? done / items.length : 1) + ')';
		$('#co-progress-text').textContent = left <= 0 ? 'Bereit zum Bestellen!' : (left === 1 ? 'Fast geschafft – nur noch 1 Angabe!' : 'Fast geschafft – noch ' + left + ' Angaben');
	}

	// ---- mode, address, time, payment
	function setMode(m) {
		S.mode = m; saveMode();
		$$('.mode-btn').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.mode === m ? 'true' : 'false'); });
		$('#sec-address').hidden = m !== 'delivery';
		$('#co-tipbox').hidden = !(S.info && S.info.pay.tip && m === 'delivery');
		if (m !== 'delivery' && (S.tipPct || S.tipCustom)) {
			S.tipPct = 0; S.tipCustom = ''; $('#co-tip-custom').hidden = true; $('#co-tip-custom-in').value = '';
			$$('.tip-btn').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.pct === '0' ? 'true' : 'false'); });
		}
		$('#co-modehint').textContent = m === 'delivery' ? 'Wir liefern innerhalb unseres Liefergebiets. Die Liefergebühr hängt von deiner Adresse ab.' : 'Du holst dein Essen bei uns ab, bezahlen kannst du online oder vor Ort.';
		loadSlots(); renderPay(); renderSummary();
	}
	// the what3words escape hatch only appears once a typed address is not found (not when it is
	// simply outside the delivery area - a code will not help there either)
	function setW3wVisible(show) {
		var t = $('#co-w3w-toggle'), b = $('#co-w3w-box');
		if (t) { t.hidden = !show; }
		if (!show && b) { b.hidden = true; }
	}
	// two or more real, deliverable streets matched the same typed text - the guest picks instead of the
	// app silently guessing between them (see shop_find_zone()'s 'ambiguous' reason)
	function renderCandidates(list) {
		var box = $('#co-candidates'); if (!box) { return; }
		if (!list || !list.length) { box.hidden = true; box.innerHTML = ''; return; }
		box.innerHTML = list.map(function (c, i) {
			return '<button type="button" class="co-candidate" data-i="' + i + '"><strong>' + esc(c.road) + '</strong><small>' + esc(c.postcode) + ' ' + esc(c.city) + '</small></button>';
		}).join('');
		box.hidden = false;
		$$('.co-candidate', box).forEach(function (btn, i) {
			btn.addEventListener('click', function () {
				var c = list[i], out = $('#co-zone');
				form.elements.zip.value = c.postcode;
				S.zoneKey = form.elements.street.value.trim() + '|' + form.elements.zip.value.trim() + '|' + form.elements.city.value.trim();
				S.zone = c.zone; S.zoneAmbiguous = false;
				out.className = 'co-zone ok'; out.textContent = 'Wir liefern zu dir. Liefergebühr ' + fmt(c.zone.fee) + ', Mindestbestellwert ' + fmt(c.zone.min) + '.';
				setW3wVisible(false); box.hidden = true; box.innerHTML = '';
				renderSummary();
			});
		});
	}
	var zoneTimer;
	function checkZone() {
		clearTimeout(zoneTimer);
		var street = form.elements.street.value.trim(), zip = form.elements.zip.value.trim(), city = form.elements.city.value.trim(), out = $('#co-zone');
		var key = street + '|' + zip + '|' + city;
		// typing a real address again means leaving what3words mode, even if it had succeeded
		S.zoneWords = ''; S.w3wKey = '';
		// a house number is required before asking at all - a street name alone ("Goschentor") is too vague a
		// query and Nominatim/Google may fuzzy-match it to a different, wrong real street while still typing
		if (!street || !city || !/\d/.test(street)) { S.zone = null; S.zoneKey = ''; S.zoneAmbiguous = false; out.textContent = ''; setW3wVisible(false); renderCandidates(null); renderSummary(); return; }
		if (key === S.zoneKey) { return; }
		out.className = 'co-zone'; out.textContent = 'Adresse wird geprüft ...';
		renderCandidates(null);
		zoneTimer = setTimeout(function () {
			post('zone', { street: street, zip: zip, city: city }).then(function (r) {
				S.zoneKey = key;
				if (r.ok) {
					// the PLZ comes straight from the same lookup that just confirmed the address - it always
					// takes over here (even a wrong one already in the field, e.g. from browser autofill),
					// since a confirmed match knows better. The street itself is never rewritten: a guest
					// should never see their own typed text silently replaced by something else
					if (r.postcode) { form.elements.zip.value = r.postcode; }
					S.zone = r.zone; S.zoneAmbiguous = false; out.className = 'co-zone ok'; out.textContent = 'Wir liefern zu dir. Liefergebühr ' + fmt(r.zone.fee) + ', Mindestbestellwert ' + fmt(r.zone.min) + '.'; setW3wVisible(false);
				} else if (r.reason === 'ambiguous') {
					S.zone = null; S.zoneAmbiguous = true; out.className = 'co-zone'; out.textContent = 'Mehrere passende Adressen - bitte auswählen:'; setW3wVisible(false); renderCandidates(r.candidates);
				} else {
					S.zone = null; S.zoneAmbiguous = false; out.className = 'co-zone bad'; out.textContent = r.error; setW3wVisible(r.reason === 'not_found' && r.w3w_available);
				}
				renderSummary();
			}).catch(function () { out.className = 'co-zone bad'; out.textContent = 'Die Adresse konnte gerade nicht geprüft werden.'; });
		}, 500);
	}
	// same idea as checkZone(), for a what3words code instead of street/zip/city
	var w3wTimer;
	function checkZoneW3W() {
		clearTimeout(w3wTimer);
		var w = $('#co-w3w'), out = $('#co-zone'); if (!w) { return; }
		var words = w.value.trim();
		if (!words) { S.zone = null; S.zoneWords = ''; S.w3wKey = ''; out.textContent = ''; renderSummary(); return; }
		if (words === S.w3wKey) { return; }
		out.className = 'co-zone'; out.textContent = 'Code wird geprüft ...';
		w3wTimer = setTimeout(function () {
			post('zone', { words: words }).then(function (r) {
				S.w3wKey = words;
				if (r.ok) { S.zone = r.zone; S.zoneWords = r.words || words; out.className = 'co-zone ok'; out.textContent = 'Wir liefern zu dir. Liefergebühr ' + fmt(r.zone.fee) + ', Mindestbestellwert ' + fmt(r.zone.min) + '.'; }
				else { S.zone = null; S.zoneWords = ''; out.className = 'co-zone bad'; out.textContent = r.error; }
				renderSummary();
			}).catch(function () { out.className = 'co-zone bad'; out.textContent = 'Der Code konnte gerade nicht geprüft werden.'; });
		}, 500);
	}
	function loadSlots() {
		fetch('api.php?op=slots&kind=' + S.mode, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { return; }
			S.slots = r;
			if (r.asap && !S.whenTouched) { S.when = 'asap'; } else if (!r.asap) { S.when = 'slot'; }
			var h = '';
			if (r.asap) { h += '<label class="co-radio"><input type="radio" name="whenmode" value="asap"' + (S.when === 'asap' ? ' checked' : '') + '/><span>So schnell wie möglich <small>in etwa ' + r.asap_min + ' Minuten, gegen ' + clock(r.asap_min) + ' Uhr</small></span></label>'; }
			if (r.days.length) {
				h += '<label class="co-radio"><input type="radio" name="whenmode" value="slot"' + (!r.asap || S.when !== 'asap' ? ' checked' : '') + '/><span>Zu einer Wunschzeit</span></label>' +
					'<div class="co-slots"><select name="day" aria-label="Tag">' + r.days.map(function (d) { return '<option value="' + d.date + '">' + esc(d.label) + '</option>'; }).join('') + '</select>' +
					'<select name="time" aria-label="Uhrzeit"></select></div>';
			}
			if (!r.asap && !r.days.length) { h = '<p class="co-zone bad">' + (r.paused ? (S.mode === 'delivery' ? 'Die Lieferung' : 'Die Abholung') + ' ist gerade pausiert' + (r.paused_until ? ' bis etwa ' + r.paused_until + ' Uhr' : '') + '. Schau gleich noch einmal vorbei.' : 'Zurzeit kannst du leider nicht bestellen. Schau später wieder vorbei.') + '</p>'; }
			$('#co-when').innerHTML = h;
			fillTimes();
			updateSubmit();
		});
	}
	function fillTimes() {
		var day = $('select[name=day]'), time = $('select[name=time]'); if (!day || !time || !S.slots) { return; }
		var d = S.slots.days.filter(function (x) { return x.date === day.value; })[0];
		time.innerHTML = d ? d.slots.map(function (t) { return '<option value="' + t + '">' + t + ' Uhr</option>'; }).join('') : '';
	}
	function renderPay() {
		if (!S.info) { updateSubmit(); return; }
		var opts = [];
		var tot = fmt(total());
		if (S.info.pay.online) { opts.push(['mollie', 'Online bezahlen', 'Karte, PayPal, Apple Pay und mehr. Sicher über Mollie, du bezahlst im nächsten Schritt.']); }
		if (S.info.pay.cash) { opts.push(['cash', S.mode === 'delivery' ? 'Bar bei Lieferung' : 'Bar bei Abholung', 'Bitte halte ' + tot + ' bereit, möglichst passend.']); }
		if (S.info.pay.card_door) { opts.push(['card_door', S.mode === 'delivery' ? 'Karte bei Lieferung' : 'Karte bei Abholung', 'Wir bringen das Kartengerät mit. ' + tot + ' zahlst du dort.']); }
		if (!opts.some(function (o) { return o[0] === S.pay; })) { S.pay = opts.length ? opts[0][0] : ''; }
		$('#co-pay').innerHTML = opts.map(function (o) {
			return '<label class="co-radio"><input type="radio" name="pay" value="' + o[0] + '"' + (S.pay === o[0] ? ' checked' : '') + '/><span>' + o[1] + ' <small>' + o[2] + '</small></span></label>';
		}).join('') || '<p class="co-zone bad">Zurzeit ist keine Zahlungsart verfügbar.</p>';
		updateSubmit();
	}

	// ---- coupon: a preview (the server checks the code again with the order)
	function couponMsg(t, bad) { var m = $('#co-coupon-msg'); m.textContent = t || ''; m.className = 'co-coupon-msg' + (t ? (bad ? ' bad' : ' ok') : ''); }
	function applyCoupon(code, silent) {
		code = String(code || '').trim(); if (!code) { return; }
		if (!silent) { couponMsg('Einen Moment ...', false); }
		post('coupon', { code: code, type: S.mode, phone: form.elements.phone.value, email: form.elements.email.value, lines: S.cart.map(function (l) { return { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note }; }) }).then(function (r) {
			if (r.ok) { S.coupon = { code: r.code, discount: r.discount, label: r.label }; $('#co-coupon-box').hidden = true; $('#co-coupon-toggle').hidden = true; couponMsg('Gutschein ' + r.code + ' eingelöst: ' + r.label + ', du sparst ' + fmt(r.discount) + '.', false); }
			else { S.coupon = null; $('#co-coupon-toggle').hidden = false; if (!silent) { $('#co-coupon-box').hidden = false; } couponMsg(r.error, true); }
			renderSummary();
		}).catch(function () { couponMsg('Der Gutschein konnte gerade nicht geprüft werden.', true); });
	}
	function removeCoupon() { S.coupon = null; $('#co-coupon-toggle').hidden = false; $('#co-coupon-in').value = ''; couponMsg('', false); renderSummary(); }

	// ---- events
	form.addEventListener('input', function (ev) {
		if (/^(street|zip|city)$/.test(ev.target.name)) { checkZone(); }
		if (/^(street|zip|city|name|phone)$/.test(ev.target.name)) { updateSubmit(); }
		if (ev.target.id === 'co-w3w') { checkZoneW3W(); updateSubmit(); }
	});
	(function () { var t = $('#co-w3w-toggle'), b = $('#co-w3w-box'); if (t && b) { t.addEventListener('click', function () { b.hidden = false; $('#co-w3w').focus(); }); } })();
	form.addEventListener('change', function (ev) {
		var t = ev.target;
		if (t.name === 'whenmode') { S.when = t.value === 'asap' ? 'asap' : 'slot'; S.whenTouched = true; updateSubmit(); }
		if (t.name === 'day') { fillTimes(); updateSubmit(); }
		if (t.name === 'time') { updateSubmit(); }
		if (t.name === 'pay') { S.pay = t.value; updateSubmit(); }
	});
	document.addEventListener('click', function (ev) {
		if (ev.target.closest('#co-coupon-toggle')) { var bx = $('#co-coupon-box'); bx.hidden = !bx.hidden; if (!bx.hidden) { $('#co-coupon-in').focus(); } $('#co-coupon-toggle').setAttribute('aria-expanded', bx.hidden ? 'false' : 'true'); return; }
		if (ev.target.closest('#co-coupon-go')) { applyCoupon($('#co-coupon-in').value, false); return; }
		if (ev.target.closest('#co-coupon-off')) { removeCoupon(); return; }
		var m = ev.target.closest('.mode-btn'); if (m) { setMode(m.dataset.mode); if (S.coupon) { applyCoupon(S.coupon.code, true); } return; }
		var tp = ev.target.closest('.tip-btn');
		if (tp) {
			S.tipPct = tp.dataset.pct === 'custom' ? 'custom' : +tp.dataset.pct;
			$$('.tip-btn').forEach(function (b) { b.setAttribute('aria-pressed', b === tp ? 'true' : 'false'); });
			$('#co-tip-custom').hidden = tp.dataset.pct !== 'custom';
			if (tp.dataset.pct === 'custom') { $('#co-tip-custom-in').focus(); } else { S.tipCustom = ''; }
			renderSummary();
		}
	});
	$('#co-tip-custom-in').addEventListener('input', function () { S.tipCustom = this.value; renderSummary(); });
	$('#co-coupon-in').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); applyCoupon(this.value, false); } });
	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var err = $('#co-error'); err.textContent = '';
		if (S.busy) { return; }
		var f = form.elements, when = 'asap';
		if (S.when !== 'asap') { var dd = $('select[name=day]'), tt = $('select[name=time]'); when = dd && tt && tt.value ? dd.value + ' ' + tt.value : ''; }
		if (!when) { err.textContent = 'Bitte wähle eine Zeit.'; return; }
		if (!f.name.value.trim()) { err.textContent = 'Bitte gib deinen Namen an.'; f.name.focus(); return; }
		if (!f.phone.value.trim()) { err.textContent = 'Bitte gib deine Telefonnummer an.'; f.phone.focus(); return; }
		remember();
		S.busy = true; updateSubmit(); $('#co-submit').textContent = 'Einen Moment ...';
		post('create', {
			type: S.mode, when: when, payment: S.pay, tip: tipCents(), name: f.name.value, phone: f.phone.value, email: f.email.value, note: f.note.value,
			street: f.street.value, zip: f.zip.value, city: f.city.value, words: S.zoneWords, address_note: f.address_note.value, website: f.website.value, coupon: S.coupon ? S.coupon.code : '',
			lines: S.cart.map(function (l) { return { pid: l.pid, vid: l.vid, opts: l.opts, qty: l.qty, note: l.note }; }),
			group: S.group ? S.group.token : '', me: S.group ? S.group.me : '', rev: S.group ? (S.group.rev || '') : ''
		}).then(function (r) {
			if (!r.ok) { var msg = r.error || 'Das hat nicht geklappt.'; err.textContent = msg; S.busy = false; updateSubmit(); $('#co-why').textContent = msg; err.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); return; }
			try {
				if (S.group) { localStorage.removeItem(GKEY); } // the shared basket is done; the own cart stays as it was
				else { var d = JSON.parse(localStorage.getItem(KEY) || '{}'); d.cart = []; localStorage.setItem(KEY, JSON.stringify(d)); }
			} catch (e) {}
			location.href = r.redirect;
		}).catch(function () { var msg = 'Das hat nicht geklappt. Bitte versuche es noch einmal.'; err.textContent = msg; S.busy = false; updateSubmit(); $('#co-why').textContent = msg; err.scrollIntoView({ block: 'center', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); });
	});

	load(); prefill(); accountPrefill();
	form.addEventListener('input', function (ev) { if (ev.target && (ev.target.name === 'phone' || ev.target.name === 'email')) { clearTimeout(stampTimer); stampTimer = setTimeout(refreshStamp, 600); } });
	// coming from a shared basket (checkout.php?g=...): the lines are everybody's, the organizer of that basket orders and pays
	var gTok = ''; try { gTok = new URLSearchParams(location.search).get('g') || ''; } catch (e) {}
	var gSaved = null; try { gSaved = JSON.parse(localStorage.getItem(GKEY) || 'null'); } catch (e) {}
	if (/^[a-f0-9]{24}$/.test(gTok) && gSaved && gSaved.token === gTok && /^[a-f0-9]{32}$/.test(gSaved.me)) { S.group = gSaved; S.cart = []; }
	function showEmpty() { $('#co-empty').hidden = false; $$('.co-sec').forEach(function (s) { s.hidden = true; }); }
	if (!S.cart.length && !S.group) { showEmpty(); }
	fetch('api.php?op=state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
		if (!r.ok || !r.accepting) { location.href = './'; return; }
		S.info = r;
		if (!S.group) { if (S.cart.length) { setMode(S.mode); checkZone(); } return; }
		return post('basket_view', { g: S.group.token, me: S.group.me }).then(function (v) {
			if (!v.ok || !v.joined || !v.owner || v.status === 'ordered') { location.href = './'; return; }
			S.group.rev = v.rev; // what the order is checked against: changed basket = the organizer looks again first
			v.members.forEach(function (m) { m.lines.forEach(function (l) { if (!l.unavailable) { var c = {}; Object.keys(l).forEach(function (k) { c[k] = l[k]; }); c.who = m.name; S.cart.push(c); } }); });
			if (!S.cart.length) { showEmpty(); return; }
			setMode(S.mode); checkZone(); renderSummary();
		});
	});
	renderSummary();
	try { var pc = new URLSearchParams(location.search).get('code'); if (pc && S.cart.length) { $('#co-coupon-in').value = pc; applyCoupon(pc, false); } } catch (e) {}
})();
