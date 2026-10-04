<?php
// Bestellungen (p=9): the dashboard of the delivery service: numbers of the day and all orders with the next step as a button
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
$or_date = (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) ? $_GET['date'] : date('Y-m-d');
$or_prev = date('Y-m-d', strtotime($or_date.' -1 day'));
$or_next = date('Y-m-d', strtotime($or_date.' +1 day'));
$or_days = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
$or_pause = shop_pause_state();
?>
<div class="content orders-page" id="orders-page" data-date="<?php echo htmlspecialchars($or_date); ?>" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="orders-bar">
		<div class="date-nav">
			<a href="main_page.php?p=9&date=<?php echo $or_prev; ?>" class="navgroup navgroup-prev" aria-label="Vorheriger Tag">
				<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><polyline points="15 5, 8 12, 15 19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
			<a href="main_page.php?p=9&date=<?php echo $or_next; ?>" class="navgroup navgroup-next" aria-label="Nächster Tag">
				<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><polyline points="9 5, 16 12, 9 19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
			<?php if ($or_date !== date('Y-m-d')): ?><a class="today-btn" href="main_page.php?p=9">Heute</a><?php endif; ?>
		</div>
		<h3><?php echo $or_days[(int)date('w', strtotime($or_date))].', '.date('d.m.Y', strtotime($or_date)); ?><?php echo $or_date === date('Y-m-d') ? ' (heute)' : ''; ?></h3>
		<span class="orders-spacer"></span>
		<div class="orders-filter" role="group" aria-label="Filter">
			<button type="button" data-filter="all" aria-pressed="true">Alle</button>
			<button type="button" data-filter="open" aria-pressed="false">Offen</button>
			<button type="button" data-filter="closed" aria-pressed="false">Abgeschlossen</button>
		</div>
		<a class="orders-kitchen" href="main_page.php?p=12">Bestellung erfassen</a>
		<a class="orders-kitchen" href="preview_link.php" target="_blank" rel="noopener" title="Die Bestellseite so ansehen, wie Gäste sie sehen, auch wenn sie noch nicht freigegeben ist">Bestellseite ansehen</a>
		<details class="orders-tools">
			<summary>Werkzeuge</summary>
			<div class="orders-tools-panel">
				<button type="button" class="orders-kitchen" data-demo="delivery" title="Legt eine Testbestellung zum Ausprobieren von Monitoren und Druck an">Test: Lieferung</button>
				<button type="button" class="orders-kitchen" data-demo="pickup">Test: Abholung</button>
				<?php if (current_user_can('Settings-General')): ?><a class="orders-kitchen" href="main_page.php?p=10">Speisekarte</a><?php endif; ?>
				<a class="orders-kitchen" href="disposition.php" target="_blank" rel="noopener">Disposition öffnen</a>
				<a class="orders-kitchen" href="kitchen_screen.php" target="_blank" rel="noopener">Küchenbildschirm öffnen</a>
			</div>
		</details>
	</div>

	<div class="orders-pause" id="orders-pause" role="group" aria-label="Neue Bestellungen annehmen">
		<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $or_k => $or_lab): $or_p = $or_pause[$or_k]; ?>
		<div class="pause-item<?php echo $or_p['paused'] ? ' is-paused' : ''; ?>" data-kind="<?php echo $or_k; ?>">
			<label class="pause-switch"><input type="checkbox" role="switch" data-pause-switch<?php echo $or_p['paused'] ? '' : ' checked'; ?>/><span class="pause-track" aria-hidden="true"></span><span class="pause-label"><?php echo $or_lab; ?> annehmen</span></label>
			<select data-pause-for aria-label="Wie lange die <?php echo $or_lab; ?> pausiert werden soll"<?php echo $or_p['paused'] ? ' hidden' : ''; ?>>
				<option value="15">Pause: 15 Min</option><option value="30" selected>Pause: 30 Min</option><option value="60">Pause: 1 Std</option><option value="120">Pause: 2 Std</option><option value="0">Pause: bis ich sie aufhebe</option>
			</select>
			<span class="pause-note" data-pause-note><?php echo $or_p['paused'] ? 'pausiert'.($or_p['until'] ? ' bis '.$or_p['until'].' Uhr' : ', bis du sie wieder einschaltest') : ''; ?></span>
		</div>
		<?php endforeach; ?>
	</div>

	<div class="orders-stats" id="orders-stats"></div>
	<div id="orders-list" class="orders-list" aria-live="polite"></div>
	<p class="orders-note" id="orders-note" role="status" aria-live="polite"></p>
	<dialog class="orders-dialog" id="orders-dlg" aria-labelledby="od-title">
		<form method="dialog" id="od-form">
			<h3 id="od-title"></h3>
			<div id="od-body"></div>
			<div class="orders-dlg-actions">
				<button type="button" class="offer-delete" id="od-cancel">Abbrechen</button>
				<button type="submit" class="button_dark" id="od-confirm" value="confirm">OK</button>
			</div>
		</form>
	</dialog>
</div>
<script src="js/orders.js?v=<?php echo @filemtime(__DIR__.'/../js/orders.js'); ?>"></script>
