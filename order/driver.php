<?php
/*
 * The driver's own page (order/driver.php?device=<traccar device id>), bookmarked once on his phone - no login,
 * the device id is the credential (mapped to his name in Einstellungen > Lieferservice). Shows the open delivery
 * pool to accept from, his own queue of accepted-but-not-yet-started deliveries, and the detail of whichever one
 * he has started ("Zugestellt", "Pausieren", "Zurück in den Pool", "Fehlgeschlagen"). Only one delivery can ever
 * be started at a time, so at most one guest watches his live position. GPS itself comes from the Traccar app
 * running in the background (order/driver_gps.php) and keeps reporting even while this page is closed - this
 * page only ever reads/claims/starts orders. Every delivery shows its district and the way from the restaurant
 * (see shop_driver_state()), for drivers who do not know the town.
 */
require __DIR__.'/bootstrap.inc.php';
header('Cache-Control: no-store');
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$device = isset($_GET['device']) ? (string)$_GET['device'] : '';
$driver = shop_driver_by_device($device);
$phone = !empty($settings['mailPhone']) ? $settings['mailPhone'] : '';
$telHref = $phone !== '' ? 'tel:'.shop_h(preg_replace('/\s+/', '', $phone)) : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Fahrer &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="vendor/leaflet/leaflet.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
</head>
<body class="shop-shell dv" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>" data-device="<?php echo shop_h($device); ?>">
<?php if (!$driver): ?>
<main class="st-wrap">
	<h1 class="st-title">Link ungültig</h1>
	<p class="st-lead">Dieses Gerät ist keinem Fahrer zugeordnet. Bitte vom Betreiber in Einstellungen &gt; Lieferservice eintragen lassen<?php echo $telHref !== '' ? ' oder uns kurz anrufen' : ''; ?>.</p>
	<?php if ($telHref !== ''): ?><p class="st-again"><a class="cart-go" href="<?php echo $telHref; ?>">Anrufen: <?php echo shop_h($phone); ?></a></p><?php endif; ?>
</main>
<?php else: ?>
<header class="dv-bar">
	<h1 class="dv-who"><?php echo shop_h($driver['name']); ?></h1>
	<p class="dv-gps" id="dv-gps" role="status" aria-live="polite"><span class="dv-dot" aria-hidden="true"></span><span id="dv-gps-text">Standort &hellip;</span></p>
	<button type="button" class="dv-icon-btn" id="dv-sound" aria-pressed="false" aria-label="Ton bei neuen Lieferungen" title="Ton bei neuen Lieferungen"></button>
</header>
<main class="st-wrap">
	<p class="dv-state" id="dv-state" role="status">Lädt &hellip;</p>
	<p class="dv-banner" id="dv-banner" role="alert" hidden></p>
	<div id="dv-root"></div>
</main>
<div class="dv-toast" id="dv-toast" role="status" aria-live="polite" hidden></div>
<script src="vendor/leaflet/leaflet.js"></script>
<?php endif; ?>
<script src="driver.js?v=<?php echo @filemtime(__DIR__.'/driver.js'); ?>"></script>
</body>
</html>
