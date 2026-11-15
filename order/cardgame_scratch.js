/* Minispiel "Rubbellos" der Bestellseite (geladen von cardgame.js, wenn dieses Spiel an der Reihe ist): vier Felder mit silberner Folie, wie die vier Karten. Der Gast rubbelt nacheinander drei davon frei
   (mit dem Finger oder der Maus): unter den ersten beiden steht ein Motiv, unter dem dritten "10 % Gutschein"; das vierte Feld bleibt immer zugedeckt. Ist gut die Hälfte eines Feldes frei (oder tippt er auf
   "Alles aufdecken"), geht die Folie auf. Erst beim dritten Feld fragt das Spiel den Server (api.play, der macht den Gutschein); wer vorher aufhört, hat nichts verbraucht. Ein angefangenes Feld wird erst
   fertig gerubbelt, dann kommt das nächste. "Alles aufdecken" ist auch der Weg für alle, die nicht rubbeln können (Tastatur, Sprachsteuerung): es deckt das nächste Feld auf.
   Der Text sagt nirgends etwas über Zufall oder Chancen. Mit "Bewegung reduzieren" geht die Folie ohne Übergang weg. */
(function () {
	'use strict';
	var CELL = 8, BRUSH = 14, NEED = 0.55;
	// the pictures under the first two fields (flat and gold, like the motifs of the cards); two different ones for every game
	var MOTIFS = [
		{ w: 'Blitz', vb: '0 0 24 24', svg: '<path d="M13.5 2 5 13.5h5.2L9.5 22 19 9.8h-5.4z" fill="currentColor"/>' },
		{ w: 'Pizza', vb: '2 3.5 20 19.5', svg: '<path d="M12 22 3 6.5a19 19 0 0 1 18 0z" fill="currentColor"/><path d="M3.8 7.6a17.2 17.2 0 0 1 16.4 0" stroke="#1a1408" stroke-width="1.3" fill="none" opacity=".45"/><circle cx="10" cy="11" r="1.7" fill="#1a1408"/><circle cx="14.3" cy="12.5" r="1.5" fill="#1a1408"/><circle cx="11.7" cy="16.3" r="1.3" fill="#1a1408"/>' },
		{ w: 'Stern', vb: '0 0 24 24', svg: '<path d="m12 2.5 2.9 6.2 6.8.8-5 4.7 1.3 6.7L12 17.6 6 20.9l1.3-6.7-5-4.7 6.8-.8z" fill="currentColor"/>' },
		{ w: 'Flamme', vb: '0 0 24 24', svg: '<path d="M12.5 2s5.5 4.8 5.5 10.8a6 6 0 0 1-12 0c0-2.3 1.1-3.9 2.3-5 .1 2 .9 3.1 2 3.4C10 8 10.6 4.7 12.5 2z" fill="currentColor"/><path d="M12 21.5a3.2 3.2 0 0 1-3.2-3.2c0-1.8 1.3-2.8 2.2-4 .5 1 1.2 1.3 1.8 1.3 1.3 1.1 2.4 1.9 2.4 2.7a3.2 3.2 0 0 1-3.2 3.2z" fill="#1a1408" opacity=".45"/>' },
		{ w: 'Krone', vb: '0 0 24 24', svg: '<path d="M3 8.5 7.5 12 12 5l4.5 7L21 8.5 19 19H5z" fill="currentColor"/><path d="M5.5 16.5h13" stroke="#1a1408" stroke-width="1.2" opacity=".45"/>' },
		{ w: 'Zitrone', vb: '0 0 24 24', svg: '<circle cx="12" cy="12" r="10" fill="#e9d46b"/><circle cx="12" cy="12" r="7.6" fill="#f6ea96"/><path d="M12 4.4v15.2M4.4 12h15.2M6.6 6.6l10.8 10.8M17.4 6.6 6.6 17.4" stroke="#e9d46b" stroke-width="1.1"/>' }
	];
	function rnd(n) { try { var a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] % n; } catch (e) { return Math.floor(Math.random() * n); } }
	(window.AmadeusGames = window.AmadeusGames || {}).scratch = {
		title: 'Rubbel die Felder frei', sub: 'Rubbel nacheinander drei Felder frei.', count: '0 von 3',
		mount: function (stage, api) {
			var i1 = rnd(MOTIFS.length), i2 = rnd(MOTIFS.length - 1); if (i2 >= i1) { i2++; }
			var motifs = [MOTIFS[i1], MOTIFS[i2]], opened = 0, active = null, locked = false, flashTimer = 0, fields = [], i;
			var html = '<div class="cg-sgrid">';
			for (i = 0; i < 4; i++) { html += '<div class="cg-scratch" data-i="' + i + '"><div class="cg-scratch-under"></div><canvas class="cg-scratch-foil" aria-hidden="true"></canvas></div>'; }
			stage.innerHTML = html + '</div><div class="cg-act cg-scratch-act"><button type="button" class="cg-link" data-scratch="all">Alles aufdecken</button></div>';
			var all = stage.querySelector('[data-scratch="all"]'), dpr = Math.min(2, window.devicePixelRatio || 1);
			[].forEach.call(stage.querySelectorAll('.cg-scratch'), function (box) {
				var f = { box: box, under: box.querySelector('.cg-scratch-under'), cv: box.querySelector('canvas'), W: 0, H: 0, cols: 0, rows: 0, grid: null, marked: 0, state: 'idle', drawing: false, last: null };
				f.ctx = f.cv.getContext('2d'); fields.push(f); wire(f);
			});
			function flash(t) { api.say(t); clearTimeout(flashTimer); flashTimer = setTimeout(function () { if (opened < 3) { api.say(opened + ' von 3'); } }, 1700); }
			function content(f) {   // under the foil: a picture for the first two fields, the voucher for the third
				if (opened < 2) { f.under.innerHTML = '<svg class="cg-scratch-ic" viewBox="' + motifs[opened].vb + '" width="38" height="38" aria-hidden="true" focusable="false">' + motifs[opened].svg + '</svg><span class="cg-word">' + motifs[opened].w + '</span>'; }
				else { f.under.innerHTML = '<span class="cg-pct">' + api.esc(api.percent) + ' %</span><span class="cg-word">Gutschein</span>'; }
			}
			function foil(f) {
				var g = f.ctx.createLinearGradient(0, 0, f.W, f.H);
				g.addColorStop(0, '#9c9686'); g.addColorStop(0.35, '#d8d3c4'); g.addColorStop(0.62, '#aaa493'); g.addColorStop(1, '#c9c3b2');
				f.ctx.globalCompositeOperation = 'source-over'; f.ctx.fillStyle = g; f.ctx.fillRect(0, 0, f.W, f.H);
				f.ctx.strokeStyle = 'rgba(255,255,255,.22)'; f.ctx.lineWidth = 1;
				for (var x = -f.H; x < f.W; x += 8) { f.ctx.beginPath(); f.ctx.moveTo(x, f.H); f.ctx.lineTo(x + f.H, 0); f.ctx.stroke(); }
				f.ctx.strokeStyle = 'rgba(26,20,8,.35)'; f.ctx.lineWidth = 2; f.ctx.strokeRect(5, 5, f.W - 10, f.H - 10);
				f.ctx.fillStyle = 'rgba(26,20,8,.62)'; f.ctx.font = '700 ' + Math.round(f.H * 0.2) + 'px system-ui, -apple-system, sans-serif'; f.ctx.textAlign = 'center'; f.ctx.textBaseline = 'middle';
				f.ctx.fillText('Rubbeln', f.W / 2, f.H / 2);
			}
			function size(f) {
				var r = f.box.getBoundingClientRect(); f.W = Math.max(1, Math.round(r.width)); f.H = Math.max(1, Math.round(r.height));
				f.cv.width = f.W * dpr; f.cv.height = f.H * dpr; f.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
				f.cols = Math.ceil(f.W / CELL); f.rows = Math.ceil(f.H / CELL); f.grid = new Uint8Array(f.cols * f.rows); f.marked = 0;
				foil(f);
			}
			function activate(f) { f.state = 'active'; active = f; content(f); }
			function mark(f, p) {   // the cells under the brush count as scratched
				var x0 = Math.max(0, Math.floor((p[0] - BRUSH) / CELL)), x1 = Math.min(f.cols - 1, Math.floor((p[0] + BRUSH) / CELL)), y0 = Math.max(0, Math.floor((p[1] - BRUSH) / CELL)), y1 = Math.min(f.rows - 1, Math.floor((p[1] + BRUSH) / CELL));
				for (var cy = y0; cy <= y1; cy++) { for (var cx = x0; cx <= x1; cx++) {
					var dx = (cx + 0.5) * CELL - p[0], dy = (cy + 0.5) * CELL - p[1];
					if (dx * dx + dy * dy <= BRUSH * BRUSH && !f.grid[cy * f.cols + cx]) { f.grid[cy * f.cols + cx] = 1; f.marked++; }
				} }
			}
			function stroke(f, a, b) {
				f.ctx.globalCompositeOperation = 'destination-out'; f.ctx.lineWidth = BRUSH * 2; f.ctx.lineCap = 'round'; f.ctx.lineJoin = 'round';
				f.ctx.beginPath(); f.ctx.moveTo(a[0], a[1]); f.ctx.lineTo(b[0], b[1]); f.ctx.stroke();
				var n = Math.max(1, Math.ceil(Math.hypot(b[0] - a[0], b[1] - a[1]) / 5));
				for (var k = 0; k < n + 1; k++) { mark(f, [a[0] + (b[0] - a[0]) * k / n, a[1] + (b[1] - a[1]) * k / n]); }
				if (f.marked / (f.cols * f.rows) >= NEED) { reveal(f); }
			}
			function pos(f, ev) { var r = f.cv.getBoundingClientRect(); return [ev.clientX - r.left, ev.clientY - r.top]; }
			function wire(f) {
				function end() { f.drawing = false; }
				f.cv.addEventListener('pointerdown', function (ev) {
					if (locked || f.state === 'open') { return; }
					if (active && active !== f) { flash('Rubbel erst das angefangene Feld frei.'); ev.preventDefault(); return; }
					if (!active) { activate(f); }
					f.drawing = true; try { f.cv.setPointerCapture(ev.pointerId); } catch (e) {}
					f.last = pos(f, ev); stroke(f, f.last, f.last); ev.preventDefault();
				});
				f.cv.addEventListener('pointermove', function (ev) { if (!f.drawing || locked) { return; } var p = pos(f, ev); stroke(f, f.last, p); f.last = p; });
				f.cv.addEventListener('pointerup', end); f.cv.addEventListener('pointercancel', end); f.cv.addEventListener('lostpointercapture', end);
			}
			function reveal(f) {
				if (locked || f.state === 'open') { return; }
				if (!active) { activate(f); }   // "Alles aufdecken" without scratching: this field is the next one
				locked = true; f.drawing = false; all.disabled = true;
				if (opened < 2) {   // a picture: the server is not asked yet
					f.state = 'open'; f.box.classList.add('is-open'); opened++; active = null; api.say(opened + ' von 3');
					setTimeout(function () { locked = false; all.disabled = false; }, 450);
					return;
				}
				api.say('Einen Moment ...');   // the third field: the server makes the voucher, then the foil goes; the fourth field stays covered
				api.play().then(function (r) {
					api.say(''); all.hidden = true; f.state = 'open'; f.box.classList.add('is-open'); opened = 3; active = null;
					fields.forEach(function (x) { if (x.state !== 'open') { x.box.classList.add('is-off'); } });
					api.win(r, { title: 'Aufgedeckt!', delay: 900 });
				}, function (e) { api.error(e.message); locked = false; all.disabled = false; });
			}
			all.addEventListener('click', function () {
				var f = active || fields.filter(function (x) { return x.state === 'idle'; })[0];
				if (f) { reveal(f); }
			});
			requestAnimationFrame(function () { fields.forEach(size); });
		}
	};
})();
