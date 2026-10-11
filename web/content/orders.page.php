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
		<div class="orders-icons">
			<a class="orders-kitchen orders-sq" href="main_page.php?p=12" aria-label="Bestellung erfassen" title="Bestellung erfassen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h8l4 4v5M6 3v18h7M14 3v4h4M18 15v6M15 18h6"/></svg></a>
			<a class="orders-kitchen orders-sq" href="disposition.php" target="_blank" rel="noopener" aria-label="Disposition öffnen (neues Fenster)" title="Disposition öffnen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="5" height="12" rx="1"/><rect x="9.500" y="4" width="5" height="16" rx="1"/><rect x="16" y="4" width="5" height="9" rx="1"/></svg></a>
			<a class="orders-kitchen orders-sq" href="kitchen_screen.php" target="_blank" rel="noopener" aria-label="Küchenbildschirm öffnen (neues Fenster)" title="Küchenbildschirm öffnen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M3 5h18v11H3zM8 20h8M12 16v4"/></svg></a>
			<a class="orders-kitchen orders-sq" href="tagesbericht.php?date=<?php echo htmlspecialchars($or_date); ?>&amp;from=orders" target="_blank" rel="noopener" aria-label="Tagesbericht (neues Fenster)" title="Tagesbericht"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 13h6M9 17h6"/></svg></a>
			<a class="orders-kitchen orders-sq" href="preview_link.php" target="_blank" rel="noopener" aria-label="Bestellseite ansehen (neues Fenster)" title="Die Bestellseite so ansehen, wie Gäste sie sehen, auch wenn sie noch nicht freigegeben ist"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.500-7 10-7 10 7 10 7-3.500 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></a>
			<details class="orders-tools">
				<summary aria-label="Werkzeuge" title="Werkzeuge"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/></svg></summary>
				<div class="orders-tools-panel">
					<button type="button" class="orders-kitchen" data-demo="delivery" title="Legt eine Testbestellung zum Ausprobieren von Monitoren und Druck an">Test: Lieferung</button>
					<button type="button" class="orders-kitchen" data-demo="pickup">Test: Abholung</button>
					<?php if (current_user_can('Settings-General')): ?><a class="orders-kitchen" href="main_page.php?p=10">Speisekarte</a><?php endif; ?>
				</div>
			</details>
		</div>
	</div>

	<div class="orders-pause" id="orders-pause" role="group" aria-label="Neue Bestellungen annehmen">
		<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $or_k => $or_lab): $or_p = $or_pause[$or_k]; ?>
		<div class="pause-item<?php echo $or_p['paused'] ? ' is-paused' : ''; ?>" data-kind="<?php echo $or_k; ?>">
			<label class="pause-switch"><input type="checkbox" role="switch" data-pause-switch<?php echo $or_p['paused'] ? '' : ' checked'; ?>/><span class="pause-track" aria-hidden="true"></span><span class="pause-label"><?php echo $or_lab; ?></span></label>
			<span class="pause-note" data-pause-note><?php echo $or_p['paused'] ? 'bis '.($or_p['until'] ? $or_p['until'].' Uhr' : 'du sie aufhebst') : ''; ?></span>
			<div class="pause-pop" data-pause-pop role="group" aria-label="Wie lange die <?php echo $or_lab; ?> pausiert werden soll" hidden>
				<p class="pause-q"><?php echo $or_lab; ?> pausieren für:</p>
				<button type="button" data-pause-min="15">15 Min</button><button type="button" data-pause-min="30">30 Min</button><button type="button" data-pause-min="60">1 Std</button><button type="button" data-pause-min="120">2 Std</button><button type="button" data-pause-min="0">Bis ich sie aufhebe</button>
			</div>
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
				<button type="button" class="offer-delete orders-sq" id="od-cancel" aria-label="Abbrechen" title="Abbrechen"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
				<button type="submit" class="button_dark" id="od-confirm" value="confirm">OK</button>
			</div>
		</form>
	</dialog>
</div>
<script src="js/orders.js?v=<?php echo @filemtime(__DIR__.'/../js/orders.js'); ?>"></script>
