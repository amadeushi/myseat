<?php
/*
 * Kitchen monitor: full-screen board of the open orders in three columns (new, in the kitchen, ready), made to be read from a
 * distance. Needs a backend login (Reservation-Edit). The page loads the board every few seconds from ajax/shop_orders.php.
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
	<title>Disposition &ndash; <?php echo htmlspecialchars($brand); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<link rel="stylesheet" href="css/kitchen.css?v=<?php echo @filemtime(__DIR__.'/css/kitchen.css'); ?>"/>
</head>
<body class="kitchen" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<header class="k-top">
		<h1>Disposition</h1>
		<div class="k-counts" id="k-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div id="k-tools" class="k-tools"></div>
		<button type="button" class="k-btn" id="k-full">Vollbild</button>
		<a class="k-btn" href="kitchen_screen.php">Küchenbildschirm</a>
		<a class="k-btn" href="main_page.php?p=9">Bestellungen</a>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<main class="k-board">
		<section class="k-col" data-col="new" aria-labelledby="kc-new"><h2 id="kc-new">Neu <span class="k-n" id="n-new">0</span></h2><div class="k-list" id="col-new"></div></section>
		<section class="k-col" data-col="work" aria-labelledby="kc-work"><h2 id="kc-work">In der Küche <span class="k-n" id="n-work">0</span></h2><div class="k-list" id="col-work"></div></section>
		<section class="k-col" data-col="ready" aria-labelledby="kc-ready"><h2 id="kc-ready">Fertig <span class="k-n" id="n-ready">0</span></h2><div class="k-list" id="col-ready"></div></section>
	</main>
	<script src="js/monitor_sound.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_sound.js'); ?>"></script>
	<script src="js/monitor_print.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_print.js'); ?>"></script>
	<script src="js/disposition.js?v=<?php echo @filemtime(__DIR__.'/js/disposition.js'); ?>"></script>
</body>
</html>
