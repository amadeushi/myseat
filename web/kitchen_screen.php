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

if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = bin2hex(random_bytes(16)); }
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
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
</head>
<body class="kitchen ks" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<header class="k-top">
		<h1>Küche</h1>
		<div class="k-counts" id="k-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div id="k-tools" class="k-tools"></div>
		<button type="button" class="k-btn" id="k-cols" aria-label="Anzahl der Spalten">5 Spalten</button>
		<button type="button" class="k-btn" id="k-auto" aria-pressed="false">Bon automatisch: aus</button>
		<button type="button" class="k-btn" id="k-full">Vollbild</button>
		<a class="k-btn" href="disposition.php">Disposition</a>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<main class="ks-board" id="ks-board" aria-live="polite"></main>
	<p class="k-overflow" id="ks-overflow" role="status" hidden></p>
	<script src="js/monitor_sound.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_sound.js'); ?>"></script>
	<script src="js/monitor_print.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_print.js'); ?>"></script>
	<script src="js/kitchen_screen.js?v=<?php echo @filemtime(__DIR__.'/js/kitchen_screen.js'); ?>"></script>
</body>
</html>
