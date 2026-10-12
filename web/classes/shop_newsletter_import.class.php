<?php
/*
 * Import of an address list (e-mail and name) into the newsletter list, with a duplicate check.
 * Two modes:
 *   confirmed - the owner states that the guests of the file gave a documented consent for restaurant news (his statement and the origin are written
 *               into the proof of every address). They are mailable for "news" at once. Needs the tick and an origin text.
 *   blocked   - no proof: the addresses are only kept, "blocked". They never get a mail until the guest confirms himself (double opt-in).
 * Duplicates are found by the normalised address (shop_nl_key: lower case, without +tag): inside the file, and against the list. Somebody who
 * unsubscribed is never put back by an import. The same function plans (preview) and imports, so the preview shows exactly what the import does.
 */

const SHOP_NL_IMPORT_MAX_ROWS = 20000;

// text of a file (utf-8, with or without BOM, or windows-1252 as old systems export it) -> array of array(email, name, line)
function shop_nl_import_parse($text) {
	$text = (string)$text;
	if (substr($text, 0, 3) === "\xEF\xBB\xBF") { $text = substr($text, 3); }
	if (!mb_check_encoding($text, 'UTF-8')) { $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252'); }
	$lines = preg_split('/\r\n|\r|\n/', $text);
	// the delimiter: the one that appears most in the first lines
	$sample = array_slice(array_values(array_filter($lines, function ($l) { return trim($l) !== ''; })), 0, 10);
	$delim = ','; $best = 0;
	foreach (array(',', ';', "\t", '|') as $d) { $n = 0; foreach ($sample as $l) { $n += substr_count($l, $d); } if ($n > $best) { $best = $n; $delim = $d; } }
	$rows = array(); $emailCol = -1; $nameCols = array(); $header = false; $n = 0;
	foreach ($lines as $i => $line) {
		if (trim($line) === '') { continue; }
		$n++;
		// "Name <mail@host>" (an address book copied from a mail program)
		if (preg_match('/^\s*"?([^"<]*?)"?\s*<([^>\s]+@[^>\s]+)>\s*[,;]?\s*$/u', $line, $m)) { $rows[] = array('email' => $m[2], 'name' => trim($m[1]), 'line' => $i + 1); continue; }
		$cells = str_getcsv($line, $delim, '"', '');
		if ($n === 1) {
			$hasMail = false; foreach ($cells as $c) { if (strpos($c, '@') !== false) { $hasMail = true; } }
			if (!$hasMail && preg_match('/mail/i', $line)) {
				$header = true;
				foreach ($cells as $k => $c) {
					$h = mb_strtolower(trim($c), 'UTF-8');
					if ($emailCol < 0 && preg_match('/mail/', $h)) { $emailCol = $k; }
					elseif (preg_match('/^(name|vorname|nachname|first.?name|last.?name|familienname|kunde|gast)/', $h)) { $nameCols[$k] = $h; }
				}
				if ($emailCol < 0) { $emailCol = 0; }
				continue;
			}
		}
		if (!$header) {
			// without header: the cell with the @ is the address, the first other cell with letters is the name
			$emailCol = -1; $nameCols = array();
			foreach ($cells as $k => $c) { if ($emailCol < 0 && strpos($c, '@') !== false) { $emailCol = $k; } }
			if ($emailCol >= 0) { foreach ($cells as $k => $c) { if ($k !== $emailCol && preg_match('/\pL/u', $c)) { $nameCols = array($k => ''); break; } } }
		}
		$email = ($emailCol >= 0 && isset($cells[$emailCol])) ? trim($cells[$emailCol]) : '';
		// "Vorname" before "Nachname" whatever the column order is
		$parts = array(); $cols = array_keys($nameCols);
		usort($cols, function ($a, $b) use ($nameCols) { $ra = preg_match('/^(nach|last|familien)/', $nameCols[$a]) ? 1 : 0; $rb = preg_match('/^(nach|last|familien)/', $nameCols[$b]) ? 1 : 0; return $ra - $rb ?: $a - $b; });
		foreach ($cols as $k) { if (isset($cells[$k]) && trim($cells[$k]) !== '') { $parts[] = trim($cells[$k]); } }
		$rows[] = array('email' => $email, 'name' => implode(' ', $parts), 'line' => $i + 1);
		if (count($rows) > SHOP_NL_IMPORT_MAX_ROWS) { break; }
	}
	return $rows;
}

/*
 * Sort every row into: new, dupfile (again inside the file), have (already on the list and mailable), pending, unsub (unsubscribed, stays so),
 * blockedhave (on the list, blocked), invalid. In mode "confirmed" a blocked address on the list is upgraded ('upgrade').
 * Returns array(counts, rows) - rows: array(kind, email, name, line, id, note).
 */
function shop_nl_import_plan($rows, $mode) {
	shop_ensure_schema();
	$seen = array(); $out = array(); $c = array('new' => 0, 'upgrade' => 0, 'dupfile' => 0, 'have' => 0, 'pending' => 0, 'unsub' => 0, 'blockedhave' => 0, 'invalid' => 0, 'total' => count($rows));
	foreach ($rows as $r) {
		$e = shop_nl_email($r['email']); $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['name'])), 0, 80);
		$o = array('kind' => '', 'email' => $e, 'name' => $name, 'line' => $r['line'], 'id' => 0, 'note' => '');
		if (!shop_nl_valid($e)) { $o['kind'] = 'invalid'; $o['email'] = trim((string)$r['email']); $o['note'] = $e === '' ? 'keine Adresse' : 'keine gültige Adresse'; }
		else {
			$k = shop_nl_key($e);
			if (isset($seen[$k])) { $o['kind'] = 'dupfile'; $o['note'] = 'steht schon in Zeile '.$seen[$k]; }
			else {
				$seen[$k] = $r['line'];
				$row = fb_row("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE email_key = ? ORDER BY (news = 1 OR stamps = 1) DESC, id LIMIT 1", 's', array($k));
				if (!$row) { $o['kind'] = 'new'; }
				else {
					$o['id'] = (int)$row['id']; $o['note'] = $row['email'] !== $e ? 'bekannt als '.$row['email'] : '';
					if (!empty($row['blocked'])) { $o['kind'] = $mode === 'confirmed' ? 'upgrade' : 'blockedhave'; }
					elseif (!empty($row['news']) || !empty($row['stamps'])) { $o['kind'] = 'have'; }
					elseif (!empty($row['unsub_at'])) { $o['kind'] = 'unsub'; }
					elseif (!empty($row['want_news']) || !empty($row['want_stamps'])) { $o['kind'] = 'pending'; }
					else { $o['kind'] = 'unsub'; }
				}
			}
		}
		$c[$o['kind']]++;
		$out[] = $o;
	}
	return array('counts' => $c, 'rows' => $out);
}

