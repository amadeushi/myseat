<?php
/*
 * The slip of an order as ESC/POS bytes for the receipt printer in the kitchen (80 mm paper, 42 columns, code page 858). The same slip as
 * web/bon.php, but as text with large and bold characters: it is printed by the print agent on the Raspberry Pi (see shop_print_*), so no
 * browser, no print dialog and no driver are involved. Without "full" it is the kitchen slip (name and postcode of the guest only), with
 * full the delivery slip (address, what to collect; no phone number).
 */

// UTF-8 text -> code page 858 (850 plus the euro sign); what the printer cannot print becomes "?"
function escpos_enc($s) {
	$s = str_replace(array('–', '—', '„', '“', '”', '‚', '‘', '’', '×', '…', "\r"), array('-', '-', '"', '"', '"', "'", "'", "'", 'x', '...', ''), (string)$s);
	$out = '';
	foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
		if ($ch === '€') { $out .= "\xD5"; continue; }
		if (strlen($ch) === 1) { $out .= $ch; continue; }
		$c = @mb_convert_encoding($ch, 'CP850', 'UTF-8');
		$out .= ($c === false || $c === '' || $c === '?') ? '?' : $c;
	}
	return $out;
}

// break a text into lines of at most $w characters, at spaces where possible
function escpos_wrap($s, $w) {
	$lines = array();
	foreach (explode("\n", trim((string)$s)) as $para) {
		$cur = '';
		foreach (preg_split('/\s+/u', $para, -1, PREG_SPLIT_NO_EMPTY) as $word) {
			while (mb_strlen($word) > $w) { if ($cur !== '') { $lines[] = $cur; $cur = ''; } $lines[] = mb_substr($word, 0, $w); $word = mb_substr($word, $w); }
			if ($cur === '') { $cur = $word; } elseif (mb_strlen($cur) + 1 + mb_strlen($word) <= $w) { $cur .= ' '.$word; } else { $lines[] = $cur; $cur = $word; }
		}
		$lines[] = $cur;
	}
	return $lines;
}

function shop_slip_escpos($o, $items, $full) {
	$W = 42;
	$b = "\x1b@\x1bt\x13"; // reset, code page 858
	// size: 0 normal, 1 double height, 2 double width and height
	$put = function ($text, $size = 0, $bold = false, $align = 0) use (&$b, $W) {
		$mult = $size === 2 ? 2 : 1;
		$gs = $size === 2 ? "\x11" : ($size === 1 ? "\x01" : "\x00");
		$b .= "\x1ba".chr($align)."\x1d!".$gs."\x1bE".chr($bold ? 1 : 0);
		foreach (escpos_wrap($text, (int)floor($W / $mult)) as $l) { $b .= escpos_enc($l)."\n"; }
		$b .= "\x1d!\x00\x1bE\x00\x1ba\x00";
	};
	$rule = function () use (&$b, $W) { $b .= str_repeat('-', $W)."\n"; };
	$delivery = ($o['type'] === 'delivery');
	$due = $o['scheduled_at'] ?: ($o['eta_at'] ?: $o['created_at']);

	// an order from Lieferando: a white-on-black bar on top (reverse print), so it is not mixed up with the own deliveries
	if (isset($o['source']) && $o['source'] === 'lieferando') { $b .= "\x1ba\x01\x1dB\x01\x1d!\x11\x1bE\x01 LIEFERANDO \n\x1dB\x00\x1d!\x00\x1bE\x00\x1ba\x00\n"; }
	if (isset($o['source']) && $o['source'] === 'uber_eats') { $b .= "\x1ba\x01\x1dB\x01\x1d!\x11\x1bE\x01 UBER EATS \n\x1dB\x00\x1d!\x00\x1bE\x00\x1ba\x00\n"; }
	$put('#'.(int)$o['day_no'], 2, true);
	$put($delivery ? 'LIEFERUNG' : 'ABHOLUNG', 1, true);
	$put(($o['scheduled_at'] ? 'geplant ' : '').substr($due, 11, 5), 2, true);
	$put($o['number'].($o['is_test'] ? '  TEST' : ''), 0);
	$rule();
	if (!$full) {
		$who = trim((string)$o['customer_name']).(($delivery && trim((string)$o['zip']) !== '') ? ' - '.$o['zip'] : '');
		if ($who !== '') { $put($who, 1, true); $rule(); }
	} else {
		$put(trim((string)$o['customer_name']), 1, true);
		if ($delivery) {
			$put(trim((string)$o['street']), 1);
			$put(trim($o['zip'].' '.$o['city']), 1);
			if (trim((string)$o['address_note']) !== '') { $put('Hinweis: '.$o['address_note'], 0, true); }
		}
		$rule();
	}
	$cnt = shop_slip_counts($items); $pos = 0;
	foreach ($items as $it) {
		$pos++; $put('Pos '.$pos.' von '.$cnt['pos'], 0); // small line above the dish: a position that is missing shows as a jump in the numbers
		$put((int)$it['qty'].'x '.$it['title'], 1, true);
		if ($it['variation'] !== '') { $put('   '.$it['variation'], 0); }
		foreach ($it['options'] as $op) { $put('   + '.($op['qty'] > 1 ? (int)$op['qty'].'x ' : '').$op['title'], 1); }
		if ($it['note'] !== '') { $put('   >> '.$it['note'].' <<', 1, true); }
		$b .= "\n";
	}
	if ($o['note'] !== '') { $put('>> '.$o['note'].' <<', 1, true); }
	if ($pos !== $cnt['pos']) { error_log('slip: '.$pos.' positions printed, '.$cnt['pos'].' counted'); } // cannot happen: both come from the same list
	$rule();
	$put(shop_slip_control_text($cnt), 0, true);
	$rule();
	if ($full) {
		if (!empty($o['adjust_note'])) { foreach (explode('; ', $o['adjust_note']) as $adj) { $put($adj, 0, true); } }
		$put(in_array($o['payment_method'], array('mollie', 'lieferando', 'uber_eats'), true) ? ($o['payment_method'] === 'lieferando' ? 'bei Lieferando bezahlt' : ($o['payment_method'] === 'uber_eats' ? 'bei Uber Eats bezahlt' : 'online bezahlt')) : ($o['payment_method'] === 'cash' ? 'BAR kassieren: ' : 'KARTE kassieren: ').shop_money($o['total_cents']), 1, true);
		if (!empty($o['pay_with_cents']) && $o['payment_method'] === 'cash') { $put('Gast zahlt mit '.shop_money((int)$o['pay_with_cents']).', Rueckgeld '.shop_money((int)$o['pay_with_cents'] - (int)$o['total_cents']), 0, true); }
		$rule();
	}
	$put('gedruckt '.date('H:i').' Uhr', 0);
	$put('--- ENDE ---', 0, true, 1); // the last thing on the slip: if it is missing, the slip did not arrive completely
	return $b."\n\n\n\x1dV\x42\x00";
}
