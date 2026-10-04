<?php
/*
 * Import of a Lieferando order receipt PDF into the delivery shop (order/lieferando_import.php): n8n watches a
 * Nextcloud folder for the PDF that gets created when the order is accepted on the Lieferando tablet, and POSTs
 * it here. We only need two things out of the receipt: pickup vs. delivery, and the dishes for the kitchen
 * monitor — the exact delivery address is intentionally NOT extracted (it is only in a QR code); Lieferando's
 * own courier app keeps handling the actual delivery.
 *
 * The receipt is a real text PDF (embedded font, no OCR needed) laid out as fixed blocks separated by a line of
 * underscores: 0 = restaurant header + order code + timestamp, 1 = pickup/delivery + confirmed time + customer,
 * 2 = the dishes (category headers, "2x Titel  12.90", modifier lines "- mit Titel  0.00", wrapped over several
 * lines), 3 = "Gesamt", 4 = payment status/method, then optionally "Anmerkungen: ..." (the guest's note) and
 * finally the "keine Rechnung" / QR footer. lieferando_parse_text() reads this structure; lieferando_import()
 * turns it into a normal tp_shop_orders row (source = 'lieferando') so it shows up on the kitchen monitor like
 * any other order. external_ref (the Lieferando order code) makes a second import of the same PDF a no-op.
 */
require_once __DIR__.'/feedback.class.php';
require_once __DIR__.'/shop.class.php';
require_once __DIR__.'/../../vendor/autoload.php';

function lieferando_api_key() {
	global $settings;
	return !empty($settings['lieferandoApiKey']) ? (string)$settings['lieferandoApiKey'] : '';
}

// splits the receipt text into the blocks between lines of underscores (kept in order, first/last may be empty)
function lieferando_split_blocks($text) {
	$lines = preg_split('/\r\n|\r|\n/', (string)$text);
	$blocks = array(); $cur = array();
	foreach ($lines as $l) {
		if (preg_match('/^_{5,}$/', trim($l))) { $blocks[] = $cur; $cur = array(); }
		else { $cur[] = $l; }
	}
	$blocks[] = $cur;
	return $blocks;
}

function lieferando_nonempty($lines) {
	$out = array();
	foreach ($lines as $l) { $t = trim($l); if ($t !== '') { $out[] = $t; } }
	return $out;
}

// The dish block. The receipt is 31 characters wide, and it tells what a line is by its indent: an item "Nx Titel  Preis" and a category header start in column 0,
// an option " - mit Titel  Preis" in column 1, and everything indented further belongs to the item above. The price has two rows: the price, and below it "EUR". If the
// title or option is too long it wraps beside the "EUR" row (and further down): that is part of the title. If "EUR" stands alone, the title was complete, and every
// further indented line is a note of the guest to that item ("Bitte schneiden"). Nothing is dropped: a line that cannot be placed goes to $unplaced.
function lieferando_join_text($a, $b) {
	$last = preg_match('/(\S+)$/u', $a, $m) ? $m[1] : '';
	// a word that was cut in the middle by the line width ("Sambal-Hollandaisesa" + "uce")
	if (mb_strlen($last) >= 18 && !preg_match('/[,.;:)\-]$/u', $a) && preg_match('/^[a-zäöüß]{1,6}$/u', $b)) { return $a.$b; }
	return $a.' '.$b;
}

