<?php
/*
 * GPS receiver for a driver's phone, in the background, independent of order/driver.php being open: the Traccar
 * app (OsmAnd protocol - a plain GET/POST with id/lat/lon) points here instead of needing its own Traccar server.
 * The device id is the only credential (mapped to a driver in Einstellungen > Lieferservice); an unknown or
 * inactive id is accepted with a plain 200 so the app does not retry-loop, but nothing is stored for it.
 * No session: a machine client sends no cookies, and starting one per ping would only create session litter.
 */
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/mysql_compat.php');
include(__DIR__.'/../web/classes/connect.db.php');
require_once(__DIR__.'/../web/classes/shop.class.php');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
shop_ensure_schema();

$deviceId = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : '';
$lat = isset($_REQUEST['lat']) ? (float)$_REQUEST['lat'] : null;
$lon = isset($_REQUEST['lon']) ? (float)$_REQUEST['lon'] : (isset($_REQUEST['lng']) ? (float)$_REQUEST['lng'] : null);

if ($deviceId !== '' && $lat !== null && $lon !== null && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
	$driver = shop_driver_by_device($deviceId);
	if ($driver) { shop_driver_update_position((int)$driver['id'], $lat, $lon); }
}
echo 'OK';
