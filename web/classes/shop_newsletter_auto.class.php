<?php
/*
 * Newsletter automations (step 4): mails that go out by themselves when something happens to a guest. All of them are OFF until the owner switches them on
 * (page "Newsletter", tab "Automatiken"); each has its own mail made in the block editor (a template row of tp_shop_nl_campaigns with kind <> '').
 *   welcome         news    right after the guest confirmed the news: personal one-time voucher (bound to the e-mail address)
 *   stamp_almost    stamps  the stamp card is nearly full and the guest did not order for a while
 *   stamp_expiry    stamps  the oldest stamp is about to expire
 *   voucher_expiry  stamps  a personal voucher is about to expire unused
 *   winback         news    no order and no reservation for a long time (optional personal voucher)
 *   sunset          news    long inactive: one mail "do you still want this?"; no answer within 30 days -> the news are switched off (hygiene)
 * Rules: only guests whose consent for the topic is confirmed; at most one automatic mail per guest per 7 days (the welcome mail is exempt); every mail is claimed
 * in tp_shop_nl_auto_log before it is sent (never twice); every mail has the unsubscribe link. No tracking.
 */

const SHOP_NLA_CAP_DAYS = 7;
const SHOP_NLA_SUNSET_WAIT_DAYS = 30;

function shop_nla_schema($db, $opts) {
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_nl_auto_log')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `sub_id` INT UNSIGNED NOT NULL, `kind` VARCHAR(14) NOT NULL, `ref` VARCHAR(40) NOT NULL DEFAULT '', `sent_at` DATETIME NOT NULL, `ok` TINYINT NOT NULL DEFAULT 0,
		UNIQUE KEY `once` (`sub_id`, `kind`, `ref`), KEY `cap` (`sub_id`, `sent_at`), KEY `stat` (`kind`, `sent_at`)) $opts");
}

