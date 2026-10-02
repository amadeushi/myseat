<?php
/*
 * sipgate.io Push API: verify a shared URL key
 * before loading the database. No Basic Auth or session cookies are required.
 * Only incoming newCall events update the short-lived caller display.
 */
header('Cache-Control: no-store');
header('Content-Type: text/plain; charset=utf-8');

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	echo 'Nur POST erlaubt';
	exit;
}
require_once(__DIR__.'/sipgate_diagnostics.php');
sipgate_diagnostic_write('request_received');
$expectedKey = require(__DIR__.'/../config/sipgate_webhook_key.php');
$givenKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
if (!is_string($expectedKey) || $expectedKey === '' || $givenKey === '' || !hash_equals($expectedKey, $givenKey)) {
	sipgate_diagnostic_write('url_key_invalid');
	http_response_code(403);
	echo 'Nicht autorisiert';
	exit;
}
sipgate_diagnostic_write('url_key_valid');
$body = file_get_contents('php://input');
if ($body === false) {
	sipgate_diagnostic_write('request_body_unreadable');
	http_response_code(400);
	echo 'Anfrageinhalt nicht lesbar';
	exit;
}

// Parse only the authenticated POST body; query parameters and cookies must not override it.
$data = array();
parse_str($body, $data);
$event = isset($data['event']) && is_string($data['event']) ? $data['event'] : '';
$direction = isset($data['direction']) && is_string($data['direction']) ? $data['direction'] : '';
$from = isset($data['from']) && is_string($data['from']) ? $data['from'] : '';
if ($event === 'newCall' && $direction === 'in' && $from !== '') {
	include(__DIR__.'/../config/config.general.php');
	include(__DIR__.'/../web/classes/mysql_compat.php');
	include(__DIR__.'/../web/classes/connect.db.php');
	require_once(__DIR__.'/../web/classes/shop.class.php');
	shop_record_incoming_call($from);
}

sipgate_diagnostic_write('processed');
header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?><Response />';
