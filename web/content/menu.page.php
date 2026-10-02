<?php
// Speisekarte (p=10): editor for categories, dishes and option groups of the delivery service. All work is done by web/js/menu_editor.js
// through web/ajax/shop_menu_admin.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = bin2hex(random_bytes(16)); }
?>
<div class="content menu-page" id="menu-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="orders-bar">
		<h3>Speisekarte</h3>
		<div class="orders-filter" role="group" aria-label="Ansicht">
			<button type="button" data-view="dishes" aria-pressed="true">Gerichte</button>
			<button type="button" data-view="groups" aria-pressed="false">Zubehörgruppen</button>
			<button type="button" data-view="coupons" aria-pressed="false">Gutscheine</button>
		</div>
		<span class="orders-spacer"></span>
		<a class="orders-kitchen" href="main_page.php?p=9">Bestellungen</a>
		<a class="orders-kitchen" href="main_page.php?q=10">Lieferservice-Einstellungen</a>
	</div>
	<p class="me-note" id="me-note" role="status" aria-live="polite"></p>
	<div id="me-root" class="me-root"><p class="orders-empty">Die Speisekarte wird geladen ...</p></div>
	<dialog class="orders-dialog" id="me-dlg" aria-labelledby="me-od-title">
		<form method="dialog" id="me-od-form">
			<h3 id="me-od-title"></h3>
			<div id="me-od-body"></div>
			<div class="orders-dlg-actions">
				<button type="button" class="offer-delete" id="me-od-cancel">Abbrechen</button>
				<button type="submit" class="button_dark" id="me-od-confirm" value="confirm">OK</button>
			</div>
		</form>
	</dialog>
</div>
<script src="js/menu_editor.js?v=<?php echo @filemtime(__DIR__.'/../js/menu_editor.js'); ?>"></script>
