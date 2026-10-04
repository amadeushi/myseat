<?php
// Bestellung erfassen (p=12): the till for the staff - a delivery or pickup order that did not come through the own online shop (phone call, or an
// order from a delivery portal mySeat isn't technically connected to). Built for the person who takes a call: the menu on the left, the "Bon" on the
// right that follows the talk (Wer, Was, Wohin, Wann, Zahlung), the sum and the button always in sight. Builds on the same menu/pricing/zone logic as the
// guest shop (web/classes/shop.class.php), but skips every guest-only guard (shop "closed", minimum order, online payment, rate limit) - the staff already
// has a confirmed order to enter; closed hours, pause and the minimum only show as hints. All work is done by web/js/orders_pos.js through web/ajax/shop_pos.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
$kx_pct = max(1, min(50, (int)shop_setting('pos_discount_pct')));
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
?>
<link rel="stylesheet" href="../order/shop.css?v=<?php echo @filemtime(__DIR__.'/../../order/shop.css'); ?>"/>
<link rel="stylesheet" href="css/kasse.css?v=<?php echo @filemtime(__DIR__.'/../css/kasse.css'); ?>"/>
<div class="content pos-page kx" id="pos-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="kx-top">
		<h3>Bestellung erfassen</h3>
		<span class="kx-keys" aria-hidden="true"><kbd>F2</kbd> Telefon <kbd>F3</kbd> Suche <kbd>F6</kbd> Rabatt <kbd>F7</kbd> Aufschlag <kbd>Strg</kbd>+<kbd>Enter</kbd> Anlegen</span>
		<span class="orders-spacer"></span>
		<button type="button" class="kx-btn" id="kx-mode" aria-pressed="false">Kassenmodus</button>
		<a class="kx-btn" href="main_page.php?p=9">Zur Bestellungen-Übersicht</a>
	</div>
	<p class="pos-call" id="pos-call" role="status" aria-live="assertive" hidden></p>
	<div class="kx-grid">
		<section class="kx-menu" aria-label="Speisekarte">
			<div class="kx-search">
				<svg class="kx-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M20 20l-4.8-4.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				<input type="search" id="pos-search" placeholder="Gericht tippen, mit Pfeiltasten wählen, Enter legt es in den Bon" aria-label="Speisekarte durchsuchen" autocomplete="off" enterkeyhint="search"/>
				<button type="button" class="kx-search-clear" id="pos-search-clear" aria-label="Suche löschen" hidden>&times;</button>
			</div>
			<div class="kx-cats" id="pos-cats" role="tablist" aria-label="Kategorien"></div>
			<div class="kx-products" id="pos-products"><p class="kx-empty">Speisekarte wird geladen ...</p></div>
			<p class="kx-empty" id="pos-search-empty" hidden>Keine Gerichte gefunden. Versuch es mit einem anderen Begriff.</p>
		</section>
		<aside class="kx-bon" aria-label="Bon">
			<form class="kx-form" id="pos-form" autocomplete="off" novalidate>
				<div class="kx-fixed">
					<div class="kx-done" id="kx-done" role="status" aria-live="polite" hidden></div>
					<div class="kx-alerts" id="kx-alerts" role="status" aria-live="polite"></div>
				</div>
				<div class="kx-scroll" id="kx-scroll">

					<section class="kx-sec" aria-labelledby="kx-h-who">
						<h4 class="kx-h" id="kx-h-who">Wer</h4>
						<div class="kx-seg" role="radiogroup" aria-label="Lieferung oder Abholung">
							<label><input type="radio" name="type" value="delivery" checked/><span>Lieferung</span></label>
							<label><input type="radio" name="type" value="pickup"/><span>Abholung</span></label>
						</div>
						<div class="kx-row2">
							<label class="kx-f">Telefon<input type="text" name="phone" id="pos-phone" inputmode="tel" autocomplete="off"/></label>
							<label class="kx-f">Name<input type="text" name="name" id="pos-name" autocomplete="off"/></label>
						</div>
						<label class="kx-check" id="pos-no-phone-label" hidden><input type="checkbox" name="no_phone" id="pos-no-phone"/> Keine Telefonnummer (Vor-Ort-Abholung)</label>
						<div class="kx-cust" id="kx-cust"></div>
					</section>

					<section class="kx-sec" aria-labelledby="kx-h-what">
						<h4 class="kx-h" id="kx-h-what">Was</h4>
						<div class="kx-lines" id="pos-cart"><p class="kx-empty">Noch nichts im Bon. Gericht tippen oder anklicken.</p></div>
					</section>

					<section class="kx-sec" id="pos-address" aria-labelledby="kx-h-where">
						<h4 class="kx-h" id="kx-h-where">Wohin</h4>
						<label class="kx-f">Straße und Hausnummer<input type="text" name="street" id="pos-street" autocomplete="off"/></label>
						<div class="kx-row2 kx-row-zip">
							<label class="kx-f">PLZ<input type="text" name="zip" inputmode="numeric" autocomplete="off"/></label>
							<label class="kx-f">Ort<input type="text" name="city" autocomplete="off"/></label>
						</div>
						<label class="kx-f">Hinweis zur Adresse<input type="text" name="address_note" placeholder="zum Beispiel 3. OG, Klingel Nowak" autocomplete="off"/></label>
						<div class="kx-zone" id="pos-zone" role="status" aria-live="polite"></div>
					</section>

					<section class="kx-sec" aria-labelledby="kx-h-when">
						<h4 class="kx-h" id="kx-h-when">Wann</h4>
						<div class="kx-quote" id="kx-quote" role="status" aria-live="polite"></div>
						<div class="kx-chips" id="kx-whens" role="group" aria-label="Zeit wählen"></div>
						<div class="kx-custom" id="kx-custom" hidden>
							<label class="kx-f">Tag<select id="kx-day"><option value="0">Heute</option><option value="1">Morgen</option></select></label>
							<label class="kx-f">Uhrzeit<input type="time" id="kx-time" step="300"/></label>
						</div>
					</section>

					<section class="kx-sec" aria-labelledby="kx-h-pay">
						<h4 class="kx-h" id="kx-h-pay">Zahlung</h4>
						<div class="kx-seg" role="radiogroup" aria-label="Zahlart">
							<label><input type="radio" name="payment" value="cash" checked/><span>Bar</span></label>
							<label><input type="radio" name="payment" value="card_door"/><span>Karte (Tür/Abholung)</span></label>
						</div>
						<div class="kx-cash" id="kx-cash">
							<span class="kx-cash-l">Gast zahlt mit</span>
							<div class="kx-chips" id="kx-cash-chips" role="group" aria-label="Betrag"></div>
							<input type="text" id="kx-cash-in" inputmode="decimal" placeholder="anderer Betrag" aria-label="Gast zahlt mit, Betrag in Euro" autocomplete="off"/>
							<p class="kx-change" id="kx-change" aria-live="polite"></p>
						</div>
						<label class="kx-f">Notiz an die Küche<textarea name="note" rows="2"></textarea></label>
					</section>

					<details class="kx-read" id="kx-read"><summary>Zum Vorlesen</summary><p id="kx-read-t"></p></details>
					<details class="kx-recent" id="kx-recent"><summary>Zuletzt erfasst</summary><div id="kx-recent-l"></div></details>
				</div>
				<footer class="kx-foot">
					<p class="kx-foot-say" id="kx-foot-say" aria-live="polite"></p>
					<dl class="kx-sum" id="kx-sum"></dl>
					<div class="kx-adj" role="group" aria-label="Preis anpassen">
						<button type="button" class="kx-adjbtn" id="kx-disc" data-pct="<?php echo (int)$kx_pct; ?>" aria-pressed="false">&minus;<?php echo (int)$kx_pct; ?> % Rabatt</button>
						<button type="button" class="kx-adjbtn" id="kx-sur" aria-pressed="false" aria-expanded="false" aria-controls="kx-sur-box">+ Aufschlag</button>
					</div>
					<div class="kx-adjbox" id="kx-disc-box" hidden><div class="kx-chips" id="kx-disc-why" role="group" aria-label="Grund des Rabatts"></div></div>
					<div class="kx-adjbox" id="kx-sur-box" hidden>
						<div class="kx-adjrow"><div class="kx-chips" id="kx-sur-amts" role="group" aria-label="Aufschlag in Euro"></div><input type="text" id="kx-sur-in" inputmode="decimal" placeholder="anderer Betrag" aria-label="Aufschlag, Betrag in Euro, bis 50" autocomplete="off"/></div>
						<div class="kx-adjrow"><div class="kx-chips" id="kx-sur-why" role="group" aria-label="Grund des Aufschlags"></div></div>
					</div>
					<button type="submit" class="kx-go" id="kx-go"><span id="kx-go-l">Bestellung anlegen</span><span id="kx-go-t"></span></button>
					<p class="kx-msg" id="pos-msg" role="status" aria-live="polite"></p>
				</footer>
			</form>
		</aside>
	</div>
	<dialog class="shop-dialog" id="pos-dlg" aria-labelledby="pd-title"></dialog>
</div>
<script src="js/orders_pos.js?v=<?php echo @filemtime(__DIR__.'/../js/orders_pos.js'); ?>"></script>