// title, topic, texts, the numbers the owner can set (field: key, label, unit eur|Tage|Monate|Stempel, min, max) and their defaults
function shop_nla_kinds() {
	return array(
		'welcome' => array('title' => 'Begrüßungsmail mit Gutschein', 'topic' => 'news', 'when' => 'Gleich nach der Bestätigung der Neuigkeiten',
			'desc' => 'Wer die Neuigkeiten per Mail bestätigt, bekommt als Dankeschön einen persönlichen Gutschein. Er gilt nur für diese E-Mail-Adresse und nur einmal. Gäste aus einem Import bekommen ihn nicht.',
			'def' => array('cents' => 500, 'min_cents' => 1500, 'coupon_days' => 30),
			'fields' => array(array('cents', 'Wert des Gutscheins', 'eur', 1, 100), array('min_cents', 'Gilt ab Warenwert', 'eur', 0, 200), array('coupon_days', 'Gültig für', 'Tage', 7, 365)),
			'vars' => array('gutschein', 'gutschein_wert', 'gutschein_bis', 'gutschein_ab')),
		'stamp_almost' => array('title' => 'Stempelkarte fast voll', 'topic' => 'stamps', 'when' => 'Wenn nur noch wenige Stempel fehlen und der Gast länger nicht bestellt hat',
			'desc' => 'Erinnert Gäste mit Stempel-Erinnerungen daran, dass ihre Karte fast voll ist. Pro Stand der Karte höchstens einmal, und nicht öfter als alle 60 Tage.',
			'def' => array('days' => 14, 'left' => 2), 'fields' => array(array('days', 'Wartezeit nach dem letzten Stempel', 'Tage', 3, 90), array('left', 'Erinnern, wenn höchstens so viele Stempel fehlen', 'Stempel', 1, 4)),
			'vars' => array('stempel_stand', 'stempel_fehlen', 'stempel_ziel')),
		'stamp_expiry' => array('title' => 'Stempel laufen ab', 'topic' => 'stamps', 'when' => 'Kurz bevor der älteste Stempel verfällt',
			'desc' => 'Warnt Gäste mit Stempel-Erinnerungen, bevor ein Stempel verfällt. Einmal pro Ablaufdatum.',
			'def' => array('days' => 14), 'fields' => array(array('days', 'Erinnern so lange vor dem Ablauf', 'Tage', 3, 60)), 'vars' => array('stempel_stand', 'stempel_ziel', 'stempel_bis')),
		'voucher_expiry' => array('title' => 'Gutschein läuft ab', 'topic' => 'stamps', 'when' => 'Kurz bevor ein persönlicher Gutschein unbenutzt verfällt',
			'desc' => 'Erinnert Gäste mit Stempel-Erinnerungen an ihren Gutschein (aus der Stempelkarte oder einer Aktion), bevor er verfällt. Einmal pro Gutschein.',
			'def' => array('days' => 7), 'fields' => array(array('days', 'Erinnern so lange vor dem Ablauf', 'Tage', 2, 30)), 'vars' => array('gutschein', 'gutschein_wert', 'gutschein_bis')),
		'winback' => array('title' => 'Wir vermissen dich', 'topic' => 'news', 'when' => 'Wenn der Gast lange weder bestellt noch reserviert hat',
			'desc' => 'Eine freundliche Mail an Gäste mit bestätigten Neuigkeiten, die lange nicht da waren, auf Wunsch mit einem persönlichen Gutschein. Danach mindestens 180 Tage Pause. Wer so lange weg ist, dass die Automatik "Noch Interesse?" greift, bekommt statt dieser Mail die Frage.',
			'def' => array('days' => 45, 'cents' => 0, 'min_cents' => 1500, 'coupon_days' => 30),
			'fields' => array(array('days', 'Tage ohne Bestellung und Reservierung', 'Tage', 21, 365), array('cents', 'Wert des Gutscheins (0 = keiner)', 'eur', 0, 100), array('min_cents', 'Gilt ab Warenwert', 'eur', 0, 200), array('coupon_days', 'Gutschein gültig für', 'Tage', 7, 365)),
			'vars' => array('gutschein', 'gutschein_wert', 'gutschein_bis', 'gutschein_ab')),
		'sunset' => array('title' => 'Noch Interesse?', 'topic' => 'news', 'when' => 'Nach langer Inaktivität, einmal',
			'desc' => 'Hält die Liste sauber: Wer lange weder bestellt noch reserviert hat, bekommt eine Mail mit der Frage, ob er weiter Neuigkeiten möchte. Ohne Antwort innerhalb von 30 Tagen werden die Neuigkeiten für ihn ausgeschaltet.',
			'def' => array('months' => 12), 'fields' => array(array('months', 'Monate ohne Bestellung und Reservierung', 'Monate', 6, 36)), 'vars' => array('halten')),
	);
}

function shop_nla_cfg() {
	$raw = json_decode((string)shop_setting('nla_cfg'), true); if (!is_array($raw)) { $raw = array(); }
	$out = array();
	foreach (shop_nla_kinds() as $k => $d) { $o = array_merge($d['def'], array('on' => 0, 'since' => ''), isset($raw[$k]) && is_array($raw[$k]) ? $raw[$k] : array()); $out[$k] = $o; }
	return $out;
}
function shop_nla_cfg_set($kind, $on, $params) {
	$kinds = shop_nla_kinds(); if (!isset($kinds[$kind])) { return false; }
	$cfg = shop_nla_cfg(); $c = $cfg[$kind];
	foreach ($kinds[$kind]['fields'] as $f) { if (isset($params[$f[0]])) { $m = $f[2] === 'eur' ? 100 : 1; $c[$f[0]] = max($f[3] * $m, min($f[4] * $m, (int)$params[$f[0]])); } }
	$on = $on ? 1 : 0;
	if ($on && empty($c['on'])) { $c['since'] = date('Y-m-d H:i:s'); }
	$c['on'] = $on;
	$cfg[$kind] = $c;
	shop_setting_set('nla_cfg', json_encode($cfg));
	return true;
}

// ---- templates -----------------------------------------------------------------------------------------------------------------------------------------

