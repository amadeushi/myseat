<?php
/*
 * Guest accounts of the order shop. No password, no registration: an account is a confirmed mobile number and/or e-mail address.
 * It is kept only as the hashes the stamp card and the coupons already use (shop_coupon_guest_keys), so the orders, the stamps and
 * the voucher of that guest are found without any migration.
 *
 * Sign-in: the guest types an e-mail address or a mobile number, we send a 6 digit code and a link. Both belong to ONE row of
 * tp_shop_login_codes - whichever is used first uses up the other. The code is only stored as a hash (HMAC with a server secret),
 * so is the link token; both are valid for SHOP_ACC_TTL seconds and a code survives SHOP_ACC_MAX_TRIES wrong guesses.
 * The answer to a request never tells whether an account exists. A session is a random token in a cookie (httpOnly, SameSite=Lax),
 * kept in the database as a hash.
 */
require_once __DIR__.'/shop.class.php';

const SHOP_ACC_TTL = 600;          // seconds a code / link stays valid
const SHOP_ACC_MAX_TRIES = 5;      // wrong guesses per code
const SHOP_ACC_SESSION_DAYS = 90;  // sliding
const SHOP_ACC_COOKIE = 'amd_acct';
const SHOP_ACC_MAX_FAVS = 30;

function shop_acc_enabled() { return shop_flag('account_on'); }
function shop_acc_hash($v) { return hash_hmac('sha256', (string)$v, sms_secret()); }
function shop_acc_ip_hash() { return substr(shop_acc_hash('ip|'.(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '')), 0, 16); }
function shop_acc_now() { return date('Y-m-d H:i:s'); }

/*
 * What the guest typed: an e-mail address or a mobile number (a landline cannot receive an SMS).
 * Returns array(ok, kind 'phone'|'mail', key (guest key), to (E.164 number or address), mask (what we may show back)) or array(ok => false, error).
 */
function shop_acc_target($input) {
	$s = trim(mb_substr((string)$input, 0, 160));
	if ($s === '') { return array('ok' => false, 'error' => 'Bitte gib deine E-Mail-Adresse oder Handynummer ein.'); }
	if (strpos($s, '@') !== false) {
		$mail = strtolower($s);
		if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Die E-Mail-Adresse sieht nicht richtig aus.'); }
		$k = shop_coupon_guest_keys('', $mail);
		list($u, $d) = explode('@', $mail, 2);
		return array('ok' => true, 'kind' => 'mail', 'key' => $k[1], 'to' => $mail, 'mask' => mb_substr($u, 0, 1).'•••@'.$d);
	}
	$m = sms_normalize_phone($s);
	if ($m === null) { return array('ok' => false, 'error' => 'Das ist keine Handynummer, an die wir eine SMS schicken können. Nimm bitte deine E-Mail-Adresse.'); }
	$k = shop_coupon_guest_keys($m, '');
	return array('ok' => true, 'kind' => 'phone', 'key' => $k[0], 'to' => $m, 'mask' => '•••• '.substr($m, -4));
}

