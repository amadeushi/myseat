<?php
/*
 * Checkout: how (delivery or pickup), where, when, who, how to pay, then "Zahlungspflichtig bestellen". The page is a
 * shell; order/checkout.js fills the summary from the cart in the browser and posts the order to api.php (op=create).
 */
require __DIR__.'/bootstrap.inc.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$lang = 'de';
if (!$shop_public || !$shop_accepting) { header('Location: ./'); exit; }
$privacy = (!empty($settings['privacyUrl']) && preg_match('#^https?://#i', $settings['privacyUrl'])) ? $settings['privacyUrl'] : '';
$terms = (!empty($settings['termsLink']) && preg_match('#^https?://#i', $settings['termsLink'])) ? $settings['termsLink'] : '';
$imprint = (!empty($settings['imprintUrl']) && preg_match('#^https?://#i', $settings['imprintUrl'])) ? $settings['imprintUrl'] : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Zur Kasse &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
	<link rel="stylesheet" href="stempel.css?v=<?php echo @filemtime(__DIR__.'/stempel.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
</head>
<body class="shop-shell checkout" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>" data-account="<?php echo shop_acc_enabled() ? '1' : '0'; ?>">
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<a class="co-back" href="./">&larr; Zurück zur Speisekarte</a>
		</div>
	</header>

	<div class="co-layout">
		<form class="co-form" id="co-form" novalidate autocomplete="on">
			<h1>Zur Kasse</h1>
			<div class="co-acc" id="co-acc" role="status" hidden></div>
			<section class="co-quick" id="co-quick" aria-label="Deine Angaben">
				<h2>Deine Angaben</h2>
				<p class="co-quick-wait" id="co-quick-wait">Einen Moment, wir holen deine Angaben ...</p>
				<dl class="co-quick-list" id="co-quick-list"></dl>
				<label class="co-f wide co-quick-note"><span>Anmerkung zur Bestellung (optional)</span><input type="text" id="co-quick-note" maxlength="500"/></label>
				<button type="button" class="co-quick-edit" id="co-quick-edit">Angaben ändern</button>
			</section>

			<p class="co-empty" id="co-empty" hidden>Dein Warenkorb ist leer. <a href="./">Zur Speisekarte</a></p>

			<fieldset class="co-sec" id="co-modesec">
				<legend>Wie möchtest du bestellen?</legend>
				<div class="shop-mode" role="group" aria-label="Lieferart">
					<button type="button" class="mode-btn" data-mode="delivery" aria-pressed="true">Lieferung<small class="mode-sub" id="co-sub-delivery"></small></button>
					<button type="button" class="mode-btn" data-mode="pickup" aria-pressed="false">Abholung<small class="mode-sub" id="co-sub-pickup">bei uns</small></button>
				</div>
				<p class="co-hint" id="co-modehint"></p>
			</fieldset>

			<fieldset class="co-sec" id="sec-address">
				<legend>Lieferadresse</legend>
				<div class="co-grid">
					<label class="co-f wide"><span>Straße und Hausnummer</span><input type="text" name="street" autocomplete="street-address" maxlength="120" required/></label>
					<label class="co-f"><span>PLZ</span><input type="text" name="zip" inputmode="numeric" autocomplete="postal-code" maxlength="10" required/></label>
					<label class="co-f"><span>Ort</span><input type="text" name="city" autocomplete="address-level2" maxlength="80" value="Hildesheim" required/></label>
					<label class="co-f wide"><span>Hinweis zur Adresse (optional)</span><input type="text" name="address_note" maxlength="200" placeholder="Etage, Klingel, Hinterhaus"/></label>
				</div>
				<p class="co-zone" id="co-zone" role="status" aria-live="polite"></p>
				<div class="co-candidates" id="co-candidates" hidden role="status" aria-live="polite"></div>
				<button type="button" class="shop-zone-w3w-toggle" id="co-w3w-toggle" hidden>Liegt es an keiner Straße? what3words-Code eingeben</button>
				<div class="co-grid" id="co-w3w-box" hidden>
					<label class="co-f wide"><span>what3words-Code</span><input type="text" id="co-w3w" maxlength="40" placeholder="///beispiel.wort.code"/></label>
				</div>
			</fieldset>

			<fieldset class="co-sec" id="sec-when">
				<legend>Wann?</legend>
				<div id="co-when" class="co-when"></div>
			</fieldset>

			<fieldset class="co-sec" id="sec-data">
				<legend>Deine Daten</legend>
				<div class="co-grid">
					<label class="co-f wide"><span>Name</span><input type="text" name="name" autocomplete="name" maxlength="120" required/></label>
					<label class="co-f"><span>Telefon</span><input type="tel" name="phone" inputmode="tel" autocomplete="tel" maxlength="40" required/></label>
					<label class="co-f"><span>E-Mail (optional)</span><input type="email" name="email" autocomplete="email" maxlength="160"/></label>
					<label class="co-f wide"><span>Anmerkung zur Bestellung (optional)</span><input type="text" name="note" maxlength="500"/></label>
				</div>
				<label class="co-check"><input type="checkbox" name="remember" value="1"/> Meine Angaben auf diesem Gerät merken, damit es beim nächsten Mal schneller geht</label>
				<div class="co-nl" role="group" aria-label="Mails von uns">
					<label class="co-check"><input type="checkbox" name="nl_news" value="1"/> <span><?php echo shop_h(shop_nl_consent_text('news')); ?></span></label>
					<p class="co-hint" id="co-nl-hint" hidden>Dafür brauchen wir deine E-Mail-Adresse. Du bekommst eine Mail zum Bestätigen, abmelden kannst du dich jederzeit.</p>
				</div>
				<input type="text" name="website" class="co-trap" tabindex="-1" autocomplete="off" aria-hidden="true"/>
			</fieldset>

			<div id="co-stamp" class="co-sec-stamp"></div>
			<div class="co-nl-stamps" id="co-nl-stamps-box" hidden>
				<label class="co-check" id="co-nl-stamps"><input type="checkbox" name="nl_stamps" value="1"/> <span><?php echo shop_h(shop_nl_consent_text('stamps')); ?></span></label>
				<p class="co-hint" id="co-nl-hint-stamps" hidden>Dafür brauchen wir deine E-Mail-Adresse. Du bekommst eine Mail zum Bestätigen, abmelden kannst du dich jederzeit.</p>
			</div>

			<fieldset class="co-sec" id="co-paysec">
				<legend>Bezahlung</legend>
				<p class="co-hint co-askhint" id="co-payhint" hidden>Bitte wähle, wie du bezahlen möchtest.</p>
				<div id="co-pay" class="co-pay"></div>
				<div id="co-tipbox" class="co-tip" hidden>
					<span>Trinkgeld für die Fahrerin oder den Fahrer</span>
					<div class="co-tips" role="group" aria-label="Trinkgeld">
						<button type="button" class="tip-btn" data-pct="0" aria-pressed="true">Keins</button>
						<button type="button" class="tip-btn" data-pct="5" aria-pressed="false">5 %</button>
						<button type="button" class="tip-btn" data-pct="10" aria-pressed="false">10 %</button>
						<button type="button" class="tip-btn" data-pct="15" aria-pressed="false">15 %</button>
						<button type="button" class="tip-btn" data-pct="20" aria-pressed="false">20 %</button>
						<button type="button" class="tip-btn" data-pct="custom" aria-pressed="false">Wunschbetrag</button>
					</div>
					<div class="co-tip-custom" id="co-tip-custom" hidden>
						<label for="co-tip-custom-in">Trinkgeld (frei wählbar)</label>
						<div class="co-tip-custom-row"><input type="text" id="co-tip-custom-in" inputmode="decimal" placeholder="0,00"/><span>€</span></div>
					</div>
				</div>
			</fieldset>
			<p class="co-error" id="co-error" role="alert"></p>
		</form>

		<aside class="co-sum" aria-label="Deine Bestellung">
			<h2>Deine Bestellung</h2>
			<div id="co-lines"></div>
			<div class="co-coupon" id="co-coupon">
				<button type="button" class="co-coupon-toggle" id="co-coupon-toggle" aria-expanded="false" aria-controls="co-coupon-box">Gutscheincode einlösen</button>
				<div class="co-coupon-box" id="co-coupon-box" hidden>
					<label class="co-coupon-label" for="co-coupon-in">Gutscheincode</label>
					<div class="co-coupon-row"><input type="text" id="co-coupon-in" maxlength="40" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="z. B. WILLKOMMEN10"/><button type="button" class="co-coupon-go" id="co-coupon-go">Einlösen</button></div>
				</div>
				<p class="co-coupon-msg" id="co-coupon-msg" role="status" aria-live="polite"></p>
			</div>
			<?php if (shop_flag('gv_pay_on')): ?>
			<div class="co-coupon co-gv" id="co-gv">
				<button type="button" class="co-coupon-toggle" id="co-gv-toggle" aria-expanded="false" aria-controls="co-gv-box">Geschenkgutschein einlösen</button>
				<div class="co-coupon-box" id="co-gv-box" hidden>
					<label class="co-coupon-label" for="co-gv-num">Gutscheinnummer</label>
					<input type="text" id="co-gv-num" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="AMD-XXXX-XXXX" class="co-gv-in"/>
					<label class="co-coupon-label" for="co-gv-code">Sicherheitscode</label>
					<div class="co-coupon-row"><input type="text" id="co-gv-code" maxlength="12" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="6 Zeichen"/><button type="button" class="co-coupon-go" id="co-gv-go">Prüfen</button></div>
				</div>
				<p class="co-coupon-msg" id="co-gv-msg" role="status" aria-live="polite"></p>
			</div>
			<?php endif; ?>
			<div class="cart-sum" id="co-totals"></div>
			<div class="co-progress" id="co-progress">
				<div class="co-progress-track"><div class="co-progress-fill" id="co-progress-fill"></div></div>
				<p class="co-progress-text" id="co-progress-text"></p>
			</div>
			<p class="co-legal">Mit dem Klick auf den Knopf gibst du eine verbindliche Bestellung ab und bist zur Zahlung verpflichtet. Speisen werden für dich frisch zubereitet, ein Widerrufsrecht besteht dafür nicht. <?php if ($terms): ?>Es gelten unsere <a href="<?php echo shop_h($terms); ?>" target="_blank" rel="noopener noreferrer">AGB</a>.<?php endif; ?> <?php if ($privacy): ?>Hinweise zum Datenschutz findest du <a href="<?php echo shop_h($privacy); ?>" target="_blank" rel="noopener noreferrer">hier</a>.<?php endif; ?> <?php if ($imprint): ?><a href="<?php echo shop_h($imprint); ?>" target="_blank" rel="noopener noreferrer">Impressum</a><?php endif; ?></p>
			<div class="co-accwarn" id="co-accwarn" role="alert" hidden></div>
			<p class="co-modeline" id="co-modeline" role="status" hidden></p>
			<button type="submit" form="co-form" class="cart-go" id="co-submit" disabled>Zahlungspflichtig bestellen</button>
			<p class="co-why" id="co-why" role="status" aria-live="polite"></p>
		</aside>
	</div>
	<script src="stempel.js?v=<?php echo @filemtime(__DIR__.'/stempel.js'); ?>"></script>
	<script src="checkout.js?v=<?php echo @filemtime(__DIR__.'/checkout.js'); ?>"></script>
</body>
</html>
