/* Schnitzel nach Wunsch (the build-it-yourself plate of the order page). The drawing is the one of the website https://amadeus.delivery/schnitzel-nach-wunsch/ (assets/schnitzel-demo.js):
 * a comic plate, drawn from a state { sorte, fleisch, beilage, dips }. Here the plate hangs on the real dish: the kind is an option of the group "Sorte", the meat is the variation, the side
 * is the group "Beilagen", the dips are the group "Soßen zum Dippen" - the prices come from the server like for every other dish, nothing is priced here.
 * The dialog is the one of the pizza table (order/pizza.css, #pizza-dialog); only the stage shows a plate. SchnitzelLab.open() returns false when the dish does not have the expected groups,
 * then the normal dialog opens. */
(function () {
	'use strict';

	var SORTEN = [
		{ id: 'sambal', title: 'Sambalschnitzel', what: 'cremig-scharfe Sambal-Hollandaise' },
		{ id: 'jaeger', title: 'Jägerschnitzel', what: 'Champignonrahmsoße' },
		{ id: 'toskana', title: 'Schnitzel „Toskana“', what: 'Basilikum-Pesto, getrocknete Tomaten und Rucola' }
	];
	var FLEISCH = [{ id: 'schwein', title: 'Schweinefleisch' }, { id: 'haehnchen', title: 'Hähnchenfleisch' }];
	var BEILAGEN = [
		{ id: 'pommes', title: 'Pommes Frites' }, { id: 'salz', title: 'Salzkartoffeln' }, { id: 'wedges', title: 'Wedges' },
		{ id: 'suess', title: 'Süßkartoffel-Pommes' }, { id: 'brat', title: 'Bratkartoffeln' }, { id: 'reis', title: 'Basmatireis' },
		{ id: 'curly', title: 'Curly Fries' }, { id: 'krok', title: 'Kroketten' }, { id: 'dipper', title: 'Potato Dippers' }
	];
	var DIPS = [
		{ id: 'sambal', title: 'Sambal-Hollandaise', c: '#e0762c' }, { id: 'holl', title: 'Sauce Hollandaise', c: '#f1d98c' },
		{ id: 'quark', title: 'Kräuterquark', c: '#eef0da', fleck: '#6f9a45' }, { id: 'knob', title: 'Knoblauchsoße', c: '#f8f4e6' },
		{ id: 'curry', title: 'Currysoße', c: '#e0b020' }, { id: 'chili', title: 'Chilisoße', c: '#c2271b' },
		{ id: 'bbq', title: 'Barbecue-Soße', c: '#6a2a14' }, { id: 'ketchup', title: 'Ketchup', c: '#cb2b1f' },
		{ id: 'mayo', title: 'Mayonnaise', c: '#f6eecb' }, { id: 'vayo', title: 'Vayonaise (vegan)', c: '#f1ecd6', fleck: '#6f9a45' },
		// in the shop only (the demo of the website has ten dips): the cream sauce with mushrooms and the Korean glaze with sesame
		{ id: 'cream', title: 'Champignonrahmsoße', c: '#d8c19a', fleck: '#8a6a46' }, { id: 'korean', title: 'Sticky Korean BBQ', c: '#4a2a1b', fleck: '#f6eedc' }
	];

	function by(list, id) { for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } } return list[0]; }
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	/* ---------- drawing: comic style (thick outline, flat colours, a highlight on everything), fixed offsets so the plate never flickers ---------- */
	var K = '#4a2a12';
	function ol(w, c) { return ' stroke="' + (c || K) + '" stroke-width="' + w + '" stroke-linejoin="round" stroke-linecap="round"'; }
	function seeded(seed) { var s = seed; return function () { s = (s * 9301 + 49297) % 233280; return s / 233280; }; }

	/* smooth, slightly lumpy outline around an ellipse */
	function blob(rx, ry, seed) {
		var n = 30, p = [], i, t, r;
		for (i = 0; i < n; i++) { t = i / n * Math.PI * 2; r = 1 + 0.05 * Math.sin(5 * t + seed) + 0.035 * Math.sin(9 * t + seed * 2) + 0.018 * Math.sin(17 * t + seed); p.push([rx * r * Math.cos(t), ry * r * Math.sin(t)]); }
		function mid(a, b) { return ((a[0] + b[0]) / 2).toFixed(1) + ' ' + ((a[1] + b[1]) / 2).toFixed(1); }
		var d = 'M' + mid(p[0], p[1]);
		for (i = 1; i <= n; i++) { var a = p[i % n], b = p[(i + 1) % n]; d += ' Q' + a[0].toFixed(1) + ' ' + a[1].toFixed(1) + ' ' + mid(a, b); }
		return d + ' Z';
	}
	var SCH = blob(154, 96, 1.3);
	var SCH_IN = blob(152, 100, 1.3);

	function defs() {
		return '<defs><clipPath id="sn-clip"><path d="' + SCH + '"/></clipPath></defs>';
	}

	function plate() {
		return '<ellipse cx="280" cy="228" rx="266" ry="190" fill="#d9d1bd"' + ol(4.5, '#8f866f') + '/>' +
			'<ellipse cx="280" cy="219" rx="258" ry="182" fill="#f7f2e6"' + ol(3, '#b5ab92') + '/>' +
			'<ellipse cx="280" cy="219" rx="212" ry="148" fill="#fffdf7" stroke="rgba(43,26,14,.28)" stroke-width="2.5"/>' +
			'<path d="M70 150 C100 78 190 40 290 36" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" opacity=".9"/>';
	}

	function mush(x, y, r) {
		return '<g transform="translate(' + x + ' ' + y + ') rotate(' + r + ')"><path d="M-6 2 L-6 15 Q0 19 6 15 L6 2 Z" fill="#efe1c3"' + ol(2.4, '#8a6a3a') + '/><path d="M-19 4 C-19 -17 19 -17 19 4 Q0 9 -19 4 Z" fill="#d9b98a"' + ol(2.8, '#7a5a2e') + '/><path d="M-12 -4 C-8 -11 4 -12 9 -8" fill="none" stroke="#f6e6c6" stroke-width="3" stroke-linecap="round"/></g>';
	}
	function leaf(x, y, r, sc) {
		return '<g transform="translate(' + x + ' ' + y + ') rotate(' + r + ') scale(' + sc + ')"><path d="M0 0 C4 -13 15 -16 20 -7 C27 -16 40 -10 38 1 C40 12 27 17 20 8 C15 17 4 14 0 0 Z" fill="#4c9a31"' + ol(2.6, '#2d6a1c') + '/><path d="M3 0 L32 0" stroke="#2d6a1c" stroke-width="2" stroke-linecap="round"/></g>';
	}

	function sauce(sorte) {
		var s = '', i;
		if (sorte === 'sambal') {
			var rib = 'M-150 -30 C-110 -78 -70 14 -22 -22 C26 -58 62 24 112 -12 C132 -26 146 -22 156 -30';
			var rib2 = 'M-150 34 C-104 -2 -66 74 -14 42 C34 12 72 82 124 48 C140 38 150 40 160 36';
			s += '<path d="' + rib + '" fill="none" stroke="#9a3d0f" stroke-width="44" stroke-linecap="round" stroke-linejoin="round"/><path d="' + rib2 + '" fill="none" stroke="#9a3d0f" stroke-width="36" stroke-linecap="round" stroke-linejoin="round"/>';
			s += '<path d="' + rib + '" fill="none" stroke="#e8742a" stroke-width="37" stroke-linecap="round" stroke-linejoin="round"/><path d="' + rib2 + '" fill="none" stroke="#ee8334" stroke-width="29" stroke-linecap="round" stroke-linejoin="round"/>';
			s += '<path d="' + rib + '" fill="none" stroke="#fbb26a" stroke-width="7" stroke-linecap="round" transform="translate(0 -9)" opacity=".85"/><path d="' + rib2 + '" fill="none" stroke="#fbb26a" stroke-width="6" stroke-linecap="round" transform="translate(0 -8)" opacity=".85"/>';
			var f = [[-118, -40], [-84, -14], [-52, -30], [-20, -12], [10, -38], [42, -4], [76, -20], [108, -4], [-100, 34], [-62, 52], [-26, 34], [12, 52], [52, 36], [92, 56], [128, 40]];
			for (i = 0; i < f.length; i++) { s += '<ellipse cx="' + f[i][0] + '" cy="' + f[i][1] + '" rx="5" ry="2.6" transform="rotate(' + (i * 37 % 180) + ' ' + f[i][0] + ' ' + f[i][1] + ')" fill="#c1121f" stroke="#6b0a10" stroke-width="1.3"/>'; }
		} else if (sorte === 'jaeger') {
			s += '<path d="' + blob(124, 80, 2.1) + '" fill="#6e4222"' + ol(3.5, '#3d2211') + '/>';
			s += '<path d="' + blob(106, 64, 2.1) + '" transform="translate(-8 -9)" fill="#8d5a2d"/>';
			s += '<path d="M-92 -34 C-50 -62 20 -62 76 -42" fill="none" stroke="#c28a50" stroke-width="5" stroke-linecap="round" opacity=".75"/>';
			var m = [[-76, -8, -16], [-36, 28, 12], [4, -26, 8], [44, 12, -10], [82, -14, 18], [-4, 38, -6], [-82, 30, 10], [52, -38, -14]];
			for (i = 0; i < m.length; i++) { s += mush(m[i][0], m[i][1], m[i][2]); }
			var g = [[-56, -30], [24, 6], [70, 30], [-30, 4]];
			for (i = 0; i < g.length; i++) { s += '<circle cx="' + g[i][0] + '" cy="' + g[i][1] + '" r="3" fill="#4c9a31" stroke="#2d6a1c" stroke-width="1.2"/>'; }
		} else {
			var p1 = 'M-146 -34 C-106 -72 -64 6 -18 -26 C26 -56 62 14 106 -16 C126 -28 142 -22 154 -30';
			var p2 = 'M-148 30 C-104 -2 -62 66 -12 38 C34 12 70 72 118 44 C134 36 146 38 158 34';
			s += '<path d="' + p1 + '" fill="none" stroke="#2f5d17" stroke-width="40" stroke-linecap="round" stroke-linejoin="round"/><path d="' + p2 + '" fill="none" stroke="#2f5d17" stroke-width="34" stroke-linecap="round" stroke-linejoin="round"/>';
			s += '<path d="' + p1 + '" fill="none" stroke="#5e9c32" stroke-width="33" stroke-linecap="round" stroke-linejoin="round"/><path d="' + p2 + '" fill="none" stroke="#6aa93b" stroke-width="27" stroke-linecap="round" stroke-linejoin="round"/>';
			s += '<path d="' + p1 + '" fill="none" stroke="#a6d46c" stroke-width="6" stroke-linecap="round" transform="translate(0 -8)" opacity=".85"/><path d="' + p2 + '" fill="none" stroke="#a6d46c" stroke-width="5" stroke-linecap="round" transform="translate(0 -7)" opacity=".85"/>';
			var t = [[-104, -22, 24], [-56, 2, -30], [-8, -22, 14], [36, 6, -24], [84, -14, 32], [-80, 38, -14], [-24, 44, 20], [30, 50, -12], [96, 36, 26]];
			for (i = 0; i < t.length; i++) { s += '<g transform="translate(' + t[i][0] + ' ' + t[i][1] + ') rotate(' + t[i][2] + ')"><rect x="-17" y="-6" width="34" height="12" rx="6" fill="#c4301f"' + ol(2.4) + '/><path d="M-10 -2 L8 -2" stroke="#f08a70" stroke-width="2.4" stroke-linecap="round"/></g>'; }
			s += leaf(-96, -64, -20, 1) + leaf(-30, -70, 10, 1.05) + leaf(40, -66, -10, 1) + leaf(100, -52, 24, 0.95) + leaf(-110, 62, 16, 0.9) + leaf(70, 70, -22, 0.95);
		}
		return s;
	}

	function ripples() {
		var d = ['M-120 -50 q22 -14 48 -2', 'M-30 -70 q26 -10 54 4', 'M60 -52 q20 -10 44 2', 'M-110 62 q28 -12 56 0', 'M20 70 q26 -12 52 0', 'M-60 4 q18 -8 38 0', 'M70 10 q16 -8 34 0'], i, s = '';
		for (i = 0; i < d.length; i++) { s += '<path d="' + d[i] + '" fill="none" stroke="#b8701c" stroke-width="3.2" stroke-linecap="round" opacity=".55"/>'; }
		return s;
	}

	function schnitzel(sorte, fleisch) {
		var chick = fleisch === 'haehnchen', R = seeded(7), crumbs = '', i;
		for (i = 0; i < 70; i++) {
			var cx = (R() - 0.5) * 290, cy = (R() - 0.5) * 190, big = R() > 0.55;
			crumbs += '<circle cx="' + cx.toFixed(0) + '" cy="' + cy.toFixed(0) + '" r="' + (big ? 3.2 : 2) + '" fill="' + (R() > 0.45 ? (chick ? '#fff3cf' : '#ffe08a') : (chick ? '#c9923f' : '#a85f1a')) + '" opacity="' + (big ? 0.85 : 0.7) + '"/>';
		}
		return '<g transform="translate(190 216) rotate(-4) scale(' + (chick ? 0.97 : 1.05) + ')">' +
			'<path d="' + SCH + '" transform="translate(7 10)" fill="rgba(43,26,14,.2)"/>' +
			'<path d="' + SCH + '" fill="' + (chick ? '#dba75a' : '#cf8a2b') + '"/>' +
			'<g clip-path="url(#sn-clip)"><path d="' + SCH + '" transform="translate(-9 -11) scale(.93)" fill="' + (chick ? '#f2cf84' : '#efb64f') + '"/>' + crumbs +
			'<path d="M-130 -62 C-80 -92 20 -98 100 -72" fill="none" stroke="#fff6d2" stroke-width="7" stroke-linecap="round" opacity=".85"/>' +
			ripples() + sauce(sorte) + '</g>' +
			'<path d="' + SCH + '" fill="none"' + ol(4.5, chick ? '#9a6420' : '#8a4a10') + '/>' +
			'</g>';
	}

	function fry(a, L, col, x0, y0) {
		return '<g transform="translate(' + x0 + ' ' + y0 + ') rotate(' + a + ')"><rect x="-7" y="' + (-L) + '" width="14" height="' + L + '" rx="5" fill="' + col[0] + '"' + ol(2.8, col[1]) + '/><path d="M-2.5 ' + (-L + 9) + ' L-2.5 -12" stroke="' + col[2] + '" stroke-width="3" stroke-linecap="round"/></g>';
	}

	function side(id) {
		var s = '', i, a;
		if (id === 'pommes' || id === 'suess') {
			var col = id === 'pommes' ? ['#f0c94a', '#c99a2c', '#fff0a8'] : ['#ee9335', '#b9661a', '#ffc27a'];
			var order = [];
			for (i = 0; i < 17; i++) { order.push(i); }
			order.sort(function (p, q) { return Math.abs(q - 8) - Math.abs(p - 8); });
			for (i = 0; i < order.length; i++) {
				var n = order[i]; a = -46 + n * (92 / 16) + ((n * 37) % 7 - 3);
				s += fry(a, 84 + (n * 13) % 5 * 7, col, ((n % 3) - 1) * 9, 58);
			}
			s += '<g transform="translate(-4 48) rotate(-72)"><rect x="-7" y="-46" width="14" height="46" rx="5" fill="' + col[0] + '"' + ol(2.8, col[1]) + '/></g><g transform="translate(10 58) rotate(68)"><rect x="-7" y="-42" width="14" height="42" rx="5" fill="' + col[0] + '"' + ol(2.8, col[1]) + '/></g>';
		} else if (id === 'wedges') {
			var w = [[-38, -48, -14], [4, -52, 8], [44, -42, 22], [-48, -10, 6], [-6, -16, -10], [38, -6, 14], [-30, 28, -6], [14, 26, 12], [52, 30, 30], [-6, 52, -4]];
			for (i = 0; i < w.length; i++) { s += '<g transform="translate(' + w[i][0] + ' ' + w[i][1] + ') rotate(' + w[i][2] + ')"><path d="M-36 10 Q0 -40 36 10 Q0 -8 -36 10 Z" fill="#e8ae48"' + ol(2.8, '#9a5f1a') + '/><path d="M-30 8 Q0 -32 30 8" fill="none" stroke="#a9691f" stroke-width="3" stroke-linecap="round"/><path d="M-14 -4 Q-2 -14 10 -8" fill="none" stroke="#fff0b8" stroke-width="3" stroke-linecap="round"/><circle cx="-8" cy="2" r="1.6" fill="#7a4a14"/><circle cx="12" cy="1" r="1.6" fill="#7a4a14"/></g>'; }
		} else if (id === 'brat') {
			var R = seeded(21), b = [];
			for (i = 0; i < 16; i++) { b.push([(R() - 0.5) * 120, (R() - 0.5) * 112, R() * 90]); }
			b.sort(function (p, q) { return p[1] - q[1]; });
			for (i = 0; i < b.length; i++) { s += '<g transform="translate(' + b[i][0].toFixed(0) + ' ' + b[i][1].toFixed(0) + ') rotate(' + b[i][2].toFixed(0) + ')"><path d="M-21 -9 Q-8 -23 11 -18 Q25 -8 20 11 Q5 23 -14 16 Q-26 6 -21 -9 Z" fill="#e0a947"' + ol(2.8) + '/><path d="M-13 -9 Q-4 -16 6 -13" fill="none" stroke="#fff0b8" stroke-width="3" stroke-linecap="round"/><circle cx="-2" cy="2" r="2.4" fill="#6b4a1a"/><circle cx="9" cy="6" r="2" fill="#6b4a1a"/><circle cx="-12" cy="7" r="2.2" fill="#4c9a31"/></g>'; }
		} else if (id === 'salz') {
			var z = [[-40, -40, 20], [8, -52, -12], [46, -28, 30], [-52, 4, -8], [-6, -8, 12], [42, 14, -20], [-34, 46, 14], [14, 52, -6]];
			for (i = 0; i < z.length; i++) { s += '<g transform="translate(' + z[i][0] + ' ' + z[i][1] + ') rotate(' + z[i][2] + ')"><ellipse rx="30" ry="22" fill="#f4e7b4"' + ol(2.8) + '/><path d="M-18 -10 Q-4 -19 12 -14" fill="none" stroke="#fffbe0" stroke-width="4" stroke-linecap="round"/><circle cx="-7" cy="1" r="2.4" fill="#4c9a31"/><circle cx="9" cy="5" r="2" fill="#4c9a31"/><circle cx="3" cy="-7" r="1.8" fill="#4c9a31"/></g>'; }
			s += '<g transform="translate(-2 -14) rotate(-8)"><rect x="-10" y="-7" width="20" height="14" rx="3" fill="#ffd84a"' + ol(2.4) + '/><path d="M-6 -3 L4 -3" stroke="#fff3a8" stroke-width="2.4" stroke-linecap="round"/></g>';
		} else if (id === 'reis') {
			s += '<g transform="translate(-6 8) scale(.82)">'; s += '<path d="M-76 46 C-80 -46 -34 -66 0 -66 C34 -66 80 -46 76 46 Q0 66 -76 46 Z" fill="#fffdf4"' + ol(3.4) + '/>';
			s += '<path d="M-54 -14 C-48 -42 -18 -54 8 -52" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round"/>';
			var G = seeded(5);
			for (i = 0; i < 48; i++) { var gx = (G() - 0.5) * 124, gy = (G() - 0.5) * 100 + 6; s += '<path d="M' + gx.toFixed(0) + ' ' + gy.toFixed(0) + ' q5 -3 10 0" fill="none" stroke="#d8cca6" stroke-width="2.4" stroke-linecap="round"/>'; }
			s += '<circle cx="-14" cy="-34" r="3" fill="#4c9a31"/><circle cx="20" cy="-22" r="2.6" fill="#4c9a31"/>'; s += '</g>';
		} else if (id === 'curly') {
			var cp = [[-36, -46, -20], [20, -52, 14], [-44, -4, 8], [14, -8, -14], [40, 30, 30], [-34, 36, -6], [8, 56, 14]];
			for (i = 0; i < cp.length; i++) {
				var pts = '', tt;
				for (tt = 0; tt <= 2.2 * Math.PI * 2; tt += 0.28) { pts += (tt === 0 ? 'M' : 'L') + (6.4 * tt - 14 * Math.sin(tt) - 44).toFixed(1) + ' ' + (-14 * Math.cos(tt)).toFixed(1) + ' '; }
				s += '<g transform="translate(' + cp[i][0] + ' ' + cp[i][1] + ') rotate(' + cp[i][2] + ') scale(.85)"><path d="' + pts + '" fill="none" stroke="#8a3f12" stroke-width="17" stroke-linejoin="round" stroke-linecap="round"/><path d="' + pts + '" fill="none" stroke="#e8923a" stroke-width="11" stroke-linejoin="round" stroke-linecap="round"/><path d="' + pts + '" fill="none" stroke="#ffd28a" stroke-width="3" stroke-linecap="round" stroke-dasharray="9 22" transform="translate(0 -3)"/><path d="' + pts + '" fill="none" stroke="#b3441c" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="2 13" transform="translate(0 2)"/></g>';
			}
		} else if (id === 'krok') {
			var k = [[-40, -34, -24], [6, -48, 14], [44, -16, 42], [-34, 18, 8], [12, 6, -30], [-6, 52, 78], [44, 40, -50]];
			for (i = 0; i < k.length; i++) { s += '<g transform="translate(' + k[i][0] + ' ' + k[i][1] + ') rotate(' + k[i][2] + ')"><rect x="-16" y="-34" width="32" height="68" rx="15" fill="#dc9a38"' + ol(3) + '/><path d="M-7 -22 L-7 20" stroke="#ffe39a" stroke-width="4" stroke-linecap="round"/><circle cx="6" cy="-8" r="2" fill="#a85f1a"/><circle cx="4" cy="14" r="2" fill="#a85f1a"/><circle cx="8" cy="-24" r="1.8" fill="#a85f1a"/></g>'; }
		} else {
			var p = [[-50, -48, -24], [-12, -56, 8], [32, -44, 28], [-62, -6, 18], [-22, -12, -12], [22, -4, -30], [50, 12, 14], [-44, 34, 8], [-2, 34, -20], [38, 42, 22], [-14, 64, 6]];
			for (i = 0; i < p.length; i++) { s += '<g transform="translate(' + p[i][0] + ' ' + p[i][1] + ') rotate(' + p[i][2] + ')"><path d="M-30 6 C-26 -20 6 -30 32 -10 C26 -6 22 4 14 8 C2 -8 -14 -8 -30 6 Z" fill="#f0c551"' + ol(2.8, '#a8701a') + '/><path d="M-30 6 C-26 -20 6 -30 32 -10" fill="none" stroke="#b9772a" stroke-width="4.5" stroke-linecap="round"/><path d="M-18 -4 C-8 -14 6 -16 18 -10" fill="none" stroke="#fff3b0" stroke-width="3" stroke-linecap="round"/><circle cx="0" cy="-2" r="1.5" fill="#b9892a"/><circle cx="12" cy="-3" r="1.3" fill="#b9892a"/></g>'; }
		}
		return '<g transform="translate(440 226) scale(1.2)">' + s + '</g>';
	}

	function salad() {
		return '<g transform="translate(372 100) scale(1.2)">' +
			'<ellipse cx="-30" cy="10" rx="34" ry="17" transform="rotate(-22 -30 10)" fill="#7fb84a"' + ol(2.8, '#2f6b1e') + '/>' +
			'<ellipse cx="26" cy="-2" rx="34" ry="17" transform="rotate(20 26 -2)" fill="#5fa63c"' + ol(2.8, '#2f6b1e') + '/>' +
			'<ellipse cx="-4" cy="20" rx="34" ry="16" transform="rotate(-4 -4 20)" fill="#98c95a"' + ol(2.8, '#2f6b1e') + '/>' +
			'<path d="M-46 6 L-14 14 M14 -8 L46 4" stroke="#3f7f2a" stroke-width="2" stroke-linecap="round"/>' +
			'<circle cx="-12" cy="6" r="16" fill="#e23a2c"' + ol(3, '#8d2316') + '/><path d="M-22 0 Q-14 -8 -6 -4" fill="none" stroke="#ff9a8a" stroke-width="3.4" stroke-linecap="round"/><circle cx="-8" cy="12" r="2" fill="#f7c35c"/><circle cx="-16" cy="12" r="1.8" fill="#f7c35c"/>' +
			'<circle cx="28" cy="16" r="12" fill="#c9e59a"' + ol(3, '#5f8a2c') + '/><circle cx="28" cy="16" r="6" fill="#e8f6c6"/><circle cx="26" cy="15" r="1.2" fill="#6f9a45"/><circle cx="31" cy="18" r="1.2" fill="#6f9a45"/>' +
			'<path d="M36 -16 C46 -26 62 -16 54 -4 C50 4 40 2 38 -6" fill="none" stroke="#6e2a55" stroke-width="7" stroke-linecap="round"/><path d="M36 -16 C46 -26 62 -16 54 -4 C50 4 40 2 38 -6" fill="none" stroke="#b64a8a" stroke-width="3.2" stroke-linecap="round"/>' +
			'</g>';
	}

	function bowl(d) {
		return '<circle r="21" fill="#2c3036"' + ol(3.2, '#121418') + '/><path d="M-15 -12 Q-4 -20 10 -17" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="3" stroke-linecap="round"/><circle r="14" fill="' + d.c + '"' + ol(2, '#121418') + '/>' +
			'<path d="M-8 -6 Q-3 -11 3 -10" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="3.2" stroke-linecap="round"/>' +
			(d.fleck ? '<circle cx="-4" cy="3" r="1.8" fill="' + d.fleck + '"/><circle cx="5" cy="4" r="1.6" fill="' + d.fleck + '"/><circle cx="6" cy="-4" r="1.4" fill="' + d.fleck + '"/>' : '');
	}
	function dips(list) {
		var s = '';
		for (var i = 0; i < list.length; i++) {
			var d = by(DIPS, list[i]), x = 280 + (i % 5 - 2) * 48, y = 352 + Math.floor(i / 5) * 40;
			s += '<g transform="translate(' + x + ' ' + y + ')"><title>' + esc(d.title) + '</title>' + bowl(d) + '</g>';
		}
		return s;
	}

	function lemon() {
		var s = '<g transform="translate(458 340) rotate(-12)"><circle r="23" fill="#ffe14d"' + ol(3.2, '#b8860b') + '/><circle r="17" fill="#fff4a6" stroke="#e8b923" stroke-width="2"/>';
		for (var i = 0; i < 6; i++) { s += '<path d="M0 0 L0 -16" transform="rotate(' + i * 60 + ')" stroke="#e8b923" stroke-width="2.2" stroke-linecap="round"/>'; }
		return s + '<path d="M-12 -12 Q-4 -19 6 -17" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" opacity=".9"/></g>';
	}

	function render(st) {
		return '<svg viewBox="0 0 560 430" xmlns="http://www.w3.org/2000/svg" role="img" aria-hidden="true" focusable="false">' + defs() + plate() +
			'<g class="fade">' + schnitzel(st.sorte, st.fleisch) + salad() + side(st.beilage) + lemon() + dips(st.dips) + '</g></svg>';
	}

	/* Node (tests): only the drawing */
	if (typeof module !== 'undefined' && module.exports) { module.exports = { render: render, SORTEN: SORTEN, BEILAGEN: BEILAGEN, DIPS: DIPS }; return; }

	/* ---------- the shop: options of the dish -> the state of the plate ---------- */
	var SORT_RX = [[/sambal/, 'sambal'], [/j(ä|ae)ger/, 'jaeger'], [/toskana/, 'toskana']];
	var BEIL_RX = [[/s(ü|ue)(ß|ss)kartoffel/, 'suess'], [/krokett/, 'krok'], [/salzkartoffel/, 'salz'], [/reis/, 'reis'], [/dipper/, 'dipper'], [/curly/, 'curly'], [/wedge/, 'wedges'], [/brat/, 'brat'], [/pommes|frites/, 'pommes']];
	var DIP_RX = [[/sambal/, 'sambal'], [/champignon/, 'cream'], [/korean/, 'korean'], [/hollandaise/, 'holl'], [/qu?ark|kr(ä|ae)uter/, 'quark'], [/knoblauch/, 'knob'], [/curry/, 'curry'], [/chili/, 'chili'],
		[/barbecue|bbq/, 'bbq'], [/ketchup/, 'ketchup'], [/vayo/, 'vayo'], [/mayo/, 'mayo']];
	function idOf(rx, title, dflt) { var t = String(title).toLowerCase(), i; for (i = 0; i < rx.length; i++) { if (rx[i][0].test(t)) { return rx[i][1]; } } return dflt; }
	// the role of an option group, by its name: "Beilagen", the dips ("Soßen zum Dippen"), else the kind ("Sorte")
	function roleOf(g) { var t = String(g.title).toLowerCase(); return /beilage/.test(t) ? 'beilage' : (/dip|soß|sosse/.test(t) ? 'dip' : 'sorte'); }
	function rnd(n) { return Math.floor(Math.random() * n); }

	function open(p, edit, api) {
		var fmt = api.fmt, esc = api.esc, dlg = document.getElementById('pizza-dialog');
		if (!dlg) { return false; }
		var gr = p.groups.map(function (g) { return { g: g, role: roleOf(g) }; });
		var byRole = {}; gr.forEach(function (x) { if (!byRole[x.role]) { byRole[x.role] = x.g; } });
		if (!byRole.sorte || !byRole.beilage || !p.variations.length) { return false; }
		var sel = { vid: p.variations[0].id, opts: {}, qty: 1 }, items = {}, tab = 0, editing = !!edit, tried = false, rolling = false;
		var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		function $(s) { return dlg.querySelector(s); }
		function $$(s) { return Array.prototype.slice.call(dlg.querySelectorAll(s)); }
		p.groups.forEach(function (g) { g.items.forEach(function (it) { items[it.id] = { it: it, g: g }; }); });
		// like the demo of the website: it starts with a complete plate (first kind, first meat, first side), the guest changes it
		gr.forEach(function (x) { if (x.role !== 'dip' && x.g.min > 0 && x.g.items.length) { sel.opts[x.g.items[0].id] = 1; } });
		if (editing) {
			var ln = edit.line;
			if (p.variations.some(function (v) { return v.id === ln.vid; })) { sel.vid = ln.vid; }
			Object.keys(sel.opts).forEach(function (k) { delete sel.opts[k]; });
			Object.keys(ln.opts || {}).forEach(function (k) { if (items[k]) { sel.opts[k] = Math.min(+ln.opts[k], items[k].it.max); } });
			sel.qty = ln.qty;
		}
		function cur(s) { var v = p.variations.filter(function (x) { return x.id === (s || sel).vid; })[0]; return v || p.variations[0]; }
		function groupSum(g) { return g.items.reduce(function (n, it) { return n + (sel.opts[it.id] || 0); }, 0); }
		function chosen(g, s) { return g.items.filter(function (it) { return ((s || sel).opts[it.id] || 0) > 0; }); }
		function unit() { var x = 0; Object.keys(sel.opts).forEach(function (k) { x += items[k].it.price * sel.opts[k]; }); return cur().price + Math.round(x * cur().mult); }
		function name(it) { return String(it.title).replace(/^extra\s+/i, ''); }
		function missing() { return p.groups.filter(function (g) { return g.min > 0 && groupSum(g) < g.min; }); }
		function stateOf(s) {
			var k = chosen(byRole.sorte, s)[0], b = chosen(byRole.beilage, s)[0], d = byRole.dip ? chosen(byRole.dip, s) : [];
			return { sorte: k ? idOf(SORT_RX, k.title, 'sambal') : 'sambal', fleisch: /h(ä|ae)hnchen/i.test(cur(s).title) ? 'haehnchen' : 'schwein', beilage: b ? idOf(BEIL_RX, b.title, 'pommes') : 'pommes',
				dips: d.map(function (it) { return idOf(DIP_RX, it.title, 'knob'); }).filter(function (v, i, a) { return a.indexOf(v) === i; }) };
		}
		function list(a) { return a.length < 2 ? a.join('') : a.slice(0, -1).join(', ') + ' und ' + a[a.length - 1]; }
		function summary() {
			var k = chosen(byRole.sorte)[0], b = chosen(byRole.beilage)[0], d = byRole.dip ? chosen(byRole.dip).map(name) : [];
			return (k ? k.title : 'Dein Schnitzel') + ' mit ' + cur().title + (b ? ' und ' + b.title : '') + (d.length ? ', dazu ' + list(d) : '') + '. Eine Salatbeilage ist immer dabei.';
		}

		/* markup: the same bars and tray as the pizza table */
		var tabs = p.groups.map(function (g, i) {
			return '<button type="button" class="pz-tab" role="tab" data-tab="' + i + '" aria-selected="false" id="pz-tab-' + i + '" aria-controls="pz-panel"><span>' + esc(api.cleanTitle(g.title)) + '</span><span class="pz-count" data-count="' + i + '" hidden></span></button>';
		}).join('');
		var meat = '<div class="pz-teig" role="group" aria-label="Fleisch"><span class="pz-label">Fleisch</span>' + p.variations.map(function (v) {
			var diff = v.price - p.variations[0].price;
			return '<button type="button" class="pz-chip" data-v="' + v.id + '" aria-pressed="false">' + esc(v.title) + (diff ? ' <small>' + (diff > 0 ? '+' : '') + fmt(diff) + '</small>' : '') + '</button>';
		}).join('') + '</div>';
		dlg.innerHTML =
			'<header class="pz-top"><button type="button" class="pz-back" aria-label="Zurück zur Speisekarte"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
			'<h2 id="pz-title">' + esc(p.title) + '</h2><button type="button" class="pz-reset sn-dice"><img src="zeus-stamp.png" width="20" height="27" alt=""><span>Zeus würfelt</span></button></header>' +
			'<div class="pz-stage sn-stage"><div class="sn-table" aria-hidden="true"></div><div class="sn-art" role="img" aria-label="Dein Schnitzel von oben"></div><p class="sn-zeus" hidden><strong>Zeus hat gewürfelt.</strong> Gefällt dir die Kombination nicht? Tipp einfach etwas anderes an.</p><p class="pz-sum"></p></div>' +
			'<section class="pz-bench">' + meat + '<div class="pz-tabs" role="tablist" aria-label="Auswahl">' + tabs + '</div><div class="pz-panel" id="pz-panel" role="tabpanel"></div></section>' +
			'<footer class="pz-foot"><span class="pz-step"><button type="button" class="pz-qty" id="pz-minus" aria-label="Weniger">&minus;</button><span class="pz-qnum" id="pz-qnum">1</span><button type="button" class="pz-qty" id="pz-plus" aria-label="Mehr">+</button></span>' +
			'<button type="button" class="pz-add" id="pz-add"><span id="pz-add-label"></span><span id="pz-add-price"></span></button></footer><p class="pz-live" role="status" aria-live="polite"></p>';

		function icon(role, it) {
			if (role === 'sorte') { return '<svg class="pz-ico sn-ico" viewBox="20 100 340 230" aria-hidden="true" focusable="false">' + schnitzel(idOf(SORT_RX, it.title, 'sambal'), 'schwein') + '</svg>'; }
			if (role === 'beilage') { return '<svg class="pz-ico sn-ico" viewBox="350 136 180 180" aria-hidden="true" focusable="false">' + side(idOf(BEIL_RX, it.title, 'pommes')) + '</svg>'; }
			return '<svg class="pz-ico sn-ico" viewBox="-24 -24 48 48" aria-hidden="true" focusable="false">' + bowl(by(DIPS, idOf(DIP_RX, it.title, 'knob'))) + '</svg>';
		}
		function paintStage(s) { $('.sn-art').innerHTML = render(s || stateOf(sel)); }
		function say(t) { $('.pz-live').textContent = t; }
		function renderPanel() {
			var x = gr[tab], g = x.g, h = '';
			if (g.max > 1 || g.min > 0) { h += '<p class="pz-rule" data-rule></p>'; }
			h += '<ul class="pz-grid' + (x.role === 'sorte' ? ' sn-grid--sorte' : '') + '">';
			g.items.forEach(function (it) {
				var what = '';
				if (x.role === 'sorte') { var sk = by(SORTEN, idOf(SORT_RX, it.title, 'sambal')); what = '<span class="sn-what">' + esc(sk.what) + '</span>'; }
				h += '<li><button type="button" class="pz-tile" data-id="' + it.id + '" aria-pressed="false"><span class="pz-ico-wrap">' + icon(x.role, it) + '</span><span class="pz-name">' + esc(name(it)) + '</span>' + what + '<span class="pz-price" data-price="' + it.price + '"></span></button></li>';
			});
			$('#pz-panel').innerHTML = h + '</ul>';
			$('#pz-panel').setAttribute('aria-labelledby', 'pz-tab-' + tab);
			$$('.pz-tab').forEach(function (t, i) { t.setAttribute('aria-selected', i === tab ? 'true' : 'false'); t.tabIndex = i === tab ? 0 : -1; });
			refresh();
		}
		function refresh() {
			var m = cur().mult;
			$$('.pz-chip').forEach(function (c) { c.setAttribute('aria-pressed', +c.dataset.v === sel.vid ? 'true' : 'false'); });
			$$('.pz-price').forEach(function (e) { var pr = +e.dataset.price; e.textContent = pr ? '+' + fmt(Math.round(pr * m)) : 'inklusive'; });
			p.groups.forEach(function (g, i) { var n = groupSum(g), c = $('[data-count="' + i + '"]'); c.hidden = n === 0 || gr[i].role !== 'dip'; c.textContent = n; });
			var g = p.groups[tab], sum = groupSum(g), full = g.max > 1 && sum >= g.max;
			$$('.pz-tile').forEach(function (t) {
				var id = +t.dataset.id, q = sel.opts[id] || 0, it = items[id].it;
				t.setAttribute('aria-pressed', q > 0 ? 'true' : 'false'); t.disabled = q === 0 && full;
				t.setAttribute('aria-label', name(it) + (it.price ? ', +' + fmt(Math.round(it.price * m)) : ', inklusive'));
			});
			var rule = $('[data-rule]');
			if (rule) { rule.textContent = g.min > 0 && g.max === 1 ? (sum ? 'Gewählt. Tippe eine andere an, um zu wechseln.' : 'Pflicht: bitte eine wählen.') : (g.max > 1 ? 'Beliebig viele, höchstens ' + g.max + '. ' : '') + sum + (g.max > 1 ? ' von ' + g.max : '') + ' gewählt'; rule.classList.toggle('is-missing', tried && sum < g.min); }
			$('.pz-sum').textContent = summary();
			$$('.pz-tab').forEach(function (t, i) { t.classList.toggle('is-missing', tried && p.groups[i].min > 0 && groupSum(p.groups[i]) < p.groups[i].min); });
			$('#pz-qnum').textContent = sel.qty;
			var miss = missing().length, add = $('#pz-add');
			add.classList.toggle('is-blocked', miss > 0);
			$('#pz-add-label').textContent = miss ? 'Noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') : (editing ? 'Änderung speichern' : 'In den Warenkorb');
			$('#pz-add-price').textContent = miss ? '' : fmt(unit() * sel.qty);
		}
		function touched() { var z = $('.sn-zeus'); if (z) { z.hidden = true; } paintStage(); refresh(); }
		function pick(id) {
			var e = items[id], g = e.g, q = sel.opts[id] || 0;
			if (g.max === 1) {
				if (q && g.min > 0) { return; }                                   // a required single choice stays chosen
				g.items.forEach(function (x) { delete sel.opts[x.id]; });
				if (!q) { sel.opts[id] = 1; }
			} else if (q) { delete sel.opts[id]; }
			else if (g.max > 0 && groupSum(g) >= g.max) { say('Höchstens ' + g.max + ' aus „' + api.cleanTitle(g.title) + '“.'); return; }
			else { sel.opts[id] = 1; }
			touched();
			say(name(e.it) + (sel.opts[id] ? ' gewählt' : ' abgewählt') + '. ' + fmt(unit() * sel.qty) + '.');
		}

		/* the dice of Zeus: a random complete plate (six quick pictures like on the website, none with a reduced-motion setting) */
		function randomSel() {
			var s = { vid: p.variations[rnd(p.variations.length)].id, opts: {} };
			gr.forEach(function (x) {
				var it = x.g.items;
				if (!it.length) { return; }
				if (x.role === 'dip') { var pool = it.slice(), n = rnd(3); while (n-- > 0 && pool.length) { var k = rnd(pool.length); s.opts[pool[k].id] = 1; pool.splice(k, 1); } }
				else if (x.g.min > 0) { s.opts[it[rnd(it.length)].id] = 1; }
			});
			return s;
		}
		function sameSel(a, b) { return JSON.stringify([a.vid, Object.keys(a.opts).sort()]) === JSON.stringify([b.vid, Object.keys(b.opts).sort()]); }
		function roll() {
			if (rolling) { return; }
			var fin = randomSel(), guard = 0; while (sameSel(fin, sel) && guard++ < 20) { fin = randomSel(); }
			function finish() {
				sel.vid = fin.vid; sel.opts = fin.opts; rolling = false; $('.sn-dice').disabled = false;
				paintStage(); refresh(); $('.sn-zeus').hidden = false; say('Zeus hat gewürfelt. ' + summary());
			}
			if (reduce) { finish(); return; }
			rolling = true; $('.sn-dice').disabled = true;
			var step = 0;
			(function tick() { if (step++ < 6) { var t = randomSel(), st = stateOf(t); st.dips = []; paintStage(st); setTimeout(tick, 90); } else { finish(); } })();
		}

		dlg.onclick = function (ev) {
			var t = ev.target;
			if (t.closest('.pz-back')) { dlg.close(); return; }
			if (t.closest('.sn-dice')) { roll(); return; }
			var tb = t.closest('.pz-tab'); if (tb) { tab = +tb.dataset.tab; renderPanel(); return; }
			var ch = t.closest('.pz-chip'); if (ch) { sel.vid = +ch.dataset.v; touched(); return; }
			var tile = t.closest('.pz-tile'); if (tile) { pick(+tile.dataset.id); return; }
			if (t.closest('#pz-minus')) { sel.qty = Math.max(1, sel.qty - 1); refresh(); return; }
			if (t.closest('#pz-plus')) { sel.qty = Math.min(50, sel.qty + 1); refresh(); return; }
			if (t.closest('#pz-add')) {
				var mg = missing();
				if (mg.length) { tried = true; tab = p.groups.indexOf(mg[0]); renderPanel(); say('Bitte noch ' + mg.length + (mg.length === 1 ? ' Pflichtangabe' : ' Pflichtangaben') + ' wählen.'); return; }
				var v = cur(), parts = [];
				p.groups.forEach(function (g) { g.items.forEach(function (it) { if (sel.opts[it.id]) { parts.push(it.title); } }); });
				var line = { pid: p.id, title: p.title, vid: sel.vid, vtitle: v ? v.title : '', opts: JSON.parse(JSON.stringify(sel.opts)), optText: parts.join(', '), unit: unit(), qty: sel.qty, note: editing ? (edit.line.note || '') : '', ch: 1 };
				if (editing) { api.replace(edit.i, line); } else { api.add(line); }
				dlg.close();
			}
		};
		dlg.onkeydown = function (ev) {
			var tb = ev.target.closest && ev.target.closest('.pz-tab');
			if (tb && (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft')) { tab = (tab + (ev.key === 'ArrowRight' ? 1 : p.groups.length - 1)) % p.groups.length; renderPanel(); var n = $('#pz-tab-' + tab); if (n) { n.focus(); } ev.preventDefault(); }
		};

		// the pizza table may have left an observer on its stage: not needed here
		if (dlg._pzRO) { dlg._pzRO.disconnect(); dlg._pzRO = null; }
		tab = Math.max(0, p.groups.indexOf(byRole.sorte));
		renderPanel(); paintStage();
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
		return true;
	}

	window.SchnitzelLab = { open: open, render: render };
})();
