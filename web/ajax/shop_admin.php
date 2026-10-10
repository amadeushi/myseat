<?php
/*
 * Shop settings from the backend (Einstellungen > Lieferservice): switches and values, Mollie, delivery zones (the menu has its
 * own editor, shop_menu_admin.php). Answers JSON. Needs a login with Settings-General and the
 * session token of the page.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/shop.class.php');
require_once('../classes/shop_uber.class.php');

header('Content-Type: application/json; charset=utf-8');
function sa_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	sa_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	sa_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
set_time_limit(120);
shop_ensure_schema();
$op = isset($_POST['op']) ? (string)$_POST['op'] : '';

if ($op === 'save') {
	foreach (array('public', 'accepting', 'test_mode', 'allow_cash', 'allow_card_door', 'allow_online', 'tip_enabled', 'sms_orders', 'stamp_on', 'offers_on', 'tours_on', 'account_on', 'account_sms', 'feedback_on', 'places_suggest') as $flag) {
		shop_setting_set($flag, !empty($_POST[$flag]) ? '1' : '0');
	}
	foreach (array('eta_delivery_min' => array(10, 240), 'kitchen_drive_min' => array(0, 60), 'lead_pickup_min' => array(0, 240), 'slot_min' => array(5, 60), 'days_ahead' => array(0, 14),
		'stamp_percent' => array(1, 100), 'stamp_goal' => array(2, 12), 'stamp_months' => array(1, 60), 'voucher_days' => array(7, 730), 'account_sms_daily' => array(1, 5000), 'track_days' => array(0, 30), 'last_order_min' => array(0, 240)) as $k => $range) {
		if (isset($_POST[$k])) { shop_setting_set($k, (string)max($range[0], min($range[1], (int)$_POST[$k]))); }
	}
	// offers: amounts in euro, the days, and up to three free extras (products without choices)
	foreach (array('offer_t1' => 500, 'offer_t2' => 500, 'offer_t3' => 500, 'offer_voucher' => 100, 'offer_voucher_min' => 500, 'offer_combo' => 20) as $k => $max) {
		if (isset($_POST[$k])) { shop_setting_set($k, number_format(max(0, min($max, (float)str_replace(',', '.', $_POST[$k]))), 2, '.', '')); }
	}
	if (isset($_POST['offer_voucher_days'])) { shop_setting_set('offer_voucher_days', (string)max(7, min(365, (int)$_POST['offer_voucher_days']))); }
	if (isset($_POST['offer_extra_0']) || isset($_POST['offer_extra_1']) || isset($_POST['offer_extra_2'])) {
		$ex = array();
		foreach (array('offer_extra_0', 'offer_extra_1', 'offer_extra_2') as $k) {
			$pid = isset($_POST[$k]) ? (int)$_POST[$k] : 0;
			if ($pid > 0 && !in_array($pid, $ex, true) && fb_row("SELECT id FROM ".fb_t('tp_shop_products')." WHERE id = ? AND active = 1", 'i', array($pid))) { $ex[] = $pid; }
		}
		shop_setting_set('offer_extras', implode(',', $ex));
	}
	foreach (array('min_order_delivery', 'min_order_pickup') as $k) {
		if (isset($_POST[$k])) { shop_setting_set($k, number_format(max(0, min(500, (float)str_replace(',', '.', $_POST[$k]))), 2, '.', '')); }
	}
	if (isset($_POST['notify_email'])) { $ne = trim((string)$_POST['notify_email']); shop_setting_set('notify_email', ($ne === '' || filter_var($ne, FILTER_VALIDATE_EMAIL)) ? $ne : (string)shop_setting('notify_email')); }
	foreach (array('origin_street' => 120, 'origin_zip' => 10, 'origin_city' => 80) as $k => $len) { if (isset($_POST[$k])) { shop_setting_set($k, mb_substr(trim((string)$_POST[$k]), 0, $len)); } }
	if (isset($_POST['notice'])) { shop_setting_set('notice', mb_substr(trim((string)$_POST['notice']), 0, 200)); }
	sa_out(array('ok' => true, 'message' => 'Gespeichert.'));
}

if ($op === 'save_mollie') {
	$key = isset($_POST['mollie_key']) ? trim((string)$_POST['mollie_key']) : '';
	if ($key !== '') {
		if (!preg_match('/^(test|live)_[A-Za-z0-9]{20,}$/', $key)) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel sieht nicht richtig aus. Er beginnt mit test_ oder live_.')); }
		$enc = sms_encrypt($key);
		if ($enc === null) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		shop_setting_set('mollie_key', $enc);
	}
	sa_out(array('ok' => true, 'message' => 'Gespeichert.'));
}
if ($op === 'clear_mollie') { shop_setting_set('mollie_key', null); sa_out(array('ok' => true, 'message' => 'Der Mollie-Schlüssel wurde gelöscht.')); }

// Uber Eats receipts: the address of the n8n webhook that reads the images, and whether a receipt that does not add up is read again with the stronger model
if ($op === 'save_uber_read') {
	$url = isset($_POST['uber_read_url']) ? trim((string)$_POST['uber_read_url']) : '';
	if ($url !== '' && !preg_match('#^https://[^\s]+$#i', $url)) { sa_out(array('ok' => false, 'error' => 'Die Adresse muss mit https:// beginnen.')); }
	shop_setting_set('uber_read_url', $url);
	shop_setting_set('uber_read_large', !empty($_POST['uber_read_large']) ? '1' : '0');
	shop_setting_set('uber_auto_import', !empty($_POST['uber_auto_import']) ? '1' : '0');
	sa_out(array('ok' => true, 'message' => 'Gespeichert.'));
}
// reads receipts once more: one by id, or (id 0) the ones that are "check", "error" or still new (at most 4 at a time, every reading takes 5 to 20 seconds, up to twice that when the stronger model is needed)
if ($op === 'uber_reread') {
	$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
	$all = !empty($_POST['all']);   // the last four, whatever their state (after the reading has been changed, to see the same receipts read the new way)
	$ids = $id > 0 ? array($id) : array_map(function ($r) { return (int)$r['id']; }, fb_rows("SELECT id FROM ".fb_t('tp_shop_uber_slips')." ".($all ? "" : "WHERE status IN ('check', 'error', 'new', 'reading') ").($all ? "ORDER BY id DESC" : "ORDER BY id")." LIMIT 4"));
	if (!$ids) { sa_out(array('ok' => true, 'message' => 'Es gibt nichts neu zu lesen.')); }
	set_time_limit(280);
	$n = array('read' => 0, 'check' => 0, 'error' => 0);
	foreach ($ids as $i) { $r = shop_uber_process($i, true); $n[empty($r['ok']) ? 'error' : $r['status']]++; }
	sa_out(array('ok' => true, 'message' => $n['read'].' gelesen, '.$n['check'].' zu prüfen, '.$n['error'].' Fehler.'));
}
// makes an order out of a read receipt (shop_uber_import): only a receipt that adds up and has a known payment; the answer says if it became a test order (receipt of another day)
if ($op === 'uber_import') {
	$r = shop_uber_import(isset($_POST['id']) ? (int)$_POST['id'] : 0, false);
	if (empty($r['ok'])) { sa_out(array('ok' => false, 'error' => $r['error'])); }
	if (!empty($r['duplicate'])) { sa_out(array('ok' => true, 'message' => 'Diese Bestellung gab es schon'.(!empty($r['day_no']) ? ' (#'.$r['day_no'].')' : '').'.')); }
	sa_out(array('ok' => true, 'message' => 'Bestellung #'.$r['day_no'].' angelegt'.(!empty($r['test']) ? ' (als Test, der Bon ist nicht von heute)' : '').(!empty($r['cash']) ? ', Barzahlung bei Lieferung' : ', bei Uber Eats bezahlt').'.'));
}
// reads the newest stored receipt once more (a test of the whole way: n8n, the model, the check), without creating anything
if ($op === 'uber_read_test') {
	$row = fb_row("SELECT id FROM ".fb_t('tp_shop_uber_slips')." ORDER BY id DESC LIMIT 1");
	if (!$row) { sa_out(array('ok' => false, 'error' => 'Es ist noch kein Bon empfangen worden.')); }
	$r = shop_uber_process((int)$row['id'], true);
	if (empty($r['ok'])) { sa_out(array('ok' => false, 'error' => 'Bon '.(int)$row['id'].': '.(isset($r['error']) ? $r['error'] : implode(' ', $r['problems'])))); }
	$msg = $r['status'] === 'read' ? 'Bon '.(int)$row['id'].' gelesen, die Summe stimmt (Stufe '.$r['tier'].').' : 'Bon '.(int)$row['id'].' gelesen, aber zu prüfen: '.implode(' ', $r['problems']);
	sa_out(array('ok' => true, 'message' => $msg));
}
if ($op === 'save_google_key') {
	$key = isset($_POST['google_key']) ? trim((string)$_POST['google_key']) : '';
	if ($key !== '') {
		if (!preg_match('/^[A-Za-z0-9_-]{20,60}$/', $key)) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel sieht nicht richtig aus.')); }
		$enc = sms_encrypt($key);
		if ($enc === null) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		shop_setting_set('google_key', $enc);
	}
	sa_out(array('ok' => true, 'message' => 'Gespeichert.'));
}
if ($op === 'clear_google_key') { shop_setting_set('google_key', null); sa_out(array('ok' => true, 'message' => 'Der Google-Schlüssel wurde gelöscht.')); }
if ($op === 'test_google_key') { sa_out(shop_google_test()); }

if ($op === 'save_w3w_key') {
	$key = isset($_POST['w3w_key']) ? trim((string)$_POST['w3w_key']) : '';
	if ($key !== '') {
		// what3words does not document a fixed key format (unlike Mollie's test_/live_ prefix) -
		// only a sane length is checked here, the what3words API itself is the real validator
		if (mb_strlen($key) < 8 || mb_strlen($key) > 100) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel sieht nicht richtig aus.')); }
		$enc = sms_encrypt($key);
		if ($enc === null) { sa_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		shop_setting_set('w3w_key', $enc);
	}
	sa_out(array('ok' => true, 'message' => 'Gespeichert.'));
}
if ($op === 'clear_w3w_key') { shop_setting_set('w3w_key', null); sa_out(array('ok' => true, 'message' => 'Der what3words-Schlüssel wurde gelöscht.')); }
if ($op === 'test_w3w_key') { sa_out(shop_w3w_test()); }

if ($op === 'test_mollie') {
	$r = shop_mollie_test();
	sa_out($r['ok'] ? array('ok' => true, 'message' => 'Verbindung in Ordnung. Aktive Zahlarten: '.($r['methods'] ? implode(', ', $r['methods']) : 'keine (im Mollie-Konto aktivieren)').'.') : array('ok' => false, 'error' => $r['error']));
}

if ($op === 'zone') {
	$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
	$r = shop_zone_save_info($id, isset($_POST['name']) ? $_POST['name'] : '', shop_cents(isset($_POST['fee']) ? $_POST['fee'] : 0),
		shop_cents(isset($_POST['min']) ? $_POST['min'] : 0), !empty($_POST['active']));
	sa_out($r['ok'] ? array('ok' => true, 'message' => 'Liefergebiet gespeichert.') : $r);
}

if ($op === 'save_driver') {
	$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
	$r = shop_driver_save($id, isset($_POST['name']) ? $_POST['name'] : '', isset($_POST['device_id']) ? $_POST['device_id'] : '', !empty($_POST['active']), isset($_POST['phone']) ? $_POST['phone'] : null);
	sa_out($r['ok'] ? array('ok' => true, 'message' => 'Fahrer gespeichert.') : $r);
}
if ($op === 'save_suburb') {
	$r = shop_suburb_save((int)(isset($_POST['id']) ? $_POST['id'] : 0), isset($_POST['kind']) ? $_POST['kind'] : '', isset($_POST['pattern']) ? $_POST['pattern'] : '', isset($_POST['suburb']) ? $_POST['suburb'] : '');
	sa_out($r['ok'] ? array('ok' => true, 'message' => 'Die Regel ist gespeichert.') : $r);
}
if ($op === 'delete_suburb') { sa_out(shop_suburb_delete((int)(isset($_POST['id']) ? $_POST['id'] : 0))); }
if ($op === 'save_hours') {
	$data = json_decode(isset($_POST['hours']) ? (string)$_POST['hours'] : '', true);
	if (!is_array($data)) { sa_out(array('ok' => false, 'error' => 'Die Zeiten sind nicht lesbar. Bitte lade die Seite neu.')); }
	$r = shop_hours_save($data);
	sa_out($r['ok'] ? array('ok' => true, 'message' => $r['count'] ? 'Die Bestellzeiten sind gespeichert.' : 'Gespeichert. Es sind keine Zeiten eingetragen, die Bestellseite ist damit immer geschlossen.') : $r);
}
if ($op === 'save_exception') {
	$data = json_decode(isset($_POST['data']) ? (string)$_POST['data'] : '', true);
	if (!is_array($data)) { sa_out(array('ok' => false, 'error' => 'Die Angaben sind nicht lesbar. Bitte lade die Seite neu.')); }
	$r = shop_ex_save($data);
	sa_out($r['ok'] ? array('ok' => true, 'message' => 'Die Ausnahme ist gespeichert.') : $r);
}
if ($op === 'delete_exception') { sa_out(shop_ex_delete((int)(isset($_POST['id']) ? $_POST['id'] : 0))); }
if ($op === 'add_holidays') {
	$n = shop_ex_add_holidays((int)(isset($_POST['year']) ? $_POST['year'] : 0));
	if ($n < 0) { sa_out(array('ok' => false, 'error' => 'Dieses Jahr ist nicht möglich.')); }
	sa_out(array('ok' => true, 'message' => $n ? $n.' Feiertage eingetragen (geschlossen). Prüf die Liste und ändere, was bei euch offen ist.' : 'Diese Feiertage sind schon eingetragen.'));
}
if ($op === 'delete_driver') {
	$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
	if ($id <= 0) { sa_out(array('ok' => false, 'error' => 'Unbekannter Fahrer.')); }
	sa_out(shop_driver_delete($id));
}

sa_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
