<?php
/* Local cache/proxy for the guest status-page map tiles: each tile is fetched from OpenStreetMap once and then
   served from disk, so a guest's browser only ever talks to our own domain (not OSM, which would otherwise see
   every visitor's IP on every map load). No login/session/DB needed - this is a plain cached image endpoint,
   only ever requested by order/track.js's L.tileLayer. */

$z = isset($_GET['z']) ? (int)$_GET['z'] : -1;
$x = isset($_GET['x']) ? (int)$_GET['x'] : -1;
$y = isset($_GET['y']) ? (int)$_GET['y'] : -1;
$span = $z >= 0 ? (1 << $z) : 0;
if ($z < 0 || $z > 19 || $x < 0 || $y < 0 || $x >= $span || $y >= $span) {
	http_response_code(400);
	exit;
}

$maxAgeDisk = 30 * 24 * 3600; // Kartenbilder ändern sich selten - 30 Tage lokal vorhalten ist unbedenklich
$cacheDir = __DIR__.'/cache/tiles/'.$z.'/'.$x;
$cacheFile = $cacheDir.'/'.$y.'.png';

function tp_serve($file, $maxAge) {
	header('Content-Type: image/png');
	header('Cache-Control: public, max-age='.$maxAge);
	header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
	readfile($file);
	exit;
}

if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $maxAgeDisk) {
	tp_serve($cacheFile, $maxAgeDisk);
}

$ch = curl_init('https://tile.openstreetmap.org/'.$z.'/'.$x.'/'.$y.'.png');
curl_setopt_array($ch, array(
	CURLOPT_RETURNTRANSFER => true,
	CURLOPT_CONNECTTIMEOUT => 5,
	CURLOPT_TIMEOUT => 8,
	CURLOPT_USERAGENT => 'mySeat-Lieferservice/1.0 (Amadeus Hildesheim; reservierung.amds.at; hamun@amds.at)',
));
$data = curl_exec($ch);
$ok = $data !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
curl_close($ch);

if ($ok) {
	if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
	// write to a unique temp file and rename(), so a concurrent request for the same tile can never read a
	// half-written file (that race is what caused the map to show blank/white tiles on a cold cache)
	$tmp = $cacheFile.'.'.getmypid().'.tmp';
	if (@file_put_contents($tmp, $data) !== false) { @rename($tmp, $cacheFile); }
	header('Content-Type: image/png');
	header('Cache-Control: public, max-age='.$maxAgeDisk);
	echo $data;
	exit;
}

// OSM gerade nicht erreichbar: lieber eine veraltete Kachel zeigen als gar keine
if (is_file($cacheFile)) {
	tp_serve($cacheFile, 3600);
}

http_response_code(502);
