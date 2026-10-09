<?php
/*
 * Driver map of the dispatch: where every driver is, which deliveries are open, assigned or on their way, who is the best driver to ask for an open
 * one. Made for a monitor that stands upright: the map on top, drivers and deliveries below, the details of the selection slide over the lists.
 * Needs a backend login (Reservation-Edit), like the dispatch screen. The page asks ajax/shop_orders.php (dispatch_map) every few seconds; assigning and
 * taking a delivery back use the same operations as the dispatch screen.
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
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Fahrerkarte &ndash; <?php echo htmlspecialchars($brand); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<link rel="stylesheet" href="css/kitchen.css?v=<?php echo @filemtime(__DIR__.'/css/kitchen.css'); ?>"/>
	<link rel="stylesheet" href="css/fahrerkarte.css?v=<?php echo @filemtime(__DIR__.'/css/fahrerkarte.css'); ?>"/>
	<link rel="stylesheet" href="js/leaflet/leaflet.css"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="kitchen fk-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<header class="k-top">
		<h1>Fahrerkarte</h1>
		<div class="k-counts" id="fk-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div class="fk-filter" id="fk-filter" role="group" aria-label="Aufträge anzeigen">
			<button type="button" class="k-btn" data-filter="all">Alle</button>
			<button type="button" class="k-btn" data-filter="open">Offen</button>
			<button type="button" class="k-btn" data-filter="late">Überfällig</button>
		</div>
		<button type="button" class="k-btn" id="fk-trails" aria-pressed="true">Spuren</button>
		<button type="button" class="k-btn" id="fk-zones" aria-pressed="false">Liefergebiete</button>
		<button type="button" class="k-btn" id="fk-new">Fahrauftrag</button>
		<button type="button" class="k-btn" id="k-full">Vollbild</button>
		<a class="k-btn" href="disposition.php">Disposition</a>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<main class="fk">
		<section class="fk-map" aria-label="Karte">
			<div id="fk-map"></div>
			<div class="fk-mapbar">
				<button type="button" class="k-btn" id="fk-fit">Alles zeigen</button>
				<span class="fk-upd" id="fk-upd" role="status"></span>
			</div>
			<p class="fk-legend">Zahl: Auftrag &middot; Gold: offen &middot; mit Fahrerfarbe: zugeteilt &middot; Grün: unterwegs &middot; Grau: noch in der Küche &middot; Bernstein: überfällig</p>
		</section>
		<section class="fk-side" aria-label="Fahrer und Aufträge">
			<div class="fk-cols">
				<section class="fk-col" aria-labelledby="fk-h-d"><h2 id="fk-h-d">Fahrer <span class="k-n" id="fk-n-d">0</span></h2><div class="fk-list" id="fk-drivers"></div></section>
				<section class="fk-col" aria-labelledby="fk-h-o"><h2 id="fk-h-o">Aufträge <span class="k-n" id="fk-n-o">0</span></h2><div class="fk-list" id="fk-orders"></div></section>
			</div>
			<aside class="fk-detail" id="fk-detail" aria-live="polite" hidden></aside>
		</section>
	</main>
	<dialog class="fk-dialog" id="fk-job" aria-labelledby="fk-job-t">
		<form method="dialog" id="fk-job-form" autocomplete="off">
			<h2 id="fk-job-t">Fahrauftrag anlegen</h2>
			<p class="fk-job-hint">Ein Auftrag nur für die Fahrer: erscheint sofort bei den Fahrern, ohne Küche, ohne Bon und ohne Umsatz.</p>
			<div class="k-retry-grid">
				<label class="k-retry-f wide"><span>Straße und Hausnummer</span><input type="text" name="street" maxlength="160" required/></label>
				<label class="k-retry-f"><span>PLZ</span><input type="text" name="zip" maxlength="10"/></label>
				<label class="k-retry-f"><span>Ort</span><input type="text" name="city" maxlength="80" value="Hildesheim" required/></label>
				<label class="k-retry-f wide"><span>Was wird mitgenommen?</span><textarea name="text" maxlength="400" rows="3" required></textarea></label>
				<label class="k-retry-f"><span>Name oder Bezeichnung</span><input type="text" name="name" maxlength="120" placeholder="Fahrauftrag"/></label>
				<label class="k-retry-f"><span>Telefon (optional)</span><input type="text" name="phone" maxlength="40" inputmode="tel"/></label>
				<label class="k-retry-f"><span>Betrag zum Kassieren in Euro (leer: nichts)</span><input type="text" name="amount" maxlength="10" inputmode="decimal"/></label>
				<label class="k-retry-f"><span>Zahlart</span><select name="payment"><option value="cash">Bar</option><option value="card_door">Karte</option></select></label>
				<label class="k-retry-f wide"><span>Hinweis für den Fahrer</span><input type="text" name="address_note" maxlength="200"/></label>
			</div>
			<p class="k-retry-msg" id="fk-job-msg" role="status"></p>
			<div class="k-assign-foot"><button type="submit" class="k-go" id="fk-job-go" value="go">Auftrag anlegen</button><button type="button" class="k-go secondary" id="fk-job-close">Abbrechen</button></div>
		</form>
	</dialog>
	<div class="fk-toast" id="fk-toast" role="status" hidden></div>
	<script src="js/leaflet/leaflet.js"></script>
	<script src="js/fahrerkarte.js?v=<?php echo @filemtime(__DIR__.'/js/fahrerkarte.js'); ?>"></script>
</body>
</html>
