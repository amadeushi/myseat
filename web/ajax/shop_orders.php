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

require_once('../classes/session_restore.php'); myseat_restore_session(); // a restarted browser or an expired session: back in from the "stay logged in" cookie
if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	so_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); } // after a restored session: the same token the page was opened with
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';

if ($op === 'board') {
	so_out(array('ok' => true, 'now' => date('H:i'), 'orders' => shop_board(), 'drivers' => shop_dispatch_drivers(), 'pause' => shop_pause_state(), 'drive_min' => max(0, min(60, (int)shop_setting('kitchen_drive_min')))));
}
if ($op === 'kitchen') {
	so_out(array('ok' => true, 'now' => date('H:i'), 'orders' => shop_kitchen_board()));
}
if ($op === 'day') {
	$date = (isset($_REQUEST['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_REQUEST['date'])) ? $_REQUEST['date'] : date('Y-m-d');
	$filter = (isset($_REQUEST['filter']) && in_array($_REQUEST['filter'], array('open', 'closed'), true)) ? $_REQUEST['filter'] : 'all';
	so_out(array('ok' => true, 'date' => $date, 'orders' => shop_day_orders($date, $filter), 'stats' => shop_day_stats($date), 'pause' => shop_pause_state()));
}
// the driver map of the dispatch (web/fahrerkarte.php): drivers, deliveries, who to ask; the zones (rarely change); the track of one driver
if ($op === 'dispatch_map') { so_out(array_merge(array('ok' => true), shop_dispatch_map())); }
if ($op === 'zones') {
	so_out(array('ok' => true, 'zones' => array_values(array_map(function ($z) { return array('id' => $z['id'], 'name' => $z['name'], 'polygon' => $z['polygon'], 'active' => $z['active']); },
		array_filter(shop_zones_list(), function ($z) { return count($z['polygon']) >= 3; })))));
}
if ($op === 'track') {
	so_out(array('ok' => true, 'points' => shop_driver_track((int)(isset($_REQUEST['driver']) ? $_REQUEST['driver'] : 0), (int)(isset($_REQUEST['hours']) ? $_REQUEST['hours'] : 6), 700)));
}
if ($op === 'drivers_live') {
	so_out(array('ok' => true, 'origin' => shop_origin(), 'drivers' => shop_drivers_live()));
}

// everything below changes something: POST with the token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$_POST['token'])) {
	so_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
if ($op === 'pause_set') {
	$kind = (isset($_POST['kind']) && $_POST['kind'] === 'pickup') ? 'pickup' : 'delivery';
	shop_pause_set($kind, !empty($_POST['on']), isset($_POST['minutes']) ? (int)$_POST['minutes'] : 0);
	so_out(array('ok' => true, 'pause' => shop_pause_state()));
}
if ($op === 'demo') {
	$new = shop_create_demo_order(isset($_POST['type']) ? (string)$_POST['type'] : 'delivery',
		isset($_POST['street']) ? (string)$_POST['street'] : '', isset($_POST['zip']) ? (string)$_POST['zip'] : '', isset($_POST['city']) ? (string)$_POST['city'] : '');
	so_out($new ? array('ok' => true, 'id' => $new) : array('ok' => false, 'error' => 'Die Testbestellung konnte nicht angelegt werden.'));
}
$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
$order = $id ? shop_order($id) : null;
if (!$order) { so_out(array('ok' => false, 'error' => 'Diese Bestellung gibt es nicht.')); }
$who = isset($_SESSION['valid_user']) && is_string($_SESSION['valid_user']) ? $_SESSION['valid_user'] : 'Personal';

if ($op === 'print_job') {
	// the kitchen printer on the Raspberry Pi takes the slip when its agent is alive; otherwise the monitor prints through the browser as before
	if (!shop_print_agent_alive()) { so_out(array('ok' => true, 'queued' => false)); }
	shop_print_enqueue($id, !empty($_POST['full']));
	so_out(array('ok' => true, 'queued' => true));
}
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
if ($op === 'retry') {
	so_out(shop_dispatch_retry_order($id, array('street' => isset($_POST['street']) ? $_POST['street'] : '', 'zip' => isset($_POST['zip']) ? $_POST['zip'] : '', 'city' => isset($_POST['city']) ? $_POST['city'] : '',
		'note' => isset($_POST['note']) ? $_POST['note'] : '', 'phone' => isset($_POST['phone']) ? $_POST['phone'] : ''), $who));
}
if ($op === 'assign') {
	so_out(shop_dispatch_assign_order($id, (int)(isset($_POST['driver']) ? $_POST['driver'] : 0), $who));
}
if ($op === 'release_to_pool') {
	so_out(shop_dispatch_release_order($id, $who));
}
if ($op === 'delete_test') {
	so_out(shop_delete_test_order($id) ? array('ok' => true) : array('ok' => false, 'error' => 'Nur Testbestellungen können gelöscht werden.'));
}
so_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
