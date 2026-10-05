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
$sh_suburbs = shop_suburbs_list(); $sh_seen = shop_suburbs_seen();
$sh_hours = fb_rows("SELECT kind, weekday, begins, ends FROM ".fb_t('tp_shop_hours')." ORDER BY kind, weekday, begins");
$sh_days = array('Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So');
$sh_by = array('delivery' => array(), 'pickup' => array());
foreach ($sh_hours as $h) { $sh_by[$h['kind']][(int)$h['weekday']][] = array(substr($h['begins'], 0, 5), substr($h['ends'], 0, 5)); }
$sh_win = function ($b, $e) use ($sh_e) {
	return '<span class="hr-win"><input type="time" step="300" value="'.$sh_e($b).'" aria-label="Von"/> <span>bis</span> <input type="time" step="300" value="'.$sh_e($e).'" aria-label="Bis"/> <button type="button" class="offer-delete hr-x" aria-label="Zeitfenster entfernen" title="Zeitfenster entfernen">&times;</button></span>';
};
$sh_ex = fb_rows("SELECT id, date_from, date_to, kind, closed, yearly, label, windows FROM ".fb_t('tp_shop_hours_ex')." ORDER BY date_from, id");
$sh_ex_now = array(); $sh_ex_past = array();
foreach ($sh_ex as $x) { if (!$x['yearly'] && $x['date_to'] < date('Y-m-d')) { $sh_ex_past[] = $x; } else { $sh_ex_now[] = $x; } }
$sh_ex_row = function ($x) use ($sh_e) {
	$d = function ($v) { return date('d.m.Y', strtotime($v)); };
	$when = $x['date_from'] === $x['date_to'] ? $d($x['date_from']) : $d($x['date_from']).' bis '.$d($x['date_to']);
	if ($x['yearly']) { $when = date('d.m.', strtotime($x['date_from'])).($x['date_from'] === $x['date_to'] ? '' : ' bis '.date('d.m.', strtotime($x['date_to']))).' jedes Jahr'; }
	$what = $x['closed'] ? 'geschlossen' : implode(', ', array_map(function ($w) { return $w[0].' bis '.$w[1]; }, (array)json_decode($x['windows'], true)));
	$kind = $x['kind'] === 'delivery' ? 'nur Lieferung' : ($x['kind'] === 'pickup' ? 'nur Abholung' : 'Lieferung und Abholung');
	$js = $sh_e(json_encode(array('id' => (int)$x['id'], 'date_from' => $x['date_from'], 'date_to' => $x['date_to'], 'kind' => $x['kind'], 'closed' => (int)$x['closed'], 'yearly' => (int)$x['yearly'], 'label' => $x['label'], 'windows' => (array)json_decode($x['windows'], true))));
	return '<tr><td>'.$sh_e($when).'</td><td>'.$sh_e($x['label']).'</td><td>'.$sh_e($kind).'</td><td class="'.($x['closed'] ? 'shop-closed' : '').'">'.$sh_e($what).'</td><td class="ex-act"><button type="button" class="me-mini" data-ex-edit="'.$js.'">Ändern</button> <button type="button" class="offer-delete" data-ex-del="'.(int)$x['id'].'">Löschen</button></td></tr>';
};
$sh_mollie = shop_mollie_info();
$sh_google = shop_google_key_info();
$sh_w3w = shop_w3w_key_info();
$sh_sipgate_key = require(__DIR__.'/../../config/sipgate_webhook_key.php');
$sh_webhook_url = shop_site_url().'/order/sipgate_webhook.php?key='.rawurlencode($sh_sipgate_key);
$sh_check = function ($k) use ($sh) { return $sh[$k] === '1' ? ' checked' : ''; };
?>
<div class="sms-page shop-page" data-endpoint="ajax/shop_admin.php" data-token="<?php echo $sh_e($token); ?>">
	<p class="offers-intro">Der Lieferservice nimmt Bestellungen für Lieferung und Abholung an, die Disposition und die Küche sehen sie auf ihren Monitoren. Die Bestellseite ist unter <code>/order/</code> erreichbar. Solange nichts freigegeben ist, sehen Gäste nichts. <a href="preview_link.php" target="_blank" rel="noopener">Bestellseite als Mitarbeiter ansehen (Vorschau)</a></p>

	<div class="sms-status">
		<span class="offer-badge<?php echo $sh['public'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['public'] === '1' ? 'Seite ist sichtbar' : 'Seite ist nicht sichtbar'; ?></span>
		<span class="offer-badge<?php echo $sh['accepting'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['accepting'] === '1' ? 'Bestellungen werden angenommen' : 'Bestellungen aus'; ?></span>
		<span class="sms-keyinfo"><?php echo (int)$sh_cats; ?> Kategorien, <?php echo (int)$sh_prods; ?> Gerichte</span>
	</div>

	<form class="sms-form" id="shop-form" autocomplete="off">
		<label class="offer-check"><input type="checkbox" name="public" value="1"<?php echo $sh_check('public'); ?>/> Bestellseite für Gäste sichtbar</label>
		<small class="offer-help">Ohne Haken sehen Gäste „Bestellen kommt bald“. Du selbst siehst die Seite trotzdem, solange du im Backend angemeldet bist (Vorschau für Mitarbeiter). Zum Prüfen ein privates Fenster ohne Anmeldung öffnen.</small>
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
			<label class="offer-label" for="sh-drive">Fahrzeit der Lieferung für den Küchenbildschirm (Minuten)</label>
			<input type="number" id="sh-drive" name="kitchen_drive_min" min="0" max="60" value="<?php echo $sh_e($sh['kitchen_drive_min']); ?>"/>
			<label class="offer-label" for="sh-lead">Vorlauf für Abholung (Minuten)</label>
			<input type="number" id="sh-lead" name="lead_pickup_min" min="0" max="240" value="<?php echo $sh_e($sh['lead_pickup_min']); ?>"/>
			<label class="offer-label" for="sh-last">Letzte Bestellung vor Ende der Bestellzeit (Minuten, 0 = bis Ladenschluss)</label>
			<input type="number" id="sh-last" name="last_order_min" min="0" max="240" value="<?php echo $sh_e($sh['last_order_min']); ?>"/>
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
		<small class="offer-help">Von dieser Adresse gehen die Bestätigungen an Gäste, und hierhin kommt eine Mail bei jeder neuen Bestellung. Bleibt das Feld leer, nimmt der Shop die E-Mail-Adresse aus den Stammdaten.</small>
		<input type="email" id="sh-mail" name="notify_email" maxlength="160" value="<?php echo $sh_e($sh['notify_email']); ?>"/>
		<label class="offer-check"><input type="checkbox" name="sms_orders" value="1"<?php echo $sh_check('sms_orders'); ?>/> SMS an den Gast, wenn die Lieferung losfährt oder die Abholung bereit ist (nur bei Handynummer und eingerichtetem SMS-Versand)</label>

		<h4 class="sms-sub">Stempelkarte</h4>
		<small class="offer-help">Jede abgeschlossene Bestellung eines Gastes mit Kundenkonto ist ein Stempel (ohne Kundenkonto gibt es keinen, siehe Kundenkonto unten). Ist die Karte voll, bekommt der Gast einen persönlichen Gutschein in Höhe des eingestellten Anteils der Warenwerte dieser Bestellungen. Er wird bei der nächsten passenden Bestellung automatisch und komplett abgezogen. Er gilt ab einem Warenwert in Höhe des Gutscheins, einen Rest gibt es nicht. Die Gutscheine sehen Sie unter Gutscheine (Code STEMPEL-...). Ein Gutschein bleibt gültig, auch wenn Sie die Stempelkarte ausschalten.</small>
		<label class="offer-check"><input type="checkbox" name="feedback_on" value="1"<?php echo $sh_check('feedback_on'); ?>/> Feedback-Mail nach Bestellungen (zwei Stunden nach Abschluss, nur mit E-Mail-Adresse)</label>
		<label class="offer-check"><input type="checkbox" name="places_suggest" value="1"<?php echo $sh_check('places_suggest'); ?>/> Straßenvorschläge in der Kasse beim Tippen (Google Places, braucht den Google-Schlüssel unten; jede Eingabe geht an Google)</label>
		<label class="offer-check"><input type="checkbox" name="stamp_on" value="1"<?php echo $sh_check('stamp_on'); ?>/> Stempelkarte anbieten</label>
		<div class="shop-grid">
			<label class="offer-label" for="sh-sp">Gutschein in Prozent der Warenwerte</label>
			<input type="text" id="sh-sp" name="stamp_percent" inputmode="numeric" value="<?php echo $sh_e($sh['stamp_percent']); ?>"/>
			<label class="offer-label" for="sh-sg">Stempel pro Karte</label>
			<input type="text" id="sh-sg" name="stamp_goal" inputmode="numeric" value="<?php echo $sh_e($sh['stamp_goal']); ?>"/>
			<label class="offer-label" for="sh-sm">Stempel gültig (Monate)</label>
			<input type="text" id="sh-sm" name="stamp_months" inputmode="numeric" value="<?php echo $sh_e($sh['stamp_months']); ?>"/>
			<label class="offer-label" for="sh-sd">Gutschein gültig (Tage)</label>
			<input type="text" id="sh-sd" name="voucher_days" inputmode="numeric" value="<?php echo $sh_e($sh['voucher_days']); ?>"/>
		</div>

		<h4 class="sms-sub">Kundenkonto</h4>
		<small class="offer-help">Gäste melden sich im Shop ohne Passwort mit E-Mail-Adresse oder Handynummer an: Sie bekommen einen 6-stelligen Code und einen Link (bei SMS ein Kurzlink über YOURLS, wenn dort "Kurzlink" eingerichtet ist). Danach sehen sie ihre früheren Bestellungen und können sie nachbestellen, speichern Lieblingsgerichte und sehen ihre Stempelkarte. Das Konto ist die bestätigte Nummer oder Adresse, die Bestellungen werden darüber gefunden. Der Anmelde-Code per SMS braucht eingerichteten SMS-Versand, per Mail die E-Mail-Adresse oben.</small>
		<label class="offer-check"><input type="checkbox" name="account_on" value="1"<?php echo $sh_check('account_on'); ?>/> Kundenkonto anbieten</label>
		<label class="offer-check"><input type="checkbox" name="account_sms" value="1"<?php echo $sh_check('account_sms'); ?>/> Anmelde-Code auch per SMS schicken (sonst nur per E-Mail)</label>
		<div class="shop-grid">
			<label class="offer-label" for="sh-asd">Anmelde-SMS pro Tag (höchstens)</label>
			<input type="text" id="sh-asd" name="account_sms_daily" inputmode="numeric" value="<?php echo $sh_e($sh['account_sms_daily']); ?>"/>
		</div>

		<h4 class="sms-sub">Weg der Fahrer</h4>
		<small class="offer-help">Die Fahrerkarte der Disposition zeigt die Spur, die ein Fahrer gefahren ist. Die Punkte werden so viele Tage aufbewahrt und danach gelöscht. 0 heißt: Es wird nichts gespeichert, die Karte zeigt dann nur die aktuelle Position. Bitte informiert die Fahrer darüber.</small>
		<div class="shop-grid">
			<label class="offer-label" for="sh-trk">Aufbewahrung in Tagen (0 bis 30)</label>
			<input type="text" id="sh-trk" name="track_days" inputmode="numeric" value="<?php echo $sh_e($sh['track_days']); ?>"/>
		</div>

		<h4 class="sms-sub">Standort des Restaurants</h4>
		<small class="offer-help">Für die Karte auf der Statusseite: Gäste sehen, wo wir sind und wohin geliefert wird. Ohne Adresse zeigt die Karte nur das Ziel, und die Fahrer sehen keine Entfernung.</small>
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
	<?php $sh_pu = shop_places_usage(); $sh_pn = function ($n) { return number_format($n, 0, ',', '.'); }; ?>
	<p class="offer-help" id="places-usage">Straßenvorschläge der Kasse (Google Places): heute <strong><?php echo $sh_pn($sh_pu['today']['suggest']); ?></strong> Abfragen, <strong><?php echo $sh_pn($sh_pu['today']['pick']); ?></strong> gewählt &middot; dieser Monat <strong><?php echo $sh_pn($sh_pu['month']['suggest']); ?></strong> Abfragen, <strong><?php echo $sh_pn($sh_pu['month']['pick']); ?></strong> gewählt &middot; letzter Monat <?php echo $sh_pn($sh_pu['last']['suggest']); ?> / <?php echo $sh_pn($sh_pu['last']['pick']); ?>. Gezählt werden die Anfragen, die tatsächlich an Google gingen; ein Tageslimit setzt du in der Google-Cloud-Konsole bei der Places API.</p>
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

	<h4 class="sms-sub">Sipgate-Anrufererkennung</h4>
	<p class="offer-help">Zeigt bei "Bestellung erfassen" automatisch die Nummer eines eingehenden Anrufs an. Trage die Webhook-URL unten in deinem Sipgate-Konto unter sipgate.io für eingehende Anrufe ein. Die vollständige URL enthält einen geheimen Schlüssel. Benutzername und Passwort sind nicht erforderlich. Gib die URL nicht weiter.</p>
	<p class="offer-help">Webhook-URL: <code><?php echo $sh_e($sh_webhook_url); ?></code></p>

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
			<input type="text" name="phone" value="<?php echo $sh_e($d['phone']); ?>" maxlength="40" placeholder="Telefon (für die Disposition)" aria-label="Telefon des Fahrers"/>
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
			<input type="text" name="phone" value="" maxlength="40" placeholder="Telefon (für die Disposition)" aria-label="Telefon des Fahrers"/>
			<label class="offer-check"><input type="checkbox" name="active" value="1" checked/> aktiv</label>
			<button type="submit" class="button_dark">Fahrer anlegen</button>
			<span class="detail-status" role="status" aria-live="polite"></span>
		</form>
	</div>

	<h4 class="sms-sub">Stadtteile für die Fahrer</h4>
	<p class="offer-help">Auf der Fahrerseite steht bei jeder Lieferung der Stadtteil und die Strecke vom Restaurant. Den Stadtteil liefert OpenStreetMap zur Adresse. Wenn er dort anders heißt, als ihr ihn nennt (zum Beispiel „Altstadt“ statt „Stadtmitte“), oder falsch ist, könnt ihr es hier korrigieren. Die Reihenfolge der Regeln: erst die Straße, dann die PLZ, dann der Name von OpenStreetMap. Eine Änderung gilt sofort, auch für bestehende Bestellungen. Die Strecke wird nach dem Standort des Restaurants berechnet (Straße, PLZ und Ort oben unter „Standort des Restaurants“).</p>
	<?php if ($sh_seen): ?>
	<p class="offer-help">Bei den Lieferungen der letzten 90 Tage kennt OpenStreetMap diese Stadtteile (Klick legt eine Umbenennung an):
		<?php foreach ($sh_seen as $x): ?><button type="button" class="offer-badge" data-suburb-seen="<?php echo $sh_e($x['name']); ?>"><?php echo $sh_e($x['name']); ?> (<?php echo (int)$x['n']; ?>)</button> <?php endforeach; ?></p>
	<?php endif; ?>
	<div class="shop-zones" id="shop-suburbs">
		<?php foreach (array_merge($sh_suburbs, array(array('id' => 0, 'kind' => 'name', 'pattern' => '', 'suburb' => ''))) as $r): ?>
		<form class="shop-zone" data-id="<?php echo (int)$r['id']; ?>">
			<select name="kind" aria-label="Art der Regel">
				<option value="name"<?php echo $r['kind'] === 'name' ? ' selected' : ''; ?>>OpenStreetMap-Name</option>
				<option value="zip"<?php echo $r['kind'] === 'zip' ? ' selected' : ''; ?>>PLZ</option>
				<option value="street"<?php echo $r['kind'] === 'street' ? ' selected' : ''; ?>>Straße</option>
			</select>
			<input type="text" name="pattern" value="<?php echo $sh_e($r['pattern']); ?>" maxlength="120" placeholder="zum Beispiel Altstadt, 31141 oder Goschenstraße" aria-label="Wenn die Adresse so heißt"/>
			<input type="text" name="suburb" value="<?php echo $sh_e($r['suburb']); ?>" maxlength="80" placeholder="anzeigen als, zum Beispiel Stadtmitte" aria-label="Stadtteil anzeigen als"/>
			<button type="submit" class="button_dark"><?php echo $r['id'] ? 'Speichern' : 'Regel anlegen'; ?></button>
			<?php if ($r['id']): ?><button type="button" class="offer-delete" data-suburb-delete="<?php echo (int)$r['id']; ?>">Löschen</button><?php endif; ?>
			<span class="detail-status" role="status" aria-live="polite"></span>
		</form>
		<?php endforeach; ?>
	</div>

	<h4 class="sms-sub">Bestellzeiten</h4>
	<p class="offer-help">Wann Gäste Lieferung und Abholung bestellen können. Jeder Tag kann mehrere Zeitfenster haben (zum Beispiel Mittag und Abend), ein Tag ohne Zeitfenster ist zu. Über Mitternacht bitte in zwei Zeilen eintragen: bis 23:59 und am nächsten Tag ab 00:00. Die Zeiten gelten sofort nach dem Speichern.</p>
	<?php if (!$sh_hours): ?>
		<p class="offer-help"><strong>Noch keine Zeiten eingetragen.</strong> Solange nichts eingetragen ist, ist die Bestellseite für Lieferung und Abholung geschlossen.</p>
	<?php endif; ?>
	<form id="hours-form" class="shop-hours-form">
		<?php foreach (array('delivery' => 'Lieferung', 'pickup' => 'Abholung') as $kind => $label): ?>
		<fieldset class="hr-kind" data-kind="<?php echo $kind; ?>">
			<legend><?php echo $label; ?></legend>
			<?php for ($d = 0; $d < 7; $d++): ?>
			<div class="hr-row" data-day="<?php echo $d; ?>">
				<span class="hr-day"><?php echo $sh_days[$d]; ?></span>
				<span class="hr-wins"><?php foreach (isset($sh_by[$kind][$d]) ? $sh_by[$kind][$d] : array() as $w) { echo $sh_win($w[0], $w[1]); } ?></span>
				<span class="shop-closed hr-closed">zu</span>
				<span class="hr-tools"><button type="button" class="hr-add">+ Zeitfenster</button><button type="button" class="hr-copy" title="Die Zeiten dieses Tages für alle anderen Tage übernehmen (gespeichert wird erst mit dem Knopf unten)">Auf alle Tage</button></span>
			</div>
			<?php endfor; ?>
		</fieldset>
		<?php endforeach; ?>
		<template id="hours-win"><?php echo $sh_win('11:00', '14:00'); ?></template>
		<p class="offer-actions"><button type="submit" class="button_dark">Bestellzeiten speichern</button> <span class="detail-status" id="hours-msg" role="status" aria-live="polite"></span></p>
	</form>

	<h4 class="sms-sub">Feiertage, Ruhetage und abweichende Zeiten</h4>
	<p class="offer-help">Ein Eintrag gilt an diesem Tag oder in diesem Zeitraum anstelle der Wochenzeiten oben: entweder ganz geschlossen (Ruhetag, Betriebsferien, Feiertag) oder mit eigenen Zeiten (zum Beispiel Heiligabend nur bis 14:00). Gilt für einen Eintrag nur Lieferung oder nur Abholung, geht er dem für beide vor, bei mehreren Einträgen der kürzere Zeitraum. Gäste können für diese Tage nicht bestellen, auch nicht im Voraus.</p>
	<?php if ($sh_ex_now): ?>
	<table class="shop-hours shop-ex"><thead><tr><th>Wann</th><th>Bezeichnung</th><th>Betrifft</th><th>Dann</th><th></th></tr></thead><tbody><?php foreach ($sh_ex_now as $x) { echo $sh_ex_row($x); } ?></tbody></table>
	<?php else: ?><p class="offer-help">Noch keine Ausnahmen eingetragen.</p><?php endif; ?>
	<?php if ($sh_ex_past): ?>
	<details class="shop-ex-past"><summary>Vergangene Ausnahmen (<?php echo count($sh_ex_past); ?>)</summary>
	<table class="shop-hours shop-ex"><tbody><?php foreach (array_reverse($sh_ex_past) as $x) { echo $sh_ex_row($x); } ?></tbody></table></details>
	<?php endif; ?>
	<form id="ex-form" class="shop-ex-form" autocomplete="off">
		<input type="hidden" name="id" value="0"/>
		<h5 class="ex-title" id="ex-title">Neue Ausnahme</h5>
		<div class="ex-grid">
			<label>Von <input type="date" name="date_from" required/></label>
			<label>Bis (leer = nur dieser Tag) <input type="date" name="date_to"/></label>
			<label>Betrifft <select name="kind"><option value="all">Lieferung und Abholung</option><option value="delivery">nur Lieferung</option><option value="pickup">nur Abholung</option></select></label>
			<label>Bezeichnung (sieht der Gast) <input type="text" name="label" maxlength="80" placeholder="z. B. Heiligabend, Betriebsferien"/></label>
		</div>
		<div class="ex-mode" role="radiogroup" aria-label="Dann">
			<label class="offer-check"><input type="radio" name="closed" value="1" checked/> Geschlossen</label>
			<label class="offer-check"><input type="radio" name="closed" value="0"/> Andere Zeiten</label>
			<label class="offer-check"><input type="checkbox" name="yearly" value="1"/> jedes Jahr wiederholen</label>
		</div>
		<div class="hr-row ex-wins" id="ex-wins" hidden><span class="hr-wins"></span><span class="shop-closed hr-closed">kein Zeitfenster</span><span class="hr-tools"><button type="button" class="hr-add">+ Zeitfenster</button></span></div>
		<p class="offer-actions"><button type="submit" class="button_dark">Ausnahme speichern</button> <button type="button" class="me-mini" id="ex-cancel" hidden>Abbrechen</button> <span class="detail-status" id="ex-msg" role="status" aria-live="polite"></span></p>
	</form>
	<div class="shop-ex-holidays">
		<span>Gesetzliche Feiertage Niedersachsen eintragen für</span>
		<select id="ex-year" aria-label="Jahr"><?php for ($yy = (int)date('Y'); $yy <= (int)date('Y') + 2; $yy++) { echo '<option value="'.$yy.'">'.$yy.'</option>'; } ?></select>
		<button type="button" class="me-mini" id="ex-holidays">Eintragen</button>
		<small class="offer-help">Sie werden als "geschlossen" angelegt. Wo ihr geöffnet habt, ändere oder lösche den Eintrag.</small>
	</div>
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
			post('save_driver', { id: f.dataset.id, name: f.elements.name.value, device_id: f.elements.device_id.value, phone: f.elements.phone.value, active: f.elements.active.checked ? '1' : '' },
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
	// Stadtteil-Regeln für die Fahrerseite: id 0 legt eine neue an
	Array.prototype.forEach.call(document.querySelectorAll('#shop-suburbs .shop-zone'), function (f) {
		var out = f.querySelector('.detail-status');
		f.addEventListener('submit', function (ev) {
			ev.preventDefault();
			post('save_suburb', { id: f.dataset.id, kind: f.elements.kind.value, pattern: f.elements.pattern.value, suburb: f.elements.suburb.value },
				function (r) { say(out, r.message, false); setTimeout(function () { location.reload(); }, 600); }, function (e) { say(out, e, true); });
		});
		var del = f.querySelector('[data-suburb-delete]');
		if (del) {
			del.addEventListener('click', function () {
				if (del.dataset.armed) { post('delete_suburb', { id: del.dataset.suburbDelete }, function () { location.reload(); }, function (e) { say(out, e, true); }); }
				else { del.dataset.armed = '1'; del.textContent = 'Wirklich löschen?'; setTimeout(function () { del.dataset.armed = ''; del.textContent = 'Löschen'; }, 4000); }
			});
		}
	});
	Array.prototype.forEach.call(document.querySelectorAll('[data-suburb-seen]'), function (b) {
		b.addEventListener('click', function () {
			var f = document.querySelector('#shop-suburbs .shop-zone[data-id="0"]');
			f.elements.kind.value = 'name'; f.elements.pattern.value = b.dataset.suburbSeen; f.elements.suburb.value = b.dataset.suburbSeen; f.elements.suburb.focus(); f.elements.suburb.select();
		});
	});
	// opening times: windows are added/removed in the page, nothing is stored until "Bestellzeiten speichern"
	var hform = document.getElementById('hours-form'), hmsg = document.getElementById('hours-msg');
	function hoursSync(row) { row.querySelector('.hr-closed').hidden = row.querySelectorAll('.hr-win').length > 0; }
	function hoursAdd(row, b, e) {
		var t = document.getElementById('hours-win').content.firstElementChild.cloneNode(true), ins = t.querySelectorAll('input');
		if (b) { ins[0].value = b; ins[1].value = e; }
		row.querySelector('.hr-wins').appendChild(t); hoursSync(row); return t;
	}
	hform.querySelectorAll('.hr-row').forEach(hoursSync);
	hform.addEventListener('click', function (ev) {
		var row = ev.target.closest('.hr-row'); if (!row) { return; }
		if (ev.target.closest('.hr-x')) { ev.target.closest('.hr-win').remove(); hoursSync(row); return; }
		if (ev.target.closest('.hr-add')) { hoursAdd(row).querySelector('input').focus(); return; }
		if (ev.target.closest('.hr-copy')) {
			var src = [].map.call(row.querySelectorAll('.hr-win'), function (w) { var i = w.querySelectorAll('input'); return [i[0].value, i[1].value]; });
			row.closest('.hr-kind').querySelectorAll('.hr-row').forEach(function (r) {
				if (r === row) { return; }
				r.querySelectorAll('.hr-win').forEach(function (w) { w.remove(); });
				src.forEach(function (w) { hoursAdd(r, w[0], w[1]); }); hoursSync(r);
			});
			say(hmsg, 'Übernommen, noch nicht gespeichert.', false);
		}
	});
	hform.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var data = {};
		hform.querySelectorAll('.hr-kind').forEach(function (k) {
			data[k.dataset.kind] = {};
			k.querySelectorAll('.hr-row').forEach(function (r) { data[k.dataset.kind][r.dataset.day] = [].map.call(r.querySelectorAll('.hr-win'), function (w) { var i = w.querySelectorAll('input'); return [i[0].value, i[1].value]; }); });
		});
		say(hmsg, 'Einen Moment ...', false);
		post('save_hours', { hours: JSON.stringify(data) }, function (r) { say(hmsg, r.message, false); setTimeout(function () { location.reload(); }, 900); }, function (e) { say(hmsg, e, true); });
	});
	// exceptions: one form for new and for changing; the windows reuse the helpers of the weekly form above
	var xform = document.getElementById('ex-form'), xmsg = document.getElementById('ex-msg'), xrow = document.getElementById('ex-wins');
	function xMode() { xrow.hidden = xform.elements.closed.value === '1'; }
	function xReset() { xform.reset(); xform.elements.id.value = '0'; xrow.querySelectorAll('.hr-win').forEach(function (w) { w.remove(); }); hoursSync(xrow); xMode(); document.getElementById('ex-title').textContent = 'Neue Ausnahme'; document.getElementById('ex-cancel').hidden = true; }
	xform.addEventListener('change', function (ev) { if (ev.target.name === 'closed') { xMode(); if (xform.elements.closed.value === '0' && !xrow.querySelector('.hr-win')) { hoursAdd(xrow); } } });
	xrow.addEventListener('click', function (ev) {
		if (ev.target.closest('.hr-x')) { ev.target.closest('.hr-win').remove(); hoursSync(xrow); return; }
		if (ev.target.closest('.hr-add')) { hoursAdd(xrow).querySelector('input').focus(); }
	});
	document.getElementById('ex-cancel').addEventListener('click', xReset);
	document.querySelectorAll('[data-ex-edit]').forEach(function (b) {
		b.addEventListener('click', function () {
			var x = JSON.parse(b.dataset.exEdit); xReset();
			xform.elements.id.value = x.id; xform.elements.date_from.value = x.date_from; xform.elements.date_to.value = x.date_to === x.date_from ? '' : x.date_to;
			xform.elements.kind.value = x.kind; xform.elements.label.value = x.label; xform.elements.yearly.checked = !!x.yearly;
			xform.elements.closed.value = x.closed ? '1' : '0'; (x.windows || []).forEach(function (w) { hoursAdd(xrow, w[0], w[1]); }); xMode();
			document.getElementById('ex-title').textContent = 'Ausnahme ändern'; document.getElementById('ex-cancel').hidden = false;
			xform.scrollIntoView({ block: 'center' }); xform.elements.label.focus();
		});
	});
	document.querySelectorAll('[data-ex-del]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (b.dataset.armed) { post('delete_exception', { id: b.dataset.exDel }, function () { location.reload(); }, function (e) { say(xmsg, e, true); }); }
			else { b.dataset.armed = '1'; b.textContent = 'Wirklich löschen?'; setTimeout(function () { b.dataset.armed = ''; b.textContent = 'Löschen'; }, 4000); }
		});
	});
	xform.addEventListener('submit', function (ev) {
		ev.preventDefault();
		var e = xform.elements, d = { id: +e.id.value || 0, date_from: e.date_from.value, date_to: e.date_to.value, kind: e.kind.value, label: e.label.value, closed: e.closed.value === '1' ? 1 : 0, yearly: e.yearly.checked ? 1 : 0,
			windows: [].map.call(xrow.querySelectorAll('.hr-win'), function (w) { var i = w.querySelectorAll('input'); return [i[0].value, i[1].value]; }) };
		say(xmsg, 'Einen Moment ...', false);
		post('save_exception', { data: JSON.stringify(d) }, function (r) { say(xmsg, r.message, false); setTimeout(function () { location.reload(); }, 700); }, function (er) { say(xmsg, er, true); });
	});
	document.getElementById('ex-holidays').addEventListener('click', function () {
		post('add_holidays', { year: document.getElementById('ex-year').value }, function (r) { say(xmsg, r.message, false); setTimeout(function () { location.reload(); }, 1400); }, function (er) { say(xmsg, er, true); });
	});
	xMode();
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
