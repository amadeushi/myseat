<?php
/*
 * Backend of the POS page (web/content/orders_pos.page.php): the full menu to pick from, a live zone/fee check
 * while typing an address, a caller's order history by phone, the incoming-call banner, and creating the order
 * itself. Needs a login with Reservation-Edit, same as the rest of the "Bestellungen" dashboard.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function pos_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	pos_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();

$body = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$raw = file_get_contents('php://input');
	$body = json_decode((string)$raw, true);
	if (!is_array($body)) { $body = $_POST; }
}
$op = isset($_GET['op']) ? (string)$_GET['op'] : (isset($body['op']) ? (string)$body['op'] : '');

if ($op === 'menu') {
	pos_out(array('ok' => true, 'categories' => shop_pos_catalog()));
}
if ($op === 'zone') {
	$street = mb_substr((string)(isset($_GET['street']) ? $_GET['street'] : ''), 0, 160);
	$zip = mb_substr((string)(isset($_GET['zip']) ? $_GET['zip'] : ''), 0, 10);
	$city = mb_substr((string)(isset($_GET['city']) ? $_GET['city'] : ''), 0, 80);
	$r = shop_find_zone($street, $zip, $city);
	if (!$r['ok']) { pos_out(array('ok' => false, 'error' => $r['error'], 'reason' => isset($r['reason']) ? $r['reason'] : '', 'candidates' => isset($r['candidates']) ? $r['candidates'] : array())); }
	pos_out(array('ok' => true, 'zone' => array('id' => $r['zone']['id'], 'name' => $r['zone']['name'], 'fee' => $r['zone']['fee_cents']), 'postcode' => isset($r['postcode']) ? $r['postcode'] : ''));
}
if ($op === 'guest_history') {
	$phone = isset($_GET['phone']) ? (string)$_GET['phone'] : '';
	pos_out(array('ok' => true, 'orders' => shop_guest_history($phone)));
}
if ($op === 'last_call') {
	pos_out(array('ok' => true, 'call' => shop_last_call()));
}

// everything below changes something: POST with the admin token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($body['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$body['token'])) {
	pos_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
if ($op === 'create') {
	pos_out(shop_create_manual_order($body));
}
pos_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
