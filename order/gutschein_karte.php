<?php
/*
 * The page behind the QR code of a voucher (?t=<token of the voucher>, the token is only on the card). It shows the voucher as a card with its balance and, for the team of the restaurant,
 * redeems it: all of it or a part, released by the PIN of an employee (the PIN alone finds the employee, wrong PINs are counted: gv_redeem_pin). The guest needs nothing here.
 * Without the PIN the page only shows the state. A redemption is booked with the name of the employee in the list of movements.
 */
require __DIR__.'/gutschein.inc.php';
$t = preg_replace('/[^a-f0-9]/', '', isset($_REQUEST['t']) ? (string)$_REQUEST['t'] : '');
$v = gv_by_token($t);
$h = 'shop_h';
$msg = isset($_SESSION['gv_flash']) ? $_SESSION['gv_flash'] : null; unset($_SESSION['gv_flash']);
$err = '';
if ($v && $_SERVER['REQUEST_METHOD'] === 'POST' && shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '')) {
	$mode = isset($_POST['mode']) && $_POST['mode'] === 'part' ? 'part' : 'all';
	$amount = null;
	if ($mode === 'part') {
		$amount = gv_parse_amount(isset($_POST['amount']) ? $_POST['amount'] : '');
		if ($amount === null || $amount < 1) { $err = 'Bitte den Teilbetrag in Euro eingeben, zum Beispiel 12,50.'; }
		elseif ($amount > (int)$v['balance_cents']) { $err = 'Der Teilbetrag ist größer als das Guthaben von '.gv_money((int)$v['balance_cents']).'.'; }
	}
	if ($err === '') {
		$r = gv_redeem_pin((int)$v['id'], isset($_POST['pin']) ? (string)$_POST['pin'] : '', $amount);
		if (!empty($r['ok'])) {
			$_SESSION['gv_flash'] = array('ok', 'Eingelöst: '.gv_money($r['redeemed']).'. Restguthaben: '.gv_money((int)$r['voucher']['balance_cents']).'.');
			header('Location: gutschein_karte.php?t='.$v['token']); exit;
		}
		$err = $r['error'];
	}
	$v = gv_by_token($t);
}
$state = $v ? gv_state($v) : '';
$names = array('open' => 'gültig', 'used' => 'eingelöst', 'expired' => 'abgelaufen', 'void' => 'gesperrt', 'pending' => 'noch nicht freigeschaltet');
gv_page_head('Gutschein', 'gv-karte');
?>
	<main class="gv-page">
	<?php if (!$v): ?>
		<h1>Gutschein nicht gefunden</h1>
		<p>Dieser Link passt zu keinem Gutschein. Bitte scanne den QR-Code auf dem Gutschein noch einmal.</p>
	<?php else: $bal = (int)$v['balance_cents']; ?>
		<h1><?php echo $state === 'open' ? 'Gutschein über '.$h(gv_money($bal)) : 'Gutschein'; ?></h1>
		<p><img class="gv-karte-img<?php echo $state === 'open' ? '' : ' is-off'; ?>" src="gutschein_karte_bild.php?t=<?php echo $h($v['token']); ?>&amp;b=<?php echo $bal; ?>" alt="Gutschein <?php echo $h($v['number']); ?>, Guthaben <?php echo $h(gv_money($bal)); ?>" width="1100" height="780"/></p>
		<p class="gv-stateline<?php echo $state === 'open' ? ' is-ok' : ' is-bad'; ?>">
			<?php if ($state === 'open'): ?>Guthaben <strong><?php echo $h(gv_money($bal)); ?></strong><?php echo (int)$v['face_cents'] > $bal ? ' von '.$h(gv_money((int)$v['face_cents'])) : ''; ?>, gültig bis <?php echo $h($v['valid_until'] ? date('d.m.Y', strtotime($v['valid_until'])) : 'unbegrenzt'); ?>.
			<?php else: echo $h(gv_block_reason($v) ?: 'Dieser Gutschein ist '.($names[$state] ?? $state).'.'); endif; ?>
		</p>
		<?php if ($msg && $msg[0] === 'ok'): ?><p class="gv-stateline is-ok" role="status"><?php echo $h($msg[1]); ?></p><?php endif; ?>

		<?php if ($state === 'open'): ?>
		<h2>Einlösen (Team)</h2>
		<p class="gv-hint">Nur für Mitarbeiter von <?php echo $h($gv_brand); ?>. Die persönliche PIN löst die Einlösung aus.</p>
		<?php if ($err !== ''): ?><p class="gv-err" role="alert"><?php echo $h($err); ?></p><?php endif; ?>
		<form method="post" action="gutschein_karte.php" class="gv-redeem" autocomplete="off">
			<input type="hidden" name="t" value="<?php echo $h($v['token']); ?>"/><input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
			<label for="gk-pin">PIN</label>
			<input id="gk-pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" required/>
			<button type="submit" name="mode" value="all" class="cart-go">Ganzen Betrag einlösen (<?php echo $h(gv_money($bal)); ?>)</button>
			<label for="gk-amount">Oder einen Teilbetrag (Euro)</label>
			<input id="gk-amount" name="amount" type="text" inputmode="decimal" maxlength="8" placeholder="z. B. 12,50"/>
			<button type="submit" name="mode" value="part" class="cart-go is-alt">Teilbetrag einlösen</button>
		</form>
		<?php endif; ?>
	<?php endif; ?>
	</main>
<?php gv_page_foot(); ?>
