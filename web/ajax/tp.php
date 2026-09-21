<?php
/*
 * JSON endpoint of the table plan (Tischplan). POST only, body = JSON {"action": "...", ...}.
 * Needs a logged in backend user and the CSRF token of the page (header X-TP-Token).
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
include('../classes/tableplan.class.php');

function tp_out($payload, $code = 200) {
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}
function tp_fail($msg, $code = 400) {
	tp_out(array('ok' => false, 'error' => $msg), $code);
}

if (empty($_SESSION['valid_user']) || empty($_SESSION['role'])) {
	tp_fail('Nicht angemeldet', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	tp_fail('POST erforderlich', 405);
}
$token = isset($_SESSION['tp_token']) ? $_SESSION['tp_token'] : '';
$sent  = isset($_SERVER['HTTP_X_TP_TOKEN']) ? $_SERVER['HTTP_X_TP_TOKEN'] : '';
if ($token === '' || !hash_equals($token, $sent)) {
	tp_fail('Ungültiges Token - Seite neu laden', 403);
}
if (!current_user_can('Reservation-Edit') && !current_user_can('Page-System')) {
	tp_fail('Keine Berechtigung', 403);
}
$can_edit_plan = (bool)current_user_can('Page-System');

$data   = json_decode(file_get_contents('php://input'), true);
$data   = is_array($data) ? $data : array();
$action = isset($data['action']) ? (string)$data['action'] : '';

// the outlet is always the one selected in the session, and it has to belong to the user's property
$outlet_id = isset($_SESSION['outletID']) ? (int)$_SESSION['outletID'] : 0;
$prop_id   = isset($_SESSION['property']) ? (int)$_SESSION['property'] : 0;
$ok_outlet = tp_rows("SELECT `outlet_id` FROM `".$dbTables->outlets."` WHERE `outlet_id` = ? AND `property_id` = ?", 'ii', array($outlet_id, $prop_id));
if (!$ok_outlet) {
	tp_fail('Unbekanntes Outlet', 400);
}

tp_ensure_schema();

$structure_actions = array('table_save', 'table_delete', 'link_toggle', 'area_save', 'area_delete', 'area_move');
if (in_array($action, $structure_actions, true) && !$can_edit_plan) {
	tp_fail('Der Plan darf nur von Administratoren bearbeitet werden', 403);
}

switch ($action) {
	case 'load':
		$areas = tp_list_areas($outlet_id);
		tp_out(array(
			'areas'    => $areas,
			'ok'       => true,
			'outlet'   => $outlet_id,
			'canvas'   => array('w' => TP_CANVAS_W, 'h' => TP_CANVAS_H),
			'canEdit'  => $can_edit_plan,
			'tables'   => tp_list_tables($outlet_id),
			'links'    => tp_list_links($outlet_id),
		));

	case 'table_save':
		$saved = tp_save_table($outlet_id, isset($data['table']) && is_array($data['table']) ? $data['table'] : array());
		if (!$saved) {
			tp_fail('Tisch konnte nicht gespeichert werden (Name fehlt?)');
		}
		tp_out(array('ok' => true, 'table' => $saved));

	case 'table_delete':
		if (!tp_delete_table($outlet_id, isset($data['table_id']) ? (int)$data['table_id'] : 0)) {
			tp_fail('Tisch nicht gefunden');
		}
		tp_out(array('ok' => true));

	case 'link_toggle':
		$r = tp_toggle_link($outlet_id, isset($data['a']) ? (int)$data['a'] : 0, isset($data['b']) ? (int)$data['b'] : 0);
		if ($r === 'other_area') {
			tp_fail('Verbundene Tische müssen im selben Bereich stehen');
		}
		if (!$r) {
			tp_fail('Verbindung nicht möglich');
		}
		tp_out(array('ok' => true, 'state' => $r, 'links' => tp_list_links($outlet_id)));

	case 'area_save':
		$saved = tp_save_area($outlet_id, isset($data['area']) && is_array($data['area']) ? $data['area'] : array());
		if (!$saved) {
			tp_fail('Bereich konnte nicht gespeichert werden (Name fehlt?)');
		}
		tp_out(array('ok' => true, 'area' => $saved, 'areas' => tp_list_areas($outlet_id)));

	case 'area_delete':
		$r = tp_delete_area($outlet_id, isset($data['area_id']) ? (int)$data['area_id'] : 0);
		if ($r === 'last') {
			tp_fail('Der letzte Bereich kann nicht gelöscht werden');
		}
		if ($r === 'has_tables') {
			tp_fail('Der Bereich enthält noch Tische - bitte zuerst löschen oder in einen anderen Bereich verschieben');
		}
		if (!$r) {
			tp_fail('Bereich nicht gefunden');
		}
		tp_out(array('ok' => true, 'areas' => tp_list_areas($outlet_id)));

	case 'area_move':
		$aid = isset($data['area_id']) ? (int)$data['area_id'] : 0;
		if (!tp_owns_area($outlet_id, $aid)) {
			tp_fail('Bereich nicht gefunden');
		}
		tp_out(array('ok' => true, 'areas' => tp_move_area($outlet_id, $aid, isset($data['dir']) ? (int)$data['dir'] : 0)));

	default:
		tp_fail('Unbekannte Aktion');
}
