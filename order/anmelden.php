<?php
/*
 * The page behind the link of a sign-in mail or SMS. Opening it only shows a button: mail programs and link previews fetch links on their
 * own, and that must not use up the one-time link. The button (a POST with the CSRF token of this browser's session) signs the guest in.
 * The link works in any browser, also on another device than the one that asked for the code.
 */
require __DIR__.'/bootstrap.inc.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$token = isset($_POST['t']) ? (string)$_POST['t'] : '';
	if (!shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '')) { $error = 'Die Sitzung ist abgelaufen. Bitte öffne den Link noch einmal.'; }
	elseif (!shop_acc_enabled()) { $error = 'Das Kundenkonto ist gerade nicht verfügbar.'; }
	else {
		$r = shop_acc_verify_link($token);
		if ($r['ok']) { header('Location: ./?konto=1'); exit; }
		$error = $r['error'];
	}
}
$valid = $error === '' && shop_acc_enabled() && shop_acc_link_row($token) !== null;
if (!$valid && $error === '') { $error = shop_acc_enabled() ? 'Dieser Link ist abgelaufen oder wurde schon benutzt. Fordere im Shop einfach einen neuen Code an.' : 'Das Kundenkonto ist gerade nicht verfügbar.'; }
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Anmelden &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
</head>
<body class="shop-shell">
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<a class="co-back" href="./">Zur Speisekarte</a>
		</div>
	</header>
	<main class="st-wrap">
	<?php if ($valid): ?>
		<h1 class="st-title">Bei <?php echo shop_h($brand); ?> anmelden</h1>
		<p class="st-lead">Tippe auf den Knopf, dann bist du angemeldet und siehst deine Bestellungen, Lieblingsgerichte und deine Stempelkarte.</p>
		<form method="post" action="anmelden.php" class="st-again">
			<input type="hidden" name="t" value="<?php echo shop_h($token); ?>"/>
			<input type="hidden" name="token" value="<?php echo shop_h($_SESSION['shop_token']); ?>"/>
			<button type="submit" class="cart-go">Jetzt anmelden</button>
		</form>
		<p class="st-lead">Du hast das nicht angefordert? Dann schließe diese Seite einfach, es passiert nichts.</p>
	<?php else: ?>
		<h1 class="st-title">Das hat nicht geklappt</h1>
		<p class="st-lead" role="alert"><?php echo shop_h($error); ?></p>
		<p class="st-again"><a class="cart-go" href="./?konto=1">Neuen Code anfordern</a></p>
	<?php endif; ?>
	</main>
</body>
</html>
