/* Kartenspiel der Bestellseite: nach einer Weile im Shop, ohne zu bestellen, wird einem Gast ohne Konto ein kleines Spiel angeboten: vier verdeckte Karten, drei werden
   aufgedeckt, die dritte zeigt einen persönlichen Gutschein (web/classes/shop_cardgame.class.php macht ihn, der Server entscheidet, ob das Spiel gerade angeboten wird).
   Der Text sagt nirgends etwas über Zufall oder Chancen: wer spielt, bekommt den Gutschein. Der Code wird in localStorage (amadeusPromoCode) gelegt, die Kasse trägt ihn ein.
   Es wird geladen, wenn das Spiel eingeschaltet ist; die Zeit bis zum Angebot (data-after, Sekunden) kommt aus den Einstellungen. */
(function () {
	'use strict';
	var me = document.currentScript;
	var AFTER = Math.max(15, +(me && me.dataset.after) || 60) * 1000;
	var KEY = 'amadeusCardGame', T0 = 'amadeusVisitT0', PROMO = 'amadeusPromoCode';
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
	function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
	function ssSet(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} }
	function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',').replace(',00', '') + ' €'; }

	// already played, or said no recently: nothing to do
	var st = {}; try { st = JSON.parse(lsGet(KEY) || '{}') || {}; } catch (e) {}
	if (st.done || (st.no && Date.now() - st.no < 14 * 86400000)) { return; }

	// time on the shop of this visit (a tab session), counted from the first page
	var t0 = +ssGet(T0) || 0;
	if (!t0) { t0 = Date.now(); ssSet(T0, String(t0)); }
	var cfg = null, offerEl = null, timer = setInterval(tick, 3000);
	function dialogOpen() { return !!document.querySelector('dialog[open]'); }
	function tick() {
		if (document.visibilityState !== 'visible' || dialogOpen() || Date.now() - t0 < AFTER) { return; }
		clearInterval(timer);
		fetch('api.php?op=card_state', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (r && r.ok && r.eligible) { cfg = r; showOffer(); }
		}).catch(function () {});
	}

	function dismiss() { lsSet(KEY, JSON.stringify({ no: Date.now() })); if (offerEl) { offerEl.remove(); offerEl = null; } }
	function showOffer() {
		offerEl = document.createElement('aside');
		offerEl.className = 'cg-offer'; offerEl.setAttribute('aria-label', 'Kleines Spiel');
		offerEl.innerHTML = '<div class="cg-offer-t"><strong>Kleines Spiel gefällig?</strong><span>Such dir drei Karten aus.</span></div>' +
			'<div class="cg-offer-b"><button type="button" class="cg-btn" data-cg="play">Spielen</button><button type="button" class="cg-link" data-cg="no">Nein danke</button></div>';
		offerEl.addEventListener('click', function (ev) {
			var b = ev.target.closest('[data-cg]'); if (!b) { return; }
			if (b.dataset.cg === 'no') { dismiss(); } else { offerEl.remove(); offerEl = null; openGame(); }
		});
		document.body.appendChild(offerEl);
	}

	var BOLT = '<svg viewBox="0 0 24 24" width="44" height="44" aria-hidden="true"><path d="M13.5 2 5 13.5h5.2L9.5 22 19 9.8h-5.4z" fill="currentColor"/></svg>';
	var CUTLET = '<svg viewBox="0 0 56 40" width="64" height="46" aria-hidden="true"><path d="M3 21C2 11 12 4 25 5c11 1 19 5 18 13-1 9-10 15-22 14C10 31 4 28 3 21z" fill="currentColor"/><circle cx="14" cy="17" r="1.5" fill="#1a1408"/><circle cx="22" cy="12" r="1.5" fill="#1a1408"/><circle cx="31" cy="15" r="1.5" fill="#1a1408"/><circle cx="19" cy="24" r="1.5" fill="#1a1408"/><circle cx="28" cy="22" r="1.5" fill="#1a1408"/><circle cx="39" cy="20" r="1.5" fill="#1a1408"/><circle cx="43" cy="29" r="9" fill="#e9d46b"/><circle cx="43" cy="29" r="6.6" fill="#f6ea96"/><path d="M43 22.4v13.2M36.4 29h13.2M38.3 24.3l9.4 9.4M47.7 24.3l-9.4 9.4" stroke="#e9d46b" stroke-width="1"/></svg>';
	var FACES = [{ svg: BOLT, w: 'Blitz' }, { svg: CUTLET, w: 'Schnitzel' }];

	function openGame() {
		var d = document.createElement('dialog');
		d.className = 'gb-dialog cg-dialog'; d.setAttribute('aria-labelledby', 'cg-title');
		var cards = '';
		for (var i = 0; i < 4; i++) {
			cards += '<button type="button" class="cg-card" data-i="' + i + '" aria-label="Karte ' + (i + 1) + ', verdeckt"><span class="cg-in"><span class="cg-back"><img src="zeus_dot.png" alt="" width="44" height="44"></span><span class="cg-face"></span></span></button>';
		}
		d.innerHTML = '<div class="cg-body"><h2 id="cg-title">Such dir drei Karten aus</h2><p class="cg-sub" id="cg-sub">Tippe nacheinander auf drei Karten.</p>' +
			'<div class="cg-grid">' + cards + '</div><p class="cg-count" id="cg-count" role="status">0 von 3</p><div class="cg-result" id="cg-result" hidden></div>' +
			'<div class="cg-act" id="cg-act"><button type="button" class="cg-link" data-cg="close">Schließen</button></div></div>';
		document.body.appendChild(d);
		var picks = 0, wait = false, done = false;
		function q(id) { return d.querySelector('#' + id); }
		d.addEventListener('close', function () {
			if (!done) { lsSet(KEY, JSON.stringify({ no: Date.now() })); }
			setTimeout(function () { d.remove(); }, 60);
		});
		d.addEventListener('click', function (ev) {
			if (ev.target === d) { d.close(); return; }
			var c = ev.target.closest('.cg-card');
			if (c) { turn(c); return; }
			var b = ev.target.closest('[data-cg]'); if (!b) { return; }
			if (b.dataset.cg === 'close') { d.close(); }
			if (b.dataset.cg === 'copy') { copy(b); }
		});
		function up(c, html, word) {
			c.querySelector('.cg-face').innerHTML = html; c.classList.add('is-up');
			c.setAttribute('aria-label', 'Karte ' + (+c.dataset.i + 1) + ', aufgedeckt: ' + word); c.setAttribute('aria-disabled', 'true');
		}
		function turn(c) {
			if (wait || done || c.classList.contains('is-up') || picks >= 3) { return; }
			if (picks < 2) {
				var f = FACES[picks]; up(c, f.svg + '<span class="cg-word">' + f.w + '</span>', f.w);
				picks++; q('cg-count').textContent = picks + ' von 3'; return;
			}
			wait = true; q('cg-count').textContent = 'Einen Moment ...';
			fetch('api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'card_play', token: document.body.dataset.token }) })
				.then(function (r) { return r.json(); }).then(function (r) {
					if (!r || !r.ok) { q('cg-count').textContent = ''; q('cg-sub').textContent = (r && r.error) || 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'; wait = false; return; }
					done = true; picks = 3;
					up(c, '<span class="cg-pct">' + esc(r.percent) + ' %</span><span class="cg-word">Gutschein</span>', r.percent + ' Prozent Gutschein'); c.classList.add('is-win');
					lsSet(KEY, JSON.stringify({ done: Date.now(), code: r.code })); lsSet(PROMO, JSON.stringify({ code: r.code, at: Date.now() }));
					[].forEach.call(d.querySelectorAll('.cg-card:not(.is-up)'), function (x) { x.disabled = true; });
					q('cg-title').textContent = 'Treffer!'; q('cg-sub').hidden = true; q('cg-count').hidden = true; q('cg-act').hidden = true;
					var res = q('cg-result');
					res.innerHTML = '<p class="cg-lead"><strong>' + esc(r.percent) + ' % Rabatt</strong> auf deine nächste Bestellung</p>' +
						'<p class="cg-code" aria-label="Dein Code">' + esc(r.code) + '</p>' +
						'<p class="cg-fine">Gilt ab ' + esc(fmt(r.min)) + ' Warenwert, einmal pro Person, bis ' + esc(r.until) + '. Wir tragen den Code an der Kasse für dich ein.</p>' +
						'<div class="cg-act"><button type="button" class="cg-btn" id="cg-go" data-cg="close">Weiter bestellen</button><button type="button" class="cg-link" data-cg="copy">Code kopieren</button></div>';
					res.hidden = false; res.dataset.code = r.code;
					var go = q('cg-go'); if (go) { go.focus({ preventScroll: true }); res.scrollIntoView({ block: 'nearest' }); }
				}).catch(function () { q('cg-count').textContent = ''; q('cg-sub').textContent = 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'; wait = false; });
		}
		function copy(b) {
			var code = q('cg-result').dataset.code || '';
			try { navigator.clipboard.writeText(code).then(function () { b.textContent = 'Kopiert'; }, function () { b.textContent = 'Bitte von Hand kopieren'; }); } catch (e) { b.textContent = 'Bitte von Hand kopieren'; }
		}
		d.showModal();
	}
})();