function lieferando_parse_items($rawLines, &$unplaced = null) {
	$unplaced = array();
	$items = array(); $cur = -1; $ctx = 'none'; $closed = false;
	foreach ($rawLines as $raw) {
		$hadEur = (bool)preg_match('/\s*EUR\s*$/u', rtrim($raw));
		$raw = rtrim(preg_replace('/\s*EUR\s*$/u', '', rtrim($raw)));
		if (trim($raw) === '') { if ($hadEur && ($ctx === 'title' || $ctx === 'option')) { $closed = true; } continue; } // "EUR" alone: the title or option above is complete
		$indent = mb_strlen($raw) - mb_strlen(ltrim($raw));
		$line = trim($raw);
		if ($indent <= 1 && preg_match('/^(\d+)\s*[xX]\s+(.+)$/u', $line, $m)) {
			$rest = trim($m[2]); $unit = 0; $text = $raw;
			if (preg_match('/^(.*\S)\s+(\d+[.,]\d{2})$/u', $raw, $pm)) { $text = $pm[1]; $rest = trim(preg_replace('/^\d+\s*[xX]\s+/u', '', $pm[1])); $unit = (int)round((float)str_replace(',', '.', $pm[2]) * 100); }
			$items[] = array('qty' => max(1, (int)$m[1]), 'title' => $rest, 'options' => array(), 'base_cents' => $unit, 'note' => '');
			$cur = count($items) - 1; $ctx = 'title'; $closed = false;
			continue;
		}
		if ($indent <= 2 && $line[0] === '-' && $cur >= 0) {
			$rest = trim(substr($line, 1)); $price = 0; $text = $raw;
			if (preg_match('/^(.*\S)\s+(\d+[.,]\d{2})$/u', $raw, $pm)) { $text = $pm[1]; $rest = trim(substr(trim($pm[1]), 1)); $price = (int)round((float)str_replace(',', '.', $pm[2]) * 100); }
			$items[$cur]['options'][] = array('title' => $rest, 'price_cents' => $price);
			$ctx = 'option'; $closed = false;
			continue;
		}
		if ($indent === 0) { $ctx = 'none'; continue; } // a category header ("Fleischgerichte"): not needed on the ticket
		if ($cur < 0 || $ctx === 'none') {
			if ($cur >= 0) { $items[$cur]['note'] = $items[$cur]['note'] === '' ? $line : $items[$cur]['note'].' '.$line; $ctx = 'note'; }
			else { $unplaced[] = $line; }
			continue;
		}
		if ($ctx === 'note' || $closed) {
			$items[$cur]['note'] = $items[$cur]['note'] === '' ? $line : lieferando_join_text($items[$cur]['note'], $line); $ctx = 'note';
		} elseif ($ctx === 'option') {
			$k = count($items[$cur]['options']) - 1;
			$items[$cur]['options'][$k]['title'] = lieferando_join_text($items[$cur]['options'][$k]['title'], $line);
		} else {
			$items[$cur]['title'] = lieferando_join_text($items[$cur]['title'], $line);
		}
	}
	$out = array();
	foreach ($items as $it) {
		$opts = array(); $optSum = 0;
		foreach ($it['options'] as $o) {
			$t = trim($o['title']);
			if ($t === '') { continue; }
			$opts[] = array('title' => (stripos($t, 'mit') === 0) ? $t : 'mit '.$t, 'price_cents' => $o['price_cents']);
			$optSum += $o['price_cents'];
		}
		$out[] = array('qty' => $it['qty'], 'title' => mb_substr($it['title'], 0, 160), 'options' => $opts, 'unit_cents' => $it['base_cents'] + $optSum, 'note' => mb_substr($it['note'], 0, 250));
	}
	return $out;
}

// "Wichtig:" block at the end of the order data: who has to pay what. Only this block counts, because the words "bezahlt" and "Bezahlt mit" also stand
// in "nicht bezahlt worden" and "Bezahlt mit : 40.00 EUR" (a cash order). Anything unclear counts as open, so the driver collects rather than forgets to.
function lieferando_parse_payment($text, $total) {
	$r = array('payment_status' => 'cod', 'payment_method' => 'cash', 'pay_with_cents' => 0, 'warning' => '');
	if (!preg_match('/Wichtig\s*:(.*?)(?:_{5,}|$)/su', $text, $m)) { $r['warning'] = 'Zahlung nicht gefunden'; return $r; }
	$w = $m[1];
	if (preg_match('/nicht\s+bezahlt/iu', $w)) {
		if (preg_match('/Zahlung\s+Bar/iu', $w)) {
			if (preg_match('/Bezahlt\s+mit\s*:?\s*(\d+[.,]\d{2})/iu', $w, $pm)) {
				$given = (int)round((float)str_replace(',', '.', $pm[1]) * 100);
				if ($total !== null && $given > $total) { $r['pay_with_cents'] = $given; }
			}
		} elseif (preg_match('/Zahlung\s+(?:Karte|EC|Kreditkarte)/iu', $w)) { $r['payment_method'] = 'card_door'; }
		else { $r['warning'] = 'Zahlungsart unklar'; }
	} elseif (preg_match('/online\s+bezahlt/iu', $w) || preg_match('/Zahlung\s+Online/iu', $w)) {
		$r['payment_status'] = 'paid'; $r['payment_method'] = 'lieferando';
	} else { $r['warning'] = 'Zahlung unklar'; }
	return $r;
}

