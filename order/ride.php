<?php
/*
 * Zeus Ride: the pixel game on its own page (the game is order/ride.js, the rules and the voucher are web/classes/shop_ride.class.php, the calls are ride_start / ride_finish in order/api.php).
 * Linked from the websites and a QR code. Switched off (Einstellungen > Lieferservice > Zeus Ride) the page tells so; staff who are logged in to the backend see the game anyway (to test it,
 * with ?dev=1 also the test switches of the game).
 */
require __DIR__.'/bootstrap.inc.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$privacy = (!empty($settings['privacyUrl']) && preg_match('#^https?://#i', $settings['privacyUrl'])) ? $settings['privacyUrl'] : '';
$st = shop_ride_state(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
$show = $shop_public && ($st['on'] || $shop_staff);
$dev = $shop_staff && isset($_GET['dev']) && $_GET['dev'] === '1';
$music = is_file(__DIR__.'/ride_music/midnight_drive.m4a') ? 'ride_music/midnight_drive.m4a?v='.@filemtime(__DIR__.'/ride_music/midnight_drive.m4a') : '';   // CC0, see ride_music/LIZENZ.txt
?><!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0d0918"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Zeus Ride &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="ride.css?v=<?php echo @filemtime(__DIR__.'/ride.css'); ?>"/>
	<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="rd<?php echo $show ? '' : ' rd-off'; ?>" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>" data-tiers="<?php echo shop_h(json_encode($st['tiers'])); ?>" data-eligible="<?php echo $st['eligible'] ? '1' : '0'; ?>" data-have="<?php echo shop_h($st['have'] ? json_encode($st['have']) : ''); ?>" data-dev="<?php echo $dev ? '1' : '0'; ?>" data-music="<?php echo shop_h($music); ?>">
<?php if ($show): ?>
	<div id="stage"><canvas id="c" width="180" height="320" role="img" aria-label="Zeus Ride: Du fährst einen weißen Lieferroller mit schwarzem Kasten durch Hildesheim, sammelst goldene Zeus-Münzen mit dem Kopf der Dogge, achtest auf deine Lebensleiste und holst dir Schnitzel und Boosts. Lenken mit einem Finger in die Wunschrichtung, am Rechner mit Pfeiltasten oder A und D."></canvas></div>
	<p class="rd-have" id="rd-have" hidden></p>
	<div class="rd-win" id="rd-win" role="status" hidden></div>
	<noscript><p class="rd-msg">Für das Spiel braucht dein Browser JavaScript.</p></noscript>
<?php else: ?>
	<main class="rd-msg">
		<h1>Zeus Ride</h1>
		<p>Das Spiel gibt es gerade nicht.</p>
	</main>
<?php endif; ?>
	<nav class="rd-bar" aria-label="Weiter">
		<a href="./">Zur Speisekarte</a>
		<?php if ($show && $music !== ''): ?><button type="button" class="rd-mute" id="rd-mute" hidden>Ton aus</button><?php endif; ?>
		<?php if ($privacy): ?><a href="<?php echo shop_h($privacy); ?>">Datenschutz</a><?php endif; ?>
	</nav>
<?php if ($show && $music !== ''): ?>
	<p class="rd-credit">Musik: „Midnight Drive“ von congusbongus (CC0)</p>
<?php endif; ?>
<?php if ($show): ?>
	<script src="ride.js?v=<?php echo @filemtime(__DIR__.'/ride.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