/*
 * Do the import. $o: mode (confirmed|blocked), file (name of the file), origin (where the consent comes from, owner's words), user (who imports).
 * Returns array(ok, error, batch, counts). Every address gets a proof entry "import" with batch number, file, origin and the owner's statement.
 */
function shop_nl_import_run($rows, $o) {
	shop_ensure_schema();
	$mode = (isset($o['mode']) && $o['mode'] === 'confirmed') ? 'confirmed' : 'blocked';
	$origin = trim(preg_replace('/\s+/u', ' ', (string)(isset($o['origin']) ? $o['origin'] : '')));
	if ($mode === 'confirmed' && mb_strlen($origin) < 10) { return array('ok' => false, 'error' => 'Bitte beschreibe kurz, woher die Einwilligung stammt (mindestens ein Satz).'); }
	if (count($rows) > SHOP_NL_IMPORT_MAX_ROWS) { return array('ok' => false, 'error' => 'Die Datei hat mehr als '.SHOP_NL_IMPORT_MAX_ROWS.' Zeilen. Bitte teile sie.'); }
	$plan = shop_nl_import_plan($rows, $mode);
	$last = fb_row("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(detail, ':', 1), '#', -1) AS UNSIGNED)) AS b FROM ".fb_t('tp_shop_consent_log')." WHERE event = 'import'");
	$batch = ($last && $last['b'] ? (int)$last['b'] : 0) + 1;
	$who = mb_substr((string)(isset($o['user']) ? $o['user'] : ''), 0, 40); $file = mb_substr(preg_replace('/[^\p{L}\p{N} ._-]/u', '', (string)(isset($o['file']) ? $o['file'] : '')), 0, 60);
	$now = date('Y-m-d H:i:s'); $added = 0; $upgraded = 0;
	$base = 'Import #'.$batch.': '.($file !== '' ? $file.', ' : '').date('d.m.Y').($who !== '' ? ', '.$who : '');
	$detail = $mode === 'confirmed'
		? mb_substr($base.'. Der Inhaber bestätigt eine dokumentierte Einwilligung für Neuigkeiten. Herkunft: '.$origin, 0, 255)
		: mb_substr($base.'. Ohne Nachweis übernommen, gesperrt bis zur Bestätigung durch den Gast.', 0, 255);
	foreach ($plan['rows'] as $r) {
		if ($r['kind'] === 'new') {
			fb_exec("INSERT INTO ".fb_t('tp_shop_subscribers')." (email, email_key, name, news, blocked, token, confirmed_at, source, lang, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'import', 'de', ?, ?)", 'sssiissss',
				array($r['email'], shop_nl_key($r['email']), $r['name'], $mode === 'confirmed' ? 1 : 0, $mode === 'confirmed' ? 0 : 1, bin2hex(random_bytes(16)), $mode === 'confirmed' ? $now : null, $now, $now));
			$id = (int)mysqli_insert_id(fb_db());
			if ($id > 0) { shop_nl_log($id, 'import', $mode === 'confirmed' ? array('news') : array(), 'import', $detail); $added++; }
		}
		elseif ($r['kind'] === 'upgrade' && $r['id'] > 0) {
			fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET news = 1, blocked = 0, confirmed_at = ?, unsub_at = NULL, name = IF(name = '', ?, name), updated_at = ? WHERE id = ?", 'sssi', array($now, $r['name'], $now, $r['id']));
			shop_nl_log($r['id'], 'import', array('news'), 'import', $detail); $upgraded++;
		}
		elseif (in_array($r['kind'], array('have', 'pending', 'blockedhave'), true) && $r['id'] > 0 && $r['name'] !== '') {
			fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET name = ?, updated_at = ? WHERE id = ? AND name = ''", 'ssi', array($r['name'], $now, $r['id']));
		}
	}
	return array('ok' => true, 'batch' => $batch, 'added' => $added, 'upgraded' => $upgraded, 'counts' => $plan['counts']);
}

