<?php
/*
 * Guest feedback after a visit: a star rating (food, service, overall) plus an optional comment,
 * collected 24h+ after the reservation's time. A 4-5 star overall rating asks the guest to also
 * post the same rating on Google/TripAdvisor (links from the outlet's own settings); anything
 * lower stays inside the restaurant's own inbox instead of going public.
 *
 * Own table (created on first use): tp_feedback, one row per reservation that was ever asked.
 * Two extra columns on the outlets table (review platform links) and one on reservations (the
 * guest's mail language, so a request sent days later still knows which language to use) are
 * added the same way, on first use.
 */

function fb_db() {
	return $GLOBALS['__mysql_compat_link'];
}

function fb_t($name) {
	global $settings;
	$prefix = isset($settings['dbTablePrefix']) ? $settings['dbTablePrefix'] : '';
	return '`'.$prefix.$name.'`';
}

function fb_exec($sql, $types = '', $params = array()) {
	$db = fb_db();
	$st = mysqli_prepare($db, $sql);
	if (!$st) { return false; }
	if ($types !== '') { mysqli_stmt_bind_param($st, $types, ...$params); }
	if (!mysqli_stmt_execute($st)) { mysqli_stmt_close($st); return false; }
	return $st;
}

function fb_rows($sql, $types = '', $params = array()) {
	$st = fb_exec($sql, $types, $params);
	if (!$st) { return array(); }
	$res = mysqli_stmt_get_result($st);
	$rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
	mysqli_stmt_close($st);
	return $rows;
}

function fb_row($sql, $types = '', $params = array()) {
	$rows = fb_rows($sql, $types, $params);
	return $rows ? $rows[0] : null;
}

function fb_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	$db = fb_db();
	global $dbTables;

	// reservations that must not get automatic guest mails (reminder, feedback request), e.g. imported
	// from another system for the days it still mails the guests itself
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_mail_optout')." (
		`reservation_id` INT NOT NULL PRIMARY KEY,
		`reason` VARCHAR(100) NOT NULL DEFAULT '',
		`created_at` DATETIME NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_feedback')." (
		`feedback_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`reservation_id` INT NOT NULL,
		`outlet_id` INT NOT NULL,
		`token` VARCHAR(40) NOT NULL,
		`guest_name` VARCHAR(150) NOT NULL DEFAULT '',
		`guest_email` VARCHAR(190) NOT NULL DEFAULT '',
		`lang` VARCHAR(2) NOT NULL DEFAULT 'de',
		`visit_date` DATE NOT NULL,
		`visit_time` TIME NOT NULL,
		`rating_food` TINYINT UNSIGNED DEFAULT NULL,
		`rating_service` TINYINT UNSIGNED DEFAULT NULL,
		`rating_overall` TINYINT UNSIGNED DEFAULT NULL,
		`comment` TEXT,
		`consent_public` TINYINT(1) NOT NULL DEFAULT 0,
		`is_public` TINYINT(1) NOT NULL DEFAULT 0,
		`reply` TEXT,
		`replied_at` DATETIME DEFAULT NULL,
		`status` VARCHAR(12) NOT NULL DEFAULT 'requested',
		`requested_at` DATETIME NOT NULL,
		`submitted_at` DATETIME DEFAULT NULL,
		`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY `uniq_token` (`token`),
		UNIQUE KEY `uniq_reservation` (`reservation_id`),
		KEY `idx_outlet_visit` (`outlet_id`, `visit_date`),
		KEY `idx_status` (`status`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	foreach (array('outlet_tripadvisor_url', 'outlet_google_url') as $col) {
		$has = mysqli_query($db, "SHOW COLUMNS FROM `".$dbTables->outlets."` LIKE '".$col."'");
		if ($has && mysqli_num_rows($has) === 0) {
			mysqli_query($db, "ALTER TABLE `".$dbTables->outlets."` ADD `".$col."` VARCHAR(255) NOT NULL DEFAULT ''");
		}
	}
	$has = mysqli_query($db, "SHOW COLUMNS FROM `".$dbTables->reservations."` LIKE 'reservation_email_lang'");
	if ($has && mysqli_num_rows($has) === 0) {
		mysqli_query($db, "ALTER TABLE `".$dbTables->reservations."` ADD `reservation_email_lang` VARCHAR(2) NOT NULL DEFAULT 'de'");
	}
	$has = mysqli_query($db, "SHOW COLUMNS FROM ".fb_t('tp_feedback')." LIKE 'consent_public'");
	if ($has && mysqli_num_rows($has) === 0) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_feedback')." ADD `consent_public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `comment`");
	}
	// reservation_id started out NOT NULL (one feedback row per live reservation), but reviews
	// imported from a prior review platform have no reservation to link to - MySQL's UNIQUE KEY
	// still enforces uniqueness among the non-NULL values, so this stays safe for real bookings
	$col = mysqli_fetch_assoc(mysqli_query($db, "SHOW COLUMNS FROM ".fb_t('tp_feedback')." LIKE 'reservation_id'"));
	if ($col && strtoupper($col['Null']) === 'NO') {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_feedback')." MODIFY `reservation_id` INT NULL");
	}
	$done = true;
}

