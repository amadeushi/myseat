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
	echo json_encode($order ? array('ok' => true, 'status' => $order['status'], 'payment' => $order['payment_status'], 'driver' => shop_driver_position($order), 'here' => (int)($order['status'] === 'delivering' && !empty($order['driver_here_at']))) : array('ok' => false));
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
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
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
		$paidOnline = ($order['payment_method'] === 'mollie' || $order['payment_method'] === 'uber_eats');   // (Uber Eats: paid on Uber's side, the order is marked paid)
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
		<?php if (!in_array($st, array('failed', 'cancelled'), true)): ?><img class="st-zeus-head" src="zeus_badge.png" alt="" width="72" height="64"/><?php endif; ?>
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
		<?php if ($delivery && $st === 'delivering' && !empty($order['driver_here_at'])): $hd = shop_issue_driver($order); ?>
		<div class="st-box st-driverhere" id="st-driverhere" role="alert">
			<h2>Dein Fahrer ist da</h2>
			<p>Er steht vor deiner Tür oder gleich davor. Bitte mach auf oder komm kurz raus.</p>
			<?php if ($hd && $hd['phone'] !== ''): ?><a class="cart-go" href="tel:<?php echo shop_h(preg_replace('/[^\d+]/', '', $hd['phone'])); ?>"><span><?php echo shop_h($hd['name'] !== '' ? $hd['name'].' anrufen' : 'Fahrer anrufen'); ?></span></a><?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ($eta && !in_array($st, array('pending', 'done', 'cancelled', 'failed'), true)): ?>
		<div class="st-eta" aria-label="Voraussichtliche Zeit">
			<span><?php echo $delivery ? ($order['scheduled_at'] ? 'Lieferung um' : 'Voraussichtlich bei dir um') : ($order['scheduled_at'] ? 'Abholung um' : 'Abholbereit gegen'); ?></span>
			<strong><?php echo shop_h(date('H:i', $eta)); ?></strong><span>Uhr</span>
		</div>
		<?php endif; ?>
		<?php if ($late): ?><p class="st-late">Es dauert etwas länger als geplant, danke für deine Geduld. Wir sind dran<?php echo $tel !== '' ? '. Bei Fragen erreichst du uns unter '.$tel : ''; ?>.</p><?php endif; ?>
		<span class="st-num">Bestellung <?php echo shop_h($order['number']); ?></span>

		<?php
			// "Falsch bestellt?": as long as the kitchen has not begun, the guest can turn a pickup into a delivery or the other way round (once; not for an order paid online or in a tour)
			$canSwitch = in_array($st, array('new', 'accepted'), true) && $order['source'] === 'shop' && $order['payment_method'] !== 'mollie' && empty($order['tour'])
				&& !fb_row("SELECT id FROM ".fb_t('tp_shop_order_log')." WHERE order_id = ? AND event = 'mode_switch' LIMIT 1", 'i', array((int)$order['id']));
		?>
		<?php if ($canSwitch): ?>
			<div class="st-switch" id="st-switch" data-to="<?php echo $delivery ? 'pickup' : 'delivery'; ?>">
				<p class="st-switch-q"><strong>Falsch bestellt?</strong> <?php echo $delivery ? 'Du möchtest lieber selbst abholen?' : 'Du möchtest es doch geliefert bekommen?'; ?></p>
				<?php if ($delivery): ?>
					<button type="button" class="st-pay-sw" id="st-sw-go">Doch selbst abholen</button>
				<?php else: ?>
					<button type="button" class="st-pay-sw" id="st-sw-open" aria-expanded="false" aria-controls="st-sw-form">Doch liefern lassen</button>
					<div class="st-switch-form" id="st-sw-form" hidden>
						<label>Straße und Hausnummer<input type="text" id="st-sw-street" maxlength="120" autocomplete="street-address"/></label>
						<label>PLZ<input type="text" id="st-sw-zip" maxlength="10" inputmode="numeric" autocomplete="postal-code"/></label>
						<label>Ort<input type="text" id="st-sw-city" maxlength="80" value="Hildesheim" autocomplete="address-level2"/></label>
						<label>Hinweis für die Lieferung (optional)<input type="text" id="st-sw-note" maxlength="200" placeholder="Etage, Klingel"/></label>
						<button type="button" class="st-pay-sw" id="st-sw-go">Liefern lassen</button>
					</div>
				<?php endif; ?>
				<span class="st-warn" id="st-sw-msg" role="alert"></span>
			</div>
		<?php endif; ?>
		<?php if ($st === 'pending' && $paidOnline): ?>
			<div class="st-pay">Die Zahlung ist noch offen. <a href="#" id="st-repay">Jetzt online bezahlen</a><span id="st-repay-msg" class="st-warn" role="alert"></span>
				<?php if ($order['payment_method'] === 'mollie' && $order['payment_status'] !== 'paid' && (shop_flag('allow_cash') || shop_flag('allow_card_door'))): ?>
				<span class="st-pay-alt">Lieber vor Ort zahlen?
					<?php if (shop_flag('allow_cash')): ?><button type="button" class="st-pay-sw" data-pay="cash"><?php echo $delivery ? 'Bar bei Lieferung' : 'Bar bei Abholung'; ?></button><?php endif; ?>
					<?php if (shop_flag('allow_card_door')): ?><button type="button" class="st-pay-sw" data-pay="card_door"><?php echo $delivery ? 'Karte bei Lieferung' : 'Karte bei Abholung'; ?></button><?php endif; ?>
				</span>
				<?php endif; ?>
			</div>
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
		<?php if (in_array($st, array('new', 'accepted', 'preparing', 'ready', 'delivering'), true) && shop_flag('push_on')): ?>
		<div class="st-push" id="st-push" data-order="<?php echo shop_h($token); ?>" hidden>
			<p>Du musst die Seite nicht offen lassen: Wir melden uns auf diesem Gerät, wenn sich etwas tut.</p>
			<button type="button" class="cart-go">Benachrichtigung einschalten</button>
			<p class="st-pushmsg" id="st-pushmsg" role="status" aria-live="polite"></p>
		</div>
		<script src="push.js?v=<?php echo @filemtime(__DIR__.'/push.js'); ?>"></script>
		<?php endif; ?>
		<?php if ($st === 'done' && $delivery):
			// "Alles dabei?": after the delivery, for a while: call the driver, or report what is missing (web/classes/shop_issue.class.php, order/issue.js); the box is drawn by the script from this state
			$issueState = shop_issue_public($order, $phone);
			if (!empty($issueState['show'])): ?>
		<div class="st-box st-issue" id="st-issue" data-order="<?php echo shop_h($token); ?>" data-state="<?php echo shop_h(json_encode($issueState, JSON_UNESCAPED_UNICODE)); ?>"><noscript><h2>Alles dabei?</h2><p>Wenn etwas fehlt, ruf uns bitte an<?php echo $tel !== '' ? ': '.$tel : ''; ?>.</p></noscript></div>
		<script src="issue.js?v=<?php echo @filemtime(__DIR__.'/issue.js'); ?>"></script>
		<?php endif; endif; ?>
		<?php if ($st === 'done' && $delivery):
			// one tap on the stars for the delivery (the driver, when he is known by name): saved at once, asked once
			$drvName = ''; if (!empty($order['driver_id'])) { $dm = shop_drivers_name_map(); $drvName = isset($dm[(int)$order['driver_id']]) ? trim((string)strtok(trim((string)$dm[(int)$order['driver_id']]), ' ')) : ''; }
			$rated = $order['driver_rating'] !== null ? (int)$order['driver_rating'] : 0;
		?>
		<div class="st-box st-rate" id="st-rate" data-rated="<?php echo $rated; ?>">
			<h2>Wie war deine Lieferung<?php echo $drvName !== '' ? ' durch '.shop_h($drvName) : ''; ?>?</h2>
			<div class="st-stars" role="group" aria-label="Bewertung von 1 bis 5 Sternen">
				<?php for ($i = 1; $i <= 5; $i++): ?><button type="button" class="st-star<?php echo $i <= $rated ? ' is-on' : ''; ?>" data-rate="<?php echo $i; ?>" aria-label="<?php echo $i; ?> von 5 Sternen"<?php echo $rated ? ' disabled' : ''; ?>><svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M12 2.5l2.9 6 6.6.9-4.8 4.6 1.2 6.5L12 17.4 6.1 20.5l1.2-6.5L2.5 9.4l6.6-.9z" stroke-linejoin="round"/></svg></button><?php endfor; ?>
			</div>
			<div class="st-ratethanks"><img class="st-zeus" id="st-zeus" src="zeus_badge.png" alt="" width="56" height="48"<?php echo $rated ? '' : ' hidden'; ?>/><p class="st-ratemsg" id="st-ratemsg" role="status" aria-live="polite"><?php echo $rated ? 'Danke dir! Zeus freut sich.' : ''; ?></p></div>
		</div>
		<?php endif; ?>
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
		<?php
			// stamp card reminders: asked when the guest sees his progress (his best moment). Two equal answers, no second ask after "Nein danke" for 90 days;
			// a request that waits for its confirmation shows what to do next (and a button to send the mail again)
			$nlAcc = (shop_acc_enabled() && $order && shop_stamp_cfg()['on']) ? shop_acc_current() : null;
			if ($nlAcc && $st !== 'cancelled' && $st !== 'failed' && empty($order['is_test']) && shop_nl_valid($order['email'])):
				$nlSt = shop_nl_state($order['email']);
				if (!$nlSt['stamps']):
					$nlMode = $nlSt['want_stamps'] ? 'pending' : 'ask';
					$nlCount = isset($sState) ? (int)$sState['count'] : 0; $nlGoal = isset($sState) ? (int)$sState['goal'] : shop_stamp_cfg()['goal'];
		?>
		<section class="st-nl" id="st-nl" data-mode="<?php echo $nlMode; ?>" data-order="<?php echo shop_h($token); ?>" data-csrf="<?php echo shop_h($_SESSION['shop_token']); ?>" data-mail="<?php echo shop_h(preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', shop_nl_email($order['email']))); ?>" data-count="<?php echo $nlCount; ?>" data-goal="<?php echo $nlGoal; ?>" aria-live="polite"></section>
		<script>
		(function () {
			var box = document.getElementById('st-nl'), d = box.dataset, KEY = 'amadeusNlNo', busy = false;
			function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
			function skipped() { try { var t = +localStorage.getItem(KEY); return t && Date.now() - t < 90 * 86400000; } catch (e) { return false; } }
			function ask() {
				if (skipped()) { box.hidden = true; return; }
				var n = +d.count, g = +d.goal;
				box.innerHTML = '<h2>Soll ich dir Bescheid geben?</h2><p>' + (n > 0 ? 'Du hast ' + n + ' von ' + g + ' Stempeln. ' : '') + 'Wir sagen dir per Mail Bescheid, wenn deine Karte fast voll ist und bevor Stempel verfallen.</p>' +
					'<div class="st-nl-btns"><button type="button" class="st-nl-btn is-yes" data-yes>Ja, bitte Bescheid geben</button><button type="button" class="st-nl-btn" data-no>Nein danke</button></div>' +
					'<p class="st-nl-fine">Du bestätigst es einmal per Mail an ' + esc(d.mail) + '. Abmelden kannst du dich jederzeit.</p>';
			}
			function pending(msg) {
				box.innerHTML = '<h2>Fast fertig</h2><p>' + (msg || 'Wir haben dir eine Mail an <strong>' + esc(d.mail) + '</strong> geschickt. Tippe dort auf „Anmeldung bestätigen“, erst dann gilt es. Schau auch im Spam-Ordner nach.') + '</p>' +
					'<div class="st-nl-btns"><button type="button" class="st-nl-btn" data-again>Mail erneut senden</button></div><p class="st-nl-fine" data-state role="status"></p>';
			}
			function send(again) {
				if (busy) { return; } busy = true;
				fetch('api.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ op: 'nl_ask', token: d.csrf, order: d.order, topics: ['stamps'] }) })
					.then(function (r) { return r.json(); }).then(function (r) {
						busy = false;
						if (!r.ok) { if (again) { var s = box.querySelector('[data-state]'); if (s) { s.textContent = r.error || 'Das hat nicht geklappt.'; } } else { var p = box.querySelector('.st-nl-fine'); if (p) { p.textContent = r.error || 'Das hat nicht geklappt. Bitte versuche es noch einmal.'; } } return; }
						if (r.state === 'already') { box.innerHTML = '<h2>Das hast du schon eingestellt</h2><p>Wir geben dir Bescheid. Ändern kannst du es in deinem Konto unter „Mails“.</p>'; return; }
						if (again) { var st = box.querySelector('[data-state]'); if (st) { st.textContent = 'Die Mail ist unterwegs.'; } } else { pending(); }
					}).catch(function () { busy = false; var f = box.querySelector('.st-nl-fine, [data-state]'); if (f) { f.textContent = 'Keine Verbindung. Bitte versuche es noch einmal.'; } });
			}
			box.addEventListener('click', function (ev) {
				if (ev.target.closest('[data-yes]')) { send(false); }
				else if (ev.target.closest('[data-no]')) { try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {} box.innerHTML = '<p class="st-nl-fine">Alles klar, wir fragen nicht noch einmal. Du kannst es jederzeit in deinem Konto unter „Mails“ einschalten.</p>'; }
				else if (ev.target.closest('[data-again]')) { send(true); }
			});
			if (d.mode === 'pending') { pending(); } else { ask(); }
		})();
		</script>
		<?php endif; endif; ?>
		<?php if (shop_acc_enabled() && !shop_acc_current()): ?>
		<p class="st-account">Du bist nicht angemeldet, deshalb zählt diese Bestellung noch nicht für deine Stempelkarte. Melde dich an (mit derselben Telefonnummer oder E-Mail-Adresse wie bei dieser Bestellung), dann tragen wir sie nach. <a href="./?konto=1&amp;next=<?php echo rawurlencode('status.php?t='.$token); ?>">Jetzt anmelden</a></p>
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
				<?php if ((int)$order['discount_cents'] > 0): ?><div class="cart-row muted"><span><?php echo $order['coupon_code'] !== '' ? 'Gutschein '.shop_h($order['coupon_code']) : 'Rabatt'; ?></span><span>&minus;<?php echo shop_money($order['discount_cents']); ?></span></div><?php endif; ?>
				<?php if ((int)$order['offer_cents'] > 0): ?><div class="cart-row muted"><span><?php echo shop_h($order['offer_note'] !== '' ? preg_replace('/ -[\d,]+ €$/u', '', $order['offer_note']) : 'Kombi-Vorteil'); ?></span><span>&minus;<?php echo shop_money($order['offer_cents']); ?></span></div><?php endif; ?>
				<?php if ((int)$order['surcharge_cents'] > 0): ?><div class="cart-row muted"><span>Aufschlag</span><span><?php echo shop_money($order['surcharge_cents']); ?></span></div><?php endif; ?>
				<?php $vcents = (int)(isset($order['voucher_cents']) ? $order['voucher_cents'] : 0); ?>
				<div class="cart-row total"><span>Gesamt</span><span><?php echo shop_money((int)$order['total_cents'] + $vcents); ?></span></div>
				<?php if ($vcents > 0): ?><div class="cart-row muted"><span>Geschenkgutschein</span><span>&minus;<?php echo shop_money($vcents); ?></span></div><?php if ((int)$order['total_cents'] > 0): ?><div class="cart-row total"><span>Noch zu zahlen</span><span><?php echo shop_money($order['total_cents']); ?></span></div><?php endif; ?><?php endif; ?>
				<div class="cart-row muted"><span>Zahlung</span><span><?php
					echo $order['payment_method'] === 'voucher' ? 'mit Geschenkgutschein bezahlt' : ($paidOnline ? ($order['payment_status'] === 'paid' ? 'online bezahlt' : 'online, noch offen') : (($order['payment_method'] === 'cash' ? 'bar' : 'mit Karte').($delivery ? ' bei Lieferung' : ' bei Abholung'))); ?></span></div>
			</div>
			<?php if ($order['offer_choice'] === 'voucher'): $ev = fb_row("SELECT code, value, min_order_cents, valid_until FROM ".fb_t('tp_shop_coupons')." WHERE source = 'offer' AND note = ? LIMIT 1", 's', array('Ersatz für das Gratis-Extra, Bestellung '.$order['number'])); if ($ev): ?>
			<p class="st-voucher">Dein Gutschein statt des Extras: <strong><?php echo shop_h($ev['code']); ?></strong> über <?php echo shop_money($ev['value']); ?>, gültig bis <?php echo shop_h(date('d.m.Y', strtotime($ev['valid_until']))); ?> ab <?php echo shop_money($ev['min_order_cents']); ?> Warenwert. Wir ziehen ihn bei deiner nächsten Bestellung automatisch ab.</p>
			<?php endif; endif; ?>
		</div>

		<div class="st-box">
			<h2><?php echo $delivery ? 'Lieferadresse' : 'Abholung'; ?></h2>
			<?php if ($delivery): ?>
				<p><?php echo shop_h($order['customer_name']); ?><br/><?php echo shop_h($order['street']); ?><br/><?php echo shop_h($order['zip'].' '.$order['city']); ?><?php echo $order['address_note'] !== '' ? '<br/><span class="cart-opts">'.shop_h($order['address_note']).'</span>' : ''; ?></p>
			<?php else: ?>
				<p>Du holst deine Bestellung bei uns ab, <?php echo shop_h($brand); ?>. Nenne beim Abholen deine Bestellnummer <strong><?php echo shop_h($order['number']); ?></strong>.</p>
			<?php endif; ?>
			<?php if (!$delivery && in_array($st, array('accepted', 'preparing', 'ready'), true)): ?>
			<div class="st-here" id="st-here">
				<?php if ($order['arrived_at'] !== null): ?>
				<p class="st-heredone" role="status">Danke, wir wissen Bescheid. Wir sind gleich bei dir.</p>
				<?php else: ?>
				<p>Bist du schon da? Tippe kurz, dann wissen wir Bescheid.</p>
				<button type="button" class="cart-go" id="st-here-btn">Ich bin da</button>
				<p class="st-heremsg" id="st-heremsg" role="status" aria-live="polite"></p>
				<?php endif; ?>
			</div>
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
			if (!done) { setInterval(function () { fetch('status.php?t=' + t + '&json=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.ok && (r.status + '|' + r.payment) !== status) { location.reload(); return; } if (r.ok && r.here && !document.getElementById('st-driverhere')) { location.reload(); return; } if (r.ok && window.StatusMap) { window.StatusMap.update(r.driver); } }).catch(function () {}); }, <?php echo $st === 'delivering' ? 6000 : 12000; ?>); }
			// "Ich bin da" (pickup) and the stars for the delivery: one tap, saved at once
			function post(op, extra) {
				return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(Object.assign({ op: op, token: TOKEN, order: t }, extra || {})) }).then(function (r) { return r.json(); });
			}
			var hb = document.getElementById('st-here-btn');
			if (hb) { hb.addEventListener('click', function () {
				hb.disabled = true; var hm = document.getElementById('st-heremsg');
				post('arrived').then(function (r) {
					if (r.ok) { var box = document.getElementById('st-here'); box.innerHTML = '<p class="st-heredone" role="status">Danke, wir wissen Bescheid. Wir sind gleich bei dir.</p>'; }
					else { hb.disabled = false; hm.textContent = r.error || 'Das hat nicht geklappt. Bitte sag uns kurz Bescheid.'; }
				}).catch(function () { hb.disabled = false; hm.textContent = 'Keine Verbindung. Bitte versuche es noch einmal.'; });
			}); }
			var rb = document.getElementById('st-rate');
			if (rb) {
				var stars = [].slice.call(rb.querySelectorAll('.st-star'));
				function paint(n) { stars.forEach(function (s, i) { s.classList.toggle('is-on', i < n); }); }
				stars.forEach(function (s) {
					s.addEventListener('mouseenter', function () { if (!s.disabled) { paint(+s.dataset.rate); } });
					s.addEventListener('focus', function () { if (!s.disabled) { paint(+s.dataset.rate); } });
					s.addEventListener('click', function () {
						var n = +s.dataset.rate; stars.forEach(function (x) { x.disabled = true; }); paint(n);
						post('rate_driver', { rating: n }).then(function (r) {
							document.getElementById('st-ratemsg').textContent = r.ok ? 'Danke dir! Zeus freut sich.' : (r.error || 'Das hat nicht geklappt.'); document.getElementById('st-zeus').hidden = !r.ok;
							if (!r.ok) { stars.forEach(function (x) { x.disabled = false; }); paint(0); }
						}).catch(function () { document.getElementById('st-ratemsg').textContent = 'Keine Verbindung. Bitte versuche es noch einmal.'; stars.forEach(function (x) { x.disabled = false; }); paint(0); });
					});
				});
				rb.addEventListener('mouseleave', function () { if (!stars[0].disabled) { paint(0); } });
			}
			var sw = document.getElementById('st-switch');
			if (sw) {
				var swOpen = document.getElementById('st-sw-open'), swGo = document.getElementById('st-sw-go'), swMsg = document.getElementById('st-sw-msg');
				if (swOpen) { swOpen.addEventListener('click', function () { var f = document.getElementById('st-sw-form'); f.hidden = !f.hidden; swOpen.setAttribute('aria-expanded', f.hidden ? 'false' : 'true'); if (!f.hidden) { document.getElementById('st-sw-street').focus(); } }); }
				swGo.addEventListener('click', function () {
					var to = sw.dataset.to, body = { op: 'switch_mode', token: TOKEN, order: t, to: to };
					if (to === 'delivery') { body.street = document.getElementById('st-sw-street').value; body.zip = document.getElementById('st-sw-zip').value; body.city = document.getElementById('st-sw-city').value; body.note = document.getElementById('st-sw-note').value; }
					swGo.disabled = true; swMsg.textContent = ' Einen Moment ...';
					fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body) }).then(function (r) { return r.json(); }).then(function (r) {
						if (r.ok) { location.reload(); } else { swMsg.textContent = ' ' + (r.error || 'Das hat nicht geklappt.'); swGo.disabled = false; } }).catch(function () { swMsg.textContent = ' Das hat nicht geklappt.'; swGo.disabled = false; });
				});
			}
			Array.prototype.forEach.call(document.querySelectorAll('.st-pay-sw'), function (b) {
				b.addEventListener('click', function () {
					var m = document.getElementById('st-repay-msg'); Array.prototype.forEach.call(document.querySelectorAll('.st-pay-sw'), function (x) { x.disabled = true; }); m.textContent = ' Einen Moment ...';
					fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'switch_pay', token: TOKEN, order: t, payment: b.dataset.pay }) }).then(function (r) { return r.json(); }).then(function (r) {
						if (r.ok || r.paid) { location.reload(); } else { m.textContent = ' ' + (r.error || 'Das hat nicht geklappt.'); Array.prototype.forEach.call(document.querySelectorAll('.st-pay-sw'), function (x) { x.disabled = false; }); } }).catch(function () { m.textContent = ' Das hat nicht geklappt.'; Array.prototype.forEach.call(document.querySelectorAll('.st-pay-sw'), function (x) { x.disabled = false; }); });
				});
			});
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
