<?php
// Bestellungen (p=9): the dashboard of the delivery service: numbers of the day and all orders with the next step as a button
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = bin2hex(random_bytes(16)); }
$or_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
$or_prev = date('Y-m-d', strtotime($or_date.' -1 day'));
$or_next = date('Y-m-d', strtotime($or_date.' +1 day'));
$or_days = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
?>
<div class="content orders-page" id="orders-page" data-date="<?php echo htmlspecialchars($or_date); ?>" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="orders-bar">
		<a class="orders-nav" href="main_page.php?p=9&date=<?php echo $or_prev; ?>" aria-label="Vorheriger Tag">&larr;</a>
		<h3><?php echo $or_days[(int)date('w', strtotime($or_date))].', '.date('d.m.Y', strtotime($or_date)); ?><?php echo $or_date === date('Y-m-d') ? ' (heute)' : ''; ?></h3>
		<a class="orders-nav" href="main_page.php?p=9&date=<?php echo $or_next; ?>" aria-label="Nächster Tag">&rarr;</a>
		<?php if ($or_date !== date('Y-m-d')): ?><a class="orders-nav wide" href="main_page.php?p=9">Heute</a><?php endif; ?>
		<span class="orders-spacer"></span>
		<div class="orders-filter" role="group" aria-label="Filter">
			<button type="button" data-filter="all" aria-pressed="true">Alle</button>
			<button type="button" data-filter="open" aria-pressed="false">Offen</button>
		</div>
		<button type="button" class="orders-kitchen" data-demo="delivery" title="Legt eine Testbestellung zum Ausprobieren von Monitoren und Druck an">Test: Lieferung</button>
		<button type="button" class="orders-kitchen" data-demo="pickup">Test: Abholung</button>
		<?php if (current_user_can('Settings-General')): ?><a class="orders-kitchen" href="main_page.php?p=10">Speisekarte</a><?php endif; ?>
		<a class="orders-kitchen" href="disposition.php" target="_blank" rel="noopener">Disposition öffnen</a>
		<a class="orders-kitchen" href="kitchen_screen.php" target="_blank" rel="noopener">Küchenbildschirm öffnen</a>
	</div>

	<div class="orders-stats" id="orders-stats"></div>
	<div id="orders-list" class="orders-list" aria-live="polite"></div>
	<p class="orders-note" id="orders-note"></p>
</div>
<script src="js/orders.js?v=<?php echo @filemtime(__DIR__.'/../js/orders.js'); ?>"></script>
