<?php
/*
 * The order page for guests: menu by category, cart, product dialog. Delivery or pickup is chosen at the top; the
 * cart lives in the browser until the guest goes to the checkout (checkout.php).
 */
require __DIR__.'/bootstrap.inc.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$lang = 'de';
if (!$shop_public) {
	http_response_code(503);
	header('Retry-After: 3600');
}
$menu = $shop_public ? shop_menu() : array();
// pizza configurator: its stylesheet and script only travel when a dish has it switched on
$has_conf = false;
foreach ($menu as $c0) { foreach ($c0['products'] as $p0) { if (!empty($p0['configurator'])) { $has_conf = true; } } }
$notice = (string)shop_setting('notice');
$acc_on = $shop_public && shop_acc_enabled(); // guest account: sign-in, order history, favorites, stamp card (konto.js)
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Bestellen &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
	<?php if ($has_conf): ?><link rel="stylesheet" href="pizza.css?v=<?php echo @filemtime(__DIR__.'/pizza.css'); ?>"/><?php endif; ?>
	<?php if ($acc_on): ?><link rel="stylesheet" href="stempel.css?v=<?php echo @filemtime(__DIR__.'/stempel.css'); ?>"/><link rel="stylesheet" href="konto.css?v=<?php echo @filemtime(__DIR__.'/konto.css'); ?>"/><?php endif; ?>
</head>
<body class="shop-shell" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>" data-accepting="<?php echo $shop_accepting ? '1' : '0'; ?>" data-account="<?php echo $acc_on ? '1' : '0'; ?>" data-stamp="<?php $sc = shop_stamp_cfg(); echo ($acc_on && $sc['on']) ? (int)$sc['percent'] : 0; ?>">
<?php if (!$shop_public): ?>
	<main class="shop-soon">
		<?php echo brand_logo_html($brand, 'brand-logo'); ?>
		<h1>Bestellen kommt bald</h1>
		<p>Unser Lieferservice startet in Kürze. Bis dahin erreichst du uns telefonisch.</p>
	</main>
