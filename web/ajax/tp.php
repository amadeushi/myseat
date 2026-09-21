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
include('../classes/tableplan_assign.class.php');
include('../classes/online_block.class.php');

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
if (!current_user_can('Reservation-Edit') && !current_user_can('Page-System') && !current_user_can('Reservation-New')) {
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

$structure_actions = array('table_save', 'table_delete', 'link_toggle', 'area_save', 'area_delete', 'area_move', 'closure_save', 'closure_delete', 'setting_save');
if (in_array($action, $structure_actions, true) && !$can_edit_plan) {
	tp_fail('Der Plan darf nur von Administratoren bearbeitet werden', 403);
}

switch ($action) {
	case 'load':
		$areas = tp_list_areas($outlet_id);
		tp_out(array(
			'areas'    => $areas,
			'closures' => tp_list_closures($outlet_id),
			'autoAssign' => tp_get_setting('auto_assign', '1') === '1',
			'availabilityMode' => tp_availability_mode(),
			'counter' => tp_counter_info($outlet_id),
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

	case 'closure_save':
		$saved = tp_save_closure($outlet_id, isset($data['closure']) && is_array($data['closure']) ? $data['closure'] : array());
		if (!$saved) {
			tp_fail('Sperrzeitraum ungültig (Datum fehlt oder „bis“ liegt vor „von“)');
		}
		tp_out(array('ok' => true, 'closures' => tp_list_closures($outlet_id)));

	case 'closure_delete':
		if (!tp_delete_closure($outlet_id, isset($data['closure_id']) ? (int)$data['closure_id'] : 0)) {
			tp_fail('Sperrzeitraum nicht gefunden');
		}
		tp_out(array('ok' => true, 'closures' => tp_list_closures($outlet_id)));

	case 'setting_save':
		if (array_key_exists('autoAssign', $data)) {
			tp_set_setting('auto_assign', !empty($data['autoAssign']) ? '1' : '0');
		}
		if (array_key_exists('availabilityMode', $data)) {
			$mode = $data['availabilityMode'] === 'tables' ? 'tables' : 'counter';
			if ($mode === 'tables' && tp_active_table_count($outlet_id) < 1) {
				tp_fail('Für die Verfügbarkeit nach Tischplan zuerst Tische anlegen');
			}
			tp_set_setting('availability_mode', $mode);
		}
		tp_out(array('ok' => true, 'autoAssign' => tp_get_setting('auto_assign', '1') === '1', 'availabilityMode' => tp_availability_mode()));

	case 'free_tables':
		$date = isset($data['date']) ? (string)$data['date'] : '';
		$time = isset($data['time']) ? (string)$data['time'] : '';
		if (!tp_is_date($date) || ($time !== '' && !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time))) {
			tp_fail('Ungültige Angaben');
		}
		tp_out(array('ok' => true) + tp_table_options($outlet_id, $date, substr($time, 0, 5), isset($data['pax']) ? (int)$data['pax'] : 0,
			isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0));

	case 'preview':
		$date = isset($data['date']) ? (string)$data['date'] : '';
		if (!tp_is_date($date)) {
			tp_fail('Ungültiges Datum');
		}
		$pax = max(1, min(99, isset($data['pax']) ? (int)$data['pax'] : 2));
		$interval = isset($general['timeintervall']) ? (int)$general['timeintervall'] : 15;
		$why = tp_online_day_block_reason($outlet_id, $date);
		tp_out(array('ok' => true, 'date' => $date, 'pax' => $pax, 'reason' => $why, 'slots' => $why ? array() : tp_online_preview($outlet_id, $date, $pax, $interval)));

	case 'day':
		$date = isset($data['date']) ? (string)$data['date'] : '';
		if (!tp_is_date($date)) {
			tp_fail('Ungültiges Datum');
		}
		tp_out(array('ok' => true) + tp_day_payload($outlet_id, $date));

	case 'assign':
		$r = tp_assign($outlet_id, isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0,
			isset($data['table_ids']) && is_array($data['table_ids']) ? $data['table_ids'] : array(), !empty($data['confirm']));
		if (empty($r['ok'])) {
			tp_out($r, !empty($r['needs_confirm']) ? 200 : 400);
		}
		tp_out(array('ok' => true) + tp_day_payload($outlet_id, $r['date']));

	case 'auto_assign':
		$rid = isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0;
		$row = tp_reservation_row($outlet_id, $rid);
		if (!$row) {
			tp_fail('Reservierung nicht gefunden');
		}
		$found = tp_auto_assign($outlet_id, $rid);
		tp_out(array('ok' => true, 'found' => (bool)$found) + tp_day_payload($outlet_id, $row['reservation_date']));

	case 'auto_assign_day':
		$date = isset($data['date']) ? (string)$data['date'] : '';
		if (!tp_is_date($date)) {
			tp_fail('Ungültiges Datum');
		}
		$sum = tp_auto_assign_day($outlet_id, $date);
		tp_out(array('ok' => true, 'summary' => $sum) + tp_day_payload($outlet_id, $date));

	default:
		tp_fail('Unbekannte Aktion');
}