/*
 * Reservations whose visit is over, worth asking about: not cancelled, not a no-show, a guest
 * email on file, the visit happened between $hours_min and $hours_max hours ago, and nobody has
 * been asked for this reservation yet.
 */
function fb_find_due_reservations($hours_min = 24, $hours_max = 96) {
	fb_ensure_schema();
	global $dbTables;
	$sql = "SELECT r.reservation_id, r.reservation_outlet_id, r.reservation_date, r.reservation_time,
			r.reservation_guest_name, r.reservation_guest_email, r.reservation_email_lang
		FROM `".$dbTables->reservations."` r
		LEFT JOIN ".fb_t('tp_feedback')." f ON f.reservation_id = r.reservation_id
		LEFT JOIN ".fb_t('tp_mail_optout')." o ON o.reservation_id = r.reservation_id
		WHERE r.reservation_hidden = 0
		AND o.reservation_id IS NULL
		AND r.reservation_status <> 'NSW'
		AND r.reservation_guest_email <> ''
		AND f.feedback_id IS NULL
		AND TIMESTAMP(r.reservation_date, r.reservation_time) <= (NOW() - INTERVAL ? HOUR)
		AND TIMESTAMP(r.reservation_date, r.reservation_time) >= (NOW() - INTERVAL ? HOUR)";
	return fb_rows($sql, 'ii', array((int)$hours_min, (int)$hours_max));
}

// one request per reservation (the unique key on reservation_id also protects against a double
// insert if the cron overlaps itself)
function fb_create_request($r) {
	fb_ensure_schema();
	$token = bin2hex(random_bytes(16));
	$lang = ($r['reservation_email_lang'] === 'en') ? 'en' : 'de';
	$ok = fb_exec("INSERT IGNORE INTO ".fb_t('tp_feedback')."
			(reservation_id, outlet_id, token, guest_name, guest_email, lang, visit_date, visit_time, status, requested_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'requested', NOW())",
		'iissssss', array(
			(int)$r['reservation_id'], (int)$r['reservation_outlet_id'], $token,
			$r['reservation_guest_name'], $r['reservation_guest_email'], $lang,
			$r['reservation_date'], $r['reservation_time'],
		));
	return $ok ? $token : false;
}

function fb_get_by_token($token) {
	fb_ensure_schema();
	return fb_row("SELECT * FROM ".fb_t('tp_feedback')." WHERE token = ? LIMIT 1", 's', array($token));
}

// only two categories are asked; the "overall" rating shown in the backend stats/list is their
// rounded average, not a separate question to the guest
function fb_submit($feedback_id, $food, $service, $comment, $consent_public) {
	fb_ensure_schema();
	$overall = (int)round(((int)$food + (int)$service) / 2);
	fb_exec("UPDATE ".fb_t('tp_feedback')." SET rating_food=?, rating_service=?, rating_overall=?, comment=?, consent_public=?, status='submitted', submitted_at=NOW() WHERE feedback_id=? AND status='requested'",
		'iiisii', array((int)$food, (int)$service, $overall, $comment, $consent_public ? 1 : 0, (int)$feedback_id));
}

