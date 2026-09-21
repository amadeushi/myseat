<?php
/*
 * JSON endpoint: block / unblock online bookings for one day (POST {"outlet_id","date","blocked","reason"}).
 * Needs a logged in backend user with the daily-outlet edit right and the CSRF token of the page.
 */
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

include('../../config/config.general.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/local.class.php');
include('../classes/business.class.php');
include('../classes/db_queries.db.php');
include('../../config/config.inc.php');
include('../classes/online_block.class.php');

function ob_out($payload, $code = 200) {
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}
function ob_fail($msg, $code = 400) {
	ob_out(array('ok' => false, 'error' => $msg), $code);
}

if (empty($_SESSION['valid_user']) || empty($_SESSION['role'])) {
	ob_fail('Nicht angemeldet', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	ob_fail('POST erforderlich', 405);
}
$token = isset($_SESSION['ob_token']) ? $_SESSION['ob_token'] : '';
$sent  = isset($_SERVER['HTTP_X_OB_TOKEN']) ? $_SERVER['HTTP_X_OB_TOKEN'] : '';
if ($token === '' || !hash_equals($token, $sent)) {
	ob_fail('Ungültiges Token - Seite neu laden', 403);
}
if (!current_user_can('Daily-Outlet-Edit')) {
	ob_fail('Keine Berechtigung', 403);
}

$data = json_decode(file_get_contents('php://input'), true);
$data = is_array($data) ? $data : array();
$outlet_id = isset($data['outlet_id']) ? (int)$data['outlet_id'] : 0;
$date      = isset($data['date']) ? (string)$data['date'] : '';
$prop_id   = isset($_SESSION['property']) ? (int)$_SESSION['property'] : 0;

if (!ob_valid_date($date)) {
	ob_fail('Ungültiges Datum');
}
if (!tp_rows("SELECT `outlet_id` FROM `".$dbTables->outlets."` WHERE `outlet_id` = ? AND `property_id` = ?", 'ii', array($outlet_id, $prop_id))) {
	ob_fail('Unbekanntes Outlet');
}

$by = isset($_SESSION['u_fullname']) ? $_SESSION['u_fullname'] : (isset($_SESSION['u_name']) ? $_SESSION['u_name'] : '');
$state = ob_set($outlet_id, $date, !empty($data['blocked']), isset($data['reason']) ? $data['reason'] : '', $by);
if ($state === null) {
	ob_fail('Speichern fehlgeschlagen', 500);
}
$row = ob_get($outlet_id, $date);
ob_out(array('ok' => true, 'blocked' => $state, 'reason' => $row ? $row['reason'] : ''));