function shop_nla_default($kind) {
	$btn = array('t' => 'btn', 'text' => 'Jetzt bestellen', 'url' => shop_stamp_shop_url());
	switch ($kind) {
		case 'welcome': return array('subject' => 'Zwei Bestellungen am Tag: so hat unsere Lieferung angefangen', 'preheader' => 'Mit Lastenrädern über das Hildesheimer Pflaster. Und dein Gutschein ist auch da.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Willkommen, {vorname|Gast}'),
			array('t' => 'p', 'html' => 'April 2020. Im Amadeus ist es dunkel und still, an den Tischen sitzt niemand, im Hintergrund läuft keine Musik. Wir sind zu dritt: ein Koch, ich am Telefon, der die Bestellungen annimmt, und einer, der sie ausfährt. Mehr als ein, zwei Bestellungen am Tag sind es nicht.'),
			array('t' => 'p', 'html' => 'Ein Auto hatten wir nicht, also liehen wir uns Lastenräder beim <a href="https://hilde-lastenrad.de/">Hilde Lastenradverleih</a>. Das Hildesheimer Pflaster hat die Bestellungen ordentlich durchgeschüttelt, und trotzdem kamen sie an.'),
			array('t' => 'p', 'html' => 'Wir haben schnell dazugelernt. Ein paar Monate später sind wir in jede Ecke des Landkreises gefahren, sogar bis nach Sarstedt. Heute liefern wir im Stadtgebiet von Hildesheim, dafür ohne Umwege und mit warmem Essen.'),
			array('t' => 'p', 'html' => 'Dass du jetzt dabei bist, freut mich. Dafür bekommst du von mir einen Gutschein:'),
			array('t' => 'box', 'label' => 'Dein persönlicher Gutschein', 'value' => '{gutschein_wert}', 'note' => 'Code {gutschein}, gültig bis {gutschein_bis}, ab {gutschein_ab} Warenwert'),
			array('t' => 'p', 'html' => 'Gib den Code im Warenkorb ein. Er gilt nur für dich, mit dieser E-Mail-Adresse, und nur einmal.'),
			array('t' => 'p', 'html' => 'Wenn du nicht weißt, was du nehmen sollst: Nichts verkaufen wir häufiger als unsere Schnitzel.'), $btn,
			array('t' => 'p', 'html' => 'Zeus, meine Deutsche Dogge und unser Maskottchen, steht für Freundlichkeit, Wärme und Verlässlichkeit. So wollen wir auch bei dir ankommen.'),
			array('t' => 'zeus', 'text' => 'Bis bald, Hamun und Zeus')));
		case 'stamp_almost': return array('subject' => 'Noch {stempel_fehlen} Stempel, {vorname|Gast}, dann bist du dran', 'preheader' => '{stempel_stand} von {stempel_ziel} Stempeln sind voll.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Fast geschafft!'), array('t' => 'p', 'html' => '{stempel_stand} von {stempel_ziel} Stempeln sind voll, noch {stempel_fehlen}. Dann schenken wir dir einen Gutschein, den wir bei der nächsten Bestellung automatisch abziehen.'), $btn,
			array('t' => 'zeus', 'text' => 'Guten Appetit, Hamun und Zeus')));
		case 'stamp_expiry': return array('subject' => 'Dein ältester Stempel zählt noch bis {stempel_bis}', 'preheader' => 'Du hast {stempel_stand} von {stempel_ziel} Stempeln.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Dein Stempel wartet noch'), array('t' => 'p', 'html' => 'Bis zum {stempel_bis} gilt dein ältester Stempel noch. Du hast {stempel_stand} von {stempel_ziel}. Fehlt dir nur noch einer, dann ist jetzt der richtige Moment, deine Karte voll zu machen.'), $btn,
			array('t' => 'zeus', 'text' => 'Guten Appetit, Hamun und Zeus')));
		case 'voucher_expiry': return array('subject' => 'Dein Gutschein über {gutschein_wert} wartet bis {gutschein_bis}', 'preheader' => 'Wir ziehen ihn bei deiner nächsten Bestellung automatisch ab.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Dein Gutschein wartet noch, {vorname|Gast}'),
			array('t' => 'box', 'label' => 'Dein Gutschein', 'value' => '{gutschein_wert}', 'note' => 'gültig bis {gutschein_bis}'),
			array('t' => 'p', 'html' => 'Wir ziehen ihn bei deiner nächsten Bestellung automatisch ab. Du musst nichts eingeben. Nach dem {gutschein_bis} verfällt er.'), $btn,
			array('t' => 'zeus', 'text' => 'Guten Appetit, Hamun und Zeus')));
		case 'winback': return array('subject' => '{vorname|Gast}, wann gibt es wieder Schnitzel?', 'preheader' => 'Ein kurzer Gruß von Hamun, und etwas für deine nächste Bestellung.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Schön, wenn du wieder vorbeischaust'),
			array('t' => 'p', 'html' => 'Wir haben uns lange nicht gesehen. Das ist völlig in Ordnung, ich wollte mich trotzdem kurz melden.'),
			array('t' => 'p', 'html' => 'Bei uns brennt noch Licht in der Küche, und die Schnitzel gehen nach wie vor am besten. Komm vorbei, wenn dir danach ist, oder lass dir das Essen im Hildesheimer Stadtgebiet nach Hause bringen. Ich freue mich auf dich.'),
			array('t' => 'p', 'html' => 'Als kleines Willkommen zurück:'),
			array('t' => 'box', 'label' => 'Dein Gutschein', 'value' => '{gutschein_wert}', 'note' => 'Code {gutschein}, gültig bis {gutschein_bis}'), $btn,
			array('t' => 'zeus', 'text' => 'Bis bald, Hamun und Zeus')));
		case 'sunset': return array('subject' => 'Möchtest du weiter Post von uns, {vorname|Gast}?', 'preheader' => 'Ein Tipp genügt, sonst höre ich in '.SHOP_NLA_SUNSET_WAIT_DAYS.' Tagen auf.', 'blocks' => array(
			array('t' => 'h', 'text' => 'Bleiben wir in Kontakt?'),
			array('t' => 'p', 'html' => 'Wir haben lange nichts voneinander gehört, {vorname|Gast}. Bevor meine Mails bei dir nur noch Platz im Postfach wegnehmen, frage ich lieber einmal nach.'),
			array('t' => 'p', 'html' => 'Wenn du weiter Neuigkeiten aus dem Amadeus möchtest, tippe auf den Knopf. Sonst höre ich in '.SHOP_NLA_SUNSET_WAIT_DAYS.' Tagen von selbst auf, dir zu schreiben. Mails zu deinen Bestellungen bekommst du natürlich weiterhin.'),
			array('t' => 'btn', 'text' => 'Ja, gerne weiter', 'url' => '{halten}'),
			array('t' => 'zeus', 'text' => 'Alles Gute, Hamun und Zeus')));
	}
	return array('subject' => '', 'blocks' => array());
}
function shop_nla_template($kind) {
	shop_ensure_schema(); $kinds = shop_nla_kinds(); if (!isset($kinds[$kind])) { return null; }
	$r = fb_row("SELECT * FROM ".fb_t('tp_shop_nl_campaigns')." WHERE kind = ? LIMIT 1", 's', array($kind));
	if ($r) { return $r; }
	$d = shop_nla_default($kind); $now = date('Y-m-d H:i:s');
	fb_exec("INSERT INTO ".fb_t('tp_shop_nl_campaigns')." (name, subject, preheader, blocks, status, kind, created_at, updated_at) VALUES (?, ?, ?, ?, 'auto', ?, ?, ?)", 'sssssss',
		array($kinds[$kind]['title'], $d['subject'], isset($d['preheader']) ? $d['preheader'] : '', json_encode($d['blocks'], JSON_UNESCAPED_UNICODE), $kind, $now, $now));
	return fb_row("SELECT * FROM ".fb_t('tp_shop_nl_campaigns')." WHERE kind = ? LIMIT 1", 's', array($kind));
}
function shop_nla_sample_vars() {
	return array('gutschein' => 'HALLO-DEMO42', 'gutschein_wert' => '5,00 €', 'gutschein_bis' => date('d.m.Y', strtotime('+30 days')), 'gutschein_ab' => '15,00 €', 'stempel_stand' => '3', 'stempel_fehlen' => '2', 'stempel_ziel' => '5',
		'stempel_bis' => date('d.m.Y', strtotime('+14 days')), 'halten' => shop_nl_base().'newsletter.php?m=0&keep=1');
}

