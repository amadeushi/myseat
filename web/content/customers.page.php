<?php
// Kunden (p=13): who has ordered, who has an account, a stamp card, a voucher - for the person on the phone and at the till. The customers are not a table of their own, they are
// worked out from the orders, accounts, stamps and vouchers (web/classes/shop_customers.class.php). On the left the list with search and views, on the right the card of one
// customer with what can be done by hand. All work is done by web/js/customers.js through web/ajax/shop_customers.php.
require_once __DIR__.'/../classes/shop_customers.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
$cu_open = preg_replace('/[^a-f0-9]/', '', isset($_GET['c']) ? (string)$_GET['c'] : '');
?>
<link rel="stylesheet" href="css/customers.css?v=<?php echo @filemtime(__DIR__.'/../css/customers.css'); ?>"/>
<div class="content cu" id="cu-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>" data-admin="<?php echo current_user_can('Settings-General') ? '1' : '0'; ?>" data-open="<?php echo htmlspecialchars($cu_open); ?>">
	<div class="cu-top">
		<h3>Kunden</h3>
		<span class="cu-sum" id="cu-sum"></span>
		<span class="orders-spacer"></span>
		<a class="cu-btn" href="main_page.php?p=12">Bestellung erfassen</a>
		<a class="cu-btn" href="main_page.php?p=9">Bestellungen</a>
	</div>
	<div class="cu-grid" id="cu-grid">
		<section class="cu-list" aria-label="Kundenliste">
			<div class="cu-search">
				<svg class="cu-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M20 20l-4.8-4.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				<input type="search" id="cu-q" placeholder="Name, Telefon oder E-Mail" aria-label="Kunden suchen" autocomplete="off" enterkeyhint="search"/>
			</div>
			<div class="cu-views" id="cu-views" role="group" aria-label="Ansicht"></div>
			<div class="cu-rows" id="cu-rows" role="list"></div>
			<button type="button" class="cu-more" id="cu-more" hidden>Weitere Kunden laden</button>
			<p class="cu-foot" id="cu-foot"></p>
		</section>
		<section class="cu-card" id="cu-card" aria-label="Kundenkarte" aria-live="polite">
			<div class="cu-none"><p><b>Kunde wählen</b></p><p>Links suchen oder aus der Liste öffnen. Mit den Pfeiltasten springst du durch die Liste.</p></div>
		</section>
	</div>
</div>
<script src="js/customers.js?v=<?php echo @filemtime(__DIR__.'/../js/customers.js'); ?>"></script>
