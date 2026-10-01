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
$sh_drivers = shop_drivers_list();
$sh_hours = fb_rows("SELECT kind, weekday, begins, ends FROM ".fb_t('tp_shop_hours')." ORDER BY kind, weekday, begins");
$sh_days = array('Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So');
$sh_by = array('delivery' => array(), 'pickup' => array());
foreach ($sh_hours as $h) { $sh_by[$h['kind']][(int)$h['weekday']][] = substr($h['begins'], 0, 5).' bis '.substr($h['ends'], 0, 5); }
$sh_mollie = shop_mollie_info();
$sh_google = shop_google_key_info();
$sh_w3w = shop_w3w_key_info();
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

	<h4 class="sms-sub">Google Geocoding (Adress-Fallback)</h4>
	<p class="offer-help">Wird nur benutzt, wenn OpenStreetMap eine eingegebene Lieferadresse nicht findet - kostenlose Adressen werden dadurch nie kostenpflichtig. Schlüssel im Google-Cloud-Konsole unter APIs &amp; Dienste, Anmeldedaten (Geocoding API aktivieren). Wird verschlüsselt gespeichert, nie wieder angezeigt, nur die letzten 4 Zeichen.</p>
	<div class="sms-status">
		<?php if ($sh_google['set']): ?><span class="offer-badge sms-badge-on">Schlüssel hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_google['masked']); ?></span>
		<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
	</div>
	<form class="sms-form" id="google-key-form" autocomplete="off">
		<input type="password" name="google_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_google['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'API-Schlüssel einfügen'; ?>"/>
		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<?php if ($sh_google['set']): ?><button type="button" class="button_dark" id="google-key-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="google-key-clear">Schlüssel löschen</button><?php endif; ?>
			<span class="detail-status" id="google-key-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<h4 class="sms-sub">what3words (Adressen ohne Straße)</h4>
	<p class="offer-help">Lässt Gäste ohne richtige Adresse (Feld, Veranstaltungsort) einen what3words-Code statt Straße/PLZ/Ort eingeben - erscheint nur, wenn eine eingegebene Adresse gar nicht gefunden wird. Schlüssel unter <a href="https://what3words.com/select-plan" target="_blank" rel="noopener">what3words.com/select-plan</a>. Wird verschlüsselt gespeichert, nie wieder angezeigt, nur die letzten 4 Zeichen.</p>
	<div class="sms-status">
		<?php if ($sh_w3w['set']): ?><span class="offer-badge sms-badge-on">Schlüssel hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_w3w['masked']); ?></span>
		<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
	</div>
	<form class="sms-form" id="w3w-key-form" autocomplete="off">
		<input type="password" name="w3w_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_w3w['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'API-Schlüssel einfügen'; ?>"/>
		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<?php if ($sh_w3w['set']): ?><button type="button" class="button_dark" id="w3w-key-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="w3w-key-clear">Schlüssel löschen</button><?php endif; ?>
			<span class="detail-status" id="w3w-key-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<h4 class="sms-sub">Speisekarte</h4>
	<p class="offer-help">Kategorien, Gerichte und Zubehörgruppen (Größen, Beilagen, Extras) pflegst du im Speisekarten-Editor.</p>
	<p class="offer-actions"><a class="button_dark" href="main_page.php?p=10">Speisekarte bearbeiten</a></p>

	<h4 class="sms-sub">Liefergebiete</h4>
	<?php if (!$sh_zones): ?>
		<p class="offer-help">Noch keine Liefergebiete. Zeichne sie im Liefergebiete-Editor auf der Karte.</p>
	<?php else: ?>
	<div class="shop-zones" id="shop-zones-list">
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
	<p class="offer-actions"><a class="button_dark" href="main_page.php?p=11">Liefergebiete-Editor öffnen (Formen zeichnen, anlegen, löschen)</a></p>

	<h4 class="sms-sub">Fahrer</h4>
	<p class="offer-help">Jeder Fahrer trägt in seiner Traccar-App eine selbst gewählte Geräte-ID ein (Server-URL: diese Domain + <code>/order/driver_gps.php</code>, Protokoll: OsmAnd). Hier wird die Geräte-ID einmalig einem Namen zugeordnet; sein eigener Link lautet dann <code>/order/driver.php?device=&lt;Geräte-ID&gt;</code>, zum Speichern auf dem Homescreen.</p>
	<div class="shop-zones" id="shop-drivers">
		<?php foreach ($sh_drivers as $d): ?>
		<form class="shop-zone" data-id="<?php echo (int)$d['id']; ?>">
			<input type="text" name="name" value="<?php echo $sh_e($d['name']); ?>" maxlength="80" placeholder="Name" aria-label="Name"/>
			<input type="text" name="device_id" value="<?php echo $sh_e($d['device_id']); ?>" maxlength="64" placeholder="Geräte-ID" aria-label="Geräte-ID"/>
			<label class="offer-check"><input type="checkbox" name="active" value="1"<?php echo $d['active'] ? ' checked' : ''; ?>/> aktiv</label>
			<span class="offer-badge<?php echo ($d['last_seen_min'] !== null && $d['last_seen_min'] <= 10) ? ' sms-badge-on' : ''; ?>">
				<?php if ($d['last_seen_min'] === null): ?>Noch nie gesehen - Geräte-ID in der Traccar-App prüfen
				<?php elseif ($d['last_seen_min'] <= 10): ?>Live (zuletzt vor <?php echo (int)$d['last_seen_min']; ?> Min)
				<?php else: ?>Zuletzt vor <?php echo (int)$d['last_seen_min']; ?> Min - App prüft nicht mehr?
				<?php endif; ?>
			</span>
			<button type="submit" class="button_dark">Speichern</button>
			<button type="button" class="offer-delete" data-driver-delete="<?php echo (int)$d['id']; ?>">Löschen</button>
			<span class="detail-status" role="status" aria-live="polite"></span>
		</form>
		<?php endforeach; ?>
		<form class="shop-zone" data-id="0">
			<input type="text" name="name" value="" maxlength="80" placeholder="Name" aria-label="Name"/>
			<input type="text" name="device_id" value="" maxlength="64" placeholder="Geräte-ID" aria-label="Geräte-ID"/>
			<label class="offer-check"><input type="checkbox" name="active" value="1" checked/> aktiv</label>
			<button type="submit" class="button_dark">Fahrer anlegen</button>
			<span class="detail-status" role="status" aria-live="polite"></span>
		</form>
	</div>

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
	Array.prototype.forEach.call(document.querySelectorAll('#shop-zones-list .shop-zone'), function (f) {
		var out = f.querySelector('.detail-status');
		f.addEventListener('submit', function (ev) {
			ev.preventDefault();
			post('zone', { id: f.dataset.id, name: f.elements.name.value, fee: f.elements.fee.value, min: f.elements.min.value, active: f.elements.active.checked ? '1' : '' },
				function (r) { say(out, r.message, false); }, function (e) { say(out, e, true); });
		});
	});
	// Fahrer: Name/Geräte-ID/aktiv speichert mit id=0 einen neuen Fahrer an, sonst aktualisiert es den bestehenden
	Array.prototype.forEach.call(document.querySelectorAll('#shop-drivers .shop-zone'), function (f) {
		var out = f.querySelector('.detail-status');
		f.addEventListener('submit', function (ev) {
			ev.preventDefault();
			post('save_driver', { id: f.dataset.id, name: f.elements.name.value, device_id: f.elements.device_id.value, active: f.elements.active.checked ? '1' : '' },
				function (r) { say(out, r.message, false); setTimeout(function () { location.reload(); }, 600); }, function (e) { say(out, e, true); });
		});
		var del = f.querySelector('[data-driver-delete]');
		if (del) {
			del.addEventListener('click', function () {
				if (del.dataset.armed) { post('delete_driver', { id: del.dataset.driverDelete }, function () { location.reload(); }, function (e) { say(out, e, true); }); }
				else { del.dataset.armed = '1'; del.textContent = 'Wirklich löschen?'; setTimeout(function () { del.dataset.armed = ''; del.textContent = 'Löschen'; }, 4000); }
			});
		}
	});
	var mform = document.getElementById('mollie-form'), mmsg = document.getElementById('mollie-msg');
	mform.addEventListener('submit', function (ev) { ev.preventDefault(); say(mmsg, 'Einen Moment ...', false); post('save_mollie', { mollie_key: mform.elements.mollie_key.value }, function (r) { say(mmsg, r.message, false); setTimeout(function () { location.reload(); }, 700); }, function (e) { say(mmsg, e, true); }); });
	var mt = document.getElementById('mollie-test');
	if (mt) { mt.addEventListener('click', function () { say(mmsg, 'Einen Moment ...', false); post('test_mollie', {}, function (r) { say(mmsg, r.message, false); }, function (e) { say(mmsg, e, true); }); }); }
	var mc = document.getElementById('mollie-clear');
	if (mc) { mc.addEventListener('click', function () { if (mc.dataset.armed) { post('clear_mollie', {}, function () { location.reload(); }, function (e) { say(mmsg, e, true); }); } else { mc.dataset.armed = '1'; mc.textContent = 'Wirklich löschen?'; setTimeout(function () { mc.dataset.armed = ''; mc.textContent = 'Schlüssel löschen'; }, 4000); } }); }

	// same save/clear pattern as Mollie above, for the two delivery-zone geocoding keys
	function wireKeyForm(formId, msgId, clearId, saveOp, clearOp, field, clearLabel) {
		var f = document.getElementById(formId), m = document.getElementById(msgId); if (!f) { return; }
		f.addEventListener('submit', function (ev) { ev.preventDefault(); say(m, 'Einen Moment ...', false); post(saveOp, ((function () { var d = {}; d[field] = f.elements[field].value; return d; })()), function (r) { say(m, r.message, false); setTimeout(function () { location.reload(); }, 700); }, function (e) { say(m, e, true); }); });
		var c = document.getElementById(clearId);
		if (c) { c.addEventListener('click', function () { if (c.dataset.armed) { post(clearOp, {}, function () { location.reload(); }, function (e) { say(m, e, true); }); } else { c.dataset.armed = '1'; c.textContent = 'Wirklich löschen?'; setTimeout(function () { c.dataset.armed = ''; c.textContent = clearLabel; }, 4000); } }); }
	}
	wireKeyForm('google-key-form', 'google-key-msg', 'google-key-clear', 'save_google_key', 'clear_google_key', 'google_key', 'Schlüssel löschen');
	wireKeyForm('w3w-key-form', 'w3w-key-msg', 'w3w-key-clear', 'save_w3w_key', 'clear_w3w_key', 'w3w_key', 'Schlüssel löschen');
	var gt = document.getElementById('google-key-test'), gtmsg = document.getElementById('google-key-msg');
	if (gt) { gt.addEventListener('click', function () { say(gtmsg, 'Einen Moment ...', false); post('test_google_key', {}, function (r) { say(gtmsg, r.message, false); }, function (e) { say(gtmsg, e, true); }); }); }
	var wt = document.getElementById('w3w-key-test'), wtmsg = document.getElementById('w3w-key-msg');
	if (wt) { wt.addEventListener('click', function () { say(wtmsg, 'Einen Moment ...', false); post('test_w3w_key', {}, function (r) { say(wtmsg, r.message, false); }, function (e) { say(wtmsg, e, true); }); }); }
});
</script>
