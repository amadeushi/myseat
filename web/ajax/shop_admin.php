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
	foreach (array('public', 'accepting', 'test_mode', 'allow_cash', 'allow_card_door', 'allow_online', 'tip_enabled', 'sms_orders') as $flag) {
		shop_setting_set($flag, !empty($_POST[$flag]) ? '1' : '0');
	}
	foreach (array('eta_delivery_min' => array(10, 240), 'lead_pickup_min' => array(0, 240), 'slot_min' => array(5, 60), 'days_ahead' => array(0, 14)) as $k => $range) {
		if (isset($_POST[$k])) { shop_setting_set($k, (string)max($range[0], min($range[1], (int)$_POST[$k]))); }
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
	$name = mb_substr(trim((string)(isset($_POST['name']) ? $_POST['name'] : '')), 0, 80);
	if ($id <= 0 || $name === '') { sa_out(array('ok' => false, 'error' => 'Name fehlt.')); }
	fb_exec("UPDATE ".fb_t('tp_shop_zones')." SET name = ?, fee_cents = ?, min_order_cents = ?, active = ? WHERE id = ?",
		'siiii', array($name, shop_cents(isset($_POST['fee']) ? $_POST['fee'] : 0), shop_cents(isset($_POST['min']) ? $_POST['min'] : 0), !empty($_POST['active']) ? 1 : 0, $id));
	sa_out(array('ok' => true, 'message' => 'Liefergebiet gespeichert.'));
}

sa_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
