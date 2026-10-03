/* Pizza configurator ("Deine Pizza nach Wunsch"): the guest tops a raw dough by tapping the ingredient list. Every option of the
 * dish that carries a symbol (server: shop_item_icon()) becomes an ingredient tile and shows on the pizza; the rest are plain extras.
 * It is only another way to fill the same cart line as the normal product dialog (opts: option id -> amount), so prices, limits and
 * the kitchen text stay the server's business. Placement is symmetric: every portion lands on an orbit of six pieces turned by 60 degrees.
 * The art is authored SVG (64 x 64 symbols, painted look); no library, no images. */
(function () {
	'use strict';
	var NS = 'http://www.w3.org/2000/svg';

	/* ---------- art: t = piece (on the pizza), sauce / melt (a layer under the pieces), side (tile only); o = orbits per portion, k = size ---------- */
	function drop(f, s) {
		return '<path d="M32 7c11 14 19 23 19 33a19 19 0 0 1-38 0c0-10 8-19 19-33z" fill="' + f + '" stroke="' + s + '" stroke-width="3" stroke-linejoin="round"/>' +
			'<path d="M21 41a11 11 0 0 0 9 10" fill="none" stroke="#fff" stroke-opacity=".65" stroke-width="3" stroke-linecap="round"/>';
	}
	var ART = {
		tomato: { t: 'piece', o: 1, k: 1.05, g: '<circle cx="32" cy="32" r="24" fill="#d8402e" stroke="#8d2316" stroke-width="3"/><circle cx="32" cy="32" r="17" fill="#ee6a52"/><g fill="#f6cf5a" stroke="#c98a2a" stroke-width="1"><ellipse cx="32" cy="22" rx="3.5" ry="5"/><ellipse cx="22" cy="36" rx="3.5" ry="5" transform="rotate(-60 22 36)"/><ellipse cx="42" cy="36" rx="3.5" ry="5" transform="rotate(60 42 36)"/></g><path d="M18 24a16 16 0 0 1 12-9" stroke="#ffb4a0" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		spinach: { t: 'piece', o: 1, k: 1.1, g: '<path d="M32 6c14 6 22 20 14 34-4 7-10 12-14 18-4-6-10-11-14-18C10 26 18 12 32 6z" fill="#4c8a38" stroke="#2c5a22" stroke-width="3" stroke-linejoin="round"/><path d="M32 12v44M32 26l-9-6M32 34l10-7M32 42l-8-5" stroke="#8cc86a" stroke-width="2.4" fill="none" stroke-linecap="round"/>' },
		onion: { t: 'piece', o: 1, k: 1.05, g: '<g fill="none" stroke-linecap="round"><path d="M8 42A25 25 0 0 1 54 24" stroke="#9c3f78" stroke-width="6.5"/><path d="M15 40A17 17 0 0 1 48 28" stroke="#d98bb7" stroke-width="5.5"/><path d="M22 38A10 10 0 0 1 42 32" stroke="#f3d8e8" stroke-width="5"/></g>' },
		olive: { t: 'piece', o: 2, k: .8, g: '<circle cx="32" cy="32" r="21" fill="#7d9230" stroke="#47561a" stroke-width="3.5"/><circle cx="32" cy="32" r="8.5" fill="#cf4a35" stroke="#7a2418" stroke-width="2.5"/><path d="M17 25a17 17 0 0 1 10-9" stroke="#b7cc62" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		pepperoni: { t: 'piece', o: 2, k: .85, g: '<circle cx="32" cy="32" r="22" fill="#9cc05a" stroke="#5d7f2b" stroke-width="3.5"/><circle cx="32" cy="32" r="12" fill="#e7f0b4" stroke="#7f9c3c" stroke-width="2"/><g fill="#fbf6d0"><circle cx="32" cy="26" r="2"/><circle cx="26" cy="35" r="2"/><circle cx="38" cy="35" r="2"/></g><path d="M16 26a18 18 0 0 1 11-10" stroke="#d3e79a" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		pepper: { t: 'piece', o: 1, k: 1.1, g: '<path d="M7 42C10 22 34 8 56 22c-6 6-14 8-20 10-8 3-14 8-18 20-5 0-9-4-11-10z" fill="#d93a2b" stroke="#8a1d14" stroke-width="3" stroke-linejoin="round"/><path d="M15 36c6-12 22-18 34-12" stroke="#f58a73" stroke-width="3.2" fill="none" stroke-linecap="round"/>' },
		corn: { t: 'piece', o: 2, k: .8, g: '<g fill="#f3c42d" stroke="#b78a12" stroke-width="2"><ellipse cx="21" cy="24" rx="7" ry="9" transform="rotate(-20 21 24)"/><ellipse cx="41" cy="21" rx="7" ry="9" transform="rotate(15 41 21)"/><ellipse cx="32" cy="40" rx="7.5" ry="9.5"/><ellipse cx="48" cy="40" rx="6" ry="8" transform="rotate(25 48 40)"/><ellipse cx="14" cy="43" rx="6" ry="8" transform="rotate(-25 14 43)"/></g><g fill="#fff3a8"><circle cx="19" cy="21" r="2.2"/><circle cx="39" cy="17" r="2.2"/><circle cx="30" cy="36" r="2.4"/></g>' },
		broccoli: { t: 'piece', o: 1, k: 1.05, g: '<path d="M30 44l-3 17h10l-3-17z" fill="#a6c46a" stroke="#5f7a30" stroke-width="2.5" stroke-linejoin="round"/><g fill="#3f8032" stroke="#245018" stroke-width="2.5"><circle cx="21" cy="28" r="12"/><circle cx="39" cy="22" r="13"/><circle cx="46" cy="38" r="10"/><circle cx="29" cy="40" r="11"/></g><g fill="#6dac56"><circle cx="18" cy="24" r="3.2"/><circle cx="36" cy="18" r="3.6"/><circle cx="44" cy="34" r="3"/><circle cx="26" cy="36" r="3.2"/></g>' },
		artichoke: { t: 'piece', o: 1, k: 1.1, g: '<path d="M32 6c11 9 21 19 19 34-2 11-10 17-19 17S15 51 13 40C11 25 21 15 32 6z" fill="#8f9a52" stroke="#566029" stroke-width="3" stroke-linejoin="round"/><path d="M32 21c6 6 11 13 9 23-2 6-5 10-9 11-4-1-7-5-9-11-2-10 3-17 9-23z" fill="#cdd48c"/><path d="M32 26v26" stroke="#8f9a52" stroke-width="2" stroke-linecap="round"/>' },
		pineapple: { t: 'piece', o: 1, k: 1.05, g: '<path d="M10 24c8-11 30-13 43-2 4 15-3 29-16 35-13 2-26-6-27-19z" fill="#f2cd45" stroke="#b08a14" stroke-width="3" stroke-linejoin="round"/><path d="M19 25l24 24M31 18l18 18M17 38l15 15" stroke="#d8a91f" stroke-width="2" fill="none" stroke-linecap="round"/><path d="M17 27c6-7 15-9 23-6" stroke="#fff0a0" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		sundried: { t: 'piece', o: 1, k: 1, g: '<path d="M8 40C6 24 22 11 41 13c11 2 17 11 13 21-4 9-12 9-21 7-6-1-10 4-17 6-5 0-7-3-8-7z" fill="#8f2b1e" stroke="#521309" stroke-width="3" stroke-linejoin="round"/><path d="M17 26c8-7 19-7 27-2M15 36c8 0 15-5 23 0" stroke="#c4553f" stroke-width="2.4" fill="none" stroke-linecap="round"/>' },
		arugula: { t: 'piece', o: 1, k: 1.1, g: '<path d="M32 5c4 7 2 10 8 11 6 0 10-2 13 2-2 6-8 6-6 10 2 4 8 3 9 9-6 4-10 0-13 4-2 4 2 8-2 12-4 2-6-4-9-4s-5 6-9 4c-4-4 0-8-2-12-3-4-7 0-13-4 1-6 7-5 9-9 2-4-4-4-6-10 3-4 7-2 13-2 6-1 4-4 8-11z" fill="#5fa12f" stroke="#33621a" stroke-width="3" stroke-linejoin="round"/><path d="M32 10v48M32 24l-10-4M32 32l11-5M32 42l-10-4" stroke="#a6dc6e" stroke-width="2.4" fill="none" stroke-linecap="round"/>' },
		caper: { t: 'piece', o: 2, k: .75, g: '<g fill="#7f9332" stroke="#465718" stroke-width="2.5"><circle cx="22" cy="26" r="10"/><circle cx="43" cy="24" r="9"/><circle cx="34" cy="45" r="10"/></g><g fill="#c1d36e"><circle cx="19" cy="22" r="2.8"/><circle cx="40" cy="20" r="2.6"/><circle cx="31" cy="41" r="2.8"/></g>' },
		mushroom: { t: 'piece', o: 1, k: 1.05, g: '<path d="M7 28C7 15 18 7 32 7s25 8 25 21c0 6-4 9-9 9h-3c0 6-2 14-4 21H23c-2-7-4-15-4-21h-3c-5 0-9-3-9-9z" fill="#e9dcc0" stroke="#8c7447" stroke-width="3" stroke-linejoin="round"/><path d="M12 25c2-9 11-14 20-14" stroke="#b8935a" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M26 40c0 6 2 10 2 15h8c0-5 2-9 2-15z" fill="#f7f0de"/>' },
		melt: { t: 'melt', c: '#f2cf68', g: '<path d="M5 45L59 21v21L5 54z" fill="#f4c94a" stroke="#a87d16" stroke-width="3" stroke-linejoin="round"/><path d="M5 45L59 21" stroke="#fde68a" stroke-width="3" stroke-linecap="round"/><g fill="#d9a82a"><circle cx="21" cy="47" r="3.2"/><circle cx="40" cy="37" r="3.6"/></g>' },
		parmesan: { t: 'piece', o: 2, k: .95, g: '<g fill="#f3e4b0" stroke="#bda35a" stroke-width="2.5" stroke-linejoin="round"><path d="M8 42c4-15 19-20 30-13-4 2-10 6-12 15z"/><path d="M30 49c4-13 17-17 26-11-4 2-8 6-10 13z"/><path d="M21 23c4-11 15-13 22-7-4 2-8 4-10 10z"/></g>' },
		gorgonzola: { t: 'piece', o: 2, k: .9, g: '<path d="M8 31c2-13 17-19 30-15 12 2 17 15 12 25-6 11-23 13-34 6-6-4-10-8-8-16z" fill="#ece5d0" stroke="#aaa183" stroke-width="3" stroke-linejoin="round"/><g fill="#5f7ea6"><circle cx="26" cy="28" r="3.2"/><circle cx="39" cy="37" r="3.6"/><circle cx="30" cy="43" r="2.5"/><circle cx="45" cy="26" r="2.3"/></g>' },
		mozzarella: { t: 'piece', o: 1, k: 1.05, g: '<circle cx="32" cy="32" r="24" fill="#f8f3e6" stroke="#bfb392" stroke-width="3"/><circle cx="32" cy="32" r="17" fill="#fffdf6"/><path d="M15 27a19 19 0 0 1 13-11" stroke="#fff" stroke-width="4" fill="none" stroke-linecap="round"/><g fill="#e8dfc4"><circle cx="41" cy="41" r="3"/><circle cx="25" cy="43" r="2"/></g>' },
		feta: { t: 'piece', o: 2, k: .85, g: '<g stroke="#bdb59a" stroke-width="2.5" stroke-linejoin="round"><path d="M8 24l17-8 15 8-17 9z" fill="#fffdf4"/><path d="M8 24v15l15 9V33z" fill="#e8e1c9"/><path d="M23 33v15l17-9V24z" fill="#f4efdc"/><path d="M38 40l12-5 11 5-12 6z" fill="#fffdf4"/></g>' },
		ham: { t: 'piece', o: 1, k: 1.1, g: '<path d="M7 37c0-17 15-27 32-25 12 2 19 13 17 23-3 13-15 19-28 17-12 0-21-6-21-15z" fill="#e69a9a" stroke="#a85a5e" stroke-width="3" stroke-linejoin="round"/><path d="M13 35c6-13 19-17 31-13" stroke="#f6c2c0" stroke-width="4" fill="none" stroke-linecap="round"/><path d="M21 47c9 4 19 2 28-7" stroke="#c97a7e" stroke-width="2.4" fill="none" stroke-linecap="round"/>' },
		salami: { t: 'piece', o: 1, k: 1.1, g: '<circle cx="32" cy="32" r="25" fill="#b6362e" stroke="#6e1a16" stroke-width="3.5"/><circle cx="32" cy="32" r="19" fill="#c9473c"/><g fill="#f3d6c8"><circle cx="24" cy="24" r="3"/><circle cx="40" cy="26" r="2.6"/><circle cx="30" cy="38" r="3.2"/><circle cx="44" cy="40" r="2.4"/><circle cx="20" cy="38" r="2"/></g><path d="M14 27a20 20 0 0 1 12-13" stroke="#e98b7b" stroke-width="3.5" fill="none" stroke-linecap="round"/>' },
		sucuk: { t: 'piece', o: 1, k: 1.05, g: '<circle cx="32" cy="32" r="24" fill="#8a2a24" stroke="#4a1210" stroke-width="3.5"/><circle cx="32" cy="32" r="18" fill="#a23a2c"/><g fill="#e4a64a"><circle cx="25" cy="25" r="2.2"/><circle cx="39" cy="28" r="2"/><circle cx="31" cy="39" r="2.4"/><circle cx="43" cy="40" r="1.8"/><circle cx="21" cy="37" r="1.8"/></g><path d="M15 28a19 19 0 0 1 12-12" stroke="#d1705e" stroke-width="3.2" fill="none" stroke-linecap="round"/>' },
		chicken: { t: 'piece', o: 1, k: 1.1, g: '<path d="M8 35c0-15 13-25 28-23 13 2 21 13 19 25-2 11-15 17-27 15-11-2-20-6-20-17z" fill="#e8c88e" stroke="#a37a3a" stroke-width="3" stroke-linejoin="round"/><path d="M17 26l11 11M28 19l13 13M23 40l9 9" stroke="#a9742f" stroke-width="3.5" stroke-linecap="round"/><path d="M15 32c4-11 15-15 23-13" stroke="#fbe9bf" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		tuna: { t: 'piece', o: 1, k: 1.05, g: '<g fill="#cfa99c" stroke="#8e6b60" stroke-width="2.5" stroke-linejoin="round"><path d="M7 31l15-13 13 9-6 15-17 2z"/><path d="M30 25l17-9 11 13-11 13-15-4z"/><path d="M19 47l13-7 15 7-9 10-17-2z"/></g><path d="M11 31l11-9M34 27l11-7" stroke="#ead2c9" stroke-width="2.4" stroke-linecap="round"/>' },
		shrimp: { t: 'piece', o: 1, k: 1.1, g: '<path d="M44 11c11 8 13 23 4 34-9 11-26 11-34 0 9 4 20 2 26-6 6-9 4-19 4-28z" fill="#f08850" stroke="#a4461c" stroke-width="3" stroke-linejoin="round"/><path d="M30 44l8-10M38 49l9-9M23 40l7-9" stroke="#ffcfa6" stroke-width="2.6" stroke-linecap="round"/><path d="M44 11c4-4 10-5 15-2" stroke="#a4461c" stroke-width="2.4" fill="none" stroke-linecap="round"/>' },
		nugget: { t: 'piece', o: 1, k: 1.05, g: '<rect x="9" y="15" width="46" height="35" rx="15" fill="#cd8e3f" stroke="#7b4a17" stroke-width="3.5" transform="rotate(-12 32 32)"/><g fill="#e9b56a"><circle cx="24" cy="28" r="3"/><circle cx="36" cy="37" r="2.6"/><circle cx="43" cy="26" r="2.3"/></g>' },
		patty: { t: 'piece', o: 1, k: 1.05, g: '<circle cx="32" cy="32" r="24" fill="#6a3d28" stroke="#33190d" stroke-width="3.5"/><g fill="#8c5a3c"><circle cx="22" cy="24" r="5"/><circle cx="38" cy="22" r="4.5"/><circle cx="30" cy="36" r="5.5"/><circle cx="43" cy="38" r="4"/><circle cx="19" cy="40" r="3.5"/></g><path d="M14 28a19 19 0 0 1 11-12" stroke="#a46b46" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		sauce_hollandaise: { t: 'sauce', c: '#f0d35c', g: drop('#f0d35c', '#a98a1a') },
		// Sambal Hollandaise: a yellow hollandaise with a slight red cast and flecks of sambal oelek (f)
		sauce_sambal: { t: 'sauce', c: '#efb55c', f: '#c4402b', g: drop('#efb55c', '#a8651f') + '<g fill="#c4402b"><circle cx="26" cy="39" r="2.1"/><circle cx="36" cy="45" r="1.9"/><circle cx="31" cy="31" r="1.7"/><circle cx="39" cy="37" r="1.6"/><circle cx="24" cy="46" r="1.4"/></g>' },
		sauce_creme: { t: 'sauce', c: '#f6f0e2', g: drop('#f6f0e2', '#b8ad92') },
		sauce_bbq: { t: 'sauce', c: '#9a3d20', g: drop('#9a3d20', '#58200d') },
		// Sticky Korean BBQ: a dark brown, glossy sticky glaze with sesame seeds (f); the barbecue above is the redder one
		sauce_korean: { t: 'sauce', c: '#4a2a1b', f: '#f6eedc', g: drop('#4a2a1b', '#21100a') + '<path d="M24 20c-3 6-3 12 0 18" fill="none" stroke="#b58a6a" stroke-opacity=".75" stroke-width="2.4" stroke-linecap="round"/><g fill="#f6eedc" stroke="#9a6b3a" stroke-width=".5"><ellipse cx="28" cy="40" rx="2" ry="1.2" transform="rotate(25 28 40)"/><ellipse cx="36" cy="35" rx="2" ry="1.2" transform="rotate(-30 36 35)"/><ellipse cx="32" cy="46" rx="2" ry="1.2" transform="rotate(60 32 46)"/><ellipse cx="38" cy="43" rx="1.8" ry="1.1" transform="rotate(10 38 43)"/><ellipse cx="30" cy="30" rx="1.8" ry="1.1" transform="rotate(-50 30 30)"/></g>' },
		sauce_garlic: { t: 'sauce', c: '#f4ecd6', g: drop('#f4ecd6', '#bcae88') },
		sauce_curry: { t: 'sauce', c: '#e2a626', g: drop('#e2a626', '#8f6410') },
		sauce_other: { t: 'sauce', c: '#d4a762', g: drop('#d4a762', '#8a6528') },
		dip: { t: 'side', g: '<path d="M11 25h42l-4 27a6 6 0 0 1-6 5H21a6 6 0 0 1-6-5z" fill="#efe4cc" stroke="#8a7551" stroke-width="3" stroke-linejoin="round"/><ellipse cx="32" cy="25" rx="21" ry="7" fill="#d9a43a" stroke="#8a7551" stroke-width="3"/><path d="M19 23c7 3 19 3 26 0" stroke="#f4cf72" stroke-width="3" fill="none" stroke-linecap="round"/>' },
		plus: { t: 'side', g: '<circle cx="32" cy="32" r="22" fill="#e7d6b0" stroke="#8a7551" stroke-width="3"/><path d="M32 20v24M20 32h24" stroke="#6b5532" stroke-width="5" stroke-linecap="round"/>' }
	};

	/* ---------- geometry ---------- */
	var R = 100;                                        // dough radius in the pizza's own units (view box -124..124)
	var RINGS = [66, 52, 38, 74, 59, 45, 31];           // sauce swirls, outermost-first by preference
	var ORBITS = (function () {
		// candidate orbits: a radius and a start angle, each stands for six points turned by 60 degrees. Greedy farthest-point order, so
		// the first portions spread over the whole dough instead of piling up
		var cand = [], a, j, chosen = [], pts = [];
		[24, 38, 52, 64, 76].forEach(function (rr) { for (a = 0; a < 60; a += 5) { cand.push({ r: rr, t0: a }); } });
		function points(o) { var p = []; for (j = 0; j < 6; j++) { var ang = (o.t0 + j * 60) * Math.PI / 180; p.push([o.r * Math.cos(ang), o.r * Math.sin(ang)]); } return p; }
		var first = cand.filter(function (c) { return c.r === 52 && c.t0 === 0; })[0];
		chosen.push(first); pts = pts.concat(points(first));
		while (chosen.length < 30) {
			var best = null, bd = -1;
			cand.forEach(function (c) {
				if (chosen.indexOf(c) >= 0) { return; }
				var cp = points(c), d = 1e9;
				cp.forEach(function (p) { pts.forEach(function (q) { var dd = Math.hypot(p[0] - q[0], p[1] - q[1]); if (dd < d) { d = dd; } }); });
				if (d > bd) { bd = d; best = c; }
			});
			chosen.push(best); pts = pts.concat(points(best));
		}
		return chosen.map(function (c) { return { pts: points(c) }; });
	})();
	// the oval (Flammkuchen): a portion is four pieces, mirrored left/right and top/bottom, so the layout is symmetric on both axes
	var DX = 136, DY = 84;                              // semi-axes of the oval dough
	var ORBITS_OVAL = (function () {
		var cand = [], chosen = [], pts = [];
		[.22, .38, .54, .68, .8].forEach(function (rf) { for (var t = 10; t <= 80; t += 10) { cand.push({ r: rf, t: t }); } });
		function points(o) { var a = o.t * Math.PI / 180, x = o.r * DX * Math.cos(a), y = o.r * DY * Math.sin(a); return [[x, y], [-x, y], [-x, -y], [x, -y]]; }
		var first = cand.filter(function (c) { return c.r === .54 && c.t === 40; })[0];
		chosen.push(first); pts = pts.concat(points(first));
		while (chosen.length < 36) {
			var best = null, bd = -1;
			cand.forEach(function (c) {
				if (chosen.indexOf(c) >= 0) { return; }
				var d = 1e9;
				points(c).forEach(function (p) { pts.forEach(function (q) { var dd = Math.hypot(p[0] - q[0], p[1] - q[1]); if (dd < d) { d = dd; } }); });
				if (d > bd) { bd = d; best = c; }
			});
			chosen.push(best); pts = pts.concat(points(best));
		}
		return chosen.map(function (c) { return { pts: points(c) }; });
	})();
	function seed(str) { var h = 0, i; for (i = 0; i < str.length; i++) { h = (h * 31 + str.charCodeAt(i)) % 360; } return h; }
	function wobble(r, amp, n, ph) {
		var d = '', i, steps = 72, a, rr;
		for (i = 0; i <= steps; i++) { a = i / steps * Math.PI * 2; rr = r + amp * Math.sin(a * n + ph); d += (i ? 'L' : 'M') + (rr * Math.cos(a)).toFixed(1) + ' ' + (rr * Math.sin(a)).toFixed(1); }
		return d + 'Z';
	}
	function wobbleE(rx, ry, amp, n, ph) {
		var d = '', i, steps = 90, a, f;
		for (i = 0; i <= steps; i++) { a = i / steps * Math.PI * 2; f = 1 + amp / 100 * Math.sin(a * n + ph); d += (i ? 'L' : 'M') + (rx * f * Math.cos(a)).toFixed(1) + ' ' + (ry * f * Math.sin(a)).toFixed(1); }
		return d + 'Z';
	}
	function el(tag, attrs, parent) {
		var e = document.createElementNS(NS, tag);
		Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); });
		if (parent) { parent.appendChild(e); }
		return e;
	}

	/* ---------- sprite (once) ---------- */
	function sprite() {
		if (document.getElementById('pz-sprite')) { return; }
		var h = '<svg id="pz-sprite" width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>';
		Object.keys(ART).forEach(function (k) { h += '<symbol id="pz-k-' + k + '" viewBox="0 0 64 64">' + ART[k].g + '</symbol>'; });
		document.body.insertAdjacentHTML('beforeend', h + '</defs></svg>');
	}

	/* the table: planks of authored paint (no image, no repeating gradient) */
	function wood() {
		var h = '', tones = ['#6c4527', '#5f3c21', '#70492b', '#593820', '#664226'], hs = [62, 58, 64, 56, 60], i, j, y = 0;
		for (i = 0; i < 5; i++) {
			h += '<rect x="0" y="' + y + '" width="400" height="' + hs[i] + '" fill="' + tones[i] + '"/><rect x="0" y="' + (y + hs[i] - 2) + '" width="400" height="2" fill="#2e1a0c" fill-opacity=".65"/>';
			for (j = 0; j < 7; j++) { var gy = y + 8 + ((j * 37 + i * 11) % (hs[i] - 14)); h += '<path d="M' + ((j * 61 + i * 23) % 120 - 20) + ' ' + gy + 'c60 -3 110 4 180 0s120 -4 260 2" fill="none" stroke="#2e1a0c" stroke-opacity=".22" stroke-width="1.2"/>'; }
			h += '<ellipse cx="' + (60 + i * 71) + '" cy="' + (y + hs[i] / 2) + '" rx="9" ry="5" fill="#4a2c16" fill-opacity=".55"/>';
			y += hs[i];
		}
		return h;
	}

	/* ---------- the dialog ---------- */
	function open(p, edit, api) {
		var fmt = api.fmt, esc = api.esc;
		var dlg = document.getElementById('pizza-dialog');
		if (!dlg) { return false; }
		sprite();
		var sel = { vid: p.variations.length ? p.variations[0].id : 0, opts: {}, qty: 1 };
		var items = {}, alloc = {}, ringOf = {}, tried = false, tab = 0, editing = !!edit;
		var oval = p.configurator === 2, ORB = oval ? ORBITS_OVAL : ORBITS;          // 2 = Flammkuchen: oval, extra thin, on a long board
		function $$(s, r) { return Array.prototype.slice.call((r || dlg).querySelectorAll(s)); }
		function $(s) { return dlg.querySelector(s); }
		p.groups.forEach(function (g) { g.items.forEach(function (it) { items[it.id] = { it: it, g: g }; }); });
		if (editing) {
			var ln = edit.line;
			if (p.variations.some(function (v) { return v.id === ln.vid; })) { sel.vid = ln.vid; }
			Object.keys(ln.opts || {}).forEach(function (k) { if (items[k]) { sel.opts[k] = Math.min(+ln.opts[k], items[k].it.max); } });
			sel.qty = ln.qty;
		}
		function cur() { return p.variations.filter(function (x) { return x.id === sel.vid; })[0]; }
		function mult() { var v = cur(); return v ? v.mult : 1; }
		function base() { var v = cur(); return v ? v.price : p.price; }
		function groupSum(g) { return g.items.reduce(function (s, it) { return s + (sel.opts[it.id] || 0); }, 0); }
		function unit() { var x = 0; Object.keys(sel.opts).forEach(function (k) { x += items[k].it.price * sel.opts[k]; }); return base() + Math.round(x * mult()); }
		function name(it) { return String(it.title).replace(/\s+(auf Pizza|zum Dippen)\s*$/i, '').replace(/^extra\s+/i, ''); }
		function gtitle(g) { return api.cleanTitle(g.title); }
		function artOf(it) { return it.icon && ART[it.icon] ? ART[it.icon] : null; }
		function isOnPizza(it) { var a = artOf(it); return !!a && a.t !== 'side'; }
		function iconKey(it) { return artOf(it) ? it.icon : 'plus'; }
		function missing() { return p.groups.filter(function (g) { return g.min > 0 && groupSum(g) < g.min; }); }

		/* markup */
		var tabs = p.groups.map(function (g, i) {
			return '<button type="button" class="pz-tab" role="tab" data-tab="' + i + '" aria-selected="false" id="pz-tab-' + i + '" aria-controls="pz-panel"><span>' + esc(gtitle(g)) + '</span><span class="pz-count" data-count="' + i + '" hidden></span></button>';
		}).join('');
		var teig = p.variations.length ? '<div class="pz-teig" role="group" aria-label="Teig"><span class="pz-label">Teig</span>' + p.variations.map(function (v) {
			var diff = v.price - p.variations[0].price;
			return '<button type="button" class="pz-chip" data-v="' + v.id + '" aria-pressed="false">' + esc(v.title) + (diff ? ' <small>' + (diff > 0 ? '+' : '') + fmt(diff) + '</small>' : '') + '</button>';
		}).join('') + '</div>' : '';
		dlg.innerHTML =
			'<header class="pz-top"><button type="button" class="pz-back" aria-label="Zurück zur Speisekarte"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></button>' +
			'<h2 id="pz-title">' + esc(p.title) + '</h2><button type="button" class="pz-reset" hidden>Neu belegen</button></header>' +
			'<div class="pz-stage"><svg class="pz-wood" viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice" aria-hidden="true">' + wood() + '</svg>' +
			'<svg class="pz-pizza" viewBox="-124 -124 248 248" role="img" aria-label="Deine Pizza von oben"></svg>' +
			'<div class="pz-dips" aria-hidden="true"></div><p class="pz-sum"></p></div>' +
			'<section class="pz-bench">' + teig + '<div class="pz-tabs" role="tablist" aria-label="Zutaten">' + tabs + '</div><div class="pz-panel" id="pz-panel" role="tabpanel"></div></section>' +
			'<footer class="pz-foot"><span class="pz-step"><button type="button" class="pz-qty" id="pz-minus" aria-label="Weniger">&minus;</button><span class="pz-qnum" id="pz-qnum">1</span><button type="button" class="pz-qty" id="pz-plus" aria-label="Mehr">+</button></span>' +
			'<button type="button" class="pz-add" id="pz-add"><span id="pz-add-label"></span><span id="pz-add-price"></span></button></footer><p class="pz-live" role="status" aria-live="polite"></p>';

		/* pizza drawing */
		var svg = $('.pz-pizza');
		var defs = el('defs', {}, svg), i, rs;
		var g1 = el('radialGradient', { id: 'pz-g-dough', cx: '42%', cy: '36%', r: '70%' }, defs), s1a = el('stop', { offset: '0', 'stop-color': '#f6e2b6' }, g1), s1b = el('stop', { offset: '1', 'stop-color': '#e3bf84' }, g1);
		var g2 = el('radialGradient', { id: 'pz-g-board', cx: '40%', cy: '35%', r: '80%' }, defs); el('stop', { offset: '0', 'stop-color': '#6a6561' }, g2); el('stop', { offset: '1', 'stop-color': '#3d3935' }, g2);
		if (oval) {
			// a long wooden board (Flammkuchen brett) instead of the round peel
			svg.setAttribute('viewBox', '-172 -116 344 232'); svg.classList.add('is-oval'); svg.style.aspectRatio = '344 / 232';
			var g3 = el('linearGradient', { id: 'pz-g-brett', x1: '0', y1: '0', x2: '0', y2: '1' }, defs); el('stop', { offset: '0', 'stop-color': '#c9a06b' }, g3); el('stop', { offset: '1', 'stop-color': '#a87a46' }, g3);
			el('rect', { x: -160, y: -92, width: 332, height: 208, rx: 26, fill: '#000', 'fill-opacity': '.38' }, svg);
			el('rect', { x: -166, y: -100, width: 332, height: 208, rx: 26, fill: 'url(#pz-g-brett)', stroke: '#6b4423', 'stroke-width': 3 }, svg);
			el('rect', { x: -158, y: -92, width: 316, height: 192, rx: 20, fill: 'none', stroke: '#e6c896', 'stroke-opacity': '.45', 'stroke-width': 1.5 }, svg);
			for (i = 0; i < 9; i++) { var gy = -84 + i * 21 + (i % 3) * 3; el('path', { d: 'M-156 ' + gy + 'c70 -5 130 5 200 0s90 -5 110 2', fill: 'none', stroke: '#6b4423', 'stroke-opacity': '.2', 'stroke-width': 1.3 }, svg); }
			el('circle', { cx: 148, cy: 4, r: 6.5, fill: '#4a2c16', 'fill-opacity': '.75' }, svg);
			el('ellipse', { cx: 2, cy: 4, rx: DX + 2, ry: DY + 2, fill: '#000', 'fill-opacity': '.28' }, svg);
		} else {
			el('circle', { cx: 6, cy: 9, r: 119, fill: '#000', 'fill-opacity': '.38' }, svg);
			el('circle', { r: 118, fill: 'url(#pz-g-board)', stroke: '#2a2724', 'stroke-width': 3 }, svg);
			el('circle', { r: 110, fill: 'none', stroke: '#8a847d', 'stroke-opacity': '.35', 'stroke-width': 1.5 }, svg);
			el('circle', { cx: 2, cy: 4, r: R + 2, fill: '#000', 'fill-opacity': '.28' }, svg);
		}
		// the dough: light wheat dough, or darker whole-grain dough with bran flecks and oat flakes for a spelt-rye variation (the title decides)
		var doughG = el('g', {}, svg);
		function wholeGrain() { var v = cur(); return !!v && /dinkel|roggen|vollkorn/i.test(v.title); }
		// the thin oval dough: no raised rim, an uneven baked edge with a few charred blisters; whole grain adds bran flecks and oat flakes
		function drawOvalDough(c, whole) {
			var k, ang, f, x, y;
			el('path', { d: wobbleE(DX, DY, 1.1, 9, .3), fill: 'url(#pz-g-dough)', stroke: c.edge, 'stroke-width': 3, 'stroke-linejoin': 'round' }, doughG);
			el('path', { d: wobbleE(DX - 5, DY - 4, 1.1, 9, .3), fill: 'none', stroke: c.hi, 'stroke-opacity': c.hia, 'stroke-width': 2.4 }, doughG);
			for (k = 0; k < 30; k++) { ang = ((k * 137.5) % 360) * Math.PI / 180; f = .9 + (k % 4) * .02; x = DX * f * Math.cos(ang); y = DY * f * Math.sin(ang); el('ellipse', { cx: x.toFixed(1), cy: y.toFixed(1), rx: 2 + (k % 3), ry: 1.4 + (k % 2) * .6, fill: k % 3 ? c.dk : '#7a4a22', 'fill-opacity': k % 3 ? '.5' : '.6', transform: 'rotate(' + ((k * 37) % 180) + ' ' + x.toFixed(1) + ' ' + y.toFixed(1) + ')' }, doughG); }
			for (k = 0; k < 40; k++) { ang = ((k * 53.7) % 360) * Math.PI / 180; f = .12 + ((k * 29) % 78) / 100; el('circle', { cx: (DX * f * Math.cos(ang)).toFixed(1), cy: (DY * f * Math.sin(ang)).toFixed(1), r: .9 + (k % 3) * .5, fill: c.fl, 'fill-opacity': c.fa }, doughG); }
			if (whole) {
				for (k = 0; k < 90; k++) { ang = ((k * 97.3 + 11) % 360) * Math.PI / 180; f = .08 + ((k * 41) % 84) / 100; x = DX * f * Math.cos(ang); y = DY * f * Math.sin(ang); el('ellipse', { cx: x.toFixed(1), cy: y.toFixed(1), rx: 1.1 + (k % 4) * .5, ry: .7 + (k % 3) * .3, fill: '#5c3a1b', 'fill-opacity': '.5', transform: 'rotate(' + ((k * 47) % 180) + ' ' + x.toFixed(1) + ' ' + y.toFixed(1) + ')' }, doughG); }
				for (k = 0; k < 28; k++) { ang = ((k * 163.7 + 40) % 360) * Math.PI / 180; f = .1 + ((k * 37) % 80) / 100; x = DX * f * Math.cos(ang); y = DY * f * Math.sin(ang); el('ellipse', { cx: x.toFixed(1), cy: y.toFixed(1), rx: 3, ry: 1.7, fill: '#f1dfb8', 'fill-opacity': '.8', stroke: '#b99455', 'stroke-width': .5, transform: 'rotate(' + ((k * 71) % 180) + ' ' + x.toFixed(1) + ' ' + y.toFixed(1) + ')' }, doughG); }
			}
		}
		function drawDough() {
			var whole = wholeGrain(), c = whole ? { a: '#d6ad72', b: '#b6844d', edge: '#94672f', ring: '#a77b41', hi: '#efd29c', hia: '.42', dk: '#8a5f2c', lt: '#e4c28c', fl: '#f0dfbd', fa: '.28' } : { a: '#f6e2b6', b: '#e3bf84', edge: '#c79a55', ring: '#d7ad68', hi: '#fff4d2', hia: '.55', dk: '#c99a55', lt: '#fff1c9', fl: '#fff', fa: '.35' };
			s1a.setAttribute('stop-color', c.a); s1b.setAttribute('stop-color', c.b);
			while (doughG.firstChild) { doughG.removeChild(doughG.firstChild); }
			if (oval) { drawOvalDough(c, whole); return; }
			el('circle', { r: R, fill: 'url(#pz-g-dough)', stroke: c.edge, 'stroke-width': 3 }, doughG);
			el('circle', { r: 91, fill: 'none', stroke: c.ring, 'stroke-width': 2 }, doughG);
			el('circle', { r: 95.5, fill: 'none', stroke: c.hi, 'stroke-opacity': c.hia, 'stroke-width': 3 }, doughG);
			var k, ang, rad;
			for (k = 0; k < 26; k++) { ang = ((k * 137.5) % 360) * Math.PI / 180; rad = 93 + (k % 3) * 2; el('circle', { cx: (rad * Math.cos(ang)).toFixed(1), cy: (rad * Math.sin(ang)).toFixed(1), r: 1.6 + (k % 4) * .7, fill: k % 2 ? c.dk : c.lt, 'fill-opacity': k % 2 ? '.5' : '.7' }, doughG); }
			for (k = 0; k < 34; k++) { ang = ((k * 53.7) % 360) * Math.PI / 180; rad = 14 + ((k * 29) % 70); el('circle', { cx: (rad * Math.cos(ang)).toFixed(1), cy: (rad * Math.sin(ang)).toFixed(1), r: .9 + (k % 3) * .5, fill: c.fl, 'fill-opacity': c.fa }, doughG); }
			if (whole) {
				// bran flecks (dark) and oat flakes (light, tilted) over the whole dough, deterministic like everything else
				for (k = 0; k < 70; k++) { ang = ((k * 97.3 + 11) % 360) * Math.PI / 180; rad = 8 + ((k * 41) % 82); el('ellipse', { cx: (rad * Math.cos(ang)).toFixed(1), cy: (rad * Math.sin(ang)).toFixed(1), rx: 1.1 + (k % 4) * .5, ry: .7 + (k % 3) * .3, fill: '#5c3a1b', 'fill-opacity': '.5', transform: 'rotate(' + ((k * 47) % 180) + ' ' + (rad * Math.cos(ang)).toFixed(1) + ' ' + (rad * Math.sin(ang)).toFixed(1) + ')' }, doughG); }
				for (k = 0; k < 22; k++) { ang = ((k * 163.7 + 40) % 360) * Math.PI / 180; rad = 10 + ((k * 37) % 78); el('ellipse', { cx: (rad * Math.cos(ang)).toFixed(1), cy: (rad * Math.sin(ang)).toFixed(1), rx: 3, ry: 1.7, fill: '#f1dfb8', 'fill-opacity': '.8', stroke: '#b99455', 'stroke-width': .5, transform: 'rotate(' + ((k * 71) % 180) + ' ' + (rad * Math.cos(ang)).toFixed(1) + ' ' + (rad * Math.sin(ang)).toFixed(1) + ')' }, doughG); }
			}
		}
		drawDough();
		var layerBase = el('g', {}, svg), layerSauce = el('g', {}, svg), layerMelt = el('g', {}, svg), layerPieces = el('g', {}, svg);
		var nodes = { sauce: {}, melt: {}, piece: {} };

		// the cheese that is already on every pizza (under the sauce swirls and the toppings); a vegan pizza (title, or a dough
		// called vegan) gets paler Pizzaschmelz. "Doppelt Käse" and the like add a second layer on top (layerMelt)
		function isVegan() { var v = cur(); return /vegan/i.test(p.title) || (!!v && /vegan/i.test(v.title)); }
		function baseName() { var ch = isVegan() ? 'Pizzaschmelz (vegan)' : 'Käse'; return oval ? 'Tomatensoße, ' + ch : ch; }
		function drawBase() {
			while (layerBase.firstChild) { layerBase.removeChild(layerBase.firstChild); }
			var vg = isVegan(), k, ang, rad;
			if (oval) {
				// Flammkuchenart: tomato sauce spread over the thin dough, the cheese melted over it
				el('path', { d: wobbleE(DX - 7, DY - 6, 1.4, 8, .5), fill: '#c8452f', 'fill-opacity': '.88', stroke: '#8f2a18', 'stroke-opacity': '.45', 'stroke-width': 1.4 }, layerBase);
				for (k = 0; k < 22; k++) { ang = ((k * 131.3 + 17) % 360) * Math.PI / 180; var fq = .15 + ((k * 31) % 72) / 100; el('ellipse', { cx: (DX * fq * Math.cos(ang)).toFixed(1), cy: (DY * fq * Math.sin(ang)).toFixed(1), rx: 7 + (k % 3) * 2, ry: 1.6, fill: '#e56a50', 'fill-opacity': '.4', transform: 'rotate(' + ((k * 53) % 180) + ' ' + (DX * fq * Math.cos(ang)).toFixed(1) + ' ' + (DY * fq * Math.sin(ang)).toFixed(1) + ')' }, layerBase); }
				el('path', { d: wobbleE(DX - 16, DY - 13, 2.4, 9, .9), fill: vg ? '#f3e19a' : '#f2cf68', 'fill-opacity': vg ? '.6' : '.66', stroke: vg ? '#d9c070' : '#e0b13c', 'stroke-opacity': '.5', 'stroke-width': 1.5 }, layerBase);
				for (k = 0; k < (vg ? 10 : 18); k++) { ang = ((k * 151.3 + 23) % 360) * Math.PI / 180; var fc = .1 + ((k * 37) % 72) / 100; el('ellipse', { cx: (DX * fc * Math.cos(ang)).toFixed(1), cy: (DY * fc * Math.sin(ang)).toFixed(1), rx: 3 + (k % 3), ry: 2 + (k % 2), fill: vg ? '#e6cf86' : '#d9a336', 'fill-opacity': vg ? '.4' : '.5' }, layerBase); }
				return;
			}
			el('path', { d: wobble(83, 3.4, 9, .7), fill: vg ? '#f3e19a' : '#f2cf68', 'fill-opacity': vg ? '.72' : '.8', stroke: vg ? '#d9c070' : '#e0b13c', 'stroke-opacity': '.55', 'stroke-width': 1.6 }, layerBase);
			for (k = 0; k < (vg ? 9 : 16); k++) { ang = ((k * 151.3 + 23) % 360) * Math.PI / 180; rad = 10 + ((k * 37) % 66); el('ellipse', { cx: (rad * Math.cos(ang)).toFixed(1), cy: (rad * Math.sin(ang)).toFixed(1), rx: 3 + (k % 3), ry: 2 + (k % 2), fill: vg ? '#e6cf86' : '#d9a336', 'fill-opacity': vg ? '.4' : '.5' }, layerBase); }
		}
		drawBase();

		/* orbits and rings: stable, a removed ingredient frees its places, the others never move */
		var usedOrbit = {};
		function takeOrbit() { var n; for (n = 0; n < ORB.length; n++) { if (!usedOrbit[n]) { usedOrbit[n] = true; return n; } } return -1; }
		function freeOrbit(n) { delete usedOrbit[n]; }
		function takeRing() { var n, used = {}; Object.keys(ringOf).forEach(function (k) { used[ringOf[k]] = true; }); for (n = 0; n < RINGS.length; n++) { if (!used[n]) { return n; } } return RINGS.length - 1; }

		/* dips ("zum Dippen") do not go on the pizza: each chosen one stands beside the board as a little bowl of its sauce */
		function dipStyle(title) {
			var t = String(title).toLowerCase();
			if (/korean/.test(t)) { return { c: '#4a2a1b', f: '#f6eedc' }; }
			if (/sambal/.test(t)) { return { c: '#efb55c', f: '#c4402b' }; }
			if (/hollandaise/.test(t)) { return { c: '#f0d35c' }; }
			if (/kr(ä|ae)uter|quark/.test(t)) { return { c: '#eef0d8', f: '#6f9a3e' }; }
			if (/knoblauch/.test(t)) { return { c: '#f4ecd6' }; }
			if (/curry/.test(t)) { return { c: '#e2a626' }; }
			if (/chili/.test(t)) { return { c: '#c8321f', f: '#f1a08a' }; }
			if (/barbecue|bbq/.test(t)) { return { c: '#9a3d20' }; }
			if (/champignon|rahm/.test(t)) { return { c: '#d9c7a6', f: '#8c7447' }; }
			return { c: '#d4a762' };
		}
		function bowlSvg(st) {
			var fl = '', k, a, r;
			if (st.f) { for (k = 0; k < 6; k++) { a = (k * 137.5) * Math.PI / 180; r = 3 + (k % 3) * 3; fl += '<circle cx="' + (24 + r * Math.cos(a)).toFixed(1) + '" cy="' + (24 + r * Math.sin(a)).toFixed(1) + '" r="1.3" fill="' + st.f + '"/>'; } }
			return '<svg viewBox="0 0 48 48" focusable="false"><ellipse cx="26" cy="30" rx="20" ry="17" fill="#000" fill-opacity=".3"/><circle cx="24" cy="24" r="20" fill="#f3ead8" stroke="#a89572" stroke-width="2.5"/><circle cx="24" cy="24" r="15.5" fill="#d9ccb0" stroke="#a89572" stroke-width="1.2"/><circle cx="24" cy="24" r="12.5" fill="' + st.c + '"/>' + fl + '<path d="M15.5 21a10.5 10.5 0 0 1 8-6" stroke="#fff" stroke-opacity=".55" stroke-width="2.4" fill="none" stroke-linecap="round"/></svg>';
		}
		var dipNodes = {};
		// a sauce cup holds about 125 ml, roughly 6 cm across, i.e. a fifth of a 30 cm pizza (a bit less of the larger Flammkuchen): the bowls
		// are sized from the pizza as drawn, so they grow and shrink with it. On a phone the dips row takes height from the pizza itself, hence two passes.
		function sizeDips() {
			var st = $('.pz-stage'); if (!st) { return; }
			var sr = st.getBoundingClientRect(), sumH = $('.pz-sum').getBoundingClientRect().height;
			var wide = matchMedia('(min-width: 900px), (orientation: landscape) and (max-height: 620px)').matches;
			// the pizza as it would be drawn without dips: its dough and its board in px
			var avH = sr.height - sumH - 6, dough = oval ? Math.min(sr.width * .96, 760, avH * 344 / 232) * (2 * DX / 344) : Math.min(sr.width * .92, avH) * (2 * R / 248);
			var b = Math.max(46, Math.min(96, Math.round(dough * (oval ? .18 : .22))));
			var boardW = oval ? dough * (332 / 272) : dough * (236 / 200);
			// beside the board when the table has room (a round pizza leaves it even on a phone), else a column with a strip reserved for it on a wide stage, else a row below
			var fits = (sr.width - boardW) / 2 >= b + 14;
			st.style.setProperty('--dip', b + 'px');
			st.classList.toggle('dips-side', fits || wide);
			st.classList.toggle('dips-pad', wide && !fits);
		}
		function syncDips(firstPaint) {
			var box = $('.pz-dips'), want = {};
			Object.keys(sel.opts).map(Number).forEach(function (id) {
				var it = items[id].it;
				if (it.icon !== 'dip') { return; }
				var q = Math.min(sel.opts[id], 3);
				want[id] = q;
				var cur = dipNodes[id];
				if (cur && cur.length === q) { return; }
				(cur || []).forEach(function (n) { box.removeChild(n); });
				dipNodes[id] = [];
				for (var k = 0; k < q; k++) {
					var d = document.createElement('span'); d.className = 'pz-dip' + (firstPaint ? ' is-still' : ''); d.title = name(it); d.innerHTML = bowlSvg(dipStyle(it.title));
					box.appendChild(d); dipNodes[id].push(d);
				}
			});
			Object.keys(dipNodes).forEach(function (id) { if (!want[id]) { dipNodes[id].forEach(function (n) { box.removeChild(n); }); delete dipNodes[id]; } });
			$('.pz-stage').classList.toggle('has-dips', box.children.length > 0);
			sizeDips();
		}

		function syncPizza(addedId) {
			syncDips(!addedId && !Object.keys(dipNodes).length);
			var ids = Object.keys(sel.opts).map(Number).filter(function (id) { return isOnPizza(items[id].it); }), want = {}, delay = 0;
			ids.forEach(function (id) {
				var it = items[id].it, art = artOf(it);
				if (art.t === 'sauce') {
					if (ringOf[id] == null) { ringOf[id] = takeRing(); }
					want['s' + id] = true;
					if (!nodes.sauce[id]) {
						var ri = RINGS[ringOf[id]], dd = oval ? wobbleE(ri * DX / R, ri * DY / R, 2.2, 7, ringOf[id]) : wobble(ri, 2.2, 7, ringOf[id]);
						var shade = el('path', { d: dd, fill: 'none', stroke: '#6b3f12', 'stroke-opacity': '.32', 'stroke-width': 13, 'stroke-linejoin': 'round' }, layerSauce);
						var path = el('path', { d: dd, fill: 'none', stroke: art.c, 'stroke-width': 10.5, 'stroke-linejoin': 'round', 'stroke-opacity': '.96' }, layerSauce);
						var flecks = null;
						if (art.f) { flecks = el('path', { d: dd, fill: 'none', stroke: art.f, 'stroke-width': 3.2, 'stroke-dasharray': '1.1 7.5', 'stroke-linecap': 'round', 'stroke-opacity': '.9', 'class': 'pz-flecks' }, layerSauce); }
						var shine = el('path', { d: oval ? wobbleE((ri - 2.2) * DX / R, (ri - 2.2) * DY / R, 1.6, 7, ringOf[id] + .6) : wobble(ri - 2.2, 1.6, 7, ringOf[id] + .6), fill: 'none', stroke: '#fff', 'stroke-opacity': '.3', 'stroke-width': 2.4 }, layerSauce);
						var len = path.getTotalLength ? path.getTotalLength() : 400;
						path.style.strokeDasharray = len; path.style.strokeDashoffset = len; path.classList.add('pz-pour');
						void path.getBoundingClientRect(); path.style.strokeDashoffset = 0;
						nodes.sauce[id] = flecks ? [shade, path, flecks, shine] : [shade, path, shine];
					}
				} else if (art.t === 'melt') {
					want['m' + id] = true;
					if (!nodes.melt[id]) {
						var k = Object.keys(nodes.melt).length;
						var mr = 76 - k * 6;
						nodes.melt[id] = [el('path', { d: oval ? wobbleE(mr * DX / R * .93, mr * DY / R * .93, 3.2, 8, k * 1.3 + 1.9) : wobble(mr, 3.2, 8, k * 1.3 + 1.9), fill: art.c, 'fill-opacity': '.55', stroke: '#e0b13c', 'stroke-opacity': '.5', 'stroke-width': 1.6, class: 'pz-melt' }, layerMelt)];
					}
				}
			});
			ids.forEach(function (id) {
				var it = items[id].it, art = artOf(it);
				if (art.t !== 'piece') { return; }
				// the oval is larger and a portion has four pieces instead of six, so it takes half as many orbits again
				var need = Math.ceil((sel.opts[id] || 0) * art.o * (oval ? 1.5 : 1)), have = alloc[id] || (alloc[id] = []);
				while (have.length < need) { var o = takeOrbit(); if (o < 0) { break; } have.push(o); }
				while (have.length > need) { freeOrbit(have.pop()); }
				have.forEach(function (oi) {
					var pts = ORB[oi].pts;
					for (var j = 0; j < pts.length; j++) {
						var key = id + '|' + oi + '|' + j; want[key] = true;
						if (nodes.piece[key]) { continue; }
						var ang = Math.atan2(pts[j][1], pts[j][0]) * 180 / Math.PI, sc = 26 * art.k / 64;
						var outer = el('g', { transform: 'translate(' + pts[j][0].toFixed(1) + ' ' + pts[j][1].toFixed(1) + ') rotate(' + (ang + seed(String(id))).toFixed(0) + ') scale(' + sc.toFixed(3) + ')' }, layerPieces);
						var inner = el('g', { 'class': 'pz-pc' }, outer);
						inner.style.animationDelay = (addedId === id ? (delay += 34) : 0) + 'ms';
						if (!addedId) { inner.style.animation = 'none'; }
						el('use', { href: '#pz-k-' + it.icon, x: -32, y: -32, width: 64, height: 64 }, inner);
						nodes.piece[key] = outer;
					}
				});
			});
			Object.keys(nodes.piece).forEach(function (k) { if (!want[k]) { layerPieces.removeChild(nodes.piece[k]); delete nodes.piece[k]; } });
			Object.keys(nodes.sauce).forEach(function (id) { if (!want['s' + id]) { nodes.sauce[id].forEach(function (n) { layerSauce.removeChild(n); }); delete nodes.sauce[id]; delete ringOf[id]; } });
			Object.keys(nodes.melt).forEach(function (id) { if (!want['m' + id]) { nodes.melt[id].forEach(function (n) { layerMelt.removeChild(n); }); delete nodes.melt[id]; } });
			Object.keys(alloc).forEach(function (id) { if (!sel.opts[id]) { alloc[id].forEach(freeOrbit); delete alloc[id]; } });
		}

		/* tray */
		function renderPanel() {
			var g = p.groups[tab], h = '';
			if (g.max > 1 || g.min > 0) { h += '<p class="pz-rule" data-rule></p>'; }
			h += '<ul class="pz-grid">';
			g.items.forEach(function (it) {
				var multi = it.max > 1;
				h += '<li><button type="button" class="pz-tile" data-id="' + it.id + '" aria-pressed="false"><span class="pz-ico-wrap"><svg class="pz-ico" viewBox="0 0 64 64" aria-hidden="true"><use href="#pz-k-' + iconKey(it) + '"/></svg><span class="pz-badge" data-badge hidden></span></span>' +
					'<span class="pz-name">' + esc(name(it)) + '</span><span class="pz-price" data-price="' + it.price + '"></span></button>' +
					(multi ? '<button type="button" class="pz-less" data-less="' + it.id + '" aria-label="Weniger ' + esc(name(it)) + '" hidden>&minus;</button>' : '') + '</li>';
			});
			$('#pz-panel').innerHTML = h + '</ul>';
			$('#pz-panel').setAttribute('aria-labelledby', 'pz-tab-' + tab);
			$$('.pz-tab').forEach(function (t, i) { t.setAttribute('aria-selected', i === tab ? 'true' : 'false'); t.tabIndex = i === tab ? 0 : -1; });
			refresh();
		}
		function say(t) { $('.pz-live').textContent = t; }
		function refresh() {
			var m = mult(), total = Object.keys(sel.opts).length;
			$$('.pz-chip').forEach(function (c) { c.setAttribute('aria-pressed', +c.dataset.v === sel.vid ? 'true' : 'false'); });
			$$('.pz-price').forEach(function (e) { var pr = +e.dataset.price; e.textContent = pr ? '+' + fmt(Math.round(pr * m)) : 'inklusive'; });
			p.groups.forEach(function (g, i) { var n = groupSum(g), c = $('[data-count="' + i + '"]'); c.hidden = n === 0; c.textContent = n; });
			var g = p.groups[tab], sum = groupSum(g), full = g.max > 0 && sum >= g.max && g.max !== 1;
			$$('.pz-tile').forEach(function (t) {
				var id = +t.dataset.id, q = sel.opts[id] || 0, it = items[id].it, li = t.parentNode, badge = t.querySelector('[data-badge]'), less = li.querySelector('.pz-less');
				t.setAttribute('aria-pressed', q > 0 ? 'true' : 'false');
				t.disabled = (q === 0 && full) || (it.max > 1 && q >= it.max);
				t.setAttribute('aria-label', name(it) + (it.max > 1 && q ? ', ' + q + ' mal' : '') + (it.price ? ', +' + fmt(Math.round(it.price * m)) : ''));
				badge.hidden = !(it.max > 1 && q > 0); badge.textContent = q + '×';
				if (less) { less.hidden = q === 0; }
			});
			var rule = $('[data-rule]');
			if (rule) { rule.textContent = (g.min > 0 ? 'Pflicht: ' + (g.min === g.max ? 'genau ' + g.min : 'mindestens ' + g.min) + '. ' : '') + (g.max > 0 ? sum + ' von ' + g.max + ' gewählt' : sum + ' gewählt'); rule.classList.toggle('is-missing', tried && sum < g.min); }
			var names = [];
			p.groups.forEach(function (gg) { gg.items.forEach(function (it) { var q = sel.opts[it.id]; if (q) { names.push((q > 1 ? q + '× ' : '') + name(it)); } }); });
			// every pizza comes with its first layer of cheese (Pizzaschmelz on a vegan one): it is not an option, so it is named here
			var inc = 'Inklusive: ' + baseName();
			$('.pz-sum').textContent = names.length ? inc + ' · Dazu: ' + names.join(', ') : inc + ' · Tippe unten eine Zutat an, sie landet auf dem Teig.';
			$('.pz-reset').hidden = total === 0;
			$$('.pz-tab').forEach(function (t, i) { t.classList.toggle('is-missing', tried && p.groups[i].min > 0 && groupSum(p.groups[i]) < p.groups[i].min); });
			$('#pz-qnum').textContent = sel.qty;
			var miss = missing().length, add = $('#pz-add');
			add.classList.toggle('is-blocked', miss > 0);
			$('#pz-add-label').textContent = miss ? 'Noch ' + miss + (miss === 1 ? ' Pflichtangabe' : ' Pflichtangaben') : (editing ? 'Änderung speichern' : 'In den Warenkorb');
			$('#pz-add-price').textContent = miss ? '' : fmt(unit() * sel.qty);
		}

		function change(id, d) {
			var e = items[id], q = sel.opts[id] || 0, g = e.g, it = e.it, nq = Math.max(0, Math.min(it.max, q + d));
			if (d > 0 && q === 0 && g.max === 1) { g.items.forEach(function (x) { delete sel.opts[x.id]; }); }
			else if (d > 0 && g.max > 0 && groupSum(g) >= g.max) { say('Höchstens ' + g.max + ' aus „' + gtitle(g) + '“.'); return; }
			if (nq === q && !(d > 0 && q === 0)) { return; }
			if (nq) { sel.opts[id] = nq; } else { delete sel.opts[id]; }
			syncPizza(d > 0 ? id : 0);
			if (d > 0) { var pz = $('.pz-pizza'); pz.classList.remove('is-bump'); void pz.getBoundingClientRect(); pz.classList.add('is-bump'); }
			refresh();
			var tot = Object.keys(sel.opts).length;
			say(name(it) + (nq ? (nq > 1 ? ', ' + nq + ' mal' : ' hinzugefügt') : ' entfernt') + '. ' + tot + (tot === 1 ? ' Zutat' : ' Zutaten') + ', ' + fmt(unit() * sel.qty) + '.');
		}

		dlg.onclick = function (ev) {
			var t = ev.target;
			if (t.closest('.pz-back')) { dlg.close(); return; }
			var tb = t.closest('.pz-tab'); if (tb) { tab = +tb.dataset.tab; renderPanel(); return; }
			var ch = t.closest('.pz-chip');
			if (ch) {
				var was = wholeGrain(), wasV = isVegan(); sel.vid = +ch.dataset.v;
				if (wholeGrain() !== was || isVegan() !== wasV) { drawDough(); drawBase(); var pz = $('.pz-pizza'); pz.classList.remove('is-bump'); void pz.getBoundingClientRect(); pz.classList.add('is-bump'); }
				refresh(); return;
			}
			var ls = t.closest('.pz-less'); if (ls) { change(+ls.dataset.less, -1); return; }
			var tile = t.closest('.pz-tile');
			if (tile) { var id = +tile.dataset.id; if (items[id].it.max === 1 && sel.opts[id]) { if (items[id].g.min > 0) { return; } change(id, -1); } else { change(id, 1); } return; }
			if (t.closest('.pz-reset')) { Object.keys(sel.opts).forEach(function (k) { delete sel.opts[k]; }); syncPizza(0); refresh(); say('Der Teig ist wieder leer.'); return; }
			if (t.closest('#pz-minus')) { sel.qty = Math.max(1, sel.qty - 1); refresh(); return; }
			if (t.closest('#pz-plus')) { sel.qty = Math.min(50, sel.qty + 1); refresh(); return; }
			if (t.closest('#pz-add')) {
				var mg = missing();
				if (mg.length) { tried = true; tab = p.groups.indexOf(mg[0]); renderPanel(); say('Bitte noch ' + mg.length + (mg.length === 1 ? ' Pflichtangabe' : ' Pflichtangaben') + ' wählen.'); return; }
				var v = cur(), parts = [];
				p.groups.forEach(function (gg) { gg.items.forEach(function (it) { var q = sel.opts[it.id]; if (q) { parts.push((q > 1 ? q + '× ' : '') + it.title); } }); });
				var line = { pid: p.id, title: p.title, vid: sel.vid, vtitle: v ? v.title : '', opts: JSON.parse(JSON.stringify(sel.opts)), optText: parts.join(', '), unit: unit(), qty: sel.qty, note: editing ? (edit.line.note || '') : '', ch: 1 };
				if (editing) { api.replace(edit.i, line); } else { api.add(line); }
				dlg.close();
			}
		};
		dlg.onkeydown = function (ev) {
			var tb = ev.target.closest && ev.target.closest('.pz-tab');
			if (tb && (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft')) {
				tab = (tab + (ev.key === 'ArrowRight' ? 1 : p.groups.length - 1)) % p.groups.length; renderPanel(); var n = $('#pz-tab-' + tab); if (n) { n.focus(); } ev.preventDefault();
			}
		};

		// start on the first group that has something for the pizza; an edited line brings its choices back onto the dough
		tab = Math.max(0, p.groups.findIndex(function (g) { return g.items.some(isOnPizza); }));
		renderPanel();
		syncPizza(0);
		if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
		sizeDips();
		if (window.ResizeObserver) { if (dlg._pzRO) { dlg._pzRO.disconnect(); } dlg._pzRO = new ResizeObserver(sizeDips); dlg._pzRO.observe($('.pz-stage')); }
		return true;
	}

	window.PizzaLab = { open: open };
})();
