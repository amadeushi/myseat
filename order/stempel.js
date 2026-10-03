/* Stempelkarte: draws the card (round stamps, Zeus as the stamp, voucher ticket) into an element. The numbers come from the server
   (op=stamp_state / the status page); this only shows them. AmadeusStamp.render(el, state, { known, next, fresh, full }) */
(function () {
	'use strict';
	var ROT = [-6, 4, -3, 7, -5, 3, -4, 6, -2, 5, -7, 2], uid = 0;
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function fmt(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
	function slot(i, filled, fresh, next) {
		var id = 'stp-p' + (++uid);
		var svg = '<svg viewBox="0 0 232 232" aria-hidden="true"><circle class="stp-disc" cx="116" cy="116" r="114"/><circle class="stp-inner" cx="116" cy="116" r="86"/>' +
			'<defs><path id="' + id + '" d="M116 116 m-89 0 a89 89 0 1 1 178 0 a89 89 0 1 1 -178 0"/></defs>' +
			'<text class="stp-text"><textPath href="#' + id + '" textLength="551" lengthAdjust="spacing">AMADEUS DELIVERY · AMADEUS DELIVERY · </textPath></text></svg>';
		return '<div class="stp-slot' + (filled ? '' : ' is-empty') + (filled && fresh ? ' is-fresh' : '') + (next ? ' is-next' : '') + '" style="--r:' + ROT[i % ROT.length] + 'deg" role="img" aria-label="Stempel ' + (i + 1) + ', ' + (filled ? 'gesetzt' : 'frei') + '">' +
			svg + '<span class="stp-num">' + (i + 1) + '</span><div class="stp-dog"><i></i></div></div>';
	}
	function render(el, st, o) {
		if (!el) { return; }
		if (!st || !st.on) { el.innerHTML = ''; return; }
		o = o || {};
		var goal = Math.max(2, st.goal | 0), count = Math.max(0, Math.min(st.count | 0, goal)), cols = goal <= 5 ? goal : (goal <= 8 ? 4 : 6), head, sub;
		if (o.full) { count = goal; }
		if (o.full) { head = 'Karte voll'; sub = 'Dein Gutschein ist da. Wir ziehen ihn bei deiner nächsten Bestellung automatisch ab.'; }
		else if (!o.known) { head = 'Sammle Stempel'; sub = 'Jede abgeschlossene Bestellung ist ein Stempel. Nach ' + goal + ' Stempeln bekommst du einen Gutschein über ' + st.percent + ' % deiner Bestellungen, den wir automatisch abziehen.'; }
		else {
			head = 'Deine Stempelkarte';
			sub = count + ' von ' + goal + ' Stempeln · noch ' + (goal - count) + ' bis zum Gutschein' + (st.saved > 0 ? ' · bisher ' + fmt(st.saved) + ' gespart' : '');
			if (count === 0 && st.voucher) { sub = 'Neue Karte, los geht’s. Dein Gutschein steht unten.'; }
		}
		var h = '<div class="stp"><div class="stp-head"><h3>' + esc(head) + '</h3><p>' + esc(sub) + '</p></div><div class="stp-row" style="--cols:' + cols + '">';
		for (var i = 0; i < goal; i++) { h += slot(i, i < count, o.fresh === i, !!o.next && i === count); }
		h += '</div>';
		if (st.voucher) {
			h += '<div class="stp-ticket"><span>Gutschein, wird automatisch abgezogen<small>gültig bis ' + esc(st.voucher.until) + ' · ab ' + fmt(st.voucher.min || st.voucher.value) + ' Warenwert' +
				(st.voucher.discount > 0 ? ' · bei dieser Bestellung −' + fmt(st.voucher.discount) : (o.cart && st.voucher.missing > 0 ? ' · dir fehlen noch ' + fmt(st.voucher.missing) : '')) + '</small></span><b>' + fmt(st.voucher.value) + '</b></div>';
		}
		if (o.known && st.until && count > 0 && !o.full) { h += '<p class="stp-hint">Deine Stempel gelten bis ' + esc(st.until) + '.</p>'; }
		el.innerHTML = h + '</div>';
	}
	window.AmadeusStamp = { render: render };
})();
