<?php
/*
 * Wertgutscheine (gift vouchers) with a balance: the replacement of the former voucher shop. Not to be confused with the discount coupons
 * and stamp-card vouchers of shop.class.php (tp_shop_coupons): those give a discount with limited use, these hold money that can be paid out in parts.
 *
 * One voucher = one row with a balance; every change of the balance is a row in the append-only list of movements (issue, redeem, refund, adjust, void, import),
 * so the balance can always be re-computed from the movements. The balance only ever changes with one conditional UPDATE (never read-then-write),
 * so two tills redeeming the same voucher at the same moment cannot spend it twice.
 *
 * Numbers: the pre-printed blanks have running numbers that can be guessed, therefore a voucher is only redeemed online with number + security code
 * (the code is made when the blank is sold, or printed on the PDF). In the backend the staff redeems with the number and the personal PIN of the employee.
 * Kinds: pdf (self-print), print (numbered blank sent by post or picked up), import (open voucher of the former system).
 * Everything is off until the setting gv_on is switched on; all functions here work without it (the backend needs them to enter and redeem vouchers).
 */

const GV_PIN_MAX_FAILS = 5;            // wrong PINs in a row before the employee is locked
const GV_PIN_LOCK_MIN = 15;            // minutes of the lock
const GV_TRY_MAX = 10;                 // wrong number/code pairs per address in GV_TRY_WINDOW_MIN before the lookup is refused
const GV_TRY_WINDOW_MIN = 15;
const GV_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O/1/I: numbers and codes are read aloud and copied by hand
const GV_MAX_CENTS = 100000;           // no voucher above 1000 euro (a hard limit of the functions, the shop settings can only lower it)

