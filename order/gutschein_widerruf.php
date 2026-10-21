<?php
/*
 * "Vertrag widerrufen" (§ 356a BGB): the withdrawal function for voucher orders, always reachable from the footer of the voucher pages. Two steps: the guest names the contract (order number + e-mail,
 * or comes from the status page with the token), checks it, and confirms with "Widerruf bestätigen". The receipt is shown at once and mailed (gv_order_withdraw). Wrong pairs are counted like
 * wrong voucher codes, so the page cannot be used to find out who ordered.
 */
require __DIR__.'/gutschein.inc.php';
$h = 'shop_h';
$step = 1; $o = null; $err = ''; $done = null;
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$tok = isset($_REQUEST['t']) ? (string)$_REQUEST['t'] : '';
if ($tok !== '') { $o = gv_order_by_token($tok); if ($o) { $step = 2; } else { $err = 'Dieser Link passt zu keiner Bestellung.'; } }
if ($post) {
	if (!shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '')) { $err = 'Die Sitzung ist abgelaufen. Bitte versuche es noch einmal.'; $step = 1; $o = null; }
	elseif (isset($_POST['do']) && $_POST['do'] === 'check') {
		$o = null; $step = 1;
		if (gv_tries_left() < 1) { $err = 'Zu viele Versuche. Bitte warte kurz und versuche es später noch einmal.'; }
		else {
			$o = gv_order_by_number(isset($_POST['number']) ? $_POST['number'] : '', isset($_POST['email']) ? $_POST['email'] : '');
			if ($o) { $step = 2; }
			else { fb_exec("INSERT INTO ".fb_t('tp_gv_tries')." (ip, at) VALUES (?, ?)", 'ss', array(gv_ip(), gv_now())); $err = 'Zu diesen Angaben finden wir keine Bestellung. Bitte prüfe Bestellnummer und E-Mail-Adresse (beides steht in deiner Bestätigungsmail).'; }
		}
	} elseif (isset($_POST['do']) && $_POST['do'] === 'confirm' && $o) {
		$r = gv_order_withdraw($o);
		if ($r['ok']) { $done = $r['order']; $step = 3; } else { $err = $r['error']; $step = 2; }
	}
}
gv_page_head('Vertrag widerrufen');
?>
	<main class="gv-page">
		<h1>Vertrag widerrufen</h1>
	<?php if ($step === 3): ?>
		<p class="gv-stateline is-ok" role="status">Dein Widerruf ist am <?php echo $h(date('d.m.Y', strtotime($done['withdrawn_at']))); ?> um <?php echo $h(date('H:i', strtotime($done['withdrawn_at']))); ?> Uhr bei uns eingegangen.</p>
		<p>Das ist deine Eingangsbestätigung. Wir schicken sie dir zusätzlich an <strong><?php echo $h($done['buyer_email']); ?></strong>. Der Gutschein <?php echo $h($done['number']); ?> ist gesperrt. Wir erstatten <?php echo $h(gv_money((int)$done['total_cents'])); ?> auf dem Zahlungsweg deiner Bestellung, in der Regel innerhalb von 14 Tagen.</p>
		<p><a class="cart-go is-quiet" href="./">Zur Speisekarte</a></p>
	<?php elseif ($step === 2 && $o): list($st, $text) = gv_order_withdraw_state($o); ?>
		<p>Schritt 2 von 2: Prüfe, welchen Vertrag du widerrufen möchtest.</p>
		<?php if ($err !== ''): ?><p class="gv-err" role="alert"><?php echo $h($err); ?></p><?php endif; ?>
		<div class="gv-card"><dl>
			<dt>Bestellung</dt><dd><?php echo $h($o['number']); ?> vom <?php echo $h(date('d.m.Y', strtotime($o['created_at']))); ?></dd>
			<dt>Gutschein</dt><dd><?php echo $h(gv_money((int)$o['amount_cents'])); ?> (<?php echo $h(gv_form_label($o['delivery'])); ?>)</dd>
			<dt>Gesamt</dt><dd><?php echo $h(gv_money((int)$o['total_cents'])); ?></dd>
			<dt>Käufer</dt><dd><?php echo $h($o['buyer_name']); ?></dd>
		</dl></div>
		<p class="gv-stateline <?php echo $st === 'open' ? '' : 'is-bad'; ?>"><?php echo $h($text); ?></p>
		<?php if ($st === 'open'): ?>
		<form method="post" action="gutschein_widerruf.php">
			<input type="hidden" name="t" value="<?php echo $h($o['token']); ?>"/><input type="hidden" name="do" value="confirm"/><input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
			<button type="submit" class="cart-go">Widerruf bestätigen</button>
		</form>
		<p><a href="gutschein_widerruf.php">Anderen Vertrag wählen</a></p>
		<?php else: ?><p><a class="cart-go is-quiet" href="./">Zur Speisekarte</a></p><?php endif; ?>
	<?php else: ?>
		<p>Hier kannst du einen Vertrag über einen Gutschein widerrufen, den du bei uns online abgeschlossen hast. Es geht in zwei Schritten: Du nennst den Vertrag, dann bestätigst du den Widerruf.</p>
		<?php if ($err !== ''): ?><p class="gv-err" role="alert"><?php echo $h($err); ?></p><?php endif; ?>
		<form method="post" action="gutschein_widerruf.php">
			<input type="hidden" name="do" value="check"/><input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
			<label class="co-f">Bestellnummer (z. B. G00042)<input type="text" name="number" maxlength="16" autocapitalize="characters" autocomplete="off" required/></label>
			<label class="co-f">E-Mail-Adresse der Bestellung<input type="email" name="email" maxlength="160" autocomplete="email" required/></label>
			<button type="submit" class="cart-go">Weiter</button>
		</form>
	<?php endif; ?>
	</main>
<?php gv_page_foot(); ?>
