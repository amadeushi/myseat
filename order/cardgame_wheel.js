/* Minispiel "Rad" der Bestellseite (geladen von cardgame.js, wenn dieses Spiel an der Reihe ist): ein Rad mit acht Feldern, vier zeigen "10 %", vier ein Motiv. Ein Tipp auf die Mitte dreht es, dreimal:
   die ersten beiden Male bleibt es auf einem Motiv stehen, beim dritten Mal auf einem "10 %"-Feld. Erst beim dritten Mal fragt das Spiel den Server (api.play, der macht den Gutschein), vorher wird nichts
   verbraucht. Die Felder und die Endlagen sind nur Darstellung. Der Text sagt nirgends etwas über Zufall oder Chancen. Mit "Bewegung reduzieren" dreht es sich nicht, es steht sofort. */
(function () {
	'use strict';
	var N = 8, SEG = 360 / N;
	// the pictures of the other fields (flat and gold, like the motifs of the cards); the four of this wheel are chosen anew for every game
	var ICONS = {
		bolt: ['0 0 24 24', '<path d="M13.5 2 5 13.5h5.2L9.5 22 19 9.8h-5.4z" fill="currentColor"/>'],
		pizza: ['2 3.5 20 19.5', '<path d="M12 22 3 6.5a19 19 0 0 1 18 0z" fill="currentColor"/><path d="M3.8 7.6a17.2 17.2 0 0 1 16.4 0" stroke="#1a1408" stroke-width="1.3" fill="none" opacity=".45"/><circle cx="10" cy="11" r="1.7" fill="#1a1408"/><circle cx="14.3" cy="12.5" r="1.5" fill="#1a1408"/><circle cx="11.7" cy="16.3" r="1.3" fill="#1a1408"/>'],
		star: ['0 0 24 24', '<path d="m12 2.5 2.9 6.2 6.8.8-5 4.7 1.3 6.7L12 17.6 6 20.9l1.3-6.7-5-4.7 6.8-.8z" fill="currentColor"/>'],
		flame: ['0 0 24 24', '<path d="M12.5 2s5.5 4.8 5.5 10.8a6 6 0 0 1-12 0c0-2.3 1.1-3.9 2.3-5 .1 2 .9 3.1 2 3.4C10 8 10.6 4.7 12.5 2z" fill="currentColor"/><path d="M12 21.5a3.2 3.2 0 0 1-3.2-3.2c0-1.8 1.3-2.8 2.2-4 .5 1 1.2 1.3 1.8 1.3 1.3 1.1 2.4 1.9 2.4 2.7a3.2 3.2 0 0 1-3.2 3.2z" fill="#1a1408" opacity=".45"/>'],
		crown: ['0 0 24 24', '<path d="M3 8.5 7.5 12 12 5l4.5 7L21 8.5 19 19H5z" fill="currentColor"/><path d="M5.5 16.5h13" stroke="#1a1408" stroke-width="1.2" opacity=".45"/>'],
		lemon: ['0 0 24 24', '<circle cx="12" cy="12" r="10" fill="#e9d46b"/><circle cx="12" cy="12" r="7.6" fill="#f6ea96"/><path d="M12 4.4v15.2M4.4 12h15.2M6.6 6.6l10.8 10.8M17.4 6.6 6.6 17.4" stroke="#e9d46b" stroke-width="1.1"/>']
	};
	function rnd(n) { try { var a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; } catch (e) { return Math.floor(Math.random() * n); } }
	function pt(r, deg) { var a = (deg - 90) * Math.PI / 180; return [r * Math.cos(a), r * Math.sin(a)]; }
	function slice(i) {
		var p0 = pt(100, i * SEG - SEG / 2), p1 = pt(100, i * SEG + SEG / 2);
		return 'M0 0L' + p0[0].toFixed(2) + ' ' + p0[1].toFixed(2) + 'A100 100 0 0 1 ' + p1[0].toFixed(2) + ' ' + p1[1].toFixed(2) + 'Z';
	}
	function disc(percent, icons) {
		var s = '<svg class="cg-wheel-disc" viewBox="-112 -112 224 224" aria-hidden="true" focusable="false"><circle r="110" fill="#14110e" stroke="#c9a259" stroke-width="2"/>', i, k = 0;
		for (i = 0; i < 16; i++) { var p = pt(105.5, i * 22.5); s += '<circle cx="' + p[0].toFixed(1) + '" cy="' + p[1].toFixed(1) + '" r="2.3" fill="' + (i % 2 ? '#8d7440' : '#e2c07f') + '"/>'; }
		for (i = 0; i < N; i++) {
			var voucher = i % 2 === 0, deg = i * SEG;
			s += '<g class="cg-seg" data-i="' + i + '"><path d="' + slice(i) + '" fill="' + (voucher ? '#d4ab5f' : '#242220') + '" stroke="#0c0b0a" stroke-width="1.5"/>';
			if (voucher) {
				s += '<text class="cg-seg-t" transform="rotate(' + deg + ') translate(0 -64)" text-anchor="middle" dominant-baseline="central" font-size="22" font-weight="700" fill="#1a1408">' + percent + ' %</text>';
			} else {
				var ic = ICONS[icons[k++]];
				s += '<g transform="rotate(' + deg + ') translate(0 -66)"><svg x="-17" y="-17" width="34" height="34" viewBox="' + ic[0] + '" overflow="visible">' + ic[1] + '</svg></g>';
			}
			s += '</g>';
		}
		return s + '<circle r="9" fill="#14110e"/></svg>';
	}
	(window.AmadeusGames = window.AmadeusGames || {}).wheel = {
		title: 'Dreh das Rad', sub: 'Tippe in die Mitte. Du hast bis zu vier Drehungen.', count: '0 von 4',
		mount: function (stage, api) {
			var names = Object.keys(ICONS), icons = [], busy = false, runs = 0, cur = 0, lastSeg = -1;
			while (icons.length < 4) { icons.push(names.splice(rnd(names.length), 1)[0]); }
			stage.innerHTML = '<div class="cg-wheel"><svg class="cg-wheel-pointer" viewBox="0 0 26 32" aria-hidden="true" focusable="false"><path d="M13 31 2 9a12 12 0 1 1 22 0z" fill="#e2c07f" stroke="#1a1408" stroke-width="2.5" stroke-linejoin="round"/><circle cx="13" cy="10" r="3.2" fill="#1a1408"/></svg>' +
				'<div class="cg-wheel-rot">' + disc(api.esc(api.percent), icons) + '</div><button type="button" class="cg-hub" aria-label="Rad drehen">Drehen</button></div>' +
				'<div class="cg-tries" aria-hidden="true"><i></i><i></i><i></i><i></i></div>';
			var wheel = stage.querySelector('.cg-wheel'), rot = stage.querySelector('.cg-wheel-rot'), hub = stage.querySelector('.cg-hub');
			// four marks below the wheel, like the four cards: every turn uses one; the fourth is never used (it dims when the voucher is there)
			function mark(n, off) { var t = stage.querySelectorAll('.cg-tries i'), i; for (i = 0; i < t.length && i < n; i++) { t[i].classList.add('is-used'); } if (off && t[3]) { t[3].classList.add('is-off'); } }
			hub.addEventListener('click', go);
			// turns the wheel on from where it stands, so that field s ends under the pointer (a little off its middle); done() when it stands
			function turnTo(s, done, ms) {
				var jitter = (rnd(2001) / 1000 - 1) * 12, turns = 3 + rnd(3), over = false;
				var to = cur + 360 * turns + (((jitter - s * SEG - cur) % 360) + 360) % 360;
				cur = to;
				function end() { if (over) { return; } over = true; done(); }
				if (api.reduced) { rot.style.transition = 'none'; rot.style.transform = 'rotate(' + to + 'deg)'; end(); return; }
				rot.addEventListener('transitionend', function once(ev) { if (ev.target === rot && ev.propertyName === 'transform') { rot.removeEventListener('transitionend', once); end(); } });
				setTimeout(end, ms + 900);   // if the browser sends no transitionend
				rot.style.transition = 'transform ' + ms + 'ms cubic-bezier(0.12, 0.7, 0.12, 1)';
				void rot.offsetWidth;
				rot.style.transform = 'rotate(' + to + 'deg)';
			}
			function go() {
				if (busy) { return; }
				busy = true; hub.disabled = true;
				if (runs < 2) {
					// the first two turns stop on a picture, never twice on the same one; the server is not asked yet
					var s; do { s = 2 * rnd(4) + 1; } while (s === lastSeg); lastSeg = s;
					turnTo(s, function () {
						runs++; api.say(runs + ' von 4'); mark(runs); hub.textContent = 'Nochmal';
						var f = stage.querySelector('.cg-seg[data-i="' + s + '"]');
						if (f) { f.classList.add('is-land'); setTimeout(function () { f.classList.remove('is-land'); }, 900); }
						wheel.classList.add('is-stop'); setTimeout(function () { wheel.classList.remove('is-stop'); }, 450);
						busy = false; hub.disabled = false;
					}, 2800);
					return;
				}
				// the third turn: the server makes the voucher, then the wheel stops on one of the four "10 %" fields
				api.say('Einen Moment ...');
				api.play().then(function (r) {
					api.say('');
					var v = 2 * rnd(4);
					turnTo(v, function () {
						wheel.classList.add('is-win');
						var hit = stage.querySelector('.cg-seg[data-i="' + v + '"]'); if (hit) { hit.classList.add('is-hit'); }
						hub.textContent = ''; mark(3, true);
						api.win(r, { title: 'Treffer!', delay: 1000 });
					}, 4600);
				}, function (e) { api.error(e.message); busy = false; hub.disabled = false; });
			}
		}
	};
})();