<?php else: ?>
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<div class="shop-mode" role="group" aria-label="Lieferart">
				<button type="button" class="mode-btn" data-mode="delivery" aria-pressed="true">Lieferung</button>
				<button type="button" class="mode-btn" data-mode="pickup" aria-pressed="false">Abholung</button>
			</div>
			<p class="shop-status" id="shop-status" role="status" aria-live="polite"></p>
			<?php if ($shop_accepting): ?><button type="button" class="shop-group-btn" id="gb-top" data-gb-new hidden>Gemeinsam bestellen</button><?php endif; ?>
			<?php if ($acc_on): ?><button type="button" class="acc-btn" id="acc-btn" aria-haspopup="dialog"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="8.500" r="3.800" fill="none" stroke="currentColor" stroke-width="1.700"/><path d="M4.500 20c.7-3.700 3.700-5.700 7.500-5.700s6.800 2 7.500 5.700" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round"/></svg><span id="acc-btn-label">Anmelden</span></button><?php endif; ?>
			<div class="shop-zone" id="shop-zone">
				<button type="button" class="shop-zone-toggle" id="shop-zone-toggle" aria-expanded="false" aria-controls="shop-zone-box">Liefert ihr zu mir?</button>
				<p class="shop-zone-result" id="shop-zone-result" role="status" aria-live="polite"></p>
				<div class="shop-zone-box" id="shop-zone-box" hidden>
					<div class="co-grid">
						<label class="co-f wide"><span>Straße und Hausnummer</span><input type="text" id="sz-street" autocomplete="street-address" maxlength="120"/></label>
						<label class="co-f"><span>PLZ</span><input type="text" id="sz-zip" inputmode="numeric" autocomplete="postal-code" maxlength="10"/></label>
						<label class="co-f"><span>Ort</span><input type="text" id="sz-city" autocomplete="address-level2" maxlength="80" value="Hildesheim"/></label>
					</div>
					<div class="co-candidates" id="sz-candidates" hidden role="status" aria-live="polite"></div>
					<button type="button" class="shop-zone-w3w-toggle" id="sz-w3w-toggle" hidden>Liegt es an keiner Straße? what3words-Code eingeben</button>
					<div class="co-grid" id="sz-w3w-box" hidden>
						<label class="co-f wide"><span>what3words-Code</span><input type="text" id="sz-w3w" maxlength="40" placeholder="///beispiel.wort.code"/></label>
					</div>
				</div>
			</div>
		</div>
	</header>
	<div class="shop-zone-backdrop" id="shop-zone-backdrop" hidden></div>
	<?php if ($shop_staff && !shop_flag('public')): ?><p class="shop-notice">Vorschau für Mitarbeiter: Gäste sehen diese Seite nicht. Du siehst sie nur, weil du im Backend angemeldet bist.</p><?php endif; ?>
	<?php if ($notice !== ''): ?><p class="shop-notice"><?php echo shop_h($notice); ?></p><?php endif; ?>
	<?php if (!$shop_accepting): ?><p class="shop-notice">Wir nehmen gerade keine Bestellungen an. Du kannst dich schon in Ruhe umsehen.<?php echo $shop_staff ? ' (Vorschau für Mitarbeiter)' : ''; ?></p><?php endif; ?>

	<?php if ($menu): ?>
	<div class="shop-search-bar">
		<div class="shop-search-in">
			<svg class="shop-search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M20 20l-4.8-4.8" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
			<input type="search" id="shop-search" placeholder="Gericht oder Zutat suchen" aria-label="Speisekarte durchsuchen" autocomplete="off" enterkeyhint="search"/>
			<button type="button" class="shop-search-clear" id="shop-search-clear" aria-label="Suche löschen" hidden>&times;</button>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($acc_on): ?><section class="acc-favs" id="acc-favs" aria-label="Deine Favoriten" hidden></section><?php endif; ?>

	<div class="shop-layout">
		<nav class="shop-cats" id="shop-cats" aria-label="Kategorien">
			<?php foreach ($menu as $c): ?><a href="#cat-<?php echo (int)$c['id']; ?>" data-cat="<?php echo (int)$c['id']; ?>"><?php echo shop_h(trim($c['name'])); ?></a><?php endforeach; ?>
		</nav>

		<main class="shop-menu" id="shop-menu">
			<?php if (!$menu): ?>
				<p class="shop-empty">Die Speisekarte ist noch leer.</p>
			<?php endif; ?>
			<?php foreach ($menu as $c): ?>
			<section class="shop-cat" id="cat-<?php echo (int)$c['id']; ?>" aria-labelledby="h-<?php echo (int)$c['id']; ?>">
				<h2 id="h-<?php echo (int)$c['id']; ?>"><?php echo shop_h(trim($c['name'])); ?></h2>
				<?php if (trim($c['description']) !== ''): ?><p class="shop-cat-desc"><?php echo shop_h($c['description']); ?></p><?php endif; ?>
				<ul class="shop-list">
					<?php foreach ($c['products'] as $p):
						$choices = ((int)$p['nvar'] > 0 || (int)$p['nmod'] > 0);
						$from = ((int)$p['nvar'] > 0) ? min((int)$p['price_cents'], (int)$p['vmin']) : (int)$p['price_cents'];
					?>
					<li class="shop-item" data-id="<?php echo (int)$p['id']; ?>" data-choices="<?php echo $choices ? '1' : '0'; ?>" data-title="<?php echo shop_h($p['title']); ?>" data-price="<?php echo (int)$p['price_cents']; ?>">
						<div class="shop-item-text">
							<h3><?php echo shop_h($p['title']); ?></h3>
							<?php if (trim($p['description']) !== ''): ?><p><?php echo shop_h($p['description']); ?></p><?php endif; ?>
						</div>
						<?php if ($p['image_url'] !== ''): ?><img class="shop-item-img" src="<?php echo shop_h($p['image_url']); ?>" alt="" loading="lazy" width="80" height="80"/><?php endif; ?>
						<div class="shop-item-buy">
							<span class="shop-price"><?php echo ($choices && (int)$p['nvar'] > 0) ? 'ab ' : ''; ?><?php echo shop_money($from); ?></span>
							<?php $conf = !empty($p['configurator']); ?>
							<?php if ($shop_accepting): ?><button type="button" class="shop-add" aria-label="<?php echo shop_h($p['title']); ?> <?php echo $conf ? 'selbst belegen' : ($choices ? 'auswählen' : 'hinzufügen'); ?>"><?php echo $conf ? 'Belegen' : ($choices ? 'Wählen' : '+'); ?></button><?php endif; ?>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endforeach; ?>
			<p class="shop-empty" id="shop-search-empty" hidden>Keine Gerichte gefunden. Versuch es mit einem anderen Begriff.</p>
			<p class="shop-legal">Alle Preise in Euro inklusive gesetzlicher Umsatzsteuer. Angaben zu Allergenen und Zusatzstoffen findest du bei jedem Gericht, bei Fragen sagen wir dir gern Bescheid.</p>
			<?php include __DIR__.'/../api/legal_footer.php'; ?>
		</main>

		<?php if ($shop_accepting): ?>
		<aside class="shop-cart" id="shop-cart" aria-label="Warenkorb">
			<div class="cart-head">
				<h2>Dein Warenkorb</h2>
				<button type="button" class="cart-close" id="cart-close" aria-label="Warenkorb schließen">&times;</button>
			</div>
			<div id="cart-body"></div>
		</aside>
		<?php endif; ?>
	</div>

	<?php if ($shop_accepting): ?>
	<button type="button" class="shop-cartbar" id="cartbar" hidden>
		<span class="cartbar-count" id="cartbar-count">0</span>
		<span class="cartbar-label">Warenkorb ansehen</span>
		<span class="cartbar-total" id="cartbar-total"></span>
	</button>
	<dialog class="shop-dialog" id="product-dialog" aria-labelledby="pd-title"></dialog>
	<dialog class="gb-dialog" id="group-dialog" aria-label="Gemeinsam bestellen"></dialog>
	<?php if ($has_conf): ?><dialog class="pz" id="pizza-dialog" aria-labelledby="pz-title"></dialog><?php endif; ?>
	<?php endif; ?>
	<?php if ($acc_on): ?><dialog class="gb-dialog acc-dialog" id="acc-dialog" aria-labelledby="acc-title"></dialog><?php endif; ?>
	<div class="shop-toast" id="shop-toast" role="status" aria-live="polite"></div>
	<?php if ($has_conf): ?><script src="pizza.js?v=<?php echo @filemtime(__DIR__.'/pizza.js'); ?>"></script><?php endif; ?>
	<script src="shop.js?v=<?php echo @filemtime(__DIR__.'/shop.js'); ?>"></script>
	<?php if ($acc_on): ?><script src="stempel.js?v=<?php echo @filemtime(__DIR__.'/stempel.js'); ?>"></script><script src="konto.js?v=<?php echo @filemtime(__DIR__.'/konto.js'); ?>"></script><?php endif; ?>
<?php endif; ?>
</body>
</html>
