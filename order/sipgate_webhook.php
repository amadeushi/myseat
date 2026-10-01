<?php
/*
 * Sipgate.io Push API webhook: sipgate calls this URL for every call event on a configured phone number, with
 * HTTP Basic Auth (username/password chosen freely when the push URL is set up in the sipgate web portal - only
 * the password is checked here, against the one secret saved in Einstellungen > Lieferservice). No session: a
 * machine client sends no cookies. Reacts only to an incoming call starting ("newCall", direction "in") and
 * records the caller's number for web/content/orders_pos.page.php to show as a short-lived screen-pop - no
 * missed-call tracking, no call log, just "who is calling right now".
 */
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/mysql_compat.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop.class.php');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
shop_ensure_schema();

$secret = shop_sipgate_secret();
$given = isset($_SERVER['PHP_AUTH_PW']) ? (string)$_SERVER['PHP_AUTH_PW'] : '';
if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
	header('WWW-Authenticate: Basic realm="mySeat"');
	http_response_code(401);
	echo 'Nicht autorisiert';
	exit;
}

$event = isset($_REQUEST['event']) ? (string)$_REQUEST['event'] : '';
$direction = isset($_REQUEST['direction']) ? (string)$_REQUEST['direction'] : '';
$from = isset($_REQUEST['from']) ? (string)$_REQUEST['from'] : '';
if ($event === 'newCall' && $direction === 'in' && $from !== '') {
	shop_record_incoming_call($from);
}
echo 'OK';