// ---- helpers ---------------------------------------------------------------------------------------------------------------------------------------------

function shop_nla_keys($sub) {
	if (!empty($sub['account_id'])) {
		if (!function_exists('shop_acc_keys')) { require_once __DIR__.'/shop_account.class.php'; }
		$acc = fb_row("SELECT * FROM ".fb_t('tp_shop_accounts')." WHERE id = ?", 'i', array((int)$sub['account_id']));
		if ($acc) { return shop_acc_keys($acc); }
	}
	return shop_coupon_guest_keys('', $sub['email']);
}
// the last order or reservation of this address (timestamp, 0 = none)
function shop_nla_last_activity($email) {
	$t = 0; $e = shop_nl_email($email);
	try {
		$o = fb_row("SELECT MAX(created_at) AS m FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND LOWER(email) = ?", 's', array($e)); if ($o && $o['m']) { $t = max($t, strtotime($o['m'])); }
		global $dbTables;
		if (is_object($dbTables) && !empty($dbTables->reservations)) {
			$r = fb_row("SELECT MAX(reservation_date) AS m FROM `".$dbTables->reservations."` WHERE LOWER(reservation_guest_email) = ? AND reservation_date <= CURDATE()", 's', array($e)); if ($r && $r['m']) { $t = max($t, strtotime($r['m'])); }
		}
	} catch (Throwable $x) { error_log('mySeat newsletter activity: '.$x->getMessage()); }
	return $t;
}
function shop_nla_logged($subId, $kind, $withinDays = 0) {
	return (bool)fb_row("SELECT id FROM ".fb_t('tp_shop_nl_auto_log')." WHERE sub_id = ? AND kind = ?".($withinDays > 0 ? " AND sent_at > ?" : '')." LIMIT 1",
		$withinDays > 0 ? 'iss' : 'is', $withinDays > 0 ? array((int)$subId, $kind, date('Y-m-d H:i:s', time() - $withinDays * 86400)) : array((int)$subId, $kind));
}
function shop_nla_capped($subId) { return (bool)fb_row("SELECT id FROM ".fb_t('tp_shop_nl_auto_log')." WHERE sub_id = ? AND sent_at > ? LIMIT 1", 'is', array((int)$subId, date('Y-m-d H:i:s', time() - SHOP_NLA_CAP_DAYS * 86400))); }

