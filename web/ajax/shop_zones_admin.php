<?php
/*
 * Delivery zone editor (backend page p=11, web/content/shop_zones.page.php): draw, edit and delete the polygons
 * themselves. Name/fee/min/active still go through web/ajax/shop_admin.php's 'zone' op (used by the settings
 * page too); this endpoint only ever touches a zone's shape or its existence. Answers JSON. Needs a login with
 * Settings-General and the shared admin token (session key shop_admin_token, same one the menu editor uses).
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
function sz_out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	sz_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';

if ($op === 'load') {
	$zones = shop_zones_list();
	sz_out(array('ok' => true, 'zones' => $zones, 'overlaps' => shop_zones_overlaps($zones), 'origin' => shop_origin()));
}

// everything below changes something: POST with the shared admin token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$_POST['token'])) {
	sz_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
$data = isset($_POST['data']) ? json_decode((string)$_POST['data'], true) : array();
if (!is_array($data)) { $data = array(); }
$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);

if ($op === 'create') {
	$r = shop_zone_create(isset($data['name']) ? $data['name'] : '', shop_cents(isset($data['fee']) ? $data['fee'] : 0),
		shop_cents(isset($data['min']) ? $data['min'] : 0), !empty($data['active']), isset($data['polygon']) ? $data['polygon'] : array());
	if ($r['ok']) {
		$zones = shop_zones_list();
		sz_out(array('ok' => true, 'id' => $r['id'], 'overlaps' => shop_zones_overlaps($zones)));
	}
	sz_out($r);
}

if ($op === 'save_info') {
	$r = shop_zone_save_info($id, isset($data['name']) ? $data['name'] : '', shop_cents(isset($data['fee']) ? $data['fee'] : 0),
		shop_cents(isset($data['min']) ? $data['min'] : 0), !empty($data['active']));
	sz_out($r);
}

if ($op === 'save_shape') {
	if ($id <= 0) { sz_out(array('ok' => false, 'error' => 'Unbekanntes Gebiet.')); }
	$r = shop_zone_save_shape($id, isset($data['polygon']) ? $data['polygon'] : array());
	if ($r['ok']) {
		$zones = shop_zones_list();
		sz_out(array('ok' => true, 'overlaps' => shop_zones_overlaps($zones)));
	}
	sz_out($r);
}

if ($op === 'delete') {
	if ($id <= 0) { sz_out(array('ok' => false, 'error' => 'Unbekanntes Gebiet.')); }
	shop_zone_delete($id);
	sz_out(array('ok' => true, 'overlaps' => shop_zones_overlaps(shop_zones_list())));
}

sz_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
