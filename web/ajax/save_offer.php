<?php
/*
 * Saves or deletes one "Angebotszeit" (see web/classes/offers.class.php). multipart/form-data because
 * of the optional picture. Answers JSON.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/offers.class.php');

header('Content-Type: application/json; charset=utf-8');
function so_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-Outlets')) {
	http_response_code(403);
	so_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	so_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}

offers_ensure_schema(); offers_utf8mb4();
$link = fb_db();
$upload_dir = __DIR__.'/../../uploads/offers/';
$drop_image = function ($file) use ($upload_dir) { if ($file && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp|gif)$/', $file) && is_file($upload_dir.$file)) { @unlink($upload_dir.$file); } };

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$old = $id ? offers_find($id) : null;

// the outlet must belong to the logged-in property
$outlet_id = $old ? (int)$old['outlet_id'] : (isset($_POST['outlet_id']) ? (int)$_POST['outlet_id'] : 0);
$st = mysqli_prepare($link, "SELECT outlet_id FROM `".$dbTables->outlets."` WHERE outlet_id = ? AND property_id = ? LIMIT 1");
$prop = (int)$_SESSION['propertyID'];
mysqli_stmt_bind_param($st, 'ii', $outlet_id, $prop);
mysqli_stmt_execute($st);
if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) { so_out(array('ok' => false, 'error' => 'Unbekanntes Outlet.')); }
if ($id && !$old) { so_out(array('ok' => false, 'error' => 'Angebot nicht gefunden.')); }

if (isset($_POST['delete']) && $old) {
	$drop_image($old['image']);
	$st = mysqli_prepare($link, "DELETE FROM ".fb_t('tp_offers')." WHERE offer_id = ?");
	mysqli_stmt_bind_param($st, 'i', $id);
	mysqli_stmt_execute($st);
	so_out(array('ok' => true, 'message' => 'Angebot gelöscht.'));
}

$title = trim((string)(isset($_POST['title']) ? $_POST['title'] : ''));
$title = mb_substr($title, 0, 120);
if ($title === '') { so_out(array('ok' => false, 'error' => 'Bitte gib einen Titel ein.')); }
$desc = mb_substr(trim((string)(isset($_POST['description']) ? $_POST['description'] : '')), 0, 3000);

$days = array();
if (isset($_POST['weekdays']) && is_array($_POST['weekdays'])) { foreach ($_POST['weekdays'] as $d) { if (preg_match('/^[0-6]$/', (string)$d)) { $days[$d] = $d; } } }
if (!$days) { so_out(array('ok' => false, 'error' => 'Bitte wähle mindestens einen Wochentag.')); }
$weekdays = implode(',', array_values($days));

$all_day = !empty($_POST['all_day']) ? 1 : 0;
$from = isset($_POST['time_from']) ? substr((string)$_POST['time_from'], 0, 5) : '';
$to = isset($_POST['time_to']) ? substr((string)$_POST['time_to'], 0, 5) : '';
if ($all_day) { $from = '00:00'; $to = '23:59'; }
elseif (!preg_match('/^\d{2}:\d{2}$/', $from) || !preg_match('/^\d{2}:\d{2}$/', $to)) { so_out(array('ok' => false, 'error' => 'Bitte gib Beginn und Ende der Uhrzeit an.')); }
elseif ($to < $from) { so_out(array('ok' => false, 'error' => 'Das Ende muss nach dem Beginn liegen.')); }

$date_from = null; $date_to = null;
if (!empty($_POST['use_range'])) {
	$df = isset($_POST['date_from']) ? (string)$_POST['date_from'] : ''; $dt = isset($_POST['date_to']) ? (string)$_POST['date_to'] : '';
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $df) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) { so_out(array('ok' => false, 'error' => 'Bitte gib den Zeitraum mit Anfang und Ende an.')); }
	if ($dt < $df) { so_out(array('ok' => false, 'error' => 'Das Ende des Zeitraums liegt vor dem Anfang.')); }
	$date_from = $df; $date_to = $dt;
}
$active = isset($_POST['active']) ? 1 : 0;

// picture: real image types only, random file name, kept small
$image = $old ? $old['image'] : null;
if (!empty($_POST['remove_image'])) { $drop_image($image); $image = null; }
if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
	$f = $_FILES['image'];
	if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 2000000) { so_out(array('ok' => false, 'error' => 'Das Bild konnte nicht hochgeladen werden (höchstens 2 MB).')); }
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
	$ext = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
	if (!isset($ext[$mime]) || !@getimagesize($f['tmp_name'])) { so_out(array('ok' => false, 'error' => 'Bitte lade ein Bild als JPG, PNG, WebP oder GIF hoch.')); }
	if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0755, true); }
	$name = bin2hex(random_bytes(12)).'.'.$ext[$mime];
	if (!move_uploaded_file($f['tmp_name'], $upload_dir.$name)) { so_out(array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.')); }
	$drop_image($image);
	$image = $name;
}

if ($old) {
	$st = mysqli_prepare($link, "UPDATE ".fb_t('tp_offers')." SET title=?, description=?, weekdays=?, time_from=?, time_to=?, all_day=?, date_from=?, date_to=?, image=?, active=? WHERE offer_id=?");
	mysqli_stmt_bind_param($st, 'sssssisssii', $title, $desc, $weekdays, $from, $to, $all_day, $date_from, $date_to, $image, $active, $id);
} else {
	$st = mysqli_prepare($link, "INSERT INTO ".fb_t('tp_offers')." (outlet_id, title, description, weekdays, time_from, time_to, all_day, date_from, date_to, image, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())");
	mysqli_stmt_bind_param($st, 'isssssisssi', $outlet_id, $title, $desc, $weekdays, $from, $to, $all_day, $date_from, $date_to, $image, $active);
}
if (!mysqli_stmt_execute($st)) { error_log('mySeat save_offer: '.mysqli_stmt_error($st)); so_out(array('ok' => false, 'error' => 'Speichern fehlgeschlagen.')); }
so_out(array('ok' => true, 'message' => 'Angebot gespeichert.'));
