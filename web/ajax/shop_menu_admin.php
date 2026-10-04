<?php
/*
 * Menu editor of the delivery service (backend page p=10): load the whole menu, create/change/delete categories, dishes and
 * option groups ("Zubehörgruppen"), move things up and down. Answers JSON. Needs a login with Settings-General and the token
 * of the page (own key in the session, shop_admin_token).
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
function sm_out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	sm_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';

if ($op === 'load') {
	sm_out(array('ok' => true) + shop_me_load());
}

// everything below changes something: POST with the token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$_POST['token'])) {
	sm_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
$data = isset($_POST['data']) ? json_decode((string)$_POST['data'], true) : array();
if (!is_array($data)) { $data = array(); }
$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
$dir = (isset($_POST['dir']) && $_POST['dir'] === 'up') ? -1 : 1;

switch ($op) {
	case 'img_upload':      $r = shop_img_from_upload(isset($_FILES['file']) ? $_FILES['file'] : null); shop_img_gc(); sm_out($r);
	case 'img_fetch':       $r = shop_img_fetch(isset($_POST['url']) ? $_POST['url'] : ''); shop_img_gc(); sm_out($r);
	case 'img_list':        sm_out(array('ok' => true, 'items' => shop_img_external()));
	case 'img_localize':    sm_out(shop_img_localize($id));
	case 'category_save':   sm_out(shop_me_save_category($data));
	case 'category_delete': sm_out(shop_me_delete_category($id));
	case 'category_move':   sm_out(array('ok' => shop_me_move('tp_shop_categories', $id, $dir)) + shop_me_load());
	case 'product_save':    sm_out(shop_me_save_product($data));
	case 'product_delete':  sm_out(shop_me_delete_product($id));
	case 'product_move':    sm_out(array('ok' => shop_me_move('tp_shop_products', $id, $dir, 'category_id')) + shop_me_load());
	case 'group_save':      sm_out(shop_me_save_group($data));
	case 'group_copy':      sm_out(shop_me_copy_group($id));
	case 'group_delete':    sm_out(shop_me_delete_group($id));
	case 'coupon_save':     sm_out(shop_me_save_coupon($data));
	case 'coupon_delete':   sm_out(shop_me_delete_coupon($id));
}
sm_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
