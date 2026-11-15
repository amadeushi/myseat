/* Minispiel der Bestellseite: nach einer Weile auf der Seite (nur im Arbeitsspeicher gezählt, nur bei sichtbarem Tab), ohne zu bestellen, wird einem Gast ohne Konto ein kleines Spiel angeboten (Karten aufdecken, ein Rad drehen oder ein Feld freirubbeln).
   Am Ende steht ein persönlicher Gutschein (web/classes/shop_cardgame.class.php macht ihn; der Server entscheidet, ob und welches Spiel gerade angeboten wird, card_state).
   Diese Datei ist die Hülle: Zeitpunkt, Hinweis unten, Dialog, Ergebnis mit Code. Jedes Spiel ist eine eigene Datei (cardgame_cards.js, cardgame_wheel.js, cardgame_scratch.js), die erst geladen wird,
   wenn sie dran ist, und sich als window.AmadeusGames.<name> meldet: { title, sub, count, mount(stage, api) }. Die Spiele melden nur das Ende mit api.win(), den Server fragt api.play().
   Der Text sagt nirgends etwas über Zufall oder Chancen: wer spielt, bekommt den Gutschein. Der Code wird in localStorage (amadeusPromoCode) gelegt, die Kasse trägt ihn ein.
   Es wird geladen, wenn das Spiel eingeschaltet ist; die Zeit bis zum Angebot (data-after, Sekunden) kommt aus den Einstellungen, data-v ist die Version der Spiel-Dateien. */
