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
	<a class="skip-link" href="#main">Zum Inhalt springen</a>
	<header class="k-top">
		<h1>Disposition</h1>
		<div class="k-counts" id="k-counts" aria-live="polite"></div>
		<span class="k-clock" id="k-clock"></span>
		<div class="k-pause k-grp" id="k-pause" role="group" aria-label="Neue Bestellungen annehmen">
			<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $dp_k => $dp_l): $dp_p = $dp_pause[$dp_k]; ?>
			<div class="k-pause-item<?php echo $dp_p['paused'] ? ' is-paused' : ''; ?>" data-kind="<?php echo $dp_k; ?>">
				<label class="k-pause-switch"><input type="checkbox" role="switch" data-pause-switch<?php echo $dp_p['paused'] ? '' : ' checked'; ?>/><span class="k-pause-track" aria-hidden="true"></span><span><?php echo $dp_l; ?></span></label>
				<span class="k-pause-note" data-pause-note><?php echo $dp_p['paused'] ? 'bis '.($dp_p['until'] ? $dp_p['until'] : 'Aufhebung') : ''; ?></span>
				<div class="k-pause-pop" data-pause-pop role="group" aria-label="Wie lange die <?php echo $dp_l; ?> pausiert werden soll" hidden>
					<p class="k-pause-q"><?php echo $dp_l; ?> pausieren für:</p>
					<button type="button" class="k-btn" data-pause-min="15">15 Min</button><button type="button" class="k-btn" data-pause-min="30">30 Min</button><button type="button" class="k-btn" data-pause-min="60">1 Std</button><button type="button" class="k-btn" data-pause-min="120">2 Std</button><button type="button" class="k-btn" data-pause-min="0">Bis ich sie aufhebe</button>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		<div class="k-grp k-grp-set">
			<div id="k-tools" class="k-tools"></div>
			<button type="button" class="k-btn k-sq" id="k-auto" aria-pressed="false" aria-label="Bon bei Annahme drucken: aus" data-tip="Bon bei Annahme drucken: aus"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v5a1 1 0 0 1-1 1h-2"/><path d="M7 14h10v6H7z"/><path d="M15.500 11.500h.01" stroke-width="2.400"/></svg></button>
			<button type="button" class="k-btn k-sq" id="k-out" aria-haspopup="dialog" aria-label="Gerichte für heute ausverkauft melden" data-tip="Gerichte heute aus"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round"><circle cx="12" cy="12" r="8.500"/><circle cx="12" cy="12" r="4.500"/><path d="M5.500 18.500l13-13"/></svg><span class="k-out-n" id="k-out-n" hidden></span></button>
			<button type="button" class="k-btn k-sq" id="k-upload" aria-label="Lieferando-PDF hochladen" data-tip="Lieferando-Bon (PDF) hochladen"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M7 9l5-5 5 5M4 20h16"/></svg></button><input type="file" id="k-upfile" accept="application/pdf,.pdf" multiple hidden/>
		</div>
		<div class="k-grp k-grp-nav">
			<a class="k-btn k-sq" href="fahrerkarte.php" aria-label="Fahrerkarte" data-tip="Fahrerkarte (Karte der Fahrer)"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3 6v14l6-2 6 2 6-2V4l-6 2-6-2zM9 4v14M15 6v14"/></svg></a>
			<a class="k-btn k-sq" href="kitchen_screen.php" aria-label="Küchenbildschirm" data-tip="Küchenbildschirm"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18v11H3zM8 20h8M12 16v4"/></svg></a>
			<a class="k-btn k-sq" href="main_page.php?p=9" aria-label="Bestellungen" data-tip="Bestellungen (Liste)"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg></a>
			<a class="k-btn k-sq" href="tagesbericht.php" target="_blank" rel="noopener" aria-label="Tagesbericht (öffnet in neuem Fenster)" data-tip="Tagesbericht"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/></svg></a>
			<button type="button" class="k-btn k-sq" id="k-full" aria-label="Vollbild" data-tip="Vollbild ein/aus"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
		</div>
	</header>
	<p class="k-offline" id="k-offline" role="alert" hidden>Keine Verbindung. Ich versuche es weiter ...</p>
	<div class="k-msgs" id="k-msgs" role="status" aria-live="polite"></div>
	<section class="k-issues" id="k-issues" aria-label="Meldungen der Gäste: etwas fehlt" hidden></section>
	<main class="k-board" id="main" tabindex="-1">
		<section class="k-col" data-col="fail" id="sec-fail" aria-labelledby="kc-fail" hidden><h2 id="kc-fail">Fehlgeschlagen <span class="k-n" id="n-fail">0</span></h2><div class="k-list" id="col-fail"></div></section>
		<section class="k-col" data-col="new" id="sec-new" aria-labelledby="kc-new"><h2 id="kc-new">Neu <span class="k-n" id="n-new">0</span></h2><div class="k-list" id="col-new"></div><div class="k-bandnav" id="nav-new" hidden></div><p class="k-overflow" id="of-new" role="status" hidden></p></section>
		<section class="k-col" data-col="work" id="sec-work" aria-labelledby="kc-work"><h2 id="kc-work">In der Küche <span class="k-n" id="n-work">0</span></h2><div class="k-list" id="col-work"></div><p class="k-overflow" id="of-work" role="status" hidden></p></section>
		<section class="k-col" data-col="ready" id="sec-ready" aria-labelledby="kc-ready"><h2 id="kc-ready">Fertig <span class="k-n" id="n-ready">0</span></h2><div class="k-list" id="col-ready"></div><div class="k-bandnav" id="nav-ready" hidden></div><p class="k-overflow" id="of-ready" role="status" hidden></p></section>
		<section class="k-col" data-col="out" id="sec-out" aria-labelledby="kc-out"><h2 id="kc-out">Unterwegs <span class="k-n" id="n-out">0</span></h2><div class="k-list" id="col-out"></div><div class="k-bandnav" id="nav-out" hidden></div></section>
	</main>
	<dialog class="fk-dialog k-outdlg" id="k-outdlg" aria-labelledby="k-outdlg-t">
		<h2 id="k-outdlg-t">Heute aus</h2>
		<p class="fk-job-hint">Was heute nicht mehr geht. Gäste sehen "heute aus" und können es nicht bestellen, morgen ist alles wieder da.</p>
		<input type="search" id="k-outq" class="k-outq" placeholder="Gericht suchen" aria-label="Gericht suchen" autocomplete="off"/>
		<div class="k-outlist" id="k-outlist" role="group" aria-label="Gerichte"></div>
		<div class="k-outfoot"><button type="button" class="k-go secondary k-icbtn" id="k-outclose" aria-label="Schließen" title="Schließen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round"/></svg></button></div>
	</dialog>
	<script src="js/monitor_sound.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_sound.js'); ?>"></script>
	<script src="js/monitor_print.js?v=<?php echo @filemtime(__DIR__.'/js/monitor_print.js'); ?>"></script>
	<script src="js/disposition.js?v=<?php echo @filemtime(__DIR__.'/js/disposition.js'); ?>"></script>
</body>
</html>
