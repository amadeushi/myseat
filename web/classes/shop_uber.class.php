<?php
/*
 * Reading the Uber Eats receipts (the images that order/uber_import.php stores in tp_shop_uber_slips): the image goes to a webhook in n8n (setting uber_read_url), which lets a vision model
 * of OpenAI read it and answers with JSON in the format of shop_uber_schema(); the prompt and the schema are kept here, n8n only passes them on. Nothing is trusted blindly: shop_uber_check()
 * adds the lines up (items plus options against the "Zwischensumme", then fee and discount against the amount paid), and a receipt that does not add up is read a second time with the stronger
 * model of n8n (tier "large", if setting uber_read_large is on) and, if it still does not add up, is marked "check" for a person. Status of a slip: new, read (adds up), check, error, imported (an order exists, tp_shop_uber_slips.order_id).
 *
 * What the receipts look like (13 samples of 2026-10-05, the archive version without address and phone; the version printed when an order arrives has address, phone and surname):
 * "Uber Eats LIEFERUNG", name and order code (5 characters) in a black bar, "2 x Pizzabrot mit Soße nach Wahl  13,40 €" with indented options below ("WÄHLE DEINE SAUCE" / "1x Knoblauchsauce  0,00 €"),
 * Zwischensumme, Liefergebühr, optional more fee lines ("Marketplace-Gebühr (Gebühren von Uber)" 1,49 €, seen on one of the 13 samples), then the amount: "Gezahlter Betrag" (paid online) or
 * "Fälliger Bargeldbetrag" (cash due, the driver collects it), "Bestellt am October 5, 4:57 PM", "Fällig um". The price of a line is that of the whole line; the price of an option is per piece, so an option
 * of a "2 x" line counts twice (15.70 + 13.40 + 15.70 + 2 x 2.00 + 1.00 = 49.80 in the first sample).
 */
require_once __DIR__.'/shop.class.php';

function shop_uber_read_url() { return trim((string)shop_setting('uber_read_url')); }
// the secret n8n checks (header X-Uber-Secret, "Header Auth" credential there): made on first use, shown in the backend next to the address
function shop_uber_read_secret() {
	$k = (string)shop_setting('uber_read_secret');
	if (strlen($k) < 32) { $k = bin2hex(random_bytes(24)); shop_setting_set('uber_read_secret', $k); }
	return $k;
}

function shop_uber_prompt() {
	return "Das Bild ist ein gedruckter Bestellbon von Uber Eats für ein Restaurant. Lies ihn und gib die Daten im vorgegebenen Format zurück. Heutiges Datum: ".date('Y-m-d').".\n"
		."Regeln:\n"
		."- Schreibe Texte genau so ab, wie sie gedruckt sind (Schreibweise, Umlaute, ß). Erfinde nichts. Was nicht auf dem Bon steht, ist null (oder eine leere Liste).\n"
		."- Beträge sind ganze Cent: 15,70 € ist 1570.\n"
		."- kind: \"delivery\" bei LIEFERUNG, \"pickup\" bei ABHOLUNG. order_code und customer_name stehen im schwarzen Balken (der Code rechts, der Name links).\n"
		."- items: Jede Zeile mit Menge (\"2 x\") und fettem Titel ist ein Gericht; qty ist die Menge, price_cents der Preis ganz rechts in genau dieser Zeile. Die eingerückten Zeilen darunter sind Optionen dieses Gerichts: group ist die Überschrift in Großbuchstaben darüber (z. B. \"WÄHLE DEINE SAUCE\"), qty die Zahl vor dem \"x\", title der Text, price_cents der Preis rechts. note ist ein gedruckter Hinweis zum Gericht.\n"
		."- note (oben): Anmerkung des Gastes zur Bestellung, wenn gedruckt. phone, street, zip, city nur, wenn gedruckt.\n"
		."- subtotal_cents ist die Zwischensumme, fee_cents die Liefergebühr, discount_cents Rabatte (als positive Zahl), tip_cents Trinkgeld.\n"
		."- other_fees: jede weitere Gebührenzeile zwischen Zwischensumme und Endbetrag, die nicht die Liefergebühr ist (zum Beispiel \"Marketplace-Gebühr (Gebühren von Uber)\", \"Servicegebühr\", \"Kleinmengenzuschlag\"), jeweils label (der gedruckte Text, ohne Klammerzusatz in der nächsten Zeile) und cents. Keine solche Zeile: leere Liste.\n"
		."- total_cents ist der große Endbetrag unten (bei \"Gezahlter Betrag\", \"Fälliger Bargeldbetrag\" oder \"Gesamtbetrag\"). payment ist \"cash_due\", wenn \"Fälliger Bargeldbetrag\" gedruckt ist (der Fahrer kassiert bar), \"paid_online\", wenn \"Gezahlter Betrag\" oder eine Online-Zahlung gedruckt ist, sonst null.\n"
		."- ordered_text ist der Text hinter \"Bestellt am\" genau so, wie er gedruckt ist (z. B. \"October 6, 8:01 PM\"), due_text der Text hinter \"Lieferung bis\" oder \"Fällig um\" genau so gedruckt (z. B. \"October 6, 9:49 PM\"), null wenn dahinter nichts steht. ordered_at ist \"Bestellt am\" als JJJJ-MM-TT HH:MM in 24 Stunden (Jahr: das heutige, wenn keins gedruckt ist), due_at die Uhrzeit als HH:MM, sonst null.\n"
		."- unclear: Liste der Stellen, die du nicht sicher lesen konntest (leer, wenn alles klar ist).";
}

