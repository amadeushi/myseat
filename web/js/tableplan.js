/*
 * Table plan editor / viewer. Plain JavaScript (no jQuery), talks to ajax/tp.php.
 * The plan lives on a fixed logical canvas (TP.canvasW x TP.canvasH) that is scaled to the
 * available width; all coordinates are logical pixels on a 10px grid.
 */
(function () {
	'use strict';
	var cfg = window.TP;
	if (!cfg) { return; }

	var GRID = 10;
	var st = {
		areas: [],
		area: null,          // area_id currently shown
		tables: [],
		links: [],
		selected: null,      // table_id
		edit: false,
		linkMode: false,
		linkFirst: null,
		scale: 1
	};

	var tabs   = document.getElementById('tp-tabs');
	var stage  = document.getElementById('tp-stage');
	var canvas = document.getElementById('tp-canvas');
	var svg    = document.getElementById('tp-links');
	var panel  = document.getElementById('tp-panel');
	var msg    = document.getElementById('tp-msg');
	var toggle = document.getElementById('tp-mode-toggle');

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
	function normAreas(list) {
		return list.map(function (a) { return { area_id: parseInt(a.area_id, 10), area_name: a.area_name }; });
	}
	function areaById(id) {
		for (var i = 0; i < st.areas.length; i++) { if (st.areas[i].area_id === id) { return st.areas[i]; } }
		return null;
	}
	function tablesOfArea(id) {
		return st.tables.filter(function (t) { return t.area_id === id; });
	}
	function tableData(t, over) {
		var d = { table_id: t.table_id, area_id: t.area_id, table_name: t.table_name, seats: t.seats, shape: t.shape, x: t.x, y: t.y, w: t.w, h: t.h, rot: t.rot, active: t.active };
		Object.keys(over || {}).forEach(function (k) { d[k] = over[k]; });
		return d;
	}
	function normLinks(list) {
		return list.map(function (l) { return { table_a: parseInt(l.table_a, 10), table_b: parseInt(l.table_b, 10) }; });
	}

	/* ---------- scaling ---------- */
	function rescale() {
		var avail = stage.clientWidth;
		st.scale = avail / cfg.canvasW;
		canvas.style.transform = 'scale(' + st.scale + ')';
		stage.style.height = Math.round(cfg.canvasH * st.scale) + 'px';
	}
	window.addEventListener('resize', rescale);

	/* ---------- rendering ---------- */
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

	function tableNode(t) {
		var cls = 'tp-table' + (t.shape === 'round' ? ' is-round' : '') + (st.selected === t.table_id ? ' is-selected' : '') +
			(st.linkMode && st.linkFirst === t.table_id ? ' is-link-first' : '');
		var node = el('div', {
			'class': cls,
			'data-id': String(t.table_id),
			role: 'button',
			tabindex: '0',
			'aria-label': 'Tisch ' + t.table_name + ', ' + t.seats + ' Plätze'
		}, [
			el('span', { 'class': 'tp-inner', style: 'transform:rotate(' + (-t.rot) + 'deg)' }, [
				el('span', { 'class': 'tp-name', text: t.table_name }),
				el('span', { 'class': 'tp-seats', text: t.seats + ' Pl.' })
			])
		]);
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

	function render() {
		Array.prototype.slice.call(canvas.querySelectorAll('.tp-table')).forEach(function (n) { canvas.removeChild(n); });
		tablesOfArea(st.area).forEach(function (t) { canvas.appendChild(tableNode(t)); });
		renderLinks();
		canvas.classList.toggle('is-edit', st.edit);
		canvas.classList.toggle('is-linking', st.linkMode);
		renderTabs();
		renderPanel();
	}

	function selectArea(id) {
		st.area = id; st.selected = null; st.linkMode = false; st.linkFirst = null;
		render();
	}

	function renderTabs() {
		while (tabs.firstChild) { tabs.removeChild(tabs.firstChild); }
		st.areas.forEach(function (a) {
			var n = tablesOfArea(a.area_id).length;
			tabs.appendChild(el('button', {
				type: 'button', role: 'tab', 'class': 'tp-tab' + (a.area_id === st.area ? ' is-active' : ''),
				'aria-selected': a.area_id === st.area ? 'true' : 'false',
				onclick: function () { selectArea(a.area_id); }
			}, [el('span', { text: a.area_name }), el('span', { 'class': 'tp-tab-count', text: String(n) })]));
		});
		if (st.edit) {
			tabs.appendChild(el('button', { type: 'button', 'class': 'tp-tab tp-tab-add', text: '+ Bereich', onclick: addArea }));
		}
	}

	/* ---------- side panel ---------- */
	function field(label, input) {
		return el('label', { 'class': 'tp-field' }, [el('span', { text: label }), input]);
	}

	function seatsOf(list) {
		return list.reduce(function (s, t) { return s + t.seats; }, 0);
	}

	function renderPanel() {
		while (panel.firstChild) { panel.removeChild(panel.firstChild); }
		var sel = st.selected ? byId(st.selected) : null;

		if (!st.edit) {
			panel.appendChild(el('h3', { text: 'Übersicht' }));
			var here = tablesOfArea(st.area), cur = areaById(st.area);
			panel.appendChild(el('p', { text: (cur ? cur.area_name + ': ' : '') + here.length + ' Tische, ' + seatsOf(here) + ' Sitzplätze' }));
			if (st.areas.length > 1) {
				panel.appendChild(el('p', { 'class': 'tp-hint', text: 'Gesamt: ' + st.tables.length + ' Tische, ' + seatsOf(st.tables) + ' Sitzplätze' }));
			}
			if (!st.tables.length) {
				panel.appendChild(el('p', { 'class': 'tp-hint', text: cfg.canEdit ? 'Noch keine Tische angelegt. Mit „Plan bearbeiten“ kannst du den Plan aufbauen.' : 'Noch keine Tische angelegt.' }));
			}
			return;
		}

		if (st.linkMode) {
			var first = st.linkFirst ? byId(st.linkFirst) : null;
			panel.appendChild(el('h3', { text: 'Tische verbinden' }));
			panel.appendChild(el('p', { 'class': 'tp-hint', text: first
				? 'Jetzt den Tisch anklicken, der mit „' + first.table_name + '“ zusammengestellt werden kann. Nochmaliges Verbinden hebt die Verbindung auf.'
				: 'Erst den ersten Tisch anklicken, dann den zweiten.' }));
			panel.appendChild(el('button', { 'class': 'button_dark', type: 'button', text: 'Fertig', onclick: function () { st.linkMode = false; st.linkFirst = null; render(); } }));
			return;
		}

		if (!sel) {
			var ar = areaById(st.area);
			panel.appendChild(el('h3', { text: 'Plan bearbeiten' }));
			panel.appendChild(el('p', { 'class': 'tp-hint', text: 'Tische ziehen, an der Ecke vergrößern, anklicken zum Bearbeiten.' }));
			panel.appendChild(el('button', { 'class': 'button_dark tp-primary', type: 'button', text: '+ Neuer Tisch', onclick: addTable }));
			if (ar) {
				var aname = el('input', { type: 'text', value: ar.area_name, maxlength: '40' });
				aname.addEventListener('change', function () { renameArea(ar, aname.value); });
				var idx = st.areas.indexOf(ar);
				panel.appendChild(el('div', { 'class': 'tp-areabox' }, [
					el('h3', { text: 'Bereich' }),
					field('Name', aname),
					el('div', { 'class': 'tp-actions' }, [
						el('button', { 'class': 'button_dark', type: 'button', title: 'Reiter nach links', 'aria-label': 'Bereich nach links', text: '←', disabled: idx === 0 ? 'disabled' : null, onclick: function () { moveArea(ar, -1); } }),
						el('button', { 'class': 'button_dark', type: 'button', title: 'Reiter nach rechts', 'aria-label': 'Bereich nach rechts', text: '→', disabled: idx === st.areas.length - 1 ? 'disabled' : null, onclick: function () { moveArea(ar, 1); } }),
						el('button', { 'class': 'tp-danger', type: 'button', text: 'Bereich löschen', onclick: function () { removeArea(ar); } })
					])
				]));
			}
			return;
		}

		panel.appendChild(el('h3', { text: 'Tisch bearbeiten' }));
		var name = el('input', { type: 'text', value: sel.table_name, maxlength: '40' });
		var seatsIn = el('input', { type: 'number', value: String(sel.seats), min: '1', max: '99' });
		var area = el('select', {}, st.areas.map(function (a) { return el('option', { value: String(a.area_id), text: a.area_name }); }));
		area.value = String(sel.area_id);
		var shape = el('select', {}, [
			el('option', { value: 'rect', text: 'Rechteckig' }),
			el('option', { value: 'round', text: 'Rund' })
		]);
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
			el('button', { 'class': 'button_dark', type: 'button', text: 'Verbinden …', onclick: function () { st.linkMode = true; st.linkFirst = sel.table_id; render(); } }),
			el('button', { 'class': 'tp-danger', type: 'button', text: 'Löschen', onclick: function () { removeTable(sel); } })
		]));
		panel.appendChild(el('button', { 'class': 'button_dark tp-primary', type: 'button', text: '+ Neuer Tisch', onclick: addTable }));
	}

	/* ---------- server actions ---------- */
	function persist(t, movedArea) {
		return api('table_save', { table: t }).then(function (r) {
			if (!r.ok) { say(r.error || 'Speichern fehlgeschlagen', true); render(); return null; }
			var saved = normalize(r.table);
			var idx = -1;
			st.tables.forEach(function (x, i) { if (x.table_id === saved.table_id) { idx = i; } });
			if (idx >= 0) { st.tables[idx] = saved; } else { st.tables.push(saved); }
			st.selected = saved.table_id;
			if (movedArea) {
				// the server drops links across areas; follow the table to its new area
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
			selectArea(st.areas[0].area_id);
			say('Bereich gelöscht');
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
		});
	}

	function toggleLink(a, b) {
		api('link_toggle', { a: a, b: b }).then(function (r) {
			if (!r.ok) { say(r.error || 'Verbindung nicht möglich', true); return; }
			st.links = normLinks(r.links);
			render();
			say(r.state === 'linked' ? 'Tische verbunden' : 'Verbindung aufgehoben');
		});
	}

	/* ---------- pointer interaction ---------- */
	var drag = null;

	canvas.addEventListener('pointerdown', function (e) {
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		if (!node) {
			if (st.edit && !st.linkMode && st.selected) { st.selected = null; render(); }
			return;
		}
		var t = byId(parseInt(node.getAttribute('data-id'), 10));
		if (!t) { return; }

		if (st.linkMode) {
			e.preventDefault();
			if (!st.linkFirst) { st.linkFirst = t.table_id; render(); return; }
			if (st.linkFirst !== t.table_id) { toggleLink(st.linkFirst, t.table_id); }
			return;
		}
		if (!st.edit) { st.selected = t.table_id; render(); return; }

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
		if (t && d.moved) {
			persist(tableData(t));
		}
	}
	canvas.addEventListener('pointerup', endDrag);
	canvas.addEventListener('pointercancel', endDrag);

	canvas.addEventListener('keydown', function (e) {
		var node = e.target.closest ? e.target.closest('.tp-table') : null;
		if (!node || (e.key !== 'Enter' && e.key !== ' ')) { return; }
		e.preventDefault();
		st.selected = parseInt(node.getAttribute('data-id'), 10);
		render();
	});

	if (toggle) {
		toggle.addEventListener('click', function () {
			st.edit = !st.edit;
			st.linkMode = false; st.linkFirst = null;
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
		render();
	});
})();