// a personal voucher bound to the e-mail address: fixed amount, one use, from a minimum goods value; returns the variables for the mail or null
function shop_nla_coupon($sub, $prefix, $source, $cents, $minCents, $days, $note) {
	if ($cents <= 0) { return null; }
	$keys = shop_coupon_guest_keys('', $sub['email']); $key = $keys[1]; if ($key === '') { return null; }
	$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
	for ($t = 0; $t < 6 && $code === ''; $t++) { $c = $prefix.'-'; for ($i = 0; $i < 6; $i++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; } if (!shop_coupon_by_code($c)) { $code = $c; } }
	if ($code === '') { $code = $prefix.'-'.strtoupper(bin2hex(random_bytes(4))); }
	$until = date('Y-m-d H:i:s', strtotime('+'.(int)$days.' days'));
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_from, valid_until, max_uses, per_guest, used, active, created_at, source, guest_key, guest_key2, parent_id)
		VALUES (?, ?, 'fixed', ?, 0, ?, 'all', NULL, ?, 1, 0, 0, 1, ?, ?, '', ?, NULL)", 'ssiissss', array($code, mb_substr($note, 0, 160), (int)$cents, (int)$minCents, $until, date('Y-m-d H:i:s'), $source, $key));
	if (!$st) { return null; }
	return array('gutschein' => $code, 'gutschein_wert' => shop_money((int)$cents), 'gutschein_bis' => date('d.m.Y', strtotime($until)), 'gutschein_ab' => shop_money((int)$minCents));
}

