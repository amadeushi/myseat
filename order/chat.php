<?php
/*
 * The order chat for guests: a guided conversation with answer buttons (stage 1). The conversation is held by order/chat_api.php; this page is only the window.
 * Behind the setting "Bestell-Chat"; staff can look at it while it is off (preview).
 */
require __DIR__.'/bootstrap.inc.php';
require_once __DIR__.'/../web/classes/shop_chat.class.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$phone = !empty($settings['mailPhone']) ? $settings['mailPhone'] : '';
$embed = !empty($_GET['embed']);   // inside the chat bubble of the order page (desktop): no logo, the page around it holds the menu
$ok = $shop_public && (shop_chat_on() || $shop_staff);
if (!$ok) { http_response_code(404); }
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Per Chat bestellen &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
	<link rel="stylesheet" href="chat.css?v=<?php echo @filemtime(__DIR__.'/chat.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="shop-shell chat-shell<?php echo $embed ? ' is-embed' : ''; ?>" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>">
<?php if (!$ok): ?>
	<main class="shop-soon">
		<?php echo brand_logo_html($brand, 'brand-logo'); ?>
		<h1>Der Chat ist noch nicht freigegeben</h1>
		<p>Du kannst trotzdem bestellen: <a href="./">zur Bestellseite</a><?php echo $phone !== '' ? ' oder ruf uns an unter <a href="tel:'.shop_h(preg_replace('/\s+/', '', $phone)).'">'.shop_h($phone).'</a>' : ''; ?>.</p>
	</main>
<?php else: ?>
	<header class="chat-top">
		<div class="chat-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<p class="chat-title">Bestellen im Chat</p>
			<nav class="chat-nav" aria-label="Weitere Möglichkeiten">
				<button type="button" class="chat-link" id="chat-reset" aria-label="Neu anfangen"><span class="lbl-long">Neu anfangen</span><span class="lbl-short" aria-hidden="true">Neu</span></button>
				<?php if ($phone !== ''): ?><a class="chat-link" href="tel:<?php echo shop_h(preg_replace('/[^0-9+]/', '', $phone)); ?>">Anrufen</a><?php endif; ?>
				<a class="chat-link" id="chat-menu" href="./" aria-label="Speisekarte"><span class="lbl-long">Speisekarte</span><span class="lbl-short" aria-hidden="true">Karte</span></a>
			</nav>
		</div>
	</header>
	<?php if ($shop_staff && !shop_chat_on()): ?><p class="shop-notice">Vorschau für Mitarbeiter: Der Chat ist ausgeschaltet, Gäste sehen ihn nicht.</p><?php endif; ?>
	<main class="chat-main" id="chat-main">
		<h1 class="sr-only">Bestellen im Chat</h1>
		<div class="chat-log" id="chat-log" role="log" aria-live="polite" aria-relevant="additions" aria-label="Gespräch"></div>
		<div class="chat-wait-slot" id="chat-wait-slot" role="status" aria-live="polite"></div>
		<p class="chat-error" id="chat-error" role="alert" hidden></p>
	</main>
	<div class="chat-dock">
		<button type="button" class="chat-cartbar" id="chat-cart" hidden><span class="chat-cart-label">Warenkorb</span><span class="chat-cart-count" id="chat-cart-count"></span><span class="chat-cart-total" id="chat-cart-total"></span></button>
		<form class="chat-input" id="chat-form" autocomplete="off" hidden>
			<label class="sr-only" for="chat-text" id="chat-text-label">Deine Antwort</label>
			<input type="text" id="chat-text" name="text" maxlength="300" enterkeyhint="send" autocapitalize="sentences"/>
			<button type="submit" class="chat-send">Senden</button>
		</form>
	</div>
	<script src="chat.js?v=<?php echo @filemtime(__DIR__.'/chat.js'); ?>"></script>
<?php endif; ?>
</body>
</html>
