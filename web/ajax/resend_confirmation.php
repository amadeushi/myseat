<?php
/*
 * Sends the current details of a reservation to the guest once more ("Bestätigung erneut senden"), with a clear note that something
 * changed. Mail when the guest has an address, else SMS to a mobile number. Answers JSON.
 * Used by the button in the reservation details (includes/reservation_detail.inc.php); the work is appr_notify_update() in
 * classes/approval.class.php.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/local.class.php');
include('../classes/business.class.php');
include('../classes/db_queries.db.php');
include('../../config/config.inc.php');
require_once('../classes/approval.class.php');

header('Content-Type: application/json; charset=utf-8');
function rsv_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	rsv_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	rsv_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}

appr_ensure_schema();
$link = $GLOBALS['__mysql_compat_link'];
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$prop = (int)$_SESSION['propertyID'];
$st = mysqli_prepare($link, "SELECT r.* FROM `".$dbTables->reservations."` r JOIN `".$dbTables->outlets."` o ON o.outlet_id = r.reservation_outlet_id WHERE r.reservation_id = ? AND o.property_id = ? LIMIT 1");
mysqli_stmt_bind_param($st, 'ii', $id, $prop);
mysqli_stmt_execute($st);
$r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$r) { rsv_out(array('ok' => false, 'error' => 'Reservierung nicht gefunden.')); }
if ((int)$r['reservation_hidden'] === 1) { rsv_out(array('ok' => false, 'error' => 'Die Reservierung ist storniert.')); }
if ($r['reservation_approval'] === 'pending') { rsv_out(array('ok' => false, 'error' => 'Die Anfrage ist noch nicht entschieden. Bitte bestätige sie zuerst.')); }
if (strtotime(substr($r['reservation_date'], 0, 10).' 23:59:59') < time()) { rsv_out(array('ok' => false, 'error' => 'Der Termin liegt in der Vergangenheit.')); }

rsv_out(appr_notify_update($r));