function shop_uber_schema() {
	$s = function ($t = 'string') { return array('type' => array($t, 'null')); };
	$obj = function ($props) { return array('type' => 'object', 'additionalProperties' => false, 'required' => array_keys($props), 'properties' => $props); };
	$option = $obj(array('group' => $s(), 'qty' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'price_cents' => $s('integer')));
	$item = $obj(array('qty' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'price_cents' => $s('integer'), 'note' => $s(), 'options' => array('type' => 'array', 'items' => $option)));
	return $obj(array(
		'order_code' => $s(), 'kind' => array('type' => array('string', 'null'), 'enum' => array('delivery', 'pickup', null)), 'customer_name' => $s(), 'phone' => $s(),
		'street' => $s(), 'zip' => $s(), 'city' => $s(), 'note' => $s(), 'items' => array('type' => 'array', 'items' => $item),
		'subtotal_cents' => $s('integer'), 'fee_cents' => $s('integer'), 'discount_cents' => $s('integer'), 'tip_cents' => $s('integer'),
		'other_fees' => array('type' => 'array', 'items' => $obj(array('label' => array('type' => 'string'), 'cents' => array('type' => 'integer')))),
		'total_cents' => $s('integer'), 'payment' => array('type' => array('string', 'null'), 'enum' => array('paid_online', 'cash_due', null)),
		'ordered_at' => $s(), 'due_at' => $s(), 'ordered_text' => $s(), 'due_text' => $s(), 'unclear' => array('type' => 'array', 'items' => array('type' => 'string'))));
}

// the receipt goes to the model twice as wide: on the 512 dots of the printer image it read a "6" as a "5" (twice, with the same receipt); more pixels per letter make it surer. Without GD as it is.
function shop_uber_enlarge($png) {
	if (!function_exists('imagecreatefromstring') || !function_exists('imagescale') || !function_exists('imagepng')) { return $png; }
	$im = @imagecreatefromstring($png);
	if (!$im) { return $png; }
	$w = imagesx($im); $h = imagesy($im);
	if ($w >= 900 || $w < 1 || $h < 1) { imagedestroy($im); return $png; }
	$tc = imagecreatetruecolor($w, $h); imagecopy($tc, $im, 0, 0, 0, 0, $w, $h); imagedestroy($im);
	$big = imagescale($tc, $w * 2, $h * 2, IMG_BICUBIC); imagedestroy($tc);
	if (!$big) { return $png; }
	ob_start(); imagepng($big, null, 6); $out = ob_get_clean(); imagedestroy($big);
	return ($out !== '' && $out !== false) ? $out : $png;
}

// sends the image to n8n; $tier is "small" or "large" (n8n picks the model). array('ok', 'data' => the JSON of the receipt, 'model')
function shop_uber_call($png, $tier) {
	$url = shop_uber_read_url();
	if ($url === '') { return array('ok' => false, 'error' => 'Die Adresse des n8n-Webhooks ist nicht eingetragen.'); }
	$body = json_encode(array('image_b64' => base64_encode(shop_uber_enlarge($png)), 'prompt' => shop_uber_prompt(), 'schema' => shop_uber_schema(), 'tier' => $tier), JSON_UNESCAPED_UNICODE);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 90,
		CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Uber-Secret: '.shop_uber_read_secret())));
	$raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); curl_close($ch);
	if ($errno) { return array('ok' => false, 'error' => 'n8n nicht erreichbar.'); }
	$j = json_decode((string)$raw, true);
	if ($code !== 200 || !is_array($j)) { return array('ok' => false, 'error' => 'n8n antwortet mit HTTP '.$code.'.'); }
	if (empty($j['ok']) || !isset($j['data']) || !is_array($j['data'])) { return array('ok' => false, 'error' => 'Lesen fehlgeschlagen: '.(isset($j['error']) ? mb_substr((string)$j['error'], 0, 200) : 'keine Daten')); }
	return array('ok' => true, 'data' => $j['data'], 'model' => isset($j['model']) ? (string)$j['model'] : '');
}

/*
 * "Bestellt am" and "Lieferung bis" / "Fällig um" as they are printed ("October 6, 8:01 PM", "6. Oktober 2026 um 20:01", "9:49 PM"): the day and the time are taken out of the text here, the model
 * only copies the text (it once turned "October 6" into the 5th). Returns 'Y-m-d H:i' or null. Without a day in the text the day of $base ('Y-m-d') is taken, without a year the current one
 * (a day that would lie more than a day ahead is last year's).
 */
