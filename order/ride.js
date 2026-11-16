/* Zeus Ride (order/ride.php): pixel game on a canvas of 180 x 320 points, scaled by whole numbers. Der Roller fährt durch Hildesheim, sammelt Zeus-Münzen (die Dogge), holt Schnitzel (Leben) und Boosts.
   Aus der Prototyp-Datei (pixel.html) erzeugt; Tempo 100, Lenkung 2,6, 75 Sekunden. Der Server (web/classes/shop_ride.class.php) gibt die Laufnummer und macht den Gutschein ab der eingestellten Münzzahl;
   dieses Skript meldet nur die Münzen (ride_finish). Die Zahl der Münzen auf der Strecke (NTOK, 79) steht auch im Server (SHOP_RIDE_COINS): ändert sich die Strecke, müssen beide passen.
   Die Test-Schalter der Adresse (?ap=1 Autopilot, ?at= Startpunkt, ?sim= Tempo, ?life=) gelten nur für Mitarbeiter mit ?dev=1 auf der Seite. */
(function () {
	'use strict';
	var B = document.body.dataset, DEV = B.dev === '1', Q = new URLSearchParams(DEV ? location.search : '');
	function num(k, d) { var v = parseFloat(Q.get(k)); return isFinite(v) ? v : d; }
	var W = 180, H = 320, STEP = 1 / 60, CH = 256;
	var SPEED = num('speed', 100), TURN = num('turn', 2.6), TIME = num('time', 75), WSCALE = num('w', 1), DEBUG = Q.get('debug') === '1', MAP = Q.get('map') === '1', SIM = num('sim', 1), AP = Q.get('ap') === '1', START = num('at', 0), LIFE0 = num('life', 100);
	var FEEL = Q.get('feel') === 'alt' ? 'alt' : 'neu', CTL = Q.get('ctl') === 'halves' ? 'halves' : Q.get('ctl') === 'fixed' ? 'fixed' : 'drag', HAND = Q.get('hand') === 'l' ? 'l' : 'r', FX = HAND === 'l' ? 38 : W - 38, FY = H - 48;
	var REDUCED = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
	var cv = document.getElementById('c'), ctx = cv.getContext('2d'); ctx.imageSmoothingEnabled = false;

	// ---------- palette (dusk Miami, our own colours)
	var C = { bg: '#120d24', ground: '#1c1631', ground2: '#241d40', ground3: '#171227', road: '#2d2745', road2: '#3e3660', road3: '#211b36', walk: '#4a3f78', pink: '#ff4fa3', teal: '#19d3c5', gold: '#ffd45a', ink: '#f4eaff', dim: '#b9aadf', dark: '#0b0716', cream: '#f3e1d3', beam: '#4a2a3a', hp: '#7dff9a' };
	var ROOFS = [['#ff4fa3', '#ff9bc9', '#b52a73'], ['#19d3c5', '#7cf2e8', '#0c8e86'], ['#ffb347', '#ffd98f', '#c07a1a'], ['#8f6bff', '#bda9ff', '#5a3fc4'], ['#ff6f61', '#ffa398', '#b8402f'], ['#2aa7ff', '#86cfff', '#1a6fb0'], ['#e9e2ff', '#ffffff', '#a9a0cf']];

	// ---------- pixel font 5x7 (rows as 5-bit numbers)
	var F = { ' ': [0, 0, 0, 0, 0, 0, 0], A: [14, 17, 17, 31, 17, 17, 17], B: [30, 17, 17, 30, 17, 17, 30], C: [14, 17, 16, 16, 16, 17, 14], D: [30, 17, 17, 17, 17, 17, 30], E: [31, 16, 16, 30, 16, 16, 31], F: [31, 16, 16, 30, 16, 16, 16], G: [14, 17, 16, 23, 17, 17, 15], H: [17, 17, 17, 31, 17, 17, 17], I: [14, 4, 4, 4, 4, 4, 14], J: [7, 2, 2, 2, 2, 18, 12], K: [17, 18, 20, 24, 20, 18, 17], L: [16, 16, 16, 16, 16, 16, 31], M: [17, 27, 21, 21, 17, 17, 17], N: [17, 17, 25, 21, 19, 17, 17], O: [14, 17, 17, 17, 17, 17, 14], P: [30, 17, 17, 30, 16, 16, 16], Q: [14, 17, 17, 17, 21, 18, 13], R: [30, 17, 17, 30, 20, 18, 17], S: [15, 16, 16, 14, 1, 1, 30], T: [31, 4, 4, 4, 4, 4, 4], U: [17, 17, 17, 17, 17, 17, 14], V: [17, 17, 17, 17, 17, 10, 4], W: [17, 17, 17, 21, 21, 27, 17], X: [17, 17, 10, 4, 10, 17, 17], Y: [17, 17, 10, 4, 4, 4, 4], Z: [31, 1, 2, 4, 8, 16, 31],
		'0': [14, 17, 19, 21, 25, 17, 14], '1': [4, 12, 4, 4, 4, 4, 14], '2': [14, 17, 1, 2, 4, 8, 31], '3': [30, 1, 1, 14, 1, 1, 30], '4': [2, 6, 10, 18, 31, 2, 2], '5': [31, 16, 30, 1, 1, 17, 14], '6': [6, 8, 16, 30, 17, 17, 14], '7': [31, 1, 2, 4, 8, 8, 8], '8': [14, 17, 17, 14, 17, 17, 14], '9': [14, 17, 17, 15, 1, 2, 12],
		'.': [0, 0, 0, 0, 0, 12, 12], ',': [0, 0, 0, 0, 12, 4, 8], ':': [0, 12, 12, 0, 12, 12, 0], '!': [4, 4, 4, 4, 4, 0, 4], '/': [1, 1, 2, 4, 8, 16, 16], '%': [24, 25, 2, 4, 8, 19, 3], '-': [0, 0, 0, 31, 0, 0, 0], '+': [0, 4, 4, 31, 4, 4, 0], 'Ä': [10, 0, 14, 17, 31, 17, 17], 'Ö': [10, 0, 14, 17, 17, 17, 14], 'Ü': [10, 0, 17, 17, 17, 17, 14], '&': [12, 18, 20, 8, 21, 18, 13] };
	function drawStr(str, x, y, sc, col) { ctx.fillStyle = col; for (var i = 0; i < str.length; i++) { var g = F[str[i]] || F[' ']; for (var r = 0; r < 7; r++) { var bits = g[r]; if (!bits) { continue; } for (var c = 0; c < 5; c++) { if ((bits >> (4 - c)) & 1) { ctx.fillRect(x + i * 6 * sc + c * sc, y + r * sc, sc, sc); } } } } }
	function tx(str, x, y, sc, col, sh, align) { str = String(str).toUpperCase(); var w = str.length * 6 * sc - sc; if (align === 'center') { x = Math.round(x - w / 2); } else if (align === 'right') { x = Math.round(x - w); } if (sh) { drawStr(str, x + sc, y + sc, sc, sh); } drawStr(str, x, y, sc, col); return w; }

	// ---------- sprites from text rows
	function fromRows(rows, map) { var h = rows.length, w = rows[0].length, c = document.createElement('canvas'); c.width = w; c.height = h; var g = c.getContext('2d'); for (var y = 0; y < h; y++) { for (var x = 0; x < w; x++) { var m = map[rows[y][x]]; if (m) { g.fillStyle = m; g.fillRect(x, y, 1, 1); } } } return c; }
	function rotSprite(src, ang) {
		var w = src.width, h = src.height, d = Math.ceil(Math.hypot(w, h)) + 2; if (d % 2) { d++; }
		var out = document.createElement('canvas'); out.width = out.height = d; var o = out.getContext('2d');
		var sd = src.getContext('2d').getImageData(0, 0, w, h).data, od = o.createImageData(d, d), q = od.data, ca = Math.cos(ang), sa = Math.sin(ang), dc = d / 2;
		for (var y = 0; y < d; y++) { for (var x = 0; x < d; x++) { var dx = x + 0.5 - dc, dy = y + 0.5 - dc, sx = Math.floor(ca * dx + sa * dy + w / 2), sy = Math.floor(-sa * dx + ca * dy + h / 2); if (sx < 0 || sy < 0 || sx >= w || sy >= h) { continue; } var si = (sy * w + sx) * 4, di = (y * d + x) * 4; q[di] = sd[si]; q[di + 1] = sd[si + 1]; q[di + 2] = sd[si + 2]; q[di + 3] = sd[si + 3]; } }
		o.putImageData(od, 0, 0); return out;
	}
	function shadowOf(src) { var c = document.createElement('canvas'); c.width = src.width; c.height = src.height; var g = c.getContext('2d'); g.drawImage(src, 0, 0); g.globalCompositeOperation = 'source-in'; g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(0, 0, c.width, c.height); return c; }

	var SCOOT = fromRows([
		'.....kkk.....', '.....kkk.....', '.o...kkk...o.', '.o..SWYWS..o.', '.HHHHHHHHHHH.', '..jSWWWWWSj..', '..jSWWWWWSj..', '..jjjjjjjjj..',
		'..jjjkkkjjj..', '..jjTtTTTjj..', '..jjTTTTTjj..', '..jjjTTTjjj..', '...jjjjjjj...', '....WWWWW....', '.bbbbbbbbbbb.', '.bKKKKGGKKKb.',
		'.bKKKGGKKKKb.', '.bKKGGGGKKKb.', '.bKKKKGGKKKb.', '.bKKKGGKKKKb.', '.bKKKKKKKKKb.', '.bbbRRRRRbbb.', '.....kkk.....', '.....kkk.....'],
		{ k: '#2a2442', b: '#5a5080', K: '#120d1c', o: '#9a90c0', H: '#1b1428', S: '#cfc6ea', W: '#fbf8ff', Y: '#fff3a8', j: '#3a2f7a', T: '#19d3c5', t: '#8ff7ee', G: '#ffcf4a', R: '#ff3b5c' });
	var SC = [], SSH = [];
	for (var ai = 0; ai < 16; ai++) { var r0 = rotSprite(SCOOT, ai * Math.PI / 8); SC.push(r0); SSH.push(shadowOf(r0)); }
	// the Zeus coin: the head of the Great Dane (black ears and mask, dark muzzle with light lips) on a gold coin
	var COIN = fromRows(['....ooooo....', '..oohhGGGoo..', '.ohFFFFFFFGo.', 'ohFknnnnnkFGo', 'ohkkfkfkfkkGo', 'ohkkfffffkkGo', 'oGkkfkkkfkkdo', 'oGFkfgkgfkFdo', 'oGFFfgkgfFFdo', 'oGFFffkffFFdo', '.oGFFfffFFdo.', '..oodddddoo..', '....ooooo....'],
		{ o: '#4a2608', G: '#f5b93a', h: '#fff3a8', d: '#c47a1c', F: '#ffe9a0', f: '#c58a3f', n: '#8e5a22', k: '#1a1210', g: '#b8b0a8' });
	var CONE = fromRows(['.ooooo.', 'oOOOOOo', 'oOwwwOo', 'oOwOwOo', 'oOwwwOo', 'oOOOOOo', '.ooooo.'], { o: '#5a2a10', O: '#ff7a1a', w: '#fff6ea' });
	var COINSH = shadowOf(COIN);
	var PKS = {
		blitz: fromRows(['...ooooo...', '..oBBBBBo..', '.oBBBGGBBo.', 'oBBBGGBBBBo', 'oBBGGGGBBBo', 'oBBBBGGBBBo', 'oBBBGGBBBBo', 'oBBGGBBBBBo', '.oBBBBBBBo.', '..oBBBBBo..', '...ooooo...'], { o: '#19d3c5', B: '#2b1d6e', G: '#ffe45a' }),
		magnet: fromRows(['...ooooo...', '..oBBBBBo..', '.oBWWBWWBo.', 'oBBWWBWWBBo', 'oBBRRBRRBBo', 'oBBRRBRRBBo', 'oBBRRBRRBBo', 'oBBRRRRRBBo', '.oBBRRRBBo.', '..oBBBBBo..', '...ooooo...'], { o: '#ff4fa3', B: '#4a1540', W: '#f4eaff', R: '#e8344e' }),
		schild: fromRows(['...ooooo...', '..oBBBBBo..', '.oCCCCCCCo.', 'oBCCCGCCCBo', 'oBCGGGGGCBo', 'oBCCCGCCCBo', 'oBCCCGCCCBo', 'oBBCCGCCBBo', '.oBBCCCBBo.', '..oBBCBBo..', '...ooooo...'], { o: '#ffe45a', B: '#0d3a5a', C: '#6fd6ff', G: '#ffe45a' }),
		food: fromRows(['...ooooo...', '..oPPPPPo..', '.oPMMMMPPo.', 'oPMmmMMMPPo', 'oMmMMmMMMPo', 'oMMmMMMmMPo', 'oPMMMmMMLLo', 'oPPMMMMLLLo', '.oPPMMMLLo.', '..oPPPPPo..', '...ooooo...'], { o: '#7dff9a', P: '#efe6ff', M: '#c47a1c', m: '#f0b24a', L: '#fff06a' })
	};
	var HEART = fromRows(['.hhh.hhh.', 'hHhhhhhhh', 'hhhhhhhhh', 'hhhhhhhhh', '.hhhhhhh.', '..hhhhh..', '...hhh...', '....h....'], { h: '#ff3b5c', H: '#ffd0e6' });
	// Zeus, the Great Dane: left half of the face, mirrored
	var ZL = ['......nnnnnn', '....nnfffffF', '...nffffffFF', '..kkffffffFF', '.kkkkfffffFF', '.kkkkffffffF', '.kkkkfffffff', '.kkkkffnnfff', '.kkkkfkkkkff', '.kkkkfkkFkff', '.kkkkfkkkkfn', '.kkkkffkkffn', '..kkkffffkkk', '..kkkfffgkkk', '..kkkffffgkk', '..kkkffffgkk', '...kkffffgkk', '....kffffgkk', '.....ffffgkk', '.....fffffgk', '.....ffffffk', '.....fffffff', '......ffffff', '......fffffn', '.....nffffFF', '....nfffffFF', '...nfffffFFF', '..nnffffFFFF'];
	var ZEUSP = fromRows(ZL.map(function (l) { return l + l.split('').reverse().join(''); }), { k: '#17110f', n: '#5a3b1a', f: '#a8692a', F: '#d9a05a', g: '#9a948c', w: '#f3efe8' });

	// ---------- the route: turtle steps [turn in degrees, length in px, half width of the road]
	var STEPS = [[0, 400, 34], [90, 260, 34], [-90, 380, 34], [-90, 300, 34], [90, 420, 34], [90, 240, 26], [-90, 360, 34], [-45, 300, 34], [45, 360, 34], [90, 260, 34], [90, 300, 34], [-90, 420, 26], [-90, 260, 34], [90, 360, 34], [45, 280, 34], [-45, 380, 34], [-90, 300, 34], [90, 520, 34]];
	var segs = [], a = -Math.PI / 2, px = 0, py = 0, cum = 0;
	STEPS.forEach(function (s) { a += s[0] * Math.PI / 180; var nx = px + Math.cos(a) * s[1], ny = py + Math.sin(a) * s[1]; segs.push({ x0: px, y0: py, x1: nx, y1: ny, a: a, len: s[1], hw: s[2] * WSCALE, s0: cum }); cum += s[1]; px = nx; py = ny; });
	var TOTAL = cum;
	function at(s, lat) { s = Math.max(0, Math.min(TOTAL - 0.01, s)); var g = segs[0]; for (var i = 0; i < segs.length; i++) { if (s >= segs[i].s0) { g = segs[i]; } } var t = s - g.s0, cx = g.x0 + Math.cos(g.a) * t, cy = g.y0 + Math.sin(g.a) * t; return { x: cx - Math.sin(g.a) * (lat || 0), y: cy + Math.cos(g.a) * (lat || 0), a: g.a, hw: g.hw }; }
	function edgeDist(x, y) { var m = 1e9; for (var i = 0; i < segs.length; i++) { var g = segs[i], dx = g.x1 - g.x0, dy = g.y1 - g.y0, t = Math.max(0, Math.min(1, ((x - g.x0) * dx + (y - g.y0) * dy) / (g.len * g.len))), d = Math.hypot(x - (g.x0 + dx * t), y - (g.y0 + dy * t)) - g.hw; if (d < m) { m = d; } } return m; }

	// ---------- things on the route (same as the grey version)
	var RAMP_AT = [470, 1120, 2430, 3090, 3680, 4340, 5350];
	var ramps = RAMP_AT.map(function (s) { var p = at(s, 0); return { s: s, x: p.x, y: p.y, a: p.a, hw: p.hw }; });
	var barriers = ramps.map(function (r) { var p = at(r.s + 30, 0); return { x: p.x, y: p.y, a: p.a, hw: p.hw }; });
	var tokens = [], s;
	function nearRamp(s) { return RAMP_AT.some(function (r) { return s > r - 40 && s < r + 90; }); }
	for (s = 120; s < TOTAL - 80; s += 84) { if (nearRamp(s)) { continue; } var p = at(s, Math.sin(s / 90) * 15 * WSCALE); tokens.push({ x: p.x, y: p.y, air: false, got: false }); }
	ramps.forEach(function (r) { [10, 24, 38].forEach(function (d) { var p = at(r.s + d, 0); tokens.push({ x: p.x, y: p.y, air: true, got: false }); }); });
	var cones = [];
	for (s = 330; s < TOTAL - 150; s += 470) { if (nearRamp(s) || nearRamp(s + 30)) { continue; } var side = (cones.length % 2 ? -1 : 1) * 14 * WSCALE; [0, 26].forEach(function (d, k) { var p = at(s + d, side * (k ? -0.4 : 1)); cones.push({ x: p.x, y: p.y, hit: false }); }); }
	// pickups on the route: the Schnitzel heals, the other three are boosts
	var PK = { blitz: { name: 'Blitz-Turbo', t: 2.4, c: '#ffe45a' }, magnet: { name: 'Magnet', t: 7, c: C.pink }, schild: { name: 'Zeus-Schild', t: 6, c: '#6fd6ff' }, food: { name: 'Schnitzel +30', t: 0, c: C.hp } };
	var PK_ORDER = ['blitz', 'magnet', 'food', 'schild', 'blitz', 'food', 'magnet', 'blitz', 'food', 'schild', 'magnet', 'blitz'];
	var pickups = [];
	(function () { var ps = 560; PK_ORDER.forEach(function (type, k) { var tries = 0, q; while (tries++ < 14) { q = at(ps, 0); if (!nearRamp(ps) && !nearRamp(ps + 36) && !cones.some(function (c) { return Math.hypot(c.x - q.x, c.y - q.y) < 36; })) { break; } ps += 36; } pickups.push({ type: type, x: q.x, y: q.y, x0: q.x, y0: q.y, got: false, ph: k * 1.7 }); ps += 410; }); })();
	tokens = tokens.filter(function (t) { return !pickups.some(function (q) { return Math.hypot(q.x - t.x, q.y - t.y) < 16; }); });
	tokens.forEach(function (t) { t.x0 = t.x; t.y0 = t.y; });
	var NTOK = tokens.length;
	// landmarks of Hildesheim beside the road: a sign shows the name when the scooter passes
	// each one stands straight ahead of a corner, so the road bends around it and it is in the middle of the picture when the scooter comes near
	var LM = [{ name: 'Marktplatz', seg: 4, half: 60, kind: 'plaza' }, { name: 'Dom & Rosenstock', seg: 8, half: 58, kind: 'dom' }, { name: 'St. Michaelis', seg: 12, half: 66, kind: 'mich' }, { name: 'St. Andreas', seg: 16, half: 56, kind: 'andreas' }];
	LM.forEach(function (l) { var g = segs[l.seg], d = g.hw + 10 + l.half; l.x = Math.round(g.x1 + Math.cos(g.a) * d); l.y = Math.round(g.y1 + Math.sin(g.a) * d); l.s = g.s0 + g.len - 20; l.shown = false; });

	// ---------- world: drawn once in chunks of 256 px, with hard pixel edges
	function hash(x, y) { var h = (x * 374761393 + y * 668265263) | 0; h = (h ^ (h >> 13)) * 1274126177 | 0; return ((h ^ (h >> 16)) >>> 0) / 4294967295; }
	function bevel(g, x, y, w, h, pal) { g.fillStyle = pal[0]; g.fillRect(x, y, w, h); g.fillStyle = pal[1]; g.fillRect(x, y, w, 1); g.fillRect(x, y, 1, h); g.fillStyle = pal[2]; g.fillRect(x, y + h - 1, w, 1); g.fillRect(x + w - 1, y, 1, h); }
	function ridge(g, x, y, w, h, pal, vert) { g.fillStyle = pal[2]; if (vert) { g.fillRect(x + Math.floor(w / 2) - 1, y + 1, 2, h - 2); } else { g.fillRect(x + 1, y + Math.floor(h / 2) - 1, w - 2, 2); } }
	var FW = [['#c9503f', '#f08a6e', '#8e2f2a'], ['#b8453a', '#e87c63', '#7d2a2a'], ['#a8503a', '#e0906a', '#7a3322']];   // tiled roofs of the old town
	function building(g, gx, gy) {
		var hv = hash(gx, gy + 7), h2 = hash(gx + 3, gy), h3 = hash(gx, gy + 11), h4 = hash(gx + 9, gy + 5), pal, k, px;
		var ix = 3 + Math.floor(h2 * 5), iy = 3 + Math.floor(h3 * 5), w = 40 - ix - 3 - Math.floor(h3 * 5), h = 40 - iy - 3 - Math.floor(h2 * 5), x = gx * 40 + ix, y = gy * 40 + iy;
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, y + 3, w, h);
		if (h4 < 0.5) {   // timber-framed house of the old town: tiled roof, front with beams and lit windows (seen from the south)
			var fh = 8, rh = h - fh; pal = FW[Math.floor(hv * FW.length) % FW.length];
			bevel(g, x, y, w, rh, pal); ridge(g, x, y, w, rh, pal, hv > 0.5);
			g.fillStyle = 'rgba(6,3,16,.3)';
			if (hv > 0.5) { for (k = x + 3; k < x + w - 2; k += 3) { if (Math.abs(k - (x + (w >> 1))) > 1) { g.fillRect(k, y + 1, 1, rh - 2); } } }
			else { for (k = y + 3; k < y + rh - 2; k += 3) { if (Math.abs(k - (y + (rh >> 1))) > 1) { g.fillRect(x + 1, k, w - 2, 1); } } }
			if (h2 > 0.5) { g.fillStyle = '#3a2f4a'; g.fillRect(x + w - 9, y + 4, 4, 4); g.fillStyle = '#6a5a7a'; g.fillRect(x + w - 9, y + 4, 4, 1); }
			g.fillStyle = C.cream; g.fillRect(x, y + rh, w, fh); g.fillStyle = C.beam; g.fillRect(x, y + rh, w, 1); g.fillRect(x, y + h - 1, w, 1);
			for (px = x; px < x + w; px += 6) { g.fillRect(px, y + rh, 1, fh); } g.fillRect(x + w - 1, y + rh, 1, fh);
			for (px = x + 2; px < x + w - 4; px += 6) { g.fillStyle = hash(px, y) > 0.3 ? '#ffd45a' : '#7d72a6'; g.fillRect(px, y + rh + 2, 2, 4); }
			return;
		}
		pal = ROOFS[Math.floor(hv * ROOFS.length) % ROOFS.length];
		bevel(g, x, y, w, h, pal); ridge(g, x, y, w, h, pal, hv > 0.5);
		if (h2 > 0.55) { g.fillStyle = '#cfc8e6'; g.fillRect(x + 4, y + 4, 6, 5); g.fillStyle = '#7d72a6'; g.fillRect(x + 4, y + 8, 6, 1); }
		if (h3 > 0.6) { g.fillStyle = '#7be7ff'; g.fillRect(x + w - 10, y + h - 10, 5, 5); g.fillStyle = '#e9fbff'; g.fillRect(x + w - 10, y + h - 10, 5, 1); }
		if (hv > 0.78) { g.fillStyle = hv > 0.9 ? C.pink : C.teal; g.fillRect(x + 2, y + h - 2, w - 4, 1); }
	}
	function palm(g, x, y) {
		x = Math.round(x); y = Math.round(y); g.fillStyle = 'rgba(6,3,16,.45)'; g.fillRect(x - 4, y + 1, 11, 6);
		var D = [[0, -1], [1, -1], [1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1]];
		D.forEach(function (d, k) { var len = (k % 2 ? 6 : 8), f = (k % 2 ? 0.75 : 1); for (var i = 1; i <= len; i++) { var X = x + Math.round(d[0] * i * f), Y = y + Math.round(d[1] * i * f), th = i < 6 ? 3 : 2; g.fillStyle = i < 3 ? '#0e6b4a' : i < 6 ? '#1fb877' : '#5dffb0'; g.fillRect(X - 1, Y - 1, th, th); } });
		g.fillStyle = '#0a4a34'; g.fillRect(x - 2, y - 2, 5, 5); g.fillStyle = '#c98a3a'; g.fillRect(x - 1, y - 1, 3, 3);
	}
	function disc(g, cx, cy, r, col) { g.fillStyle = col; for (var y = -r; y <= r; y++) { for (var x = -r; x <= r; x++) { if (x * x + y * y <= r * r + r * 0.4) { g.fillRect(cx + x, cy + y, 1, 1); } } } }
	function roofBlock(g, x, y, w, h, pal, vert) { g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, y + 3, w, h); bevel(g, x, y, w, h, pal); ridge(g, x, y, w, h, pal, vert); }
	function rose(g, x, y) {   // rose bush: Hildesheim and its Rosenstock
		x = Math.round(x); y = Math.round(y); g.fillStyle = 'rgba(6,3,16,.45)'; g.fillRect(x - 4, y + 2, 11, 6);
		disc(g, x, y, 6, '#0e4a34'); disc(g, x - 1, y - 1, 5, '#17803f');
		[[-4, -2, 0], [0, -4, 1], [3, -1, 0], [-2, 2, 1], [2, 3, 0], [-5, 1, 2], [4, -4, 2], [0, 0, 1]].forEach(function (q) { g.fillStyle = q[2] === 0 ? '#ff4fa3' : q[2] === 1 ? '#ff9bc9' : '#e8344e'; g.fillRect(x + q[0], y + q[1], 2, 2); g.fillStyle = '#fff0f6'; g.fillRect(x + q[0], y + q[1], 1, 1); });
	}
	function pyramid(g, x, y, s, pal) {   // hip roof seen from above: four facets, lit from the top left (pal: left, top, right, bottom)
		var hs = s / 2, i, j, dx, dy;
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, y + 3, s, s);
		for (j = 0; j < s; j++) { for (i = 0; i < s; i++) { dx = i + 0.5 - hs; dy = j + 0.5 - hs; g.fillStyle = Math.abs(dx) > Math.abs(dy) ? (dx < 0 ? pal[0] : pal[2]) : (dy < 0 ? pal[1] : pal[3]); g.fillRect(x + i, y + j, 1, 1); } }
		g.fillStyle = pal[1]; g.fillRect(x + (s >> 1) - 1, y + (s >> 1) - 1, 2, 2); g.fillStyle = 'rgba(6,3,16,.7)'; g.fillRect(x, y, s, 1); g.fillRect(x, y + s - 1, s, 1); g.fillRect(x, y, 1, s); g.fillRect(x + s - 1, y, 1, s);
	}
	function gable(g, x, yB, w, n, o) {   // timber house with its gable to the square, n storeys, seen from the south; yB is the foot line
		var sh = 6, fh = n * sh, gh = Math.round(w * 0.5), top = yB - fh - gh, r, i, b, rw, rx, sy, wx, bay = Math.max(2, Math.floor(w / 6));
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, top + 3, w, fh + gh);
		for (r = 0; r < gh; r++) { rw = Math.max(2, Math.round(w * (r + 1) / gh)); rx = x + ((w - rw) >> 1); g.fillStyle = r % 3 === 2 ? o.roof[2] : o.roof[0]; g.fillRect(rx, top + r, rw, 1); g.fillStyle = o.roof[1]; g.fillRect(rx, top + r, 1, 1); }
		g.fillStyle = o.cream; g.fillRect(x, top + gh, w, fh);
		for (i = 0; i < n; i++) {
			sy = top + gh + i * sh;
			g.fillStyle = (o.gold && i % 2) ? '#c47a1c' : o.beam; g.fillRect(x, sy, w, 1);
			g.fillStyle = 'rgba(6,3,16,.28)'; g.fillRect(x, sy + 1, w, 1);
			g.fillStyle = o.beam; for (b = 0; b <= bay; b++) { g.fillRect(x + Math.round(b * (w - 1) / bay), sy, 1, sh); }
			for (b = 0; b < bay; b++) { wx = x + Math.round(b * (w - 1) / bay) + 2; if (i === n - 1 && Math.abs(wx + 1 - (x + (w >> 1))) < 5) { continue; } g.fillStyle = hash(wx, sy) > 0.25 ? '#ffd45a' : '#7d72a6'; g.fillRect(wx, sy + 2, 2, 3); }
		}
		g.fillStyle = o.beam; g.fillRect(x, yB - 1, w, 1); g.fillStyle = '#3a2f7a'; g.fillRect(x + ((w - 4) >> 1), yB - 6, 4, 5); g.fillStyle = C.gold; g.fillRect(x + ((w - 4) >> 1), yB - 7, 4, 1);
	}
	function rathaus(g, x, yB, w) {   // Gothic town hall: slate roof, stone front with pointed arches, a small red spire
		var fh = 24, rh = 16, top = yB - fh - rh, i, n = Math.floor((w - 4) / 8), ax, r, sw;
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, top + 3, w, fh + rh);
		bevel(g, x, top, w, rh, ['#5d6f9a', '#8ea2d6', '#3d4c75']); ridge(g, x, top, w, rh, ['', '', '#3d4c75'], false);
		g.fillStyle = '#d8cdbd'; g.fillRect(x, top + rh, w, fh); g.fillStyle = '#b3a590'; g.fillRect(x, top + rh, w, 1); g.fillRect(x, yB - 1, w, 1);
		for (i = 0; i < n; i++) { ax = x + 4 + i * 8; g.fillStyle = '#2a2147'; g.fillRect(ax, yB - 12, 4, 11); g.fillRect(ax + 1, yB - 14, 2, 2); g.fillStyle = '#ffd45a'; g.fillRect(ax + 1, yB - 9, 2, 2); g.fillStyle = '#2a2147'; g.fillRect(ax, top + rh + 5, 4, 6); g.fillRect(ax + 1, top + rh + 3, 2, 2); g.fillStyle = '#ffd45a'; g.fillRect(ax + 1, top + rh + 7, 2, 2); }
		g.fillStyle = '#d8cdbd'; g.fillRect(x + w - 13, top - 4, 9, 8); g.fillStyle = '#b3a590'; g.fillRect(x + w - 13, top - 4, 1, 8);
		for (r = 0; r < 9; r++) { sw = Math.max(1, 9 - r); g.fillStyle = r % 2 ? '#8e2f2a' : '#c9503f'; g.fillRect(x + w - 13 + ((9 - sw) >> 1), top - 5 - r, sw, 1); }
		g.fillStyle = C.gold; g.fillRect(x + w - 9, top - 17, 1, 3);
	}
	var AMT = { cream: '#f6e6d4', beam: '#4a2a3a', roof: ['#c9503f', '#f08a6e', '#8e2f2a'], gold: true };
	var HOUSE2 = { cream: '#ffd0e6', beam: '#5a2a50', roof: ['#19b3a6', '#6bf0e4', '#0c8e86'] };
	var HOUSE3 = { cream: '#e9e2ff', beam: '#3a2f7a', roof: ['#ff8a4a', '#ffbd8f', '#b8502a'] };
	function plaza(g, x, y) {   // Marktplatz: Rathaus, Knochenhauer-Amtshaus and more timber houses on the north side, the Roland fountain in the middle
		var S = 120, x0 = x - 60, y0 = y - 60, yB = y0 + S - 4, fy = y - 18, i;
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x0 + 3, y0 + 3, S, S);
		g.fillStyle = '#7e5f95'; g.fillRect(x0, y0, S, S); g.fillStyle = '#cba6c0'; g.fillRect(x0 + 2, y0 + 2, S - 4, S - 4);
		g.fillStyle = '#b88fae'; for (i = 8; i < S - 2; i += 8) { g.fillRect(x0 + i, y0 + 2, 1, S - 4); g.fillRect(x0 + 2, y0 + i, S - 4, 1); }
		rathaus(g, x0 + 1, yB, 47); gable(g, x0 + 50, yB, 28, 5, AMT); gable(g, x0 + 80, yB, 22, 4, HOUSE2); gable(g, x0 + 104, yB, 15, 3, HOUSE3);
		disc(g, x, fy, 16, '#a99ac0'); disc(g, x, fy, 14, '#efe6ff'); disc(g, x, fy, 12, '#2aa7ff'); disc(g, x, fy, 9, '#7cc8ff');
		g.fillStyle = '#efe6ff'; g.fillRect(x - 3, fy - 3, 6, 6); g.fillStyle = '#a99ac0'; g.fillRect(x - 3, fy + 2, 6, 1);
		g.fillStyle = '#8f93b8'; g.fillRect(x - 1, fy - 11, 3, 8); g.fillStyle = C.gold; g.fillRect(x - 1, fy - 13, 3, 2); g.fillStyle = '#ffffff'; g.fillRect(x + 2, fy - 14, 1, 9);
		[[x0 + 8, y0 + 8], [x0 + S - 11, y0 + 8], [x0 + 8, y0 + 36], [x0 + S - 11, y0 + 36]].forEach(function (q) { g.fillStyle = C.pink; g.fillRect(q[0], q[1], 3, 3); g.fillStyle = '#ffd0e6'; g.fillRect(q[0] + 1, q[1] + 1, 1, 1); });
	}
	function dom(g, x, y) {   // Hildesheimer Dom: nave and transept, two west towers with the portal in the south, the apse with the thousand-year-old rose in the north
		var R = ['#1fb8a0', '#6bf0e4', '#0c8e86', '#0a6f6a'], DT = ['#16907f', '#9ffaf0', '#0a5f5a', '#074742'], a, ang;
		roofBlock(g, x - 13, y - 36, 26, 72, R, true); roofBlock(g, x - 38, y - 20, 76, 24, R, false);
		disc(g, x, y - 36, 13, R[0]); disc(g, x, y - 36, 9, R[1]); g.fillStyle = R[2]; g.fillRect(x - 1, y - 48, 2, 12);
		pyramid(g, x - 8, y - 16, 16, DT); pyramid(g, x - 13, y + 34, 13, DT); pyramid(g, x, y + 34, 13, DT);
		g.fillStyle = '#d8cdbd'; g.fillRect(x - 13, y + 47, 26, 10); g.fillStyle = '#b3a590'; g.fillRect(x - 13, y + 47, 26, 1); g.fillStyle = '#2a2147'; g.fillRect(x - 3, y + 50, 6, 7); g.fillRect(x - 2, y + 48, 4, 2); g.fillStyle = C.gold; g.fillRect(x - 3, y + 49, 6, 1);
		for (a = 0; a < 7; a++) { ang = Math.PI * (1.05 + a * 0.15); rose(g, x + Math.cos(ang) * 15, y - 36 + Math.sin(ang) * 15); }
	}
	function mich(g, x, y) {   // St. Michaelis: two transepts, two apses, two crossing towers, four stair turrets
		var R = ['#ff6f61', '#ffa398', '#b8402f', '#8e2f26'], Gd = ['#ffb347', '#ffd98f', '#c07a1a', '#9a5a10'];
		roofBlock(g, x - 16, y - 50, 32, 100, R, true); roofBlock(g, x - 40, y - 32, 80, 22, R, false); roofBlock(g, x - 40, y + 10, 80, 22, R, false);
		disc(g, x, y - 52, 14, R[0]); disc(g, x, y - 52, 10, R[1]); disc(g, x, y + 52, 14, R[0]); disc(g, x, y + 52, 10, R[1]);
		pyramid(g, x - 9, y - 30, 18, Gd); pyramid(g, x - 9, y + 12, 18, Gd);
		[[-38, -34], [38, -34], [-38, 34], [38, 34]].forEach(function (q) { disc(g, x + q[0], y + q[1], 7, '#9a5a10'); disc(g, x + q[0], y + q[1], 6, Gd[0]); disc(g, x + q[0], y + q[1], 3, Gd[1]); g.fillStyle = C.gold; g.fillRect(x + q[0], y + q[1], 1, 1); });
	}
	function andreas(g, x, y) {   // St. Andreas: hall church with the tall west tower; the long shadow of the spire shows how high it is
		var S = ['#6f86c0', '#bcd0ff', '#3a4c82', '#2c3a66'], B = ['#5d6f9a', '#8ea2d6', '#3d4c75'], j, wj;
		roofBlock(g, x - 26, y - 40, 10, 58, B, true); roofBlock(g, x + 16, y - 40, 10, 58, B, true);
		roofBlock(g, x - 17, y - 50, 34, 68, B, true);
		disc(g, x, y - 50, 12, B[0]); disc(g, x, y - 50, 8, B[1]);
		g.fillStyle = 'rgba(6,3,16,.34)'; for (j = 0; j < 64; j++) { wj = Math.max(2, 12 - (j >> 2)); g.fillRect(x + 8 + j, y + 24 + j, wj, 1); }
		g.fillStyle = '#9a9fba'; g.fillRect(x - 14, y + 12, 28, 28); g.fillStyle = '#7d82a0'; g.fillRect(x - 14, y + 12, 28, 1); g.fillRect(x - 14, y + 39, 28, 1); g.fillRect(x - 14, y + 12, 1, 28); g.fillRect(x + 13, y + 12, 1, 28);
		pyramid(g, x - 10, y + 16, 20, S); g.fillStyle = C.gold; g.fillRect(x - 1, y + 25, 2, 2);
	}
	var LMDRAW = { plaza: plaza, dom: dom, mich: mich, andreas: andreas };
	// signs beside the road: "Hildesheim liebt amadeus.delivery" on neon boards (readable from above, they stand on two posts)
	function gtext(g, str, x, y, sc, col) { g.fillStyle = col; str = String(str).toUpperCase(); for (var i = 0; i < str.length; i++) { var gl = F[str[i]] || F[' ']; for (var r = 0; r < 7; r++) { var bits = gl[r]; if (!bits) { continue; } for (var c = 0; c < 5; c++) { if ((bits >> (4 - c)) & 1) { g.fillRect(x + i * 6 * sc + c * sc, y + r * sc, sc, sc); } } } } }
	function signDims(sc) { var pad = 4 * sc, gap = 3 * sc, tw = 16 * 6 * sc - sc; return { sc: sc, pad: pad, gap: gap, tw: tw, w: pad * 2 + tw + gap + 9 * sc, h: pad * 2 + 14 * sc + gap, ht: pad * 2 + 14 * sc + gap + 6 * sc }; }
	function drawSign(g, sg) {
		var d = sg.d, x = sg.x, y = sg.y, sc = d.sc, w = d.w, h = d.h;
		g.fillStyle = 'rgba(6,3,16,.5)'; g.fillRect(x + 3, y + 3, w, d.ht);
		g.fillStyle = '#3a3355'; g.fillRect(x + 6 * sc, y + h, 2 * sc, 6 * sc); g.fillRect(x + w - 8 * sc, y + h, 2 * sc, 6 * sc);
		g.fillStyle = '#0b0716'; g.fillRect(x, y, w, h);
		g.fillStyle = C.pink; g.fillRect(x, y, w, sc); g.fillRect(x, y + h - sc, w, sc); g.fillRect(x, y, sc, h); g.fillRect(x + w - sc, y, sc, h);
		g.fillStyle = C.teal; g.fillRect(x + 2 * sc, y + sc * 2 - 1, w - 4 * sc, 1);
		gtext(g, 'Hildesheim liebt', x + d.pad, y + d.pad, sc, '#ffd0e6');
		g.drawImage(HEART, x + d.pad + d.tw + d.gap, y + d.pad - (sc >> 1), 9 * sc, 8 * sc);
		gtext(g, 'amadeus.delivery', x + d.pad + ((w - 2 * d.pad - d.tw) >> 1), y + d.pad + 7 * sc + d.gap, sc, C.gold);
	}
	var signs = [];
	function signFits(x, y, w, h) {   // free of the route, the landmarks and the other signs
		var xx, yy, i, l, o;
		for (yy = y - 6; yy <= y + h + 6; yy += 6) { for (xx = x - 6; xx <= x + w + 6; xx += 6) { if (edgeDist(xx, yy) < 10) { return false; } } }
		for (i = 0; i < LM.length; i++) { l = LM[i]; if (x + w > l.x - l.half - 24 && x < l.x + l.half + 24 && y + h > l.y - l.half - 30 && y < l.y + l.half + 30) { return false; } }
		for (i = 0; i < signs.length; i++) { o = signs[i]; if (x < o.x + o.d.w + 20 && x + w + 20 > o.x && y < o.y + o.d.ht + 20 && y + h + 20 > o.y) { return false; } }
		return true;
	}
	[[210, 1], [1020, 1], [2330, 1], [3480, 1], [4780, 1], [5880, 2]].forEach(function (c) {
		var d = signDims(c[1]), done = false;
		[0, 40, -40, 80, -80, 120, -120].forEach(function (da) {
			for (var lat = 20; lat <= 130 && !done; lat += 6) { [1, -1].forEach(function (sd) { if (done) { return; } var g0 = at(c[0] + da, 0), p = at(c[0] + da, sd * (g0.hw + lat)), x = Math.round(p.x - d.w / 2), y = Math.round(p.y - d.ht / 2); if (signFits(x, y, d.w, d.ht)) { signs.push({ x: x, y: y, d: d }); done = true; } }); }
		});
	});
	var staticSprites = [];
	function buildStatic() {
		ramps.forEach(function (r) { var w = Math.round(r.hw * 2), h = 28, c = document.createElement('canvas'); c.width = w; c.height = h; var g = c.getContext('2d');
			for (var y = 0; y < h; y++) { for (var x = 0; x < w; x++) { var k = Math.floor((y + Math.abs(x - w / 2) * 0.5) / 5) % 2; g.fillStyle = k ? C.pink : C.teal; g.fillRect(x, y, 1, 1); } }
			g.fillStyle = '#fff3fb'; g.fillRect(0, 0, w, 2); g.fillStyle = C.dark; g.fillRect(0, h - 2, w, 2); g.fillRect(0, 0, 1, h); g.fillRect(w - 1, 0, 1, h); g.fillRect(0, 2, w, 1);
			staticSprites.push({ c: rotSprite(c, r.a + Math.PI / 2), x: r.x, y: r.y }); });
		barriers.forEach(function (b) { var w = Math.round(b.hw * 2), h = 10, c = document.createElement('canvas'); c.width = w; c.height = h; var g = c.getContext('2d');
			for (var y = 0; y < h; y++) { for (var x = 0; x < w; x++) { g.fillStyle = Math.floor((x + y) / 5) % 2 ? '#e8344e' : '#fff1f5'; g.fillRect(x, y, 1, 1); } }
			g.fillStyle = C.dark; g.fillRect(0, 0, w, 1); g.fillRect(0, h - 1, w, 1); g.fillRect(0, 0, 3, h); g.fillRect(w - 3, 0, 3, h);
			staticSprites.push({ c: rotSprite(c, b.a + Math.PI / 2), x: b.x, y: b.y }); });
		var fp = at(TOTAL - 4, 0), fw = Math.round(fp.hw * 2), fc = document.createElement('canvas'); fc.width = fw; fc.height = 8; var fg = fc.getContext('2d');
		for (var yy = 0; yy < 8; yy++) { for (var xx = 0; xx < fw; xx++) { fg.fillStyle = (Math.floor(xx / 4) + Math.floor(yy / 4)) % 2 ? '#111' : '#fff'; fg.fillRect(xx, yy, 1, 1); } }
		staticSprites.push({ c: rotSprite(fc, fp.a + Math.PI / 2), x: fp.x, y: fp.y });
	}
	buildStatic();
	var TC = document.createElement('canvas'); TC.width = TC.height = CH; var tg = TC.getContext('2d', { willReadFrequently: true });
	function layer(g, X0, Y0, wfn, colfn, o) {
		tg.setTransform(1, 0, 0, 1, 0, 0); tg.clearRect(0, 0, CH, CH); tg.translate(-X0, -Y0); tg.lineJoin = 'round'; tg.lineCap = o.cap || 'round'; tg.setLineDash(o.dash || []);
		segs.forEach(function (sg, i) { tg.strokeStyle = colfn(i); tg.lineWidth = wfn(sg); tg.beginPath(); tg.moveTo(sg.x0, sg.y0); tg.lineTo(sg.x1, sg.y1); tg.stroke(); });
		tg.setTransform(1, 0, 0, 1, 0, 0);
		var id = tg.getImageData(0, 0, CH, CH), d = id.data;
		for (var p = 0; p < d.length; p += 4) {
			if (d[p + 3] < 128) { d[p + 3] = 0; continue; } d[p + 3] = 255;
			if (o.noise) { var n = hash(((p >> 2) % CH) + X0, Math.floor((p >> 2) / CH) + Y0); if (n > 0.985) { d[p] = 0x3e; d[p + 1] = 0x36; d[p + 2] = 0x60; } else if (n < 0.015) { d[p] = 0x21; d[p + 1] = 0x1b; d[p + 2] = 0x36; } }
				if (o.cobble) { var wx = ((p >> 2) % CH) + X0, wy = Math.floor((p >> 2) / CH) + Y0, rw = Math.floor(wy / 3), cxn = Math.floor((wx + (rw & 1) * 2) / 4);   // cobblestones: 4 x 3 px, every second row shifted
					if ((((wy % 3) + 3) % 3) === 0 || ((wx + (rw & 1) * 2) & 3) === 0) { d[p] = 0x35; d[p + 1] = 0x2c; d[p + 2] = 0x5c; } else { var hn = hash(cxn, rw); if (hn > 0.66) { d[p] = 0x56; d[p + 1] = 0x4a; d[p + 2] = 0x8c; } else if (hn < 0.33) { d[p] = 0x44; d[p + 1] = 0x3a; d[p + 2] = 0x70; } } }
		}
		tg.putImageData(id, 0, 0); g.drawImage(TC, X0, Y0);
	}
	var chunks = {};
	function renderChunk(cx, cy) {
		var c = document.createElement('canvas'); c.width = c.height = CH; var g = c.getContext('2d'); g.imageSmoothingEnabled = false;
		var X0 = cx * CH, Y0 = cy * CH, i, gx, gy, ss; g.translate(-X0, -Y0);
		g.fillStyle = C.ground; g.fillRect(X0, Y0, CH, CH);
		for (i = 0; i < 110; i++) { g.fillStyle = i % 3 ? C.ground2 : C.ground3; g.fillRect(X0 + Math.floor(hash(cx * 61 + i, cy) * CH), Y0 + Math.floor(hash(cx, cy * 61 + i + 999) * CH), 2, 1); }
		for (gy = Math.floor((Y0 - 40) / 40); gy <= Math.floor((Y0 + CH) / 40); gy++) {
			for (gx = Math.floor((X0 - 40) / 40); gx <= Math.floor((X0 + CH) / 40); gx++) {
				var mx = gx * 40 + 20, my = gy * 40 + 20; if (edgeDist(mx, my) < 38) { continue; }
				var near = LM.some(function (l) { return Math.hypot(mx - l.x, my - l.y) < l.half * 1.5 + 18; }); if (near || signs.some(function (sg) { return gx * 40 < sg.x + sg.d.w + 4 && gx * 40 + 40 > sg.x - 4 && gy * 40 < sg.y + sg.d.ht + 4 && gy * 40 + 40 > sg.y - 4; })) { continue; }
				var hv = hash(gx, gy);
				if (hv < 0.12) { g.fillStyle = '#12382c'; g.fillRect(gx * 40 + 4, gy * 40 + 4, 32, 32); g.fillStyle = '#17503c'; g.fillRect(gx * 40 + 4, gy * 40 + 4, 32, 2); palm(g, gx * 40 + 13, gy * 40 + 14); rose(g, gx * 40 + 27, gy * 40 + 27); }
				else if (hv > 0.17) { building(g, gx, gy); }
			}
		}
		LM.forEach(function (l) { if (Math.abs(l.x - (X0 + CH / 2)) < CH / 2 + 130 && Math.abs(l.y - (Y0 + CH / 2)) < CH / 2 + 130) { LMDRAW[l.kind](g, l.x, l.y); } });
			signs.forEach(function (sg) { if (sg.x < X0 + CH + 8 && sg.x + sg.d.w + 8 > X0 && sg.y < Y0 + CH + 8 && sg.y + sg.d.ht + 8 > Y0) { drawSign(g, sg); } });
		layer(g, X0, Y0, function (sg) { return sg.hw * 2 + 12; }, function () { return C.walk; }, { cobble: true });
		layer(g, X0, Y0, function (sg) { return sg.hw * 2 + 2; }, function (k) { return k % 2 ? C.teal : C.pink; }, {});
		layer(g, X0, Y0, function (sg) { return sg.hw * 2; }, function () { return C.road; }, { noise: true });
		layer(g, X0, Y0, function () { return 2; }, function () { return '#efe3ff'; }, { dash: [6, 8], cap: 'butt' });
		for (ss = 70; ss < TOTAL - 70; ss += 96) { if (nearRamp(ss) || hash(Math.floor(ss), 5) > 0.72) { continue; } var sd = (Math.floor(ss / 96) % 2 ? 1 : -1), pp = at(ss, sd * (at(ss, 0).hw + 15)); if (pp.x > X0 - 12 && pp.x < X0 + CH + 12 && pp.y > Y0 - 12 && pp.y < Y0 + CH + 12) { (hash(Math.floor(ss), 9) > 0.55 ? rose : palm)(g, pp.x, pp.y); } }
		staticSprites.forEach(function (q) { g.drawImage(q.c, Math.round(q.x - q.c.width / 2), Math.round(q.y - q.c.height / 2)); });
		return c;
	}
	var minx = 1e9, maxx = -1e9, miny = 1e9, maxy = -1e9;
	segs.forEach(function (g) { [[g.x0, g.y0], [g.x1, g.y1]].forEach(function (p) { minx = Math.min(minx, p[0]); maxx = Math.max(maxx, p[0]); miny = Math.min(miny, p[1]); maxy = Math.max(maxy, p[1]); }); });
	var queue = [];
	for (var qy = Math.floor((miny - 280) / CH); qy <= Math.floor((maxy + 280) / CH); qy++) { for (var qx = Math.floor((minx - 280) / CH); qx <= Math.floor((maxx + 280) / CH); qx++) { if (edgeDist(qx * CH + CH / 2, qy * CH + CH / 2) < 290) { queue.push([qx, qy]); } } }
	queue.sort(function (u, v) { return Math.hypot(u[0] * CH + 128, u[1] * CH + 128 - 100) - Math.hypot(v[0] * CH + 128, v[1] * CH + 128 - 100); });
	var QTOTAL = queue.length;

	// ---------- state
	var TIERS = (function () { try { return JSON.parse(B.tiers || '[]') || []; } catch (e) { return []; } })(), ELIG = B.eligible === '1', TOKEN = B.token || '', res = null, runId = '', winEl = document.getElementById('rd-win');
	var life = LIFE0, inv = 0, lifeFlash = 0, healFlash = 0, pk = 0, bacc = 0, pops = [], fx = { blitz: 0, magnet: 0, schild: 0 };
	var st = 'loading', cd = 0, tleft = TIME, got = 0, hitFlash = 0, shake = 0, endAt = 0, endWhy = '', banner = '', bannerT = 0, clock = 0, exh = 0;
	var car = { x: 0, y: 0, h: -Math.PI / 2, f: 1, z: 0, vz: 0, cur: 0, prog: 0, ramp: false, t: 0 }, parts = [], cam = { x: 0, y: -50 }, camSnap = true;
	function reset() { hideWin(); res = null; runId = ''; car.x = 0; car.y = 0; car.h = -Math.PI / 2; car.f = 1; car.z = 0; car.vz = 0; car.cur = 0; car.prog = 0; car.ramp = false; car.t = 0; tleft = TIME; got = 0; hitFlash = 0; shake = 0; banner = ''; bannerT = 0; parts.length = 0; camSnap = true; tokens.forEach(function (t) { t.got = false; }); cones.forEach(function (c) { c.hit = false; }); LM.forEach(function (l) { l.shown = false; }); life = LIFE0; inv = 0; lifeFlash = 0; healFlash = 0; pk = 0; bacc = 0; pops.length = 0; fx.blitz = fx.magnet = fx.schild = 0; pickups.forEach(function (q) { q.got = false; }); tokens.forEach(function (t) { t.x = t.x0; t.y = t.y0; });
		if (START > 0) { var sp0 = at(START, 0); car.x = sp0.x; car.y = sp0.y; car.h = sp0.a; car.prog = START; segs.forEach(function (g0, i0) { if (START >= g0.s0) { car.cur = i0; } }); } }
	function puff(n, x, y, cols, sp, life, size) { for (var i = 0; i < n; i++) { var an = Math.random() * 6.283, v = sp * (0.4 + Math.random() * 0.6); parts.push({ x: x, y: y, vx: Math.cos(an) * v, vy: Math.sin(an) * v, t: 0, life: life * (0.6 + Math.random() * 0.4), cols: cols, size: size }); } }

	// ---------- input: hold the left or right half of the screen, or the arrow keys / A and D
	var keys = {}, ptrs = {};
	function wrapA(d) { while (d > Math.PI) { d -= 2 * Math.PI; } while (d < -Math.PI) { d += 2 * Math.PI; } return d; }
	// keys: left / right. Finger: the direction of the drag is the direction the guest wants to drive (north is up); the scooter turns toward it, gently when it is nearly there
	function steer() {
		var v = 0;
		if (AP && st === 'play') { var tp = at(car.prog + 62, Math.sin((car.prog + 62) / 90) * 15 * WSCALE); return Math.max(-1, Math.min(1, wrapA(Math.atan2(tp.y - car.y, tp.x - car.x) - car.h) / 0.35)); }   // ?ap=1: drives itself (for testing)
		if (keys.ArrowLeft || keys.a || keys.A) { v -= 1; } if (keys.ArrowRight || keys.d || keys.D) { v += 1; }
		for (var k in ptrs) { var p = ptrs[k]; if (p.off) { continue; } if (CTL === 'halves') { v += p.x < W / 2 ? -1 : 1; } else { var dx = p.x - p.ox, dy = p.y - p.oy, L = Math.hypot(dx, dy), an = Math.atan2(dy, dx), turn = Math.max(-1, Math.min(1, wrapA(an - car.h) / 0.35));
				if (FEEL === 'alt') { if (L > 6) { v += turn; } }
				else if (L > 3) { v += turn * Math.pow(Math.min(1, (L - 3) / 13), 1.3); } } }
		return Math.max(-1, Math.min(1, v));
	}
	// feel "neu": the dead zone (3 px) only decides whether the stick is used at all; how far the finger is pulled sets how hard it steers (soft at first, then full at about 16 px)
	function primary() { if (st === 'ready') { begin(); } else if (st === 'pause') { st = 'play'; } else if (st === 'end' && performance.now() - endAt > 500 && !(winEl && !winEl.hidden)) { begin(); } }
	addEventListener('keydown', function (e) { keys[e.key] = true; if (e.key === ' ' || e.key === 'Enter') { primary(); e.preventDefault(); } if (e.key === 'p' || e.key === 'Escape') { if (st === 'play') { st = 'pause'; } } });
	addEventListener('keyup', function (e) { keys[e.key] = false; });
	var stage = document.getElementById('stage');
	function lpos(e) { var r = cv.getBoundingClientRect(); return { x: (e.clientX - r.left) / r.width * W, y: (e.clientY - r.top) / r.height * H }; }
	stage.addEventListener('pointerdown', function (e) { e.preventDefault(); var l = lpos(e), p = { x: l.x, y: l.y, ox: l.x, oy: l.y, off: false }; if (CTL === 'fixed') { p.ox = FX; p.oy = FY; p.off = Math.hypot(l.x - FX, l.y - FY) > 64; } ptrs[e.pointerId] = p; try { stage.setPointerCapture(e.pointerId); } catch (x) {} primary(); });
	stage.addEventListener('pointermove', function (e) { var p = ptrs[e.pointerId]; if (p) { var l = lpos(e); p.x = l.x; p.y = l.y; } });
	['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (n) { stage.addEventListener(n, function (e) { delete ptrs[e.pointerId]; }); });
	document.addEventListener('visibilitychange', function () { if (document.hidden && st === 'play') { st = 'pause'; } });
	addEventListener('contextmenu', function (e) { e.preventDefault(); });

	// ---------- update (same rules as the grey version)
	function nearest() { var best = null, lo = Math.max(0, car.cur - 1), hi = Math.min(segs.length - 1, car.cur + 2); for (var i = lo; i <= hi; i++) { var g = segs[i], dx = g.x1 - g.x0, dy = g.y1 - g.y0, t = ((car.x - g.x0) * dx + (car.y - g.y0) * dy) / (g.len * g.len); t = Math.max(0, Math.min(1, t)); var fx = g.x0 + dx * t, fy = g.y0 + dy * t, d = Math.hypot(car.x - fx, car.y - fy), over = d - g.hw; if (!best || over < best.over) { best = { over: over, fx: fx, fy: fy, d: d, i: i, s: g.s0 + t * g.len }; } } return best; }
	function update(dt) {
		for (var pi = parts.length - 1; pi >= 0; pi--) { var q = parts[pi]; q.t += dt; q.x += q.vx * dt; q.y += q.vy * dt; if (q.t >= q.life) { parts.splice(pi, 1); } }
		if (bannerT > 0) { bannerT -= dt; } for (var oi = pops.length - 1; oi >= 0; oi--) { pops[oi].t += dt; if (pops[oi].t > 1.2) { pops.splice(oi, 1); } }
		// the camera follows softly (a fixed distance ahead of the scooter would swing the whole picture with every turn)
		var la = 50 + (fx.blitz > 0 ? 18 : 0), cgx = car.x + Math.cos(car.h) * la, cgy = car.y + Math.sin(car.h) * la;
		if (camSnap) { cam.x = cgx; cam.y = cgy; camSnap = false; } else { var ck = 1 - Math.exp(-dt * 7); cam.x += (cgx - cam.x) * ck; cam.y += (cgy - cam.y) * ck; }
		if (st === 'count') { cd -= dt; if (cd <= 0) { st = 'play'; } return; }
		if (st !== 'play') { return; }
		car.t += dt; tleft -= dt; inv = Math.max(0, inv - dt); lifeFlash = Math.max(0, lifeFlash - dt); healFlash = Math.max(0, healFlash - dt); fx.blitz = Math.max(0, fx.blitz - dt); fx.magnet = Math.max(0, fx.magnet - dt); fx.schild = Math.max(0, fx.schild - dt);
		var air = car.z > 0.01, sv = steer();
		var bo = fx.blitz > 0 ? 1.45 : 1; car.h += sv * TURN * dt * (air ? 0.3 : 1) * (bo > 1 ? 1.18 : 1); car.f = Math.min(1, car.f + dt * 1.1);
		var v = SPEED * car.f * bo; car.x += Math.cos(car.h) * v * dt; car.y += Math.sin(car.h) * v * dt;
		if (air || car.vz > 0) { car.vz -= 270 * dt; car.z += car.vz * dt; if (car.z <= 0) { car.z = 0; car.vz = 0; puff(4, car.x, car.y, ['#d9d0f5', '#8e86a8'], 30, 0.3, 2); } }
		bacc += dt; if (bacc > 0.025 && fx.blitz > 0 && car.z === 0) { bacc = 0; parts.push({ x: car.x - Math.cos(car.h) * 11, y: car.y - Math.sin(car.h) * 11, vx: -Math.cos(car.h) * 40 + (Math.random() - 0.5) * 16, vy: -Math.sin(car.h) * 40 + (Math.random() - 0.5) * 16, t: 0, life: 0.3, cols: ['#ffffff', '#7cf2e8', '#ffe45a'], size: 2 }); }
			if (life < 30 && car.z === 0 && Math.random() < dt * 9) { parts.push({ x: car.x, y: car.y - 2, vx: (Math.random() - 0.5) * 10, vy: -14 - Math.random() * 10, t: 0, life: 0.6, cols: ['#8e86a8', '#5d5578', '#3a3355'], size: 2 }); }
			exh += dt; if (exh > 0.07 && car.z === 0) { exh = 0; parts.push({ x: car.x - Math.cos(car.h) * 10, y: car.y - Math.sin(car.h) * 10, vx: (Math.random() - 0.5) * 12, vy: (Math.random() - 0.5) * 12, t: 0, life: 0.35, cols: ['#e6defa', '#9a90bd', '#4a416e'], size: 2 }); }
		var n = nearest(); car.cur = n.i; car.prog = Math.max(car.prog, n.s);
		if (n.over > -4) { var push = n.over + 4, ux = (n.fx - car.x) / (n.d || 1), uy = (n.fy - car.y) / (n.d || 1); car.x += ux * push; car.y += uy * push; if (car.f > 0.5) { puff(REDUCED ? 3 : 6, car.x - ux * 6, car.y - uy * 6, [C.pink, '#fff3fb', C.teal], 55, 0.28, 2); hurt(3, car.x - ux * 6, car.y - uy * 6); } car.f = Math.min(car.f, 0.35); }
		var onGround = car.z < 4, inRamp = false;
		ramps.forEach(function (r) { var dx = car.x - r.x, dy = car.y - r.y, al = dx * Math.cos(r.a) + dy * Math.sin(r.a), ac = -dx * Math.sin(r.a) + dy * Math.cos(r.a); if (Math.abs(al) < 14 && Math.abs(ac) < r.hw) { inRamp = true; if (!car.ramp && car.z === 0) { car.vz = 95; car.z = 0.01; } } });
		car.ramp = inRamp;
		barriers.forEach(function (b) { var dx = car.x - b.x, dy = car.y - b.y, al = dx * Math.cos(b.a) + dy * Math.sin(b.a), ac = -dx * Math.sin(b.a) + dy * Math.cos(b.a); if (onGround && Math.abs(al) < 8 && Math.abs(ac) < b.hw) { car.x -= Math.cos(car.h) * 14; car.y -= Math.sin(car.h) * 14; car.f = 0.12; puff(REDUCED ? 3 : 8, car.x, car.y, ['#e8344e', '#fff1f5'], 60, 0.3, 2); hurt(12, car.x, car.y); } });
		cones.forEach(function (c) { if (!c.hit && onGround && Math.hypot(car.x - c.x, car.y - c.y) < 8) { c.hit = true; if (fx.blitz > 0) { puff(REDUCED ? 4 : 10, c.x, c.y, ['#ff7a1a', '#fff6ea', '#ffe45a'], 90, 0.4, 2); } else { car.f = Math.min(car.f, 0.5); puff(4, c.x, c.y, ['#ff7a1a', '#fff6ea'], 45, 0.3, 2); hurt(6, c.x, c.y); } } });
		tokens.forEach(function (t) {
				if (t.got) { return; }
				var ok = t.air ? car.z > 5 : car.z < 5, dd = Math.hypot(car.x - t.x, car.y - t.y);
				if (ok && fx.magnet > 0 && dd < 40 && dd > 0.1) { var pull = Math.min(dd, 190 * dt); t.x += (car.x - t.x) / dd * pull; t.y += (car.y - t.y) / dd * pull; dd = Math.hypot(car.x - t.x, car.y - t.y); }
				if (ok && dd < 10) { t.got = true; got++; puff(REDUCED ? 3 : 7, t.x, t.y - (t.air ? 14 : 0), ['#fff3a8', C.gold, '#ffffff'], 55, 0.32, 1); }
			});
			pickups.forEach(function (q) { if (!q.got && car.z < 8 && Math.hypot(car.x - q.x, car.y - q.y) < 13) { q.got = true; collect(q); } });
			LM.forEach(function (l) { if (!l.shown && car.prog > l.s - 40) { l.shown = true; banner = l.name; bannerT = 1.9; } });
		hitFlash = Math.max(0, hitFlash - dt); shake = Math.max(0, shake - dt);
		if (st !== 'play') { return; }
			var fin = at(TOTAL, 0);
		if (Math.hypot(car.x - fin.x, car.y - fin.y) < 30) { finish('Geschafft!'); } else if (tleft <= 0) { tleft = 0; finish('Zeit um!'); }
	}
	function hurt(n, x, y) {   // life: nothing happens in the short grace time after a hit, or under the shield
		if (inv > 0 || st !== 'play') { return false; }
		if (fx.schild > 0) { inv = 0.25; puff(REDUCED ? 3 : 8, x, y, ['#6fd6ff', '#ffffff', '#ffe45a'], 55, 0.3, 2); return false; }
		life = Math.max(0, life - n); inv = 0.9; lifeFlash = 0.45; hitFlash = 0.18; shake = REDUCED ? 0 : 0.2;
		if (life <= 0) { puff(REDUCED ? 8 : 28, car.x, car.y, ['#ffffff', '#ffd45a', '#ff7a1a', '#ff3b5c', '#3a2f7a'], 95, 0.7, 3); finish('Kaputt!'); }
		return true;
	}
	function collect(q) {
		var d = PK[q.type]; pk++; puff(REDUCED ? 4 : 12, q.x, q.y, [d.c, '#ffffff', C.gold], 70, 0.45, 2);
		if (q.type === 'food') { life = Math.min(100, life + 30); healFlash = 0.5; } else { fx[q.type] = d.t; }
		pops.push({ txt: d.name, x: car.x, y: car.y, t: 0, col: d.c });
	}
	function finish(why) { st = 'end'; endWhy = why; endAt = performance.now(); report(); }
	// ---- the server: a run starts (run number), a run ends (the coins; the voucher comes back when enough were collected)
	function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	// the voucher already won: the server tells (session), else this browser remembers it (amadeusPromoCode keeps code, percent, end date); shown under the game so the code can be copied
	var haveEl = document.getElementById('rd-have'), HAVE = (function () {
		var h = null; try { h = JSON.parse(B.have || 'null'); } catch (e) {}
		if (!h) { try { var p = JSON.parse(localStorage.getItem('amadeusPromoCode') || 'null'), m = p && p.until ? /^(\d\d)\.(\d\d)\.(\d{4})$/.exec(p.until) : null; if (p && /^ZEUS-/.test(p.code || '') && m && new Date(+m[3], +m[2] - 1, +m[1], 23, 59, 59) >= new Date()) { h = p; } } catch (e) {} }
		return (h && h.code) ? h : null;
	})();
	function copyCode(b, code) { try { navigator.clipboard.writeText(code).then(function () { b.textContent = 'Kopiert'; }, function () { b.textContent = 'Bitte von Hand kopieren'; }); } catch (e) { b.textContent = 'Bitte von Hand kopieren'; } }
	function showHave(h) {
		if (!haveEl || !h || !h.code) { return; }
		HAVE = h;
		haveEl.innerHTML = 'Dein Gutschein: <strong>' + esc(h.code) + '</strong> <button type="button" class="rd-link" data-rd="copy">Kopieren</button><small>' + esc(h.percent) + ' % Rabatt, ab ' + esc(fmtEur(h.min_order)) + ' Warenwert, bis ' + esc(h.until) + '. An der Kasse wird er eingetragen.</small>';
		haveEl.dataset.code = h.code; haveEl.hidden = false; fit();
	}
	if (haveEl) { haveEl.addEventListener('click', function (ev) { var b = ev.target.closest('[data-rd="copy"]'); if (b) { copyCode(b, haveEl.dataset.code || ''); } }); }
	function nextTier(n) { for (var i = 0; i < TIERS.length; i++) { if (TIERS[i].min > n) { return TIERS[i]; } } return null; }   // the next step above n coins (the server sends them ascending)
	function fmtEur(c) { return (c / 100).toFixed(2).replace('.', ',').replace(',00', '') + ' €'; }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	function api(op, data) { data = data || {}; data.op = op; data.token = TOKEN; return fetch('api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }).then(function (r) { return r.json(); }).catch(function () { return null; }); }
	// ---- music (CC0, see order/ride_music/LIZENZ.txt): starts with the first tap (phones allow no sound before), loops, has a mute button; the choice to mute lives in memory only, nothing is stored in the browser
	var music = (function () {
		var AC = window.AudioContext || window.webkitAudioContext, url = B.music || '', btn = document.getElementById('rd-mute'), actx = null, gain = null, buf = null, src = null, loading = false, muted = false;
		if (!AC || !url) { return { start: function () {}, state: function () { return null; } }; }
		// the song fades out at its end and fades in at its start: the last seconds are mixed into the first seconds, so the loop has no hole (the buffer is shorter by that time)
		function seamless(b) {
			var sr = b.sampleRate, xn = Math.round(Math.min(6, Math.floor(b.duration / 4)) * sr), len = b.length - xn, out = actx.createBuffer(b.numberOfChannels, len, sr);
			for (var c = 0; c < b.numberOfChannels; c++) { var s = b.getChannelData(c), o = out.getChannelData(c); o.set(s.subarray(0, len)); for (var i = 0; i < xn; i++) { var f = i / xn; o[i] = s[i] * f + s[len + i] * (1 - f); } }
			return out;
		}
		function play() { if (src || !buf || muted) { return; } src = actx.createBufferSource(); src.buffer = buf; src.loop = true; src.connect(gain); src.start(0); }
		function start() {
			if (muted) { return; }
			if (!actx) { try { actx = new AC(); } catch (e) { return; } gain = actx.createGain(); gain.gain.value = 0.4; gain.connect(actx.destination); }
			if (actx.state === 'suspended') { actx.resume(); }
			if (btn) { btn.hidden = false; }
			if (buf) { play(); return; }
			if (loading) { return; }
			loading = true;
			fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.arrayBuffer(); }).then(function (ab) { return new Promise(function (ok, no) { actx.decodeAudioData(ab, ok, no); }); })
				.then(function (b) { buf = seamless(b); loading = false; play(); }).catch(function () { loading = false; if (btn) { btn.hidden = true; } });
		}
		function setMuted(m) { muted = m; if (btn) { btn.textContent = m ? 'Ton an' : 'Ton aus'; } if (!actx) { if (!m) { start(); } return; } if (m) { actx.suspend(); } else { actx.resume(); start(); } }
		if (btn) { btn.addEventListener('click', function () { setMuted(!muted); }); }
		document.addEventListener('visibilitychange', function () { if (!actx || muted) { return; } if (document.hidden) { actx.suspend(); } else { actx.resume(); } });
		return { start: start, state: function () { return { state: actx ? actx.state : 'none', dur: buf ? buf.duration : 0, muted: muted, playing: !!src }; } };
	})();
	if (stage) { stage.addEventListener('pointerup', function () { music.start(); }); }   // phones count the lifting of the finger as the tap that allows sound
	function begin() { music.start(); reset(); st = 'count'; cd = 2.1; api('ride_start').then(function (r) { if (r && r.ok) { runId = r.run; ELIG = !!r.eligible; } }); }
	function report() {
		var id = runId, n = got; res = null;
		api('ride_finish', { run: id, coins: n }).then(function (r) {
			if (st !== 'end') { return; }
			res = (r && r.ok) ? r : { err: true };
			if (res.win) { lsSet('amadeusPromoCode', JSON.stringify({ code: res.code, at: Date.now(), percent: res.percent, until: res.until, min_order: res.min_order })); lsSet('amadeusCardGame', JSON.stringify({ done: Date.now(), code: res.code })); ELIG = false; showWin(res); showHave(res); }
			else if (res.have) { showHave(res.have); }
		});
	}
	function showWin(r) {
		if (!winEl) { return; }
		winEl.innerHTML = '<p class="rd-lead"><strong>' + esc(r.percent) + ' % Rabatt</strong> auf deine nächste Bestellung</p><p class="rd-code" aria-label="Dein Code">' + esc(r.code) + '</p>' +
			'<p class="rd-fine">Gilt ab ' + esc(fmtEur(r.min_order)) + ' Warenwert, einmal pro Person, bis ' + esc(r.until) + '. Wir tragen den Code an der Kasse für dich ein.</p>' +
			'<div class="rd-act"><a class="rd-btn" href="./">Zur Speisekarte</a><button type="button" class="rd-link" data-rd="copy">Kopieren</button><button type="button" class="rd-link" data-rd="again">Nochmal fahren</button></div>';
		winEl.dataset.code = r.code; winEl.hidden = false;
	}
	function hideWin() { if (winEl) { winEl.hidden = true; winEl.innerHTML = ''; } }
	setTimeout(function () { showHave(HAVE); }, 0);
	if (winEl) { winEl.addEventListener('click', function (ev) {
		var b = ev.target.closest('[data-rd]'); if (!b) { return; }
		if (b.dataset.rd === 'again') { begin(); return; }
		var code = winEl.dataset.code || '';
		try { navigator.clipboard.writeText(code).then(function () { b.textContent = 'Kopiert'; }, function () { b.textContent = 'Bitte von Hand kopieren'; }); } catch (e) { b.textContent = 'Bitte von Hand kopieren'; }
	}); }

	// ---------- draw
	function panel(x, y, w, h) { ctx.fillStyle = 'rgba(11,7,22,.9)'; ctx.fillRect(x, y, w, h); ctx.fillStyle = C.pink; ctx.fillRect(x, y, w, 1); ctx.fillRect(x, y + h - 1, w, 1); ctx.fillRect(x, y, 1, h); ctx.fillRect(x + w - 1, y, 1, h); ctx.fillStyle = C.teal; ctx.fillRect(x + 2, y + 2, w - 4, 1); }
	function blink() { return REDUCED || Math.floor(clock * 2) % 2 === 0; }
	function ring(cx, cy, r, col) { ctx.fillStyle = col; cx = Math.round(cx); cy = Math.round(cy); for (var y = -r; y <= r; y++) { for (var x = -r; x <= r; x++) { var d = Math.hypot(x, y); if (d <= r + 0.4 && d > r - 1.4) { ctx.fillRect(cx + x, cy + y, 1, 1); } } } }
	function stickUI() {   // the stick under the finger (drag), or at its fixed place (fixed)
		if (CTL === 'halves') { return; }
		var any = false; ctx.globalAlpha = 0.75;
		for (var k in ptrs) { var p = ptrs[k]; if (p.off) { continue; } any = true; var dx = p.x - p.ox, dy = p.y - p.oy, L = Math.hypot(dx, dy), m = Math.min(L, 20), kx = p.ox + (L ? dx / L * m : 0), ky = p.oy + (L ? dy / L * m : 0); ring(p.ox, p.oy, 25, C.dark); ring(p.ox, p.oy, 24, C.ink); disc(ctx, Math.round(kx), Math.round(ky), 6, C.dark); disc(ctx, Math.round(kx), Math.round(ky), 5, C.gold); disc(ctx, Math.round(kx), Math.round(ky), 2, '#fff3a8'); }
		if (CTL === 'fixed' && !any && (st === 'play' || st === 'count')) { ctx.globalAlpha = 0.45; ring(FX, FY, 25, C.dark); ring(FX, FY, 24, C.ink); disc(ctx, FX, FY, 6, C.dim); }
		ctx.globalAlpha = 1;
	}
	function drawMap() {
		var k = Math.min((W - 24) / (maxx - minx), (H - 24) / (maxy - miny)); ctx.fillStyle = '#1b1b22'; ctx.fillRect(0, 0, W, H);
		ctx.lineJoin = 'round'; ctx.lineCap = 'round';
		segs.forEach(function (g, n) { ctx.strokeStyle = n % 2 ? '#6a6a78' : '#8a8a98'; ctx.lineWidth = Math.max(2, g.hw * 2 * k); ctx.beginPath(); ctx.moveTo(12 + (g.x0 - minx) * k, 12 + (g.y0 - miny) * k); ctx.lineTo(12 + (g.x1 - minx) * k, 12 + (g.y1 - miny) * k); ctx.stroke(); });
		function m(o, col, sz) { ctx.fillStyle = col; ctx.fillRect(12 + (o.x - minx) * k - sz / 2, 12 + (o.y - miny) * k - sz / 2, sz, sz); }
		LM.forEach(function (l) { m(l, C.pink, 8); }); ramps.forEach(function (r) { m(r, '#6ab7ff', 4); }); m({ x: 0, y: 0 }, '#6f6', 6); tx('Karte: ' + Math.round(TOTAL) + ' px', 4, 3, 1, '#fff');
	}
	var fps = 60;
	function render() {
		clock = performance.now() / 1000;
		if (MAP) { drawMap(); return; }
		if (st === 'loading') { ctx.fillStyle = C.bg; ctx.fillRect(0, 0, W, H); tx('Lade Hildesheim', W / 2, 140, 1, C.ink, C.dark, 'center'); ctx.fillStyle = C.walk; ctx.fillRect(40, 158, 100, 5); ctx.fillStyle = C.teal; ctx.fillRect(40, 158, Math.round(100 * (1 - queue.length / QTOTAL)), 5); return; }
		var camx = cam.x, camy = cam.y, sx = shake > 0 ? Math.round((Math.random() - 0.5) * 4) : 0, sy = shake > 0 ? Math.round((Math.random() - 0.5) * 4) : 0;
		var csx = Math.round(W / 2 - (camx - car.x)), csy = Math.round(H / 2 - (camy - car.y));   // where the scooter stands on the screen: changes only slowly with the soft camera
		var ox = Math.round(car.x) - csx + sx, oy = Math.round(car.y) - csy + sy;   // the world is shifted by whole pixels together with the scooter, so it does not flicker against it
		ctx.fillStyle = C.ground; ctx.fillRect(0, 0, W, H);
		for (var cy = Math.floor(oy / CH); cy <= Math.floor((oy + H) / CH); cy++) { for (var cx = Math.floor(ox / CH); cx <= Math.floor((ox + W) / CH); cx++) { var key = cx + ',' + cy; if (!chunks[key] && edgeDist(cx * CH + CH / 2, cy * CH + CH / 2) < 330) { chunks[key] = renderChunk(cx, cy); } if (chunks[key]) { ctx.drawImage(chunks[key], cx * CH - ox, cy * CH - oy); } } }
		function X(x) { return Math.round(x - ox); } function Y(y) { return Math.round(y - oy); }
		cones.forEach(function (c) { if (!c.hit) { ctx.fillStyle = 'rgba(6,3,16,.45)'; ctx.fillRect(X(c.x) - 2, Y(c.y) - 1, 8, 6); ctx.drawImage(CONE, X(c.x) - 3, Y(c.y) - 3); } });
		pickups.forEach(function (q) {
			if (q.got) { return; }
			var x = X(q.x), y = Y(q.y), bob = REDUCED ? 0 : Math.round(Math.sin(clock * 4 + q.ph) * 1.5);
			ctx.globalAlpha = 0.4; ring(x, y, (REDUCED || Math.floor(clock * 3 + q.ph) % 2 === 0) ? 11 : 12, PK[q.type].c); ctx.globalAlpha = 1;
			ctx.fillStyle = 'rgba(6,3,16,.45)'; ctx.fillRect(x - 4, y + 4, 9, 3); ctx.drawImage(PKS[q.type], x - 5, y - 11 + bob);
		});
		tokens.forEach(function (t, i) {
			if (t.got) { return; }
			var x = X(t.x), y = Y(t.y), lift = t.air ? 15 : 0, bob = REDUCED ? 0 : Math.round(Math.sin(clock * 4 + i * 0.9));
			ctx.drawImage(COINSH, x - (t.air ? 3 : 5), y + (t.air ? 1 : -1), t.air ? 7 : 11, t.air ? 4 : 5);
			ctx.drawImage(COIN, x - 6, y - 7 - lift + bob);
			if (!REDUCED && Math.floor(clock * 3 + i * 0.37) % 6 === 0) { ctx.fillStyle = '#ffffff'; ctx.fillRect(x + 5, y - 11 - lift + bob, 1, 3); ctx.fillRect(x + 4, y - 10 - lift + bob, 3, 1); }
		});
		var idx = ((Math.round((car.h + Math.PI / 2) / (Math.PI / 8)) % 16) + 16) % 16, sp = SC[idx], sh = SSH[idx], zz = Math.round(car.z), sx0 = X(car.x), sy0 = Y(car.y), mk, ma;
		parts.forEach(function (q) { var k = Math.min(q.cols.length - 1, Math.floor(q.t / q.life * q.cols.length)); ctx.fillStyle = q.cols[k]; var s2 = q.t > q.life * 0.6 ? 1 : q.size; ctx.fillRect(X(q.x), Y(q.y), s2, s2); });
		ctx.globalAlpha = fx.blitz > 0 ? 0.34 : 0.18; disc(ctx, sx0, sy0, 14, fx.blitz > 0 ? '#ffe45a' : C.teal); ctx.globalAlpha = 1;   // soft glow under the scooter, so it stands out from the road
		ctx.drawImage(sh, sx0 - (sh.width >> 1) + 2 + (zz >> 2), sy0 - (sh.height >> 1) + 2 + (zz >> 2));
		if (!(life <= 0 || (inv > 0 && fx.schild <= 0 && st === 'play' && Math.floor(clock * 16) % 2 === 0))) { ctx.drawImage(sp, sx0 - (sp.width >> 1), sy0 - (sp.height >> 1) - zz); }
		if (fx.magnet > 0 && st === 'play') {
			ctx.globalAlpha = 0.3; ctx.fillStyle = C.pink; for (mk = 0; mk < 28; mk++) { ma = mk / 28 * 6.283; ctx.fillRect(sx0 + Math.round(Math.cos(ma) * 40), sy0 - (zz >> 1) + Math.round(Math.sin(ma) * 40), 1, 1); } ctx.globalAlpha = 1;
			for (mk = 0; mk < 5; mk++) { ma = clock * 3.2 + mk * 1.2566; ctx.fillStyle = mk % 2 ? '#ffffff' : C.pink; ctx.fillRect(sx0 + Math.round(Math.cos(ma) * 17), sy0 - zz + Math.round(Math.sin(ma) * 17), 2, 2); }
		}
		if (fx.schild > 0 && st === 'play' && (fx.schild > 1.5 || Math.floor(clock * 8) % 2 === 0)) { ctx.globalAlpha = 0.16; disc(ctx, sx0, sy0 - zz, 15, '#6fd6ff'); ctx.globalAlpha = 0.9; ring(sx0, sy0 - zz, 15, '#6fd6ff'); ctx.globalAlpha = 1; ctx.fillStyle = '#ffffff'; ctx.fillRect(sx0 - 9, sy0 - zz - 10, 2, 2); }
		pops.forEach(function (o) { var w2 = o.txt.length * 6 - 1, px2 = Math.max(Math.ceil(w2 / 2) + 2, Math.min(W - Math.ceil(w2 / 2) - 2, X(o.x))), py2 = Y(o.y) - 24 - Math.round(o.t * 16); ctx.globalAlpha = o.t > 0.9 ? Math.max(0, 1 - (o.t - 0.9) / 0.3) : 1; tx(o.txt, px2, py2, 1, o.col, C.dark, 'center'); ctx.globalAlpha = 1; });
		if (hitFlash > 0) { ctx.fillStyle = 'rgba(255,79,163,.18)'; ctx.fillRect(0, 0, W, H); }
		// HUD: time and coins, life bar, route progress, active boosts
		ctx.fillStyle = 'rgba(18,13,36,.92)'; ctx.fillRect(0, 0, W, 31); ctx.fillStyle = C.walk; ctx.fillRect(0, 31, W, 1);
		tx('Zeit ' + Math.ceil(tleft), 5, 4, 1, tleft < 10 && blink() ? '#ff8a7a' : C.ink, C.dark);
		var tw = String(got + '/' + NTOK).length * 6 - 1; tx(got + '/' + NTOK, W - 5, 4, 1, C.gold, C.dark, 'right'); ctx.drawImage(COIN, W - 5 - tw - 16, -1);
		var lowL = life < 25, lc = life > 50 ? C.hp : life > 25 ? '#ffd45a' : '#ff3b5c', lwid = W - 27, lw = Math.round(lwid * life / 100), flashOn = Math.floor(clock * 14) % 2 === 1;
		ctx.drawImage(HEART, 5, 12 + (healFlash > 0 && flashOn ? -1 : 0));
		ctx.fillStyle = C.dark; ctx.fillRect(18, 12, lwid + 4, 9); ctx.fillStyle = lifeFlash > 0 && flashOn ? '#ff3b5c' : '#2a2147'; ctx.fillRect(20, 14, lwid, 5);
		if (!(lowL && !blink())) { ctx.fillStyle = lc; ctx.fillRect(20, 14, lw, 5); ctx.fillStyle = 'rgba(255,255,255,.35)'; ctx.fillRect(20, 14, lw, 1); }
		if (healFlash > 0 && flashOn) { ctx.fillStyle = '#ffffff'; ctx.fillRect(20, 14, lw, 5); }
		ctx.fillStyle = C.dark; for (var tk = 1; tk < 5; tk++) { ctx.fillRect(20 + Math.round(lwid * tk / 5), 14, 1, 5); }
		ctx.fillStyle = '#2a2147'; ctx.fillRect(5, 25, W - 10, 3); var pw = Math.round((W - 10) * Math.min(1, car.prog / TOTAL)); ctx.fillStyle = C.teal; ctx.fillRect(5, 25, pw, 3); ctx.fillStyle = C.pink; for (var bx = 5; bx < 5 + pw; bx += 8) { ctx.fillRect(bx, 25, 4, 3); }
		LM.forEach(function (l) { var d = (l.s / TOTAL) * (W - 10) + 5; ctx.fillStyle = C.gold; ctx.fillRect(Math.round(d), 24, 1, 5); });
		var cx0 = 5; ['blitz', 'magnet', 'schild'].forEach(function (k) { if (fx[k] > 0) { ctx.drawImage(PKS[k], cx0, 35); ctx.fillStyle = C.dark; ctx.fillRect(cx0 + 13, 40, 28, 5); ctx.fillStyle = PK[k].c; ctx.fillRect(cx0 + 14, 41, Math.max(1, Math.round(26 * fx[k] / PK[k].t)), 3); cx0 += 46; } });
		if (DEBUG) { tx(Math.round(fps) + ' fps v ' + Math.round(SPEED * car.f) + ' s ' + Math.round(car.prog) + ' hp ' + life, 4, 50, 1, '#9fffc0', C.dark); }
		if (bannerT > 0 && st === 'play') { var bw = banner.length * 6 + 14; panel(Math.round((W - bw) / 2), 50, bw, 15); tx(banner, W / 2, 55, 1, C.gold, null, 'center'); }
		if (st === 'ready') { ctx.fillStyle = 'rgba(11,7,22,.55)'; ctx.fillRect(0, 32, W, H); panel(8, 38, W - 16, 266);
			tx('Zeus', W / 2, 46, 4, C.gold, C.pink, 'center'); tx('Ride', W / 2, 76, 4, C.teal, C.pink, 'center');
			ctx.drawImage(ZEUSP, 0, 0, 24, 28, 32, 110, 48, 56); ctx.drawImage(SC[0], 0, 0, 30, 30, 96, 110, 60, 60);
			if (ELIG) { TIERS.forEach(function (t, i) { tx('Ab ' + t.min + ' Münzen: ' + t.percent + ' %', W / 2, 168 + i * 10, 1, C.gold, C.dark, 'center'); }); } else { tx(HAVE ? 'Dein Gutschein: ' + HAVE.code : 'Gutschein schon geholt.', W / 2, 168, 1, C.gold, C.dark, 'center'); }
			var cy0 = 168 + Math.max(1, ELIG ? TIERS.length : 1) * 10 + 4;
			(CTL === 'halves' ? ['Halte links oder rechts,', 'um zu lenken.'] : CTL === 'fixed' ? ['Halte den Daumen auf den', 'Ring und zieh in die', 'Fahrtrichtung.'] : ['Finger auf den Bildschirm,', 'zieh in die Richtung,', 'in die du fahren willst.']).forEach(function (t, i) { tx(t, W / 2, cy0 + i * 11, 1, C.ink, C.dark, 'center'); });
			tx('Am Rechner: Pfeiltasten.', W / 2, cy0 + 36, 1, C.dim, C.dark, 'center'); tx('Spring über die Sperren.', W / 2, cy0 + 47, 1, C.dim, C.dark, 'center');
			[['blitz', 'Blitz'], ['magnet', 'Magnet'], ['schild', 'Schild'], ['food', 'Leben']].forEach(function (b, i) { var cxb = 28 + i * 41; ctx.drawImage(PKS[b[0]], cxb - 5, cy0 + 60); tx(b[1], cxb, cy0 + 74, 1, C.dim, C.dark, 'center'); });
			if (blink()) { tx('Tippe zum Start', W / 2, cy0 + 88, 1, C.gold, C.dark, 'center'); } }
		if (st === 'count') { tx(String(Math.max(1, Math.ceil(cd - 0.1))), W / 2, 120, 8, C.ink, C.pink, 'center'); }
		if (st === 'pause') { ctx.fillStyle = 'rgba(11,7,22,.55)'; ctx.fillRect(0, 32, W, H); panel(14, 120, W - 28, 70); tx('Pause', W / 2, 134, 3, C.ink, C.pink, 'center'); tx('Tippe zum Weiterfahren', W / 2, 170, 1, C.gold, C.dark, 'center'); }
		if (st === 'end') { ctx.fillStyle = 'rgba(11,7,22,.55)'; ctx.fillRect(0, 32, W, H); panel(6, 52, W - 12, 196);
			tx(endWhy, W / 2, 64, 2, endWhy === 'Geschafft!' ? C.teal : C.pink, C.dark, 'center');
			var lbl = got + ' von ' + NTOK, gx0 = Math.round((W - (lbl.length * 12 - 2 + 17)) / 2); ctx.drawImage(COIN, gx0, 92); tx(lbl, gx0 + 17, 95, 2, C.gold, C.dark);
			tx('Zeit ' + (TIME - tleft).toFixed(1) + ' s', W / 2, 122, 1, C.ink, C.dark, 'center'); tx('Strecke ' + Math.round(100 * Math.min(1, car.prog / TOTAL)) + ' %', W / 2, 134, 1, C.ink, C.dark, 'center');
			var ry = 156;
			if (res && res.win) { tx('Dein Gutschein:', W / 2, ry, 1, C.gold, C.dark, 'center'); tx(res.code, W / 2, ry + 14, 2, C.ink, C.pink, 'center'); var nx = nextTier(got); if (nx) { tx('Ab ' + nx.min + ' Münzen: ' + nx.percent + ' %', W / 2, 190, 1, C.dim, C.dark, 'center'); } }
			else if (res && res.err) { tx('Gutschein gerade', W / 2, ry, 1, C.dim, C.dark, 'center'); tx('nicht möglich.', W / 2, ry + 12, 1, C.dim, C.dark, 'center'); }
			else if (res && !ELIG && (res.have || HAVE)) { var hv = res.have || HAVE; tx('Dein Gutschein:', W / 2, ry, 1, C.gold, C.dark, 'center'); tx(hv.code, W / 2, ry + 14, 2, C.ink, C.pink, 'center'); }
			else if (res && res.why === 'have') { tx('Von diesem Anschluss gibt es', W / 2, ry, 1, C.dim, C.dark, 'center'); tx('keinen weiteren Gutschein.', W / 2, ry + 12, 1, C.dim, C.dark, 'center'); }
			else if (res && ELIG && nextTier(got)) { var nt = nextTier(got); tx('Noch ' + (nt.min - got) + (nt.min - got === 1 ? ' Münze' : ' Münzen'), W / 2, ry, 1, C.gold, C.dark, 'center'); tx('bis ' + nt.percent + ' % Rabatt.', W / 2, ry + 12, 1, C.gold, C.dark, 'center'); }
			else if (!res) { tx('Einen Moment ...', W / 2, ry, 1, C.dim, C.dark, 'center'); }
			if (endWhy === 'Kaputt!') { tx('Schnitzel füllen das Leben.', W / 2, 206, 1, C.dim, C.dark, 'center'); }
			if (!(res && res.win) && blink()) { tx('Tippe für noch eine Runde', W / 2, 224, 1, C.gold, C.dark, 'center'); } }
		stickUI();
	}

	// ---------- loop, scale
	var last = 0, acc = 0;
	function frame(t) {
		if (!last) { last = t; }
		var dt = Math.min(0.1, (t - last) / 1000); last = t; if (dt > 0) { fps += (1 / dt - fps) * 0.1; }
		if (st === 'loading') { var t0 = performance.now(); while (queue.length && performance.now() - t0 < 10) { var qq = queue.shift(); chunks[qq[0] + ',' + qq[1]] = renderChunk(qq[0], qq[1]); } if (!queue.length) { st = 'ready'; } }
		else { var rem = dt * SIM; while (rem > 1e-6) { var dd = Math.min(rem, 1 / 30); update(dd); rem -= dd; } }   // real frame time, so it runs evenly on 60 and 120 Hz screens
		render(); requestAnimationFrame(frame);
	}
	function fit() { var below = 0, s2; Array.prototype.forEach.call(document.querySelectorAll('.rd-bar, .rd-credit, .rd-have'), function (e) { below += e.hidden ? 0 : e.offsetHeight; }); s2 = Math.min(innerWidth / W, (innerHeight - below - 6) / H); if (s2 >= 2) { s2 = Math.floor(s2); } cv.style.width = Math.floor(W * s2) + 'px'; cv.style.height = Math.floor(H * s2) + 'px'; }
	addEventListener('resize', fit); fit();
	if (DEV) window.__ride = { snd: function () { return music.state(); }, signs: signs, ch: chunks, spr: { SC: SC, COIN: COIN, PKS: PKS, ZEUSP: ZEUSP, HEART: HEART }, life: function () { return life; }, fx: fx, pk: function () { return pk; }, pickups: pickups, hurt: hurt, st: function () { return st; }, car: car, TOTAL: TOTAL, NTOK: NTOK, start: function () { primary(); }, at: at, got: function () { return got; }, left: function () { return tleft; }, why: function () { return endWhy; }, chunks: function () { return Object.keys(chunks).length; }, force: function (v) { st = v; }, lm: LM, segs: segs, cam: cam };
	requestAnimationFrame(frame);
})();
