<?php
/*
 * Machine interface for n8n's Lieferando pipeline: n8n watches the Nextcloud folder the tablet prints the order
 * receipt into, and POSTs the PDF here (multipart field "pdf", or the raw bytes as the request body). Checked by
 * header X-Api-Key ($settings['lieferandoApiKey']) — no session, this is not a browser page. Always answers JSON
 * with 'ok'; on success n8n can move the file to a "verarbeitet" folder, on failure it should leave it where it
 * is (and can retry: the same order code is never imported twice, see lieferando_import()).
 */
require_once __DIR__.'/../web/classes/mysql_compat.php';
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop_lieferando.class.php');
date_default_timezone_set('Europe/Berlin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function li_out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { li_out(array('ok' => false, 'error' => 'Nur POST.'), 405); }

$key = lieferando_api_key();
$given = isset($_SERVER['HTTP_X_API_KEY']) ? (string)$_SERVER['HTTP_X_API_KEY'] : '';
if ($key === '' || $given === '' || !hash_equals($key, $given)) {
	li_out(array('ok' => false, 'error' => 'Ungültiger oder fehlender X-Api-Key.'), 403);
}

if (!empty($_FILES['pdf']['tmp_name']) && is_uploaded_file($_FILES['pdf']['tmp_name'])) {
	$bytes = file_get_contents($_FILES['pdf']['tmp_name']);
} else {
	$bytes = file_get_contents('php://input');
}
if ($bytes === false || $bytes === '' || substr($bytes, 0, 4) !== '%PDF') {
	li_out(array('ok' => false, 'error' => 'Keine gültige PDF-Datei erhalten.'), 400);
}

// the Pi sends the receipt as it came ('pdf', the text is read from it) and, cut to the width of the slip, a second copy ('pdf_print') that is kept for printing
$print = null;
if (!empty($_FILES['pdf_print']['tmp_name']) && is_uploaded_file($_FILES['pdf_print']['tmp_name'])) { $print = file_get_contents($_FILES['pdf_print']['tmp_name']); }
$res = lieferando_import($bytes, $print);
li_out($res, !empty($res['ok']) ? 200 : 422);
