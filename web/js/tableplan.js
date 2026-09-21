/*
 * Table plan: viewer with day assignment (reservations <-> tables) and, for administrators,
 * the plan editor (areas, tables, links, area closures). Plain JavaScript, talks to ajax/tp.php.
 * The plan lives on a fixed logical canvas (TP.canvasW x TP.canvasH) that is scaled to the
 * available width; all coordinates are logical pixels on a 10px grid.
 */
(function () {
	'use strict';
	var cfg = window.TP;
	if (!cfg) { return; }

	var GRID = 10;
	var st = {
		areas: [], area: null, tables: [], links: [], closures: [], autoAssign: true,
		availabilityMode: 'counter', counter: null,
		dayVer: 0, previewOpen: false, previewPax: 2, preview: null,
		selected: null,          // table_id (editor)
		edit: false, linkMode: false, linkFirst: null,
		date: cfg.date,          // day shown in the assignment view (YYYY-MM-DD)
		day: null,               // { dur, closed[], reservations[] }
		selRes: null,            // reservation_id
		time: '',                // "HH:MM" occupancy filter, '' = whole day
		scale: 1
	};

	var tabs   = document.getElementById('tp-tabs');
	var stage  = document.getElementById('tp-stage');
	var canvas = document.getElementById('tp-canvas');
	var svg    = document.getElementById('tp-links');
	var panel  = document.getElementById('tp-panel');
	var msg    = document.getElementById('tp-msg');
	var toggle = document.getElementById('tp-mode-toggle');
	var legend = el('div', { 'class': 'tp-legend', 'aria-label': 'Legende' });
	stage.parentNode.insertBefore(legend, stage);

	/* ---------- helpers ---------- */
	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (attrs[k] === null) { return; }
				if (k === 'text') { e.textContent = attrs[k]; }
				else if (k === 'class') { e.className = attrs[k]; }
				else if (k.indexOf('on') === 0) { e.addEventListener(k.slice(2), attrs[k]); }
				else { e.setAttribute(k, attrs[k]); }
			});
		}
		(children || []).forEach(function (c) { if (c) { e.appendChild(c); } });
		return e;
	}
	function snap(v) { return Math.round(v / GRID) * GRID; }
	function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
	function toMin(t) { var p = String(t).split(':'); return parseInt(p[0], 10) * 60 + (parseInt(p[1], 10) || 0); }
	function fmtDate(s) { var p = String(s).split('-'); return p.length === 3 ? p[2] + '.' + p[1] + '.' + p[0] : s; }
	function addDays(s, n) {
		var p = s.split('-'), d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + n));
		return d.getUTCFullYear() + '-' + ('0' + (d.getUTCMonth() + 1)).slice(-2) + '-' + ('0' + d.getUTCDate()).slice(-2);
	}
	function byId(id) {
		for (var i = 0; i < st.tables.length; i++) { if (st.tables[i].table_id === id) { return st.tables[i]; } }
		return null;
	}
	function say(text, isError) {
		msg.textContent = text || '';
		msg.className = 'tp-msg' + (isError ? ' tp-msg-error' : '');
		if (text && !isError) { setTimeout(function () { if (msg.textContent === text) { msg.textContent = ''; } }, 2500); }
	}
	function api(action, payload) {
		var body = { action: action };
		Object.keys(payload || {}).forEach(function (k) { body[k] = payload[k]; });
		return fetch('ajax/tp.php', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-TP-Token': cfg.token },
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (j) { j._status = r.status; return j; }, function () { return { ok: false, error: 'Ungültige Antwort des Servers' }; });
		}, function () { return { ok: false, error: 'Server nicht erreichbar' }; });
	}
	function normalize(t) {
		['table_id', 'area_id', 'seats', 'x', 'y', 'w', 'h', 'rot', 'active'].forEach(function (k) { t[k] = parseInt(t[k], 10); });
		return t;
	}
	function normLinks(list) {
		return list.map(function (l) { return { table_a: parseInt(l.table_a, 10), table_b: parseInt(l.table_b, 10) }; });
	}
	function normAreas(list) {
		return list.map(function (a) { return { area_id: parseInt(a.area_id, 10), area_name: a.area_name }; });
	}
	function normClosures(list) {
		return list.map(function (c) {
			return { closure_id: parseInt(c.closure_id, 10), area_id: parseInt(c.area_id, 10), date_from: c.date_from, date_to: c.date_to, yearly: parseInt(c.yearly, 10), note: c.note };
		});
	}
	function areaById(id) {
		for (var i = 0; i < st.areas.length; i++) { if (st.areas[i].area_id === id) { return st.areas[i]; } }
		return null;
	}
	function tablesOfArea(id) { return st.tables.filter(function (t) { return t.area_id === id; }); }
	function seatsOf(list) { return list.reduce(function (s, t) { return s + t.seats; }, 0); }
	function tableData(t, over) {
		var d = { table_id: t.table_id, area_id: t.area_id, table_name: t.table_name, seats: t.seats, shape: t.shape, x: t.x, y: t.y, w: t.w, h: t.h, rot: t.rot, active: t.active };
		Object.keys(over || {}).forEach(function (k) { d[k] = over[k]; });
		return d;
	}

	/* ---------- day data ---------- */
	function setDay(payload) {
		st.dayVer++;
		st.day = {
			dur: payload.dur,
			open: payload.open,
			close: payload.close,
			closed: payload.closed.map(function (x) { return parseInt(x, 10); }),
			reservations: payload.reservations.map(function (r) {
				r.tables = r.tables.map(function (x) { return parseInt(x, 10); });
				r.min = (typeof r.min === 'number') ? r.min : toMin(r.time);
				return r;
			})
		};
		if (st.selRes && !resById(st.selRes)) { st.selRes = null; }
	}
	function resById(id) {
		if (!st.day) { return null; }
		for (var i = 0; i < st.day.reservations.length; i++) { if (st.day.reservations[i].id === id) { return st.day.reservations[i]; } }
		return null;
	}
	function loadDay() {
		return api('day', { date: st.date }).then(function (r) {
			if (!r.ok) { say(r.error || 'Tag konnte nicht geladen werden', true); return; }
			setDay(r);
			render();
		});
	}
	function isClosed(areaId) { return !!st.day && st.day.closed.indexOf(areaId) >= 0; }
	function asgOf(tid) {
		if (!st.day) { return []; }
		return st.day.reservations.filter(function (r) { return r.tables.indexOf(tid) >= 0; });
	}
	function overlaps(a, b) { return Math.abs(a.min - b.min) < st.day.dur; }
	// does a reservation overlap another one of the same table (double booking)?
	function clashes(r, list) {
		return list.some(function (o) { return o.id !== r.id && overlaps(o, r); });
	}
	// occupancy of a table for the whole day: '' free, taken (one), multi (one after another), clash (overlap)
	function occState(t) {
		var list = asgOf(t.table_id);
		if (!list.length) { return ''; }
		if (list.some(function (r) { return clashes(r, list); })) { return ' is-clash'; }
		return list.length > 1 ? ' is-multi' : ' is-taken';
	}
	// strip over the opening hours with one bar per reservation (start until the end of the stay)
	function timeline(list) {
		var d = st.day, span = Math.max(60, d.close - d.open);
		var bar = el('span', { 'class': 'tp-tl', 'aria-hidden': 'true' });
		list.forEach(function (r) {
			// reservations outside the opening hours (before opening, after midnight) stay visible at the edge
			var s = Math.min(Math.max(d.open, r.min), d.close - 1), e = Math.max(s + 1, Math.min(d.close, r.min + d.dur));
			var seg = el('span', { 'class': 'tp-tl-seg' + (clashes(r, list) ? ' is-clash' : '') });
			seg.style.left = Math.min(95, (s - d.open) / span * 100) + '%';
			seg.style.width = Math.max(5, (e - s) / span * 100) + '%';
			bar.appendChild(seg);
		});
		return bar;
	}

	/* ---------- scaling ---------- */
	// Viewing: zoom onto the tables of the floor (a tall, narrow floor gets big, readable tables).
	// Editing and closed floors show the whole canvas.
	function viewBox() {
		var full = { x: 0, y: 0, w: cfg.canvasW, h: cfg.canvasH };
		if (st.edit || isClosed(st.area)) { return full; }
		var list = tablesOfArea(st.area);
		if (!list.length) { return full; }
		var x1 = Infinity, y1 = Infinity, x2 = 0, y2 = 0, pad = 30;
		list.forEach(function (t) {
			// a rotated table can reach beyond its own box
			var d = t.rot % 180 ? Math.abs(t.w - t.h) / 2 : 0;
			x1 = Math.min(x1, t.x - d); y1 = Math.min(y1, t.y - d);
			x2 = Math.max(x2, t.x + t.w + d); y2 = Math.max(y2, t.y + t.h + d);
		});
		x1 = Math.max(0, x1 - pad); y1 = Math.max(0, y1 - pad);
		x2 = Math.min(cfg.canvasW, x2 + pad); y2 = Math.min(cfg.canvasH, y2 + pad);
		return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
	}
	function rescale() {
		var b = viewBox(), sw = stage.clientWidth, s = sw / cfg.canvasW;
		if (b.w < cfg.canvasW || b.h < cfg.canvasH) {
			// as large as the width allows, but at most ~1.2 screens tall and 1.4x
			s = Math.max(s, Math.min(sw / b.w, 1.2 * window.innerHeight / b.h, 1.4));
		}
		st.scale = s;
		var ox = Math.max(0, Math.min(b.x + b.w / 2 - sw / s / 2, cfg.canvasW - sw / s));
		canvas.style.transform = 'scale(' + s + ') translate(' + (-ox) + 'px,' + (-b.y) + 'px)';
		stage.style.height = Math.round(b.h * s) + 'px';
	}
	window.addEventListener('resize', rescale);

	/* ---------- rendering: canvas ---------- */
	function linkedIds(id) {
		var out = [];
		st.links.forEach(function (l) {
			if (l.table_a === id) { out.push(l.table_b); }
			else if (l.table_b === id) { out.push(l.table_a); }
		});
		return out;
	}

	function renderLinks() {
		while (svg.firstChild) { svg.removeChild(svg.firstChild); }
		st.links.forEach(function (l) {
			var a = byId(l.table_a), b = byId(l.table_b);
			if (!a || !b || a.area_id !== st.area) { return; }
			var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
			line.setAttribute('x1', a.x + a.w / 2); line.setAttribute('y1', a.y + a.h / 2);
			line.setAttribute('x2', b.x + b.w / 2); line.setAttribute('y2', b.y + b.h / 2);
			line.setAttribute('class', 'tp-link-line');
			svg.appendChild(line);
		});
	}

	// state classes of a table in the assignment view
	function tableState(t) {
		var cls = '';
		if (isClosed(t.area_id)) { return ' is-closed'; }
		var sel = st.selRes ? resById(st.selRes) : null;
		if (sel) {
			if (sel.tables.indexOf(t.table_id) >= 0) { return ' is-mine'; }
			var busy = asgOf(t.table_id).some(function (o) { return o.id !== sel.id && overlaps(o, sel); });
			cls = busy ? ' is-busy' : (t.seats < sel.pax ? ' is-small' : ' is-fit');
			return cls;
		}
		if (st.time !== '') {
			var T = toMin(st.time);
			if (asgOf(t.table_id).some(function (o) { return o.min <= T && T < o.min + st.day.dur; })) { cls = ' is-occupied'; }
			return cls;
		}
		return occState(t);
	}

	function tableNode(t) {
		var view = !st.edit;
		var cls = 'tp-table' + (t.shape === 'round' ? ' is-round' : '') + (st.edit && st.selected === t.table_id ? ' is-selected' : '') +
			(st.linkMode && st.linkFirst === t.table_id ? ' is-link-first' : '') + (view ? tableState(t) : '');
		var lines = [
			el('span', { 'class': 'tp-name', text: t.table_name }),
			el('span', { 'class': 'tp-seats', text: t.seats + ' Pl.' })
		];
		var all = (view && st.day) ? asgOf(t.table_id) : [];
		if (view && st.day) {
			var list = all;
			if (st.time !== '') {
				var T = toMin(st.time);
				list = list.filter(function (o) { return o.min <= T && T < o.min + st.day.dur; });
			}
			if (Math.min(t.w, t.h) < 110) {
				// small table: one compact line
				if (list.length === 1) {
					lines.push(el('span', { 'class': 'tp-asg', text: list[0].time + ' ' + list[0].name + ' · ' + list[0].pax }));
				} else if (list.length > 1) {
					lines.push(el('span', { 'class': 'tp-asg', text: list.slice(0, 3).map(function (o) { return o.time; }).join(' · ') + (list.length > 3 ? ' …' : '') }));
				}
			} else {
				list.slice(0, 2).forEach(function (o) {
					lines.push(el('span', { 'class': 'tp-asg', text: o.time + ' ' + o.name + ' · ' + o.pax }));
				});
				if (list.length > 2) { lines.push(el('span', { 'class': 'tp-asg', text: '+' + (list.length - 2) + ' weitere' })); }
			}
			if (all.length && !isClosed(t.area_id)) { lines.push(timeline(all)); }
		}
		var title = t.table_name + ' (' + t.seats + ' Plätze)';
		if (all.length) {
			title += ': ' + all.map(function (r) { return r.time + ' ' + r.name + ' (' + r.pax + ')'; }).join(', ');
			if (all.some(function (r) { return clashes(r, all); })) { title += ' – Überschneidung, Tisch ist doppelt belegt'; }
			else if (all.length > 1) { title += ' – nacheinander belegt'; }
		}
		var node = el('div', {
			'class': cls, 'data-id': String(t.table_id), role: 'button', tabindex: '0', title: title,
			'aria-label': title
		}, [el('span', { 'class': 'tp-inner', style: 'transform:rotate(' + (-t.rot) + 'deg)' }, lines)]);
		if (view && all.length > 1 && !isClosed(t.area_id)) {
			node.appendChild(el('span', { 'class': 'tp-badge', text: all.some(function (r) { return clashes(r, all); }) ? '⚠ ' + all.length + '×' : all.length + '×' }));
		}
		node.style.left = t.x + 'px';
		node.style.top = t.y + 'px';
		node.style.width = t.w + 'px';
		node.style.height = t.h + 'px';
		node.style.transform = 'rotate(' + t.rot + 'deg)';
		if (st.edit && !st.linkMode && st.selected === t.table_id) {
			node.appendChild(el('span', { 'class': 'tp-handle', 'data-handle': '1', title: 'Größe ändern' }));
		}
		return node;
	}

	function closureNote(areaId) {
		var text = 'Bereich am ' + fmtDate(st.date) + ' gesperrt';
		st.closures.forEach(function (c) {
			if (c.area_id === areaId && c.note && closureHits(c, st.date)) { text += ' – ' + c.note; }
		});
		return el('div', { 'class': 'tp-closed-note', text: text });
	}

	// same rule as tp_closure_hits() on the server
	function closureHits(c, date) {
		if (date < c.date_from) { return false; }
		if (!c.date_to) { return true; }
		if (!c.yearly) { return date <= c.date_to; }
		var md = date.slice(5), f = c.date_from.slice(5), t = c.date_to.slice(5);
		return f <= t ? (md >= f && md <= t) : (md >= f || md <= t);
	}

	function render() {
		Array.prototype.slice.call(canvas.querySelectorAll('.tp-table, .tp-closed-note')).forEach(function (n) { canvas.removeChild(n); });
		tablesOfArea(st.area).forEach(function (t) { canvas.appendChild(tableNode(t)); });
		if (!st.edit && isClosed(st.area)) { canvas.appendChild(closureNote(st.area)); }
		renderLinks();
		canvas.classList.toggle('is-edit', st.edit);
		canvas.classList.toggle('is-linking', st.linkMode);
		rescale();
		renderTabs();
		renderLegend();
		renderPanel();
	}

	function renderLegend() {
		while (legend.firstChild) { legend.removeChild(legend.firstChild); }
		legend.style.display = st.edit ? 'none' : '';
		[['is-free', 'Frei'], ['is-taken', 'Belegt'], ['is-multi', 'Nacheinander belegt'], ['is-clash', 'Überschneidung (doppelt belegt)']].forEach(function (i) {
			legend.appendChild(el('span', { 'class': 'tp-legend-item' }, [el('span', { 'class': 'tp-swatch ' + i[0] }), el('span', { text: i[1] })]));
		});
		legend.appendChild(el('span', { 'class': 'tp-legend-hint', text: 'Der Balken im Tisch zeigt die Belegung über die Öffnungszeit.' }));
	}

	function selectArea(id) {
		st.area = id; st.selected = null; st.linkMode = false; st.linkFirst = null;
		render();
	}

	function renderTabs() {
		while (tabs.firstChild) { tabs.removeChild(tabs.firstChild); }
		st.areas.forEach(function (a) {
			var n = tablesOfArea(a.area_id).length;
			var closed = !st.edit && isClosed(a.area_id);
			tabs.appendChild(el('button', {
				type: 'button', role: 'tab', 'class': 'tp-tab' + (a.area_id === st.area ? ' is-active' : '') + (closed ? ' is-closed' : ''),
				'aria-selected': a.area_id === st.area ? 'true' : 'false',
				title: closed ? 'An diesem Tag gesperrt' : null,
				onclick: function () { selectArea(a.area_id); }
			}, [el('span', { text: a.area_name }), el('span', { 'class': 'tp-tab-count', text: closed ? 'gesperrt' : String(n) })]));
		});
		if (st.edit) {
			tabs.appendChild(el('button', { type: 'button', 'class': 'tp-tab tp-tab-add', text: '+ Bereich', onclick: addArea }));
		}
	}

	/* ---------- rendering: side panel ---------- */
	function field(label, input) {
		return el('label', { 'class': 'tp-field' }, [el('span', { text: label }), input]);
	}
	function btn(text, cls, fn, extra) {
		var a = { 'class': cls, type: 'button', text: text, onclick: fn };
		Object.keys(extra || {}).forEach(function (k) { a[k] = extra[k]; });
		return el('button', a);
	}

	function renderPanel() {
		while (panel.firstChild) { panel.removeChild(panel.firstChild); }
		if (!st.edit) { renderDayPanel(); return; }
		renderEditPanel();
	}

	/* --- assignment view --- */
	function renderDayPanel() {
		var d = st.day;
		var dateIn = el('input', { type: 'date', value: st.date, 'aria-label': 'Datum' });
		dateIn.addEventListener('change', function () { if (dateIn.value) { setDate(dateIn.value); } });
		panel.appendChild(el('div', { 'class': 'tp-daynav' }, [
			btn('‹', 'button_dark', function () { setDate(addDays(st.date, -1)); }, { 'aria-label': 'Vortag', title: 'Vortag' }),
			dateIn,
			btn('›', 'button_dark', function () { setDate(addDays(st.date, 1)); }, { 'aria-label': 'Folgetag', title: 'Folgetag' }),
			btn('Heute', 'button_dark', function () { setDate(cfg.today); })
		]));
		if (!d) { panel.appendChild(el('p', { 'class': 'tp-hint', text: 'Lade …' })); return; }

		var open = d.reservations.filter(function (r) { return !r.tables.length; }).length;
		var here = tablesOfArea(st.area);
		panel.appendChild(el('p', { 'class': 'tp-summary', text: d.reservations.length + ' Reservierungen, ' + open + ' ohne Tisch' }));

		var times = [];
		d.reservations.forEach(function (r) { if (times.indexOf(r.time) < 0) { times.push(r.time); } });
		var sel = el('select', { 'aria-label': 'Belegung zu einer Uhrzeit' }, [el('option', { value: '', text: 'Belegung: ganztägig' })].concat(
			times.map(function (t) { return el('option', { value: t, text: 'Belegung um ' + t + ' Uhr' }); })));
		sel.value = st.time;
		sel.addEventListener('change', function () { st.time = sel.value; render(); });
		panel.appendChild(sel);

		if (st.tables.length && open) {
			panel.appendChild(btn('Automatisch zuweisen (' + open + ')', 'button_dark tp-primary', autoDay));
		}
		if (!st.tables.length) {
			panel.appendChild(el('p', { 'class': 'tp-hint', text: cfg.canEdit ? 'Noch keine Tische angelegt. Mit „Plan bearbeiten“ kannst du den Plan aufbauen.' : 'Noch keine Tische angelegt.' }));
		}

		if (st.tables.length) { panel.appendChild(previewBlock()); }

		var list = el('div', { 'class': 'tp-reslist', role: 'list' });
		d.reservations.forEach(function (r) { list.appendChild(resCard(r)); });
		if (!d.reservations.length) { list.appendChild(el('p', { 'class': 'tp-hint', text: 'Keine Reservierungen an diesem Tag.' })); }
		panel.appendChild(list);
		panel.appendChild(el('p', { 'class': 'tp-hint', text: 'Reservierung anklicken, dann Tische im Plan anklicken – oder die Karte auf einen Tisch ziehen.' }));
		panel.appendChild(el('p', { 'class': 'tp-hint', text: here.length + ' Tische, ' + seatsOf(here) + ' Sitzplätze in diesem Bereich' + (st.areas.length > 1 ? ' (gesamt ' + st.tables.length + ' Tische, ' + seatsOf(st.tables) + ' Plätze)' : '') }));
	}

	function resCard(r) {
		var names = r.tables.map(byId).filter(Boolean).map(function (t) { return t.table_name; }).join(' + ');
		var selected = st.selRes === r.id;
		var cls = 'tp-res' + (selected ? ' is-selected' : '') + (!r.tables.length ? ' is-open' : '') + (r.conflicts.length ? ' has-conflict' : '');
		var card = el('div', { 'class': cls, draggable: 'true', 'data-id': String(r.id), role: 'listitem', tabindex: '0' }, [
			el('div', { 'class': 'tp-res-head' }, [
				el('span', { 'class': 'tp-res-time', text: r.time }),
				el('span', { 'class': 'tp-res-name', text: r.name || '–' }),
				el('span', { 'class': 'tp-res-pax', text: r.pax + ' P.' })
			]),
			el('div', { 'class': 'tp-res-tables', text: names || 'nicht zugewiesen' })
		]);
		card.addEventListener('click', function () { selectRes(selected ? null : r.id); });
		card.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); selectRes(selected ? null : r.id); } });
		card.addEventListener('dragstart', function (e) {
			e.dataTransfer.setData('text/plain', String(r.id));
			e.dataTransfer.effectAllowed = 'copy';
			st.dragRes = r.id;
		});
		card.addEventListener('dragend', function () { st.dragRes = null; });

		if (selected) {
			var detail = el('div', { 'class': 'tp-res-detail' });
			if (r.notes) { detail.appendChild(el('p', { 'class': 'tp-res-notes', text: r.notes })); }
			r.conflicts.forEach(function (c) { detail.appendChild(el('p', { 'class': 'tp-conflict', text: '⚠ ' + c })); });
			var chips = el('div', { 'class': 'tp-linkbox' });
			r.tables.forEach(function (id) {
				var t = byId(id);
				if (!t) { return; }
				chips.appendChild(el('span', { 'class': 'tp-chip' }, [
					el('span', { text: t.table_name + ' (' + t.seats + ')' }),
					el('button', { type: 'button', title: 'Tisch entfernen', 'aria-label': 'Tisch ' + t.table_name + ' entfernen', text: '×',
						onclick: function (e) { e.stopPropagation(); toggleTable(r.id, id); } })
				]));
			});
			detail.appendChild(chips);
			var acts = el('div', { 'class': 'tp-actions' }, [
				btn(r.tables.length ? 'Neu zuweisen' : 'Automatisch', 'button_dark', function (e) { e.stopPropagation(); autoOne(r.id, !!r.tables.length); })
			]);
			if (r.tables.length) {
				acts.appendChild(btn('Entfernen', 'tp-danger', function (e) { e.stopPropagation(); assignTables(r.id, [], false); }));
			}
			detail.appendChild(acts);
			detail.addEventListener('click', function (e) { e.stopPropagation(); });
			card.appendChild(detail);
		}
		return card;
	}

	/* --- editor --- */
	function renderEditPanel() {
		var sel = st.selected ? byId(st.selected) : null;

		if (st.linkMode) {
			var first = st.linkFirst ? byId(st.linkFirst) : null;
			panel.appendChild(el('h3', { text: 'Tische verbinden' }));
			panel.appendChild(el('p', { 'class': 'tp-hint', text: first
				? 'Jetzt den Tisch anklicken, der neben „' + first.table_name + '“ steht: Er wird verbunden und ist danach der Ausgangspunkt für den nächsten (Kette). Nochmaliges Verbinden hebt die Verbindung auf, ein Klick auf „' + first.table_name + '“ beendet die Kette.'
				: 'Die Tische der Reihe nach anklicken, z. B. 131, 132, 133, 134, 135: Jeder wird mit dem vorherigen verbunden.' }));
			panel.appendChild(btn('Fertig', 'button_dark', function () { st.linkMode = false; st.linkFirst = null; render(); }));
			return;
		}

		if (!sel) { renderAreaPanel(); return; }

		panel.appendChild(el('h3', { text: 'Tisch bearbeiten' }));
		var name = el('input', { type: 'text', value: sel.table_name, maxlength: '40' });
		var seatsIn = el('input', { type: 'number', value: String(sel.seats), min: '1', max: '99' });
		var area = el('select', {}, st.areas.map(function (a) { return el('option', { value: String(a.area_id), text: a.area_name }); }));
		area.value = String(sel.area_id);
		var shape = el('select', {}, [el('option', { value: 'rect', text: 'Rechteckig' }), el('option', { value: 'round', text: 'Rund' })]);
		shape.value = sel.shape;
		var rot = el('select', {}, [0, 45, 90, 135].map(function (d) { return el('option', { value: String(d), text: d + '°' }); }));
		rot.value = String(sel.rot % 180);

		function save() {
			var moved = parseInt(area.value, 10) !== sel.area_id;
			persist(tableData(sel, { table_name: name.value, seats: seatsIn.value, area_id: area.value, shape: shape.value, rot: rot.value }), moved);
		}
		[name, seatsIn, area, shape, rot].forEach(function (i) { i.addEventListener('change', save); });

		panel.appendChild(field('Name', name));
		panel.appendChild(field('Sitzplätze', seatsIn));
		panel.appendChild(field('Bereich', area));
		panel.appendChild(field('Form', shape));
		panel.appendChild(field('Drehung', rot));

		var links = linkedIds(sel.table_id);
		var linkBox = el('div', { 'class': 'tp-linkbox' }, [el('span', { 'class': 'tp-linkbox-title', text: 'Kann zusammengestellt werden mit' })]);
		if (!links.length) { linkBox.appendChild(el('span', { 'class': 'tp-hint', text: 'keinem Tisch' })); }
		links.forEach(function (id) {
			var o = byId(id);
			if (!o) { return; }
			linkBox.appendChild(el('span', { 'class': 'tp-chip' }, [
				el('span', { text: o.table_name }),
				el('button', { type: 'button', title: 'Verbindung entfernen', 'aria-label': 'Verbindung zu ' + o.table_name + ' entfernen', text: '×', onclick: function () { toggleLink(sel.table_id, id); } })
			]));
		});
		panel.appendChild(linkBox);

		panel.appendChild(el('div', { 'class': 'tp-actions' }, [
			btn('Verbinden …', 'button_dark', function () { st.linkMode = true; st.linkFirst = sel.table_id; render(); }),
			btn('Löschen', 'tp-danger', function () { removeTable(sel); })
		]));
		panel.appendChild(btn('+ Neuer Tisch', 'button_dark tp-primary', addTable));
	}

	function renderAreaPanel() {
		var ar = areaById(st.area);
		panel.appendChild(el('h3', { text: 'Plan bearbeiten' }));
		panel.appendChild(el('p', { 'class': 'tp-hint', text: 'Tische ziehen, an der Ecke vergrößern, anklicken zum Bearbeiten.' }));
		panel.appendChild(btn('+ Neuer Tisch', 'button_dark tp-primary', addTable));
		if (!ar) { return; }

		var aname = el('input', { type: 'text', value: ar.area_name, maxlength: '40' });
		aname.addEventListener('change', function () { renameArea(ar, aname.value); });
		var idx = st.areas.indexOf(ar);
		var box = el('div', { 'class': 'tp-areabox' }, [
			el('h3', { text: 'Bereich' }),
			field('Name', aname),
			el('div', { 'class': 'tp-actions' }, [
				btn('←', 'button_dark', function () { moveArea(ar, -1); }, { title: 'Reiter nach links', 'aria-label': 'Bereich nach links', disabled: idx === 0 ? 'disabled' : null }),
				btn('→', 'button_dark', function () { moveArea(ar, 1); }, { title: 'Reiter nach rechts', 'aria-label': 'Bereich nach rechts', disabled: idx === st.areas.length - 1 ? 'disabled' : null }),
				btn('Bereich löschen', 'tp-danger', function () { removeArea(ar); })
			])
		]);
		panel.appendChild(box);
		panel.appendChild(closurePanel(ar));

		var auto = el('input', { type: 'checkbox' });
		auto.checked = st.autoAssign;
		auto.addEventListener('change', function () {
			api('setting_save', { autoAssign: auto.checked }).then(function (r) {
				if (!r.ok) { say(r.error || 'Speichern fehlgeschlagen', true); auto.checked = st.autoAssign; return; }
				st.autoAssign = r.autoAssign; say('Gespeichert');
			});
		});
		var mode = el('select', {}, [
			el('option', { value: 'counter', text: 'Nach Zählung (bisher)' }),
			el('option', { value: 'tables', text: 'Nach Tischplan' })
		]);
		mode.value = st.availabilityMode;
		mode.addEventListener('change', function () {
			var want = mode.value;
			if (want === 'tables' && !window.confirm('Ab sofort entscheidet der Tischplan, welche Zeiten Gäste online buchen können. Die Sitzplatz- und Tischgrenzen des Outlets gelten online nicht mehr.\n\nJetzt umschalten?')) { mode.value = st.availabilityMode; return; }
			api('setting_save', { availabilityMode: want }).then(function (r) {
				if (!r.ok) { say(r.error || 'Umschalten fehlgeschlagen', true); mode.value = st.availabilityMode; return; }
				st.availabilityMode = r.availabilityMode; st.preview = null; render(); say('Online-Verfügbarkeit: ' + (r.availabilityMode === 'tables' ? 'nach Tischplan' : 'nach Zählung'));
			});
		});
		var c = st.counter;
		panel.appendChild(el('div', { 'class': 'tp-areabox' }, [
			el('h3', { text: 'Einstellung' }),
			el('label', { 'class': 'tp-check' }, [auto, el('span', { text: 'Neue Reservierungen automatisch einem Tisch zuweisen' })]),
			field('Online-Verfügbarkeit', mode),
			c ? el('p', { 'class': 'tp-hint', text: 'Bisherige Grenzen: ' + c.maxCapacity + ' Plätze, ' + c.maxTables + ' Tische. Tischplan: ' + c.planSeats + ' Plätze, ' + c.planTables + ' Tische. Die Vorschau in der Tagesansicht zeigt, was Gäste bei „Nach Tischplan“ buchen könnten.' }) : null
		]));
	}

	/* --- preview of the online offer by the table plan --- */
	function previewBlock() {
		var box = el('details', { 'class': 'tp-preview' });
		var pax = el('input', { type: 'number', min: '1', max: '20', value: String(st.previewPax), 'aria-label': 'Personen' });
		var chips = el('div', { 'class': 'tp-slots' });
		var note = el('p', { 'class': 'tp-hint' });
		function draw() {
			while (chips.firstChild) { chips.removeChild(chips.firstChild); }
			if (!st.preview) { chips.appendChild(el('span', { 'class': 'tp-hint', text: 'Lade …' })); return; }
			if (!st.preview.slots.length) { chips.appendChild(el('span', { 'class': 'tp-hint', text: st.preview.reason || 'Keine Zeitfenster an diesem Tag.' })); }
			st.preview.slots.forEach(function (s) { chips.appendChild(el('span', { 'class': 'tp-slot ' + (s.fits ? 'is-free' : 'is-full'), text: s.time })); });
		}
		function load() {
			var key = st.date + '|' + st.previewPax + '|' + st.dayVer + '|' + st.tables.length;
			if (st.preview && st.preview.key === key) { draw(); return; }
			st.preview = null; draw();
			api('preview', { date: st.date, pax: st.previewPax }).then(function (r) {
				if (!r.ok) { say(r.error || 'Vorschau nicht möglich', true); return; }
				st.preview = { key: key, slots: r.slots, reason: r.reason }; draw();
			});
		}
		note.textContent = st.availabilityMode === 'tables'
			? 'Grün: online buchbar, grau: ausgebucht. Diese Zeiten sehen Gäste online.'
			: 'Nur Vorschau – online gilt noch die Zählung.';
		box.open = st.previewOpen;
		box.appendChild(el('summary', { text: 'Online-Vorschau (Tischplan)' }));
		box.appendChild(field('Personen', pax));
		box.appendChild(chips);
		box.appendChild(note);
		box.addEventListener('toggle', function () { st.previewOpen = box.open; if (box.open) { load(); } });
		pax.addEventListener('change', function () { st.previewPax = Math.max(1, Math.min(20, parseInt(pax.value, 10) || 2)); st.preview = null; load(); });
		if (st.previewOpen) { load(); }
		return box;
	}

	function closurePanel(ar) {
		var box = el('div', { 'class': 'tp-areabox' }, [el('h3', { text: 'Sperrzeiten' })]);
		var mine = st.closures.filter(function (c) { return c.area_id === ar.area_id; });
		if (!mine.length) { box.appendChild(el('p', { 'class': 'tp-hint', text: 'Der Bereich ist immer verfügbar. Mit einem Sperrzeitraum ist er z. B. im Winter nicht buchbar, ohne dass er gelöscht werden muss.' })); }
		mine.forEach(function (c) {
			var range = fmtDate(c.date_from) + (c.date_to ? ' – ' + fmtDate(c.date_to) : ' – unbefristet');
			box.appendChild(el('div', { 'class': 'tp-closure' }, [
				el('span', {}, [
					el('span', { 'class': 'tp-closure-range', text: range }),
					el('span', { 'class': 'tp-closure-meta', text: (c.yearly ? 'jährlich' : '') + (c.yearly && c.note ? ' · ' : '') + (c.note || '') })
				]),
				el('button', { type: 'button', title: 'Sperrzeitraum entfernen', 'aria-label': 'Sperrzeitraum ' + range + ' entfernen', text: '×', onclick: function () { deleteClosure(c); } })
			]));
		});
		var from = el('input', { type: 'date' });
		var to = el('input', { type: 'date' });
		var yearly = el('input', { type: 'checkbox' });
		var note = el('input', { type: 'text', maxlength: '80', placeholder: 'z. B. Winterpause' });
		box.appendChild(el('div', { 'class': 'tp-closure-form' }, [
			field('Gesperrt von', from),
			field('bis (leer = unbefristet)', to),
			el('label', { 'class': 'tp-check' }, [yearly, el('span', { text: 'jedes Jahr wiederholen' })]),
			field('Notiz', note),
			btn('Sperrzeitraum hinzufügen', 'button_dark tp-primary', function () {
				if (!from.value) { say('Bitte „Gesperrt von“ eintragen', true); return; }
				if (yearly.checked && !to.value) { say('Für eine jährliche Sperre bitte auch „bis“ eintragen', true); return; }
				addClosure({ area_id: ar.area_id, date_from: from.value, date_to: to.value, yearly: yearly.checked, note: note.value });
			})
		]));
		return box;
	}

	/* ---------- day actions ---------- */
	function setDate(d) {
		st.date = d; st.selRes = null; st.time = ''; st.day = null;
		render();
		loadDay();
	}

	function selectRes(id) {
		st.selRes = id;
		var r = id ? resById(id) : null;
		if (r && r.tables.length) {
			var inArea = r.tables.some(function (t) { var o = byId(t); return o && o.area_id === st.area; });
			if (!inArea) { var first = byId(r.tables[0]); if (first) { st.area = first.area_id; } }
		}
		render();
	}

	function assignTables(resId, ids, confirmed) {
		return api('assign', { reservation_id: resId, table_ids: ids, confirm: confirmed }).then(function (r) {
			if (r.needs_confirm) {
				if (window.confirm(r.warnings.join('\n') + '\n\nTrotzdem zuweisen?')) { return assignTables(resId, ids, true); }
				return null;
			}
			if (!r.ok) { say(r.error || 'Zuweisung fehlgeschlagen', true); return null; }
			setDay(r); render(); say(ids.length ? 'Zugewiesen' : 'Zuweisung entfernt');
			return r;
		});
	}

	function toggleTable(resId, tid) {
		var r = resById(resId);
		if (!r) { return; }
		var ids = r.tables.slice(), i = ids.indexOf(tid);
		if (i >= 0) { ids.splice(i, 1); } else { ids.push(tid); }
		assignTables(resId, ids, false);
	}

	function addTableTo(resId, tid) {
		var r = resById(resId);
		if (!r) { return; }
		st.selRes = resId;
		if (r.tables.indexOf(tid) >= 0) { render(); return; }
		assignTables(resId, r.tables.concat([tid]), false);
	}

	function autoOne(resId, replace) {
		var go = function () {
			api('auto_assign', { reservation_id: resId }).then(function (r) {
				if (!r.ok) { say(r.error || 'Zuweisung fehlgeschlagen', true); return; }
				setDay(r); render();
				say(r.found ? 'Zugewiesen' : 'Kein passender freier Tisch – bitte manuell zuweisen', !r.found);
			});
		};
		if (replace) { assignTables(resId, [], true).then(function (r) { if (r) { go(); } }); } else { go(); }
	}

	function autoDay() {
		api('auto_assign_day', { date: st.date }).then(function (r) {
			if (!r.ok) { say(r.error || 'Zuweisung fehlgeschlagen', true); return; }
			setDay(r); render();
			say(r.summary.assigned + ' zugewiesen' + (r.summary.open ? ', ' + r.summary.open + ' ohne passenden Tisch (manuell zuweisen)' : ''), r.summary.open > 0);
		});
	}

	/* ---------- editor actions ---------- */
	function persist(t, movedArea) {
		return api('table_save', { table: t }).then(function (r) {
			if (!r.ok) { say(r.error || 'Speichern fehlgeschlagen', true); render(); return null; }
			var saved = normalize(r.table);
			var idx = -1;
			st.tables.forEach(function (x, i) { if (x.table_id === saved.table_id) { idx = i; } });
			if (idx >= 0) { st.tables[idx] = saved; } else { st.tables.push(saved); }
			st.selected = saved.table_id;
			if (movedArea) {
				st.links = st.links.filter(function (l) { return l.table_a !== saved.table_id && l.table_b !== saved.table_id; });
				st.area = saved.area_id;
			}
			render();
			say(movedArea ? 'Tisch in Bereich „' + areaById(saved.area_id).area_name + '“ verschoben' : 'Gespeichert');
			return saved;
		});
	}

	function addArea() {
		var n = st.areas.length + 1, name, names = {};
		st.areas.forEach(function (a) { names[a.area_name] = 1; });
		do { name = 'Bereich ' + n; n++; } while (names[name]);
		api('area_save', { area: { area_name: name } }).then(function (r) {
			if (!r.ok) { say(r.error || 'Bereich konnte nicht angelegt werden', true); return; }
			st.areas = normAreas(r.areas);
			selectArea(parseInt(r.area.area_id, 10));
		});
	}

	function renameArea(a, value) {
		api('area_save', { area: { area_id: a.area_id, area_name: value } }).then(function (r) {
			if (!r.ok) { say(r.error || 'Umbenennen fehlgeschlagen', true); render(); return; }
			st.areas = normAreas(r.areas);
			render();
			say('Gespeichert');
		});
	}

	function moveArea(a, dir) {
		api('area_move', { area_id: a.area_id, dir: dir }).then(function (r) {
			if (!r.ok) { say(r.error || 'Verschieben fehlgeschlagen', true); return; }
			st.areas = normAreas(r.areas);
			render();
		});
	}

	function removeArea(a) {
		if (tablesOfArea(a.area_id).length) {
			say('Der Bereich enthält noch Tische - bitte zuerst löschen oder in einen anderen Bereich verschieben', true);
			return;
		}
		if (!window.confirm('Bereich „' + a.area_name + '“ wirklich löschen?')) { return; }
		api('area_delete', { area_id: a.area_id }).then(function (r) {
			if (!r.ok) { say(r.error || 'Löschen fehlgeschlagen', true); return; }
			st.areas = normAreas(r.areas);
			st.closures = st.closures.filter(function (c) { return c.area_id !== a.area_id; });
			selectArea(st.areas[0].area_id);
			say('Bereich gelöscht');
		});
	}

	function addClosure(c) {
		api('closure_save', { closure: c }).then(function (r) {
			if (!r.ok) { say(r.error || 'Sperrzeitraum konnte nicht gespeichert werden', true); return; }
			st.closures = normClosures(r.closures);
			render();
			say('Sperrzeitraum gespeichert');
			loadDay();
		});
	}

	function deleteClosure(c) {
		api('closure_delete', { closure_id: c.closure_id }).then(function (r) {
			if (!r.ok) { say(r.error || 'Löschen fehlgeschlagen', true); return; }
			st.closures = normClosures(r.closures);
			render();
			say('Sperrzeitraum entfernt');
			loadDay();
		});
	}

	function addTable() {
		var n = st.tables.length + 1, name;
		var names = {};
		st.tables.forEach(function (t) { names[t.table_name] = 1; });
		do { name = 'T' + n; n++; } while (names[name]);
		var pos = { x: 20, y: 20 };
		var here = tablesOfArea(st.area);
		outer: for (var y = 20; y <= cfg.canvasH - 110; y += 110) {
			for (var x = 20; x <= cfg.canvasW - 110; x += 110) {
				var hit = here.some(function (t) { return x < t.x + t.w && x + 90 > t.x && y < t.y + t.h && y + 90 > t.y; });
				if (!hit) { pos = { x: x, y: y }; break outer; }
			}
		}
		persist({ area_id: st.area, table_name: name, seats: 4, shape: 'rect', x: pos.x, y: pos.y, w: 90, h: 90, rot: 0 });
	}

	function removeTable(t) {
		if (!window.confirm('Tisch „' + t.table_name + '“ wirklich löschen? Zuweisungen und Verbindungen gehen verloren.')) { return; }
		api('table_delete', { table_id: t.table_id }).then(function (r) {
			if (!r.ok) { say(r.error || 'Löschen fehlgeschlagen', true); return; }
			st.tables = st.tables.filter(function (x) { return x.table_id !== t.table_id; });
			st.links = st.links.filter(function (l) { return l.table_a !== t.table_id && l.table_b !== t.table_id; });
			st.selected = null;
			render();
			say('Tisch gelöscht');
			loadDay();
		});
	}

	function toggleLink(a, b, chainTo) {
		api('link_toggle', { a: a, b: b }).then(function (r) {
			if (!r.ok) { say(r.error || 'Verbindung nicht möglich', true); return; }
			st.links = normLinks(r.links);
			if (chainTo) { st.linkFirst = chainTo; }
			render();
			say(r.state === 'linked' ? 'Tische verbunden' : 'Verbindung aufgehoben');
		});
	}

	/* ---------- pointer interaction ---------- */
	var drag = null;

	canvas.addEventListener('pointerdown', function (e) {
		if (!st.edit) { return; }
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		if (!node) {
			if (!st.linkMode && st.selected) { st.selected = null; render(); }
			return;
		}
		var t = byId(parseInt(node.getAttribute('data-id'), 10));
		if (!t) { return; }

		if (st.linkMode) {
			e.preventDefault();
			if (!st.linkFirst) { st.linkFirst = t.table_id; render(); return; }
			// chain: the table just clicked is the starting point of the next link
			if (st.linkFirst !== t.table_id) { toggleLink(st.linkFirst, t.table_id, t.table_id); }
			else { st.linkFirst = null; render(); }
			return;
		}

		e.preventDefault();
		var isHandle = e.target.getAttribute && e.target.getAttribute('data-handle');
		drag = {
			id: t.table_id, mode: isHandle ? 'resize' : 'move',
			sx: e.clientX, sy: e.clientY, ox: t.x, oy: t.y, ow: t.w, oh: t.h, moved: false, pid: e.pointerId
		};
		try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
		if (st.selected !== t.table_id) {
			st.selected = t.table_id;
			renderPanel();
			Array.prototype.forEach.call(canvas.querySelectorAll('.tp-table'), function (n) {
				var on = n === node;
				n.classList.toggle('is-selected', on);
				var h = n.querySelector('.tp-handle');
				if (on && !h) { n.appendChild(el('span', { 'class': 'tp-handle', 'data-handle': '1', title: 'Größe ändern' })); }
				if (!on && h) { n.removeChild(h); }
			});
		}
	});

	canvas.addEventListener('pointermove', function (e) {
		if (!drag) { return; }
		var t = byId(drag.id);
		var node = canvas.querySelector('.tp-table[data-id="' + drag.id + '"]');
		if (!t || !node) { return; }
		var dx = (e.clientX - drag.sx) / st.scale, dy = (e.clientY - drag.sy) / st.scale;
		if (Math.abs(dx) + Math.abs(dy) > 2) { drag.moved = true; }
		if (drag.mode === 'move') {
			t.x = clamp(snap(drag.ox + dx), 0, cfg.canvasW - t.w);
			t.y = clamp(snap(drag.oy + dy), 0, cfg.canvasH - t.h);
			node.style.left = t.x + 'px'; node.style.top = t.y + 'px';
		} else {
			var a = t.rot * Math.PI / 180, c = Math.cos(a), s = Math.sin(a);
			var lx = dx * c + dy * s, ly = -dx * s + dy * c;
			t.w = clamp(snap(drag.ow + lx), 40, 400);
			t.h = clamp(snap(drag.oh + ly), 40, 400);
			node.style.width = t.w + 'px'; node.style.height = t.h + 'px';
		}
		renderLinks();
	});

	function endDrag() {
		if (!drag) { return; }
		var d = drag; drag = null;
		try { canvas.releasePointerCapture(d.pid); } catch (err) {}
		var t = byId(d.id);
		if (t && d.moved) { persist(tableData(t)); }
	}
	canvas.addEventListener('pointerup', endDrag);
	canvas.addEventListener('pointercancel', endDrag);

	// assignment view: click a table to add/remove it for the selected reservation
	function tableClicked(node) {
		var tid = parseInt(node.getAttribute('data-id'), 10);
		var t = byId(tid);
		if (!t) { return; }
		if (st.selRes) { toggleTable(st.selRes, tid); return; }
		var list = asgOf(tid);
		if (list.length) { selectRes(list[0].id); }
	}
	canvas.addEventListener('click', function (e) {
		if (st.edit) { return; }
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		if (node) { tableClicked(node); }
	});
	canvas.addEventListener('keydown', function (e) {
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		if (!node || (e.key !== 'Enter' && e.key !== ' ')) { return; }
		e.preventDefault();
		if (st.edit) { st.selected = parseInt(node.getAttribute('data-id'), 10); render(); } else { tableClicked(node); }
	});

	// drag a reservation card onto a table
	canvas.addEventListener('dragover', function (e) {
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		Array.prototype.forEach.call(canvas.querySelectorAll('.is-dragover'), function (n) { if (n !== node) { n.classList.remove('is-dragover'); } });
		if (node && !st.edit && st.dragRes) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; node.classList.add('is-dragover'); }
	});
	canvas.addEventListener('dragleave', function (e) {
		if (e.target.classList) { e.target.classList.remove('is-dragover'); }
	});
	canvas.addEventListener('drop', function (e) {
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		Array.prototype.forEach.call(canvas.querySelectorAll('.is-dragover'), function (n) { n.classList.remove('is-dragover'); });
		if (!node || st.edit || !st.dragRes) { return; }
		e.preventDefault();
		var id = st.dragRes; st.dragRes = null;
		addTableTo(id, parseInt(node.getAttribute('data-id'), 10));
	});

	if (toggle) {
		toggle.addEventListener('click', function () {
			st.edit = !st.edit;
			st.linkMode = false; st.linkFirst = null; st.selected = null;
			toggle.textContent = st.edit ? 'Bearbeiten beenden' : 'Plan bearbeiten';
			toggle.classList.toggle('is-active', st.edit);
			render();
		});
	}

	/* ---------- start ---------- */
	rescale();
	api('load').then(function (r) {
		if (!r.ok) { say(r.error || 'Plan konnte nicht geladen werden', true); return; }
		st.areas = normAreas(r.areas);
		st.area = st.areas[0].area_id;
		st.tables = r.tables.map(normalize);
		st.links = normLinks(r.links);
		st.closures = normClosures(r.closures);
		st.autoAssign = !!r.autoAssign;
		st.availabilityMode = r.availabilityMode;
		st.counter = r.counter;
		render();
		loadDay();
	});
})();
