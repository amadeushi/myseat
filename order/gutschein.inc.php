<?php
/*
 * Shared start of the voucher pages of the guests (order/gutschein*.php): the shop shell (header, footer with the legal links and the always visible "Vertrag widerrufen" link, § 356a BGB).
 * The rules are in web/classes/shop_voucher*.class.php; everything is behind the settings gv_on (switch) and gv_terms_ok (the owner has checked the wording).
 */
require __DIR__.'/bootstrap.inc.php';
$gv_brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$gv_cfg = gv_cfg();
$gv_url = function ($key) use ($settings) { return (!empty($settings[$key]) && preg_match('#^https?://#i', $settings[$key])) ? $settings[$key] : ''; };
$gv_terms = $gv_url('termsLink'); $gv_privacy = $gv_url('privacyUrl'); $gv_imprint = $gv_url('imprintUrl');

function gv_page_head($title, $bodyClass = '') {
	global $gv_brand;
	header('Cache-Control: no-store');
	header('Referrer-Policy: no-referrer');
	?><!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title><?php echo shop_h($title); ?> &ndash; <?php echo shop_h($gv_brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
	<link rel="stylesheet" href="gutschein.css?v=<?php echo @filemtime(__DIR__.'/gutschein.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="shop-shell <?php echo shop_h($bodyClass); ?>">
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($gv_brand); ?>"><?php echo brand_logo_html($gv_brand, 'brand-logo'); ?></a>
			<a class="co-back" href="./">Zur Speisekarte</a>
		</div>
	</header>
	<?php
}

function gv_page_foot() {
	global $gv_terms, $gv_privacy, $gv_imprint;
	?>
	<footer class="gv-foot">
		<div class="gv-foot-in">
			<a class="gv-withdraw" href="gutschein_widerruf.php">Vertrag widerrufen</a>
			<nav class="gv-foot-links" aria-label="Rechtliches">
				<a href="gutschein_bedingungen.php">Gutschein-Bedingungen</a>
				<?php if ($gv_terms): ?><a href="<?php echo shop_h($gv_terms); ?>">AGB</a><?php endif; ?>
				<?php if ($gv_imprint): ?><a href="<?php echo shop_h($gv_imprint); ?>">Impressum</a><?php endif; ?>
				<?php if ($gv_privacy): ?><a href="<?php echo shop_h($gv_privacy); ?>">Datenschutz</a><?php endif; ?>
			</nav>
		</div>
	</footer>
</body>
</html>
	<?php
}