function shop_nla_send($sub, $kind, $vars) {
	global $settings;
	$tpl = shop_nla_template($kind); $from = shop_mail_from(); if (!$tpl || $from === '') { return false; }
	$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus'; $kinds = shop_nla_kinds();
	$sub['vars'] = $vars;
	$m = shop_nlm_render($tpl, $sub, array('topic' => $kinds[$kind]['topic']));
	try { return bm_send_guest_mail($sub['email'], array('subject' => $m['subject'], 'plain' => $m['plain'], 'html' => $m['html'], 'ics' => '', 'ics_filename' => '', 'purpose' => 'news', 'headers' => $m['headers']), $brand, $from) !== false; }
	catch (Throwable $e) { error_log('mySeat newsletter auto: '.$e->getMessage()); return false; }
}
// claim, then send: $make returns the variables (and may create a voucher); a second run finds the claim and does nothing
function shop_nla_try($sub, $kind, $ref, $make, $cap = true) {
	$kinds = shop_nla_kinds();
	if (!shop_nl_allows($sub['email'], $kinds[$kind]['topic'])) { return false; }
	if ($cap && shop_nla_capped($sub['id'])) { return false; }
	if ($kind !== 'welcome' && $kinds[$kind]['topic'] === 'news' && shop_nl_news_count((int)$sub['id']) >= SHOP_NL_NEWS_MAX) { return false; }   // the promise of the tick: at most two news mails a month
	$st = fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_nl_auto_log')." (sub_id, kind, ref, sent_at, ok) VALUES (?, ?, ?, ?, 0)", 'isss', array((int)$sub['id'], $kind, $ref, date('Y-m-d H:i:s')));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return false; }
	$vars = $make(); $ok = $vars !== false && shop_nla_send($sub, $kind, is_array($vars) ? $vars : array());
	if ($ok) { fb_exec("UPDATE ".fb_t('tp_shop_nl_auto_log')." SET ok = 1 WHERE sub_id = ? AND kind = ? AND ref = ?", 'iss', array((int)$sub['id'], $kind, $ref)); }
	return $ok;
}

// ---- the kinds ------------------------------------------------------------------------------------------------------------------------------------------

function shop_nla_welcome($sub, $c) {
	return shop_nla_try($sub, 'welcome', '', function () use ($sub, $c) { $v = shop_nla_coupon($sub, 'HALLO', 'welcome', (int)$c['cents'], (int)$c['min_cents'], (int)$c['coupon_days'], 'Willkommensgutschein '.$sub['email']); return $v ?: array(); }, false);
}
// right after the confirmation (called by shop_nl_confirm); the runner catches up later if this fails
function shop_nla_welcome_now($subId) {
	try {
		$cfg = shop_nla_cfg(); if (empty($cfg['welcome']['on'])) { return; }
		$sub = fb_row("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE id = ?", 'i', array((int)$subId));
		if ($sub && $sub['source'] !== 'import') { shop_nla_welcome($sub, $cfg['welcome']); }
	} catch (Throwable $e) { error_log('mySeat newsletter welcome: '.$e->getMessage()); }
}

