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

// the dish block: category headers are ignored (not needed on the ticket), items are "Nx Titel  Preis", their
// modifiers are "- mit Titel  Preis" and may wrap onto the next line(s), which is why this is a small state
// machine rather than one regex per line. "EUR" can trail a wrapped modifier word instead of standing alone
// (a quirk of how the receipt lays text out) so it is stripped before anything else is decided about a line.
function lieferando_parse_items($rawLines) {
	$items = array(); $curIdx = -1; $curOptIdx = -1; $mode = 'idle';
	foreach ($rawLines as $raw) {
		$line = trim(preg_replace('/\s*EUR\s*$/u', '', trim($raw)));
		if ($line === '') { continue; }
		if (preg_match('/^(\d+)\s*[xX]\s+(.+)$/u', $line, $m)) {
			$rest = trim($m[2]); $unit = null;
			if (preg_match('/^(.*\S)\s+(\d+[.,]\d{2})$/u', $rest, $pm)) { $rest = trim($pm[1]); $unit = (int)round((float)str_replace(',', '.', $pm[2]) * 100); }
			$items[] = array('qty' => max(1, (int)$m[1]), 'title' => mb_substr($rest, 0, 160), 'options' => array(), 'unit_cents' => $unit);
			$curIdx = count($items) - 1; $curOptIdx = -1; $mode = 'item';
			continue;
		}
		if ($line[0] === '-' && $curIdx >= 0) {
			$rest = trim(substr($line, 1));
			if (preg_match('/^(.*\S)\s+(\d+[.,]\d{2})$/u', $rest, $pm)) { $rest = trim($pm[1]); }
			$text = trim(preg_replace('/^mit\s*/iu', '', $rest));
			$items[$curIdx]['options'][] = $text;
			$curOptIdx = count($items[$curIdx]['options']) - 1; $mode = 'option_open';
			continue;
		}
		if ($mode === 'option_open' && $curIdx >= 0 && $curOptIdx >= 0) {
			$items[$curIdx]['options'][$curOptIdx] = trim($items[$curIdx]['options'][$curOptIdx].' '.$line);
			continue;
		}
		// a category header (e.g. "Fleischgerichte"): not needed on the ticket, just closes the current context
		$mode = 'idle';
	}
	$out = array();
	foreach ($items as $it) {
		$opts = array();
		foreach ($it['options'] as $t) {
			$t = trim($t);
			if ($t === '') { continue; }
			$opts[] = (stripos($t, 'mit') === 0) ? $t : 'mit '.$t;
		}
		$out[] = array('qty' => $it['qty'], 'title' => $it['title'], 'options' => $opts, 'unit_cents' => $it['unit_cents']);
	}
	return $out;
}

/*
 * Reads the receipt's plain text (from Smalot\PdfParser) into what mySeat needs. Returns:
 * external_id, placed_at ('Y-m-d H:i:s' or null), confirmed_at (the "Bestätigte Uhrzeit", same format or null), type ('delivery'|'pickup'), customer_name, items (see
 * lieferando_parse_items), note (the guest's "Anmerkungen", '' if none), payment_status ('paid'|'cod'), total_cents.
 */
function lieferando_parse_text($text) {
	$out = array('external_id' => '', 'placed_at' => null, 'confirmed_at' => null, 'type' => 'delivery', 'customer_name' => '', 'items' => array(), 'note' => '', 'payment_status' => 'cod', 'total_cents' => null);

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
	if (stripos($text, 'bezahlt') !== false) { $out['payment_status'] = 'paid'; }

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
	if (isset($blocks[2])) { $out['items'] = lieferando_parse_items($blocks[2]); }
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
	$total = $p['total_cents'] !== null ? $p['total_cents'] : $sub;

	$token = bin2hex(random_bytes(16));
	$number = shop_order_number();
	$today = date('Y-m-d');
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$now = date('Y-m-d H:i:s');
	$db = fb_db();
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, customer_name, phone, note, subtotal_cents, fee_cents, tip_cents, total_cents,
		 payment_method, payment_status, lang, is_test, source, external_ref, created_at, updated_at, accepted_at, eta_at)
		VALUES (?, ?, ?, ?, ?, 'accepted', ?, '', ?, ?, 0, 0, ?, 'lieferando', ?, 'de', 0, 'lieferando', ?, ?, ?, ?, ?)",
		'ssissssiissssss', array($token, $number, $dayNo, $today, $p['type'], $p['customer_name'], $p['note'], $sub, $total,
			$p['payment_status'] === 'paid' ? 'paid' : 'open', $p['external_id'], $now, $now, $now, $p['confirmed_at']));
	if (!$ok) { return array('ok' => false, 'error' => 'Die Bestellung ('.$p['external_id'].') konnte nicht gespeichert werden.'); }
	$id = (int)mysqli_insert_id($db);
	foreach ($p['items'] as $it) {
		$productId = lieferando_find_product($it['title']);
		$unit = $it['unit_cents'] ?: 0;
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, ?, ?, '', ?, ?, ?, ?, '')",
			'iissiii', array($id, $productId, $it['title'], json_encode(array_map(function ($t) { return array('title' => $t, 'qty' => 1); }, $it['options']), JSON_UNESCAPED_UNICODE),
				$it['qty'], $unit, $unit * $it['qty']));
	}
	shop_log($id, 'created', 'lieferando '.$p['external_id']);
	return array('ok' => true, 'id' => $id, 'day_no' => $dayNo, 'number' => $number, 'type' => $p['type'], 'items' => count($p['items']));
}