/*
 * Reads the receipt's plain text (from Smalot\PdfParser) into what mySeat needs. Returns:
 * external_id, placed_at ('Y-m-d H:i:s' or null), confirmed_at (the "Bestätigte Uhrzeit", same format or null), type ('delivery'|'pickup'), customer_name, items (see
 * lieferando_parse_items), note (the guest's "Anmerkungen", '' if none), payment_status ('paid'|'cod'), payment_method ('lieferando'|'cash'|'card_door'), pay_with_cents,
 * fee_cents (delivery and service fee), tip_cents, total_cents, warnings (what has to be looked at by a person).
 */
function lieferando_parse_text($text) {
	$out = array('external_id' => '', 'placed_at' => null, 'confirmed_at' => null, 'type' => 'delivery', 'customer_name' => '', 'items' => array(), 'note' => '', 'payment_status' => 'cod',
		'payment_method' => 'cash', 'pay_with_cents' => 0, 'fee_cents' => 0, 'tip_cents' => 0, 'total_cents' => null, 'warnings' => array());

	if (preg_match('/([A-Z0-9]{5,8})\s*\n\s*(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2})/u', $text, $m)) {
		$out['external_id'] = $m[1];
		$out['placed_at'] = $m[2].'-'.$m[3].'-'.$m[4].' '.$m[5].':'.$m[6].':00';
	}
	if (preg_match('/^\s*(Lieferung|Abholung)\s*$/mu', $text, $m)) { $out['type'] = (stripos($m[1], 'Abhol') === 0) ? 'pickup' : 'delivery'; }
	// "Bestätigte Uhrzeit" is the time Lieferando promised the guest: that is when the order has to be there (or ready for pickup). The date is the day of
	// the receipt; a time that lies clearly before the order time is after midnight.
	if (preg_match('/Bestätigte\s+Uhrzeit\s*(\d{1,2}):(\d{2})/u', $text, $m)) {
		$base = $out['placed_at'] ? substr($out['placed_at'], 0, 10) : date('Y-m-d');
		$ts = strtotime($base.' '.sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]));
		if ($ts !== false && $out['placed_at'] && $ts < strtotime($out['placed_at']) - 1800) { $ts += 86400; }
		if ($ts !== false) { $out['confirmed_at'] = date('Y-m-d H:i:s', $ts); }
	}
	if (preg_match('/Gesamt\s+(\d+[.,]\d{2})/u', $text, $m)) { $out['total_cents'] = (int)round((float)str_replace(',', '.', $m[1]) * 100); }
	$pay = lieferando_parse_payment($text, $out['total_cents']);
	$out['payment_status'] = $pay['payment_status']; $out['payment_method'] = $pay['payment_method']; $out['pay_with_cents'] = $pay['pay_with_cents'];
	if ($pay['warning'] !== '') { $out['warnings'][] = $pay['warning']; }

	$blocks = lieferando_split_blocks($text);
	if (isset($blocks[1])) {
		$lines = lieferando_nonempty($blocks[1]);
		foreach ($lines as $i => $l) {
			if ($i === 0) { continue; } // the type, already read above
			if (stripos($l, 'Bestätigte') !== false || preg_match('/^\d{2}:\d{2}$/', $l)) { continue; }
			$out['customer_name'] = mb_substr($l, 0, 120);
			break;
		}
	}
	$unplaced = array();
	if (isset($blocks[2])) { $out['items'] = lieferando_parse_items($blocks[2], $unplaced); }
	if ($unplaced) { $out['warnings'][] = 'Zeilen nicht zugeordnet: '.implode(' / ', $unplaced); }
	// fees and the like between the dishes and "Gesamt" (Lieferkosten, Servicegebühr, Trinkgeld, Rabatt ...)
	$other = 0;
	foreach ($blocks as $i => $b) {
		if ($i < 2) { continue; }
		$buf = '';
		foreach (preg_split('/\r\n|\r|\n/', implode("\n", $b)) as $l) {
			$l = trim(preg_replace('/\s*EUR\s*$/u', '', rtrim($l)));
			if ($l === '' || !preg_match('/^([^\d].*\S)\s+(-?\d+[.,]\d{2})$/u', $l, $pm) || preg_match('/Bezahlt\s+mit|^Anmerkungen/iu', $l)) { continue; }
			$cents = (int)round((float)str_replace(',', '.', $pm[2]) * 100);
			if (preg_match('/^Gesamt/iu', $pm[1])) { continue; }
			if ($i === 2) { continue; } // the dishes (prices of items and options)
			if (preg_match('/Lieferkosten|Liefergeb|Servicegeb|Servicekosten|Bearbeitungsgeb/iu', $pm[1])) { $out['fee_cents'] += $cents; }
			elseif (preg_match('/Trinkgeld/iu', $pm[1])) { $out['tip_cents'] += $cents; }
			else { $other += $cents; $out['warnings'][] = 'Posten "'.trim($pm[1]).'" '.number_format($cents / 100, 2, ',', '').' €'; }
		}
	}
	// what the dishes cost: the price of an item is read as per piece; if that does not add up to the total, as the price of the line
	if ($out['total_cents'] !== null && $out['items']) {
		$perPiece = 0; $perLine = 0;
		foreach ($out['items'] as $it) { $perPiece += $it['unit_cents'] * $it['qty']; $perLine += $it['unit_cents']; }
		$want = $out['total_cents'] - $out['fee_cents'] - $out['tip_cents'] - $other;
		if ($perPiece !== $want) {
			if ($perLine === $want) { foreach ($out['items'] as &$it) { $it['unit_cents'] = (int)round($it['unit_cents'] / max(1, $it['qty'])); } unset($it); }
			else { $out['warnings'][] = 'Summe stimmt nicht (Artikel '.number_format($perPiece / 100, 2, ',', '').' €, Beleg '.number_format($want / 100, 2, ',', '').' €)'; }
		}
	}
	foreach ($blocks as $i => $b) {
		if ($i < 3) { continue; }
		$lines = lieferando_nonempty($b);
		if (!$lines) { continue; }
		if (preg_match('/^Anmerkungen\s*:?\s*(.*)$/iu', $lines[0], $m)) {
			$parts = array($m[1]);
			for ($j = 1; $j < count($lines); $j++) { $parts[] = $lines[$j]; }
			$out['note'] = mb_substr(trim(implode(' ', array_filter($parts, function ($p) { return $p !== ''; }))), 0, 500);
			break;
		}
	}
	return $out;
}