// staff can only make a review public if the guest consented to it in the first place
function fb_set_public($feedback_id, $public) {
	fb_ensure_schema();
	fb_exec("UPDATE ".fb_t('tp_feedback')." SET is_public=? WHERE feedback_id=? AND consent_public=1", 'ii', array($public ? 1 : 0, (int)$feedback_id));
}

// first name + initial of the rest, for public display ("Klaus K." instead of the full name)
function fb_public_name($full) {
	$full = trim((string)$full);
	if ($full === '') { return ''; }
	$parts = preg_split('/\s+/', $full);
	if (count($parts) < 2) { return $parts[0]; }
	return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
}

// approved, public reviews for the embeddable widget / public page - newest first
function fb_public_reviews($outlet_id, $limit = 20, $offset = 0) {
	fb_ensure_schema();
	return fb_rows("SELECT guest_name, rating_food, rating_service, rating_overall, comment, reply, visit_date
			FROM ".fb_t('tp_feedback')."
			WHERE outlet_id=? AND status='submitted' AND consent_public=1 AND is_public=1
			ORDER BY visit_date DESC, feedback_id DESC LIMIT ? OFFSET ?",
		'iii', array((int)$outlet_id, (int)$limit, (int)$offset));
}

// total count of publicly visible reviews for an outlet - used for pagination
function fb_public_reviews_count($outlet_id) {
	fb_ensure_schema();
	$row = fb_row("SELECT COUNT(*) c FROM ".fb_t('tp_feedback')."
			WHERE outlet_id=? AND status='submitted' AND consent_public=1 AND is_public=1",
		'i', array((int)$outlet_id));
	return $row ? (int)$row['c'] : 0;
}

// the average rating and star distribution should always reflect ALL public reviews, not just
// the current page - fb_stats() is normally called on an already-paginated fb_rows() result, so
// this pulls the ratings for the whole outlet separately for that purpose
function fb_public_stats($outlet_id) {
	fb_ensure_schema();
	$rows = fb_rows("SELECT rating_overall, rating_food, rating_service FROM ".fb_t('tp_feedback')."
			WHERE outlet_id=? AND status='submitted' AND consent_public=1 AND is_public=1 AND rating_overall IS NOT NULL",
		'i', array((int)$outlet_id));
	return fb_stats($rows);
}

function fb_reply($feedback_id, $reply) {
	fb_ensure_schema();
	fb_exec("UPDATE ".fb_t('tp_feedback')." SET reply=?, replied_at=NOW() WHERE feedback_id=?", 'si', array($reply, (int)$feedback_id));
}

// submitted feedback for the backend tab, newest visit first
function fb_list($outlet_id, $date_from, $date_to) {
	fb_ensure_schema();
	return fb_rows("SELECT * FROM ".fb_t('tp_feedback')." WHERE outlet_id=? AND status='submitted' AND visit_date BETWEEN ? AND ? ORDER BY visit_date DESC, visit_time DESC",
		'iss', array((int)$outlet_id, $date_from, $date_to));
}

function fb_stats($rows) {
	$n = count($rows);
	$stats = array('count' => $n, 'avg_overall' => 0, 'avg_food' => 0, 'avg_service' => 0, 'dist' => array(1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0));
	if (!$n) { return $stats; }
	$sum_o = $sum_f = $sum_s = 0; $cf = $cs = 0;
	foreach ($rows as $r) {
		$o = (int)$r['rating_overall'];
		if ($o >= 1 && $o <= 5) { $sum_o += $o; $stats['dist'][$o]++; }
		if ($r['rating_food'] !== null) { $sum_f += (int)$r['rating_food']; $cf++; }
		if ($r['rating_service'] !== null) { $sum_s += (int)$r['rating_service']; $cs++; }
	}
	$stats['avg_overall'] = $n ? round($sum_o / $n, 1) : 0;
	$stats['avg_food'] = $cf ? round($sum_f / $cf, 1) : 0;
	$stats['avg_service'] = $cs ? round($sum_s / $cs, 1) : 0;
	return $stats;
}

