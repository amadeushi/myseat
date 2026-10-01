<?php
// Bestellung erfassen (p=12): POS for the till staff - a delivery or pickup order that did not come through the
// own online shop (phone call, or an order from a delivery portal mySeat isn't technically connected to). Builds
// on the same menu/pricing/zone logic as the guest shop (web/classes/shop.class.php), but skips every guest-only
// guard (shop "closed", minimum order, time slots, coupon, online payment, rate limit) - the staff already has a
// confirmed order to enter. All work is done by web/js/orders_pos.js through web/ajax/shop_pos.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = bin2hex(random_bytes(16)); }
?>
<link rel="stylesheet" href="../order/shop.css?v=<?php echo @filemtime(__DIR__.'/../../order/shop.css'); ?>"/>
<div class="content pos-page" id="pos-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="orders-bar">
		<h3>Bestellung erfassen</h3>
		<span class="orders-spacer"></span>
		<a class="orders-kitchen" href="main_page.php?p=9">Zur Bestellungen-Übersicht</a>
	</div>
	<p class="pos-call" id="pos-call" role="status" aria-live="assertive" hidden></p>
	<div class="pos-layout">
		<div class="pos-main">
			<div class="shop-search-bar">
				<div class="shop-search-in">
					<svg class="shop-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M20 20l-4.8-4.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
					<input type="search" id="pos-search" placeholder="Gericht suchen" aria-label="Speisekarte durchsuchen" autocomplete="off" enterkeyhint="search"/>
					<button type="button" class="shop-search-clear" id="pos-search-clear" aria-label="Suche löschen" hidden>&times;</button>
				</div>
			</div>
			<div class="pos-cats" id="pos-cats"></div>
			<div class="pos-products" id="pos-products"><p class="orders-empty">Speisekarte wird geladen ...</p></div>
			<p class="shop-empty" id="pos-search-empty" hidden>Keine Gerichte gefunden. Versuch es mit einem anderen Begriff.</p>
		</div>
		<div class="pos-side">
			<div class="pos-cart" id="pos-cart"><p class="orders-empty">Noch nichts im Warenkorb.</p></div>
			<form class="pos-form" id="pos-form" autocomplete="off">
				<label>Telefon<input type="text" name="phone" id="pos-phone" required/></label>
				<label class="pos-no-phone" id="pos-no-phone-label" hidden><input type="checkbox" name="no_phone" id="pos-no-phone"/> Keine Telefonnummer (Vor-Ort-Abholung)</label>
				<div class="pos-history" id="pos-history"></div>
				<label>Name<input type="text" name="name" required/></label>
				<div class="pos-type" role="radiogroup" aria-label="Lieferung oder Abholung">
					<label><input type="radio" name="type" value="delivery" checked/> Lieferung</label>
					<label><input type="radio" name="type" value="pickup"/> Abholung</label>
				</div>
				<div id="pos-address">
					<label>Straße + Hausnummer<input type="text" name="street"/></label>
					<label>PLZ<input type="text" name="zip"/></label>
					<label>Ort<input type="text" name="city"/></label>
					<label>Hinweis zur Adresse<input type="text" name="address_note"/></label>
					<p class="pos-zone" id="pos-zone" role="status" aria-live="polite"></p>
				</div>
				<label>Zahlart
					<select name="payment">
						<option value="cash">Bar</option>
						<option value="card_door">Karte (Tür/Abholung)</option>
					</select>
				</label>
				<label>Notiz an die Küche<textarea name="note" rows="2"></textarea></label>
				<button type="submit" class="button_dark pos-submit">Bestellung anlegen</button>
				<p class="detail-status" id="pos-msg" role="status" aria-live="polite"></p>
			</form>
		</div>
	</div>
	<dialog class="shop-dialog" id="pos-dlg" aria-labelledby="pd-title"></dialog>
</div>
<script src="js/orders_pos.js?v=<?php echo @filemtime(__DIR__.'/../js/orders_pos.js'); ?>"></script>