// best-effort link to the real menu item so it counts towards stats/upsell learning like any other order; the
// kitchen ticket shows the receipt's own title regardless, so a miss here loses nothing but that link
function lieferando_find_product($title) {
	$row = fb_row("SELECT id FROM ".fb_t('tp_shop_products')." WHERE active = 1 AND LOWER(title) = LOWER(?) LIMIT 1", 's', array($title));
	return $row ? (int)$row['id'] : null;
}

/*
 * Parses $pdfBytes (the raw file content) and, unless this order code was already imported, creates the
 * tp_shop_orders row + items. Returns array('ok'=>true, 'id', 'day_no', 'number', 'duplicate') or array('ok'=>false, 'error').
 */
function lieferando_import($pdfBytes) {
	shop_ensure_schema();
	if (!class_exists('Smalot\\PdfParser\\Parser')) { return array('ok' => false, 'error' => 'PDF-Bibliothek (vendor/) fehlt auf dem Server.'); }
	try {
		$parser = new \Smalot\PdfParser\Parser();
		$text = $parser->parseContent($pdfBytes)->getText();
	} catch (Throwable $e) {
		return array('ok' => false, 'error' => 'Die PDF ließ sich nicht lesen: '.$e->getMessage());
	}
	$p = lieferando_parse_text($text);
	if ($p['external_id'] === '') { return array('ok' => false, 'error' => 'Keine Lieferando-Bestellnummer in der PDF gefunden.'); }

	$existing = fb_row("SELECT id, day_no, number FROM ".fb_t('tp_shop_orders')." WHERE external_ref = ?", 's', array($p['external_id']));
	if ($existing) { return array('ok' => true, 'duplicate' => true, 'id' => (int)$existing['id'], 'day_no' => (int)$existing['day_no'], 'number' => $existing['number']); }
	if (!$p['items']) { return array('ok' => false, 'error' => 'Keine Artikel in der PDF gefunden ('.$p['external_id'].').'); }

	$sub = 0;
	foreach ($p['items'] as $it) { $sub += ($it['unit_cents'] ?: 0) * $it['qty']; }
	$total = $p['total_cents'] !== null ? $p['total_cents'] : $sub + $p['fee_cents'] + $p['tip_cents'];
	// what a person has to look at is written in front of the note of the order, so it is seen on the kitchen monitor and on the slip
	$note = $p['note'];
	if ($p['warnings']) { $note = trim('[Beleg prüfen: '.implode('; ', $p['warnings']).'] '.$note); }

	$token = bin2hex(random_bytes(16));
	$number = shop_order_number();
	$today = date('Y-m-d');
	shop_dayno_lock();
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$now = date('Y-m-d H:i:s');
	// the time of the order is the one printed on the receipt (the import can come minutes later); only if it is from today and not in the future
	$created = ($p['placed_at'] && substr($p['placed_at'], 0, 10) === $today && strtotime($p['placed_at']) <= time() + 300) ? $p['placed_at'] : $now;
	$db = fb_db();
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, customer_name, phone, note, subtotal_cents, fee_cents, tip_cents, total_cents,
		 payment_method, payment_status, lang, is_test, source, external_ref, created_at, updated_at, accepted_at, eta_at, pay_with_cents)
		VALUES (?, ?, ?, ?, ?, 'accepted', ?, '', ?, ?, ?, ?, ?, ?, ?, 'de', 0, 'lieferando', ?, ?, ?, ?, ?, ?)",
		'ssissssiiiisssssssi', array($token, $number, $dayNo, $today, $p['type'], $p['customer_name'], $note, $sub, $p['fee_cents'], $p['tip_cents'], $total,
			$p['payment_method'], $p['payment_status'] === 'paid' ? 'paid' : 'open', $p['external_id'], $created, $now, $now, $p['confirmed_at'], $p['pay_with_cents'] ?: null));
	if (!$ok) { shop_dayno_unlock(); return array('ok' => false, 'error' => 'Die Bestellung ('.$p['external_id'].') konnte nicht gespeichert werden.'); }
	$id = (int)mysqli_insert_id($db); shop_dayno_unlock();
	foreach ($p['items'] as $it) {
		$productId = lieferando_find_product($it['title']);
		$unit = $it['unit_cents'] ?: 0;
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?)",
			'iissiiis', array($id, $productId, $it['title'], json_encode(array_map(function ($o) { return array('title' => $o['title'], 'qty' => 1, 'price_cents' => $o['price_cents']); }, $it['options']), JSON_UNESCAPED_UNICODE),
				$it['qty'], $unit, $unit * $it['qty'], $it['note']));
	}
	shop_log($id, 'created', 'lieferando '.$p['external_id'].($p['warnings'] ? ' - prüfen: '.implode('; ', $p['warnings']) : ''));
	return array('ok' => true, 'id' => $id, 'day_no' => $dayNo, 'number' => $number, 'type' => $p['type'], 'items' => count($p['items']));
}
