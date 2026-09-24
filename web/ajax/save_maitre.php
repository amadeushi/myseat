<?php
/*
 * Saves the day details of one outlet and date in a single request: day comment, extra seats,
 * extra tables, passerby limit and the single-day "Ruhetag". One row per outlet+date (looked up,
 * not trusted from the browser). Answers JSON; "reload" tells the page whether the day list itself
 * changes (closed day, capacity) and has to be reloaded - a plain comment is swapped in place.
 */
session_start();
include('../../config/config.general.php');
include('../classes/database.class.php');
include('../classes/connect.db.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/maitre.class.php');

header('Content-Type: application/json; charset=utf-8');
function sm_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Daily-Outlet-Edit')) {
	http_response_code(403);
	sm_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	sm_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}

$link = $GLOBALS['__mysql_compat_link'];
$outlet_id = isset($_POST['maitre_outlet_id']) ? (int)$_POST['maitre_outlet_id'] : 0;
$date = isset($_POST['maitre_date']) ? $_POST['maitre_date'] : '';
if ($outlet_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { sm_out(array('ok' => false, 'error' => 'Ungültige Angaben.')); }

// the outlet must belong to the logged-in property
$st = mysqli_prepare($link, "SELECT outlet_id FROM `".$dbTables->outlets."` WHERE outlet_id = ? AND property_id = ? LIMIT 1");
$prop = (int)$_SESSION['propertyID'];
mysqli_stmt_bind_param($st, 'ii', $outlet_id, $prop);
mysqli_stmt_execute($st);
if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) { sm_out(array('ok' => false, 'error' => 'Unbekanntes Outlet.')); }

// values, stored the way the rest of the app stores text (html entities)
$raw = trim((string)(isset($_POST['maitre_comment_day']) ? $_POST['maitre_comment_day'] : ''));
$raw = mb_substr($raw, 0, 200);
do { $comment = htmlentities($raw, ENT_QUOTES, 'UTF-8'); if (strlen($comment) <= 255) { break; } $raw = mb_substr($raw, 0, mb_strlen($raw) - 10); } while ($raw !== '');
$capacity = abs((int)(isset($_POST['outlet_child_capacity']) ? $_POST['outlet_child_capacity'] : 0));
$tables   = abs((int)(isset($_POST['outlet_child_tables']) ? $_POST['outlet_child_tables'] : 0));
$pass_in  = isset($_POST['outlet_child_passer_max_pax']) ? trim($_POST['outlet_child_passer_max_pax']) : '';
$passerby = ($pass_in === '') ? null : abs((int)$pass_in);
$dayoff   = (isset($_POST['outlet_child_dayoff']) && $_POST['outlet_child_dayoff'] === 'ON') ? 'ON' : 'OFF';
$dayoff_initial = !empty($_POST['dayoff_initial']) ? 1 : 0;
$author = isset($_SESSION['u_fullname']) ? mb_substr($_SESSION['u_fullname'], 0, 250) : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 20) : '';

$st = mysqli_prepare($link, "SELECT maitre_id, maitre_comment_day, outlet_child_capacity, outlet_child_tables, outlet_child_passer_max_pax
	FROM `".$dbTables->maitre."` WHERE maitre_outlet_id = ? AND maitre_date = ? ORDER BY maitre_id LIMIT 1");
mysqli_stmt_bind_param($st, 'is', $outlet_id, $date);
mysqli_stmt_execute($st);
$old = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

if ($old) {
	$note_changed = ((string)$old['maitre_comment_day'] !== $comment);
	$st = mysqli_prepare($link, "UPDATE `".$dbTables->maitre."` SET maitre_comment_day = ?,
		maitre_comment_day_timestamp = IF(?, NOW(), maitre_comment_day_timestamp), maitre_comment_day_name = IF(?, ?, maitre_comment_day_name),
		outlet_child_capacity = ?, outlet_child_tables = ?, outlet_child_passer_max_pax = ?, outlet_child_dayoff = ?,
		maitre_ip = ?, maitre_author = ? WHERE maitre_id = ?");
	$nc = $note_changed ? 1 : 0; $mid = (int)$old['maitre_id'];
	mysqli_stmt_bind_param($st, 'siisiiisssi', $comment, $nc, $nc, $author, $capacity, $tables, $passerby, $dayoff, $ip, $author, $mid);
	$changed_numbers = ((int)$old['outlet_child_capacity'] !== $capacity) || ((int)$old['outlet_child_tables'] !== $tables)
		|| (($old['outlet_child_passer_max_pax'] === null ? null : (int)$old['outlet_child_passer_max_pax']) !== $passerby);
} else {
	$st = mysqli_prepare($link, "INSERT INTO `".$dbTables->maitre."`
		(maitre_outlet_id, maitre_date, maitre_comment_day, maitre_comment_day_timestamp, maitre_comment_day_name,
		 outlet_child_capacity, outlet_child_tables, outlet_child_passer_max_pax, outlet_child_dayoff, maitre_ip, maitre_author)
		VALUES (?, ?, ?, IF(? <> '', NOW(), NULL), ?, ?, ?, ?, ?, ?, ?)");
	mysqli_stmt_bind_param($st, 'issssiiisss', $outlet_id, $date, $comment, $comment, $author, $capacity, $tables, $passerby, $dayoff, $ip, $author);
	$changed_numbers = ($capacity !== 0 || $tables !== 0 || $passerby !== null);
	$mid = 0;
}
if (!mysqli_stmt_execute($st)) {
	error_log('mySeat save_maitre: '.mysqli_stmt_error($st));
	sm_out(array('ok' => false, 'error' => 'Speichern fehlgeschlagen.'));
}
if (!$mid) { $mid = (int)mysqli_insert_id($link); }

$active = 0;
if ($dayoff === 'ON') {
	$st = mysqli_prepare($link, "SELECT COUNT(*) FROM `".$dbTables->reservations."` WHERE reservation_outlet_id = ? AND reservation_date = ? AND reservation_hidden = 0");
	mysqli_stmt_bind_param($st, 'is', $outlet_id, $date);
	mysqli_stmt_execute($st);
	$row = mysqli_fetch_row(mysqli_stmt_get_result($st));
	$active = $row ? (int)$row[0] : 0;
}

$message = 'Gespeichert.';
if ($dayoff === 'ON' && ($dayoff_initial === 0)) {
	$message = 'Ruhetag gespeichert.'.($active > 0 ? ' Achtung: An diesem Tag gibt es noch '.$active.' Reservierung'.($active === 1 ? '' : 'en').'. Sie bleiben bestehen, werden in der Liste aber ausgeblendet.' : '');
} elseif ($dayoff === 'OFF' && $dayoff_initial === 1) {
	$message = 'Der Tag ist wieder geöffnet.';
}
sm_out(array(
	'ok' => true, 'maitre_id' => $mid, 'message' => $message,
	'reload' => ((($dayoff === 'ON') ? 1 : 0) !== $dayoff_initial) || $changed_numbers,
	'note_html' => maitre_note_html($comment),
));