function shop_uber_when($text, $base = null) {
	$t = trim((string)$text);
	// the time: 8:01 PM / 20:01 (a dot instead of the colon only when no date with dots stands beside it)
	$tre = '/(\d{1,2}):(\d{2})\s*([AaPp]\.?[Mm]\.?)?/u';
	if ($t === '') { return null; }
	if (!preg_match($tre, $t, $tm)) { $tre = '/(?<![\d.])(\d{1,2})\.(\d{2})(?![\d.])\s*([AaPp]\.?[Mm]\.?)?/u'; if (!preg_match($tre, $t, $tm)) { return null; } }
	$h = (int)$tm[1]; $mi = (int)$tm[2];
	if (!empty($tm[3])) { $pm = (stripos($tm[3], 'p') === 0); if ($h === 12) { $h = $pm ? 12 : 0; } elseif ($pm && $h < 12) { $h += 12; } }
	if ($h > 23 || $mi > 59) { return null; }
	$months = array('january' => 1, 'jan' => 1, 'januar' => 1, 'february' => 2, 'feb' => 2, 'februar' => 2, 'march' => 3, 'mar' => 3, 'märz' => 3, 'maerz' => 3, 'april' => 4, 'apr' => 4, 'may' => 5, 'mai' => 5,
		'june' => 6, 'jun' => 6, 'juni' => 6, 'july' => 7, 'jul' => 7, 'juli' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9, 'october' => 10, 'oct' => 10, 'oktober' => 10,
		'okt' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12, 'dezember' => 12, 'dez' => 12);
	$dp = preg_replace($tre, ' ', $t, 1);
	$day = null; $mon = null; $year = null;
	if (preg_match('/(\d{1,2})\.?\s+([A-Za-zÄÖÜäöü]+)\.?(?:,?\s*(\d{4}))?/u', $dp, $m) && isset($months[mb_strtolower($m[2])])) { $day = (int)$m[1]; $mon = $months[mb_strtolower($m[2])]; $year = (isset($m[3]) && $m[3] !== '') ? (int)$m[3] : null; }
	elseif (preg_match('/([A-Za-zÄÖÜäöü]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s*(\d{4}))?/u', $dp, $m) && isset($months[mb_strtolower($m[1])])) { $day = (int)$m[2]; $mon = $months[mb_strtolower($m[1])]; $year = (isset($m[3]) && $m[3] !== '') ? (int)$m[3] : null; }
	elseif (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})?/', $dp, $m)) { $day = (int)$m[1]; $mon = (int)$m[2]; $year = !empty($m[3]) ? (int)$m[3] : null; }
	if ($day === null) {
		$b = ($base !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $base)) ? substr($base, 0, 10) : date('Y-m-d');
		return $b.' '.sprintf('%02d:%02d', $h, $mi);
	}
	$explicit = $year !== null; if ($year === null) { $year = (int)date('Y'); }
	if (!checkdate($mon, $day, $year)) { return null; }
	if (!$explicit && mktime(0, 0, 0, $mon, $day, $year) > time() + 86400) { $year--; if (!checkdate($mon, $day, $year)) { return null; } }
	return sprintf('%04d-%02d-%02d %02d:%02d', $year, $mon, $day, $h, $mi);
}

