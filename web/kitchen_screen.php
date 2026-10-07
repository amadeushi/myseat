<?php
/*
 * Kitchen screen: for the people who cook. One tall column per order (4 or 5 side by side), big readable dishes, no guest data,
 * nothing to reject: an order is finished with one button when it has left the kitchen. Shows the orders the dispatch accepted.
 * Needs a backend login (Reservation-Edit). The dispatch view (accept, reject, deliver) is kitchen.php.
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

require_once('classes/session_restore.php'); myseat_restore_session(); // a restarted browser or an expired session: back in from the "stay logged in" cookie
if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
// the restaurant's logo (white on transparent), dimmed behind the cards; only shown where no card covers it
require_once('classes/brand.class.php');
$logo = brand_logo_url();
$logoCss = preg_match('#^https?://[^\s\'"()\\\\]+$#', $logo) ? ' style="--ks-logo:url(\''.htmlspecialchars($logo).'\')"' : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Küchenbildschirm &ndash; <?php echo htmlspecialchars($brand); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<link rel="stylesheet" href="css/kitchen.css?v=<?php echo @filemtime(__DIR__.'/css/kitchen.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="kitchen ks"<?php echo $logoCss; ?> data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<header class="k-top">
		<h1>Küche</h1>
		<div class="k-counts" id="k-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div id="k-tools" class="k-tools"></div>
		<button type="button" class="k-btn" id="k-cols" aria-label="Anzahl der Spalten">5 Spalten</button>
		<button type="button" class="k-btn" id="k-auto" aria-pressed="false" title="Druckt nach Fertig den Bon: bei Lieferungen den Lieferschein, bei Abholungen den Küchenbon">Bon bei Fertig: aus</button>
		<button type="button" class="k-btn" id="k-done" aria-pressed="true" title="Zeigt die Bestellungen, die die Küche in den letzten 2 Stunden fertig gemeldet hat">Erledigt</button>
		<button type="button" class="k-btn k-sq" id="k-full" aria-label="Vollbild" data-tip="Vollbild ein/aus"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
		<button type="button" class="k-btn k-sq" id="k-reload" aria-label="Neu laden" data-tip="Seite neu laden (wie F5)"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.500-5.800M20 4v5h-5"/></svg></button>
		<a class="k-btn" href="disposition.php">Disposition</a>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<div class="ks-main">
		<main class="ks-board" id="ks-board" aria-live="polite"></main>
		<aside class="ks-done" id="ks-done" aria-label="Erledigte Bestellungen"></aside>
	</div>
	<nav class="ks-nav" id="ks-nav" aria-label="Seiten der Bestellungen" hidden></nav>
	<script src="js/monitor_sound.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_sound.js'); ?>"></script>
	<script src="js/monitor_print.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_print.js'); ?>"></script>
	<script src="js/kitchen_screen.js?v=<?php echo @filemtime(__DIR__.'/js/kitchen_screen.js'); ?>"></script>
</body>
</html>
