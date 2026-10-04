<?php
/*
 * Tagesbericht: two short slips (80 mm) of one day of the delivery service - one about all cash (what the drivers and the counter have to hand in) and one
 * about everything paid online (own shop through Mollie, Lieferando). Nothing is stored: the numbers are worked out from the orders of the day each time.
 * shop_report_data() adds the day up, shop_report_rows() turns it into a list of rows for one slip, and the same rows are drawn as HTML (preview and
 * browser print, web/tagesbericht.php) and as ESC/POS bytes (the receipt printer on the Raspberry Pi, see shop_print_claim()).
 *
 * A day is the calendar day of the order (order_date). Test, cancelled, failed and unpaid online orders are not counted as turnover. Cash counts as taken when the delivery or pickup is
 * done; what is still on its way is listed apart. Lieferando orders are delivered by the own drivers: their cash counts with the cash, what Lieferando took online is shown on the online slip.
 */
require_once __DIR__.'/shop.class.php';

function shop_report_method_label($m) {
	$map = array('creditcard' => 'Kreditkarte', 'paypal' => 'PayPal', 'applepay' => 'Apple Pay', 'ideal' => 'iDEAL', 'sofort' => 'Sofortüberweisung', 'klarna' => 'Klarna', 'giropay' => 'giropay',
		'bancontact' => 'Bancontact', 'eps' => 'EPS', 'banktransfer' => 'Überweisung', 'przelewy24' => 'Przelewy24', 'directdebit' => 'Lastschrift', 'trustly' => 'Trustly', 'in3' => 'in3', 'twint' => 'TWINT');
	$m = (string)$m;
	return $m === '' ? 'ohne Angabe' : (isset($map[$m]) ? $map[$m] : ucfirst($m));
}

