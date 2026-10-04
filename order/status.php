<?php
/*
 * What the guest sees after ordering: where the order stands (steps), the expected time, the order itself, and, for an
 * unpaid online order, the way to pay. The link contains the secret token of the order, so it is the only key.
 * The page asks itself (status.php?t=..&json=1) every few seconds and reloads when something changed.
 */
require __DIR__.'/bootstrap.inc.php';
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$lang = 'de';
$token = isset($_GET['t']) && preg_match('/^[a-f0-9]{32}$/', $_GET['t']) ? $_GET['t'] : '';
$order = $token !== '' ? shop_order_by_token($token) : null;
if ($order) {
	shop_expire_pending();
	$order = shop_mollie_sync($order); // the guest may be back from Mollie before the webhook arrived
	$order = shop_order((int)$order['id']);
}
if (isset($_GET['json'])) {
	header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
	echo json_encode($order ? array('ok' => true, 'status' => $order['status'], 'payment' => $order['payment_status'], 'driver' => shop_driver_position($order)) : array('ok' => false));
	exit;
}
$phone = !empty($settings['mailPhone']) ? $settings['mailPhone'] : '';
$telHref = $phone !== '' ? 'tel:'.shop_h(preg_replace('/\s+/', '', $phone)) : '';
$tel = $phone !== '' ? '<a href="'.$telHref.'">'.shop_h($phone).'</a>' : '';
$delivery = $order && $order['type'] === 'delivery';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="theme-color" content="#0c0b0a"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Deine Bestellung &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
	<link rel="stylesheet" href="stempel.css?v=<?php echo @filemtime(__DIR__.'/stempel.css'); ?>"/>
