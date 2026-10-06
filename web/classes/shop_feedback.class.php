<?php
/*
 * Feedback mail after an order: about two hours after a delivery or pickup is done the guest (with an e-mail address) gets a short, friendly mail - "Hat's geschmeckt?" -
 * whose answer is one tap on a number from 1 to 5. The tap is saved at once (status 'partial'); the page behind it asks for the second rating (delivery or pickup), a free
 * word and the consent to show the review (api/feedback.php, the same form as for reservations). The answers land in tp_feedback (kind 'delivery' / 'pickup'), so they show
 * in the feedback list of the backend with the reply function. A low rating goes to the restaurant by mail at once. Nobody is asked twice within 14 days, whoever opted
 * out ("Keine Feedback-Mails mehr") is never asked again, and only orders done after the switch-on count. The sending is started by the kitchen monitor's polling (about every
 * ten minutes, max 5 mails at a time) and, where a cron is wanted, by web/cron/send_order_feedback.php.
 */
require_once __DIR__.'/feedback.class.php';
require_once __DIR__.'/shop.class.php';
require_once __DIR__.'/booking_mail.class.php';

function shop_fb_ensure() {
	static $done = false; if ($done) { return; } $done = true;
	fb_ensure_schema();
	$db = fb_db();
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_feedback')." LIKE 'order_id'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_feedback')." ADD `order_id` INT NULL, ADD `kind` VARCHAR(12) NOT NULL DEFAULT 'reservation'"); }
	if (!fb_rows("SHOW INDEX FROM ".fb_t('tp_feedback')." WHERE Key_name = 'uniq_order'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_feedback')." ADD UNIQUE KEY `uniq_order` (`order_id`)"); }
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_mail_optout')." (`email_key` CHAR(40) NOT NULL PRIMARY KEY, `created_at` DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function shop_fb_key($email) { return sha1('mail|'.strtolower(trim((string)$email))); }
function shop_fb_base() { require_once __DIR__.'/hosts.class.php'; $h = myseat_hosts(); return $h['admin'] !== '' ? $h['admin'] : (function_exists('shop_site_url') ? shop_site_url() : ''); }
function shop_fb_outlet() {
	global $dbTables;
	$id = (int)shop_setting('feedback_outlet_id');
	$q = "SELECT o.outlet_id, o.outlet_name, o.property_id, o.confirmation_email FROM `".$dbTables->outlets."` o".($id > 0 ? " WHERE o.outlet_id = ".$id : '')." ORDER BY o.outlet_id LIMIT 1";
	$o = fb_row($q);
	$p = $o ? fb_row("SELECT * FROM `".$dbTables->properties."` WHERE id = ? LIMIT 1", 'i', array((int)$o['property_id'])) : null;
	return $o && $p ? array($o, $p) : null;
}

// orders that are due: done between 2 and 24 hours ago (and after the switch-on), with an address, nobody asked for this order, not asked within 14 days, not opted out
function shop_fb_due($limit = 5) {
	shop_fb_ensure();
	$since = (string)shop_setting('feedback_since');
	return fb_rows("SELECT o.id, o.number, o.day_no, o.type, o.customer_name, o.email, o.done_at FROM ".fb_t('tp_shop_orders')." o
		LEFT JOIN ".fb_t('tp_feedback')." f ON f.order_id = o.id
		LEFT JOIN ".fb_t('tp_shop_mail_optout')." x ON x.email_key = SHA1(CONCAT('mail|', LOWER(TRIM(o.email))))
		WHERE o.status = 'done' AND o.is_test = 0 AND o.email <> '' AND o.source NOT IN ('lieferando', 'uber_eats') AND f.feedback_id IS NULL AND x.email_key IS NULL
		AND o.done_at >= ? AND o.done_at <= ? AND o.done_at >= ?
		AND NOT EXISTS (SELECT 1 FROM ".fb_t('tp_feedback')." f2 WHERE LOWER(f2.guest_email) = LOWER(TRIM(o.email)) AND f2.requested_at > ?)
		ORDER BY o.done_at LIMIT ".(int)$limit, 'ssss',
		array(date('Y-m-d H:i:s', time() - 86400), date('Y-m-d H:i:s', time() - 7200), $since !== '' ? $since : date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 14 * 86400)));
}

function shop_fb_create($o, $outletId) {
	shop_fb_ensure();
	$token = bin2hex(random_bytes(16));
	$st = fb_exec("INSERT IGNORE INTO ".fb_t('tp_feedback')." (reservation_id, order_id, kind, outlet_id, token, guest_name, guest_email, lang, visit_date, visit_time, status, requested_at) VALUES (NULL, ?, ?, ?, ?, ?, ?, 'de', ?, ?, 'requested', NOW())",
		'isisssss', array((int)$o['id'], $o['type'] === 'delivery' ? 'delivery' : 'pickup', (int)$outletId, $token, (string)$o['customer_name'], trim((string)$o['email']), substr($o['done_at'], 0, 10), substr($o['done_at'], 11, 8)));
	return ($st && mysqli_stmt_affected_rows($st) === 1) ? $token : false;
}

function shop_fb_mail_build($ctx) {
	// $ctx: brand, first_name, summary (what was ordered, one line), urls (1..5 => link), optout_url, legal (lines), imprint_url, privacy_url
	global $settings; $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$who = !empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun'; $brand = $ctx['brand']; $first = trim((string)$ctx['first_name']);
	$subject = 'Hat’s geschmeckt'.($first !== '' ? ', '.$first : '').'?';
	$greeting = 'Hallo'.($first !== '' ? ' '.$first : '').',';
	$intro = 'hoffentlich hat dir das Essen geschmeckt! Wir wollen es ehrlich wissen, und ein Tipp reicht schon.';
	$ask = 'Wie hat es geschmeckt?'; $lo = 'nicht gut'; $hi = 'richtig lecker';
	$after = 'Wenn etwas nicht gepasst hat, sag es uns einfach. Wir lesen alles selbst und melden uns gern bei dir.';
	$sign = $who.' vom '.$brand.'-Team'; $thanks = 'Danke dir!';
	$opt = 'Keine Feedback-Mails mehr'; $auto = 'Diese E-Mail kam automatisch, weil du bei uns bestellt hast.';
	$font = 'font-family:Arial,Helvetica,sans-serif;';
	$tiles = '';
	for ($i = 1; $i <= 5; $i++) {
		$tiles .= '<td width="20%" align="center" style="padding:0 3px;"><a href="'.$h($ctx['urls'][$i]).'" style="display:block;'.$font.'font-size:22px;font-weight:bold;line-height:52px;color:#6b5330;background:#f8f2e4;border:1px solid #e6d9b8;border-radius:12px;text-decoration:none;">'.$i.'</a></td>';
	}
	$legal_html = implode('<br>', array_map($h, $ctx['legal'])); $links = array();
	if (!empty($ctx['imprint_url'])) { $links[] = '<a href="'.$h($ctx['imprint_url']).'" style="color:#8a6d3b;">Impressum</a>'; }
	if (!empty($ctx['privacy_url'])) { $links[] = '<a href="'.$h($ctx['privacy_url']).'" style="color:#8a6d3b;">Datenschutz</a>'; }
	$html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head><body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Ein Tipp reicht, und wir wissen, wie es war.</div>'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="padding:30px 32px 4px;"><div style="font-family:Georgia,\'Times New Roman\',serif;font-size:28px;color:#1c1a18;">'.$h($greeting).'</div></td></tr>'
		.'<tr><td style="'.$font.'padding:8px 32px 0;font-size:16px;line-height:1.6;color:#333333;">'.$h($intro).'</td></tr>'
		.($ctx['summary'] !== '' ? '<tr><td style="'.$font.'padding:10px 32px 0;font-size:13px;color:#6e685c;">'.$h($ctx['summary']).'</td></tr>' : '')
		.'<tr><td style="'.$font.'padding:22px 32px 8px;font-size:17px;font-weight:bold;color:#1c1a18;">'.$h($ask).'</td></tr>'
		.'<tr><td style="padding:0 29px;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'.$tiles.'</tr></table></td></tr>'
		.'<tr><td style="padding:6px 32px 0;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="left" style="'.$font.'font-size:12px;color:#6e685c;">'.$h($lo).'</td><td align="right" style="'.$font.'font-size:12px;color:#6e685c;">'.$h($hi).'</td></tr></table></td></tr>'
		.'<tr><td style="'.$font.'padding:22px 32px 6px;font-size:16px;line-height:1.6;color:#333333;">'.$h($after).'</td></tr>'
		.'<tr><td style="'.$font.'padding:10px 32px 28px;font-size:16px;line-height:1.6;color:#333333;">'.$h($thanks).'<br>'.$h($sign).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#6e685c;"><strong>Angaben zum Anbieter</strong><br>'.$legal_html.($links ? '<br>'.implode(' &middot; ', $links) : '')
		.'<br><br>'.$h($auto).' <a href="'.$h($ctx['optout_url']).'" style="color:#8a6d3b;">'.$h($opt).'</a></td></tr>'
		.'</table></td></tr></table></body></html>';
	$plain = $greeting."\r\n\r\n".$intro."\r\n".($ctx['summary'] !== '' ? $ctx['summary']."\r\n" : '')."\r\n".$ask."\r\n";
	foreach ($ctx['urls'] as $i => $u) { $plain .= $i.($i === 1 ? ' ('.$lo.')' : ($i === 5 ? ' ('.$hi.')' : '')).': '.$u."\r\n"; }
	$plain .= "\r\n".$after."\r\n\r\n".$thanks."\r\n".$sign."\r\n\r\n--\r\n".implode("\r\n", $ctx['legal'])."\r\n\r\n".$auto."\r\n".$opt.': '.$ctx['optout_url']."\r\n";
	return array('subject' => $subject, 'plain' => $plain, 'html' => $html);
}

function shop_fb_summary($orderId, $o) {
	$parts = array();
	foreach (shop_order_items((int)$orderId) as $it) { $parts[] = (int)$it['qty'].'× '.$it['title']; }
	$sum = implode(', ', array_slice($parts, 0, 3)).(count($parts) > 3 ? ' und mehr' : '');
	return 'Deine Bestellung von '.date('d.m.', strtotime($o['done_at'])).($sum !== '' ? ': '.$sum : '');
}

// sends what is due (at most $limit mails); returns the number sent
function shop_fb_run($limit = 5) {
	if (!shop_flag('feedback_on')) { return 0; }
	shop_fb_ensure();
	if ((string)shop_setting('feedback_since') === '') { shop_setting_set('feedback_since', date('Y-m-d H:i:s')); return 0; } // switched on just now: from here on
	$from = shop_mail_from(); $op = shop_fb_outlet();
	if ($from === '' || !$op) { return 0; }
	global $settings; $brand = bm_clean($op[0]['outlet_name'] !== '' ? $op[0]['outlet_name'] : $op[1]['name']);
	$base = shop_fb_base(); $sent = 0;
	foreach (shop_fb_due($limit) as $o) {
		if (!filter_var(trim($o['email']), FILTER_VALIDATE_EMAIL)) { continue; }
		$token = shop_fb_create($o, (int)$op[0]['outlet_id']); if ($token === false) { continue; }
		$urls = array(); for ($i = 1; $i <= 5; $i++) { $urls[$i] = $base.'/api/feedback.php?token='.urlencode($token).'&r='.$i; }
		$m = shop_fb_mail_build(array('brand' => $brand, 'first_name' => shop_stamp_first_name($o['customer_name']), 'summary' => shop_fb_summary((int)$o['id'], $o), 'urls' => $urls,
			'optout_url' => $base.'/api/feedback.php?token='.urlencode($token).'&optout=1', 'legal' => bm_legal_lines($op[1]), 'imprint_url' => !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '', 'privacy_url' => !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : ''));
		bm_send_guest_mail(trim($o['email']), array('subject' => $m['subject'], 'plain' => $m['plain'], 'html' => $m['html'], 'ics' => '', 'ics_filename' => ''), $brand, $from);
		shop_log((int)$o['id'], 'feedback', 'Feedback-Mail verschickt'); $sent++;
	}
	return $sent;
}
// called by the kitchen monitor's polling: at most every ten minutes, one process at a time
function shop_fb_tick() {
	try {
		if (!shop_flag('feedback_on')) { return; }
		if (time() - (int)shop_setting('feedback_last_run') < 600) { return; }
		$db = fb_db(); $r = mysqli_query($db, "SELECT GET_LOCK('".fb_t('tp_shop_feedback_run')."', 0) AS l"); $row = $r ? mysqli_fetch_assoc($r) : null;
		if (!$row || (int)$row['l'] !== 1) { return; }
		shop_setting_set('feedback_last_run', (string)time());
		try { shop_fb_run(5); } finally { mysqli_query($db, "SELECT RELEASE_LOCK('".fb_t('tp_shop_feedback_run')."')"); }
	} catch (Throwable $e) { error_log('mySeat order feedback: '.$e->getMessage()); }
}

// a low rating goes to the restaurant at once: who, what, how bad, and the word of the guest
function shop_fb_alert($f, $partial) {
	try {
		$from = shop_mail_from(); if ($from === '') { return; }
		$o = (int)$f['order_id'] ? shop_order((int)$f['order_id']) : null; if (!$o) { return; }
		$items = array(); foreach (shop_order_items((int)$o['id']) as $it) { $items[] = (int)$it['qty'].'× '.$it['title']; }
		$food = (int)$f['rating_food']; $svc = $f['rating_service'] !== null ? (int)$f['rating_service'] : null; $art = $o['type'] === 'delivery' ? 'Lieferung' : 'Abholung';
		$lines = array('Eine Bewertung zur Bestellung #'.$o['day_no'].' ('.$art.') ist eingegangen'.($partial ? ' (erster Tipp, der Rest kann noch folgen)' : '').':', '',
			'Essen: '.$food.' von 5', $svc !== null ? $art.': '.$svc.' von 5' : '', '', trim((string)$f['comment']) !== '' ? 'Kommentar: '.trim($f['comment']) : '',
			'', 'Gast: '.$o['customer_name'].($o['phone'] !== '' ? ', '.$o['phone'] : '').', '.$o['email'], 'Bestellung: '.implode(', ', $items).' ('.shop_money($o['total_cents']).')', '',
			'Im Backend antworten: '.shop_fb_base().'/web/main_page.php?p=8');
		$plain = preg_replace("/(\r\n){3,}/", "\r\n\r\n", implode("\r\n", $lines));
		$subject = 'Feedback zu Bestellung #'.$o['day_no'].': '.($svc !== null ? min($food, $svc) : $food).' von 5';
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1c1a18;">'.nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')).'</div>';
		bm_send_guest_mail($from, array('subject' => $subject, 'plain' => $plain, 'html' => $html, 'ics' => '', 'ics_filename' => ''), 'Amadeus', $from);
	} catch (Throwable $e) { error_log('mySeat feedback alert: '.$e->getMessage()); }
}