// ---- request: make a code + link, send them
function shop_acc_request($input, $purpose = 'login', $accountId = null) {
	shop_ensure_schema();
	if (!shop_acc_enabled()) { return array('ok' => false, 'error' => 'Das Kundenkonto ist gerade nicht verfügbar.'); }
	$t = shop_acc_target($input);
	if (!$t['ok']) { return $t; }
	$now = shop_acc_now();
	if ($t['kind'] === 'phone' && (!sms_enabled() || !shop_flag('account_sms'))) {
		return array('ok' => false, 'error' => 'Anmelden per SMS geht gerade nicht. Bitte nimm deine E-Mail-Adresse.');
	}
	// limits that are the same for every target, so they tell nothing about who is a guest
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_login_codes')." WHERE target_key = ? AND created_at > ?", 'ss', array($t['key'], date('Y-m-d H:i:s', time() - 900)));
	if ($n && (int)$n['n'] >= 3) { return array('ok' => false, 'error' => 'Du hast gerade schon mehrere Codes angefordert. Bitte warte ein paar Minuten und schau auch im Spam-Ordner nach.'); }
	$ip = shop_acc_ip_hash();
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_login_codes')." WHERE ip_hash = ? AND created_at > ?", 'ss', array($ip, date('Y-m-d H:i:s', time() - 3600)));
	if ($n && (int)$n['n'] >= 10) { return array('ok' => false, 'error' => 'Zu viele Anfragen. Bitte versuche es später noch einmal.'); }
	if ($t['kind'] === 'phone') {
		$cap = max(1, min(5000, (int)shop_setting('account_sms_daily')));
		$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_login_codes')." WHERE kind = 'phone' AND created_at >= ?", 's', array(date('Y-m-d 00:00:00')));
		if ($n && (int)$n['n'] >= $cap) { return array('ok' => false, 'error' => 'Heute gehen leider keine weiteren Codes per SMS raus. Bitte nimm deine E-Mail-Adresse.'); }
	}
	$code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	$token = bin2hex(random_bytes(20));
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_login_codes')." (kind, target_key, purpose, account_id, code_hash, link_hash, expires_at, ip_hash, created_at, target_plain) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
		'sssissssss', array($t['kind'], $t['key'], $purpose === 'link' ? 'link' : 'login', $accountId, shop_acc_hash($t['key'].'|'.$code), shop_acc_hash($token), date('Y-m-d H:i:s', time() + SHOP_ACC_TTL), $ip, $now, $t['to']));
	if (!$st) { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es noch einmal.'); }
	$link = shop_site_url().'/order/anmelden.php?t='.$token;
	$res = ($t['kind'] === 'phone') ? shop_acc_send_sms($t['to'], $code, $link) : shop_acc_send_mail($t['to'], $code, $link);
	if (!$res) {
		// not sent: the row is of no use, and must not count against the guest's three tries
		fb_exec("DELETE FROM ".fb_t('tp_shop_login_codes')." WHERE link_hash = ?", 's', array(shop_acc_hash($token)));
		return array('ok' => false, 'error' => $t['kind'] === 'phone' ? 'Die SMS konnte nicht verschickt werden. Bitte versuche es noch einmal oder nimm deine E-Mail-Adresse.' : 'Die E-Mail konnte nicht verschickt werden. Bitte versuche es noch einmal.');
	}
	if (random_int(1, 50) === 1) { shop_acc_purge(); }
	return array('ok' => true, 'kind' => $t['kind'], 'mask' => $t['mask'], 'minutes' => (int)(SHOP_ACC_TTL / 60));
}

