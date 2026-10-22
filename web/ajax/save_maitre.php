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
// own hours of this date (no break times): both empty = the normal hours of the weekday
maitre_ensure_columns();
$t_open_in = trim((string)(isset($_POST['day_open_time']) ? $_POST['day_open_time'] : '')); $t_close_in = trim((string)(isset($_POST['day_close_time']) ? $_POST['day_close_time'] : ''));
$t_open = maitre_parse_time($t_open_in); $t_close = maitre_parse_time($t_close_in);
if (($t_open_in !== '' && $t_open === null) || ($t_close_in !== '' && $t_close === null)) { sm_out(array('ok' => false, 'error' => 'Bitte die Zeiten als Uhrzeit angeben, zum Beispiel 11:00.')); }
if (($t_open === null) !== ($t_close === null)) { sm_out(array('ok' => false, 'error' => 'Bitte Beginn und Ende angeben, oder beide leer lassen.')); }
if ($t_open !== null && $t_open === $t_close) { sm_out(array('ok' => false, 'error' => 'Beginn und Ende dürfen nicht gleich sein.')); }
$author = isset($_SESSION['u_fullname']) ? mb_substr($_SESSION['u_fullname'], 0, 250) : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 20) : '';

$st = mysqli_prepare($link, "SELECT maitre_id, maitre_comment_day, outlet_child_capacity, outlet_child_tables, outlet_child_passer_max_pax, day_open_time, day_close_time
	FROM `".$dbTables->maitre."` WHERE maitre_outlet_id = ? AND maitre_date = ? ORDER BY maitre_id LIMIT 1");
mysqli_stmt_bind_param($st, 'is', $outlet_id, $date);
mysqli_stmt_execute($st);
$old = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

if ($old) {
	$note_changed = ((string)$old['maitre_comment_day'] !== $comment);
	$st = mysqli_prepare($link, "UPDATE `".$dbTables->maitre."` SET maitre_comment_day = ?,
		maitre_comment_day_timestamp = IF(?, NOW(), maitre_comment_day_timestamp), maitre_comment_day_name = IF(?, ?, maitre_comment_day_name),
		outlet_child_capacity = ?, outlet_child_tables = ?, outlet_child_passer_max_pax = ?, outlet_child_dayoff = ?, day_open_time = ?, day_close_time = ?,
		maitre_ip = ?, maitre_author = ? WHERE maitre_id = ?");
	$nc = $note_changed ? 1 : 0; $mid = (int)$old['maitre_id'];
	mysqli_stmt_bind_param($st, 'siisiiisssssi', $comment, $nc, $nc, $author, $capacity, $tables, $passerby, $dayoff, $t_open, $t_close, $ip, $author, $mid);
	$hours_changed = (maitre_parse_time($old['day_open_time']) !== $t_open) || (maitre_parse_time($old['day_close_time']) !== $t_close);
	$changed_numbers = ((int)$old['outlet_child_capacity'] !== $capacity) || ((int)$old['outlet_child_tables'] !== $tables)
		|| (($old['outlet_child_passer_max_pax'] === null ? null : (int)$old['outlet_child_passer_max_pax']) !== $passerby);
} else {
	$st = mysqli_prepare($link, "INSERT INTO `".$dbTables->maitre."`
		(maitre_outlet_id, maitre_date, maitre_comment_day, maitre_comment_day_timestamp, maitre_comment_day_name,
		 outlet_child_capacity, outlet_child_tables, outlet_child_passer_max_pax, outlet_child_dayoff, day_open_time, day_close_time, maitre_ip, maitre_author)
		VALUES (?, ?, ?, IF(? <> '', NOW(), NULL), ?, ?, ?, ?, ?, ?, ?, ?, ?)");
	mysqli_stmt_bind_param($st, 'issssiiisssss', $outlet_id, $date, $comment, $comment, $author, $capacity, $tables, $passerby, $dayoff, $t_open, $t_close, $ip, $author);
	$hours_changed = ($t_open !== null);
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
// reservations that lie outside the own hours stay, new ones cannot be made there
$outside = 0;
if ($t_open !== null) {
	$cmp = ($t_open < $t_close) ? "(reservation_time < ? OR reservation_time > ?)" : "(reservation_time > ? AND reservation_time < ?)";   // the second form: the day ends after midnight
	$a = ($t_open < $t_close) ? $t_open : $t_close; $b = ($t_open < $t_close) ? $t_close : $t_open;
	$st = mysqli_prepare($link, "SELECT COUNT(*) FROM `".$dbTables->reservations."` WHERE reservation_outlet_id = ? AND reservation_date = ? AND reservation_hidden = 0 AND ".$cmp);
	mysqli_stmt_bind_param($st, 'isss', $outlet_id, $date, $a, $b);
	mysqli_stmt_execute($st);
	$row = mysqli_fetch_row(mysqli_stmt_get_result($st)); $outside = $row ? (int)$row[0] : 0;
}
if ($hours_changed) {
	$message = $t_open !== null ? 'Zeiten für diesen Tag gespeichert: '.substr($t_open, 0, 5).' - '.substr($t_close, 0, 5).' Uhr.'.($outside > 0 ? ' Achtung: '.$outside.' Reservierung'.($outside === 1 ? ' liegt' : 'en liegen').' außerhalb. '.($outside === 1 ? 'Sie bleibt' : 'Sie bleiben').' bestehen.' : '') : 'Der Tag hat wieder die üblichen Zeiten.';
}
if ($dayoff === 'ON' && ($dayoff_initial === 0)) {
	$message = 'Ruhetag gespeichert.'.($active > 0 ? ' Achtung: An diesem Tag gibt es noch '.$active.' Reservierung'.($active === 1 ? '' : 'en').'. Sie bleiben bestehen, werden in der Liste aber ausgeblendet.' : '');
} elseif ($dayoff === 'OFF' && $dayoff_initial === 1) {
	$message = 'Der Tag ist wieder geöffnet.';
}
sm_out(array(
	'ok' => true, 'maitre_id' => $mid, 'message' => $message,
	'reload' => ((($dayoff === 'ON') ? 1 : 0) !== $dayoff_initial) || $changed_numbers || $hours_changed,
	'note_html' => maitre_note_html($comment),
));
