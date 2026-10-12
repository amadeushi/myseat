<?php
// Newsletter (p=14): who may get which mails, and the proof of it. The list is filled by the ticks in the shop, the chat, the account and the reservation form (each confirmed by mail);
// all work is done by web/js/newsletter.js through web/ajax/newsletter.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
?>
<div class="content sms-page nl-page" id="nl-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<p class="sh-help">Hier siehst du, wer welche Mails von uns bekommen darf und wie das belegt ist. Aufgenommen wird nur, wer per Mail bestätigt hat (Double-Opt-in). Jede Anmeldung, Bestätigung und Abmeldung steht mit Zeit, Adresse des Besuchers und Quelle im Nachweis.</p>
	<div class="nl-counts" id="nl-counts" role="group" aria-label="Filter"></div>
	<div class="nl-bar">
		<label class="sh-vh" for="nl-q">Suchen</label>
		<input type="search" id="nl-q" placeholder="E-Mail oder Name" autocomplete="off" enterkeyhint="search"/>
		<span class="detail-status" id="nl-msg" role="status" aria-live="polite"></span>
	</div>
	<p class="sh-warn" id="nl-legacy" hidden></p>
	<div class="nl-table-wrap">
		<table class="nl-table" id="nl-table">
			<thead><tr><th scope="col">Abonnent</th><th scope="col">Mails</th><th scope="col">Quelle</th><th scope="col">Stand</th><th scope="col"><span class="sh-vh">Aktionen</span></th></tr></thead>
			<tbody id="nl-rows"></tbody>
		</table>
	</div>
	<p class="sh-help" id="nl-empty" hidden>Noch keine Abonnenten. Gäste melden sich im Bestellablauf, im Chat, im Kundenkonto und bei der Reservierung an und bestätigen per Mail.</p>
</div>
<script src="js/newsletter.js?v=<?php echo @filemtime(__DIR__.'/../js/newsletter.js'); ?>"></script>
