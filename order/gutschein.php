<?php
/*
 * Gutschein-Atelier: the guest builds a voucher (amount, motif or own picture, dedication, form of delivery), sees it grow in a live preview and pays at Mollie. Also: "Guthaben prüfen" with number +
 * security code, and the landing page of the QR code on the voucher (?g=<number>). The shop part is open only when gv_on and gv_terms_ok are set (staff can look at it before).
 */
require __DIR__.'/gutschein.inc.php';
$open = $gv_cfg['open'];
$err = ''; $errField = '';
$balance = null; $balanceErr = ''; $balanceNo = '';
$scanned = isset($_GET['g']) ? mb_substr(preg_replace('/[^A-Za-z0-9\- ]/', '', (string)$_GET['g']), 0, 24) : '';
$val = function ($k, $d = '') { return isset($_POST[$k]) ? (string)$_POST[$k] : $d; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!shop_token_ok($val('token'))) { $err = 'Die Sitzung ist abgelaufen. Bitte versuche es noch einmal.'; }
	elseif ($val('do') === 'balance') {
		$balanceNo = mb_substr($val('number'), 0, 30);
		$v = gv_lookup_guest($balanceNo, $val('code'), $balanceErr);
		if ($v) { $balance = $v; }
	}
	elseif ($val('website') !== '') { $err = 'Das hat nicht geklappt. Bitte versuche es noch einmal.'; }   // hidden field that only robots fill
	elseif ($val('do') === 'order') {
		$motif = $val('motif');
		if (strpos($motif, 'own:') === 0 && !in_array(substr($motif, 4), isset($_SESSION['gv_own']) ? (array)$_SESSION['gv_own'] : array(), true)) { $motif = ''; }
		$in = array('delivery' => $val('delivery'), 'amount' => $val('amount_choice') === 'custom' ? $val('amount_custom') : $val('amount_choice'), 'motif' => $motif, 'recipient' => $val('recipient'), 'from' => $val('from'), 'message' => $val('message'),
			'name' => $val('name'), 'email' => $val('email'), 'phone' => $val('phone'), 'ship_name' => $val('ship_name'), 'ship_street' => $val('ship_street'), 'ship_zip' => $val('ship_zip'), 'ship_city' => $val('ship_city'),
			'terms' => $val('terms') === '1', 'consent' => $val('consent') === '1');
		$r = gv_order_create($in);
		if ($r['ok']) {
			$p = gv_order_pay_start($r['order'], shop_base_url());
			if ($p['ok']) { header('Location: '.$p['url']); exit; }
			fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET status = 'canceled', note = 'Zahlung nicht gestartet' WHERE id = ? AND status = 'pending'", 'i', array((int)$r['order']['id']));
			gv_void((int)$r['order']['voucher_id'], array(null, 'System'), 'Zahlung nicht gestartet');
			$err = $p['error'];
		} else { $err = $r['error']; $errField = $r['field']; }
	}
}

$presets = $gv_cfg['amounts'];
$chosenAmount = $val('amount_choice', $presets ? (string)($presets[min(1, count($presets) - 1)] / 100) : 'custom');
$forms = array_keys(array_filter($gv_cfg['forms']));
$delivery = $val('delivery', count($forms) === 1 ? $forms[0] : ($gv_cfg['forms']['pdf'] ? 'pdf' : ''));
$motifs = gv_motifs_active();
$motifNow = $val('motif', isset($motifs['kuchen']) ? 'kuchen' : (string)key($motifs));
$groups = array(); foreach ($motifs as $k => $m) { $groups[$m['group']][$k] = $m; }
$h = 'shop_h';
$errCls = function ($f) use ($errField) { return $errField === $f ? ' gv-field-err' : ''; };