function shop_nla_run($budget = 20, $seconds = 20) {
	shop_ensure_schema();
	$cfg = shop_nla_cfg(); $any = false; foreach ($cfg as $c) { if (!empty($c['on'])) { $any = true; } } if (!$any) { return 0; }
	$db = fb_db(); $lock = mysqli_real_escape_string($db, fb_t('tp_shop_nl_auto'));
	$r = mysqli_query($db, "SELECT GET_LOCK('".$lock."', 0) AS l"); $row = $r ? mysqli_fetch_assoc($r) : null; if (!$row || (int)$row['l'] !== 1) { return 0; }
	$sent = 0; $t0 = time(); $now = date('Y-m-d H:i:s'); $stCfg = shop_stamp_cfg();
	$room = function () use (&$sent, $budget, $t0, $seconds) { return $sent < $budget && time() - $t0 < $seconds; };
	try {
		// welcome: confirmed in the last 7 days (and after it was switched on), not from an import
		if (!empty($cfg['welcome']['on'])) {
			$from = max(strtotime($cfg['welcome']['since'] ?: '2000-01-01'), time() - 7 * 86400);
			foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE news = 1 AND blocked = 0 AND source <> 'import' AND confirmed_at >= ? ORDER BY id LIMIT 100", 's', array(date('Y-m-d H:i:s', $from))) as $s) {
				if (!$room()) { break; } if (shop_nla_welcome($s, $cfg['welcome'])) { $sent++; }
			}
		}
		// the three reminders of the stamp card
		$stampOn = !empty($cfg['stamp_almost']['on']) || !empty($cfg['stamp_expiry']['on']) || !empty($cfg['voucher_expiry']['on']);
		if ($stampOn) {
			foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE stamps = 1 AND blocked = 0 ORDER BY id LIMIT 600") as $s) {
				if (!$room()) { break; }
				$keys = shop_nla_keys($s); if (!shop_stamp_has_keys($keys)) { continue; }
				if (!empty($cfg['voucher_expiry']['on'])) {
					$v = fb_row("SELECT * FROM ".fb_t('tp_shop_coupons')." WHERE source IN ('stamp', 'offer') AND active = 1 AND used < max_uses AND valid_until > ? AND valid_until < ? AND ".shop_stamp_match()." ORDER BY valid_until LIMIT 1",
						'ssss', array($now, date('Y-m-d H:i:s', time() + (int)$cfg['voucher_expiry']['days'] * 86400), $keys[0], $keys[1]));
					if ($v && shop_nla_try($s, 'voucher_expiry', $v['code'], function () use ($v) { return array('gutschein' => $v['code'], 'gutschein_wert' => shop_money((int)$v['value']), 'gutschein_bis' => date('d.m.Y', strtotime($v['valid_until']))); })) { $sent++; continue; }
				}
				$open = shop_stamp_open($keys); $n = count($open); if ($n < 1) { continue; }
				$vars = array('stempel_stand' => (string)$n, 'stempel_ziel' => (string)$stCfg['goal'], 'stempel_fehlen' => (string)max(0, $stCfg['goal'] - $n), 'stempel_bis' => date('d.m.Y', strtotime($open[0]['expires_at'])));
				if (!empty($cfg['stamp_expiry']['on']) && strtotime($open[0]['expires_at']) - time() <= (int)$cfg['stamp_expiry']['days'] * 86400 && shop_nla_try($s, 'stamp_expiry', date('Y-m-d', strtotime($open[0]['expires_at'])), function () use ($vars) { return $vars; })) { $sent++; continue; }
				if (!empty($cfg['stamp_almost']['on']) && $stCfg['goal'] - $n >= 1 && $stCfg['goal'] - $n <= (int)$cfg['stamp_almost']['left']) {
					$last = 0; foreach ($open as $o) { $last = max($last, strtotime($o['earned_at'])); }
					if (time() - $last >= (int)$cfg['stamp_almost']['days'] * 86400 && !shop_nla_logged($s['id'], 'stamp_almost', 60) && shop_nla_try($s, 'stamp_almost', 'n'.$n, function () use ($vars) { return $vars; })) { $sent++; }
				}
			}
		}
		// winback
		if (!empty($cfg['winback']['on'])) {
			$c = $cfg['winback'];
			foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE news = 1 AND blocked = 0 AND confirmed_at < ? ORDER BY id LIMIT 600", 's', array(date('Y-m-d H:i:s', time() - (int)$c['days'] * 86400))) as $s) {
				if (!$room()) { break; }
				if (shop_nla_logged($s['id'], 'winback', 180) || shop_nla_capped($s['id'])) { continue; }
				$idle = time() - max(strtotime($s['confirmed_at']), shop_nla_last_activity($s['email']));
				if ($idle < (int)$c['days'] * 86400) { continue; }
				if (!empty($cfg['sunset']['on']) && $idle >= (int)$cfg['sunset']['months'] * 30 * 86400) { continue; }   // that guest gets the question of the sunset mail instead
				if (shop_nla_try($s, 'winback', date('Y-m-d'), function () use ($s, $c) { $v = shop_nla_coupon($s, 'ZURUECK', 'winback', (int)$c['cents'], (int)$c['min_cents'], (int)$c['coupon_days'], 'Gutschein Rückholmail '.$s['email']); return $v ?: array(); })) { $sent++; }
			}
		}
		// sunset: ask once; no answer after 30 days -> news off
		if (!empty($cfg['sunset']['on'])) {
			$limit = time() - (int)$cfg['sunset']['months'] * 30 * 86400;
			foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE news = 1 AND blocked = 0 AND confirmed_at < ? ORDER BY id LIMIT 600", 's', array(date('Y-m-d H:i:s', $limit))) as $s) {
				$keep = fb_row("SELECT MAX(at) AS m FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ? AND event = 'keep'", 'i', array((int)$s['id']));
				$base = max(strtotime($s['confirmed_at']), $keep && $keep['m'] ? strtotime($keep['m']) : 0, shop_nla_last_activity($s['email']));
				if ($base >= $limit) { continue; }
				$asked = fb_row("SELECT sent_at FROM ".fb_t('tp_shop_nl_auto_log')." WHERE sub_id = ? AND kind = 'sunset' AND ok = 1 ORDER BY id DESC LIMIT 1", 'i', array((int)$s['id']));
				if ($asked && strtotime($asked['sent_at']) > $base) {
					if (time() - strtotime($asked['sent_at']) >= SHOP_NLA_SUNSET_WAIT_DAYS * 86400) {
						fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET news = 0, unsub_at = IF(stamps = 0, ?, unsub_at), updated_at = ? WHERE id = ?", 'ssi', array($now, $now, (int)$s['id']));
						shop_nl_log((int)$s['id'], 'unsubscribe', array(), 'auto', 'automatisch: keine Antwort nach '.(int)$cfg['sunset']['months'].' Monaten ohne Aktivität');
					}
					continue;
				}
				if (!$room()) { continue; }
				if (shop_nla_try($s, 'sunset', 'y'.date('Y-m'), function () use ($s) { return array('halten' => shop_nl_base().'newsletter.php?m='.$s['token'].'&keep=1'); })) { $sent++; }
			}
		}
	} finally { mysqli_query($db, "SELECT RELEASE_LOCK('".$lock."')"); }
	return $sent;
}
// the backend page and the cron call this; the state scans run every ten minutes at most
function shop_nla_tick() {
	try {
		$any = false; foreach (shop_nla_cfg() as $c) { if (!empty($c['on'])) { $any = true; } } if (!$any) { return; }
		if (time() - (int)shop_setting('nla_last_run') < 600) { return; }
		shop_setting_set('nla_last_run', (string)time());
		shop_nla_run(8, 12);
	} catch (Throwable $e) { error_log('mySeat newsletter auto tick: '.$e->getMessage()); }
}

// what the owner sees: every automation with its numbers, the state and what it did in the last 30 days
function shop_nla_overview() {
	shop_ensure_schema(); $cfg = shop_nla_cfg(); $out = array();
	foreach (shop_nla_kinds() as $k => $d) {
		$n = fb_row("SELECT COUNT(*) AS n, SUM(ok = 0) AS bad FROM ".fb_t('tp_shop_nl_auto_log')." WHERE kind = ? AND sent_at > ?", 'ss', array($k, date('Y-m-d H:i:s', time() - 30 * 86400)));
		$tpl = shop_nla_template($k);
		$out[] = array('kind' => $k, 'title' => $d['title'], 'topic' => $d['topic'], 'when' => $d['when'], 'desc' => $d['desc'], 'fields' => $d['fields'], 'vars' => $d['vars'], 'cfg' => $cfg[$k], 'on' => (int)$cfg[$k]['on'],
			'n30' => $n ? (int)$n['n'] : 0, 'bad30' => $n ? (int)$n['bad'] : 0, 'template' => $tpl ? (int)$tpl['id'] : 0);
	}
	return $out;
}