// when the receipt was ordered and when it is due: out of the printed texts, the other fields of the reading only when there are none (readings from before the texts were asked for)
function shop_uber_times($p) {
	$placed = shop_uber_when(isset($p['ordered_text']) ? $p['ordered_text'] : '');
	if ($placed === null && !empty($p['ordered_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $p['ordered_at'])) { $placed = $p['ordered_at']; }
	$due = shop_uber_when(isset($p['due_text']) ? $p['due_text'] : '', $placed);
	if ($due === null && !empty($p['due_at']) && preg_match('/^\d{2}:\d{2}$/', $p['due_at'])) { $due = ($placed !== null ? substr($placed, 0, 10) : date('Y-m-d')).' '.$p['due_at']; }
	// a time without a day that lies before the order is after midnight
	if ($due !== null && $placed !== null && strtotime($due) < strtotime($placed) - 3600 && substr($due, 0, 10) === substr($placed, 0, 10)) { $due = date('Y-m-d H:i', strtotime($due) + 86400); }
	// one digit wrong in the day of the order (a 6 read as a 5): the order is more than 12 hours before the due time and on another day, but the same time of day on the day of the due time lies
	// at most 3 hours before it, as an order does: that day is taken
	if ($due !== null && $placed !== null && substr($due, 0, 10) !== substr($placed, 0, 10) && strtotime($due) - strtotime($placed) > 12 * 3600) {
		$alt = substr($due, 0, 10).' '.substr($placed, 11, 5);
		if (strtotime($alt) <= strtotime($due) && strtotime($due) - strtotime($alt) <= 3 * 3600) { $placed = $alt; }
	}
	return array('placed' => $placed, 'due' => $due);
}

function shop_uber_money($c) { return number_format($c / 100, 2, ',', '.').' €'; }

// does the receipt add up? array('ok', 'problems' => [...] (they block), 'warnings' => [...] (they do not), 'opt_mult' => whether the options are counted per piece of their line)
function shop_uber_check($d) {
	$problems = array(); $warnings = array();
	$items = (isset($d['items']) && is_array($d['items'])) ? $d['items'] : array();
	if (!$items) { return array('ok' => false, 'problems' => array('Keine Positionen gelesen.'), 'warnings' => array(), 'opt_mult' => null); }
	$base = 0; $optMult = 0; $optPlain = 0;
	foreach ($items as $i => $it) {
		$n = $i + 1; $q = max(1, (int)(isset($it['qty']) ? $it['qty'] : 1));
		if (!isset($it['price_cents']) || $it['price_cents'] === null) { $problems[] = 'Preis fehlt bei Position '.$n; } else { $base += (int)$it['price_cents']; }
		$o = 0;
		foreach ((isset($it['options']) && is_array($it['options'])) ? $it['options'] : array() as $op) {
			if (!isset($op['price_cents']) || $op['price_cents'] === null) { $problems[] = 'Preis einer Option fehlt bei Position '.$n; continue; }
			$o += (int)$op['price_cents'] * max(1, (int)(isset($op['qty']) ? $op['qty'] : 1));
		}
		$optMult += $o * $q; $optPlain += $o;
	}
	$sub = isset($d['subtotal_cents']) ? $d['subtotal_cents'] : null; $optCounted = null;
	if ($sub === null) { $problems[] = 'Zwischensumme fehlt.'; }
	elseif ($base + $optMult === (int)$sub) { $optCounted = true; }
	elseif ($base + $optPlain === (int)$sub) { $optCounted = false; }
	else { $problems[] = 'Die Positionen ergeben '.shop_uber_money($base + $optMult).', die Zwischensumme ist '.shop_uber_money((int)$sub).'.'; }
	$total = isset($d['total_cents']) ? $d['total_cents'] : null;
	if ($total === null) { $problems[] = 'Gesamtbetrag fehlt.'; }
	elseif ($sub !== null) {
		$other = 0;
		foreach ((isset($d['other_fees']) && is_array($d['other_fees'])) ? $d['other_fees'] : array() as $f) { $other += (int)(isset($f['cents']) ? $f['cents'] : 0); }
		$exp = (int)$sub - (int)(isset($d['discount_cents']) ? $d['discount_cents'] : 0) + (int)(isset($d['fee_cents']) ? $d['fee_cents'] : 0) + (int)(isset($d['tip_cents']) ? $d['tip_cents'] : 0);
		if (isset($d['payment']) && $d['payment'] === 'cash_due') {
			// "Fälliger Bargeldbetrag": what Uber states as due is what the driver collects, it overrides the account, and the "Gebühren von Uber" (Marketplace-Gebühr ...) are not put on top of the
			// price. It can be less than everything together (a promotion of Uber), but not more
			if ((int)$total <= 0 || (int)$total > $exp + $other) { $problems[] = 'Der fällige Bargeldbetrag ('.shop_uber_money((int)$total).') passt nicht zur Zwischensumme ('.shop_uber_money((int)$sub).').'; }
			elseif ((int)$total !== $exp) { $warnings[] = 'Fälliger Bargeldbetrag '.shop_uber_money((int)$total).' statt '.shop_uber_money($exp).': der Betrag von Uber gilt.'; }
		} else {
			$exp += $other;
			if ($exp !== (int)$total) { $problems[] = 'Zwischensumme, Gebühren und Rabatt ergeben '.shop_uber_money($exp).', der Endbetrag ist '.shop_uber_money((int)$total).'.'; }
		}
	}
	if (empty($d['order_code'])) { $problems[] = 'Bestellcode fehlt.'; }
	if (empty($d['customer_name'])) { $problems[] = 'Name fehlt.'; }
	if (!empty($d['unclear']) && is_array($d['unclear'])) { $problems[] = 'Unsicher gelesen: '.implode('; ', array_map('strval', $d['unclear'])); }
	if (empty($d['payment'])) { $warnings[] = 'Zahlart unklar (weder Gezahlter Betrag noch Fälliger Bargeldbetrag gelesen).'; }
	if (isset($d['kind']) && $d['kind'] === 'delivery' && (empty($d['street']) || empty($d['phone']))) { $warnings[] = 'Lieferung ohne '.(empty($d['street']) ? 'Adresse' : 'Telefonnummer').'.'; }
	return array('ok' => !$problems, 'problems' => $problems, 'warnings' => $warnings, 'opt_mult' => $optCounted);
}

// reads one stored slip and keeps the outcome in the row. Status: read (adds up), check (a person has to look), error (nothing came back from n8n; tried again with the next receipt).
function shop_uber_process($id, $force = false) {
	shop_ensure_schema();
	if (shop_uber_read_url() === '') { return array('ok' => false, 'error' => 'Die Adresse des n8n-Webhooks ist nicht eingetragen.'); }
	// claimed first (two receipts arriving together must not read the same slip twice, each reading costs); $force = read again whatever the status is
	$st = fb_exec("UPDATE ".fb_t('tp_shop_uber_slips')." SET status = 'reading' WHERE id = ?".($force ? "" : " AND status IN ('new', 'error', 'reading') AND (status <> 'reading' OR received < ?)"),
		$force ? 'i' : 'is', $force ? array((int)$id) : array((int)$id, date('Y-m-d H:i:s', time() - 600)));
	if (!$st || mysqli_stmt_affected_rows($st) < 1) { return array('ok' => false, 'error' => 'Der Bon wird schon gelesen oder ist schon gelesen.', 'skipped' => true); }
	$row = fb_row("SELECT id, png FROM ".fb_t('tp_shop_uber_slips')." WHERE id = ?", 'i', array((int)$id));
	if (!$row) { return array('ok' => false, 'error' => 'Unbekannter Bon.'); }
	$best = null; $errors = array();
	foreach (array('small', 'large') as $tier) {
		if ($tier === 'large' && shop_setting('uber_read_large') !== '1') { break; }
		$r = shop_uber_call($row['png'], $tier);
		if (!$r['ok']) { $errors[] = $tier.': '.$r['error']; continue; }
		$best = array('read' => $r['data'], 'check' => shop_uber_check($r['data']), 'tier' => $tier, 'model' => $r['model'], 'read_at' => date('Y-m-d H:i:s'));
		if ($best['check']['ok']) { break; }
	}
	if (!$best) { $best = array('error' => implode(' | ', $errors), 'read_at' => date('Y-m-d H:i:s')); }
	$status = isset($best['check']) ? ($best['check']['ok'] ? 'read' : 'check') : 'error';
	fb_exec("UPDATE ".fb_t('tp_shop_uber_slips')." SET status = ?, data = ? WHERE id = ?", 'ssi', array($status, json_encode($best, JSON_UNESCAPED_UNICODE), (int)$id));
	return array('ok' => $status !== 'error', 'status' => $status, 'tier' => isset($best['tier']) ? $best['tier'] : '', 'problems' => isset($best['check']) ? $best['check']['problems'] : $errors);
}

// slips that still need reading: the new ones and the failed ones of the last two hours (a short outage of n8n is made up for by the next receipt)
function shop_uber_pending($limit = 3) {
	$rows = fb_rows("SELECT id FROM ".fb_t('tp_shop_uber_slips')." WHERE status = 'new' OR (status IN ('error', 'reading') AND received > ?) ORDER BY id LIMIT ".(int)$limit, 's', array(date('Y-m-d H:i:s', time() - 7200)));
	return array_map(function ($r) { return (int)$r['id']; }, $rows);
}

// the last receipts for the backend page ('stale' = read before the payment was part of the format: it has to be read again): what became of each (no names, no addresses: only state, stage, number of items, amount and the reason when it does not add up)
function shop_uber_recent($limit = 12) {
	$out = array();
	foreach (fb_rows("SELECT s.id, s.received, s.status, s.data, s.order_id, s.ref, o.day_no, o.is_test FROM ".fb_t('tp_shop_uber_slips')." s LEFT JOIN ".fb_t('tp_shop_orders')." o ON o.id = s.order_id ORDER BY s.id DESC LIMIT ".(int)$limit) as $r) {
		$d = $r['data'] ? json_decode($r['data'], true) : null;
		$read = (is_array($d) && isset($d['read']) && is_array($d['read'])) ? $d['read'] : null;
		$why = '';
		if (is_array($d) && isset($d['check']['problems'])) { $why = implode(' ', $d['check']['problems']); }
		elseif (is_array($d) && isset($d['error'])) { $why = (string)$d['error']; }
		// what was read, as short lines for the person who checks it against the receipt (food and prices only: no names, no addresses)
		$lines = array();
		if ($read) {
			foreach ((isset($read['items']) && is_array($read['items'])) ? $read['items'] : array() as $it) {
				$o = array();
				foreach ((isset($it['options']) && is_array($it['options'])) ? $it['options'] : array() as $op) {
					$o[] = ((int)(isset($op['qty']) ? $op['qty'] : 1) > 1 ? (int)$op['qty'].'x ' : '').(isset($op['title']) ? $op['title'] : '?').((isset($op['price_cents']) && $op['price_cents']) ? ' +'.shop_uber_money((int)$op['price_cents']) : '');
				}
				$lines[] = (int)(isset($it['qty']) ? $it['qty'] : 1).' x '.(isset($it['title']) ? $it['title'] : '?').' '.((isset($it['price_cents']) && $it['price_cents'] !== null) ? shop_uber_money((int)$it['price_cents']) : '?').($o ? ' ('.implode(', ', $o).')' : '').((isset($it['note']) && $it['note'] !== null && $it['note'] !== '') ? ' - Hinweis: '.$it['note'] : '');
			}
			$f = array();
			if (isset($read['fee_cents']) && $read['fee_cents']) { $f[] = 'Lieferung '.shop_uber_money((int)$read['fee_cents']); }
			foreach ((isset($read['other_fees']) && is_array($read['other_fees'])) ? $read['other_fees'] : array() as $x) { $f[] = (isset($x['label']) ? $x['label'] : '?').' '.shop_uber_money((int)(isset($x['cents']) ? $x['cents'] : 0)); }
			if (!empty($read['discount_cents'])) { $f[] = 'Rabatt -'.shop_uber_money((int)$read['discount_cents']); }
			if (!empty($read['tip_cents'])) { $f[] = 'Trinkgeld '.shop_uber_money((int)$read['tip_cents']); }
			$pay = isset($read['payment']) ? ($read['payment'] === 'cash_due' ? 'BAR FÄLLIG' : ($read['payment'] === 'paid_online' ? 'online bezahlt' : '')) : '';
			$head = trim((isset($read['kind']) ? ($read['kind'] === 'pickup' ? 'Abholung' : 'Lieferung') : '').($pay !== '' ? ', '.$pay : '').($f ? ' - '.implode(', ', $f) : ''));
			if ($head !== '') { $lines[] = $head; }
		}
		$out[] = array('id' => (int)$r['id'], 'received' => $r['received'], 'status' => $r['status'], 'lines' => $lines, 'ref' => (string)$r['ref'], 'auto' => (is_array($d) && isset($d['auto']['message'])) ? (string)$d['auto']['message'] : '', 'stale' => ($read && !array_key_exists('payment', $read)), 'order_id' => ($r['order_id'] && $r['day_no']) ? (int)$r['order_id'] : 0, 'day_no' => $r['day_no'] ? (int)$r['day_no'] : 0, 'is_test' => !empty($r['is_test']), 'tier' => (is_array($d) && isset($d['tier'])) ? $d['tier'] : '',
			'items' => ($read && isset($read['items']) && is_array($read['items'])) ? count($read['items']) : null, 'total' => ($read && isset($read['total_cents'])) ? $read['total_cents'] : null, 'why' => $why);
	}
	return $out;
}

// the menu item with this title (the same match as the Lieferando import: exact title, case aside), or null
function shop_uber_find_product($title) {
	$row = fb_row("SELECT id FROM ".fb_t('tp_shop_products')." WHERE active = 1 AND LOWER(title) = LOWER(?) LIMIT 1", 's', array($title));
	return $row ? (int)$row['id'] : null;
}

/*
 * Turns a read receipt into an order (tp_shop_orders, source 'uber_eats', external_ref 'ue:<code>' so the same receipt never makes two). Only a receipt that adds up and whose payment is
 * known becomes an order: "paid_online" is paid on Uber's side (payment_method 'uber_eats', paid), "cash_due" is cash the driver collects (payment_method 'cash', open).
 * Paid online: Uber's fee lines (Marketplace-Gebühr ...) are part of fee_cents, so subtotal + fee - discount + tip is the amount on the receipt. Cash due: the "Fälliger Bargeldbetrag" of the receipt
 * is the total whatever the lines add up to, the Marketplace-Gebühr is not put on top of the price, and the difference to the account is booked as discount (or surcharge) with a note.
 * A receipt that is not from today (the archive samples) becomes a test order, so it does not show in the day report. Orders from Uber get the SMS and the status page like our own orders (the SMS go
 * out when the order is on its way or ready), but no stamps (and no feedback mail, there is no e-mail address on the receipt), see shop_sms_status and shop_stamp_award.
 * $auto = true is the automatic way (shop_uber_auto): the receipt must be fresh (ordered within the last 45 minutes, so a reprint of an old order from the archive never becomes a live order), and a
 * delivery needs address and phone number; a person pressing the button may import without (the note says so).
 */
function shop_uber_import($slipId, $auto = false) {
	shop_ensure_schema();
	$slip = fb_row("SELECT id, data, order_id FROM ".fb_t('tp_shop_uber_slips')." WHERE id = ?", 'i', array((int)$slipId));
	if (!$slip) { return array('ok' => false, 'error' => 'Unbekannter Bon.'); }
	if ($slip['order_id'] && fb_row("SELECT id FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$slip['order_id']))) { return array('ok' => true, 'duplicate' => true, 'id' => (int)$slip['order_id']); }   // (a test order that was deleted again can be made anew)
	$d = $slip['data'] ? json_decode($slip['data'], true) : null;
	$p = (is_array($d) && isset($d['read']) && is_array($d['read'])) ? $d['read'] : null;
	if (!$p) { return array('ok' => false, 'error' => 'Der Bon ist noch nicht gelesen.'); }
	$chk = shop_uber_check($p);
	if (!$chk['ok']) { return array('ok' => false, 'error' => 'Der Bon stimmt nicht: '.implode(' ', $chk['problems'])); }
	if (empty($p['payment'])) { return array('ok' => false, 'error' => 'Die Zahlart ist nicht erkannt (Gezahlter Betrag oder Fälliger Bargeldbetrag), der Bon wird nicht übernommen.'); }
	$isDelivery = !(isset($p['kind']) && $p['kind'] === 'pickup');
	if ($auto) {
		if ($isDelivery && (empty($p['street']) || (empty($p['zip']) && empty($p['city'])) || empty($p['phone']))) { return array('ok' => false, 'error' => 'Lieferung ohne Adresse oder Telefonnummer: nur von Hand zu übernehmen.'); }
		$tt = shop_uber_times($p);
		if ($tt['placed'] === null || abs(time() - strtotime($tt['placed'])) > 45 * 60) { return array('ok' => false, 'error' => 'Der Bon ist nicht frisch (bestellt '.($tt['placed'] !== null ? date('d.m. H:i', strtotime($tt['placed'])) : 'zu unbekannter Zeit').'): nur von Hand zu übernehmen.'); }
	}
	$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$p['order_code']));
	$ref = 'ue:'.substr($code, 0, 17);
	$existing = fb_row("SELECT id, day_no, number FROM ".fb_t('tp_shop_orders')." WHERE external_ref = ?", 's', array($ref));
	if ($existing) {
		fb_exec("UPDATE ".fb_t('tp_shop_uber_slips')." SET order_id = ?, status = 'imported' WHERE id = ?", 'ii', array((int)$existing['id'], (int)$slipId));
		return array('ok' => true, 'duplicate' => true, 'id' => (int)$existing['id'], 'day_no' => (int)$existing['day_no'], 'number' => $existing['number']);
	}
	// the money: the price of a line is the line's, an option's price is per piece (counted per piece of the line when the check found it that way)
	$mult = ($chk['opt_mult'] !== false);
	$lines = array(); $sub = 0;
	foreach ($p['items'] as $it) {
		$q = max(1, (int)$it['qty']); $opts = array(); $optSum = 0;
		foreach ((isset($it['options']) && is_array($it['options'])) ? $it['options'] : array() as $op) {
			$oq = max(1, (int)(isset($op['qty']) ? $op['qty'] : 1)); $opts[] = array('title' => (string)$op['title'], 'qty' => $oq, 'price_cents' => (int)$op['price_cents']); $optSum += (int)$op['price_cents'] * $oq;
		}
		$line = (int)$it['price_cents'] + $optSum * ($mult ? $q : 1);
		$lines[] = array('title' => (string)$it['title'], 'qty' => $q, 'unit' => (int)round($line / $q), 'line' => $line, 'opts' => $opts, 'note' => (isset($it['note']) && $it['note'] !== null) ? (string)$it['note'] : '');
		$sub += $line;
	}
	$cash = ($p['payment'] === 'cash_due');
	$fee = (int)(isset($p['fee_cents']) ? $p['fee_cents'] : 0);
	if (!$cash) { foreach ((isset($p['other_fees']) && is_array($p['other_fees'])) ? $p['other_fees'] : array() as $f) { $fee += (int)$f['cents']; } }   // paid online: the fee lines belong to what the guest paid
	$tip = (int)(isset($p['tip_cents']) ? $p['tip_cents'] : 0); $disc = (int)(isset($p['discount_cents']) ? $p['discount_cents'] : 0); $total = (int)$p['total_cents'];
	$sur = 0; $adjNote = '';
	if ($cash) {   // the amount due on the receipt is what is collected: the difference to the account is a discount (or a surcharge) with its reason
		$calc = $sub + $fee + $tip - $disc;
		if ($total < $calc) { $disc += $calc - $total; $adjNote = 'Uber Eats: fälliger Bargeldbetrag '.shop_uber_money($total).' statt '.shop_uber_money($calc); }
		elseif ($total > $calc) { $sur = $total - $calc; $adjNote = 'Uber Eats: fälliger Bargeldbetrag '.shop_uber_money($total).' statt '.shop_uber_money($calc); }
	}
	$today = date('Y-m-d'); $now = date('Y-m-d H:i:s');
	$tt = shop_uber_times($p);
	$placed = $tt['placed'] !== null ? $tt['placed'].':00' : '';
	$isToday = ($placed !== '' && substr($placed, 0, 10) === $today);
	$created = ($isToday && strtotime($placed) <= time() + 300) ? $placed : $now;
	$test = $isToday ? 0 : 1;   // a receipt of another day is a trial, it must not count in today's report
	// "Fällig um" on the receipt is a time that was promised (kept as the wish time like Lieferando's confirmed time); without one the order is as soon as possible: the usual time of the settings from now
	$sched = $tt['due'] !== null ? $tt['due'].':00' : null;
	$mins = (int)shop_setting($isDelivery ? 'eta_delivery_min' : 'lead_pickup_min'); if ($mins <= 0) { $mins = $isDelivery ? 45 : 20; }
	$eta = $sched ? $sched : date('Y-m-d H:i:s', time() + $mins * 60);
	$note = isset($p['note']) && $p['note'] !== null ? trim((string)$p['note']) : '';
	$warn = array();
	if ($isDelivery && empty($p['street'])) { $warn[] = 'keine Adresse auf dem Bon'; }
	if ($isDelivery && empty($p['phone'])) { $warn[] = 'keine Telefonnummer auf dem Bon'; }
	if (!$isToday) { $warn[] = 'Bon vom '.($placed !== '' ? date('d.m.Y', strtotime($placed)) : 'unbekanntem Tag').', als Test angelegt'; }
	if ($warn) { $note = trim('[Uber Eats: '.implode('; ', $warn).'] '.$note); }
	$street = isset($p['street']) ? trim((string)$p['street']) : ''; $zip = isset($p['zip']) ? trim((string)$p['zip']) : ''; $city = isset($p['city']) ? trim((string)$p['city']) : '';
	$phone = isset($p['phone']) ? trim((string)$p['phone']) : '';
	$token = bin2hex(random_bytes(16)); $number = shop_order_number();
	shop_dayno_lock();
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$db = fb_db();
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, customer_name, street, zip, city, phone, note, subtotal_cents, fee_cents, tip_cents, total_cents, discount_cents, surcharge_cents, adjust_note,
		 payment_method, payment_status, lang, is_test, source, external_ref, created_at, updated_at, accepted_at, eta_at, scheduled_at, pay_with_cents)
		VALUES (?, ?, ?, ?, ?, 'accepted', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'de', ?, 'uber_eats', ?, ?, ?, ?, ?, ?, NULL)",
		'ssiss'.'ssssss'.'iiiiii'.'sss'.'i'.'ssssss', array($token, $number, $dayNo, $today, $isDelivery ? 'delivery' : 'pickup', (string)$p['customer_name'], $street, $zip, $city, $phone, $note, $sub, $fee, $tip, $total, $disc, $sur, $adjNote,
			$cash ? 'cash' : 'uber_eats', $cash ? 'open' : 'paid', $test, $ref, $created, $now, $now, $eta, $sched));
	if (!$ok) { shop_dayno_unlock(); return array('ok' => false, 'error' => 'Die Bestellung konnte nicht gespeichert werden.'); }
	$id = (int)mysqli_insert_id($db); shop_dayno_unlock();
	if ($street !== '') {   // a point for the driver page and the map (they only look up orders that have one); a miss does not stop the import
		try { $g = shop_geocode($street, $zip, $city); if ($g && $g[0] !== null && $g[1] !== null) { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET lat = ?, lng = ? WHERE id = ?", 'ddi', array((float)$g[0], (float)$g[1], $id)); } } catch (Throwable $e) { error_log('uber geocode: '.$e->getMessage()); }
	}
	foreach ($lines as $l) {
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, ?, ?, '', ?, ?, ?, ?, ?)",
			'iissiiis', array($id, shop_uber_find_product($l['title']), $l['title'], json_encode($l['opts'], JSON_UNESCAPED_UNICODE), $l['qty'], $l['unit'], $l['line'], $l['note']));
	}
	fb_exec("UPDATE ".fb_t('tp_shop_uber_slips')." SET order_id = ?, status = 'imported' WHERE id = ?", 'ii', array($id, (int)$slipId));
	shop_log($id, 'created', 'uber_eats '.$code.($warn ? ' - '.implode('; ', $warn) : ''));
	return array('ok' => true, 'id' => $id, 'day_no' => $dayNo, 'number' => $number, 'test' => (bool)$test, 'type' => $isDelivery ? 'delivery' : 'pickup', 'cash' => $cash);
}

/*
 * The automatic way (setting uber_auto_import, set in the backend): called for every receipt that was read and adds up (order/uber_import.php). Makes the order (shop_uber_import with $auto: fresh receipt,
 * a delivery with address and phone number) and puts the kitchen slip into the print queue of the kitchen Pi (shop_print_enqueue, the full slip: it replaces the receipt the Uber Eats printer
 * used to print there). What came of it stays on the slip ('auto'), so the backend page can say why a receipt was not taken over. Returns the answer of the import, or null when the setting is off.
 */
function shop_uber_auto($slipId) {
	if (shop_setting('uber_auto_import') !== '1') { return null; }
	$r = shop_uber_import((int)$slipId, true);
	$msg = !empty($r['ok']) ? ('Automatisch übernommen als Bestellung #'.(isset($r['day_no']) ? (int)$r['day_no'] : '?').(!empty($r['test']) ? ' (Test)' : '')) : (isset($r['error']) ? (string)$r['error'] : 'nicht übernommen');
	$row = fb_row("SELECT data FROM ".fb_t('tp_shop_uber_slips')." WHERE id = ?", 'i', array((int)$slipId));
	$d = ($row && $row['data']) ? json_decode($row['data'], true) : null;
	if (is_array($d)) {
		$d['auto'] = array('ok' => !empty($r['ok']), 'message' => $msg, 'at' => date('Y-m-d H:i:s'));
		fb_exec("UPDATE ".fb_t('tp_shop_uber_slips')." SET data = ? WHERE id = ?", 'si', array(json_encode($d, JSON_UNESCAPED_UNICODE), (int)$slipId));
	}
	if (!empty($r['ok']) && empty($r['duplicate']) && empty($r['test']) && !empty($r['id'])) {
		try { shop_print_enqueue((int)$r['id'], true); } catch (Throwable $e) { error_log('uber slip print: '.$e->getMessage()); }
	}
	return $r;
}