gv_page_head('Gutschein verschenken', 'gv-shell');
?>
	<main class="gv-atelier">
		<div class="gv-intro">
			<h1>Einen Abend verschenken</h1>
			<p>Such dir Betrag und Motiv aus, schreib ein paar Worte dazu und sieh zu, wie dein Gutschein entsteht. Er gilt im Restaurant und beim Online-Bestellen, in Teilbeträgen, bis zum Ende des dritten Jahres nach dem Kauf.</p>
		</div>

		<?php if (!$open && !$gv_staff): ?>
		<div class="gv-page" style="grid-column:1/-1;margin:0"><h2>Bald hier</h2><p>Der Gutscheinverkauf startet in Kürze. Bis dahin bekommst du Gutscheine bei uns im Restaurant.</p></div>
		<?php else: ?>

		<figure class="gv-prev">
			<div class="gv-prev-frame" id="gv-frame"><img id="gv-prev" alt="So sieht dein Gutschein aus" width="1000" height="667" src="gutschein_preview.php?motif=<?php echo $h(rawurlencode($motifNow)); ?>&amp;amount=<?php echo $h(rawurlencode($chosenAmount === 'custom' ? $val('amount_custom', '50') : $chosenAmount)); ?>"/></div>
			<figcaption>Vorschau. Nummer und Sicherheitscode bekommst du nach der Zahlung.</figcaption>
		</figure>

		<form class="gv-form" id="gv-form" method="post" action="gutschein.php" novalidate data-fee="<?php echo (int)$gv_cfg['fee']; ?>" data-min="<?php echo (int)($gv_cfg['min'] / 100); ?>" data-max="<?php echo (int)($gv_cfg['max'] / 100); ?>" data-lapse="<?php echo $gv_cfg['lapse'] ? '1' : '0'; ?>" data-token="<?php echo $h($_SESSION['shop_token']); ?>">
			<?php if (!$open): ?><p class="gv-notice">Vorschau für dich: Der Verkauf ist noch nicht eingeschaltet, bestellen geht erst, wenn er offen ist.</p><?php endif; ?>
			<?php if ($err !== ''): ?><p class="gv-err" role="alert" id="gv-err"><?php echo $h($err); ?></p><?php endif; ?>
			<input type="hidden" name="do" value="order"/>
			<input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
			<input type="text" name="website" class="gv-trap" tabindex="-1" autocomplete="off" aria-hidden="true"/>

			<fieldset class="co-sec">
				<legend>Betrag</legend>
				<div class="gv-chips" role="radiogroup" aria-label="Betrag">
					<?php foreach ($presets as $c): $e = (string)($c / 100); ?>
					<label class="gv-chip"><input type="radio" name="amount_choice" value="<?php echo $h($e); ?>"<?php echo $chosenAmount === $e ? ' checked' : ''; ?>/><span><?php echo $h($e); ?> €</span></label>
					<?php endforeach; ?>
					<label class="gv-chip"><input type="radio" name="amount_choice" value="custom"<?php echo $chosenAmount === 'custom' ? ' checked' : ''; ?>/><span>Anderer Betrag</span></label>
				</div>
				<div class="gv-custom" id="gv-custom" <?php echo $chosenAmount === 'custom' ? '' : 'hidden'; ?>>
					<label for="gv-amount-custom">Betrag in vollen Euro (<?php echo (int)($gv_cfg['min'] / 100); ?> bis <?php echo (int)($gv_cfg['max'] / 100); ?>)</label>
					<input type="text" id="gv-amount-custom" name="amount_custom" inputmode="numeric" maxlength="4" autocomplete="off" value="<?php echo $h($val('amount_custom')); ?>" class="<?php echo trim($errCls('amount')); ?>"/> <span>€</span>
				</div>
			</fieldset>

			<fieldset class="co-sec">
				<legend>Motiv</legend>
				<?php foreach ($groups as $gname => $items): ?>
				<div class="gv-motif-group"><h3><?php echo $h($gname); ?></h3>
					<div class="gv-motifs">
						<?php foreach ($items as $k => $m): ?>
						<label class="gv-motif"><input type="radio" name="motif" value="<?php echo $h($k); ?>"<?php echo $motifNow === $k ? ' checked' : ''; ?>/><img src="gutschein/motive/thumb/<?php echo $h($k); ?>.jpg" alt="" width="240" height="160" loading="lazy"/><span><?php echo $h($m['label']); ?></span></label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endforeach; ?>
				<?php if ($gv_cfg['own']): ?>
				<div class="gv-motif-group"><h3>Dein eigenes Bild</h3>
					<div class="gv-motifs" id="gv-own-list">
						<?php if (strpos($motifNow, 'own:') === 0): ?><label class="gv-motif"><input type="radio" name="motif" value="<?php echo $h($motifNow); ?>" checked/><span>Dein Bild</span></label><?php endif; ?>
						<label class="gv-own" id="gv-own-pick">Bild hochladen<small>JPG, PNG oder WebP, mindestens 900 × 600 Pixel</small><input type="file" id="gv-own-file" accept="image/jpeg,image/png,image/webp"/></label>
					</div>
					<p class="gv-own-msg" id="gv-own-msg" role="status" aria-live="polite"></p>
					<p class="co-hint">Mit dem Hochladen bestätigst du, dass du das Bild verwenden darfst. Wir nutzen es nur für diesen einen Gutschein.</p>
				</div>
				<?php endif; ?>
			</fieldset>

			<fieldset class="co-sec">
				<legend>Deine Worte</legend>
				<div class="co-grid">
					<label class="co-f">Für (optional)<input type="text" name="recipient" id="gv-recipient" maxlength="80" autocomplete="off" value="<?php echo $h($val('recipient')); ?>"/></label>
					<label class="co-f">Von (optional)<input type="text" name="from" id="gv-from" maxlength="80" autocomplete="off" value="<?php echo $h($val('from')); ?>"/></label>
					<label class="co-f wide">Nachricht (optional)<textarea name="message" id="gv-message" maxlength="160" rows="3" placeholder="z. B. Alles Gute zum Geburtstag!"><?php echo $h($val('message')); ?></textarea><span class="gv-count" id="gv-count" aria-hidden="true">0 / 160</span></label>
				</div>
			</fieldset>

			<fieldset class="co-sec">
				<legend>So bekommst du ihn</legend>
				<?php if ($gv_cfg['forms']['pdf']): ?><label class="co-radio"><input type="radio" name="delivery" value="pdf"<?php echo $delivery === 'pdf' ? ' checked' : ''; ?>/><span>PDF per E-Mail<small>Sofort nach der Zahlung, zum Ausdrucken oder Weiterleiten.</small></span></label><?php endif; ?>
				<?php if ($gv_cfg['forms']['post']): ?><label class="co-radio"><input type="radio" name="delivery" value="post"<?php echo $delivery === 'post' ? ' checked' : ''; ?>/><span>Per Post<small>Als gedruckte Karte an die Adresse, die du angibst (+ <?php echo $h(gv_money($gv_cfg['fee'])); ?> Versand).</small></span></label><?php endif; ?>
				<?php if ($gv_cfg['forms']['pickup']): ?><label class="co-radio"><input type="radio" name="delivery" value="pickup"<?php echo $delivery === 'pickup' ? ' checked' : ''; ?>/><span>Abholung im Restaurant<small>Die gedruckte Karte liegt für dich bereit, wir melden uns per Mail.</small></span></label><?php endif; ?>
			</fieldset>

			<fieldset class="co-sec">
				<legend>Deine Daten</legend>
				<div class="co-grid">
					<label class="co-f">Name<input type="text" name="name" autocomplete="name" maxlength="80" required value="<?php echo $h($val('name')); ?>" class="<?php echo trim($errCls('name')); ?>"/></label>
					<label class="co-f">E-Mail<input type="email" name="email" autocomplete="email" maxlength="160" required value="<?php echo $h($val('email')); ?>" class="<?php echo trim($errCls('email')); ?>"/></label>
					<label class="co-f wide">Telefon (optional, für Rückfragen)<input type="tel" name="phone" autocomplete="tel" maxlength="40" value="<?php echo $h($val('phone')); ?>"/></label>
				</div>
				<div class="co-grid gv-ship" id="gv-ship" <?php echo $delivery === 'post' ? '' : 'hidden'; ?>>
					<label class="co-f wide">Name auf dem Umschlag<input type="text" name="ship_name" autocomplete="shipping name" maxlength="80" value="<?php echo $h($val('ship_name')); ?>" class="<?php echo trim($errCls('ship_name')); ?>"/></label>
					<label class="co-f wide">Straße und Hausnummer<input type="text" name="ship_street" autocomplete="shipping address-line1" maxlength="120" value="<?php echo $h($val('ship_street')); ?>"/></label>
					<label class="co-f">PLZ<input type="text" name="ship_zip" inputmode="numeric" autocomplete="shipping postal-code" maxlength="5" value="<?php echo $h($val('ship_zip')); ?>"/></label>
					<label class="co-f">Ort<input type="text" name="ship_city" autocomplete="shipping address-level2" maxlength="80" value="<?php echo $h($val('ship_city')); ?>"/></label>
				</div>
			</fieldset>

			<div class="gv-sum" aria-live="polite">
				<div class="gv-sum-row"><span>Gutschein</span><output id="gv-sum-amount">–</output></div>
				<div class="gv-sum-row" id="gv-sum-fee-row" hidden><span>Versand</span><output id="gv-sum-fee"></output></div>
				<div class="gv-sum-row is-total"><span>Gesamt</span><output id="gv-sum-total">–</output></div>
			</div>

			<label class="co-check"><input type="checkbox" name="terms" value="1"<?php echo $val('terms') === '1' ? ' checked' : ''; ?>/><span>Ich habe die <a href="gutschein_bedingungen.php" target="_blank" rel="noopener">Gutschein-Bedingungen</a> gelesen und akzeptiere sie<?php echo $gv_terms ? ' (dazu gelten unsere <a href="'.$h($gv_terms).'" target="_blank" rel="noopener">AGB</a>)' : ''; ?>.</span></label>
			<?php if ($gv_cfg['lapse']): ?>
			<label class="co-check gv-lapse" id="gv-lapse" <?php echo $delivery === 'pdf' ? '' : 'hidden'; ?>><input type="checkbox" name="consent" value="1"<?php echo $val('consent') === '1' ? ' checked' : ''; ?>/><span>Ich stimme ausdrücklich zu, dass der PDF-Gutschein sofort nach der Zahlung erstellt und mir bereitgestellt wird. Mir ist bekannt, dass ich mein Widerrufsrecht verliere, sobald der Gutschein bereitgestellt wurde.</span></label>
			<?php endif; ?>

			<button type="submit" class="cart-go" id="gv-submit"<?php echo $open ? '' : ' disabled aria-disabled="true"'; ?>>Zahlungspflichtig bestellen</button>
			<p class="gv-legal">Mit dem Klick gibst du eine verbindliche Bestellung ab und bezahlst sicher bei Mollie (Karte, PayPal, Apple Pay und weitere). Der Gutschein ist ein Mehrzweck-Gutschein.<?php if (!$gv_cfg['lapse']): ?> Du kannst die Bestellung innerhalb von 14 Tagen widerrufen: Die Funktion <a href="gutschein_widerruf.php">Vertrag widerrufen</a> findest du auf jeder Seite unten.<?php endif; ?></p>
		</form>

		<section class="gv-howto" style="grid-column:1/-1" aria-labelledby="gv-howto-h">
			<h2 id="gv-howto-h">Einen Gutschein einlösen</h2>
			<p>Im Restaurant nennst du uns die Gutscheinnummer oder zeigst den QR-Code. Beim Online-Bestellen gibst du Nummer und Sicherheitscode ein. Du kannst einen Teil des Betrags nutzen, der Rest bleibt auf dem Gutschein.</p>
			<form method="post" action="gutschein.php#gv-howto-h" class="gv-balance" style="margin-top:14px">
				<input type="hidden" name="do" value="balance"/><input type="hidden" name="token" value="<?php echo $h($_SESSION['shop_token']); ?>"/>
				<div class="co-grid" style="max-width:520px">
					<label class="co-f">Gutscheinnummer<input type="text" name="number" maxlength="30" autocapitalize="characters" autocomplete="off" value="<?php echo $h($balanceNo !== '' ? $balanceNo : $scanned); ?>"/></label>
					<label class="co-f">Sicherheitscode<input type="text" name="code" maxlength="12" autocapitalize="characters" autocomplete="off"/></label>
				</div>
				<button type="submit" class="gb-btn" style="margin-top:12px">Guthaben prüfen</button>
				<?php if ($balanceErr !== ''): ?><p class="gv-err" role="alert" style="margin-top:12px"><?php echo $h($balanceErr); ?></p><?php endif; ?>
				<?php if ($balance): $why = gv_block_reason($balance); ?>
				<p class="gv-stateline <?php echo $why === '' ? 'is-ok' : 'is-bad'; ?>" role="status"><?php echo $why === '' ? 'Guthaben: '.$h(gv_money((int)$balance['balance_cents'])).($balance['valid_until'] ? ', gültig bis '.$h(date('d.m.Y', strtotime($balance['valid_until']))) : '').'.' : $h($why); ?></p>
				<?php endif; ?>
			</form>
		</section>
		<?php endif; ?>
	</main>
	<script src="gutschein.js?v=<?php echo @filemtime(__DIR__.'/gutschein.js'); ?>"></script>
<?php gv_page_foot(); ?>
