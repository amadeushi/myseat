<?php
// Settings > Lieferservice: switches, values, Mollie, delivery zones, opening hours (the menu is edited on page p=10)
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
$sh_e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$sh = array();
foreach (array_keys(shop_defaults()) as $k) { $sh[$k] = shop_setting($k); }
$sh_count = function ($t) { $r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t($t)); return $r ? (int)$r['n'] : 0; };
$sh_cats = $sh_count('tp_shop_categories'); $sh_prods = $sh_count('tp_shop_products');
$sh_zones = fb_rows("SELECT id, name, fee_cents, min_order_cents, active FROM ".fb_t('tp_shop_zones')." ORDER BY id");
$sh_hours = fb_rows("SELECT kind, weekday, begins, ends FROM ".fb_t('tp_shop_hours')." ORDER BY kind, weekday, begins");
$sh_days = array('Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So');
$sh_by = array('delivery' => array(), 'pickup' => array());
foreach ($sh_hours as $h) { $sh_by[$h['kind']][(int)$h['weekday']][] = substr($h['begins'], 0, 5).' bis '.substr($h['ends'], 0, 5); }
$sh_mollie = shop_mollie_info();
$sh_check = function ($k) use ($sh) { return $sh[$k] === '1' ? ' checked' : ''; };
?>
<div class="sms-page shop-page" data-endpoint="ajax/shop_admin.php" data-token="<?php echo $sh_e($token); ?>">
	<p class="offers-intro">Der Lieferservice nimmt Bestellungen für Lieferung und Abholung an, die Disposition und die Küche sehen sie auf ihren Monitoren. Die Bestellseite ist unter <code>/order/</code> erreichbar. Solange nichts freigegeben ist, sehen Gäste nichts.</p>

	<div class="sms-status">
		<span class="offer-badge<?php echo $sh['public'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['public'] === '1' ? 'Seite ist sichtbar' : 'Seite ist nicht sichtbar'; ?></span>
		<span class="offer-badge<?php echo $sh['accepting'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['accepting'] === '1' ? 'Bestellungen werden angenommen' : 'Bestellungen aus'; ?></span>
		<span class="sms-keyinfo"><?php echo (int)$sh_cats; ?> Kategorien, <?php echo (int)$sh_prods; ?> Gerichte</span>
	</div>

	<form class="sms-form" id="shop-form" autocomplete="off">
		<label class="offer-check"><input type="checkbox" name="public" value="1"<?php echo $sh_check('public'); ?>/> Bestellseite für Gäste sichtbar</label>
		<label class="offer-check"><input type="checkbox" name="accepting" value="1"<?php echo $sh_check('accepting'); ?>/> Bestellungen annehmen (sonst nur ansehen)</label>
		<label class="offer-check"><input type="checkbox" name="test_mode" value="1"<?php echo $sh_check('test_mode'); ?>/> Testmodus: Bestellungen sind Tests, keine Mails, löschbar</label>

		<h4 class="sms-sub">Zahlung</h4>
		<label class="offer-check"><input type="checkbox" name="allow_online" value="1"<?php echo $sh_check('allow_online'); ?>/> Online bezahlen (Mollie: Karte, PayPal, Apple Pay ...)</label>
		<label class="offer-check"><input type="checkbox" name="allow_cash" value="1"<?php echo $sh_check('allow_cash'); ?>/> Bar bei Lieferung oder Abholung</label>
		<label class="offer-check"><input type="checkbox" name="allow_card_door" value="1"<?php echo $sh_check('allow_card_door'); ?>/> Kartenzahlung bei Lieferung oder Abholung</label>
		<label class="offer-check"><input type="checkbox" name="tip_enabled" value="1"<?php echo $sh_check('tip_enabled'); ?>/> Trinkgeld anbieten</label>

		<h4 class="sms-sub">Zeiten und Mindestbestellwert</h4>
		<div class="shop-grid">
			<label class="offer-label" for="sh-eta">Lieferzeit für "so schnell wie möglich" (Minuten)</label>
			<input type="number" id="sh-eta" name="eta_delivery_min" min="10" max="240" value="<?php echo $sh_e($sh['eta_delivery_min']); ?>"/>
			<label class="offer-label" for="sh-lead">Vorlauf für Abholung (Minuten)</label>
			<input type="number" id="sh-lead" name="lead_pickup_min" min="0" max="240" value="<?php echo $sh_e($sh['lead_pickup_min']); ?>"/>
			<label class="offer-label" for="sh-slot">Zeitfenster in Schritten von (Minuten)</label>
			<input type="number" id="sh-slot" name="slot_min" min="5" max="60" value="<?php echo $sh_e($sh['slot_min']); ?>"/>
			<label class="offer-label" for="sh-days">Vorbestellung bis zu (Tage im Voraus, 0 = nur heute)</label>
			<input type="number" id="sh-days" name="days_ahead" min="0" max="14" value="<?php echo $sh_e($sh['days_ahead']); ?>"/>
			<label class="offer-label" for="sh-mind">Mindestbestellwert Lieferung (€)</label>
			<input type="text" id="sh-mind" name="min_order_delivery" inputmode="decimal" value="<?php echo $sh_e($sh['min_order_delivery']); ?>"/>
			<label class="offer-label" for="sh-minp">Mindestbestellwert Abholung (€)</label>
			<input type="text" id="sh-minp" name="min_order_pickup" inputmode="decimal" value="<?php echo $sh_e($sh['min_order_pickup']); ?>"/>
		</div>
		<label class="offer-label" for="sh-mail">E-Mail-Adresse für Bestellungen</label>
		<small class="offer-help">Von dieser Adresse gehen die Bestätigungen an Gäste, und hierhin kommt eine Mail bei jeder neuen Bestellung. Ohne Adresse werden keine Mails verschickt.</small>
		<input type="email" id="sh-mail" name="notify_email" maxlength="160" value="<?php echo $sh_e($sh['notify_email']); ?>"/>
		<label class="offer-check"><input type="checkbox" name="sms_orders" value="1"<?php echo $sh_check('sms_orders'); ?>/> SMS an den Gast, wenn die Lieferung losfährt oder die Abholung bereit ist (nur bei Handynummer und eingerichtetem SMS-Versand)</label>

		<h4 class="sms-sub">Standort des Restaurants</h4>
		<small class="offer-help">Für die Karte auf der Statusseite: Gäste sehen, wo wir sind und wohin geliefert wird. Ohne Adresse zeigt die Karte nur das Ziel.</small>
		<div class="shop-grid">
			<label class="offer-label" for="sh-ost">Straße und Hausnummer</label>
			<input type="text" id="sh-ost" name="origin_street" maxlength="120" value="<?php echo $sh_e($sh['origin_street']); ?>"/>
			<label class="offer-label" for="sh-ozip">PLZ</label>
			<input type="text" id="sh-ozip" name="origin_zip" maxlength="10" value="<?php echo $sh_e($sh['origin_zip']); ?>"/>
			<label class="offer-label" for="sh-ocity">Ort</label>
			<input type="text" id="sh-ocity" name="origin_city" maxlength="80" value="<?php echo $sh_e($sh['origin_city']); ?>"/>
		</div>

		<label class="offer-label" for="sh-notice">Hinweis oben auf der Bestellseite (optional)</label>
		<small class="offer-help">Zum Beispiel "Heute Lieferzeit etwa 60 Minuten". Bleibt leer, wenn nichts zu sagen ist.</small>
		<input type="text" id="sh-notice" name="notice" maxlength="200" value="<?php echo $sh_e($sh['notice']); ?>"/>

		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<span class="detail-status" id="shop-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<h4 class="sms-sub">Mollie (Online-Zahlung)</h4>
	<p class="offer-help">Der Schlüssel steht im Mollie-Dashboard unter Entwickler, API-Schlüssel. Beginnt er mit <code>test_</code>, läuft alles im Testmodus, ohne echtes Geld. Er wird verschlüsselt gespeichert und nie wieder angezeigt, nur die letzten 4 Zeichen. Damit Mollie die Zahlung melden kann, muss die Seite von außen erreichbar sein (https).</p>
	<div class="sms-status">
		<?php if ($sh_mollie['set']): ?><span class="offer-badge sms-badge-on"><?php echo $sh_mollie['mode'] === 'live' ? 'Live-Schlüssel' : 'Testschlüssel'; ?> hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_mollie['masked']); ?></span>
		<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
	</div>
	<form class="sms-form" id="mollie-form" autocomplete="off">
		<input type="password" name="mollie_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_mollie['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'test_… oder live_… einfügen'; ?>"/>
		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<?php if ($sh_mollie['set']): ?><button type="button" class="button_dark" id="mollie-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="mollie-clear">Schlüssel löschen</button><?php endif; ?>
			<span class="detail-status" id="mollie-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<h4 class="sms-sub">Speisekarte</h4>
	<p class="offer-help">Kategorien, Gerichte und Zubehörgruppen (Größen, Beilagen, Extras) pflegst du im Speisekarten-Editor.</p>
	<p class="offer-actions"><a class="button_dark" href="main_page.php?p=10">Speisekarte bearbeiten</a></p>

	<h4 class="sms-sub">Liefergebiete</h4>
	<?php if (!$sh_zones): ?>
		<p class="offer-help">Noch keine Liefergebiete. Sie kommen mit der Übernahme aus Resmio.</p>
	<?php else: ?>
	<div class="shop-zones">
		<?php foreach ($sh_zones as $z): ?>
		<form class="shop-zone" data-id="<?php echo (int)$z['id']; ?>">
			<input type="text" name="name" value="<?php echo $sh_e($z['name']); ?>" maxlength="80" aria-label="Name"/>
			<label>Liefergebühr <input type="text" name="fee" inputmode="decimal" value="<?php echo number_format($z['fee_cents'] / 100, 2, ',', ''); ?>"/> €</label>
			<label>Mindestbestellwert <input type="text" name="min" inputmode="decimal" value="<?php echo number_format($z['min_order_cents'] / 100, 2, ',', ''); ?>"/> €</label>
			<label class="offer-check"><input type="checkbox" name="active" value="1"<?php echo $z['active'] ? ' checked' : ''; ?>/> aktiv</label>
			<button type="submit" class="button_dark">Speichern</button>
			<span class="detail-status" role="status" aria-live="polite"></span>
		</form>
		<?php endforeach; ?>
	</div>
	<p class="offer-help">Die Gebiete sind als Flächen auf der Karte hinterlegt. Ob eine Adresse liegt, prüft die Bestellseite über die Koordinaten der Adresse.</p>
	<?php endif; ?>

	<h4 class="sms-sub">Bestellzeiten</h4>
	<?php if (!$sh_hours): ?>
		<p class="offer-help">Noch keine Zeiten. Sie kommen mit der Übernahme aus Resmio.</p>
	<?php else: ?>
	<table class="shop-hours">
		<thead><tr><th></th><?php foreach ($sh_days as $d): ?><th><?php echo $d; ?></th><?php endforeach; ?></tr></thead>
		<tbody>
		<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $kind => $label): ?>
			<tr><th><?php echo $label; ?></th>
			<?php for ($d = 0; $d < 7; $d++): ?><td><?php echo isset($sh_by[$kind][$d]) ? $sh_e(implode(', ', $sh_by[$kind][$d])) : '<span class="shop-closed">zu</span>'; ?></td><?php endfor; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p class="offer-help">Aus Resmio übernommen. Prüf die Wochentage einmal gegen deine echten Zeiten.</p>
	<?php endif; ?>
