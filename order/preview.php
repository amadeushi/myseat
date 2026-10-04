<?php
/*
 * Opens the staff preview of the order page in this browser: the link comes from the backend (web/preview_link.php) and carries a signed
 * token valid for two minutes. The preview shows the shop even when it is not released for guests, and lasts four hours.
 */
require_once __DIR__.'/bootstrap.inc.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (shop_preview_check(isset($_GET['t']) ? $_GET['t'] : '')) {
	session_regenerate_id(true);
	$_SESSION['shop_preview_until'] = time() + 4 * 3600;
	header('Location: ./');
	exit;
}
http_response_code(403);
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vorschau</title></head><body style="font-family:sans-serif;background:#0c0b0a;color:#f6f1e6;padding:32px"><p>Der Vorschau-Link ist abgelaufen oder ungültig. Bitte öffne ihn im Backend neu (Bestellseite ansehen).</p></body></html>';