// ---------------------------------------------------------------------------
// the "how was your visit?" request mail - texts in German and English
// ---------------------------------------------------------------------------

function fb_mail_build($ctx) {
	// $ctx: lang, brand, guest_name, form_url, legal (array of lines), imprint_url, privacy_url
	$de = ($ctx['lang'] !== 'en');
	$h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
	$brand = $ctx['brand'];
	$name = $ctx['guest_name'];
	$url = $ctx['form_url'];

	global $settings;
	$who = !empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun';

	if ($de) {
		$subject = 'Wie war dein Abend bei uns, '.$name.'?';
		$greeting = 'Hallo '.$name.',';
		$intro = 'schön, dass du bei uns warst! Uns interessiert ehrlich, wie es für dich war: was gut lief und was wir besser machen können. Beides hilft uns. Wenn etwas nicht gepasst hat, ist das keine Störung, sondern genau das, was wir wissen wollen.';
		$cta = 'Jetzt Feedback geben';
		$note = 'Dauert nur eine Minute.';
		$closing = 'Danke, dass du dir die Zeit nimmst.';
		$sign = $who.' vom '.$brand.'-Team';
		$legal_h = 'Angaben zum Anbieter';
		$imprint_l = 'Impressum'; $privacy_l = 'Datenschutz';
		$auto = 'Diese E-Mail wurde automatisch nach deinem Besuch versendet.';
	} else {
		$subject = 'How was your evening with us, '.$name.'?';
		$greeting = 'Hello '.$name.',';
		$intro = 'it was lovely to have you! We honestly want to know how it was for you: what went well and what we can do better. Both help us. If something was not right, that is not a bother, it is exactly what we want to know.';
		$cta = 'Give feedback';
		$note = 'Takes only a minute.';
		$closing = 'Thank you for taking the time.';
		$sign = $who.' from the '.$brand.' team';
		$legal_h = 'Provider information';
		$imprint_l = 'Legal notice'; $privacy_l = 'Privacy policy';
		$auto = 'This email was sent automatically after your visit.';
	}

	$legal_html = implode('<br>', array_map($h, $ctx['legal']));
	$links = array();
	if (!empty($ctx['imprint_url'])) { $links[] = '<a href="'.$h($ctx['imprint_url']).'" style="color:#8a6d3b;">'.$h($imprint_l).'</a>'; }
	if (!empty($ctx['privacy_url'])) { $links[] = '<a href="'.$h($ctx['privacy_url']).'" style="color:#8a6d3b;">'.$h($privacy_l).'</a>'; }

	$p = $greeting."\r\n\r\n".$intro."\r\n\r\n".$note."\r\n".$url."\r\n\r\n".$closing."\r\n".$sign."\r\n";
	$p .= "\r\n--\r\n".$legal_h."\r\n".implode("\r\n", $ctx['legal'])."\r\n\r\n".$auto."\r\n";

	$font = "font-family:Arial,Helvetica,sans-serif;";
	$stars = str_repeat('&#9733;', 5);
	$html = '<!DOCTYPE html><html lang="'.$ctx['lang'].'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="'.$font.'padding:28px 32px 4px;font-size:12px;font-weight:bold;letter-spacing:.16em;text-transform:uppercase;color:#8a6d3b;">'.$h($brand).'</td></tr>'
		.'<tr><td style="font-size:28px;letter-spacing:.1em;color:#c9a259;padding:8px 32px 0;">'.$stars.'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($greeting).'<br><br>'.$h($intro).'</td></tr>'
		.'<tr><td style="padding:20px 32px;"><a href="'.$h($url).'" style="display:inline-block;background-color:#c9a259;color:#1a1408;font-weight:bold;text-decoration:none;padding:14px 28px;border-radius:999px;'.$font.'font-size:16px;">'.$h($cta).'</a></td></tr>'
		.'<tr><td style="'.$font.'padding:0 32px 20px;font-size:13px;color:#8a8577;">'.$h($note).'</td></tr>'
		.'<tr><td style="'.$font.'padding:0 32px 28px;font-size:16px;line-height:1.6;color:#333333;">'.$h($closing).'<br>'.$h($sign).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#8a8577;"><strong>'.$h($legal_h).'</strong><br>'.$legal_html
		.($links ? '<br>'.implode(' &middot; ', $links) : '').'<br><br>'.$h($auto).'</td></tr>'
		.'</table></td></tr></table></body></html>';

	return array('lang' => $ctx['lang'], 'subject' => $subject, 'plain' => $p, 'html' => $html);
}

