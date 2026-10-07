<?php
/*
 * Daily report as two short slips (80 mm): everything cash and everything online of one day (web/classes/shop_report.class.php). The page shows the slips as they
 * come out of the printer, with the day to choose and the buttons "Auf Bondrucker" (the print agent on the Raspberry Pi, like the order slips) and "Im Browser drucken".
 * Needs a backend login (Reservation-Edit), like the dispatch screen; opened from the dispatch screen and from Bestellungen.
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop_report.class.php');
date_default_timezone_set('Europe/Berlin');

require_once('classes/session_restore.php'); myseat_restore_session(); // a restarted browser or an expired session: back in from the "stay logged in" cookie
if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$date = isset($_GET['date']) ? (string)$_GET['date'] : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { $date = date('Y-m-d'); }
$prev = date('Y-m-d', strtotime($date.' -1 day')); $next = date('Y-m-d', strtotime($date.' +1 day')); $isToday = ($date === date('Y-m-d'));
$data = shop_report_data($date);
$back = isset($_GET['from']) && $_GET['from'] === 'orders' ? array('main_page.php?p=9&date='.$date, 'Bestellungen') : array('disposition.php', 'Disposition');
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Tagesbericht &ndash; <?php echo htmlspecialchars($brand); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<link rel="stylesheet" href="css/kitchen.css?v=<?php echo @filemtime(__DIR__.'/css/kitchen.css'); ?>"/>
	<link rel="stylesheet" href="css/tagesbericht.css?v=<?php echo @filemtime(__DIR__.'/css/tagesbericht.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="kitchen tb-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>" data-date="<?php echo htmlspecialchars($date); ?>">
	<header class="k-top">
		<h1>Tagesbericht</h1>
		<form class="tb-day" method="get" action="tagesbericht.php">
			<?php if (isset($_GET['from']) && $_GET['from'] === 'orders'): ?><input type="hidden" name="from" value="orders"/><?php endif; ?>
			<a class="k-btn" href="tagesbericht.php?date=<?php echo $prev; ?><?php echo isset($_GET['from']) && $_GET['from'] === 'orders' ? '&amp;from=orders' : ''; ?>" aria-label="Vorheriger Tag">&lsaquo;</a>
			<label class="tb-date"><span class="tb-sr">Tag</span><input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" max="<?php echo date('Y-m-d'); ?>" onchange="this.form.submit()"/></label>
			<a class="k-btn<?php echo $date >= date('Y-m-d') ? ' is-off' : ''; ?>" href="tagesbericht.php?date=<?php echo $next; ?><?php echo isset($_GET['from']) && $_GET['from'] === 'orders' ? '&amp;from=orders' : ''; ?>" aria-label="Nächster Tag"<?php echo $date >= date('Y-m-d') ? ' aria-disabled="true" tabindex="-1"' : ''; ?>>&rsaquo;</a>
			<?php if (!$isToday): ?><a class="k-btn" href="tagesbericht.php">Heute</a><?php endif; ?>
		</form>
		<span class="tb-msg" id="tb-msg" role="status" aria-live="polite"></span>
		<button type="button" class="k-btn is-gold" id="tb-both">Beide auf Bondrucker</button>
		<a class="k-btn" href="<?php echo htmlspecialchars($back[0]); ?>"><?php echo $back[1]; ?></a>
	</header>
	<main class="tb">
		<section class="tb-col" aria-label="Zettel Bar">
			<article class="tb-slip" id="slip-cash"><?php echo shop_report_html(shop_report_rows('cash', $data)); ?></article>
			<div class="tb-act"><button type="button" class="k-btn is-gold" data-print="cash">Bar auf Bondrucker</button><button type="button" class="k-btn" data-browser="cash">Im Browser drucken</button></div>
		</section>
		<section class="tb-col" aria-label="Zettel Online">
			<article class="tb-slip" id="slip-online"><?php echo shop_report_html(shop_report_rows('online', $data)); ?></article>
			<div class="tb-act"><button type="button" class="k-btn is-gold" data-print="online">Online auf Bondrucker</button><button type="button" class="k-btn" data-browser="online">Im Browser drucken</button></div>
		</section>
	</main>
	<script src="js/tagesbericht.js?v=<?php echo @filemtime(__DIR__.'/js/tagesbericht.js'); ?>"></script>
</body>
</html>
