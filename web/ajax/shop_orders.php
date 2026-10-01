<?php
/*
 * Orders for the staff (dashboard "Bestellungen" and the kitchen monitor): the board of open orders, the orders of a day,
 * moving an order to the next status, deleting test orders. Answers JSON. Needs a login with Reservation-Edit and the
 * token of the page (own key in the session, so it stays valid for a kitchen monitor that is open all day).
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
function so_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	so_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';

if ($op === 'board') {
	so_out(array('ok' => true, 'now' => date('H:i'), 'orders' => shop_board()));
}
if ($op === 'kitchen') {
	so_out(array('ok' => true, 'now' => date('H:i'), 'orders' => shop_kitchen_board()));
}
if ($op === 'day') {
	$date = (isset($_REQUEST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date'])) ? $_REQUEST['date'] : date('Y-m-d');
	$filter = (isset($_REQUEST['filter']) && $_REQUEST['filter'] === 'open') ? 'open' : 'all';
	so_out(array('ok' => true, 'date' => $date, 'orders' => shop_day_orders($date, $filter), 'stats' => shop_day_stats($date)));
}
if ($op === 'drivers_live') {
	so_out(array('ok' => true, 'origin' => shop_origin(), 'drivers' => shop_drivers_live()));
}

// everything below changes something: POST with the token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$_POST['token'])) {
	so_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
if ($op === 'demo') {
	$new = shop_create_demo_order(isset($_POST['type']) ? (string)$_POST['type'] : 'delivery');
	so_out($new ? array('ok' => true, 'id' => $new) : array('ok' => false, 'error' => 'Die Testbestellung konnte nicht angelegt werden.'));
}
$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
$order = $id ? shop_order($id) : null;
if (!$order) { so_out(array('ok' => false, 'error' => 'Diese Bestellung gibt es nicht.')); }
$who = isset($_SESSION['valid_user']) && is_string($_SESSION['valid_user']) ? $_SESSION['valid_user'] : 'Personal';

if ($op === 'status') {
	$to = isset($_POST['status']) ? (string)$_POST['status'] : '';
	$allowed = array('new', 'accepted', 'preparing', 'ready', 'delivering', 'done', 'cancelled');
	if (!in_array($to, $allowed, true)) { so_out(array('ok' => false, 'error' => 'Unbekannter Status.')); }
	if ($order['status'] === 'pending') { so_out(array('ok' => false, 'error' => 'Diese Bestellung wartet noch auf die Zahlung.')); }
	// cash and card orders count as paid when the order is finished
	if ($to === 'done' && $order['payment_method'] !== 'mollie' && $order['payment_status'] !== 'paid') {
		fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET payment_status = 'paid' WHERE id = ?", 'i', array($id));
	}
	shop_set_status($id, $to, $who, (int)(isset($_POST['eta']) ? $_POST['eta'] : 0));
	so_out(array('ok' => true));
}
if ($op === 'release_to_pool') {
	so_out(shop_dispatch_release_order($id, $who));
}
if ($op === 'delete_test') {
	so_out(shop_delete_test_order($id) ? array('ok' => true) : array('ok' => false, 'error' => 'Nur Testbestellungen können gelöscht werden.'));
}
so_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
