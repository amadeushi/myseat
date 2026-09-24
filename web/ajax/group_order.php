<?php
/*
 * Creates the group pre-order for one reservation (see web/classes/grouporder.class.php). The guest
 * becomes the organizer and receives the links by mail; the pickup time is the reservation's date and
 * time, the order deadline is optional. Answers JSON.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/grouporder.class.php');

header('Content-Type: application/json; charset=utf-8');
function go_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	go_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	go_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}

$link = $GLOBALS['__mysql_compat_link'];
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$st = mysqli_prepare($link, "SELECT r.reservation_id, r.reservation_guest_name, r.reservation_guest_email, r.reservation_date, r.reservation_time, r.reservation_hidden
	FROM `".$dbTables->reservations."` r JOIN `".$dbTables->outlets."` o ON o.outlet_id = r.reservation_outlet_id
	WHERE r.reservation_id = ? AND o.property_id = ? LIMIT 1");
$prop = (int)$_SESSION['propertyID'];
mysqli_stmt_bind_param($st, 'ii', $id, $prop);
mysqli_stmt_execute($st);
$r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$r) { go_out(array('ok' => false, 'error' => 'Reservierung nicht gefunden.')); }
if ((int)$r['reservation_hidden'] === 1) { go_out(array('ok' => false, 'error' => 'Die Reservierung ist storniert.')); }

$email = html_entity_decode(trim($r['reservation_guest_email']), ENT_QUOTES, 'UTF-8');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { go_out(array('ok' => false, 'error' => 'Für diese Reservierung ist keine gültige E-Mail-Adresse hinterlegt. Der Gast braucht sie, um die Links zu erhalten.')); }
if (go_find($id)) { go_out(array('ok' => false, 'error' => 'Für diese Reservierung wurde bereits eine Gruppenbestellung angelegt.')); }

$pickup = substr($r['reservation_date'], 0, 10).' '.substr($r['reservation_time'], 0, 5);
if ($pickup <= date('Y-m-d H:i')) { go_out(array('ok' => false, 'error' => 'Der Besuch liegt in der Vergangenheit.')); }
$deadline = '';
if (!empty($_POST['deadline'])) {
	if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}$/', $_POST['deadline'])) { go_out(array('ok' => false, 'error' => 'Der Bestellschluss ist ungültig.')); }
	$deadline = str_replace('T', ' ', $_POST['deadline']);
	if ($deadline >= $pickup) { go_out(array('ok' => false, 'error' => 'Der Bestellschluss muss vor dem Besuch liegen.')); }
	if ($deadline <= date('Y-m-d H:i')) { go_out(array('ok' => false, 'error' => 'Der Bestellschluss liegt schon in der Vergangenheit.')); }
}

$name = html_entity_decode(trim($r['reservation_guest_name']), ENT_QUOTES, 'UTF-8');
$res = go_post($name, $email, $pickup, $deadline);
if (!$res['ok']) {
	error_log('mySeat group order: HTTP '.$res['http'].' '.$res['error'].' '.substr(strip_tags($res['body']), 0, 200));
	go_out(array('ok' => false, 'error' => 'n8n hat die Gruppenbestellung nicht wie erwartet bestätigt. Bitte prüfe in n8n, ob die Gruppe angelegt wurde, bevor du es noch einmal versuchst.'));
}

go_ensure_schema();
$author = isset($_SESSION['u_fullname']) ? mb_substr($_SESSION['u_fullname'], 0, 250) : '';
$st = mysqli_prepare($link, "INSERT INTO ".fb_t('tp_group_orders')." (reservation_id, organizer_email, pickup_at, deadline, created_at, created_by) VALUES (?, ?, ?, ?, NOW(), ?)");
$dl = ($deadline === '') ? null : $deadline;
mysqli_stmt_bind_param($st, 'issss', $id, $email, $pickup, $dl, $author);
mysqli_stmt_execute($st);

go_out(array('ok' => true, 'message' => 'Gruppenbestellung angelegt. '.$email.' hat die Links per Mail erhalten.', 'created_at' => date('d.m.Y H:i')));
