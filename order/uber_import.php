<?php
/*
 * Machine interface for the Uber Eats receipts: the Pi in the kitchen (tools/kitchen-pi/uber-eats-bridge) acts as the network receipt printer of the Uber Eats tablet, builds the
 * receipt image out of each print job and POSTs it here as the raw PNG (Content-Type image/png) or as multipart field "png". Checked by header X-Api-Key
 * (shop_uber_key(), shown in the backend under Einstellungen > Lieferservice) — no session, this is not a browser page. Answers JSON with 'ok'. The image is stored
 * (tp_shop_uber_slips, kept SHOP_UBER_KEEP_DAYS days; the same image twice is stored once, 'duplicate' => true). If the address of the n8n webhook is set, the answer goes out first and then the
 * receipt is read (shop_uber_process, see shop_uber.class.php); with the setting "automatic" (uber_auto_import) a receipt that was read and adds up also becomes an order and the kitchen slip is printed
 * (shop_uber_auto), otherwise it is only read and checked and a person takes it over in the backend.
 */
require_once __DIR__.'/../web/classes/mysql_compat.php';
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop.class.php');
require_once(__DIR__.'/../web/classes/shop_uber.class.php');
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
if (empty($res['ok']) || shop_uber_read_url() === '') { ub_out($res, !empty($res['ok']) ? 200 : 422); }

// the Pi gets its answer first (it must not wait for the vision model); then this receipt is read, and with it the ones that failed earlier (n8n was not reachable)
$out = json_encode($res, JSON_UNESCAPED_UNICODE);
http_response_code(200); header('Content-Length: '.strlen($out)); header('Connection: close'); echo $out;
while (ob_get_level()) { ob_end_flush(); }
flush();
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
ignore_user_abort(true); set_time_limit(300);
foreach (shop_uber_pending(3) as $slipId) {
	$r = shop_uber_process($slipId);
	if (!empty($r['status']) && $r['status'] === 'read') { try { shop_uber_auto($slipId); } catch (Throwable $e) { error_log('uber auto import: '.$e->getMessage()); } }
}
