<?php
/*
 * The pages behind the links of the newsletter mails (german, or english for a guest who signed up in english):
 *   ?t=<token>  confirm the sign-up (double opt-in). Opening the link only shows a button: mail programs and link previews open links on their
 *               own, and that must not count as a confirmation. The button (a POST with the CSRF token of this browser's session) confirms.
 *   ?m=<token>  manage: switch the topics on or off, or unsubscribe from everything. The personal link proves the mailbox.
 */
require __DIR__.'/bootstrap.inc.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$ct = isset($_REQUEST['t']) ? (string)$_REQUEST['t'] : '';
$mt = isset($_REQUEST['m']) ? (string)$_REQUEST['m'] : '';
$sub = $mt !== '' ? shop_nl_by_token($mt) : ($ct !== '' ? shop_nl_by_confirm($ct) : null);
$en = $sub && $sub['lang'] === 'en';
// "unsubscribe" of the mail program (List-Unsubscribe-Post, one tap): switches the news off, the stamp card reminders stay as they are
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['List-Unsubscribe']) && $_POST['List-Unsubscribe'] === 'One-Click' && $mt !== '' && $sub) {
	shop_nl_set($mt, false, !empty($sub['stamps']));
	header('Content-Type: text/plain; charset=utf-8'); echo 'OK'; exit;
}
function nl_t($de, $en_, $isEn) { return $isEn ? $en_ : $de; }

