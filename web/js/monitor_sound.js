/* Sound for the order monitors: several synthesised sounds (no files), volume, repeat until somebody reacts. The settings sit in a small
   panel behind the "Ton" button and are kept per monitor in the browser. Browsers only allow sound after a tap on the page, so the
   context is started by the first tap and the button says so while it is still locked. */
window.MonitorSound = (function () {
	'use strict';
	var SOUNDS = {
		bell: { label: 'Glocke', play: function (c, out, t) { [[880, 1], [1320, 0.6], [1760, 0.4], [2640, 0.25]].forEach(function (p) { tone(c, out, 'sine', p[0], t, 1.4, 0.55 * p[1], 0, true); }); [880, 1320, 1760].forEach(function (f) { tone(c, out, 'sine', f, t + 0.9, 1.2, 0.35, 0, true); }); } },
		ring: { label: 'Klingel', play: function (c, out, t) { for (var i = 0; i < 20; i++) { tone(c, out, 'square', i % 2 ? 1250 : 1750, t + i * 0.09 + (i >= 10 ? 0.12 : 0), 0.08, 0.55); } } },
		doorbell: { label: 'Ding-Dong', play: function (c, out, t) { tone(c, out, 'triangle', 784, t, 0.9, 0.9, 0, true); tone(c, out, 'sine', 1568, t, 0.6, 0.35, 0, true); tone(c, out, 'triangle', 622, t + 0.55, 1.3, 0.9, 0, true); tone(c, out, 'sine', 1244, t + 0.55, 0.9, 0.35, 0, true); } },
		beep: { label: 'Signalton', play: function (c, out, t) { for (var i = 0; i < 4; i++) { tone(c, out, 'square', 1000, t + i * 0.22, 0.14, 0.6); } } },
		siren: { label: 'Sirene', play: function (c, out, t) { tone(c, out, 'sawtooth', 700, t, 0.7, 0.5, 1300); tone(c, out, 'sawtooth', 1300, t + 0.7, 0.7, 0.5, 700); tone(c, out, 'sawtooth', 700, t + 1.4, 0.7, 0.5, 1300); } },
		alarm: { label: 'Alarm', play: function (c, out, t) { for (var i = 0; i < 5; i++) { tone(c, out, 'square', 990, t + i * 0.36, 0.16, 0.6); tone(c, out, 'square', 740, t + i * 0.36 + 0.17, 0.16, 0.6); } } }
	};
	// one note with a quick attack; "decay" lets it ring out, otherwise it is cut cleanly
	function tone(c, out, type, freq, t, dur, gain, slideTo, decay) {
		var o = c.createOscillator(), g = c.createGain();
		o.type = type; o.frequency.setValueAtTime(freq, t);
		if (slideTo) { o.frequency.linearRampToValueAtTime(slideTo, t + dur); }
		g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(Math.max(0.001, gain), t + 0.012);
		if (decay) { g.gain.exponentialRampToValueAtTime(0.0001, t + dur); } else { g.gain.setValueAtTime(Math.max(0.001, gain), t + dur - 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + dur); }
		o.connect(g); g.connect(out); o.start(t); o.stop(t + dur + 0.05);
	}

	function create(opts) {
		var key = 'monitorSound_' + opts.key, ctx = null, master = null, timer = null, count = 0;
		var S = { on: false, sound: 'bell', volume: 90, repeat: '3' };
		try { var saved = JSON.parse(localStorage.getItem(key) || 'null'); if (saved) { for (var k in S) { if (saved[k] !== undefined) { S[k] = saved[k]; } } } } catch (e) {}
		function save() { try { localStorage.setItem(key, JSON.stringify(S)); } catch (e) {} }

		function audio() {
			if (!ctx) {
				var AC = window.AudioContext || window.webkitAudioContext; if (!AC) { return null; }
				ctx = new AC(); var comp = ctx.createDynamicsCompressor(); comp.threshold.value = -14; comp.ratio.value = 10; comp.attack.value = 0.003; comp.release.value = 0.2;
				master = ctx.createGain(); master.connect(comp); comp.connect(ctx.destination);
			}
			if (ctx.state === 'suspended') { ctx.resume(); }
			return ctx;
		}
		function play() {
			var c = audio(); if (!c || c.state !== 'running') { return; }
			master.gain.value = Math.max(0, Math.min(1, S.volume / 100)) * 1.6;
			(SOUNDS[S.sound] || SOUNDS.bell).play(c, master, c.currentTime + 0.03);
		}
		function stop() { if (timer) { clearInterval(timer); timer = null; } count = 0; }
		function repeatLimit() { return S.repeat === 'until' ? 1e9 : parseInt(S.repeat, 10) || 1; }

		// a new order arrived: sound now, then again every 9 seconds while somebody still has to react
		function notify() {
			if (!S.on) { return; }
			play(); stop(); count = 1;
			if (repeatLimit() <= 1) { return; }
			timer = setInterval(function () {
				if (opts.pending() <= 0 || count >= repeatLimit()) { stop(); return; }
				play(); count++;
			}, 9000);
		}
		function ack() { if (opts.pending() <= 0) { stop(); } }

		// ---- panel
		var wrap = document.createElement('div'); wrap.className = 'ms-wrap';
		var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'k-btn ms-btn'; btn.setAttribute('aria-expanded', 'false');
		var panel = document.createElement('div'); panel.className = 'ms-panel'; panel.hidden = true;
		panel.innerHTML = '<label class="ms-row ms-check"><input type="checkbox" data-f="on"/> <span>Ton bei neuer Bestellung</span></label>' +
			'<label class="ms-row"><span>Klang</span><select data-f="sound">' + Object.keys(SOUNDS).map(function (k) { return '<option value="' + k + '">' + SOUNDS[k].label + '</option>'; }).join('') + '</select></label>' +
			'<label class="ms-row"><span>Lautstärke <b data-v="volume"></b></span><input type="range" data-f="volume" min="10" max="100" step="5"/></label>' +
			'<label class="ms-row"><span>Wiederholung</span><select data-f="repeat"><option value="1">einmal</option><option value="3">3 Mal</option><option value="5">5 Mal</option><option value="until">bis jemand reagiert</option></select></label>' +
			'<button type="button" class="k-btn ms-test">Testen</button>';
		wrap.appendChild(btn); wrap.appendChild(panel); opts.mount.appendChild(wrap);

		function label() { btn.textContent = !S.on ? 'Ton aus' : ((ctx && ctx.state === 'running') ? 'Ton an' : 'Ton antippen'); btn.setAttribute('aria-pressed', S.on ? 'true' : 'false'); }
		function fill() {
			panel.querySelector('[data-f=on]').checked = S.on; panel.querySelector('[data-f=sound]').value = S.sound; panel.querySelector('[data-f=volume]').value = S.volume; panel.querySelector('[data-f=repeat]').value = S.repeat;
			panel.querySelector('[data-v=volume]').textContent = S.volume + ' %'; label();
		}
		btn.addEventListener('click', function () { audio(); var open = panel.hidden; panel.hidden = !open; btn.setAttribute('aria-expanded', open ? 'true' : 'false'); label(); });
		panel.addEventListener('change', function (ev) {
			var f = ev.target.dataset.f; if (!f) { return; }
			S[f] = ev.target.type === 'checkbox' ? ev.target.checked : (f === 'volume' ? +ev.target.value : ev.target.value); save(); fill(); audio();
			if (f === 'sound' || f === 'volume') { play(); }
		});
		panel.addEventListener('input', function (ev) { if (ev.target.dataset.f === 'volume') { S.volume = +ev.target.value; panel.querySelector('[data-v=volume]').textContent = S.volume + ' %'; save(); } });
		panel.querySelector('.ms-test').addEventListener('click', function () { audio(); play(); });
		document.addEventListener('click', function (ev) { if (!wrap.contains(ev.target)) { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); } if (S.on && (!ctx || ctx.state !== 'running')) { audio(); setTimeout(label, 200); } }, true);
		fill();
		return { notify: notify, ack: ack, stop: stop };
	}
	return { create: create };
})();
