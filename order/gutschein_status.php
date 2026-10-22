<?php
/*
 * The page of one voucher order (?t=<token>): where the guest comes back from Mollie and the page behind the button of the mails. Shows the state (waiting for the payment, paid, canceled, withdrawn),
 * the PDF / the state of the printed card, the withdrawal function. A payment that is still open can be started again here.
 */
require __DIR__.'/gutschein.inc.php';
$t = isset($_REQUEST['t']) ? (string)$_REQUEST['t'] : '';
$o = gv_order_by_token($t);
$h = 'shop_h';
if ($o && $_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '') && isset($_POST['do']) && $_POST['do'] === 'pay' && $o['status'] === 'pending') {
	$o = gv_order_sync($o);
	if ($o['status'] === 'pending') { $p = gv_order_pay_start($o, shop_base_url()); if ($p['ok']) { header('Location: '.$p['url']); exit; } $payErr = $p['error']; }
}
if ($o && $o['status'] === 'pending') { $o = gv_order_sync($o); }
$v = $o ? gv_by_id((int)$o['voucher_id']) : null;
if ($o && $o['status'] === 'pending') { header('Refresh: 8'); }
gv_page_head('Deine Gutschein-Bestellung');
?>
	<main class="gv-page">
	<?php if (!$o): ?>
		<h1>Bestellung nicht gefunden</h1>
		<p>Dieser Link passt zu keiner Bestellung. Bitte öffne den Link aus deiner Bestätigungsmail noch einmal.</p>
		<p><a class="cart-go" href="gutschein.php">Zum Gutschein-Shop</a></p>
	<?php else: list($wst, $wtext) = gv_order_withdraw_state($o); ?>
		<?php if ($o['status'] === 'paid'): ?>
			<h1>Angekommen, danke.</h1>
			<p class="gv-stateline is-ok"><?php echo $o['delivery'] === 'pdf' ? 'Dein Gutschein ist fertig und liegt auch in deinem Postfach.' : ($o['fulfilled_at'] ? ($o['delivery'] === 'post' ? 'Die Karte ist unterwegs.' : 'Die Karte liegt für dich bereit.') : ($o['delivery'] === 'post' ? 'Wir machen die Karte fertig und schreiben dir, sobald sie zur Post geht.' : 'Wir machen die Karte fertig und schreiben dir, sobald du sie holen kannst.')); ?></p>
			<?php if ($o['delivery'] === 'pdf'): ?>
				<p style="margin-top:16px"><img src="gutschein_pdf.php?t=<?php echo $h($o['token']); ?>&amp;fmt=jpg" alt="Dein Gutschein über <?php echo $h(gv_money((int)$o['amount_cents'])); ?>" width="1100" height="780" style="width:100%;height:auto;border-radius:14px;box-shadow:0 18px 48px rgba(0,0,0,.55)"/></p>
				<p><a class="cart-go" href="gutschein_pdf.php?t=<?php echo $h($o['token']); ?>">PDF herunterladen</a></p>
				<p>Wir haben dir den Gutschein auch per Mail an <?php echo $h($o['buyer_email']); ?> geschickt.<?php if ($v && $v['status'] === 'active'): ?> Aktuelles Guthaben: <strong><?php echo $h(gv_money((int)$v['balance_cents'])); ?></strong>.<?php endif; ?></p>
			<?php endif; ?>
		<?php elseif ($o['status'] === 'pending'): ?>
			<h1>Wir warten auf deine Zahlung</h1>
			<p class="gv-stateline">Sobald sie bei uns bestätigt ist, bekommst du deinen Gutschein. Diese Seite aktualisiert sich von selbst.</p>
			<?php if (!empty($payErr)): ?><p class="gv-err" role="alert"><?php echo $h($payErr); ?></p><?php endif; ?>
			<form method="post" action="gutschein_status.php"><input type="hidden" name="t" value="<?php echo $h($o['token']); ?>"/><input type="hidden" name="do" value="pay"/><input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
				<button type="submit" class="cart-go">Jetzt bezahlen</button></form>
		<?php elseif ($o['status'] === 'withdrawn'): ?>
			<h1>Dein Widerruf ist eingegangen</h1>
			<p class="gv-stateline"><?php echo $h($wtext); ?> Der Gutschein ist gesperrt. Wir erstatten <?php echo $h(gv_money((int)$o['total_cents'])); ?> auf dem Weg, mit dem du bezahlt hast.</p>
		<?php else: ?>
			<h1>Das hat nicht geklappt.</h1>
			<p class="gv-stateline is-bad">Es wurde nichts abgebucht, und es gibt keinen Gutschein.</p>
			<p><a class="cart-go" href="gutschein.php">Neu bestellen</a></p>
		<?php endif; ?>

		<div class="gv-card"><dl>
			<dt>Bestellung</dt><dd><?php echo $h($o['number']); ?> vom <?php echo $h(date('d.m.Y', strtotime($o['created_at']))); ?></dd>
			<dt>Gutschein</dt><dd><?php echo $h(gv_money((int)$o['amount_cents'])); ?></dd>
			<?php if ((int)$o['fee_cents'] > 0): ?><dt>Versand</dt><dd><?php echo $h(gv_money((int)$o['fee_cents'])); ?></dd><?php endif; ?>
			<dt>Gesamt</dt><dd><strong><?php echo $h(gv_money((int)$o['total_cents'])); ?></strong></dd>
			<dt>Zustellung</dt><dd><?php echo $h(gv_form_label($o['delivery'])); ?><?php echo $o['delivery'] === 'post' ? ' an '.$h($o['ship_name']).', '.$h($o['ship_street']).', '.$h($o['ship_zip']).' '.$h($o['ship_city']) : ''; ?></dd>
		</dl></div>

		<?php if ($o['status'] === 'paid'): ?>
		<h2>Widerruf</h2>
		<p><?php echo $h($wtext); ?></p>
		<?php if ($wst === 'open'): ?><p><a class="gv-withdraw" href="gutschein_widerruf.php?t=<?php echo $h($o['token']); ?>">Vertrag widerrufen</a></p><?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>
	</main>
<?php gv_page_foot(); ?>
