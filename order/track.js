/* Map of the order status page: restaurant, destination and, while the driver shares his position, the driver.
   window.StatusMap.update(driver) is called by the status page every few seconds with {lat, lng, age} or null. */
(function () {
	'use strict';
	var el = document.getElementById('st-map');
	if (!el || !window.L) { return; }
	var cfg = {};
	try { cfg = JSON.parse(el.dataset.cfg || '{}'); } catch (e) { return; }
	var note = document.getElementById('st-mapnote');
	var map = L.map(el, { zoomControl: true, scrollWheelZoom: false, attributionControl: true, worldCopyJump: false });
	L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
	function pin(kind, ll, label) {
		var m = L.marker(ll, { icon: L.divIcon({ className: 'st-pin st-pin-' + kind, html: '<span></span>', iconSize: [30, 30], iconAnchor: [15, 15] }), keyboard: false, interactive: false });
		if (label) { m.bindTooltip(label, { permanent: true, direction: 'top', offset: [0, -14], className: 'st-tip' }); }
		return m.addTo(map);
	}
	var pts = [];
	if (cfg.origin) { pin('shop', cfg.origin, cfg.delivery ? cfg.brand : 'Hier sind wir'); pts.push(cfg.origin); }
	if (cfg.dest) { pin('home', cfg.dest, 'Deine Adresse'); pts.push(cfg.dest); }
	var driver = null;
	function fit() { if (pts.length > 1) { map.fitBounds(pts, { paddingTopLeft: [64, 54], paddingBottomRight: [64, 30], maxZoom: 16 }); } else if (pts.length) { map.setView(pts[0], 16); } }
	fit();

	function ago(s) { return s < 25 ? 'gerade eben' : (s < 90 ? 'vor ' + Math.round(s) + ' Sekunden' : 'vor ' + Math.round(s / 60) + ' Minuten'); }
	function say(d) {
		if (!note || !cfg.delivery) { return; }
		if (d) { note.textContent = 'Dein Fahrer ist unterwegs zu dir. Standort ' + ago(d.age) + ' aktualisiert.'; }
		else if (cfg.status === 'delivering') { note.textContent = 'Dein Fahrer ist unterwegs zu dir.'; }
		else { note.textContent = ''; }
	}
	function update(d) {
		if (d && typeof d.lat === 'number') {
			var ll = [d.lat, d.lng];
			if (!driver) { driver = pin('driver', ll, 'Dein Fahrer'); } else { driver.setLatLng(ll); }
			pts = [cfg.origin, cfg.dest, ll].filter(Boolean);
			if (!map.getBounds().pad(-0.1).contains(ll)) { fit(); }
		} else if (driver) { map.removeLayer(driver); driver = null; pts = [cfg.origin, cfg.dest].filter(Boolean); }
		say(d);
	}
	update(cfg.driver || null);
	window.StatusMap = { update: update };
})();