// take a whole batch back (a wrong file): removes the addresses it created that have no other proof entry since, with their proof
function shop_nl_import_undo($batch) {
	shop_ensure_schema(); $batch = (int)$batch; if ($batch <= 0) { return 0; }
	$n = 0;
	foreach (fb_rows("SELECT sub_id FROM ".fb_t('tp_shop_consent_log')." WHERE event = 'import' AND detail LIKE ?", 's', array('Import #'.$batch.':%')) as $r) {
		$sub = fb_row("SELECT id, source FROM ".fb_t('tp_shop_subscribers')." WHERE id = ?", 'i', array((int)$r['sub_id']));
		if (!$sub) { continue; }
		$cnt = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ?", 'i', array((int)$r['sub_id']));
		if ($sub['source'] === 'import' && $cnt && (int)$cnt['n'] === 1) { shop_nl_erase((int)$r['sub_id']); $n++; }
		elseif ($sub['source'] !== 'import' && $cnt && (int)$cnt['n'] === 1) {
			// was on the list before as a blocked address and only got released by this import: block it again
			fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET news = 0, blocked = 1, confirmed_at = NULL, updated_at = ? WHERE id = ? AND blocked = 0 AND stamps = 0", 'si', array(date('Y-m-d H:i:s'), (int)$r['sub_id']));
			fb_exec("DELETE FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ? AND event = 'import'", 'i', array((int)$r['sub_id'])); $n++;
		}
	}
	return $n;
}

// the batches done so far, for the list in the backend
function shop_nl_import_batches() {
	shop_ensure_schema();
	return fb_rows("SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(detail, ':', 1), '#', -1) AS batch, MIN(at) AS at, COUNT(*) AS n, MIN(topics) AS topics, MIN(detail) AS detail FROM ".fb_t('tp_shop_consent_log')." WHERE event = 'import' GROUP BY batch ORDER BY CAST(batch AS UNSIGNED) DESC LIMIT 10");
}