function shop_report_data($date) {
	shop_ensure_schema();
	$rows = fb_rows("SELECT id, type, status, source, payment_method, payment_status, pay_detail, total_cents, fee_cents, tip_cents, discount_cents, surcharge_cents, pay_with_cents,
			driver_id, route_m, created_at, ready_at FROM ".fb_t('tp_shop_orders')." WHERE order_date = ? AND is_test = 0", 's', array($date));
	$names = array();
	foreach (fb_rows("SELECT id, name FROM ".fb_t('tp_shop_drivers')) as $dr) { $names[(int)$dr['id']] = $dr['name']; }
	$z = function () { return array('n' => 0, 'sum' => 0); };
	$d = array('date' => $date, 'orders' => 0, 'revenue' => 0, 'cash_rev' => 0, 'card_rev' => 0, 'online_rev' => 0,
		'cash' => array('done' => $z(), 'open' => $z(), 'delivery' => $z(), 'pickup' => $z(), 'nodriver' => $z(), 'lieferando' => $z(), 'change' => 0, 'storno' => $z(), 'fee' => 0, 'tip' => 0, 'discount' => 0, 'surcharge' => 0),
		'card' => array('done' => $z(), 'open' => $z(), 'storno' => $z()),
		'drivers' => array(),
		'shop' => array('paid' => $z(), 'delivery' => $z(), 'pickup' => $z(), 'by_method' => array(), 'tip' => 0, 'discount' => 0, 'refund' => $z(), 'failed' => 0, 'pending' => 0),
		'lief' => array('paid' => $z(), 'delivery' => $z(), 'pickup' => $z(), 'storno' => $z()),
		'hours' => array(), 'ready_sum' => 0, 'ready_n' => 0, 'open_n' => 0, 'top' => array());
	$add = function (&$a, $c) { $a['n']++; $a['sum'] += (int)$c; };
	$openStates = array('new', 'accepted', 'preparing', 'ready', 'delivering');
	foreach ($rows as $o) {
		$t = (int)$o['total_cents']; $m = $o['payment_method']; $st = $o['status']; $delivery = ($o['type'] === 'delivery');
		if ($m === 'mollie' && in_array($o['payment_status'], array('failed', 'expired', 'canceled'), true) && $st !== 'new') { $d['shop']['failed']++; continue; }
		if ($st === 'pending') { $d['shop']['pending']++; continue; }
		if ($st === 'cancelled' || $st === 'failed') {
			if ($m === 'mollie') { if ($o['payment_status'] === 'paid') { $add($d['shop']['refund'], $t); } }
			elseif ($m === 'lieferando') { $add($d['lief']['storno'], $t); }
			elseif ($m === 'card_door') { $add($d['card']['storno'], $t); }
			else { $add($d['cash']['storno'], $t); }
			continue;
		}
		$d['orders']++; $d['revenue'] += $t;
		if (in_array($st, $openStates, true)) { $d['open_n']++; }
		$h = (int)substr($o['created_at'], 11, 2); $d['hours'][$h] = (isset($d['hours'][$h]) ? $d['hours'][$h] : 0) + 1;
		if (!empty($o['ready_at'])) { $d['ready_sum'] += max(0, (strtotime($o['ready_at']) - strtotime($o['created_at'])) / 60); $d['ready_n']++; }
		$isDone = ($st === 'done');
		if ($m === 'mollie' || $m === 'lieferando') {
			$g = ($m === 'mollie') ? 'shop' : 'lief';
			$d['online_rev'] += $t; $add($d[$g]['paid'], $t); $add($d[$g][$delivery ? 'delivery' : 'pickup'], $t);
			if ($m === 'mollie') {
				$k = (string)$o['pay_detail']; if (!isset($d['shop']['by_method'][$k])) { $d['shop']['by_method'][$k] = $z(); } $add($d['shop']['by_method'][$k], $t);
				$d['shop']['tip'] += (int)$o['tip_cents']; $d['shop']['discount'] += (int)$o['discount_cents'];
			}
			continue;
		}
		$card = ($m === 'card_door'); $k = $card ? 'card' : 'cash';
		if ($card) { $d['card_rev'] += $t; } else { $d['cash_rev'] += $t; }
		if (!$card) { $d['cash']['fee'] += (int)$o['fee_cents']; $d['cash']['tip'] += (int)$o['tip_cents']; $d['cash']['discount'] += (int)$o['discount_cents']; $d['cash']['surcharge'] += (int)$o['surcharge_cents']; }
		if (!$isDone) { $add($d[$k]['open'], $t); continue; }
		$add($d[$k]['done'], $t);
		if ($k === 'cash') {
			if ((int)$o['pay_with_cents'] > $t) { $d['cash']['change'] += (int)$o['pay_with_cents'] - $t; }
			if ($o['source'] === 'lieferando') { $add($d['cash']['lieferando'], $t); }
			if (!$delivery) { $add($d['cash']['pickup'], $t); }
			elseif ($o['driver_id']) { $add($d['cash']['delivery'], $t); } else { $add($d['cash']['nodriver'], $t); }
		}
	}
	// every finished delivery of a driver (any payment) with the cash and card he took at the door
	foreach ($rows as $o) {
		if ($o['status'] !== 'done' || $o['type'] !== 'delivery' || !$o['driver_id']) { continue; }
		$id = (int)$o['driver_id'];
		if (!isset($d['drivers'][$id])) { $d['drivers'][$id] = array('name' => isset($names[$id]) ? $names[$id] : 'Fahrer', 'n' => 0, 'cash' => 0, 'card' => 0, 'm' => 0); }
		$d['drivers'][$id]['n']++; $d['drivers'][$id]['m'] += (int)$o['route_m'] * 2;
		if ($o['payment_method'] === 'cash') { $d['drivers'][$id]['cash'] += (int)$o['total_cents']; }
		if ($o['payment_method'] === 'card_door') { $d['drivers'][$id]['card'] += (int)$o['total_cents']; }
	}
	uasort($d['drivers'], function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
	foreach (fb_rows("SELECT i.title, SUM(i.qty) AS q FROM ".fb_t('tp_shop_order_items')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE o.order_date = ? AND o.is_test = 0 AND o.status NOT IN ('pending', 'cancelled', 'failed') GROUP BY i.title ORDER BY q DESC, i.title LIMIT 3", 's', array($date)) as $t) { $d['top'][] = array($t['title'], (int)$t['q']); }
	return $d;
}

function shop_report_weekday($date) {
	$w = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
	$ts = strtotime($date.' 12:00:00');
	return $w[(int)date('w', $ts)].', '.date('d.m.Y', $ts);
}

// the rows of one slip: kind 'cash' or 'online'. A row is array(type, ...): title, sub, rule, sec, row (label, value, 'b' bold / 'big' large), note, fill, blank
function shop_report_rows($kind, $d, $now = null) {
	$now = $now ?: time(); $m = 'shop_money';
	$today = ($d['date'] === date('Y-m-d', $now));
	$r = array();
	$r[] = array('title', $kind === 'cash' ? 'BAR' : 'ONLINE');
	$r[] = array('sub', 'TAGESBERICHT', 'b');
	$r[] = array('sub', shop_report_weekday($d['date']));
	$r[] = array('sub', ($today ? 'Zwischenstand ' : 'gedruckt ').date($today ? 'H:i' : 'd.m. H:i', $now).' Uhr');
	$r[] = array('rule');
	$r[] = array('row', 'Tagesumsatz ('.$d['orders'].' Bestellungen)', $m($d['revenue']), 'b');
	$r[] = array('row', 'davon bar', $m($d['cash_rev']));
	$r[] = array('row', 'davon Karte an der Tür', $m($d['card_rev']));
	$r[] = array('row', 'davon online', $m($d['online_rev']));
	$r[] = array('rule');
	if ($kind === 'cash') {
		$c = $d['cash'];
		$r[] = array('row', 'BAR-SOLL', $m($c['done']['sum']), 'big');
		$r[] = array('note', $c['done']['n'].' Barzahlungen, abzuliefern von Fahrern und Abholung');
		$r[] = array('row', 'Lieferung (Fahrer)', $m($c['delivery']['sum']));
		if ($c['nodriver']['n']) { $r[] = array('row', 'Lieferung ohne Fahrer', $m($c['nodriver']['sum'])); }
		$r[] = array('row', 'Abholung (Kasse)', $m($c['pickup']['sum']));
		if ($c['lieferando']['n']) { $r[] = array('note', 'darin Lieferando bar: '.$c['lieferando']['n'].' = '.$m($c['lieferando']['sum'])); }
		$r[] = array('row', 'Rückgeld ausgegeben', $m($c['change']));
		$r[] = array('rule');
		$r[] = array('sec', 'Je Fahrer');
		if (!$d['drivers']) { $r[] = array('note', 'Heute keine abgeschlossene Lieferung mit Fahrer.'); }
		foreach ($d['drivers'] as $dr) {
			$r[] = array('row', $dr['name'], $m($dr['cash']), 'b');
			$r[] = array('note', $dr['n'].($dr['n'] === 1 ? ' Fahrt' : ' Fahrten').', ca. '.number_format($dr['m'] / 1000, 0, ',', '').' km'.($dr['card'] ? ', Karte Tür '.$m($dr['card']) : ''));
		}
		$r[] = array('rule');
		$r[] = array('row', 'Karte an der Tür (EC)', $m($d['card']['done']['sum']), 'b');
		$r[] = array('note', $d['card']['done']['n'].' Zahlungen, bei den Fahrern über das Kartengerät');
		$adj = array();
		if ($c['fee']) { $adj[] = array('row', 'Liefergebühren (bar)', $m($c['fee'])); }
		if ($c['tip']) { $adj[] = array('row', 'Trinkgeld (bar)', $m($c['tip'])); }
		if ($c['discount']) { $adj[] = array('row', 'Rabatte / Gutscheine', '-'.$m($c['discount'])); }
		if ($c['surcharge']) { $adj[] = array('row', 'Aufschläge', '+'.$m($c['surcharge'])); }
		if ($adj) { $r[] = array('rule'); $r[] = array('sec', 'In den Barbestellungen'); foreach ($adj as $a) { $r[] = $a; } }
		$open = array();
		if ($c['open']['n']) { $open[] = array('row', 'Bar noch offen ('.$c['open']['n'].')', $m($c['open']['sum'])); }
		if ($d['card']['open']['n']) { $open[] = array('row', 'Karte Tür offen ('.$d['card']['open']['n'].')', $m($d['card']['open']['sum'])); }
		if ($open) { $r[] = array('rule'); $r[] = array('sec', 'Noch unterwegs'); foreach ($open as $a) { $r[] = $a; } $r[] = array('note', 'Noch nicht im Bar-Soll. Kommt dazu, wenn die Lieferung abgeschlossen ist.'); }
		$st = array();
		if ($c['storno']['n']) { $st[] = array('row', 'Storniert bar ('.$c['storno']['n'].')', $m($c['storno']['sum'])); }
		if ($d['card']['storno']['n']) { $st[] = array('row', 'Storniert Karte Tür ('.$d['card']['storno']['n'].')', $m($d['card']['storno']['sum'])); }
		if ($st) { $r[] = array('rule'); $r[] = array('sec', 'Storniert / fehlgeschlagen'); foreach ($st as $a) { $r[] = $a; } }
		$r[] = array('rule');
		$r[] = array('fill', 'Gezählt');
		$r[] = array('fill', 'Differenz');
		$r[] = array('fill', 'Name');
	} else {
		$s = $d['shop']; $l = $d['lief'];
		$r[] = array('row', 'ONLINE GESAMT', $m($s['paid']['sum'] + $l['paid']['sum']), 'big');
		$r[] = array('note', ($s['paid']['n'] + $l['paid']['n']).' online bezahlte Bestellungen');
		$r[] = array('rule');
		$r[] = array('sec', 'Eigener Shop (Mollie)');
		$r[] = array('row', 'Bestellungen ('.$s['paid']['n'].')', $m($s['paid']['sum']), 'b');
		foreach ($s['by_method'] as $k => $v) { $r[] = array('row', '  '.shop_report_method_label($k).' ('.$v['n'].')', $m($v['sum'])); }
		$r[] = array('row', '  Lieferung ('.$s['delivery']['n'].')', $m($s['delivery']['sum']));
		$r[] = array('row', '  Abholung ('.$s['pickup']['n'].')', $m($s['pickup']['sum']));
		if ($s['tip']) { $r[] = array('row', 'darin Trinkgeld', $m($s['tip'])); }
		if ($s['discount']) { $r[] = array('row', 'darin Gutscheine', '-'.$m($s['discount'])); }
		$r[] = array('rule');
		$r[] = array('sec', 'Lieferando (online bezahlt)');
		$r[] = array('row', 'Bestellungen ('.$l['paid']['n'].')', $m($l['paid']['sum']), 'b');
		$r[] = array('row', '  Lieferung ('.$l['delivery']['n'].')', $m($l['delivery']['sum']));
		$r[] = array('row', '  Abholung ('.$l['pickup']['n'].')', $m($l['pickup']['sum']));
		$r[] = array('note', 'Die Auszahlung kommt von Lieferando, die Provision ist noch nicht abgezogen.');
		$chk = array();
		if ($s['refund']['n']) { $chk[] = array('row', 'Online bezahlt, storniert ('.$s['refund']['n'].')', $m($s['refund']['sum'])); }
		if ($s['failed']) { $chk[] = array('row', 'Zahlung fehlgeschlagen', (string)$s['failed']); }
		if ($s['pending']) { $chk[] = array('row', 'Zahlung noch ausstehend', (string)$s['pending']); }
		if ($l['storno']['n']) { $chk[] = array('row', 'Lieferando storniert ('.$l['storno']['n'].')', $m($l['storno']['sum'])); }
		if ($chk) { $r[] = array('rule'); $r[] = array('sec', 'Zu klären'); foreach ($chk as $a) { $r[] = $a; } if ($s['refund']['n']) { $r[] = array('note', 'Bei Mollie zurückzahlen, falls noch nicht geschehen.'); } }
		$r[] = array('rule');
		$r[] = array('sec', 'Der Tag');
		if ($d['hours']) { arsort($d['hours']); $h = key($d['hours']); $r[] = array('row', 'Stärkste Stunde', sprintf('%02d-%02d Uhr', $h, ($h + 1) % 24)); $r[] = array('note', $d['hours'][$h].' Bestellungen in dieser Stunde'); }
		if ($d['ready_n']) { $r[] = array('row', 'Ø bis fertig', (int)round($d['ready_sum'] / $d['ready_n']).' Min'); }
		if ($d['orders']) { $r[] = array('row', 'Ø Bestellwert', $m((int)round($d['revenue'] / $d['orders']))); }
		foreach ($d['top'] as $i => $t) { $r[] = array('row', ($i + 1).'. '.$t[0], $t[1].'x'); }
		if (!$d['orders']) { $r[] = array('note', 'Heute noch keine Bestellung.'); }
		if ($d['open_n']) { $r[] = array('note', 'Noch '.$d['open_n'].' Aufträge offen, der Bericht ist ein Zwischenstand.'); }
	}
	return $r;
}

function shop_report_html($rows) {
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$o = '';
	foreach ($rows as $r) {
		switch ($r[0]) {
			case 'title': $o .= '<div class="sl-title">'.$h($r[1]).'</div>'; break;
			case 'sub': $o .= '<div class="sl-sub'.(isset($r[2]) ? ' sl-b' : '').'">'.$h($r[1]).'</div>'; break;
			case 'rule': $o .= '<hr/>'; break;
			case 'sec': $o .= '<div class="sl-sec">'.$h($r[1]).'</div>'; break;
			case 'row': $o .= '<div class="sl-row'.(isset($r[3]) ? ' sl-'.$r[3] : '').'"><span>'.$h($r[1]).'</span><b>'.$h($r[2]).'</b></div>'; break;
			case 'note': $o .= '<div class="sl-note">'.$h($r[1]).'</div>'; break;
			case 'fill': $o .= '<div class="sl-fill"><span>'.$h($r[1]).':</span><i></i></div>'; break;
			default: $o .= '<div class="sl-gap"></div>';
		}
	}
	return $o;
}

function shop_report_escpos($kind, $date) {
	require_once __DIR__.'/escpos.class.php';
	$rows = shop_report_rows($kind, shop_report_data($date));
	$W = 42; $b = "\x1b@\x1bt\x13";
	$put = function ($text, $size = 0, $bold = false, $align = 0) use (&$b, $W) {
		$mult = $size === 2 ? 2 : 1; $gs = $size === 2 ? "\x11" : ($size === 1 ? "\x01" : "\x00");
		$b .= "\x1ba".chr($align)."\x1d!".$gs."\x1bE".chr($bold ? 1 : 0);
		foreach (escpos_wrap($text, (int)floor($W / $mult)) as $l) { $b .= escpos_enc($l)."\n"; }
		$b .= "\x1d!\x00\x1bE\x00\x1ba\x00";
	};
	$line = function ($label, $value, $w) {
		$label = (string)$label; $value = (string)$value;
		$room = $w - mb_strlen($value) - 1;
		if (mb_strlen($label) > $room) { $label = mb_substr($label, 0, max(1, $room)); }
		return $label.str_repeat(' ', max(1, $w - mb_strlen($label) - mb_strlen($value))).$value;
	};
	foreach ($rows as $r) {
		switch ($r[0]) {
			case 'title': $put($r[1], 2, true, 1); break;
			case 'sub': $put($r[1], 0, isset($r[2]), 1); break;
			case 'rule': $b .= str_repeat('-', $W)."\n"; break;
			case 'sec': $put(mb_strtoupper($r[1]), 0, true); break;
			case 'row':
				$big = isset($r[3]) && $r[3] === 'big'; $bold = isset($r[3]) && ($r[3] === 'b' || $big);
				if ($big && mb_strlen($r[1]) + mb_strlen($r[2]) + 1 <= 21) { $put($line($r[1], $r[2], 21), 2, true); }
				else { $put($line($r[1], $r[2], $W), $big ? 1 : 0, $bold); }
				break;
			case 'note': $put($r[1], 0); break;
			case 'fill': $put($r[1].': '.str_repeat('_', $W - mb_strlen($r[1]) - 2), 1); $b .= "\n"; break;
			default: $b .= "\n";
		}
	}
	$b .= "\n".str_repeat('-', $W)."\n";
	return $b."\n\n\n\x1dV\x42\x00";
}
