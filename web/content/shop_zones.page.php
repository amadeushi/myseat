<?php
// Liefergebiete (p=11): draw/edit/delete the delivery-zone polygons of the delivery service. All work is done
// by web/js/shop_zones_editor.js through web/ajax/shop_zones_admin.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = bin2hex(random_bytes(16)); }
?>
<link rel="stylesheet" href="js/leaflet/leaflet.css"/>
<link rel="stylesheet" href="js/leaflet/leaflet.draw.css"/>
<div class="content zones-page" id="zones-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="orders-bar">
		<h3>Liefergebiete</h3>
		<span class="orders-spacer"></span>
		<a class="orders-kitchen" href="main_page.php?q=10">Lieferservice-Einstellungen</a>
	</div>
	<p class="me-note" id="zo-note" role="status" aria-live="polite"></p>
	<div class="zo-overlaps" id="zo-overlaps" hidden role="status" aria-live="polite"></div>
	<div class="zo-layout">
		<div class="zo-map" id="zo-map"></div>
		<div class="zo-side">
			<button type="button" class="button_dark zo-new" id="zo-new">+ Neue Zone zeichnen</button>
			<div id="zo-draft"></div>
			<div class="zo-list" id="zo-list"><p class="orders-empty">Gebiete werden geladen ...</p></div>
		</div>
	</div>
</div>
<script src="js/leaflet/leaflet.js"></script>
<script src="js/leaflet/leaflet.draw.js"></script>
<script src="js/shop_zones_editor.js?v=<?php echo @filemtime(__DIR__.'/../js/shop_zones_editor.js'); ?>"></script>
