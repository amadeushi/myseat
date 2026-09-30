<?php
/*
 * Page for the driver, opened from a link the dispatch gives him (order/driver.php?t=<order token>&k=<key>): address, phone, what to
 * collect, "Losfahren" (starts the trip and shares the position with the guest's map), "Zugestellt". The key in the link is bound to
 * this order; without it nothing is shown. The phone must stay awake and the page open while the position is shared.
 */
require __DIR__.'/bootstrap.inc.php';
header('Cache-Control: no-store');
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$o = shop_driver_order(isset($_GET['t']) ? (string)$_GET['t'] : '', isset($_GET['k']) ? (string)$_GET['k'] : '');
$items = $o ? shop_order_items((int)$o['id']) : array();
$addr = $o ? trim($o['street'].', '.$o['zip'].' '.$o['city']) : '';
// a what3words-sourced order has no real street for Google Maps to search - it does have the
// coordinate the code resolved to (shop_find_zone_w3w()), which is what the driver actually needs
$isW3w = $o && strpos($o['street'], 'what3words: ') === 0;
$routeDest = ($isW3w && $o['lat'] !== null && $o['lng'] !== null) ? $o['lat'].','.$o['lng'] : $addr;
$pay = $o ? ($o['payment_method'] === 'mollie' || $o['payment_status'] === 'paid' ? 'Bezahlt, nichts zu kassieren' : (($o['payment_method'] === 'cash' ? 'BAR kassieren: ' : 'KARTE kassieren: ').shop_money($o['total_cents']))) : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
	<meta name="color-scheme" content="dark"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Lieferung &ndash; <?php echo shop_h($brand); ?></title>
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link rel="stylesheet" href="shop.css?v=<?php echo @filemtime(__DIR__.'/shop.css'); ?>"/>
</head>
<body class="shop-shell dv" data-token="<?php echo shop_h($_SESSION['shop_token']); ?>">
<main class="st-wrap">
<?php if (!$o): ?>
	<h1 class="st-title">Link ungültig</h1>
	<p class="st-lead">Dieser Link gehört zu keiner Lieferung. Bitte lass dir in der Disposition einen neuen schicken.</p>
<?php else: ?>
	<h1 class="st-title">Lieferung #<?php echo (int)$o['day_no']; ?></h1>
	<p class="st-lead" id="dv-state"></p>

	<div class="st-box dv-addr">
		<h2>Adresse</h2>
		<p class="dv-big"><?php echo shop_h($addr); ?></p>
		<?php if ($o['address_note'] !== ''): ?><p class="cart-opts"><?php echo shop_h($o['address_note']); ?></p><?php endif; ?>
		<p class="dv-links"><a class="cart-go dv-alt" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?php echo rawurlencode($routeDest); ?>">Route öffnen</a>
			<a class="cart-go dv-alt" href="tel:<?php echo shop_h(preg_replace('/[^0-9+]/', '', $o['phone'])); ?>"><?php echo shop_h($o['customer_name']); ?> anrufen</a></p>
	</div>

	<div class="st-box">
		<h2>Kassieren</h2>
		<p class="dv-big"><?php echo shop_h($pay); ?></p>
	</div>

	<div class="st-box">
		<h2>Bestellung</h2>
		<?php foreach ($items as $it): ?><p><?php echo (int)$it['qty']; ?>× <?php echo shop_h($it['title']); ?></p><?php endforeach; ?>
		<?php if ($o['note'] !== ''): ?><p class="cart-opts">Anmerkung: <?php echo shop_h($o['note']); ?></p><?php endif; ?>
	</div>

	<div class="dv-actions">
		<button type="button" class="cart-go" id="dv-go" hidden>Losfahren und Standort teilen</button>
		<button type="button" class="cart-go" id="dv-share" hidden>Standort teilen</button>
		<button type="button" class="cart-go dv-done" id="dv-done" hidden>Zugestellt</button>
		<p class="co-why" id="dv-msg" role="status" aria-live="polite"></p>
		<p class="st-hint">Lass den Bildschirm an und diese Seite offen, solange du unterwegs bist, damit der Gast dich auf der Karte sieht.</p>
	</div>
	<script>
	(function () {
		var TOKEN = document.body.dataset.token, T = <?php echo json_encode($o['token']); ?>, K = <?php echo json_encode(isset($_GET['k']) ? (string)$_GET['k'] : ''); ?>;
		var status = <?php echo json_encode($o['status']); ?>, watch = null, lastSent = 0, wake = null;
		function $(s) { return document.querySelector(s); }
		function post(op, data) {
			data = data || {}; data.op = op; data.token = TOKEN; data.order = T; data.key = K;
			return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(data) }).then(function (r) { return r.json(); });
		}
		function show() {
			var st = { ready: 'Die Bestellung ist fertig, du kannst losfahren.', delivering: 'Du bist unterwegs.', done: 'Erledigt, danke!' }[status] || 'Die Bestellung ist noch nicht fertig.';
			$('#dv-state').textContent = st;
			$('#dv-go').hidden = status !== 'ready'; $('#dv-share').hidden = !(status === 'delivering' && watch === null); $('#dv-done').hidden = status !== 'delivering';
		}
		function say(t) { $('#dv-msg').textContent = t || ''; }
		function send(pos) {
			var now = Date.now(); if (now - lastSent < 8000) { return; } lastSent = now;
			post('driver_pos', { lat: pos.coords.latitude, lng: pos.coords.longitude }).then(function (r) { say(r.ok ? 'Standort geteilt.' : (r.error || '')); }).catch(function () { say('Keine Verbindung, ich versuche es weiter.'); });
		}
		function startShare() {
			if (!navigator.geolocation) { say('Dieses Gerät kann keinen Standort teilen.'); return; }
			if (watch !== null) { return; }
			watch = navigator.geolocation.watchPosition(send, function (e) { watch = null; say(e.code === 1 ? 'Bitte erlaube den Standortzugriff im Browser.' : 'Standort nicht verfügbar.'); show(); }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
			if (navigator.wakeLock) { navigator.wakeLock.request('screen').then(function (l) { wake = l; }).catch(function () {}); }
			show();
		}
		function stopShare() { if (watch !== null) { navigator.geolocation.clearWatch(watch); watch = null; } if (wake) { wake.release().catch(function () {}); wake = null; } }
		$('#dv-go').addEventListener('click', function () { post('driver_go').then(function (r) { if (r.ok) { status = 'delivering'; startShare(); show(); } else { say(r.error); } }); });
		$('#dv-share').addEventListener('click', startShare);
		$('#dv-done').addEventListener('click', function () {
			var b = this; if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = 'Wirklich zugestellt?'; setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Zugestellt'; }, 4000); return; }
			post('driver_done').then(function (r) { if (r.ok) { status = 'done'; stopShare(); say(''); show(); } else { say(r.error); } });
		});
		document.addEventListener('visibilitychange', function () { if (!document.hidden && watch !== null && navigator.wakeLock) { navigator.wakeLock.request('screen').then(function (l) { wake = l; }).catch(function () {}); } });
		show();
	})();
	</script>
<?php endif; ?>
</main>
</body>
</html>