(function () {
	'use strict';
	var me = document.currentScript;
	var AFTER = Math.max(15, +(me && me.dataset.after) || 60) * 1000, VER = (me && me.dataset.v) || '';
	var KEY = 'amadeusCardGame', PROMO = 'amadeusPromoCode';
	var FAIL = 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.';
	var OFFER = { cards: 'Such dir drei Karten aus.', wheel: 'Dreh am Rad.', scratch: 'Rubbel drei Felder frei.' };
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',').replace(',00', '') + ' €'; }
	function reduced() { return !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches); }

	// already played, or said no recently: nothing to do
	var st = {}; try { st = JSON.parse(lsGet(KEY) || '{}') || {}; } catch (e) {}
	if (st.done || (st.no && Date.now() - st.no < 14 * 86400000)) { return; }

	// time the guest has looked at this page: counted in memory only (nothing is stored in the browser for it), and only while the tab is visible,
	// so a tab that was left open for hours does not bring the time with it and the game does not appear the moment the guest comes back
	var seen = 0, lastTick = Date.now();
	var cfg = null, offerEl = null, timer = setInterval(tick, 3000);
	function dialogOpen() { return !!document.querySelector('dialog[open]'); }
	function tick() {
		var now = Date.now();
		if (document.visibilityState === 'visible') { seen += Math.min(now - lastTick, 4000); }
		lastTick = now;
		if (document.visibilityState !== 'visible' || dialogOpen() || seen < AFTER) { return; }
		clearInterval(timer);
		fetch('api.php?op=card_state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (r && r.ok && r.eligible) { cfg = r; showOffer(); }
		}).catch(function () {});
	}

	// the file of a game: loaded once, when the offer is shown (so it is there when the guest taps "Spielen")
	window.AmadeusGames = window.AmadeusGames || {};
	var waiting = {};
	function whenGame(name, cb) {
		if (window.AmadeusGames[name]) { cb(window.AmadeusGames[name]); return; }
		if (waiting[name]) { waiting[name].push(cb); return; }
		waiting[name] = [cb];
		var s = document.createElement('script');
		s.src = 'cardgame_' + name + '.js' + (VER ? '?v=' + encodeURIComponent(VER) : '');
		s.onload = s.onerror = function () { var l = waiting[name]; delete waiting[name]; l.forEach(function (f) { f(window.AmadeusGames[name] || null); }); };
		document.head.appendChild(s);
	}

	function dismiss() { lsSet(KEY, JSON.stringify({ no: Date.now() })); if (offerEl) { offerEl.remove(); offerEl = null; } }
	function showOffer() {
		var name = (cfg && OFFER[cfg.game]) ? cfg.game : 'cards';
		whenGame(name, function () {});
		offerEl = document.createElement('aside');
		offerEl.className = 'cg-offer'; offerEl.setAttribute('aria-label', 'Kleines Spiel');
		offerEl.innerHTML = '<div class="cg-offer-t"><strong>Kleines Spiel gefällig?</strong><span>' + esc(OFFER[name]) + '</span></div>' +
			'<div class="cg-offer-b"><button type="button" class="cg-btn" data-cg="play">Spielen</button><button type="button" class="cg-link" data-cg="no">Nein danke</button></div>';
		offerEl.addEventListener('click', function (ev) {
			var b = ev.target.closest('[data-cg]'); if (!b) { return; }
			if (b.dataset.cg === 'no') { dismiss(); return; }
			whenGame(name, function (game) {
				if (offerEl) { offerEl.remove(); offerEl = null; }
				if (game) { openGame(name, game); }   // the file did not load: the offer just goes away, nothing is remembered
			});
		});
		document.body.appendChild(offerEl);
	}

	function openGame(name, game) {
		var d = document.createElement('dialog');
		d.className = 'gb-dialog cg-dialog cg-game-' + name; d.setAttribute('aria-labelledby', 'cg-title');
		d.innerHTML = '<div class="cg-body"><h2 id="cg-title">' + esc(game.title) + '</h2><p class="cg-sub" id="cg-sub">' + esc(game.sub) + '</p><div class="cg-stage" id="cg-stage"></div>' +
			'<p class="cg-count" id="cg-count" role="status">' + esc(game.count || '') + '</p><div class="cg-result" id="cg-result" hidden></div>' +
			'<div class="cg-act" id="cg-act"><button type="button" class="cg-link" data-cg="close">Schließen</button></div></div>';
		document.body.appendChild(d);
		var done = false;
		function q(id) { return d.querySelector('#' + id); }
		d.addEventListener('close', function () {
			if (!done) { lsSet(KEY, JSON.stringify({ no: Date.now() })); }
			setTimeout(function () { d.remove(); }, 60);
		});
		d.addEventListener('click', function (ev) {
			if (ev.target === d) { d.close(); return; }
			var b = ev.target.closest('[data-cg]'); if (!b) { return; }
			if (b.dataset.cg === 'close') { d.close(); }
			if (b.dataset.cg === 'copy') { copy(b); }
		});
		function copy(b) {
			var code = q('cg-result').dataset.code || '';
			try { navigator.clipboard.writeText(code).then(function () { b.textContent = 'Kopiert'; }, function () { b.textContent = 'Bitte von Hand kopieren'; }); } catch (e) { b.textContent = 'Bitte von Hand kopieren'; }
		}
		// what a game may use
		var api = {
			esc: esc, reduced: reduced(), percent: cfg && cfg.percent ? cfg.percent : 10,
			say: function (t) { q('cg-count').textContent = t; },
			error: function (t) { q('cg-count').textContent = ''; q('cg-sub').textContent = t || FAIL; },
			// the server makes the voucher of this game; resolves with { code, until, percent, min, days }, rejects with an Error that holds the text for the guest
			play: function () {
				return fetch('api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'card_play', token: document.body.dataset.token, game: name }) })
					.then(function (r) { return r.json(); }, function () { throw new Error(FAIL); })
					.then(function (r) { if (!r || !r.ok) { throw new Error((r && r.error) || FAIL); } return r; });
			},
			// the end: remember it, then show the result with the code (after o.delay ms so the game's own ending can be seen; at once with "reduce motion")
			win: function (r, o) {
				o = o || {}; done = true;
				lsSet(KEY, JSON.stringify({ done: Date.now(), code: r.code })); lsSet(PROMO, JSON.stringify({ code: r.code, at: Date.now() }));
				q('cg-sub').hidden = true; q('cg-count').hidden = true; q('cg-act').hidden = true;
				var reveal = function () {
					q('cg-title').textContent = o.title || 'Geschafft!';
					var res = q('cg-result');
					res.innerHTML = '<p class="cg-lead"><strong>' + esc(r.percent) + ' % Rabatt</strong> auf deine nächste Bestellung</p>' +
						'<p class="cg-code" aria-label="Dein Code">' + esc(r.code) + '</p>' +
						'<p class="cg-fine">Gilt ab ' + esc(fmt(r.min)) + ' Warenwert, einmal pro Person, bis ' + esc(r.until) + '. Wir tragen den Code an der Kasse für dich ein.</p>' +
						'<div class="cg-act"><button type="button" class="cg-btn" id="cg-go" data-cg="close">Weiter bestellen</button><button type="button" class="cg-link" data-cg="copy">Code kopieren</button></div>';
					res.hidden = false; res.dataset.code = r.code;
					var go = q('cg-go'); if (go) { go.focus({ preventScroll: true }); res.scrollIntoView({ block: 'nearest' }); }
				};
				if (reduced()) { reveal(); } else { setTimeout(reveal, o.delay == null ? 800 : o.delay); }
			}
		};
		d.showModal();   // first shown, then the game is built, so it can measure its space
		game.mount(q('cg-stage'), api);
	}
})();