</div>
<script>
window.addEventListener('load', function () {
	var page = document.querySelector('.shop-page'); if (!page) { return; }
	function post(op, data, ok, fail) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', page.dataset.token);
		for (var k in data) { if (Object.prototype.hasOwnProperty.call(data, k)) { fd.append(k, data[k]); } }
		fetch(page.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { fail(r.error || 'Das hat nicht geklappt.'); return; }
			ok(r);
		}).catch(function () { fail('Das hat nicht geklappt. Bitte versuche es noch einmal.'); });
	}
	function say(el, text, isError) { el.textContent = text; el.classList.toggle('is-error', !!isError); }
	var form = document.getElementById('shop-form'), msg = document.getElementById('shop-msg');
	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var d = {}; Array.prototype.forEach.call(form.elements, function (e) { if (!e.name) { return; } if (e.type === 'checkbox') { d[e.name] = e.checked ? '1' : ''; } else { d[e.name] = e.value; } });
		say(msg, 'Einen Moment ...', false);
		post('save', d, function (r) { say(msg, r.message, false); setTimeout(function () { location.reload(); }, 600); }, function (e) { say(msg, e, true); });
	});
	Array.prototype.forEach.call(document.querySelectorAll('.shop-zone'), function (f) {
		var out = f.querySelector('.detail-status');
		f.addEventListener('submit', function (ev) {
			ev.preventDefault();
			post('zone', { id: f.dataset.id, name: f.elements.name.value, fee: f.elements.fee.value, min: f.elements.min.value, active: f.elements.active.checked ? '1' : '' },
				function (r) { say(out, r.message, false); }, function (e) { say(out, e, true); });
		});
	});
	var mform = document.getElementById('mollie-form'), mmsg = document.getElementById('mollie-msg');
	mform.addEventListener('submit', function (ev) { ev.preventDefault(); say(mmsg, 'Einen Moment ...', false); post('save_mollie', { mollie_key: mform.elements.mollie_key.value }, function (r) { say(mmsg, r.message, false); setTimeout(function () { location.reload(); }, 700); }, function (e) { say(mmsg, e, true); }); });
	var mt = document.getElementById('mollie-test');
	if (mt) { mt.addEventListener('click', function () { say(mmsg, 'Einen Moment ...', false); post('test_mollie', {}, function (r) { say(mmsg, r.message, false); }, function (e) { say(mmsg, e, true); }); }); }
	var mc = document.getElementById('mollie-clear');
	if (mc) { mc.addEventListener('click', function () { if (mc.dataset.armed) { post('clear_mollie', {}, function () { location.reload(); }, function (e) { say(mmsg, e, true); }); } else { mc.dataset.armed = '1'; mc.textContent = 'Wirklich löschen?'; setTimeout(function () { mc.dataset.armed = ''; mc.textContent = 'Schlüssel löschen'; }, 4000); } }); }
});
</script>
