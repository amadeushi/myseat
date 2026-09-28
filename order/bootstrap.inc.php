<?php
/*
 * Shared start of the public order pages (/order): session, time zone, configuration, database, the shop and brand
 * classes, and the visibility rule. Staff who are logged in to the backend can always look at the shop (preview).
 */
require_once __DIR__.'/../web/classes/mysql_compat.php';
session_start();
date_default_timezone_set('Europe/Berlin');
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop.class.php');
require_once(__DIR__.'/../web/classes/brand.class.php');
require_once(__DIR__.'/../web/classes/shop_mail.class.php');

shop_ensure_schema();
$shop_staff = !empty($_SESSION['valid_user']);
$shop_public = shop_flag('public') || $shop_staff;
$shop_accepting = shop_flag('accepting');
$shop_test = shop_flag('test_mode');

function shop_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// CSRF token of this visitor's session (checked on every POST of the order pages)
if (empty($_SESSION['shop_token'])) { $_SESSION['shop_token'] = bin2hex(random_bytes(16)); }
function shop_token_ok($t) { return isset($_SESSION['shop_token']) && is_string($t) && hash_equals($_SESSION['shop_token'], $t); }

// "heute um 16:00 Uhr" / "morgen um 16:00 Uhr" / "Mo um 16:00 Uhr"
function shop_when_text($ts) {
	if (!$ts) { return ''; }
	$days = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
	$d = date('Y-m-d', $ts);
	$label = ($d === date('Y-m-d')) ? 'heute' : (($d === date('Y-m-d', time() + 86400)) ? 'morgen' : $days[(int)date('w', $ts)]);
	return $label.' um '.date('H:i', $ts).' Uhr';
}

// public address of this installation, e.g. https://reservierung.amds.at (the order pages sit in /order)
function shop_base_url() {
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
	$dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
	return ($https ? 'https://' : 'http://').preg_replace('/[^A-Za-z0-9.\-:]/', '', $host).$dir;
}
