/*
 * Reservation forms (new / edit): phone check, guest stepper, table picker, submit checks.
 * Plain JavaScript; texts come from window.RSV (set by the form), tables from ajax/tp.php.
 */
(function () {
	'use strict';
	var L = window.RSV || {};
	var form = document.getElementById('new_reservation_form');
	if (!form) { return; }

	function byId(id) { return document.getElementById(id); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		(kids || []).forEach(function (c) { if (c) { e.appendChild(c); } });
		return e;
	}

	/* ---------- phone ---------- */
	function validPhone(v) {
		v = (v || '').trim();
		if (!v) { return true; }
		if (!/^\+?[0-9\s().\/\-]+$/.test(v)) { return false; }
		var d = v.replace(/\D/g, '');
		return d.length >= 6 && d.length <= 15;
	}
	var phone = byId('reservation_guest_phone'), phoneMsg = byId('rsv-phone-msg');
	function checkPhone(show) {
		var ok = validPhone(phone.value);
		phone.classList.toggle('is-invalid', !ok);
		if (phoneMsg) { phoneMsg.textContent = (!ok && show) ? L.phoneBad : ''; }
		return ok;
	}
	if (phone) {
		phone.addEventListener('input', function () { if (typeof syncMail === 'function') { syncMail(); } if (phone.classList.contains('is-invalid')) { checkPhone(true); } });
		phone.addEventListener('blur', function () { checkPhone(true); });
	}

	/* ---------- email (the confirmation is an opt-in that needs a valid address) ---------- */
	function validEmail(v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test((v || '').trim()); }
	var email = byId('reservation_guest_email'), emailMsg = byId('rsv-email-msg'), mailBox = byId('rsv-mail-confirm');
	function checkEmail(show) {
		var v = (email.value || '').trim(), ok = v === '' || validEmail(v);
		email.classList.toggle('is-invalid', !ok);
		if (emailMsg) { emailMsg.textContent = (!ok && show) ? L.emailBad : ''; }
		return ok;
	}
	// with SMS on, a German/Austrian mobile number is enough for a confirmation too
	function isMobile(v) {
		var d = String(v || '').replace(/\(\s*0\s*\)/g, '').replace(/\D/g, '');
		if (d.indexOf('00') === 0) { d = d.slice(2); } else if (d.charAt(0) === '0') { d = '49' + d.slice(1); }
		d = d.replace(/^(49|43)0+/, '$1');
		return /^491[567]\d{8,9}$/.test(d) || /^436\d{7,11}$/.test(d);
	}
	function syncMail() {
		if (!mailBox) { return; }
		var can = validEmail(email.value) || (mailBox.getAttribute('data-sms') === '1' && phone && isMobile(phone.value));
		var was = !mailBox.disabled;
		mailBox.disabled = !can;
		if (!can) { mailBox.checked = false; }
		// with SMS on the confirmation is the default as soon as it is possible; unticking it by hand is respected
		else if (!was && mailBox.getAttribute('data-sms') === '1' && !mailBox.getAttribute('data-touched')) { mailBox.checked = true; }
	}
	if (mailBox) { mailBox.addEventListener('change', function () { mailBox.setAttribute('data-touched', '1'); }); }
	if (email) {
		email.addEventListener('input', function () { syncMail(); if (email.classList.contains('is-invalid')) { checkEmail(true); } });
		email.addEventListener('blur', function () { checkEmail(true); });
		syncMail();
	}

	/* ---------- guests stepper ---------- */
	var pax = byId('reservation_pax');
	Array.prototype.forEach.call(form.querySelectorAll('.rsv-stepper button'), function (b) {
		b.addEventListener('click', function () {
			var v = (parseInt(pax.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10);
			pax.value = Math.max(1, Math.min(99, v));
			pax.dispatchEvent(new Event('change', { bubbles: true }));
		});
	});

	/* ---------- table picker ---------- */
	var box = byId('rsv-tables');
	var timeSel = byId('reservation_time');
	if (box && timeSel) {
		var chips = byId('rsv-chips'), msg = byId('rsv-tables-msg'), inputs = byId('rsv-tables-inputs'), areaSel = byId('rsv-area');
		var st = { data: null, sel: {}, show: 'free', area: 0, timer: null, selAny: false };
		(box.getAttribute('data-selected') || '').split(',').forEach(function (i) { if (i) { st.sel[i] = true; } });

		var call = function (payload) {
			return fetch('ajax/tp.php', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-TP-Token': box.getAttribute('data-token') },
				body: JSON.stringify(payload)
			}).then(function (r) { return r.json(); });
		};
		var tableById = function (id) {
			var t = st.data && st.data.tables;
			for (var i = 0; t && i < t.length; i++) { if (String(t[i].table_id) === String(id)) { return t[i]; } }
			return null;
		};
		var selectedList = function () {
			return Object.keys(st.sel).filter(function (k) { return st.sel[k] && tableById(k); }).map(tableById);
		};
		var anySelected = function () { return Object.keys(st.sel).some(function (k) { return st.sel[k]; }); };

		var syncInputs = function () {
			while (inputs.firstChild) { inputs.removeChild(inputs.firstChild); }
			Object.keys(st.sel).forEach(function (k) {
				if (st.sel[k]) { inputs.appendChild(el('input', { type: 'hidden', name: 'tp_tables[]', value: k })); }
			});
		};

		var setMsg = function (text, cls) { msg.textContent = text || ''; msg.className = 'rsv-tables-msg' + (cls ? ' ' + cls : ''); };

		var updateMsg = function () {
			if (!st.data) { return; }
			if (!st.data.tables.length) { setMsg(L.none, ''); return; }
			var need = parseInt(pax.value, 10) || 0, list = selectedList();
			if (!list.length) {
				var names = (st.data.auto || []).map(function (id) { var t = tableById(id); return t ? t.name : ''; }).filter(Boolean);
				setMsg(L.tablesAuto + (names.length ? ' ' + L.suggest + ': ' + names.join(' + ') + '.' : ''), '');
				return;
			}
			var seats = list.reduce(function (s, t) { return s + t.seats; }, 0);
			if (list.some(function (t) { return t.state === 'busy'; })) { setMsg(L.fitBusy, 'is-warn'); }
			else if (seats < need) { setMsg((L.fitSeats || '').replace('%d', seats), 'is-warn'); }
			else { setMsg(L.fitOk, 'is-ok'); }
		};

		var render = function () {
			while (chips.firstChild) { chips.removeChild(chips.firstChild); }
			var d = st.data;
			if (!d) { return; }
			var need = parseInt(pax.value, 10) || 0;
			d.tables.forEach(function (t) {
				var selected = !!st.sel[t.table_id];
				if (st.area && t.area_id !== st.area) { return; }
				if (st.show === 'free' && t.state !== 'free' && !selected) { return; }
				var cls = 'rsv-chip' + (selected ? ' is-sel' : '') + (t.state === 'busy' ? ' is-busy' : '') + (t.state === 'closed' ? ' is-closed' : '') +
					(t.state === 'free' && t.seats >= need ? ' is-fit' : '') + ((!st.selAny && (d.auto || []).indexOf(t.table_id) >= 0) ? ' is-suggest' : '');
				var title = t.name + ' · ' + t.seats + ' ' + (L.seats || '') + (t.state === 'busy' ? ' – ' + L.busy + ': ' + t.by : '') + (t.state === 'closed' ? ' – ' + L.closed : '');
				var b = el('button', { type: 'button', 'class': cls, title: title, 'aria-pressed': selected ? 'true' : 'false' }, [
					el('span', { 'class': 'rsv-chip-name', text: t.name }),
					el('span', { 'class': 'rsv-chip-seats', text: String(t.seats) })
				]);
				if (t.state === 'closed') { b.disabled = true; }
				b.addEventListener('click', function () {
					st.sel[t.table_id] = !st.sel[t.table_id];
					st.selAny = anySelected();
					syncInputs(); render();
				});
				chips.appendChild(b);
			});
			updateMsg();
		};

		var fillAreas = function () {
			var cur = areaSel.value;
			while (areaSel.options.length > 1) { areaSel.remove(1); }
			st.data.areas.forEach(function (a) { areaSel.appendChild(el('option', { value: String(a.area_id), text: a.area_name })); });
			areaSel.value = cur;
			areaSel.style.display = st.data.areas.length > 1 ? '' : 'none';
		};

		var load = function () {
			var time = timeSel.value;
			if (!time) { st.data = null; while (chips.firstChild) { chips.removeChild(chips.firstChild); } setMsg(L.pickTime, ''); return; }
			setMsg(L.loading, '');
			call({ action: 'free_tables', date: box.getAttribute('data-date'), time: time, pax: parseInt(pax.value, 10) || 0, reservation_id: parseInt(box.getAttribute('data-res'), 10) || 0 })
				.then(function (r) {
					if (!r.ok) { setMsg(r.error || 'Tische konnten nicht geladen werden', 'is-warn'); return; }
					st.data = r;
					st.selAny = anySelected();
					fillAreas(); syncInputs(); render();
				}, function () { setMsg('Server nicht erreichbar', 'is-warn'); });
		};
		var later = function () { clearTimeout(st.timer); st.timer = setTimeout(load, 200); };

		timeSel.addEventListener('change', later);
		pax.addEventListener('input', later);
		pax.addEventListener('change', later);
		areaSel.addEventListener('change', function () { st.area = parseInt(areaSel.value, 10) || 0; render(); });
		Array.prototype.forEach.call(box.querySelectorAll('.rsv-seg button'), function (b) {
			b.addEventListener('click', function () {
				st.show = b.getAttribute('data-show');
				Array.prototype.forEach.call(box.querySelectorAll('.rsv-seg button'), function (x) { x.classList.toggle('is-on', x === b); });
				render();
			});
		});
		syncInputs();
		load();
	}

	/* ---------- submit checks ---------- */
	function invalid(node, on) { if (node) { node.classList.toggle('is-invalid', on); } }
	form.addEventListener('submit', function (e) {
		var problems = [], first = null;
		var staff = byId('reservation_booker_name');
		function need(cond, node, text) { invalid(node, !cond); if (!cond) { problems.push(text); if (!first) { first = node; } } }
		need(!!timeSel.value, timeSel, L.needTime);
		need((parseInt(pax.value, 10) || 0) >= 1, pax, L.needPax);
		need((byId('reservation_guest_name').value || '').trim().length >= 2, byId('reservation_guest_name'), L.needName);
		need(checkPhone(true), phone, L.phoneBad);
		if (email) { need(checkEmail(true), email, L.emailBad); }
		if (staff && staff.type !== 'hidden' && !staff.disabled) { need((staff.value || '').trim().length >= 3, staff, L.needStaff); }
		var formMsg = byId('rsv-form-msg');
		if (problems.length) {
			e.preventDefault();
			e.stopImmediatePropagation();
			if (formMsg) { formMsg.textContent = problems[0]; }
			var det = byId('rsv-details');
			if (det && first && det.contains(first)) { det.open = true; }
			if (first && first.focus) { first.focus(); }
			return;
		}
		if (formMsg) { formMsg.textContent = ''; }
	}, true);
})();
