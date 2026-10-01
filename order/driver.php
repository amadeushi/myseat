<?php
/*
 * The driver's own page (order/driver.php?device=<traccar device id>), bookmarked once on his phone - no login,
 * the device id is the credential (mapped to his name in Einstellungen > Lieferservice). Shows the open delivery
 * queue to accept from, then the detail of whichever one he has (address, phone, what to collect, "Zugestellt",
 * "Zurück in den Pool"). GPS itself comes from the Traccar app running in the background (order/driver_gps.php)
 * and keeps reporting even while this page is closed - this page only ever reads/claims orders.
 */
require __DIR__.'/bootstrap.inc.php';
header('Cache-Control: no-store');
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$device = isset($_GET['device']) ? (string)$_GET['device'] : '';
$driver = shop_driver_by_device($device);
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Fahrer &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
</head>
<body class="shop-shell dv" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>" data-device="<?php echo shop_h($device); ?>">
<main class="st-wrap">
<?php if (!$driver): ?>
	<h1 class="st-title">Link ungültig</h1>
	<p class="st-lead">Dieses Gerät ist keinem Fahrer zugeordnet. Bitte vom Betreiber in Einstellungen &gt; Lieferservice eintragen lassen.</p>
<?php else: ?>
	<h1 class="st-title">Hallo <?php echo shop_h($driver['name']); ?></h1>
	<p class="st-lead" id="dv-state">Lädt …</p>
	<div id="dv-root"></div>
<?php endif; ?>
</main>
<script src="driver.js?v=<?php echo @filemtime(__DIR__.'/driver.js'); ?>"></script>
</body>
</html>
