<?php
// Settings > Lieferservice: switches, values, Mollie, delivery zones, opening hours (the menu is edited on page p=10)
require_once __DIR__.'/../classes/shop.class.php';
require_once __DIR__.'/../classes/shop_uber.class.php';
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

// helpers of the page: every field of the main form carries form="shop-form" (one "Einstellungen speichern" for all of them, wherever they stand); the cards with their own save button are separate forms
$F = ' form="shop-form"';
$sh_help = function ($short, $long = '') { echo '<p class="sh-help">'.$short.'</p>'; if ($long !== '') { echo '<details class="sh-more"><summary>Mehr dazu</summary><div>'.$long.'</div></details>'; } };
$sh_cb = function ($name, $label, $hint = '') use ($sh, $sh_e, $F) {
	echo '<label class="sh-check"><input type="checkbox" name="'.$name.'" value="1"'.($sh[$name] === '1' ? ' checked' : '').$F.'/><span><span class="sh-cb-l">'.$label.'</span>'.($hint !== '' ? '<span class="sh-hint">'.$hint.'</span>' : '').'</span></label>';
};
// a number with its unit next to the field; $attrs: ' type="number" min=".." max=".."' or '' (text with the number pad)
$sh_row = function ($id, $name, $label, $unit = '', $hint = '', $attrs = '', $mode = 'numeric') use ($sh, $sh_e, $F) {
	echo '<div class="sh-row"><div class="sh-text"><label class="sh-label" for="'.$id.'">'.$label.'</label>'.($hint !== '' ? '<p class="sh-hint">'.$hint.'</p>' : '').'</div><span class="sh-field"><input '.($attrs !== '' ? $attrs : 'type="text" inputmode="'.$mode.'"').' id="'.$id.'" name="'.$name.'" value="'.$sh_e($sh[$name]).'"'.$F.'/>'.($unit !== '' ? '<span class="sh-unit">'.$unit.'</span>' : '').'</span></div>';
};
// a wide field (address, mail, key): label and hint above, the field as wide as the column
$sh_wide = function ($id, $name, $label, $hint = '', $attrs = 'type="text"', $value = null, $extra = '') use ($sh, $sh_e, $F) {
	echo '<div class="sh-row sh-wide"><div class="sh-text"><label class="sh-label" for="'.$id.'">'.$label.'</label>'.($hint !== '' ? '<p class="sh-hint">'.$hint.'</p>' : '').'</div><input '.$attrs.' id="'.$id.'" name="'.$name.'" value="'.$sh_e($value === null ? $sh[$name] : $value).'"'.$extra.$F.'/></div>';
};
// a key or secret that is not shown all the time: masked, with "Anzeigen" and "Kopieren"
$sh_secret = function ($label, $value, $hint = '') use ($sh_e) {
	echo '<div class="sh-secret"><div class="sh-text"><span class="sh-label">'.$label.'</span>'.($hint !== '' ? '<p class="sh-hint">'.$hint.'</p>' : '').'</div><span class="sh-secret-box"><code class="sh-secret-val" data-value="'.$sh_e($value).'" aria-label="'.$sh_e($label).' (verborgen)">••••••••••••••••</code><button type="button" class="sh-btn" data-secret-show aria-pressed="false">Anzeigen</button><button type="button" class="sh-btn" data-secret-copy>Kopieren</button></span></div>';
};
?>
<div class="sms-page shop-page" data-endpoint="ajax/shop_admin.php" data-token="<?php echo $sh_e($token); ?>">
	<form id="shop-form" autocomplete="off"></form>

	<nav class="sh-nav" id="sh-nav" aria-label="Abschnitte des Lieferservice">
		<a href="#sh-allgemein">Allgemein</a><a href="#sh-zeiten">Zeiten</a><a href="#sh-zahlung">Zahlung</a><a href="#sh-gebiete">Gebiete und Adressen</a><a href="#sh-fahrer">Fahrer</a><a href="#sh-gaeste">Gäste</a><a href="#sh-anbindungen">Anbindungen</a>
	</nav>
	<p class="sh-scope">Felder ohne eigenen Speichern-Knopf sichert der Knopf <strong>„Einstellungen speichern“</strong> unten am Bildschirm. Bereiche mit eigenem Knopf (Mollie, Schlüssel, Zeiten, Gebiete, Fahrer) speichern für sich.</p>

	<section class="sh-group" id="sh-allgemein" aria-labelledby="sh-h-allgemein">
		<h3 id="sh-h-allgemein">Allgemein</h3>
		<?php $sh_help('Der Lieferservice nimmt Bestellungen für Lieferung und Abholung an. Die Bestellseite ist unter <code>/order/</code> erreichbar, Disposition und Küche sehen die Bestellungen auf ihren Monitoren.', 'Solange nichts freigegeben ist, sehen Gäste nichts. <a href="preview_link.php" target="_blank" rel="noopener">Bestellseite als Mitarbeiter ansehen (Vorschau)</a>'); ?>
		<div class="sms-status">
			<span class="offer-badge<?php echo $sh['public'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['public'] === '1' ? 'Seite ist sichtbar' : 'Seite ist nicht sichtbar'; ?></span>
			<span class="offer-badge<?php echo $sh['accepting'] === '1' ? ' sms-badge-on' : ''; ?>"><?php echo $sh['accepting'] === '1' ? 'Bestellungen werden angenommen' : 'Bestellungen aus'; ?></span>
			<span class="sms-keyinfo"><?php echo (int)$sh_cats; ?> Kategorien, <?php echo (int)$sh_prods; ?> Gerichte</span>
		</div>
		<?php $sh_cb('public', 'Bestellseite für Gäste sichtbar', 'Ohne Haken sehen Gäste „Bestellen kommt bald“. Als angemeldeter Mitarbeiter siehst du die Seite trotzdem (Vorschau); zum Prüfen ein privates Fenster ohne Anmeldung öffnen.'); ?>
		<?php $sh_cb('accepting', 'Bestellungen annehmen', 'Ohne Haken können Gäste die Seite nur ansehen.'); ?>
		<?php $sh_cb('test_mode', 'Testmodus', 'Bestellungen sind Tests: keine Mails, jederzeit löschbar.'); ?>
		<?php $sh_wide('sh-notice', 'notice', 'Hinweis oben auf der Bestellseite (optional)', 'Zum Beispiel „Heute Lieferzeit etwa 60 Minuten“. Leer lassen, wenn nichts zu sagen ist.', 'type="text" maxlength="200"'); ?>
		<h4 class="sh-sub">Speisekarte</h4>
		<?php $sh_help('Kategorien, Gerichte und Zubehörgruppen (Größen, Beilagen, Extras) pflegst du im Speisekarten-Editor.'); ?>
		<p class="offer-actions"><a class="button_dark" href="main_page.php?p=10">Speisekarte bearbeiten</a></p>
	</section>

	<section class="sh-group" id="sh-zeiten" aria-labelledby="sh-h-zeiten">
		<h3 id="sh-h-zeiten">Zeiten</h3>
		<h4 class="sh-sub">Lieferzeit und Vorlauf</h4>
		<?php $sh_row('sh-eta', 'eta_delivery_min', 'Lieferzeit bei „so schnell wie möglich“', 'Min.', 'Die Zeit, die der Gast beim Bestellen sieht.', 'type="number" min="10" max="240"'); ?>
		<?php $sh_row('sh-drive', 'kitchen_drive_min', 'Fahrzeit der Lieferung', 'Min.', 'Der Küchenbildschirm zieht sie von der Lieferzeit ab und zeigt, wann das Essen raus muss. Einzelne Lieferungen lassen sich in der Disposition anpassen.', 'type="number" min="0" max="60"'); ?>
		<?php $sh_row('sh-lead', 'lead_pickup_min', 'Vorlauf für Abholung', 'Min.', '', 'type="number" min="0" max="240"'); ?>
		<?php $sh_row('sh-last', 'last_order_min', 'Letzte Bestellung vor Ladenschluss', 'Min.', '0 = bis Ladenschluss.', 'type="number" min="0" max="240"'); ?>
		<?php $sh_row('sh-slot', 'slot_min', 'Zeitfenster in Schritten von', 'Min.', 'In diesen Schritten wählen Gäste eine Uhrzeit.', 'type="number" min="5" max="60"'); ?>
		<?php $sh_row('sh-days', 'days_ahead', 'Vorbestellung im Voraus', 'Tage', '0 = nur heute.', 'type="number" min="0" max="14"'); ?>

		<h4 class="sh-sub" id="sh-h-bestellzeiten">Bestellzeiten</h4>
		<?php $sh_help('Wann Gäste Lieferung und Abholung bestellen können. Ein Tag ohne Zeitfenster ist zu.', 'Jeder Tag kann mehrere Zeitfenster haben (zum Beispiel Mittag und Abend). Über Mitternacht bitte in zwei Zeilen eintragen: bis 23:59 und am nächsten Tag ab 00:00. Die Zeiten gelten sofort nach dem Speichern.'); ?>
		<?php if (!$sh_hours): ?>
			<p class="sh-warn"><strong>Noch keine Zeiten eingetragen.</strong> Solange nichts eingetragen ist, ist die Bestellseite für Lieferung und Abholung geschlossen.</p>
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

		<h4 class="sh-sub">Feiertage, Ruhetage und abweichende Zeiten</h4>
		<?php $sh_help('Ein Eintrag gilt an diesem Tag oder in diesem Zeitraum anstelle der Wochenzeiten: ganz geschlossen oder mit eigenen Zeiten. Gäste können für diese Tage nicht bestellen, auch nicht im Voraus.', 'Gilt ein Eintrag nur für Lieferung oder nur für Abholung, geht er dem für beide vor, bei mehreren Einträgen der kürzere Zeitraum. Beispiel für eigene Zeiten: Heiligabend nur bis 14:00.'); ?>
		<?php if ($sh_ex_now): ?>
		<table class="shop-hours shop-ex"><thead><tr><th>Wann</th><th>Bezeichnung</th><th>Betrifft</th><th>Dann</th><th><span class="sh-vh">Aktionen</span></th></tr></thead><tbody><?php foreach ($sh_ex_now as $x) { echo $sh_ex_row($x); } ?></tbody></table>
		<?php else: ?><p class="sh-help">Noch keine Ausnahmen eingetragen.</p><?php endif; ?>
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
			<p class="sh-hint">Sie werden als „geschlossen“ angelegt. Wo ihr geöffnet habt, ändere oder lösche den Eintrag.</p>
		</div>
	</section>

	<section class="sh-group" id="sh-zahlung" aria-labelledby="sh-h-zahlung">
		<h3 id="sh-h-zahlung">Zahlung und Preise</h3>
		<h4 class="sh-sub">Zahlungsarten</h4>
		<?php $sh_cb('allow_online', 'Online bezahlen', 'Über Mollie: Karte, PayPal, Apple Pay …'); ?>
		<?php $sh_cb('allow_cash', 'Bar bei Lieferung oder Abholung'); ?>
		<?php $sh_cb('allow_card_door', 'Kartenzahlung bei Lieferung oder Abholung'); ?>
		<?php $sh_cb('tip_enabled', 'Trinkgeld anbieten'); ?>
		<h4 class="sh-sub">Mindestbestellwert</h4>
		<?php $sh_row('sh-mind', 'min_order_delivery', 'Lieferung', '€', 'Je Liefergebiet lässt sich ein eigener Wert eintragen (unter Gebiete und Adressen).', '', 'decimal'); ?>
		<?php $sh_row('sh-minp', 'min_order_pickup', 'Abholung', '€', '', '', 'decimal'); ?>

		<h4 class="sh-sub">Mollie (Online-Zahlung)</h4>
		<?php $sh_help('Den Schlüssel findest du im Mollie-Dashboard unter Entwickler, API-Schlüssel. Beginnt er mit <code>test_</code>, läuft alles ohne echtes Geld.', 'Er wird verschlüsselt gespeichert und nie wieder angezeigt, nur die letzten 4 Zeichen. Damit Mollie die Zahlung melden kann, muss die Seite von außen erreichbar sein (https).'); ?>
		<div class="sh-own">
			<div class="sms-status">
				<?php if ($sh_mollie['set']): ?><span class="offer-badge sms-badge-on"><?php echo $sh_mollie['mode'] === 'live' ? 'Live-Schlüssel' : 'Testschlüssel'; ?> hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_mollie['masked']); ?></span>
				<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
			</div>
			<form class="sms-form" id="mollie-form" autocomplete="off">
				<label class="sh-label" for="sh-mollie">Mollie-Schlüssel</label>
				<input type="password" id="sh-mollie" name="mollie_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_mollie['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'test_… oder live_… einfügen'; ?>"/>
				<p class="offer-actions">
					<button type="submit" class="button_dark">Mollie-Schlüssel speichern</button>
					<?php if ($sh_mollie['set']): ?><button type="button" class="button_dark" id="mollie-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="mollie-clear">Schlüssel löschen</button><?php endif; ?>
					<span class="detail-status" id="mollie-msg" role="status" aria-live="polite"></span>
				</p>
			</form>
		</div>
	</section>

	<section class="sh-group" id="sh-gebiete" aria-labelledby="sh-h-gebiete">
		<h3 id="sh-h-gebiete">Gebiete und Adressen</h3>
		<h4 class="sh-sub">Liefergebiete</h4>
		<?php if (!$sh_zones): ?>
			<p class="sh-help">Noch keine Liefergebiete. Zeichne sie im Liefergebiete-Editor auf der Karte.</p>
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
		<?php $sh_help('Die Gebiete sind als Flächen auf der Karte hinterlegt. Ob eine Adresse liegt, prüft die Bestellseite über die Koordinaten der Adresse.'); ?>
		<?php endif; ?>
		<p class="offer-actions"><a class="button_dark" href="main_page.php?p=11">Liefergebiete-Editor öffnen (Formen zeichnen, anlegen, löschen)</a></p>

		<h4 class="sh-sub">Standort des Restaurants</h4>
		<?php $sh_help('Für die Karte auf der Statusseite: Gäste sehen, wo wir sind und wohin geliefert wird. Ohne Adresse zeigt die Karte nur das Ziel, und die Fahrer sehen keine Entfernung.'); ?>
		<?php $sh_wide('sh-ost', 'origin_street', 'Straße und Hausnummer', '', 'type="text" maxlength="120"'); ?>
		<?php $sh_row('sh-ozip', 'origin_zip', 'PLZ', '', '', 'type="text" maxlength="10" inputmode="numeric"'); ?>
		<?php $sh_row('sh-ocity', 'origin_city', 'Ort', '', '', 'type="text" maxlength="80"'); ?>

		<h4 class="sh-sub">Adressprüfung</h4>
		<?php $sh_help('Adressen prüft zuerst OpenStreetMap. Findet es eine Adresse nicht, helfen die zwei Dienste unten. Beide Schlüssel werden verschlüsselt gespeichert und nie wieder angezeigt, nur die letzten 4 Zeichen.'); ?>
		<h5 class="sh-sub2">Google Geocoding (Adress-Fallback)</h5>
		<?php $sh_help('Wird nur benutzt, wenn OpenStreetMap eine Lieferadresse nicht findet, kostenlose Adressen werden dadurch nie kostenpflichtig.', 'Den Schlüssel gibt es in der Google-Cloud-Konsole unter APIs &amp; Dienste, Anmeldedaten (Geocoding API aktivieren).'); ?>
		<div class="sh-own">
			<div class="sms-status">
				<?php if ($sh_google['set']): ?><span class="offer-badge sms-badge-on">Schlüssel hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_google['masked']); ?></span>
				<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
			</div>
			<form class="sms-form" id="google-key-form" autocomplete="off">
				<label class="sh-label" for="sh-google">Google-Schlüssel</label>
				<input type="password" id="sh-google" name="google_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_google['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'API-Schlüssel einfügen'; ?>"/>
				<p class="offer-actions">
					<button type="submit" class="button_dark">Google-Schlüssel speichern</button>
					<?php if ($sh_google['set']): ?><button type="button" class="button_dark" id="google-key-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="google-key-clear">Schlüssel löschen</button><?php endif; ?>
					<span class="detail-status" id="google-key-msg" role="status" aria-live="polite"></span>
				</p>
			</form>
		</div>
		<h5 class="sh-sub2">Straßenvorschläge beim Tippen</h5>
		<?php $sh_cb('places_suggest', 'Straßenvorschläge in der Kasse', 'Google Places, braucht den Google-Schlüssel oben. Jede Eingabe geht an Google.'); ?>
		<?php $sh_pu = shop_places_usage(); $sh_pn = function ($n) { return number_format($n, 0, ',', '.'); }; ?>
		<p class="sh-help" id="places-usage">Abfragen heute: <strong><?php echo $sh_pn($sh_pu['today']['suggest']); ?></strong>, gewählt <strong><?php echo $sh_pn($sh_pu['today']['pick']); ?></strong> &middot; dieser Monat: <strong><?php echo $sh_pn($sh_pu['month']['suggest']); ?></strong>, gewählt <strong><?php echo $sh_pn($sh_pu['month']['pick']); ?></strong> &middot; letzter Monat: <?php echo $sh_pn($sh_pu['last']['suggest']); ?>, gewählt <?php echo $sh_pn($sh_pu['last']['pick']); ?>. Gezählt werden nur Anfragen, die an Google gingen; ein Tageslimit setzt du in der Google-Cloud-Konsole bei der Places API.</p>
		<h5 class="sh-sub2">what3words (Adressen ohne Straße)</h5>
		<?php $sh_help('Lässt Gäste ohne richtige Adresse (Feld, Veranstaltungsort) einen what3words-Code eingeben. Erscheint nur, wenn eine Adresse gar nicht gefunden wird.', 'Den Schlüssel gibt es unter <a href="https://what3words.com/select-plan" target="_blank" rel="noopener">what3words.com/select-plan</a>.'); ?>
		<div class="sh-own">
			<div class="sms-status">
				<?php if ($sh_w3w['set']): ?><span class="offer-badge sms-badge-on">Schlüssel hinterlegt</span><span class="sms-keyinfo"><?php echo $sh_e($sh_w3w['masked']); ?></span>
				<?php else: ?><span class="offer-badge">Kein Schlüssel hinterlegt</span><?php endif; ?>
			</div>
			<form class="sms-form" id="w3w-key-form" autocomplete="off">
				<label class="sh-label" for="sh-w3w">what3words-Schlüssel</label>
				<input type="password" id="sh-w3w" name="w3w_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sh_w3w['set'] ? 'Neuen Schlüssel einfügen (optional)' : 'API-Schlüssel einfügen'; ?>"/>
				<p class="offer-actions">
					<button type="submit" class="button_dark">what3words-Schlüssel speichern</button>
					<?php if ($sh_w3w['set']): ?><button type="button" class="button_dark" id="w3w-key-test">Verbindung prüfen</button><button type="button" class="offer-delete" id="w3w-key-clear">Schlüssel löschen</button><?php endif; ?>
					<span class="detail-status" id="w3w-key-msg" role="status" aria-live="polite"></span>
				</p>
			</form>
		</div>
	</section>

	<section class="sh-group" id="sh-fahrer" aria-labelledby="sh-h-fahrer">
		<h3 id="sh-h-fahrer">Fahrer</h3>
		<h4 class="sh-sub">Fahrer</h4>
		<?php $sh_help('Jeder Fahrer trägt in seiner Traccar-App eine selbst gewählte Geräte-ID ein. Hier wird sie einmalig einem Namen zugeordnet.', 'Server-URL in der App: diese Domain + <code>/order/driver_gps.php</code>, Protokoll: OsmAnd. Der eigene Link des Fahrers lautet <code>/order/driver.php?device=&lt;Geräte-ID&gt;</code>, zum Speichern auf dem Homescreen.'); ?>
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

		<h4 class="sh-sub">Stadtteile für die Fahrer</h4>
		<?php $sh_help('Auf der Fahrerseite steht bei jeder Lieferung der Stadtteil und die Strecke vom Restaurant. Heißt ein Stadtteil bei OpenStreetMap anders als bei euch, korrigierst du ihn hier.', 'Beispiel: „Altstadt“ statt „Stadtmitte“. Die Reihenfolge der Regeln: erst die Straße, dann die PLZ, dann der Name von OpenStreetMap. Eine Änderung gilt sofort, auch für bestehende Bestellungen. Die Strecke wird nach dem Standort des Restaurants berechnet (siehe Gebiete und Adressen).'); ?>
		<?php if ($sh_seen): ?>
		<p class="sh-help">Diese Stadtteile kennt OpenStreetMap bei den Lieferungen der letzten 90 Tage (ein Klick legt eine Umbenennung an):
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

		<h4 class="sh-sub">Weg der Fahrer</h4>
		<?php $sh_help('Die Fahrerkarte zeigt die Spur, die ein Fahrer gefahren ist. Die Punkte werden so viele Tage aufbewahrt und danach gelöscht.', '0 heißt: Es wird nichts gespeichert, die Karte zeigt dann nur die aktuelle Position. Bitte informiert die Fahrer darüber.'); ?>
		<?php $sh_row('sh-trk', 'track_days', 'Aufbewahrung', 'Tage', '0 bis 30.'); ?>

		<h4 class="sh-sub">Touren <span class="sh-chip">in Erprobung</span></h4>
		<?php $sh_help('Die Disposition fasst Lieferungen, die zusammen aus der Küche gehen, zu einer Tour zusammen (Tour A und B).', 'Der Küchenmonitor zeigt sie in einem gemeinsamen Rahmen mit einer gemeinsamen Zeit, auf dem Bon steht die Tour. Ausgeschaltet bleibt alles wie bisher.'); ?>
		<?php $sh_cb('tours_on', 'Touren einschalten'); ?>
	</section>

	<section class="sh-group" id="sh-gaeste" aria-labelledby="sh-h-gaeste">
		<h3 id="sh-h-gaeste">Gäste</h3>
		<h4 class="sh-sub">Nachrichten an Gäste</h4>
		<?php $sh_wide('sh-mail', 'notify_email', 'E-Mail-Adresse für Bestellungen', 'Von dieser Adresse gehen die Bestätigungen an Gäste, und hierhin kommt eine Mail bei jeder neuen Bestellung. Leer: Der Shop nimmt die E-Mail-Adresse aus den Stammdaten.', 'type="email" maxlength="160"'); ?>
		<?php $sh_cb('sms_orders', 'SMS, wenn die Lieferung losfährt oder die Abholung bereit ist', 'Braucht eine Handynummer und eingerichteten SMS-Versand.'); ?>
		<?php $sh_cb('feedback_on', 'Feedback-Mail nach der Bestellung', 'Zwei Stunden nach Abschluss, nur mit E-Mail-Adresse.'); ?>

		<h4 class="sh-sub">Kundenkonto</h4>
		<?php $sh_help('Gäste melden sich ohne Passwort mit E-Mail-Adresse oder Handynummer an, mit einem 6-stelligen Code. Danach sehen sie ihre früheren Bestellungen, können sie nachbestellen und sehen ihre Stempelkarte.', 'Sie bekommen einen Code und einen Link (bei SMS ein Kurzlink über YOURLS, wenn dort „Kurzlink“ eingerichtet ist). Das Konto ist die bestätigte Nummer oder Adresse, die Bestellungen werden darüber gefunden. Der Code per SMS braucht eingerichteten SMS-Versand, per Mail die E-Mail-Adresse oben.'); ?>
		<?php $sh_cb('account_on', 'Kundenkonto anbieten'); ?>
		<?php $sh_cb('account_sms', 'Anmelde-Code auch per SMS schicken', 'Sonst nur per E-Mail.'); ?>
		<?php $sh_row('sh-asd', 'account_sms_daily', 'Anmelde-SMS höchstens', 'pro Tag'); ?>

		<h4 class="sh-sub">Stempelkarte</h4>
		<?php $sh_help('Jede abgeschlossene Bestellung eines Gastes mit Kundenkonto ist ein Stempel. Ist die Karte voll, bekommt der Gast einen persönlichen Gutschein.', 'Der Gutschein hat die Höhe des eingestellten Anteils der Warenwerte dieser Bestellungen. Er wird bei der nächsten passenden Bestellung automatisch und komplett abgezogen und gilt ab einem Warenwert in Höhe des Gutscheins, einen Rest gibt es nicht. Ohne Kundenkonto gibt es keine Stempel. Die Gutscheine siehst du unter Gutscheine (Code STEMPEL-...). Ein Gutschein bleibt gültig, auch wenn du die Stempelkarte ausschaltest.'); ?>
		<?php $sh_cb('stamp_on', 'Stempelkarte anbieten'); ?>
		<?php $sh_row('sh-sp', 'stamp_percent', 'Gutschein', '% der Warenwerte'); ?>
		<?php $sh_row('sh-sg', 'stamp_goal', 'Stempel pro Karte', 'Stempel'); ?>
		<?php $sh_row('sh-sm', 'stamp_months', 'Stempel gültig', 'Monate'); ?>
		<?php $sh_row('sh-sd', 'voucher_days', 'Gutschein gültig', 'Tage'); ?>

		<h4 class="sh-sub">Angebote</h4>
		<?php $sh_help('Angebote ohne Code, im Warenkorb: ein Gratis-Extra ab einem Warenwert und Pizza + Getränk zum Festpreis-Nachlass.', '<b>Gratis-Extra:</b> Ab einem Warenwert, der mit der Zahl der Hauptgerichte wächst (Pizza, Pasta, Fleisch, Fisch ...; Desserts, Getränke, Vorspeisen und Wein zählen nicht), wählt der Gast ein Extra gratis. Darunter zeigt der Warenkorb, was fehlt, und schlägt Snacks, Beilagen und Getränke vor, deren Preis die Lücke schließt. Wer kein Extra will, bekommt (nur mit Kundenkonto) einen persönlichen Gutschein für die nächste Bestellung. <b>Pizza + Getränk:</b> Für jede Pizza (auch Calzone und Wunschpizza) kostet ein alkoholfreies Getränk oder ein Bier einen festen Betrag weniger, kein Wein. Gratis-Extras müssen Gerichte ohne Auswahl sein (keine Größen, keine Zutatengruppen).'); ?>
		<?php $sh_cb('offers_on', 'Angebote anbieten'); ?>
		<h5 class="sh-sub2">Gratis-Extra ab Warenwert</h5>
		<?php $sh_row('sh-ot1', 'offer_t1', 'Bei 1 Hauptgericht', '€', '', '', 'decimal'); ?>
		<?php $sh_row('sh-ot2', 'offer_t2', 'Bei 2 Hauptgerichten', '€', '', '', 'decimal'); ?>
		<?php $sh_row('sh-ot3', 'offer_t3', 'Bei 3 und mehr Hauptgerichten', '€', '', '', 'decimal'); ?>
		<?php $sh_ext = array_values(array_filter(array_map('intval', explode(',', (string)$sh['offer_extras'])))); $sh_pick = fb_rows("SELECT p.id, p.title, COALESCE((SELECT MIN(v.price_cents) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id), p.price_cents) AS price_cents, c.name AS cat,
			(SELECT GROUP_CONCAT(v3.title ORDER BY v3.sort, v3.id SEPARATOR ', ') FROM ".fb_t('tp_shop_variations')." v3 WHERE v3.product_id = p.id) AS vars
		FROM ".fb_t('tp_shop_products')." p LEFT JOIN ".fb_t('tp_shop_categories')." c ON c.id = p.category_id WHERE p.active = 1 AND NOT EXISTS (SELECT 1 FROM ".fb_t('tp_shop_product_groups')." g WHERE g.product_id = p.id)
			AND COALESCE((SELECT MAX(v2.price_cents) FROM ".fb_t('tp_shop_variations')." v2 WHERE v2.product_id = p.id), p.price_cents) <= 900 ORDER BY c.sort, p.sort, p.id");   // products with variants too: each variant becomes a choice of its own
		for ($sh_i = 0; $sh_i < 3; $sh_i++): ?>
		<div class="sh-row sh-wide"><div class="sh-text"><label class="sh-label" for="sh-ox<?php echo $sh_i; ?>">Gratis-Extra <?php echo $sh_i + 1; ?><?php echo $sh_i === 0 ? ' (ist vorgewählt)' : ''; ?></label></div>
			<select id="sh-ox<?php echo $sh_i; ?>" name="offer_extra_<?php echo $sh_i; ?>"<?php echo $F; ?>><option value="0">(keins)</option>
				<?php foreach ($sh_pick as $sh_p): ?><option value="<?php echo (int)$sh_p['id']; ?>"<?php echo (isset($sh_ext[$sh_i]) && $sh_ext[$sh_i] === (int)$sh_p['id']) ? ' selected' : ''; ?>><?php echo $sh_e($sh_p['title'].($sh_p['vars'] !== null && $sh_p['vars'] !== '' ? ' - Auswahl: '.$sh_p['vars'] : '').' ('.$sh_p['cat'].', '.($sh_p['vars'] !== null && $sh_p['vars'] !== '' ? 'ab ' : '').number_format($sh_p['price_cents'] / 100, 2, ',', '').' €)'); ?></option><?php endforeach; ?>
			</select></div>
		<?php endfor; ?>
		<h5 class="sh-sub2">Gutschein statt Extra</h5>
		<?php $sh_row('sh-ov', 'offer_voucher', 'Wert des Gutscheins', '€', '', '', 'decimal'); ?>
		<?php $sh_row('sh-ovm', 'offer_voucher_min', 'Gilt ab Warenwert', '€', '', '', 'decimal'); ?>
		<?php $sh_row('sh-ovd', 'offer_voucher_days', 'Gültig', 'Tage'); ?>
		<h5 class="sh-sub2">Pizza + Getränk</h5>
		<?php $sh_row('sh-oc', 'offer_combo', 'Nachlass auf das Getränk', '€', '0 = aus.', '', 'decimal'); ?>

		<h4 class="sh-sub">Bestell-Chat <span class="sh-chip">in Erprobung</span></h4>
		<?php $sh_help('Gäste bestellen im Gespräch mit Antwortknöpfen unter <code>/order/chat.php</code>. Preise und Liefergebiet kommen immer aus dem Shop, bei Allergien oder Beschwerden wird der Gast zum Anruf gebeten.', 'Ablauf: Gericht wählen, Adresse prüfen, Zeit und Zahlung, am Ende eine Zusammenfassung zum Bestätigen. Die Handynummer wird mit einem SMS-Code bestätigt, dafür muss das Kundenkonto eingeschaltet sein. Ausgeschaltet gibt es die Seite für Gäste nicht, du selbst siehst sie als Mitarbeiter trotzdem (zum Testen).'); ?>
		<?php $sh_cb('chat_on', 'Bestell-Chat einschalten'); ?>
		<h5 class="sh-sub2">Freie Sätze mit KI</h5>
		<?php $sh_help('Der Gast darf schreiben, was er möchte („zwei Salami, eine ohne Zwiebeln, dazu eine Cola“). Ein Sprachmodell (Claude Haiku von Anthropic) legt die Gerichte in den Warenkorb. Bestellt wird nur mit dem Tipp des Gastes auf „Verbindlich bestellen“.', 'Preise, Liefergebiet und die Bestellung bleiben beim Server. Name, Adresse und Telefonnummer gehen nicht an den Dienst. Ohne Schlüssel oder bei einer Störung sucht der Chat wie bisher nur in der Speisekarte. Jeder Aufruf kostet etwas, das Tageslimit begrenzt die Kosten.'); ?>
		<?php $sh_cb('chat_ai_on', 'Freie Sätze mit KI erlauben'); ?>
		<?php require_once __DIR__.'/../classes/shop_chat_ai.class.php'; $ck_info = shop_chat_ai_key_info(); ?>
		<div class="sh-row sh-wide"><div class="sh-text"><label class="sh-label" for="sh-aikey">Schlüssel von Anthropic</label>
			<p class="sh-hint"><?php if ($ck_info['source'] !== null): ?><span class="offer-badge sms-badge-on">hinterlegt</span> <?php echo $sh_e($ck_info['masked']); ?><?php echo $ck_info['source'] === 'config' ? ' (aus der Konfiguration)' : ''; ?><?php else: ?><span class="offer-badge">noch keiner hinterlegt</span><?php endif; ?></p></div>
			<input type="password" id="sh-aikey" name="chat_ai_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $ck_info['source'] !== null ? 'Neuen Schlüssel einfügen, leer lassen = unverändert' : 'Schlüssel einfügen'; ?>"<?php echo $F; ?>/></div>
		<?php $sh_wide('sh-aiws', 'chat_ai_workspace', 'Arbeitsbereich (Workspace-ID)', 'Nur nötig, wenn der Schlüssel keinem Arbeitsbereich zugeordnet ist.', 'type="text" autocomplete="off" spellcheck="false"'); ?>
		<?php $sh_row('sh-aidaily', 'chat_ai_daily', 'Aufrufe höchstens', 'pro Tag'); ?>
		<?php if ($ck_info['source'] === 'settings'): ?><label class="sh-check"><input type="checkbox" name="chat_ai_key_clear" value="1"<?php echo $F; ?>/><span><span class="sh-cb-l">Hinterlegten Schlüssel beim Speichern löschen</span></span></label><?php endif; ?>
		<?php require_once __DIR__.'/../classes/shop_chat.class.php'; $ch_list = shop_chat_review_list(12); ?>
		<h5 class="sh-sub2">Chat-Gespräche zum Ansehen</h5>
		<?php $sh_help('Gespräche, in denen ein Gast zum Anruf gebeten wurde oder die KI etwas wahrscheinlich nicht richtig verstanden hat. Sie bleiben 14 Tage erhalten.', 'Gründe: Allergie oder Beschwerde, ein Werkzeug hat abgelehnt, oder der Gast hat den Warenkorb gleich danach geändert oder korrigiert.'); ?>
		<?php if (!$ch_list): ?><p class="sh-help">Zurzeit gibt es keine.</p><?php endif; ?>
		<?php foreach ($ch_list as $cl): ?>
			<details class="chat-review"><summary><b><?php echo $sh_e(date('d.m. H:i', strtotime($cl['at']))); ?></b> · <?php echo $sh_e(implode(' · ', $cl['why'])); ?><?php echo $cl['ordered'] ? ' · bestellt' : ''; ?></summary><pre class="sh-pre"><?php echo $sh_e($cl['transcript']); ?></pre></details>
		<?php endforeach; ?>
	</section>

	<section class="sh-group" id="sh-anbindungen" aria-labelledby="sh-h-anbindungen">
		<h3 id="sh-h-anbindungen">Anbindungen</h3>
		<h4 class="sh-sub">Uber Eats Bons (Empfang vom Küchen-Pi)</h4>
		<?php $sh_ub = shop_uber_stats(); ?>
		<?php $sh_help('Der Raspberry Pi in der Küche nimmt die Druckaufträge des Uber-Eats-Tablets an und schickt jeden Bon als Bild hierher. mySeat liest ihn über n8n mit einem Bildmodell, prüft die Summen und legt passende, frische Bons von selbst als Bestellung an.', 'Die Bilder werden '.(int)SHOP_UBER_KEEP_DAYS.' Tage gespeichert (sie enthalten Namen, Adressen und Telefonnummern). Den Schlüssel trägst du auf dem Pi ein (Datei <code>/etc/uber-bridge.conf</code>), sonst nirgends.'); ?>
		<div class="sms-status">
			<span class="offer-badge<?php echo $sh_ub['count'] ? ' sms-badge-on' : ''; ?>"><?php echo $sh_ub['count'] ? (int)$sh_ub['count'].' Bons empfangen' : 'Noch kein Bon empfangen'; ?></span>
			<?php if ($sh_ub['last'] !== ''): ?><span class="sms-keyinfo">zuletzt <?php echo $sh_e(date('d.m.Y H:i', strtotime($sh_ub['last']))); ?></span><?php endif; ?>
		</div>
		<p class="sh-help">Gelesen: <strong><?php echo (int)$sh_ub['by']['read']; ?></strong> &middot; zu prüfen: <strong><?php echo (int)$sh_ub['by']['check']; ?></strong> &middot; Fehler: <strong><?php echo (int)$sh_ub['by']['error']; ?></strong> &middot; neu: <strong><?php echo (int)$sh_ub['by']['new'] + (int)$sh_ub['by']['reading']; ?></strong></p>
		<?php $sh_rec = shop_uber_recent(12); $sh_stl = array('read' => 'gelesen', 'check' => 'zu prüfen', 'error' => 'Fehler', 'new' => 'neu', 'reading' => 'wird gelesen', 'imported' => 'übernommen'); ?>
		<?php if ($sh_ub['by']['check'] + $sh_ub['by']['error'] + $sh_ub['by']['new'] + $sh_ub['by']['reading'] > 0): ?><p class="offer-actions"><button type="button" class="button_dark" id="uber-reread">Zu prüfende Bons neu lesen</button></p><?php endif; ?>
		<?php if ($sh_ub['count']): ?><p class="offer-actions"><button type="button" class="button_dark" id="uber-reread-last">Die letzten 4 Bons neu lesen</button></p><?php endif; ?>
		<?php if ($sh_rec): ?><ul class="sh-bons" id="uber-recent">
			<?php foreach ($sh_rec as $sh_r): ?><li>Bon <?php echo (int)$sh_r['id']; ?> &middot; <?php echo $sh_e(date('d.m. H:i', strtotime($sh_r['received']))); ?> &middot; <strong><?php echo $sh_e(isset($sh_stl[$sh_r['status']]) ? $sh_stl[$sh_r['status']] : $sh_r['status']); ?></strong><?php echo $sh_r['tier'] !== '' ? ' ('.$sh_e($sh_r['tier']).')' : ''; ?><?php if ($sh_r['items'] !== null): ?> &middot; <?php echo (int)$sh_r['items']; ?> Positionen<?php endif; ?><?php if ($sh_r['total'] !== null): ?> &middot; <?php echo $sh_e(number_format($sh_r['total'] / 100, 2, ',', '.')); ?> &euro;<?php endif; ?><?php if ($sh_r['why'] !== '' && $sh_r['status'] !== 'read'): ?> &ndash; <?php echo $sh_e($sh_r['why']); ?><?php endif; ?><?php if ($sh_r['order_id']): ?> &middot; Bestellung #<?php echo (int)$sh_r['day_no']; ?><?php echo $sh_r['is_test'] ? ' (Test)' : ''; ?><?php endif; ?><?php if ($sh_r['ref'] !== ''): ?> &middot; <em>Referenz: <?php echo $sh_r['ref'] === 'pickup' ? 'Abholung' : 'Lieferung'; ?></em><?php endif; ?><?php if ($sh_r['auto'] !== '' && !$sh_r['order_id']): ?> &ndash; <?php echo $sh_e($sh_r['auto']); ?><?php endif; ?><?php if ($sh_r['lines']): ?><br/><small><?php echo $sh_e(implode(' | ', $sh_r['lines'])); ?></small><?php endif; ?><?php if (in_array($sh_r['status'], array('read', 'imported'), true) && !$sh_r['order_id'] && !$sh_r['stale']): ?> <button type="button" class="button_dark uber-import" data-id="<?php echo (int)$sh_r['id']; ?>">Als Bestellung übernehmen</button><?php endif; ?><?php if ($sh_r['stale'] && !$sh_r['order_id']): ?> <button type="button" class="button_dark uber-reread-one" data-id="<?php echo (int)$sh_r['id']; ?>">Neu lesen (Zahlart fehlt)</button><?php endif; ?></li>
			<?php endforeach; ?></ul><?php endif; ?>
		<div class="sh-own">
			<form class="sms-form" id="uber-read-form" autocomplete="off">
				<label class="sh-label" for="sh-uburl">Adresse des n8n-Webhooks</label>
				<input type="url" id="sh-uburl" name="uber_read_url" value="<?php echo $sh_e(shop_uber_read_url()); ?>" spellcheck="false" placeholder="https://..."/>
				<?php $sh_secret('Geheimnis für den n8n-Webhook', shop_uber_read_secret(), 'Zugang „Header Auth“, Name <code>X-Uber-Secret</code>.'); ?>
				<label class="sh-check"><input type="checkbox" name="uber_read_large" value="1"<?php echo shop_setting('uber_read_large') === '1' ? ' checked' : ''; ?>/><span><span class="sh-cb-l">Stimmt die Summe nicht, mit dem stärkeren Modell noch einmal lesen</span></span></label>
				<label class="sh-check"><input type="checkbox" name="uber_auto_import" value="1"<?php echo shop_setting('uber_auto_import') === '1' ? ' checked' : ''; ?>/><span><span class="sh-cb-l">Gelesene Bons sofort als Bestellung übernehmen und den Küchenzettel drucken</span><span class="sh-hint">Nur frische Bons (bestellt in den letzten 45 Minuten), die stimmen; eine Lieferung nur mit Adresse und Telefonnummer. Alles andere bleibt in der Liste zur Übernahme von Hand. Die Gäste bekommen unsere SMS und die Statusseite, aber keinen Stempel.</span></span></label>
				<p class="offer-actions">
					<button type="submit" class="button_dark">Uber-Einstellungen speichern</button>
					<?php if (shop_uber_read_url() !== '' && $sh_ub['count']): ?><button type="button" class="button_dark" id="uber-read-test">Letzten Bon testweise lesen</button><?php endif; ?>
					<span class="detail-status" id="uber-read-msg" role="status" aria-live="polite"></span>
				</p>
			</form>
		</div>
		<?php $sh_secret('Schlüssel für den Pi', shop_uber_key(), 'Trag ihn auf dem Pi in <code>/etc/uber-bridge.conf</code> ein, sonst nirgends.'); ?>

		<h4 class="sh-sub">Sipgate-Anrufererkennung</h4>
		<?php $sh_help('Zeigt bei „Bestellung erfassen“ automatisch die Nummer eines eingehenden Anrufs an. Trag die Webhook-URL in deinem Sipgate-Konto unter sipgate.io für eingehende Anrufe ein.', 'Die vollständige URL enthält einen geheimen Schlüssel. Benutzername und Passwort sind nicht erforderlich. Gib die URL nicht weiter.'); ?>
		<?php $sh_secret('Webhook-URL', $sh_webhook_url); ?>
	</section>

	<div class="sh-savebar" role="region" aria-label="Einstellungen speichern">
		<button type="submit" class="button_dark" form="shop-form">Einstellungen speichern</button>
		<span class="sh-dirty" id="shop-dirty" aria-live="polite"></span>
		<span class="detail-status" id="shop-msg" role="status" aria-live="polite"></span>
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
		post('save', d, function (r) { if (window.shopSnapReset) { window.shopSnapReset(); } say(msg, r.message, false); setTimeout(function () { location.reload(); }, 600); }, function (e) { say(msg, e, true); });
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
	var uf = document.getElementById('uber-read-form'), um = document.getElementById('uber-read-msg');
	if (uf) {
		uf.addEventListener('submit', function (ev) { ev.preventDefault(); say(um, 'Einen Moment ...', false); post('save_uber_read', { uber_read_url: uf.elements.uber_read_url.value, uber_read_large: uf.elements.uber_read_large.checked ? '1' : '', uber_auto_import: uf.elements.uber_auto_import.checked ? '1' : '' }, function (r) { say(um, r.message, false); setTimeout(function () { location.reload(); }, 900); }, function (e) { say(um, e, true); }); });
		var ur = document.getElementById('uber-reread');
		if (ur) { ur.addEventListener('click', function () { ur.disabled = true; say(um, 'Einen Moment, bis zu 4 Bons werden gelesen (je 5 bis 20 Sekunden) ...', false); post('uber_reread', { id: 0 }, function (r) { say(um, r.message, false); setTimeout(function () { location.reload(); }, 1500); }, function (e) { ur.disabled = false; say(um, e, true); }); }); }
		var ul = document.getElementById('uber-reread-last');
		if (ul) { ul.addEventListener('click', function () { ul.disabled = true; say(um, 'Einen Moment, die letzten 4 Bons werden gelesen (je 5 bis 20 Sekunden) ...', false); post('uber_reread', { id: 0, all: 1 }, function (r) { say(um, r.message, false); setTimeout(function () { location.reload(); }, 1500); }, function (e) { ul.disabled = false; say(um, e, true); }); }); }
		var urec = document.getElementById('uber-recent');
		if (urec) { urec.addEventListener('click', function (ev) { var rb = ev.target.closest ? ev.target.closest('.uber-reread-one') : null; if (rb) { rb.disabled = true; say(um, 'Einen Moment, der Bon wird gelesen (5 bis 20 Sekunden) ...', false); post('uber_reread', { id: rb.dataset.id }, function (r) { say(um, r.message, false); setTimeout(function () { location.reload(); }, 1200); }, function (e) { rb.disabled = false; say(um, e, true); }); return; } var b = ev.target.closest ? ev.target.closest('.uber-import') : null; if (!b) { return; } b.disabled = true; say(um, 'Einen Moment ...', false); post('uber_import', { id: b.dataset.id }, function (r) { say(um, r.message, false); setTimeout(function () { location.reload(); }, 1500); }, function (e) { b.disabled = false; say(um, e, true); }); }); }
		var ut = document.getElementById('uber-read-test');
		if (ut) { ut.addEventListener('click', function () { say(um, 'Einen Moment, das Bildmodell liest ...', false); post('uber_read_test', {}, function (r) { say(um, r.message, false); }, function (e) { say(um, e, true); }); }); }
	}
	var gt = document.getElementById('google-key-test'), gtmsg = document.getElementById('google-key-msg');
	if (gt) { gt.addEventListener('click', function () { say(gtmsg, 'Einen Moment ...', false); post('test_google_key', {}, function (r) { say(gtmsg, r.message, false); }, function (e) { say(gtmsg, e, true); }); }); }
	var wt = document.getElementById('w3w-key-test'), wtmsg = document.getElementById('w3w-key-msg');
	if (wt) { wt.addEventListener('click', function () { say(wtmsg, 'Einen Moment ...', false); post('test_w3w_key', {}, function (r) { say(wtmsg, r.message, false); }, function (e) { say(wtmsg, e, true); }); }); }

		// ---- secrets are masked until "Anzeigen"; "Kopieren" puts the value on the clipboard
		page.addEventListener('click', function (ev) {
			var show = ev.target.closest('[data-secret-show]'), cp = ev.target.closest('[data-secret-copy]'); if (!show && !cp) { return; }
			var box = (show || cp).closest('.sh-secret-box'), val = box.querySelector('.sh-secret-val'), btn = box.querySelector('[data-secret-show]');
			if (show) {
				var on = show.getAttribute('aria-pressed') !== 'true'; show.setAttribute('aria-pressed', on ? 'true' : 'false'); show.textContent = on ? 'Verbergen' : 'Anzeigen';
				val.textContent = on ? val.dataset.value : '••••••••••••••••'; return;
			}
			function done(ok) { cp.textContent = ok ? 'Kopiert' : 'Nicht möglich'; setTimeout(function () { cp.textContent = 'Kopieren'; }, 1800); }
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(val.dataset.value).then(function () { done(true); }, function () { done(false); }); return; }
			var ta = document.createElement('textarea'); ta.value = val.dataset.value; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0'; document.body.appendChild(ta); ta.select();
			var ok = false; try { ok = document.execCommand('copy'); } catch (e) {} document.body.removeChild(ta); done(ok);
		});
		// ---- unsaved changes of the main form (all the fields with form="shop-form"): counted next to the save button, a warning when the page is left
		var snap = {}, dirtyEl = document.getElementById('shop-dirty');
		function snapshot() { var o = {}; Array.prototype.forEach.call(form.elements, function (e) { if (!e.name) { return; } o[e.name] = e.type === 'checkbox' ? (e.checked ? '1' : '') : e.value; }); return o; }
		function updDirty() { var cur = snapshot(), n = 0; for (var k in cur) { if (Object.prototype.hasOwnProperty.call(cur, k) && cur[k] !== snap[k]) { n++; } } dirtyEl.textContent = n ? (n === 1 ? '1 Änderung nicht gespeichert' : n + ' Änderungen nicht gespeichert') : ''; dirtyEl.classList.toggle('is-on', n > 0); return n; }
		snap = snapshot();
		window.shopSnapReset = function () { snap = snapshot(); updDirty(); };
		page.addEventListener('input', updDirty); page.addEventListener('change', updDirty);
		window.addEventListener('beforeunload', function (ev) { if (updDirty()) { ev.preventDefault(); ev.returnValue = ''; } });
		// ---- the navigation follows the scrolling; a tap on an entry stays active until the guest scrolls on his own (a short last section cannot reach the top)
		var navLinks = Array.prototype.slice.call(document.querySelectorAll('#sh-nav a')), navLock = false, navQueued = false, navCur = null;
		function navSet(id) { if (id === navCur) { return; } navCur = id; navLinks.forEach(function (a) { var on = a.getAttribute('href') === '#' + id; if (on) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); } if (on) { a.scrollIntoView({ inline: 'center', block: 'nearest' }); } }); }
		function navUpdate() {
			navQueued = false; if (navLock) { return; }
			var secs = Array.prototype.slice.call(document.querySelectorAll('.sh-group')), id = secs[0].id;
			secs.forEach(function (sec) { if (sec.getBoundingClientRect().top <= 90) { id = sec.id; } });
			if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) { id = secs[secs.length - 1].id; }
			navSet(id);
		}
		function navQueue() { if (!navQueued) { navQueued = true; requestAnimationFrame(navUpdate); } }
		navLinks.forEach(function (a) { a.addEventListener('click', function () { navSet(a.getAttribute('href').slice(1)); navLock = true; }); });
		['wheel', 'touchmove', 'keydown', 'pointerdown'].forEach(function (t) { window.addEventListener(t, function () { if (navLock) { navLock = false; navQueue(); } }, { passive: true, capture: true }); });
		window.addEventListener('scroll', navQueue, { passive: true }); window.addEventListener('resize', navQueue); navUpdate();
});
</script>
