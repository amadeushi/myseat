<?php
/*
 * Creates the group pre-order for one reservation (see web/classes/grouporder.class.php). The guest
 * becomes the organizer and receives the links by mail; the pickup time is the reservation's date and
 * time, the order deadline is optional. Stores the links n8n returns. Answers JSON.
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
$st = mysqli_prepare($link, "SELECT r.reservation_id, r.reservation_bookingnumber, r.reservation_guest_name, r.reservation_guest_email, r.reservation_date, r.reservation_time, r.reservation_hidden
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
$res = go_create($r, $name, $email, $pickup, $deadline);
if (empty($res['ok'])) {
	// a transport problem leaves open whether n8n created the group; n8n never creates it twice, so trying again is safe
	go_out(array('ok' => false, 'error' => !empty($res['transport'])
		? $res['error'].' Du kannst es einfach noch einmal versuchen, eine Gruppe wird nie doppelt angelegt.'
		: 'n8n: '.(isset($res['error']) ? $res['error'] : 'unbekannter Fehler')));
}

go_ensure_schema();
$author = isset($_SESSION['u_fullname']) ? mb_substr($_SESSION['u_fullname'], 0, 250) : '';
$token_g = isset($res['group_token']) ? mb_substr((string)$res['group_token'], 0, 40) : '';
$p_url = isset($res['participant_url']) ? mb_substr((string)$res['participant_url'], 0, 255) : '';
$o_url = isset($res['organizer_url']) ? mb_substr((string)$res['organizer_url'], 0, 255) : '';
$st = mysqli_prepare($link, "INSERT INTO ".fb_t('tp_group_orders')." (reservation_id, organizer_email, pickup_at, deadline, created_at, created_by, group_token, participant_url, organizer_url) VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?)");
$dl = ($deadline === '') ? null : $deadline;
mysqli_stmt_bind_param($st, 'isssssss', $id, $email, $pickup, $dl, $author, $token_g, $p_url, $o_url);
mysqli_stmt_execute($st);

// created = false: n8n already had a group for this reservation (an earlier attempt that got lost), no new mail went out
go_out(array('ok' => true,
	'message' => !empty($res['created']) ? 'Gruppenbestellung angelegt. '.$email.' hat die Links per Mail erhalten.' : 'Die Gruppe gab es in n8n schon, sie ist jetzt hier verknüpft. Es ging keine neue Mail raus.',
	'created_at' => date('d.m.Y H:i'), 'participant_url' => $p_url, 'organizer_url' => $o_url));