</head>
<body class="shop-shell" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>">
	<header class="shop-top">
		<div class="shop-top-in">
			<a class="shop-logo" href="./" aria-label="<?php echo shop_h($brand); ?>"><?php echo brand_logo_html($brand, 'brand-logo'); ?></a>
			<a class="co-back" href="./">Zur Speisekarte</a>
		</div>
	</header>
	<main class="st-wrap">
	<?php if (!$order): ?>
		<h1 class="st-title">Bestellung nicht gefunden</h1>
		<p class="st-lead">Dieser Link gehört zu keiner Bestellung. Bitte prüfe ihn oder ruf uns an<?php echo $tel !== '' ? ': '.$tel : ''; ?>.</p>
		<?php if ($telHref !== ''): ?><p class="st-again"><a class="cart-go" href="<?php echo $telHref; ?>">Anrufen: <?php echo shop_h($phone); ?></a></p><?php endif; ?>
	<?php else:
		$st = $order['status'];
		$paidOnline = ($order['payment_method'] === 'mollie');
		$titles = array(
			'pending' => 'Fast geschafft: bitte bezahlen', 'new' => 'Danke, deine Bestellung ist eingegangen', 'accepted' => 'Wir kümmern uns um deine Bestellung',
			'preparing' => 'Deine Bestellung wird zubereitet', 'ready' => $delivery ? 'Deine Bestellung ist fertig und wird gleich abgeholt' : 'Deine Bestellung ist abholbereit',
			'delivering' => 'Deine Bestellung ist unterwegs zu dir', 'done' => 'Guten Appetit!', 'cancelled' => 'Diese Bestellung wurde storniert',
			'failed' => 'Bei deiner Lieferung gab es ein Problem');
		// "accepted" used to share its rank with "new", so the restaurant actively confirming an order produced
		// no visible step movement at all - it now gets its own step and rank, same as every other transition
		$steps = $delivery ? array('new' => 'Bestellung eingegangen', 'accepted' => 'Bestätigt', 'preparing' => 'Wird zubereitet', 'ready' => 'Fertig', 'delivering' => 'Unterwegs', 'done' => 'Geliefert')
			: array('new' => 'Bestellung eingegangen', 'accepted' => 'Bestätigt', 'preparing' => 'Wird zubereitet', 'ready' => 'Abholbereit', 'done' => 'Abgeholt');
		$rank = array('pending' => 0, 'new' => 1, 'accepted' => 2, 'preparing' => 3, 'ready' => 4, 'delivering' => 5, 'done' => 6);
		$stepRank = $delivery ? array('new' => 1, 'accepted' => 2, 'preparing' => 3, 'ready' => 4, 'delivering' => 5, 'done' => 6) : array('new' => 1, 'accepted' => 2, 'preparing' => 3, 'ready' => 4, 'done' => 5);
		$cur = isset($rank[$st]) ? $rank[$st] : 0;
		$items = shop_order_items((int)$order['id']);
		$eta = $order['scheduled_at'] ? strtotime($order['scheduled_at']) : ($order['eta_at'] ? strtotime($order['eta_at']) : 0); // a wish time always stands as the guest chose it
	?>
		<h1 class="st-title<?php echo in_array($st, array('failed', 'cancelled'), true) ? ' is-danger' : ($st === 'done' ? ' is-success' : ''); ?>"><?php echo shop_h($titles[$st]); ?></h1>
		<p class="st-lead">
			<?php if ($st === 'pending'): ?>Deine Bestellung geht erst an die Küche, wenn die Zahlung angekommen ist.
			<?php elseif ($st === 'cancelled'): ?>Wenn du damit nicht gerechnet hast, ruf uns bitte an<?php echo $tel !== '' ? ': '.$tel : ''; ?>.
			<?php elseif ($st === 'failed'): ?>Dein Fahrer konnte die Lieferung leider nicht abschließen. Bitte ruf uns an<?php echo $tel !== '' ? ': '.$tel : ''; ?>.
			<?php elseif ($st === 'done'): ?>Vielen Dank für deine Bestellung.
			<?php endif; ?>
		</p>
		<?php
			$sub = array(
				'new' => 'Wir prüfen deine Bestellung und bestätigen sie gleich.',
				'accepted' => 'Bestätigt. Gleich legt die Küche los.',
				'preparing' => 'Frisch für dich in der Küche, alles wird jetzt zubereitet.',
				'ready' => $delivery ? 'Alles ist verpackt, der Fahrer holt es gleich ab.' : 'Alles ist frisch und warm für dich bereit.',
				'delivering' => 'Gleich klingelt es bei dir.',
			);
			$late = ($eta && time() > $eta + 300 && !in_array($st, array('pending', 'done', 'cancelled', 'failed', 'ready'), true));
		?>
		<?php if (isset($sub[$st])): ?><p class="st-sub"><?php echo shop_h($sub[$st]); ?></p><?php endif; ?>
		<?php if ($eta && !in_array($st, array('pending', 'done', 'cancelled', 'failed'), true)): ?>
		<div class="st-eta" aria-label="Voraussichtliche Zeit">
			<span><?php echo $delivery ? ($order['scheduled_at'] ? 'Lieferung um' : 'Voraussichtlich bei dir um') : ($order['scheduled_at'] ? 'Abholung um' : 'Abholbereit gegen'); ?></span>
			<strong><?php echo shop_h(date('H:i', $eta)); ?></strong><span>Uhr</span>
		</div>
		<?php endif; ?>
		<?php if ($late): ?><p class="st-late">Es dauert etwas länger als geplant, danke für deine Geduld. Wir sind dran<?php echo $tel !== '' ? '. Bei Fragen erreichst du uns unter '.$tel : ''; ?>.</p><?php endif; ?>
		<span class="st-num">Bestellung <?php echo shop_h($order['number']); ?></span>

		<?php if ($st === 'pending' && $paidOnline): ?>
			<div class="st-pay">Die Zahlung ist noch offen. <a href="#" id="st-repay">Jetzt online bezahlen</a><span id="st-repay-msg" class="st-warn" role="alert"></span></div>
		<?php endif; ?>

		<?php if ($st !== 'cancelled' && $st !== 'failed' && $st !== 'pending'): ?>
		<ol class="st-steps" aria-label="Stand deiner Bestellung">
			<?php foreach ($steps as $key => $label):
				$r = $stepRank[$key]; $cls = ($cur > $r || $st === 'done') ? 'is-done' : ($cur === $r ? 'is-now' : ''); ?>
			<li class="st-step <?php echo $cls; ?>"><span><?php echo shop_h($label); ?></span></li>
			<?php endforeach; ?>
		</ol>
		<?php endif; ?>

		<?php if ($st !== 'cancelled' && $st !== 'failed' && $st !== 'done'): ?><p class="st-hint">Diese Seite aktualisiert sich von selbst. Du kannst sie offen lassen oder den Link speichern.</p><?php endif; ?>
		<?php if ($st === 'done'): ?><p class="st-again"><a class="cart-go" href="./">Noch einmal bestellen</a></p><?php endif; ?>

		<?php
			// map: delivery = restaurant, destination and (when the driver shares it) the driver; pickup = where we are
			$origin = shop_origin();
			$dest = ($delivery && (float)$order['lat'] != 0 && (float)$order['lng'] != 0) ? array((float)$order['lat'], (float)$order['lng']) : null;
			$dpos = shop_driver_position($order);
			$mapOn = in_array($st, array('new', 'accepted', 'preparing', 'ready', 'delivering'), true) && (($delivery && $dest) || (!$delivery && $origin));
			if ($mapOn):
				$cfg = array('origin' => $origin, 'dest' => $dest, 'driver' => $dpos, 'delivery' => $delivery, 'status' => $st, 'brand' => $brand);
		?>
		<div class="st-box st-mapbox">
			<h2><?php echo $delivery ? 'Auf der Karte' : 'So findest du uns'; ?></h2>
			<div id="st-map" class="st-map" data-cfg="<?php echo shop_h(json_encode($cfg)); ?>" role="img" aria-label="Karte"></div>
			<p class="st-mapnote" id="st-mapnote" role="status" aria-live="polite"></p>
			<?php if (!$delivery && $origin): ?><p class="st-mapnote"><a href="https://www.openstreetmap.org/?mlat=<?php echo $origin[0]; ?>&amp;mlon=<?php echo $origin[1]; ?>#map=17/<?php echo $origin[0]; ?>/<?php echo $origin[1]; ?>" target="_blank" rel="noopener">In der Karten-App öffnen</a></p><?php endif; ?>
		</div>
		<link rel="stylesheet" href="vendor/leaflet/leaflet.css"/>
		<script src="vendor/leaflet/leaflet.js"></script>
		<script src="track.js?v=<?php echo @filemtime(__DIR__.'/track.js'); ?>"></script>
		<?php endif; ?>

		<?php
			// stamp card: what this order brings (or brought) the guest; the stamp lands once, when the finished order is seen first
			if ($st !== 'cancelled' && $st !== 'failed' && shop_stamp_cfg()['on']):
				$sKeys = shop_coupon_guest_keys($order['phone'], $order['email']);
				$sState = shop_stamp_state($sKeys, $delivery ? 'delivery' : 'pickup', 0);
				$sRow = fb_row("SELECT coupon_id FROM ".fb_t('tp_shop_stamps')." WHERE order_id = ?", 'i', array((int)$order['id']));
				$sFull = $sRow && (int)$sRow['coupon_id'] > 0;
				$sFresh = $sFull ? $sState['goal'] - 1 : (($sRow && $sState['count'] > 0) ? $sState['count'] - 1 : -1);
				$sCfg = array('state' => $sState, 'fresh' => $sFresh, 'full' => $sFull, 'done' => $st === 'done', 'known' => shop_stamp_has_keys($sKeys));
		?>
		<div id="st-stamp" data-cfg="<?php echo shop_h(json_encode($sCfg)); ?>"></div>
		<script src="stempel.js?v=<?php echo @filemtime(__DIR__.'/stempel.js'); ?>"></script>
		<script>
		(function () {
			var el = document.getElementById('st-stamp'), c = JSON.parse(el.dataset.cfg), key = 'amadeusStampSeen:' + <?php echo json_encode($token); ?>, fresh = -1;
			try { if (c.done && c.fresh >= 0 && !localStorage.getItem(key)) { fresh = c.fresh; localStorage.setItem(key, '1'); } } catch (e) {}
			AmadeusStamp.render(el, c.state, { known: c.known, next: !c.done, fresh: fresh, full: c.full && fresh >= 0 });
		})();
		</script>
		<?php endif; ?>
		<?php if (shop_acc_enabled() && !shop_acc_current()): ?>
		<p class="st-account">Alle deine Bestellungen, Lieblingsgerichte und deine Stempelkarte findest du in deinem Konto. <a href="./?konto=1">Jetzt anmelden</a></p>
		<?php endif; ?>

		<div class="st-box">
			<h2>Deine Bestellung</h2>
			<?php foreach ($items as $it): ?>
			<div class="cart-line">
				<h3><?php echo (int)$it['qty']; ?>× <?php echo shop_h($it['title']); ?><?php echo $it['variation'] !== '' ? ' <span class="cart-opts">('.shop_h($it['variation']).')</span>' : ''; ?></h3>
				<span class="cart-lineprice"><?php echo shop_money($it['line_cents']); ?></span>
				<?php $opt = array(); foreach ($it['options'] as $o) { $opt[] = ($o['qty'] > 1 ? $o['qty'].'× ' : '').$o['title']; } if ($opt): ?><p class="cart-opts"><?php echo shop_h(implode(', ', $opt)); ?></p><?php endif; ?>
				<?php if ($it['note'] !== ''): ?><p class="cart-opts">Hinweis: <?php echo shop_h($it['note']); ?></p><?php endif; ?>
			</div>
			<?php endforeach; ?>
			<div class="cart-sum">
				<div class="cart-row"><span>Zwischensumme</span><span><?php echo shop_money($order['subtotal_cents']); ?></span></div>
				<?php if ($delivery): ?><div class="cart-row muted"><span>Liefergebühr</span><span><?php echo shop_money($order['fee_cents']); ?></span></div><?php endif; ?>
				<?php if ($order['tip_cents'] > 0): ?><div class="cart-row muted"><span>Trinkgeld</span><span><?php echo shop_money($order['tip_cents']); ?></span></div><?php endif; ?>
				<?php if ((int)$order['discount_cents'] > 0): ?><div class="cart-row muted"><span>Gutschein <?php echo shop_h($order['coupon_code']); ?></span><span>&minus;<?php echo shop_money($order['discount_cents']); ?></span></div><?php endif; ?>
				<div class="cart-row total"><span>Gesamt</span><span><?php echo shop_money($order['total_cents']); ?></span></div>
				<div class="cart-row muted"><span>Zahlung</span><span><?php
					echo $paidOnline ? ($order['payment_status'] === 'paid' ? 'online bezahlt' : 'online, noch offen') : (($order['payment_method'] === 'cash' ? 'bar' : 'mit Karte').($delivery ? ' bei Lieferung' : ' bei Abholung')); ?></span></div>
			</div>
		</div>

		<div class="st-box">
			<h2><?php echo $delivery ? 'Lieferadresse' : 'Abholung'; ?></h2>
			<?php if ($delivery): ?>
				<p><?php echo shop_h($order['customer_name']); ?><br/><?php echo shop_h($order['street']); ?><br/><?php echo shop_h($order['zip'].' '.$order['city']); ?><?php echo $order['address_note'] !== '' ? '<br/><span class="cart-opts">'.shop_h($order['address_note']).'</span>' : ''; ?></p>
			<?php else: ?>
				<p>Du holst deine Bestellung bei uns ab, <?php echo shop_h($brand); ?>. Nenne beim Abholen deine Bestellnummer <strong><?php echo shop_h($order['number']); ?></strong>.</p>
			<?php endif; ?>
			<?php if ($tel !== ''): ?><p class="cart-opts" style="margin-top:10px">Fragen zur Bestellung? <?php echo $tel; ?></p><?php endif; ?>
		</div>
		<?php include __DIR__.'/../api/legal_footer.php'; ?>
		<script>
		(function () {
			var t = <?php echo json_encode($token); ?>, status = <?php echo json_encode($st.'|'.$order['payment_status']); ?>, TOKEN = document.body.dataset.token;
			var done = <?php echo json_encode(in_array($st, array('done', 'cancelled', 'failed'), true)); ?>;
			var lateIn = <?php echo json_encode($eta ? max(0, $eta + 301 - time()) : 0); ?>;
			if (!done && lateIn > 0 && lateIn < 10800) { setTimeout(function () { location.reload(); }, lateIn * 1000); }
			if (!done) { setInterval(function () { fetch('status.php?t=' + t + '&json=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.ok && (r.status + '|' + r.payment) !== status) { location.reload(); return; } if (r.ok && window.StatusMap) { window.StatusMap.update(r.driver); } }).catch(function () {}); }, <?php echo $st === 'delivering' ? 6000 : 12000; ?>); }
			var re = document.getElementById('st-repay');
			if (re) { re.addEventListener('click', function (ev) { ev.preventDefault(); var m = document.getElementById('st-repay-msg'); m.textContent = ' Einen Moment ...';
				fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'repay', token: TOKEN, order: t }) }).then(function (r) { return r.json(); }).then(function (r) {
					if (r.ok) { location.href = r.redirect; } else { m.textContent = ' ' + r.error; } }).catch(function () { m.textContent = ' Das hat nicht geklappt.'; }); }); }
		})();
		</script>
	<?php endif; ?>
	</main>
</body>
</html>