$view = 'invalid'; $err = ''; $result = null; $csrf = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrf = shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '');
	if (!$csrf) { $err = nl_t('Die Sitzung ist abgelaufen. Bitte öffne den Link noch einmal.', 'The session has expired. Please open the link again.', $en); }
	elseif ($ct !== '' && isset($_POST['do']) && $_POST['do'] === 'confirm') {
		$r = shop_nl_confirm($ct);
		if ($r['ok']) { $view = 'confirmed'; $result = $r; $mt = $r['token']; $sub = shop_nl_by_token($mt); $en = $r['lang'] === 'en'; }
		else { $err = $r['error']; }
	}
	elseif ($mt !== '' && isset($_POST['do']) && ($_POST['do'] === 'save' || $_POST['do'] === 'unsub')) {
		$news = $_POST['do'] === 'save' && !empty($_POST['news']); $stamps = $_POST['do'] === 'save' && !empty($_POST['stamps']);
		$r = shop_nl_set($mt, $news, $stamps);
		if ($r['ok']) { $view = $r['off'] ? 'off' : 'saved'; $result = $r; $sub = shop_nl_by_token($mt); } else { $err = $r['error']; }
	}
}
if ($view === 'invalid' && $err === '') {
	if ($mt !== '' && $sub) { $view = 'manage'; }
	elseif ($ct !== '' && $sub && $sub['confirm_sent_at'] && strtotime($sub['confirm_sent_at']) >= time() - SHOP_NL_CONFIRM_DAYS * 86400) { $view = 'confirm'; }
	elseif ($ct !== '') { $err = nl_t('Dieser Link ist abgelaufen oder wurde schon benutzt. Wenn du Mails von uns möchtest, melde dich beim nächsten Besuch einfach noch einmal an.', 'This link has expired or was already used. If you would like to hear from us, just sign up again on your next visit.', $en); }
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$showStamps = $sub && (!empty($sub['stamps']) || !empty($sub['want_stamps']) || !empty($sub['account_id']));
?>
<!DOCTYPE html>
<html lang="<?php echo $en ? 'en' : 'de'; ?>">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title><?php echo shop_h(nl_t('Newsletter', 'Newsletter', $en)); ?> &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="shop-shell">
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<a class="co-back" href="./"><?php echo shop_h(nl_t('Zur Speisekarte', 'To the menu', $en)); ?></a>
		</div>
	</header>
	<main class="st-wrap">
	<?php if ($view === 'confirm'): ?>
		<h1 class="st-title"><?php echo shop_h(nl_t('Anmeldung bestätigen', 'Confirm your sign-up', $en)); ?></h1>
		<p class="st-lead"><?php
			$labels = array(); if (!empty($sub['want_news'])) { $labels[] = shop_nl_topic_label('news', $en ? 'en' : 'de'); } if (!empty($sub['want_stamps'])) { $labels[] = shop_nl_topic_label('stamps', $en ? 'en' : 'de'); }
			echo shop_h(nl_t('Du bekommst vom '.$brand.': ', 'You will get from '.$brand.': ', $en).implode($en ? ' and ' : ' und ', $labels).'.'); ?></p>
		<p class="st-lead"><?php echo shop_h(nl_t('Tippe auf den Knopf, damit wir wissen, dass diese Adresse dir gehört. Abmelden kannst du dich jederzeit, in jeder Mail steht der Link dazu.', 'Tap the button so that we know this address is yours. You can unsubscribe at any time, every mail has the link.', $en)); ?></p>
		<form method="post" action="newsletter.php" class="st-again">
			<input type="hidden" name="t" value="<?php echo shop_h($ct); ?>"/>
			<input type="hidden" name="do" value="confirm"/>
			<input type="hidden" name="token" value="<?php echo shop_h($_SESSION['shop_token']); ?>"/>
			<button type="submit" class="cart-go"><?php echo shop_h(nl_t('Anmeldung bestätigen', 'Confirm sign-up', $en)); ?></button>
		</form>
		<p class="st-lead"><?php echo shop_h(nl_t('Du hast dich nicht angemeldet? Dann schließe diese Seite einfach, es passiert nichts.', 'You did not sign up? Then just close this page, nothing happens.', $en)); ?></p>
	<?php elseif ($view === 'confirmed'): ?>
		<h1 class="st-title"><?php echo shop_h(nl_t('Danke, du bist angemeldet', 'Thank you, you are signed up', $en)); ?></h1>
		<p class="st-lead"><?php $labels = array(); foreach ($result['topics'] as $t) { $labels[] = shop_nl_topic_label($t, $en ? 'en' : 'de'); } echo shop_h(nl_t('Du bekommst ab jetzt: ', 'From now on you get: ', $en).implode($en ? ' and ' : ' und ', $labels).'.'); ?></p>
		<p class="st-again"><a class="cart-go" href="./"><?php echo shop_h(nl_t('Zur Speisekarte', 'To the menu', $en)); ?></a></p>
		<p class="st-lead"><a href="newsletter.php?m=<?php echo shop_h($mt); ?>"><?php echo shop_h(nl_t('Einstellungen ändern oder abmelden', 'Change settings or unsubscribe', $en)); ?></a></p>
	<?php elseif ($view === 'kept'): ?>
			<h1 class="st-title"><?php echo shop_h(nl_t('Danke, wir bleiben in Kontakt', 'Thank you, we stay in touch', $en)); ?></h1>
			<p class="st-lead"><?php echo shop_h(nl_t('Du bekommst weiter Neuigkeiten vom '.$brand.'. Abmelden kannst du dich jederzeit.', 'You will keep getting news from '.$brand.'. You can unsubscribe at any time.', $en)); ?></p>
			<p class="st-again"><a class="cart-go" href="./"><?php echo shop_h(nl_t('Zur Speisekarte', 'To the menu', $en)); ?></a></p>
			<p class="st-lead"><a href="newsletter.php?m=<?php echo shop_h($mt); ?>"><?php echo shop_h(nl_t('Einstellungen ändern oder abmelden', 'Change settings or unsubscribe', $en)); ?></a></p>
		<?php elseif ($view === 'manage' || $view === 'saved'): ?>
		<h1 class="st-title"><?php echo shop_h(nl_t('Deine Mails vom '.$brand, 'Your mails from '.$brand, $en)); ?></h1>
		<?php if ($view === 'saved'): ?><p class="st-lead" role="status"><?php echo shop_h(nl_t('Gespeichert.', 'Saved.', $en)); ?></p><?php endif; ?>
		<?php if ($err !== ''): ?><p class="st-lead" role="alert"><?php echo shop_h($err); ?></p><?php endif; ?>
		<p class="st-lead"><?php echo shop_h(nl_t('Adresse: ', 'Address: ', $en).$sub['email']); ?></p>
			<?php if ($view === 'manage' && !empty($_GET['keep']) && !empty($sub['news'])): ?>
			<form method="post" action="newsletter.php" class="st-again">
				<input type="hidden" name="m" value="<?php echo shop_h($mt); ?>"/>
				<input type="hidden" name="token" value="<?php echo shop_h($_SESSION['shop_token']); ?>"/>
				<p class="st-lead"><?php echo shop_h(nl_t('Möchtest du weiter Neuigkeiten vom '.$brand.' bekommen?', 'Would you like to keep getting news from '.$brand.'?', $en)); ?></p>
				<button type="submit" name="do" value="keep" class="cart-go"><?php echo shop_h(nl_t('Ja, gerne weiter', 'Yes, please continue', $en)); ?></button>
			</form>
			<?php endif; ?>
		<form method="post" action="newsletter.php" class="st-again">
			<input type="hidden" name="m" value="<?php echo shop_h($mt); ?>"/>
			<input type="hidden" name="token" value="<?php echo shop_h($_SESSION['shop_token']); ?>"/>
			<label class="co-check"><input type="checkbox" name="news" value="1"<?php echo (!empty($sub['news']) && empty($sub['blocked'])) ? ' checked' : ''; ?>/> <?php echo shop_h(nl_t('Neuigkeiten vom Restaurant, höchstens 2 Mal im Monat (Aktionen, neue Gerichte, Öffnungszeiten)', 'News from the restaurant, at most twice a month (offers, new dishes, opening times)', $en)); ?></label>
			<?php if ($showStamps): ?><label class="co-check"><input type="checkbox" name="stamps" value="1"<?php echo (!empty($sub['stamps']) && empty($sub['blocked'])) ? ' checked' : ''; ?>/> <?php echo shop_h(nl_t('Erinnerungen zu meiner Stempelkarte', 'Reminders about my stamp card', $en)); ?></label><?php endif; ?>
			<p class="st-again"><button type="submit" name="do" value="save" class="cart-go"><?php echo shop_h(nl_t('Speichern', 'Save', $en)); ?></button></p>
			<p class="st-lead"><button type="submit" name="do" value="unsub" class="gb-btn ghost"><?php echo shop_h(nl_t('Alles abbestellen', 'Unsubscribe from everything', $en)); ?></button></p>
		</form>
	<?php elseif ($view === 'off'): ?>
		<h1 class="st-title"><?php echo shop_h(nl_t('Du bist abgemeldet', 'You are unsubscribed', $en)); ?></h1>
		<p class="st-lead"><?php echo shop_h(nl_t('Wir schicken dir keine Mails mehr. Dein Stempel- und Bestellstand im Kundenkonto bleibt, wie er ist. Wenn du es dir anders überlegst, kannst du dich im Shop jederzeit wieder anmelden.', 'We will not send you any more mails. Your stamps and orders in your account stay as they are. If you change your mind, you can sign up again in the shop at any time.', $en)); ?></p>
		<p class="st-again"><a class="cart-go" href="./"><?php echo shop_h(nl_t('Zur Speisekarte', 'To the menu', $en)); ?></a></p>
	<?php else: ?>
		<h1 class="st-title"><?php echo shop_h(nl_t('Das hat nicht geklappt', 'That did not work', $en)); ?></h1>
		<p class="st-lead" role="alert"><?php echo shop_h($err !== '' ? $err : nl_t('Dieser Link ist ungültig.', 'This link is not valid.', $en)); ?></p>
		<p class="st-again"><a class="cart-go" href="./"><?php echo shop_h(nl_t('Zur Speisekarte', 'To the menu', $en)); ?></a></p>
	<?php endif; ?>
	</main>
</body>
</html>
