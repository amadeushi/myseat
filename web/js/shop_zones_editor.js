/* Delivery zone editor (backend page p=11): draw, edit and delete the polygons of web/ajax/shop_zones_admin.php.
   Leaflet.draw drives the actual point editing (vendored in web/js/leaflet/) but its own default toolbar is
   hidden in CSS - every action here is our own button calling the draw/edit handlers directly, so the tool
   matches the rest of the backend instead of Leaflet.draw's stock look. */
(function () {
	'use strict';
	var root = document.getElementById('zones-page'); if (!root) { return; }
	var TOKEN = root.dataset.token;
	// reused, already-documented DESIGN.md tones (status/capacity/daypart set) - not a new accent, just enough
	// distinct hues to tell overlapping delivery areas apart on the map
	var COLORS = ['#c9a259', '#62b6cb', '#8fbf7a', '#a99be8', '#e2b56b', '#c65a4f', '#5fc5a2', '#e0a458'];

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function eur(cents) { return (cents / 100).toFixed(2).replace('.', ','); }
	function note(t, bad) { var el = $('#zo-note'); if (!el) { return; } el.textContent = t; el.className = 'me-note' + (bad ? ' is-bad' : (t ? ' is-ok' : '')); }
	function post(op, data, id) {
		var body = new URLSearchParams(); body.set('op', op); body.set('token', TOKEN);
		if (id) { body.set('id', String(id)); }
		if (data) { body.set('data', JSON.stringify(data)); }
		return fetch('ajax/shop_zones_admin.php', { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function colorFor(i) { return COLORS[i % COLORS.length]; }
	function polyToLatLngs(poly) { return poly.map(function (p) { return [p[0], p[1]]; }); }
	function latLngsToPoly(latlngs) { return latlngs.map(function (ll) { return [ll.lat, ll.lng]; }); }

	var map, layers = {}, zones = [], drawHandler = null, editingId = null, draftLayer = null;

	function initMap(origin) {
		map = L.map('zo-map');
		map.setView(origin ? [origin[0], origin[1]] : [52.1508, 9.9511], 14);
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 }).addTo(map);
		map.on(L.Draw.Event.CREATED, function (e) { onDrawn(e.layer); });
	}
	function clearLayers() { Object.keys(layers).forEach(function (id) { map.removeLayer(layers[id]); }); layers = {}; }
	function styleFor(z, i, active) {
		return { color: colorFor(i), weight: active ? 3 : 2, fillOpacity: active ? 0.3 : 0.16, opacity: z.active ? 0.9 : 0.35, dashArray: z.active ? null : '5 5' };
	}
	function renderMap() {
		clearLayers();
		zones.forEach(function (z, i) {
			var layer = L.polygon(polyToLatLngs(z.polygon), styleFor(z, i, z.id === editingId)).addTo(map);
			layer.zoneId = z.id;
			layer.on('click', function () { selectZone(z.id); });
			layers[z.id] = layer;
		});
	}
	function selectZone(id) {
		$$('.zo-card').forEach(function (c) { c.classList.toggle('is-active', +c.dataset.id === id); });
		Object.keys(layers).forEach(function (k) {
			var z = zones.filter(function (zz) { return zz.id === +k; })[0]; if (!z) { return; }
			layers[k].setStyle(styleFor(z, zones.indexOf(z), +k === id));
			if (+k === id) { layers[k].bringToFront(); map.fitBounds(layers[k].getBounds(), { maxZoom: 16, padding: [40, 40] }); }
		});
	}
	function renderOverlaps(overlaps) {
		var box = $('#zo-overlaps'); if (!box) { return; }
		var byId = {}; zones.forEach(function (z) { byId[z.id] = z; });
		var rows = (overlaps || []).map(function (o) {
			var a = byId[o.a], b = byId[o.b], w = byId[o.winner]; if (!a || !b || !w) { return ''; }
			var other = o.winner === o.a ? b : a;
			return '<p>„' + esc(a.name) + '“ und „' + esc(b.name) + '“ überschneiden sich – dort gilt „' + esc(w.name) + '“ (günstigere Liefergebühr gewinnt), nicht „' + esc(other.name) + '“.</p>';
		}).filter(Boolean);
		box.innerHTML = rows.join('');
		box.hidden = !rows.length;
	}

	function cardHtml(z, i, isNew) {
		return '<form class="zo-card' + (isNew ? ' is-new' : '') + '" data-id="' + z.id + '">' +
			'<div class="zo-card-head"><span class="zo-swatch" style="background:' + colorFor(i) + '"></span>' +
			'<input type="text" name="name" value="' + esc(z.name) + '" maxlength="80" placeholder="Name des Gebiets" aria-label="Name" required/></div>' +
			'<div class="zo-card-fields">' +
			'<label>Liefergebühr <input type="text" name="fee" inputmode="decimal" value="' + eur(z.fee_cents) + '"/> €</label>' +
			'<label>Mindestbestellwert <input type="text" name="min" inputmode="decimal" value="' + eur(z.min_order_cents) + '"/> €</label>' +
			'<label class="offer-check"><input type="checkbox" name="active"' + (z.active ? ' checked' : '') + '/> aktiv</label></div>' +
			'<div class="zo-card-actions">' +
			(isNew ? '<button type="submit" class="button_dark">Gebiet anlegen</button><button type="button" class="zo-cancel-new">Verwerfen</button>' :
				'<button type="button" class="zo-edit-shape" data-id="' + z.id + '">' + (z.id === editingId ? 'Form fertig' : 'Form bearbeiten') + '</button>' +
				'<button type="submit" class="button_dark">Speichern</button>' +
				'<button type="button" class="zo-delete" data-id="' + z.id + '">Löschen</button>') +
			'</div><span class="detail-status" role="status" aria-live="polite"></span></form>';
	}

	function renderList() {
		var box = $('#zo-list'); if (!box) { return; }
		if (!zones.length) { box.innerHTML = '<p class="orders-empty">Noch keine Liefergebiete. Zeichne oben rechts das erste.</p>'; return; }
		box.innerHTML = zones.map(function (z, i) { return cardHtml(z, i, false); }).join('');
		wireCards();
	}
	function readCard(form) {
		return { name: form.elements.name.value, fee: form.elements.fee.value, min: form.elements.min.value, active: form.elements.active.checked };
	}
	function wireCards() {
		$$('.zo-card', $('#zo-list')).forEach(function (form) {
			var id = +form.dataset.id, status = $('.detail-status', form);
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				status.textContent = 'Speichert …'; status.className = 'detail-status';
				post('save_info', readCard(form), id).then(function (r) {
					if (!r.ok) { status.textContent = r.error; status.className = 'detail-status is-error'; return; }
					var z = zones.filter(function (zz) { return zz.id === id; })[0];
					if (z) { z.name = form.elements.name.value.trim(); z.fee_cents = Math.round(parseFloat(form.elements.fee.value.replace(',', '.')) * 100) || 0; z.min_order_cents = Math.round(parseFloat(form.elements.min.value.replace(',', '.')) * 100) || 0; z.active = form.elements.active.checked; }
					status.textContent = 'Gespeichert.'; status.className = 'detail-status is-ok';
					renderMap();
				});
			});
			$('.zo-edit-shape', form).addEventListener('click', function () { toggleShapeEdit(id); });
			$('.zo-delete', form).addEventListener('click', function () {
				var btn = this;
				if (btn.dataset.armed) {
					post('delete', null, id).then(function (r) {
						if (!r.ok) { status.textContent = r.error; status.className = 'detail-status is-error'; return; }
						zones = zones.filter(function (z) { return z.id !== id; });
						if (editingId === id) { editingId = null; }
						renderList(); renderMap(); renderOverlaps(r.overlaps);
					});
				} else {
					btn.dataset.armed = '1'; btn.textContent = 'Wirklich löschen?';
					setTimeout(function () { btn.dataset.armed = ''; btn.textContent = 'Löschen'; }, 4000);
				}
			});
			$('.zo-card-head', form).addEventListener('click', function () { selectZone(id); });
		});
	}

	function toggleShapeEdit(id) {
		if (editingId && editingId !== id) { finishShapeEdit(editingId); }
		var layer = layers[id]; if (!layer) { return; }
		if (editingId === id) { finishShapeEdit(id); return; }
		editingId = id; layer.editing.enable(); selectZone(id); renderList();
	}
	function finishShapeEdit(id) {
		var layer = layers[id]; if (!layer) { return; }
		layer.editing.disable();
		var poly = latLngsToPoly(layer.getLatLngs()[0]);
		editingId = null;
		post('save_shape', { polygon: poly }, id).then(function (r) {
			if (!r.ok) { note(r.error, true); return; }
			var z = zones.filter(function (zz) { return zz.id === id; })[0]; if (z) { z.polygon = poly; }
			renderOverlaps(r.overlaps); renderList(); renderMap();
		});
	}

	function startDrawing() {
		if (drawHandler) { drawHandler.disable(); }
		// starting a fresh draw discards an unfinished, unsaved draft rather than leaving it orphaned on the map
		if (draftLayer) { map.removeLayer(draftLayer); draftLayer = null; $('#zo-draft').innerHTML = ''; }
		drawHandler = new L.Draw.Polygon(map, { shapeOptions: { color: colorFor(zones.length), weight: 2, fillOpacity: 0.18 } });
		drawHandler.enable();
	}
	// the in-progress new zone lives in its own #zo-draft container and its own draftLayer, never inside
	// #zo-list/layers{} - both get fully rebuilt by renderList()/renderMap() whenever any other zone is
	// saved, edited or deleted, which would otherwise wipe out an unfinished draft mid-drawing
	function onDrawn(layer) {
		drawHandler = null;
		var poly = latLngsToPoly(layer.getLatLngs()[0]);
		layer.addTo(map);
		draftLayer = layer;
		var z = { id: 'new', name: '', polygon: poly, fee_cents: 0, min_order_cents: 0, active: true };
		var box = $('#zo-draft');
		box.innerHTML = cardHtml(z, zones.length, true);
		var form = $('.zo-card.is-new', box), status = $('.detail-status', form);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			status.textContent = 'Legt an …'; status.className = 'detail-status';
			var d = readCard(form); d.polygon = poly;
			post('create', d).then(function (r) {
				if (!r.ok) { status.textContent = r.error; status.className = 'detail-status is-error'; return; }
				map.removeLayer(layer); draftLayer = null; box.innerHTML = '';
				load();
			});
		});
		$('.zo-cancel-new', form).addEventListener('click', function () { map.removeLayer(layer); draftLayer = null; box.innerHTML = ''; });
		form.elements.name.focus();
	}

	function load() {
		note('');
		post('load').then(function (r) {
			if (!r.ok) { note(r.error || 'Konnte Liefergebiete nicht laden.', true); return; }
			zones = r.zones;
			if (!map) { initMap(r.origin); }
			renderMap(); renderList(); renderOverlaps(r.overlaps);
		}).catch(function () { note('Liefergebiete konnten nicht geladen werden.', true); });
	}

	var newBtn = $('#zo-new'); if (newBtn) { newBtn.addEventListener('click', startDrawing); }
	load();
})();
