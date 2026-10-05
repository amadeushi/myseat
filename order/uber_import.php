<?php
/*
 * Machine interface for the Uber Eats receipts: the Pi in the kitchen (tools/kitchen-pi/uber-eats-bridge) acts as the network receipt printer of the Uber Eats tablet, builds the
 * receipt image out of each print job and POSTs it here as the raw PNG (Content-Type image/png) or as multipart field "png". Checked by header X-Api-Key
 * (shop_uber_key(), shown in the backend under Einstellungen > Lieferservice) — no session, this is not a browser page. Answers JSON with 'ok'. For now the image is only stored
 * (tp_shop_uber_slips, kept SHOP_UBER_KEEP_DAYS days); reading the order out of it comes later. The same image twice is stored once ('duplicate' => true).
 */
require_once __DIR__.'/../web/classes/mysql_compat.php';
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function ub_out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { ub_out(array('ok' => false, 'error' => 'Nur POST.'), 405); }

shop_ensure_schema();
$given = isset($_SERVER['HTTP_X_API_KEY']) ? (string)$_SERVER['HTTP_X_API_KEY'] : '';
if ($given === '' || !hash_equals(shop_uber_key(), $given)) {
	ub_out(array('ok' => false, 'error' => 'Ungültiger oder fehlender X-Api-Key.'), 403);
}

if (!empty($_FILES['png']['tmp_name']) && is_uploaded_file($_FILES['png']['tmp_name'])) {
	$bytes = file_get_contents($_FILES['png']['tmp_name']);
} else {
	$bytes = file_get_contents('php://input');
}
if ($bytes === false || $bytes === '' || strlen($bytes) > 4 * 1024 * 1024) {
	ub_out(array('ok' => false, 'error' => 'Kein Bild erhalten (oder größer als 4 MB).'), 400);
}

$res = shop_uber_store($bytes);
ub_out($res, !empty($res['ok']) ? 200 : 422);