// ---------------------------------------------------------------------------
// staff's reply to a guest's feedback - sent to the guest by mail
// ---------------------------------------------------------------------------

function fb_reply_mail_build($ctx) {
	// $ctx: lang, brand, guest_name, reply, legal (array of lines), imprint_url, privacy_url
	$de = ($ctx['lang'] !== 'en');
	$h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
	$brand = $ctx['brand'];
	$name = $ctx['guest_name'];
	$reply = $ctx['reply'];

	global $settings;
	$who = !empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun';

	if ($de) {
		$subject = 'Unsere Antwort auf dein Feedback';
		$greeting = 'Hallo '.$name.',';
		$intro = 'danke, dass du dir die Zeit genommen hast, uns zu schreiben. Hier ist unsere Antwort:';
		$closing = 'Wir freuen uns, wenn wir uns bald wiedersehen.';
		$sign = $who.' vom '.$brand.'-Team';
		$legal_h = 'Angaben zum Anbieter';
		$imprint_l = 'Impressum'; $privacy_l = 'Datenschutz';
		$auto = 'Diese E-Mail wurde automatisch versendet, nachdem das Restaurant auf dein Feedback geantwortet hat.';
	} else {
		$subject = 'Our reply to your feedback';
		$greeting = 'Hello '.$name.',';
		$intro = 'thank you for taking the time to write to us. Here is our reply:';
		$closing = 'We look forward to seeing you again soon.';
		$sign = $who.' from the '.$brand.' team';
		$legal_h = 'Provider information';
		$imprint_l = 'Legal notice'; $privacy_l = 'Privacy policy';
		$auto = 'This email was sent automatically after the restaurant replied to your feedback.';
	}

	$legal_html = implode('<br>', array_map($h, $ctx['legal']));
	$links = array();
	if (!empty($ctx['imprint_url'])) { $links[] = '<a href="'.$h($ctx['imprint_url']).'" style="color:#8a6d3b;">'.$h($imprint_l).'</a>'; }
	if (!empty($ctx['privacy_url'])) { $links[] = '<a href="'.$h($ctx['privacy_url']).'" style="color:#8a6d3b;">'.$h($privacy_l).'</a>'; }

	$p = $greeting."\r\n\r\n".$intro."\r\n\r\n\"".$reply."\"\r\n\r\n".$closing."\r\n".$sign."\r\n";
	$p .= "\r\n--\r\n".$legal_h."\r\n".implode("\r\n", $ctx['legal'])."\r\n\r\n".$auto."\r\n";

	$font = "font-family:Arial,Helvetica,sans-serif;";
	$html = '<!DOCTYPE html><html lang="'.$ctx['lang'].'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="'.$font.'padding:28px 32px 4px;font-size:12px;font-weight:bold;letter-spacing:.16em;text-transform:uppercase;color:#8a6d3b;">'.$h($brand).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($greeting).'<br><br>'.$h($intro).'</td></tr>'
		.'<tr><td style="padding:12px 32px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#faf8f3;border-left:3px solid #c9a259;"><tr><td style="padding:14px 18px;'.$font.'font-size:15px;line-height:1.6;color:#333333;font-style:italic;">'.nl2br($h($reply)).'</td></tr></table></td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 28px;font-size:16px;line-height:1.6;color:#333333;">'.$h($closing).'<br>'.$h($sign).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#8a8577;"><strong>'.$h($legal_h).'</strong><br>'.$legal_html
		.($links ? '<br>'.implode(' &middot; ', $links) : '').'<br><br>'.$h($auto).'</td></tr>'
		.'</table></td></tr></table></body></html>';

	return array('lang' => $ctx['lang'], 'subject' => $subject, 'plain' => $p, 'html' => $html);
}
