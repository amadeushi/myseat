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

require_once('classes/session_restore.php'); myseat_restore_session(); // a restarted browser or an expired session: back in from the "stay logged in" cookie
if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$dp_pause = shop_pause_state();
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
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="kitchen" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<header class="k-top">
		<h1>Disposition</h1>
		<div class="k-counts" id="k-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div id="k-tools" class="k-tools"></div>
		<div class="k-pause" id="k-pause" role="group" aria-label="Neue Bestellungen annehmen">
			<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $dp_k => $dp_l): $dp_p = $dp_pause[$dp_k]; ?>
			<div class="k-pause-item<?php echo $dp_p['paused'] ? ' is-paused' : ''; ?>" data-kind="<?php echo $dp_k; ?>">
				<label class="k-pause-switch"><input type="checkbox" role="switch" data-pause-switch<?php echo $dp_p['paused'] ? '' : ' checked'; ?>/><span class="k-pause-track" aria-hidden="true"></span><span><?php echo $dp_l; ?><span class="k-pause-more"> annehmen</span></span></label>
				<select data-pause-for aria-label="Wie lange die <?php echo $dp_l; ?> pausiert werden soll"<?php echo $dp_p['paused'] ? ' hidden' : ''; ?>><option value="15">15 Min</option><option value="30" selected>30 Min</option><option value="60">1 Std</option><option value="120">2 Std</option><option value="0">bis ich sie aufhebe</option></select>
				<span class="k-pause-note" data-pause-note><?php echo $dp_p['paused'] ? 'pausiert'.($dp_p['until'] ? ' bis '.$dp_p['until'] : '') : ''; ?></span>
			</div>
			<?php endforeach; ?>
		</div>
		<a class="k-btn" href="fahrerkarte.php">Fahrerkarte</a>
			<a class="k-btn" href="tagesbericht.php" target="_blank" rel="noopener">Tagesbericht</a>
		<button type="button" class="k-btn" id="k-auto" aria-pressed="false" title="Druckt für jede angenommene Bestellung den Lieferschein, wenn sie angenommen wird. Der Browser druckt ohne Rückfrage, wenn er mit --kiosk-printing gestartet ist">Bon bei Annahme: aus</button>
		<button type="button" class="k-btn" id="k-upload" title="Einen Lieferando-Bon (PDF) von diesem Rechner hochladen und als Bestellung anlegen">Lieferando-PDF</button><input type="file" id="k-upfile" accept="application/pdf,.pdf" multiple hidden/>
		<button type="button" class="k-btn" id="k-full">Vollbild</button>
		<a class="k-btn" href="kitchen_screen.php">Küchenbildschirm</a>
		<a class="k-btn" href="main_page.php?p=9">Bestellungen</a>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<main class="k-board">
		<section class="k-col" data-col="fail" id="sec-fail" aria-labelledby="kc-fail" hidden><h2 id="kc-fail">Fehlgeschlagen <span class="k-n" id="n-fail">0</span></h2><div class="k-list" id="col-fail"></div></section>
		<section class="k-col" data-col="new" id="sec-new" aria-labelledby="kc-new"><h2 id="kc-new">Neu <span class="k-n" id="n-new">0</span></h2><div class="k-list" id="col-new"></div><div class="k-bandnav" id="nav-new" hidden></div><p class="k-overflow" id="of-new" role="status" hidden></p></section>
		<section class="k-col" data-col="work" id="sec-work" aria-labelledby="kc-work"><h2 id="kc-work">In der Küche <span class="k-n" id="n-work">0</span></h2><div class="k-list" id="col-work"></div><p class="k-overflow" id="of-work" role="status" hidden></p></section>
		<section class="k-col" data-col="ready" id="sec-ready" aria-labelledby="kc-ready"><h2 id="kc-ready">Fertig <span class="k-n" id="n-ready">0</span></h2><div class="k-list" id="col-ready"></div><div class="k-bandnav" id="nav-ready" hidden></div><p class="k-overflow" id="of-ready" role="status" hidden></p></section>
		<section class="k-col" data-col="out" id="sec-out" aria-labelledby="kc-out"><h2 id="kc-out">Unterwegs <span class="k-n" id="n-out">0</span></h2><div class="k-list" id="col-out"></div><div class="k-bandnav" id="nav-out" hidden></div></section>
	</main>
	<script src="js/monitor_sound.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_sound.js'); ?>"></script>
	<script src="js/monitor_print.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_print.js'); ?>"></script>
	<script src="js/disposition.js?v=<?php echo @filemtime(__DIR__.'/js/disposition.js'); ?>"></script>
</body>
</html>