function gv_schema($db, $opts) {
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_gv_vouchers')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `number` VARCHAR(24) NOT NULL, `number_key` VARCHAR(24) NOT NULL, `kind` VARCHAR(8) NOT NULL DEFAULT 'pdf',
		`face_cents` INT NOT NULL, `balance_cents` INT NOT NULL, `status` VARCHAR(8) NOT NULL DEFAULT 'active', `valid_until` DATE NULL,
		`code_hash` CHAR(64) NULL, `code_enc` VARCHAR(255) NULL, `token` CHAR(32) NOT NULL,
		`buyer_name` VARCHAR(80) NOT NULL DEFAULT '', `buyer_email` VARCHAR(160) NOT NULL DEFAULT '', `recipient` VARCHAR(80) NOT NULL DEFAULT '', `message` VARCHAR(400) NOT NULL DEFAULT '',
		`motif_id` INT UNSIGNED NULL, `order_id` INT UNSIGNED NULL, `note` VARCHAR(200) NOT NULL DEFAULT '', `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
		UNIQUE KEY `nkey` (`number_key`), UNIQUE KEY `token` (`token`), KEY `st` (`status`, `valid_until`), KEY `ord` (`order_id`)) $opts");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_gv_moves')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `voucher_id` INT UNSIGNED NOT NULL, `kind` VARCHAR(8) NOT NULL, `amount_cents` INT NOT NULL, `balance_after` INT NOT NULL,
		`staff_id` INT UNSIGNED NULL, `staff_name` VARCHAR(60) NOT NULL DEFAULT '', `ref` VARCHAR(120) NOT NULL DEFAULT '', `ip` VARCHAR(45) NOT NULL DEFAULT '', `at` DATETIME NOT NULL,
		KEY `v` (`voucher_id`), KEY `at` (`at`)) $opts");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_gv_staff')." (
		`user_id` INT UNSIGNED NOT NULL PRIMARY KEY, `pin_hash` VARCHAR(100) NOT NULL, `fails` INT NOT NULL DEFAULT 0, `locked_until` DATETIME NULL, `updated_at` DATETIME NOT NULL) $opts");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_gv_tries')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `ip` VARCHAR(45) NOT NULL, `at` DATETIME NOT NULL, KEY `ip` (`ip`, `at`)) $opts");
	// a food order paid with a voucher: which voucher, how much it paid (total_cents of the order is then what is still to be paid), and whether the money went back to the voucher (cancelled order)
	// (columns in separate statements: one combined ALTER failed on a MariaDB)
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_orders')." LIKE 'voucher_cents'")) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `voucher_cents` INT NOT NULL DEFAULT 0");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `voucher_id` INT UNSIGNED NULL");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_orders')." ADD `voucher_back` TINYINT NOT NULL DEFAULT 0");
	}
	// orders of the voucher shop: one voucher per order; the voucher exists as "pending" from the order and becomes usable when the payment is confirmed
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_gv_orders')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `number` VARCHAR(16) NOT NULL DEFAULT '', `token` CHAR(32) NOT NULL, `voucher_id` INT UNSIGNED NOT NULL, `delivery` VARCHAR(8) NOT NULL,
		`amount_cents` INT NOT NULL, `fee_cents` INT NOT NULL DEFAULT 0, `total_cents` INT NOT NULL, `motif` VARCHAR(40) NOT NULL DEFAULT '', `recipient` VARCHAR(80) NOT NULL DEFAULT '', `from_name` VARCHAR(80) NOT NULL DEFAULT '',
		`message` VARCHAR(400) NOT NULL DEFAULT '', `buyer_name` VARCHAR(80) NOT NULL, `buyer_email` VARCHAR(160) NOT NULL, `buyer_phone` VARCHAR(40) NOT NULL DEFAULT '',
		`ship_name` VARCHAR(80) NOT NULL DEFAULT '', `ship_street` VARCHAR(120) NOT NULL DEFAULT '', `ship_zip` VARCHAR(12) NOT NULL DEFAULT '', `ship_city` VARCHAR(80) NOT NULL DEFAULT '',
		`status` VARCHAR(10) NOT NULL DEFAULT 'pending', `mollie_id` VARCHAR(40) NULL, `consent_lapse` TINYINT NOT NULL DEFAULT 0, `consent_at` DATETIME NULL, `consent_ip` VARCHAR(45) NOT NULL DEFAULT '',
		`consent_ua` VARCHAR(200) NOT NULL DEFAULT '', `terms_at` DATETIME NULL, `created_at` DATETIME NOT NULL, `paid_at` DATETIME NULL, `mail_at` DATETIME NULL, `fulfilled_at` DATETIME NULL,
		`withdrawn_at` DATETIME NULL, `withdraw_ip` VARCHAR(45) NOT NULL DEFAULT '', `refund_id` VARCHAR(40) NULL, `refunded_at` DATETIME NULL, `note` VARCHAR(200) NOT NULL DEFAULT '',
		UNIQUE KEY `token` (`token`), KEY `voucher` (`voucher_id`), KEY `st` (`status`), KEY `mollie` (`mollie_id`), KEY `email` (`buyer_email`)) $opts");
	// postcard by EchtPost (echtpost.class.php): state (sending, queued, sent, error, unclear, canceled), motive id and card id there, the last error, time of the last step
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_gv_orders')." LIKE 'ep_state'")) {
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD `ep_state` VARCHAR(10) NULL");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD `ep_motive` INT UNSIGNED NULL");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD `ep_card` VARCHAR(40) NULL");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD `ep_error` VARCHAR(250) NOT NULL DEFAULT ''");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD `ep_at` DATETIME NULL");
		mysqli_query($db, "ALTER TABLE ".fb_t('tp_gv_orders')." ADD KEY `epcard` (`ep_card`)");
	}
}

function gv_money($cents) { return number_format($cents / 100, 2, ',', '.').' €'; }
function gv_now() { return date('Y-m-d H:i:s'); }
function gv_ip() { return substr(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '', 0, 45); }
// the key of a number: upper case, only letters and digits, so "amd 2k4m-9x7p" and "AMD-2K4M-9X7P" are the same voucher
function gv_number_key($n) { return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$n)), 0, 24); }
function gv_code_clean($c) { return substr(preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$c)), 0, 12); }
function gv_code_hash($code, $numberKey) { return hash('sha256', 'gv|'.$numberKey.'|'.gv_code_clean($code)); }

function gv_random($len) {
	$s = '';
	for ($i = 0; $i < $len; $i++) { $s .= GV_ALPHABET[random_int(0, strlen(GV_ALPHABET) - 1)]; }
	return $s;
}
// a new digital number "AMD-XXXX-XXXX" (40 bits) that is not used yet
function gv_new_number() {
	for ($i = 0; $i < 20; $i++) {
		$r = gv_random(8);
		$n = 'AMD-'.substr($r, 0, 4).'-'.substr($r, 4);
		if (!fb_row("SELECT id FROM ".fb_t('tp_gv_vouchers')." WHERE number_key = ?", 's', array(gv_number_key($n)))) { return $n; }
	}
	return null;
}
function gv_new_code() { return gv_random(6); }

function gv_find($number) {
	shop_ensure_schema();
	if (preg_match('/[?&]g=([^&#\s]+)/', (string)$number, $m)) { $number = urldecode($m[1]); }   // the QR code holds a link: a scanner that types it into the field still finds the voucher
	$k = gv_number_key($number);
	return $k === '' ? null : fb_row("SELECT * FROM ".fb_t('tp_gv_vouchers')." WHERE number_key = ?", 's', array($k));
}
function gv_by_id($id) { shop_ensure_schema(); return fb_row("SELECT * FROM ".fb_t('tp_gv_vouchers')." WHERE id = ?", 'i', array((int)$id)); }
function gv_by_token($t) { shop_ensure_schema(); $t = preg_replace('/[^a-f0-9]/', '', (string)$t); return strlen($t) === 32 ? fb_row("SELECT * FROM ".fb_t('tp_gv_vouchers')." WHERE token = ?", 's', array($t)) : null; }
function gv_moves($voucherId) { shop_ensure_schema(); return fb_rows("SELECT * FROM ".fb_t('tp_gv_moves')." WHERE voucher_id = ? ORDER BY id", 'i', array((int)$voucherId)); }

// can the voucher be spent today? returns '' or the reason in words for the person at the till / the guest
function gv_block_reason($v) {
	if (!$v) { return 'Gutschein nicht gefunden.'; }
	if ($v['status'] === 'void') { return 'Dieser Gutschein wurde gesperrt.'; }
	if ($v['status'] === 'pending') { return 'Dieser Gutschein ist noch nicht freigeschaltet (Zahlung offen).'; }
	if ($v['valid_until'] !== null && $v['valid_until'] < date('Y-m-d')) { return 'Dieser Gutschein ist seit '.date('d.m.Y', strtotime($v['valid_until'])).' abgelaufen.'; }
	if ((int)$v['balance_cents'] <= 0) { return 'Das Guthaben dieses Gutscheins ist aufgebraucht.'; }
	return '';
}

// validity: the AGB say three years from the end of the year of issue (31.12. of the third year after the year of issue)
function gv_default_valid_until($issued = null) {
	$y = (int)date('Y', $issued === null ? time() : $issued);
	return ($y + 3).'-12-31';
}

function gv_move_insert($voucherId, $kind, $amount, $balanceAfter, $staffId, $staffName, $ref) {
	return fb_exec("INSERT INTO ".fb_t('tp_gv_moves')." (voucher_id, kind, amount_cents, balance_after, staff_id, staff_name, ref, ip, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", 'isiiissss',
		array((int)$voucherId, $kind, (int)$amount, (int)$balanceAfter, $staffId === null ? null : (int)$staffId, mb_substr((string)$staffName, 0, 60), mb_substr((string)$ref, 0, 120), gv_ip(), gv_now()));
}

/*
 * Make a voucher. $o: kind (pdf|print|import), number (own number, e.g. of a blank or the former system; empty = new digital number), face_cents, balance_cents (import: remaining value),
 * status (active|pending), valid_until (Y-m-d, empty = default of the AGB), buyer_name, buyer_email, recipient, message, motif_id, order_id, note, staff_id, staff_name, with_code (default true).
 * Returns array(ok, voucher, code) - the plain code is only returned here (and kept encrypted for a resend), it is shown once.
 */
function gv_issue($o) {
	shop_ensure_schema();
	$face = (int)($o['face_cents'] ?? 0);
	$kind = $o['kind'] ?? 'pdf';
	if (!in_array($kind, array('pdf', 'print', 'import'), true)) { $kind = 'pdf'; }
	if ($face < 100 || $face > GV_MAX_CENTS) { return array('ok' => false, 'error' => 'Der Wert muss zwischen 1,00 € und '.gv_money(GV_MAX_CENTS).' liegen.'); }
	$balance = isset($o['balance_cents']) ? (int)$o['balance_cents'] : $face;
	if ($balance < 0 || $balance > $face) { return array('ok' => false, 'error' => 'Das Restguthaben darf nicht größer sein als der Wert.'); }
	$number = trim((string)($o['number'] ?? ''));
	if ($number === '') { $number = gv_new_number(); if ($number === null) { return array('ok' => false, 'error' => 'Keine freie Nummer gefunden. Bitte noch einmal versuchen.'); } }
	$key = gv_number_key($number);
	if (strlen($key) < 4) { return array('ok' => false, 'error' => 'Die Gutscheinnummer ist zu kurz.'); }
	$valid = isset($o['valid_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$o['valid_until']) ? $o['valid_until'] : gv_default_valid_until();
	$status = ($o['status'] ?? 'active') === 'pending' ? 'pending' : 'active';
	$code = (($o['with_code'] ?? true) ? gv_new_code() : null);
	$token = bin2hex(random_bytes(16));
	$now = gv_now();
	$db = fb_db();
	mysqli_begin_transaction($db);
	try {
		$st = fb_exec("INSERT INTO ".fb_t('tp_gv_vouchers')." (number, number_key, kind, face_cents, balance_cents, status, valid_until, code_hash, code_enc, token, buyer_name, buyer_email, recipient, message, motif_id, order_id, note, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 'sssiisssssssssiisss', array(
			$number, $key, $kind, $face, $balance, $status, $valid,
			$code === null ? null : gv_code_hash($code, $key), $code === null ? null : sms_encrypt($code), $token,
			mb_substr((string)($o['buyer_name'] ?? ''), 0, 80), mb_substr((string)($o['buyer_email'] ?? ''), 0, 160), mb_substr((string)($o['recipient'] ?? ''), 0, 80), mb_substr((string)($o['message'] ?? ''), 0, 400),
			isset($o['motif_id']) ? (int)$o['motif_id'] : null, isset($o['order_id']) ? (int)$o['order_id'] : null, mb_substr((string)($o['note'] ?? ''), 0, 200), $now, $now));
		if (!$st) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Diese Gutscheinnummer gibt es schon.'); }
		$id = (int)mysqli_insert_id($db);
		gv_move_insert($id, $kind === 'import' ? 'import' : 'issue', $balance, $balance, $o['staff_id'] ?? null, $o['staff_name'] ?? '', $kind === 'import' ? 'Übernahme' : 'Ausgestellt');
		mysqli_commit($db);
	} catch (Throwable $e) { mysqli_rollback($db); error_log('mySeat gv_issue: '.$e->getMessage()); return array('ok' => false, 'error' => 'Der Gutschein konnte nicht angelegt werden.'); }
	return array('ok' => true, 'voucher' => gv_by_id($id), 'code' => $code);
}

// the plain security code of a voucher (for the PDF / a resend); null if there is none (imported vouchers)
function gv_code_plain($v) { return empty($v['code_enc']) ? null : sms_decrypt($v['code_enc']); }

// a pending voucher (paid online) becomes usable after the payment arrived
function gv_activate($voucherId, $ref = '') {
	shop_ensure_schema();
	$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET status = 'active', updated_at = ? WHERE id = ? AND status = 'pending'", 'si', array(gv_now(), (int)$voucherId));
	return $st && mysqli_stmt_affected_rows($st) > 0;
}

/*
 * Take $cents from the balance (partial or full). The one conditional UPDATE is the lock against double spending: it only succeeds while the voucher is
 * active, not expired and holds enough. $who: array(staff_id, staff_name) for the backend, null for the shop (then $ref says which order).
 */
function gv_redeem($voucherId, $cents, $who, $ref) {
	shop_ensure_schema();
	$cents = (int)$cents;
	if ($cents < 1) { return array('ok' => false, 'error' => 'Bitte einen Betrag eingeben.'); }
	$db = fb_db();
	mysqli_begin_transaction($db);
	try {
		$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET balance_cents = balance_cents - ?, updated_at = ? WHERE id = ? AND status = 'active' AND balance_cents >= ? AND (valid_until IS NULL OR valid_until >= CURDATE())",
			'isii', array($cents, gv_now(), (int)$voucherId, $cents));
		if (!$st || mysqli_stmt_affected_rows($st) < 1) {
			mysqli_rollback($db);
			$v = gv_by_id($voucherId);
			$why = gv_block_reason($v);
			return array('ok' => false, 'error' => $why !== '' ? $why : 'Das Guthaben reicht nicht: es sind noch '.gv_money((int)$v['balance_cents']).' auf dem Gutschein.');
		}
		$v = gv_by_id($voucherId);
		gv_move_insert($voucherId, 'redeem', -$cents, (int)$v['balance_cents'], $who[0] ?? null, $who[1] ?? '', $ref);
		$moveId = (int)mysqli_insert_id($db);
		mysqli_commit($db);
	} catch (Throwable $e) { mysqli_rollback($db); error_log('mySeat gv_redeem: '.$e->getMessage()); return array('ok' => false, 'error' => 'Die Einlösung ist fehlgeschlagen.'); }
	return array('ok' => true, 'voucher' => $v, 'move_id' => $moveId);
}

// put money back (a cancelled order, a mistaken redemption); never above the face value
function gv_refund($voucherId, $cents, $who, $ref) {
	shop_ensure_schema();
	$cents = (int)$cents;
	if ($cents < 1) { return array('ok' => false, 'error' => 'Bitte einen Betrag eingeben.'); }
	$db = fb_db();
	mysqli_begin_transaction($db);
	try {
		$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET balance_cents = balance_cents + ?, updated_at = ? WHERE id = ? AND status IN ('active','pending') AND balance_cents + ? <= face_cents", 'isii', array($cents, gv_now(), (int)$voucherId, $cents));
		if (!$st || mysqli_stmt_affected_rows($st) < 1) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Das lässt sich nicht gutschreiben: das Guthaben würde über dem Wert des Gutscheins liegen.'); }
		$v = gv_by_id($voucherId);
		gv_move_insert($voucherId, 'refund', $cents, (int)$v['balance_cents'], $who[0] ?? null, $who[1] ?? '', $ref);
		mysqli_commit($db);
	} catch (Throwable $e) { mysqli_rollback($db); error_log('mySeat gv_refund: '.$e->getMessage()); return array('ok' => false, 'error' => 'Die Gutschrift ist fehlgeschlagen.'); }
	return array('ok' => true, 'voucher' => $v);
}

// block a voucher (lost, withdrawn within the 14 days, misuse); the balance stays visible in the list but cannot be spent. $reason is kept in the movement.
function gv_void($voucherId, $who, $reason) {
	shop_ensure_schema();
	$db = fb_db();
	mysqli_begin_transaction($db);
	try {
		$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET status = 'void', updated_at = ? WHERE id = ? AND status <> 'void'", 'si', array(gv_now(), (int)$voucherId));
		if (!$st || mysqli_stmt_affected_rows($st) < 1) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Der Gutschein ist schon gesperrt.'); }
		$v = gv_by_id($voucherId);
		gv_move_insert($voucherId, 'void', 0, (int)$v['balance_cents'], $who[0] ?? null, $who[1] ?? '', $reason);
		mysqli_commit($db);
	} catch (Throwable $e) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Das Sperren ist fehlgeschlagen.'); }
	return array('ok' => true, 'voucher' => $v);
}
function gv_unvoid($voucherId, $who, $reason) {
	shop_ensure_schema();
	$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET status = 'active', updated_at = ? WHERE id = ? AND status = 'void'", 'si', array(gv_now(), (int)$voucherId));
	if (!$st || mysqli_stmt_affected_rows($st) < 1) { return array('ok' => false, 'error' => 'Der Gutschein ist nicht gesperrt.'); }
	$v = gv_by_id($voucherId);
	gv_move_insert($voucherId, 'adjust', 0, (int)$v['balance_cents'], $who[0] ?? null, $who[1] ?? '', 'Entsperrt: '.$reason);
	return array('ok' => true, 'voucher' => $v);
}
// set the balance to a value by hand (correction); the difference is the movement, the reason is mandatory
function gv_adjust($voucherId, $newBalanceCents, $who, $reason) {
	shop_ensure_schema();
	$new = (int)$newBalanceCents;
	if (trim((string)$reason) === '') { return array('ok' => false, 'error' => 'Bitte einen Grund angeben.'); }
	$db = fb_db();
	mysqli_begin_transaction($db);
	try {
		$cur = fb_row("SELECT balance_cents, face_cents FROM ".fb_t('tp_gv_vouchers')." WHERE id = ? FOR UPDATE", 'i', array((int)$voucherId));
		if (!$cur || $new < 0 || $new > (int)$cur['face_cents']) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Das Guthaben muss zwischen 0 und dem Wert des Gutscheins liegen.'); }
		fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET balance_cents = ?, updated_at = ? WHERE id = ?", 'isi', array($new, gv_now(), (int)$voucherId));
		gv_move_insert($voucherId, 'adjust', $new - (int)$cur['balance_cents'], $new, $who[0] ?? null, $who[1] ?? '', $reason);
		mysqli_commit($db);
	} catch (Throwable $e) { mysqli_rollback($db); return array('ok' => false, 'error' => 'Die Korrektur ist fehlgeschlagen.'); }
	return array('ok' => true, 'voucher' => gv_by_id($voucherId));
}

// change the validity by hand (e.g. goodwill for an old voucher); the reason is mandatory and kept in the movements
function gv_set_valid($voucherId, $date, $who, $reason) {
	shop_ensure_schema();
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) { return array('ok' => false, 'error' => 'Das Datum ist nicht lesbar.'); }
	if (trim((string)$reason) === '') { return array('ok' => false, 'error' => 'Bitte einen Grund angeben.'); }
	$old = gv_by_id($voucherId);
	if (!$old) { return array('ok' => false, 'error' => 'Gutschein nicht gefunden.'); }
	fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET valid_until = ?, updated_at = ? WHERE id = ?", 'ssi', array($date, gv_now(), (int)$voucherId));
	gv_move_insert($voucherId, 'adjust', 0, (int)$old['balance_cents'], $who[0] ?? null, $who[1] ?? '', 'Gültigkeit '.($old['valid_until'] ? date('d.m.Y', strtotime($old['valid_until'])) : 'unbegrenzt').' -> '.date('d.m.Y', strtotime($date)).': '.$reason);
	return array('ok' => true, 'voucher' => gv_by_id($voucherId));
}

// a pre-printed blank gets its printed number (the voucher was made with a digital number when it was ordered); the security code stays
function gv_renumber($voucherId, $newNumber, $who) {
	shop_ensure_schema();
	$key = gv_number_key($newNumber);
	if (strlen($key) < 4) { return array('ok' => false, 'error' => 'Die Gutscheinnummer ist zu kurz.'); }
	$v = gv_by_id($voucherId);
	if (!$v) { return array('ok' => false, 'error' => 'Gutschein nicht gefunden.'); }
	if ($key === $v['number_key']) { return array('ok' => true, 'voucher' => $v); }
	if (!empty($v['code_hash'])) { $code = gv_code_plain($v); }
	$st = fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET number = ?, number_key = ?, code_hash = ?, updated_at = ? WHERE id = ?", 'ssssi', array(trim($newNumber), $key, isset($code) && $code !== null ? gv_code_hash($code, $key) : null, gv_now(), (int)$voucherId));
	if (!$st) { return array('ok' => false, 'error' => 'Diese Gutscheinnummer gibt es schon.'); }
	gv_move_insert($voucherId, 'adjust', 0, (int)$v['balance_cents'], $who[0] ?? null, $who[1] ?? '', 'Nummer '.$v['number'].' -> '.trim($newNumber));
	return array('ok' => true, 'voucher' => gv_by_id($voucherId));
}

// ---- guest side: number + security code, with a limit of wrong tries per address ----

function gv_tries_left() {
	shop_ensure_schema();
	$ip = gv_ip();
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_gv_tries')." WHERE ip = ? AND at > ?", 'ss', array($ip, date('Y-m-d H:i:s', time() - GV_TRY_WINDOW_MIN * 60)));
	return max(0, GV_TRY_MAX - (int)($r['n'] ?? 0));
}
// the voucher for number + code, or null; a wrong pair is counted, a right one is not. $err gets the words for the guest.
function gv_lookup_guest($number, $code, &$err) {
	shop_ensure_schema();
	$err = '';
	if (gv_tries_left() < 1) { $err = 'Zu viele Versuche. Bitte warte kurz und versuche es später noch einmal.'; return null; }
	$v = gv_find($number);
	$ok = $v && !empty($v['code_hash']) && hash_equals($v['code_hash'], gv_code_hash($code, $v['number_key']));
	if (!$ok) {
		fb_exec("INSERT INTO ".fb_t('tp_gv_tries')." (ip, at) VALUES (?, ?)", 'ss', array(gv_ip(), gv_now()));
		fb_exec("DELETE FROM ".fb_t('tp_gv_tries')." WHERE at < ?", 's', array(date('Y-m-d H:i:s', time() - 86400)));
		$err = 'Nummer oder Sicherheitscode stimmt nicht. Bitte prüfe beides auf dem Gutschein.';
		return null;
	}
	return $v;
}

// ---- staff PIN: one per employee (a backend user), stored hashed, locked after repeated mistakes ----

function gv_pin_valid($pin) { return preg_match('/^\d{4,8}$/', (string)$pin) === 1; }
function gv_pin_set($userId, $pin) {
	shop_ensure_schema();
	if (!gv_pin_valid($pin)) { return array('ok' => false, 'error' => 'Die PIN besteht aus 4 bis 8 Ziffern.'); }
	// the page behind the QR code finds the employee by the PIN alone, so no two employees may share one
	foreach (fb_rows("SELECT user_id, pin_hash FROM ".fb_t('tp_gv_staff')." WHERE user_id <> ?", 'i', array((int)$userId)) as $o) {
		if (password_verify((string)$pin, $o['pin_hash'])) { return array('ok' => false, 'error' => 'Diese PIN ist schon vergeben. Bitte wähle eine andere.'); }
	}
	fb_exec("REPLACE INTO ".fb_t('tp_gv_staff')." (user_id, pin_hash, fails, locked_until, updated_at) VALUES (?, ?, 0, NULL, ?)", 'iss', array((int)$userId, password_hash((string)$pin, PASSWORD_DEFAULT), gv_now()));
	return array('ok' => true);
}
function gv_pin_remove($userId) { shop_ensure_schema(); fb_exec("DELETE FROM ".fb_t('tp_gv_staff')." WHERE user_id = ?", 'i', array((int)$userId)); }
// does the PIN belong to this employee? A wrong PIN counts; GV_PIN_MAX_FAILS in a row lock the employee for GV_PIN_LOCK_MIN minutes. Returns array(ok, error)
function gv_pin_check($userId, $pin) {
	shop_ensure_schema();
	$r = fb_row("SELECT * FROM ".fb_t('tp_gv_staff')." WHERE user_id = ?", 'i', array((int)$userId));
	if (!$r) { return array('ok' => false, 'error' => 'Für diese Person ist noch keine PIN angelegt.'); }
	if ($r['locked_until'] !== null && $r['locked_until'] > gv_now()) {
		return array('ok' => false, 'error' => 'Gesperrt nach zu vielen Fehlversuchen, bis '.date('H:i', strtotime($r['locked_until'])).' Uhr.');
	}
	if (password_verify((string)$pin, $r['pin_hash'])) {
		if ((int)$r['fails'] > 0 || $r['locked_until'] !== null) { fb_exec("UPDATE ".fb_t('tp_gv_staff')." SET fails = 0, locked_until = NULL WHERE user_id = ?", 'i', array((int)$userId)); }
		return array('ok' => true);
	}
	$fails = (int)$r['fails'] + 1;
	if ($fails >= GV_PIN_MAX_FAILS) {
		fb_exec("UPDATE ".fb_t('tp_gv_staff')." SET fails = 0, locked_until = ? WHERE user_id = ?", 'si', array(date('Y-m-d H:i:s', time() + GV_PIN_LOCK_MIN * 60), (int)$userId));
		return array('ok' => false, 'error' => 'Falsche PIN. Gesperrt für '.GV_PIN_LOCK_MIN.' Minuten.');
	}
	fb_exec("UPDATE ".fb_t('tp_gv_staff')." SET fails = ? WHERE user_id = ?", 'ii', array($fails, (int)$userId));
	return array('ok' => false, 'error' => 'Falsche PIN. Noch '.(GV_PIN_MAX_FAILS - $fails).' Versuche.');
}
// employees that may redeem: backend users with a PIN, for the name list of the redeem form
function gv_staff_list() {
	shop_ensure_schema();
	global $dbTables;
	$t = isset($dbTables->plc_users) ? '`'.$dbTables->plc_users.'`' : fb_t('plc_users');
	return fb_rows("SELECT u.userID AS id, u.username, u.realname, (s.user_id IS NOT NULL) AS has_pin FROM $t u LEFT JOIN ".fb_t('tp_gv_staff')." s ON s.user_id = u.userID WHERE u.active = '1' ORDER BY u.realname, u.username");
}
function gv_staff_name($userId) {
	global $dbTables;
	$t = isset($dbTables->plc_users) ? '`'.$dbTables->plc_users.'`' : fb_t('plc_users');
	$r = fb_row("SELECT username, realname FROM $t WHERE userID = ?", 'i', array((int)$userId));
	return $r ? ($r['realname'] !== '' ? $r['realname'] : $r['username']) : '';
}

/*
 * Redeem in the backend: employee + PIN + number + amount (empty amount = everything). One call so that the PIN check and the booking cannot be separated.
 * Returns array(ok, error, voucher).
 */
function gv_redeem_staff($userId, $pin, $number, $amountCents, $ref = '') {
	$p = gv_pin_check($userId, $pin);
	if (!$p['ok']) { return $p; }
	$v = gv_find($number);
	$why = gv_block_reason($v);
	if ($why !== '') { return array('ok' => false, 'error' => $why); }
	$amount = $amountCents === null || $amountCents === '' ? (int)$v['balance_cents'] : (int)$amountCents;
	return gv_redeem((int)$v['id'], $amount, array((int)$userId, gv_staff_name($userId)), $ref !== '' ? $ref : 'Einlösung im Restaurant');
}

/*
 * Redeem from the page behind the QR code of the voucher: the employee is found by the PIN alone (PINs are unique), no name list is shown to anyone.
 * Wrong PINs are counted per address (GV_TRY_MAX in GV_TRY_WINDOW_MIN minutes) and per voucher (GV_PIN_MAX_FAILS), so the PIN cannot be guessed there.
 * $amountCents null = everything. Returns array(ok, error, voucher, redeemed).
 */
function gv_redeem_pin($voucherId, $pin, $amountCents) {
	shop_ensure_schema();
	$since = date('Y-m-d H:i:s', time() - GV_TRY_WINDOW_MIN * 60); $vkey = 'v'.(int)$voucherId; $ip = gv_ip();
	$count = function ($k) use ($since) { $r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_gv_tries')." WHERE ip = ? AND at > ?", 'ss', array($k, $since)); return (int)($r['n'] ?? 0); };
	$miss = function () use ($vkey, $ip) { foreach (array($vkey, $ip) as $k) { if ($k !== '') { fb_exec("INSERT INTO ".fb_t('tp_gv_tries')." (ip, at) VALUES (?, ?)", 'ss', array($k, gv_now())); } } };
	if (($ip !== '' && $count($ip) >= GV_TRY_MAX) || $count($vkey) >= GV_PIN_MAX_FAILS) { return array('ok' => false, 'error' => 'Zu viele falsche PINs. Bitte versuche es in '.GV_PIN_LOCK_MIN.' Minuten noch einmal oder löse im Backend ein.'); }
	if (!gv_pin_valid($pin)) { $miss(); return array('ok' => false, 'error' => 'Bitte die PIN eingeben (4 bis 8 Ziffern).'); }
	$who = null;
	foreach (fb_rows("SELECT user_id, pin_hash, locked_until FROM ".fb_t('tp_gv_staff')) as $r) { if (password_verify((string)$pin, $r['pin_hash'])) { $who = $r; break; } }
	if (!$who) { $miss(); return array('ok' => false, 'error' => 'Die PIN stimmt nicht.'); }
	if ($who['locked_until'] !== null && $who['locked_until'] > gv_now()) { return array('ok' => false, 'error' => 'Diese PIN ist gerade gesperrt.'); }
	$v = gv_by_id($voucherId); $why = gv_block_reason($v);
	if ($why !== '') { return array('ok' => false, 'error' => $why); }
	$amount = $amountCents === null ? (int)$v['balance_cents'] : (int)$amountCents;
	$r = gv_redeem((int)$voucherId, $amount, array((int)$who['user_id'], gv_staff_name((int)$who['user_id'])), 'Einlösung per QR-Code');
	if (!empty($r['ok'])) { $r['redeemed'] = $amount; }
	return $r;
}

// parse an amount typed by a person: "12,50", "12.5", "12" -> cents; null when it is not an amount
function gv_parse_amount($s) {
	$s = trim(str_replace(array('€', ' '), '', (string)$s));
	if (!preg_match('/^\d{1,5}([.,]\d{1,2})?$/', $s)) { return null; }
	return (int)round(((float)str_replace(',', '.', $s)) * 100);
}

// ---- lists, counts, key figures (backend) ----

// the state shown to people: open (usable), used (balance 0), void, expired, pending (payment open)
function gv_state($v) {
	if ($v['status'] === 'void') { return 'void'; }
	if ($v['status'] === 'pending') { return 'pending'; }
	if ($v['valid_until'] !== null && $v['valid_until'] < date('Y-m-d')) { return 'expired'; }
	return (int)$v['balance_cents'] <= 0 ? 'used' : 'open';
}
function gv_state_sql($state) {
	switch ($state) {
		case 'void': return "status = 'void'";
		case 'pending': return "status = 'pending'";
		case 'expired': return "status = 'active' AND valid_until IS NOT NULL AND valid_until < CURDATE()";
		case 'used': return "status = 'active' AND balance_cents <= 0 AND (valid_until IS NULL OR valid_until >= CURDATE())";
		case 'open': return "status = 'active' AND balance_cents > 0 AND (valid_until IS NULL OR valid_until >= CURDATE())";
	}
	return '1=1';
}
function gv_counts() {
	shop_ensure_schema();
	$o = array('all' => 0);
	foreach (array('open', 'used', 'expired', 'void', 'pending') as $s) {
		$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_gv_vouchers')." WHERE ".gv_state_sql($s));
		$o[$s] = (int)$r['n']; $o['all'] += (int)$r['n'];
	}
	return $o;
}
function gv_list($state, $q, $limit = 200) {
	shop_ensure_schema();
	$where = array(gv_state_sql($state === 'all' ? '' : $state)); $types = ''; $par = array();
	$q = trim((string)$q);
	if ($q !== '') {
		$k = gv_number_key($q);
		$where[] = "(number_key LIKE ? OR buyer_name LIKE ? OR buyer_email LIKE ? OR recipient LIKE ? OR note LIKE ?)";
		$like = '%'.addcslashes($q, '%_\\').'%';
		$types .= 'sssss'; $par = array('%'.($k !== '' ? $k : '#').'%', $like, $like, $like, $like);
	}
	$rows = fb_rows("SELECT id, number, kind, face_cents, balance_cents, status, valid_until, buyer_name, buyer_email, recipient, note, created_at FROM ".fb_t('tp_gv_vouchers')." WHERE ".implode(' AND ', $where)." ORDER BY id DESC LIMIT ".(int)$limit, $types, $par);
	foreach ($rows as &$r) { $r['state'] = gv_state($r); } unset($r);
	return $rows;
}
// key figures: sold (issued, without imports), redeemed, still open (liability) - in cents
function gv_stats() {
	shop_ensure_schema();
	$r = fb_row("SELECT
		COALESCE(SUM(CASE WHEN kind <> 'import' AND status <> 'pending' THEN face_cents END), 0) AS sold,
		COALESCE(SUM(CASE WHEN status = 'active' AND (valid_until IS NULL OR valid_until >= CURDATE()) THEN balance_cents END), 0) AS open_cents,
		COALESCE(SUM(CASE WHEN status = 'active' AND valid_until IS NOT NULL AND valid_until < CURDATE() THEN balance_cents END), 0) AS expired_cents,
		COALESCE(SUM(CASE WHEN status = 'void' THEN balance_cents END), 0) AS void_cents
		FROM ".fb_t('tp_gv_vouchers'));
	$m = fb_row("SELECT COALESCE(-SUM(CASE WHEN kind = 'redeem' THEN amount_cents END), 0) AS redeemed FROM ".fb_t('tp_gv_moves'));
	return array('sold' => (int)$r['sold'], 'redeemed' => (int)$m['redeemed'], 'open' => (int)$r['open_cents'], 'expired' => (int)$r['expired_cents'], 'void' => (int)$r['void_cents']);
}
// CSV of the vouchers (with the semicolon German Excel expects); text cells starting with = + - @ get a quote in front against formula injection
function gv_csv_cell($s) { $s = (string)$s; if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) { $s = "'".$s; } return '"'.str_replace('"', '""', $s).'"'; }
function gv_export_csv() {
	shop_ensure_schema();
	$out = "\xEF\xBB\xBF".implode(';', array('Nummer', 'Art', 'Status', 'Wert', 'Restguthaben', 'Gültig bis', 'Käufer', 'E-Mail', 'Für', 'Angelegt', 'Notiz'))."\r\n";
	$names = array('open' => 'offen', 'used' => 'eingelöst', 'expired' => 'abgelaufen', 'void' => 'gesperrt', 'pending' => 'Zahlung offen');
	$kinds = array('pdf' => 'PDF', 'print' => 'Vordruck', 'import' => 'Übernahme');
	foreach (gv_list('all', '', 100000) as $r) {
		$out .= implode(';', array(gv_csv_cell($r['number']), gv_csv_cell($kinds[$r['kind']] ?? $r['kind']), gv_csv_cell($names[$r['state']]), number_format($r['face_cents'] / 100, 2, ',', ''), number_format($r['balance_cents'] / 100, 2, ',', ''),
			$r['valid_until'] ? date('d.m.Y', strtotime($r['valid_until'])) : '', gv_csv_cell($r['buyer_name']), gv_csv_cell($r['buyer_email']), gv_csv_cell($r['recipient']), date('d.m.Y H:i', strtotime($r['created_at'])), gv_csv_cell($r['note'])))."\r\n";
	}
	return $out;
}

// ---- import of open vouchers of the former system: lines "number;remaining value;[face value];[valid until]" ----

function gv_import_date($s) {
	$s = trim((string)$s);
	if ($s === '') { return ''; }
	if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) { return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); }
	if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { return $s; }
	return null;
}
// returns array of rows: line, number, balance, face, valid, status (new|dup_file|dup_db|error), error
function gv_import_parse($text, $defaultValid) {
	shop_ensure_schema();
	$text = preg_replace('/^\xEF\xBB\xBF/', '', (string)$text);
	$lines = preg_split('/\r\n|\r|\n/', $text);
	$rows = array(); $seen = array(); $n = 0;
	foreach ($lines as $i => $line) {
		if (trim($line) === '') { continue; }
		if (++$n > 5000) { break; }
		$delim = substr_count($line, ';') ? ';' : (substr_count($line, "\t") ? "\t" : ',');
		$c = array_map('trim', str_getcsv($line, $delim, '"', ''));
		if ($n === 1 && gv_parse_amount($c[1] ?? '') === null && gv_import_date($c[3] ?? '') === null) { continue; }   // header line
		$row = array('line' => $i + 1, 'number' => $c[0] ?? '', 'balance' => null, 'face' => null, 'valid' => $defaultValid, 'status' => 'error', 'error' => '');
		$key = gv_number_key($row['number']);
		$bal = gv_parse_amount($c[1] ?? ''); $face = isset($c[2]) && $c[2] !== '' ? gv_parse_amount($c[2]) : $bal;
		$valid = isset($c[3]) && $c[3] !== '' ? gv_import_date($c[3]) : $defaultValid;
		if (strlen($key) < 4) { $row['error'] = 'Nummer fehlt oder ist zu kurz'; }
		elseif ($bal === null) { $row['error'] = 'Restwert ist keine Zahl'; }
		elseif ($face === null) { $row['error'] = 'Wert ist keine Zahl'; }
		elseif ($valid === null) { $row['error'] = 'Datum nicht lesbar (TT.MM.JJJJ)'; }
		elseif ($face < 100 || $face > GV_MAX_CENTS) { $row['error'] = 'Wert außerhalb von 1,00 bis '.gv_money(GV_MAX_CENTS); }
		elseif ($bal > $face) { $row['error'] = 'Restwert größer als der Wert'; }
		else {
			$row['balance'] = $bal; $row['face'] = $face; $row['valid'] = $valid;
			if (isset($seen[$key])) { $row['status'] = 'dup_file'; $row['error'] = 'steht in der Datei schon in Zeile '.$seen[$key]; }
			elseif (fb_row("SELECT id FROM ".fb_t('tp_gv_vouchers')." WHERE number_key = ?", 's', array($key))) { $row['status'] = 'dup_db'; $row['error'] = 'gibt es schon'; }
			else { $row['status'] = 'new'; }
			if (!isset($seen[$key])) { $seen[$key] = $row['line']; }
		}
		$rows[] = $row;
	}
	return $rows;
}
function gv_import_run($text, $defaultValid, $who) {
	$done = 0; $skipped = 0;
	foreach (gv_import_parse($text, $defaultValid) as $r) {
		if ($r['status'] !== 'new') { $skipped++; continue; }
		$x = gv_issue(array('kind' => 'import', 'number' => $r['number'], 'face_cents' => $r['face'], 'balance_cents' => $r['balance'], 'valid_until' => $r['valid'], 'with_code' => false,
			'note' => 'Übernahme aus dem alten System', 'staff_id' => $who[0] ?? null, 'staff_name' => $who[1] ?? ''));
		if ($x['ok']) { $done++; } else { $skipped++; }
	}
	return array('ok' => true, 'imported' => $done, 'skipped' => $skipped);
}