function shop_acc_brand() { global $settings; return !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus'; }

// SMS in the GSM alphabet (160 characters): code and a short link (YOURLS); without the short link only the code. True when handed to the queue.
function shop_acc_send_sms($to, $code, $link) {
	$brand = shop_acc_brand();
	$short = '';
	if (sms_link_ready()) {
		$r = sms_yourls_shorten($link, (int)(SHOP_ACC_TTL / 60), 'mySeat Anmeldung');
		if (!empty($r['ok']) && !empty($r['short'])) { $short = $r['short']; }
	}
	$text = $short !== ''
		? sms_fit('', $brand, ': Dein Code '.$code.', gültig 10 Min. Direkt anmelden: '.$short.' Nicht du? Ignoriere diese SMS.')
		: sms_fit('', $brand, ': Dein Anmeldecode ist '.$code.', gültig 10 Minuten. Gib ihn niemandem weiter.');
	$st = sms_enqueue(null, $to, 'login', $text);
	return in_array($st, array('accepted', 'queued'), true);
}

function shop_acc_send_mail($to, $code, $link) {
	if (shop_mail_from() === '') { return false; }
	shop_stamp_mail($to, array(
		'subject' => 'Dein Anmeldecode: '.$code, 'headline' => 'Dein Anmeldecode', 'lead' => 'Gib diesen Code im Bestellshop ein, oder tippe auf den Knopf, dann bist du gleich drin.',
		'image' => '', 'alt' => '', 'box' => array('label' => 'Anmeldecode', 'value' => $code, 'note' => 'gültig '.(int)(SHOP_ACC_TTL / 60).' Minuten, nur einmal nutzbar'),
		'after' => 'Du hast das nicht angefordert? Dann kannst du diese Mail einfach löschen, ohne den Code passiert nichts.',
		'btn' => 'Jetzt anmelden', 'url' => $link, 'signoff' => 'Dein Team von '.shop_acc_brand()));
	return true;
}

// ---- redeem: by code (the browser knows the target) or by link (the token)
function shop_acc_verify_code($input, $code) {
	shop_ensure_schema();
	$t = shop_acc_target($input);
	if (!$t['ok']) { return $t; }
	$code = preg_replace('/\D/', '', (string)$code);
	$row = fb_row("SELECT * FROM ".fb_t('tp_shop_login_codes')." WHERE target_key = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1", 'ss', array($t['key'], shop_acc_now()));
	if (!$row) { return array('ok' => false, 'error' => 'Der Code ist abgelaufen. Bitte fordere einen neuen an.', 'expired' => true); }
	if ((int)$row['attempts'] >= SHOP_ACC_MAX_TRIES) { return array('ok' => false, 'error' => 'Zu viele falsche Eingaben. Bitte fordere einen neuen Code an.', 'expired' => true); }
	if (strlen($code) !== 6 || !hash_equals($row['code_hash'], shop_acc_hash($t['key'].'|'.$code))) {
		fb_exec("UPDATE ".fb_t('tp_shop_login_codes')." SET attempts = attempts + 1 WHERE id = ?", 'i', array((int)$row['id']));
		$left = max(0, SHOP_ACC_MAX_TRIES - (int)$row['attempts'] - 1);
		return array('ok' => false, 'error' => $left > 0 ? 'Der Code stimmt nicht. Du hast noch '.$left.($left === 1 ? ' Versuch.' : ' Versuche.') : 'Der Code stimmt nicht. Bitte fordere einen neuen an.', 'left' => $left, 'expired' => $left === 0);
	}
	return shop_acc_consume($row, $t);
}

// the page behind the link only shows a button: a mail scanner opening the link must not use it up
function shop_acc_link_row($token) {
	shop_ensure_schema();
	if (!preg_match('/^[a-f0-9]{40}$/', (string)$token)) { return null; }
	return fb_row("SELECT * FROM ".fb_t('tp_shop_login_codes')." WHERE link_hash = ? AND used_at IS NULL AND expires_at > ? AND attempts < ?", 'ssi', array(shop_acc_hash($token), shop_acc_now(), SHOP_ACC_MAX_TRIES));
}
function shop_acc_verify_link($token) {
	$row = shop_acc_link_row($token);
	if (!$row) { return array('ok' => false, 'error' => 'Dieser Link ist abgelaufen oder wurde schon benutzt. Bitte fordere einen neuen Code an.'); }
	return shop_acc_consume($row, null);
}

function shop_acc_consume($row, $t) {
	$now = shop_acc_now();
	// exactly one winner when two requests redeem the same row
	$st = fb_exec("UPDATE ".fb_t('tp_shop_login_codes')." SET used_at = ? WHERE id = ? AND used_at IS NULL", 'si', array($now, (int)$row['id']));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return array('ok' => false, 'error' => 'Dieser Code wurde schon benutzt. Bitte fordere einen neuen an.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_login_codes')." SET used_at = ? WHERE target_key = ? AND used_at IS NULL", 'ss', array($now, $row['target_key']));
	$col = $row['kind'] === 'phone' ? 'key_phone' : 'key_mail';
	$maskCol = $row['kind'] === 'phone' ? 'mask_phone' : 'mask_mail';
	$mask = $t ? $t['mask'] : '';
	if ($row['purpose'] === 'link' && $row['account_id']) {
		// a second way to reach the same guest, confirmed while signed in
		$acc = fb_row("SELECT * FROM ".fb_t('tp_shop_accounts')." WHERE id = ?", 'i', array((int)$row['account_id']));
		if (!$acc) { return array('ok' => false, 'error' => 'Das Konto gibt es nicht mehr.'); }
		$other = fb_row("SELECT id FROM ".fb_t('tp_shop_accounts')." WHERE $col = ? AND id <> ?", 'si', array($row['target_key'], (int)$acc['id']));
		if ($other) { return array('ok' => false, 'error' => 'Diese Angabe gehört schon zu einem anderen Konto.'); }
		fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET $col = ?, $maskCol = ? WHERE id = ?", 'ssi', array($row['target_key'], $mask, (int)$acc['id']));
	} else {
		$acc = fb_row("SELECT * FROM ".fb_t('tp_shop_accounts')." WHERE $col = ?", 's', array($row['target_key']));
		if (!$acc) {
			fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_accounts')." ($col, $maskCol, created_at) VALUES (?, ?, ?)", 'sss', array($row['target_key'], $mask, $now));
			$acc = fb_row("SELECT * FROM ".fb_t('tp_shop_accounts')." WHERE $col = ?", 's', array($row['target_key']));
		}
	}
	if (!$acc) { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es noch einmal.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET last_login_at = ? WHERE id = ?", 'si', array($now, (int)$acc['id']));
	if (!empty($row['target_plain'])) {
		$cc = $row['kind'] === 'phone' ? 'contact_phone' : 'contact_mail';
		// only when still empty: a number the guest entered by hand in the account stays
		fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET $cc = ? WHERE id = ? AND ($cc = '' OR $cc IS NULL)", 'si', array(mb_substr($row['target_plain'], 0, 160), (int)$acc['id']));
	}
	shop_acc_session_start((int)$acc['id']);
	return array('ok' => true, 'account_id' => (int)$acc['id']);
}

// ---- session
function shop_acc_cookie_path() {
	$p = parse_url(shop_site_url().'/order/', PHP_URL_PATH);
	return ($p !== null && $p !== false && $p !== '') ? $p : '/order/';
}
function shop_acc_session_start($accountId) {
	$token = bin2hex(random_bytes(32)); $now = shop_acc_now(); $exp = date('Y-m-d H:i:s', time() + SHOP_ACC_SESSION_DAYS * 86400);
	fb_exec("INSERT INTO ".fb_t('tp_shop_sessions')." (token_hash, account_id, created_at, last_seen, expires_at, ua) VALUES (?, ?, ?, ?, ?, ?)",
		'sissss', array(shop_acc_hash($token), (int)$accountId, $now, $now, $exp, substr(preg_replace('/[^\x20-\x7e]/', '', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 80)));
	if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); }
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	setcookie(SHOP_ACC_COOKIE, $token, array('expires' => time() + SHOP_ACC_SESSION_DAYS * 86400, 'path' => shop_acc_cookie_path(), 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax'));
	$_COOKIE[SHOP_ACC_COOKIE] = $token;
	shop_acc_current(true);
}

// the signed-in account of this request, or null
function shop_acc_current($reset = false) {
	static $cur = false;
	if ($reset) { $cur = false; }
	if ($cur !== false) { return $cur; }
	$cur = null;
	$tok = isset($_COOKIE[SHOP_ACC_COOKIE]) ? (string)$_COOKIE[SHOP_ACC_COOKIE] : '';
	if (!shop_acc_enabled() || !preg_match('/^[a-f0-9]{64}$/', $tok)) { return null; }
	shop_ensure_schema();
	$h = shop_acc_hash($tok); $now = shop_acc_now();
	$s = fb_row("SELECT s.id AS sid, s.last_seen, a.* FROM ".fb_t('tp_shop_sessions')." s JOIN ".fb_t('tp_shop_accounts')." a ON a.id = s.account_id WHERE s.token_hash = ? AND s.expires_at > ?", 'ss', array($h, $now));
	if (!$s) { return null; }
	if (strtotime($s['last_seen']) < time() - 3600) {
		fb_exec("UPDATE ".fb_t('tp_shop_sessions')." SET last_seen = ?, expires_at = ? WHERE id = ?", 'ssi', array($now, date('Y-m-d H:i:s', time() + SHOP_ACC_SESSION_DAYS * 86400), (int)$s['sid']));
	}
	$cur = $s;
	return $cur;
}
function shop_acc_keys($acc) { return array(isset($acc['key_phone']) && $acc['key_phone'] !== null ? $acc['key_phone'] : '', isset($acc['key_mail']) && $acc['key_mail'] !== null ? $acc['key_mail'] : ''); }

function shop_acc_logout($all = false) {
	$acc = shop_acc_current();
	if ($acc) {
		if ($all) { fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE account_id = ?", 'i', array((int)$acc['id'])); }
		else { fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE id = ?", 'i', array((int)$acc['sid'])); }
	}
	setcookie(SHOP_ACC_COOKIE, '', array('expires' => time() - 3600, 'path' => shop_acc_cookie_path(), 'httponly' => true, 'samesite' => 'Lax'));
	unset($_COOKIE[SHOP_ACC_COOKIE]);
	shop_acc_current(true);
}
// the account and everything that belongs only to it; the orders stay (they are kept for the books)
function shop_acc_delete($acc) {
	$id = (int)$acc['id'];
	fb_exec("DELETE FROM ".fb_t('tp_shop_favorites')." WHERE account_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE account_id = ?", 'i', array($id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_accounts')." WHERE id = ?", 'i', array($id));
	shop_acc_logout(false);
}
function shop_acc_purge() {
	fb_exec("DELETE FROM ".fb_t('tp_shop_login_codes')." WHERE created_at < ?", 's', array(date('Y-m-d H:i:s', time() - 86400)));
	fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE expires_at < ?", 's', array(shop_acc_now()));
}

// ---- what the account shows: stamps (the existing card), orders, favorites
function shop_acc_backfill_keys() {
	$last = 0;
	for ($round = 0; $round < 30; $round++) {
		$rows = fb_rows("SELECT id, phone, email FROM ".fb_t('tp_shop_orders')." WHERE guest_key = '' AND guest_key2 = '' AND (phone <> '' OR email <> '') AND id > ? ORDER BY id LIMIT 300", 'i', array($last));
		if (!$rows) { return; }
		foreach ($rows as $r) {
			$k = shop_coupon_guest_keys($r['phone'], $r['email']); $last = (int)$r['id'];
			// '-' marks an order that cannot be matched (no digits, no address), so it is not looked at again and again
			fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET guest_key = ?, guest_key2 = ? WHERE id = ?", 'ssi', array($k[0] !== '' || $k[1] !== '' ? $k[0] : '-', $k[1], (int)$r['id']));
		}
	}
}
function shop_acc_orders($acc, $limit = 20) {
	shop_acc_backfill_keys();
	$k = shop_acc_keys($acc);
	if ($k[0] === '' && $k[1] === '') { return array(); }
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND status IN ('new','accepted','preparing','ready','delivering','done') AND ((guest_key <> '' AND guest_key = ?) OR (guest_key2 <> '' AND guest_key2 = ?)) ORDER BY created_at DESC, id DESC LIMIT ?", 'ssi', array($k[0], $k[1], (int)$limit));
	$out = array();
	foreach ($rows as $r) {
		$items = array();
		foreach (shop_order_items((int)$r['id']) as $it) {
			$o = array(); foreach ((array)$it['options'] as $x) { $o[] = ($x['qty'] > 1 ? $x['qty'].'× ' : '').$x['title']; }
			$items[] = array('title' => $it['title'], 'variation' => $it['variation'], 'opts' => implode(', ', $o), 'qty' => (int)$it['qty'], 'note' => $it['note'], 'line' => (int)$it['line_cents']);
		}
		$out[] = array('id' => (int)$r['id'], 'number' => $r['number'], 'at' => $r['created_at'], 'type' => $r['type'], 'status' => $r['status'], 'total' => (int)$r['total_cents'], 'token' => $r['token'], 'items' => $items);
	}
	return $out;
}
function shop_acc_order_owned($acc, $orderId) {
	shop_acc_backfill_keys();
	$k = shop_acc_keys($acc);
	return fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE id = ? AND is_test = 0 AND ((guest_key <> '' AND guest_key = ?) OR (guest_key2 <> '' AND guest_key2 = ?))", 'iss', array((int)$orderId, $k[0], $k[1]));
}

/*
 * A cart line as the order page keeps it (pid, vid, opts id => qty, optText, unit ...), priced from today's menu, or the reason it cannot be had.
 * $in: pid, vid, opts (id => qty), qty, note. Returns array(ok, line) | array(ok => false, error).
 */
function shop_acc_make_line($in) {
	$r = shop_price_line($in);
	if (!$r['ok']) { return $r; }
	$l = $r['line']; $opts = array(); $parts = array();
	foreach ($l['options'] as $o) { $opts[(string)$o['id']] = (int)$o['qty']; $parts[] = ($o['qty'] > 1 ? $o['qty'].'× ' : '').$o['title']; }
	$p = shop_catalog_product((int)$l['product_id']);
	return array('ok' => true, 'line' => array('pid' => (int)$l['product_id'], 'title' => $l['title'], 'vid' => (int)$l['vid'], 'vtitle' => $l['variation'], 'opts' => (object)$opts, 'optText' => implode(', ', $parts),
		'unit' => (int)$l['unit_cents'], 'qty' => (int)$l['qty'], 'note' => $l['note'], 'ch' => ($p && ($p['variations'] || $p['groups'])) ? 1 : 0));
}

// the saved ids of an old order line, falling back to the titles for orders from before the ids were stored
function shop_acc_resolve_item($it) {
	$pid = (int)$it['product_id'];
	$p = $pid > 0 ? shop_catalog_product($pid) : null;
	if (!$p) { return array('ok' => false, 'error' => 'nicht mehr auf der Karte'); }
	$vid = (int)$it['variation_id'];
	$vids = array_map(function ($v) { return $v['id']; }, $p['variations']);
	if ($vid === 0 || !in_array($vid, $vids, true)) {
		$vid = 0;
		foreach ($p['variations'] as $v) { if ($it['variation'] !== '' && mb_strtolower($v['title']) === mb_strtolower($it['variation'])) { $vid = $v['id']; } }
	}
	$byId = array(); $byTitle = array();
	foreach ($p['groups'] as $g) { foreach ($g['items'] as $x) { $byId[$x['id']] = $x; $byTitle[mb_strtolower($x['title'])][] = $x['id']; } }
	$opts = array();
	foreach ((array)$it['options'] as $o) {
		$oid = isset($o['id']) ? (int)$o['id'] : 0;
		if (!$oid || !isset($byId[$oid])) { $oid = 0; $key = mb_strtolower((string)$o['title']); if (isset($byTitle[$key]) && count($byTitle[$key]) === 1) { $oid = $byTitle[$key][0]; } }
		if ($oid) { $opts[$oid] = (isset($opts[$oid]) ? $opts[$oid] : 0) + max(1, (int)$o['qty']); }
	}
	return shop_acc_make_line(array('pid' => $pid, 'vid' => $vid, 'opts' => $opts, 'qty' => (int)$it['qty'], 'note' => $it['note']));
}

// the lines of an earlier order for the cart; what is gone from the menu is named, what got a new price is named
function shop_acc_reorder($acc, $orderId) {
	$o = shop_acc_order_owned($acc, $orderId);
	if (!$o) { return array('ok' => false, 'error' => 'Diese Bestellung gibt es nicht.'); }
	$lines = array(); $gone = array(); $changed = array();
	foreach (shop_order_items((int)$o['id']) as $it) {
		$r = shop_acc_resolve_item($it);
		if (!$r['ok']) { $gone[] = $it['title']; continue; }
		if ((int)$r['line']['unit'] !== (int)$it['unit_cents']) { $changed[] = $it['title']; }
		$lines[] = $r['line'];
	}
	if (!$lines) { return array('ok' => false, 'error' => 'Von dieser Bestellung ist leider nichts mehr auf der Karte.', 'gone' => $gone); }
	return array('ok' => true, 'lines' => $lines, 'gone' => $gone, 'changed' => $changed, 'type' => $o['type']);
}

function shop_acc_fav_sig($pid, $vid, $opts, $note) {
	ksort($opts);
	return sha1((int)$pid.'|'.(int)$vid.'|'.json_encode($opts).'|'.trim($note));
}
function shop_acc_fav_add($acc, $in) {
	$r = shop_acc_make_line(array('pid' => (int)(isset($in['pid']) ? $in['pid'] : 0), 'vid' => (int)(isset($in['vid']) ? $in['vid'] : 0), 'opts' => (isset($in['opts']) && is_array($in['opts'])) ? $in['opts'] : array(), 'qty' => 1, 'note' => isset($in['note']) ? $in['note'] : ''));
	if (!$r['ok']) { return $r; }
	$l = $r['line']; $opts = (array)$l['opts'];
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_favorites')." WHERE account_id = ?", 'i', array((int)$acc['id']));
	if ($n && (int)$n['n'] >= SHOP_ACC_MAX_FAVS) { return array('ok' => false, 'error' => 'Du hast schon '.SHOP_ACC_MAX_FAVS.' Favoriten. Entferne erst einen.'); }
	fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_favorites')." (account_id, product_id, variation_id, opts, note, sig, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
		'iiissss', array((int)$acc['id'], $l['pid'], $l['vid'], json_encode($opts), $l['note'], shop_acc_fav_sig($l['pid'], $l['vid'], $opts, $l['note']), shop_acc_now()));
	return array('ok' => true);
}
function shop_acc_fav_remove($acc, $id) {
	fb_exec("DELETE FROM ".fb_t('tp_shop_favorites')." WHERE id = ? AND account_id = ?", 'ii', array((int)$id, (int)$acc['id']));
	return array('ok' => true);
}
function shop_acc_favs($acc) {
	$out = array();
	foreach (fb_rows("SELECT * FROM ".fb_t('tp_shop_favorites')." WHERE account_id = ? ORDER BY id DESC", 'i', array((int)$acc['id'])) as $f) {
		$opts = json_decode((string)$f['opts'], true); $opts = is_array($opts) ? $opts : array();
		$r = shop_acc_make_line(array('pid' => (int)$f['product_id'], 'vid' => (int)$f['variation_id'], 'opts' => $opts, 'qty' => 1, 'note' => $f['note']));
		$out[] = $r['ok'] ? array('id' => (int)$f['id'], 'ok' => true, 'line' => $r['line'], 'sig' => $f['sig']) : array('id' => (int)$f['id'], 'ok' => false, 'sig' => $f['sig'], 'error' => $r['error']);
	}
	return $out;
}

// name, phone, e-mail and address of the guest's latest order: the checkout fills its form with it
function shop_acc_profile($acc) {
	$k = shop_acc_keys($acc);
	$r = fb_row("SELECT customer_name, phone, email, street, zip, city, address_note FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND status <> 'cancelled' AND ((guest_key <> '' AND guest_key = ?) OR (guest_key2 <> '' AND guest_key2 = ?)) ORDER BY id DESC LIMIT 1", 'ss', array($k[0], $k[1]));
	if (!$r) { return null; }
	$w3w = strpos($r['street'], 'what3words:') === 0; // no real address to fill in
	return array('name' => $r['customer_name'], 'phone' => $r['phone'], 'email' => $r['email'], 'street' => $w3w ? '' : $r['street'], 'zip' => $w3w ? '' : $r['zip'], 'city' => $w3w ? '' : $r['city'], 'address_note' => $r['address_note']);
}

// the delivery address saved in the account (null while there is none)
function shop_acc_address($acc) {
	if (empty($acc['addr_street'])) { return null; }
	return array('street' => (string)$acc['addr_street'], 'zip' => (string)$acc['addr_zip'], 'city' => (string)$acc['addr_city'], 'note' => (string)$acc['addr_note']);
}
// Save (or, with an empty street, remove) the delivery address. A typed address is only kept when we deliver there: the answer
// carries the zone (fee, minimum) so the guest sees at once what delivery to this address costs.
function shop_acc_address_save($acc, $street, $zip, $city, $note, $name = '', $phone = '') {
	// name and contact number first: they are kept whatever happens to the address (the contact number is not a way to sign in)
	$name = trim(mb_substr((string)$name, 0, 80)); $phone = trim(mb_substr((string)$phone, 0, 30));
	if ($phone !== '' && (!preg_match('/^[+0-9 ()\/.\-]{6,30}$/', $phone) || strlen(preg_replace('/\D/', '', $phone)) < 6)) { return array('ok' => false, 'error' => 'Die Telefonnummer sieht nicht richtig aus.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET name = ?, contact_phone = ? WHERE id = ?", 'ssi', array($name, $phone, (int)$acc['id']));
	$street = trim(mb_substr((string)$street, 0, 120)); $zip = trim(mb_substr((string)$zip, 0, 10)); $city = trim(mb_substr((string)$city, 0, 80)); $note = trim(mb_substr((string)$note, 0, 200));
	if ($street === '') {
		fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET addr_street = '', addr_zip = '', addr_city = '', addr_note = '' WHERE id = ?", 'i', array((int)$acc['id']));
		return array('ok' => true, 'removed' => true);
	}
	if ($city === '' || !preg_match('/\d/', $street)) { return array('ok' => false, 'error' => 'Bitte gib Straße mit Hausnummer und den Ort an.'); }
	$r = shop_find_zone($street, $zip, $city);
	if (!$r['ok']) {
		$err = isset($r['reason']) && $r['reason'] === 'ambiguous' ? 'Es passen mehrere Adressen. Bitte ergänze die Postleitzahl.' : $r['error'];
		return array('ok' => false, 'error' => $err);
	}
	if ($zip === '' && !empty($r['postcode'])) { $zip = (string)$r['postcode']; }
	fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET addr_street = ?, addr_zip = ?, addr_city = ?, addr_note = ? WHERE id = ?", 'ssssi', array($street, $zip, $city, $note, (int)$acc['id']));
	$min = $r['zone']['min_order_cents'] > 0 ? $r['zone']['min_order_cents'] : shop_cents(shop_setting('min_order_delivery'));
	return array('ok' => true, 'zone' => array('fee' => (int)$r['zone']['fee_cents'], 'min' => (int)$min));
}

// everything the page needs about the signed-in guest in one call
function shop_acc_overview($acc, $type = 'delivery') {
	$keys = shop_acc_keys($acc);
	$stamp = shop_stamp_state($keys, $type === 'pickup' ? 'pickup' : 'delivery', 0);
	$stamp['authed'] = true;
	$orders = shop_acc_orders($acc);
	return array('ok' => true, 'account' => array('mask_phone' => $acc['mask_phone'], 'mask_mail' => $acc['mask_mail'], 'has_phone' => $keys[0] !== '', 'has_mail' => $keys[1] !== ''),
		'address' => shop_acc_address($acc),
		'contact' => array('name' => isset($acc['name']) ? (string)$acc['name'] : '', 'phone' => isset($acc['contact_phone']) ? (string)$acc['contact_phone'] : '', 'mail' => isset($acc['contact_mail']) ? (string)$acc['contact_mail'] : ''),
		'stamp' => $stamp, 'orders' => $orders, 'favs' => shop_acc_favs($acc), 'profile' => $orders ? shop_acc_profile($acc) : null);
}
