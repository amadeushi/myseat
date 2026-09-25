<?php
// Settings > SMS-Versand: gateway key, on/off switch, connection test and test SMS
require_once __DIR__.'/../classes/sms.class.php';
$sm_e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$sm_key = sms_key_info();
$sm_flag = sms_cfg()['enabled'];
$sm_ready = sms_enabled();
$sm_stats = $sm_ready ? sms_stats() : null;
$sm_errors = $sm_key['source'] !== null ? fb_rows("SELECT event_type, phone, text, last_error, updated_at FROM ".fb_t('tp_sms_outbox')." WHERE status = 'failed' ORDER BY id DESC LIMIT 5") : array();
$sm_link = sms_link_cfg();
$sm_src = array('settings' => 'aus diesen Einstellungen', 'config' => 'aus der Konfigurationsdatei des Servers');
?>
<div class="sms-page" data-endpoint="ajax/save_sms_settings.php" data-token="<?php echo $sm_e($token); ?>">
	<p class="offers-intro">Mit SMS bekommen Gäste mit Mobilnummer die Reservierungsbestätigung und am Vortag eine Erinnerung. Die SMS gehen über das eigene SMS-Gateway. Ohne Schlüssel und Schalter wird nichts verschickt.</p>

	<div class="sms-status" id="sms-state">
		<?php if ($sm_ready): ?><span class="offer-badge sms-badge-on">SMS ist aktiv</span>
		<?php elseif ($sm_key['source'] === null): ?><span class="offer-badge">Kein Schlüssel hinterlegt</span>
		<?php else: ?><span class="offer-badge">SMS ist ausgeschaltet</span><?php endif; ?>
		<?php if ($sm_key['source'] !== null): ?><span class="sms-keyinfo">Schlüssel <?php echo $sm_e($sm_key['masked']); ?> (<?php echo $sm_src[$sm_key['source']]; ?>)</span><?php endif; ?>
	</div>

	<form class="sms-form" id="sms-form" autocomplete="off">
		<label class="offer-check"><input type="checkbox" name="enabled" value="1"<?php echo $sm_flag ? ' checked' : ''; ?>/> SMS-Versand aktivieren</label>

		<label class="offer-label" for="sms-key">API-Schlüssel des SMS-Gateways</label>
		<small class="offer-help">Auf dem SMS-Pi erzeugen: <code>sudo sms-project create-project myseat 100</code>. Der Schlüssel wird verschlüsselt gespeichert und danach nie wieder angezeigt, nur die letzten 4 Zeichen. Ein hier eingetragener Schlüssel hat Vorrang vor der Konfigurationsdatei. Zum Ersetzen einfach einen neuen einfügen, sonst das Feld leer lassen.</small>
		<input type="password" id="sms-key" name="api_key" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sm_key['source'] !== null ? 'Neuen Schlüssel einfügen (optional)' : 'Schlüssel einfügen'; ?>"/>

		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<?php if ($sm_key['source'] === 'settings'): ?><button type="button" class="offer-delete" id="sms-clear">Schlüssel löschen</button><?php endif; ?>
			<span class="detail-status" id="sms-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<h4 class="sms-sub">Absage-Link in der SMS</h4>
	<p class="offer-help">Statt der Telefonnummer steht in der SMS ein kurzer Link zum Stornieren (über dein YOURLS mit dem Plugin „Expiry“). Er läuft am Morgen nach dem Reservierungstag ab. Ist YOURLS nicht erreichbar, geht die SMS wie bisher mit der Telefonnummer raus.</p>
	<form class="sms-form" id="link-form" autocomplete="off">
		<label class="offer-check"><input type="checkbox" name="cancel_link" value="1"<?php echo $sm_link['enabled'] ? ' checked' : ''; ?>/> Absage-Link in SMS einfügen</label>
		<label class="offer-label" for="yourls-url">Adresse der YOURLS-API</label>
		<input type="text" id="yourls-url" name="yourls_url" value="<?php echo $sm_e($sm_link['url']); ?>" spellcheck="false"/>
		<label class="offer-label" for="yourls-sig">Signaturschlüssel (Signature Token)</label>
		<small class="offer-help">In YOURLS unter „Tools“ zu finden. Wird verschlüsselt gespeichert und nie wieder angezeigt<?php echo $sm_link['sig_masked'] ? ' (aktuell '.$sm_e($sm_link['sig_masked']).')' : ''; ?>. Leer lassen, um den vorhandenen zu behalten.</small>
		<input type="password" id="yourls-sig" name="yourls_sig" autocomplete="new-password" spellcheck="false" placeholder="<?php echo $sm_link['sig'] !== '' ? 'Neuen Schlüssel einfügen (optional)' : 'Schlüssel einfügen'; ?>"/>
		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<?php if ($sm_link['sig'] !== ''): ?><button type="button" class="button_dark" id="link-test">Verbindung prüfen</button>
			<button type="button" class="offer-delete" id="link-clear">Schlüssel löschen</button><?php endif; ?>
			<span class="detail-status" id="link-msg" role="status" aria-live="polite"></span>
		</p>
	</form>

	<?php if ($sm_key['source'] !== null): ?>
	<div class="sms-tools">
		<p><button type="button" class="button_dark" id="sms-health">Verbindung prüfen</button> <small class="offer-help">Testet Gateway und Schlüssel, es wird nichts gesendet.</small></p>
			<p class="detail-status sms-toolmsg" id="tool-msg" role="status" aria-live="polite"></p>
		<?php if ($sm_ready): ?>
		<p class="sms-test"><input type="text" id="sms-phone" inputmode="tel" placeholder="Deine Mobilnummer, z. B. 0151 2345678" autocomplete="off"/> <button type="button" class="button_dark" id="sms-test">Test-SMS senden</button></p>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ($sm_stats): ?>
	<p class="sms-stats">Heute angenommen: <strong><?php echo (int)$sm_stats['accepted_today']; ?></strong> &middot; wartet in der Warteschlange: <strong><?php echo (int)$sm_stats['queued']; ?></strong> &middot; fehlgeschlagen in 7 Tagen: <strong><?php echo (int)$sm_stats['failed_week']; ?></strong></p>
	<?php endif; ?>
	<?php if ($sm_errors): ?>
	<div class="sms-errors"><strong>Letzte Fehler</strong>
		<ul><?php foreach ($sm_errors as $er): ?><li><?php echo $sm_e(date('d.m. H:i', strtotime($er['updated_at']))); ?> &middot; <?php echo $sm_e($er['event_type']); ?> an <?php echo $sm_e(substr($er['phone'], 0, 5).'***'.substr($er['phone'], -3)); ?>: <?php echo $sm_e($er['last_error']); ?>
				<?php $sm_odd = array(); foreach (preg_split('//u', (string)$er['text'], -1, PREG_SPLIT_NO_EMPTY) as $ch) { if (strlen($ch) > 1) { $sm_odd[$ch] = sprintf('%s U+%04X', $ch, mb_ord($ch, 'UTF-8')); } } ?>
				<br/><small>Text (<?php echo (int)mb_strlen($er['text']); ?> Zeichen): <?php echo $sm_e($er['text']); ?><?php echo $sm_odd ? ' &middot; Nicht-ASCII: '.$sm_e(implode(', ', $sm_odd)) : ''; ?></small></li><?php endforeach; ?></ul>
	</div>
	<?php endif; ?>

	<div class="sms-howto">
		<strong>So läuft es</strong>
		<ul>
			<li>Bestätigung: direkt nach einer festen Buchung, und wenn du eine Gruppenanfrage bestätigst.</li>
			<li>Erinnerung: am Vortag zwischen 10 und 20 Uhr, zusätzlich zur Mail. Gäste ohne E-Mail-Adresse bekommen sie nur so.</li>
			<li>Mit Absage-Link: Gäste können über den Link selbst stornieren, auch ohne E-Mail-Adresse.</li>
			<li>Nur Mobilnummern (Festnetz wird übersprungen), bis zu 160 Zeichen im GSM-Zeichensatz (Umlaute und ß gehen, Emoji und Sonderzeichen werden ersetzt oder weggelassen).</li>
			<li>Das Gateway nimmt nur 10 neue SMS pro Minute an. Was liegen bleibt, holt ein Webcron nach: <code>…/web/cron/sms_flush.php?key=&lt;Cron-Schlüssel&gt;</code>, jede Minute.</li>
		</ul>
	</div>
</div>
<script>
window.addEventListener('load', function () {
	var page = document.querySelector('.sms-page'); if (!page) { return; }
	var msg = document.getElementById('sms-msg');
	var target = msg;
	function say(text, isError) { target.textContent = text; target.classList.toggle('is-error', !!isError); }
	try { var saved = sessionStorage.getItem('smsMsg'); if (saved) { sessionStorage.removeItem('smsMsg'); say(saved, false); } } catch (e) {}
	function call(op, extra, done, where) {
		target = where || msg; if (where) { msg.textContent = ''; }
		var fd = new FormData(); fd.append('op', op); fd.append('token', page.dataset.token);
		for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { fd.append(k, extra[k]); } }
		say('Einen Moment ...', false);
		fetch(page.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', true); return; }
			done(r);
		}).catch(function () { say('Das hat nicht geklappt. Bitte versuche es noch einmal.', true); });
	}
	function reloadWith(r) { try { sessionStorage.setItem('smsMsg', r.message); } catch (e) {} location.reload(); }
	var lmsg = document.getElementById('link-msg');
	function lsay(text, isError) { lmsg.textContent = text; lmsg.classList.toggle('is-error', !!isError); }
	function lcall(op, extra, done) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', page.dataset.token);
		for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) { fd.append(k, extra[k]); } }
		lsay('Einen Moment ...', false);
		fetch(page.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { lsay(r.error || 'Das hat nicht geklappt.', true); return; }
			done(r);
		}).catch(function () { lsay('Das hat nicht geklappt. Bitte versuche es noch einmal.', true); });
	}
	var lform = document.getElementById('link-form');
	lform.addEventListener('submit', function (ev) { ev.preventDefault(); lcall('save_link', { cancel_link: lform.elements.cancel_link.checked ? '1' : '', yourls_url: lform.elements.yourls_url.value, yourls_sig: lform.elements.yourls_sig.value }, function (r) { try { sessionStorage.setItem('smsMsg', r.message); } catch (e) {} location.reload(); }); });
	var ltest = document.getElementById('link-test');
	if (ltest) { ltest.addEventListener('click', function () { lcall('test_link', {}, function (r) { lsay(r.message, false); }); }); }
	var lclear = document.getElementById('link-clear');
	if (lclear) { lclear.addEventListener('click', function () { if (lclear.dataset.armed) { lcall('clear_link_key', {}, function (r) { try { sessionStorage.setItem('smsMsg', r.message); } catch (e) {} location.reload(); }); } else { lclear.dataset.armed = '1'; lclear.textContent = 'Wirklich löschen?'; setTimeout(function () { lclear.dataset.armed = ''; lclear.textContent = 'Schlüssel löschen'; }, 4000); } }); }

	var form = document.getElementById('sms-form');
	form.addEventListener('submit', function (ev) { ev.preventDefault(); call('save', { enabled: form.elements.enabled.checked ? '1' : '', api_key: form.elements.api_key.value }, reloadWith); });
	var clear = document.getElementById('sms-clear');
	if (clear) { clear.addEventListener('click', function () { if (clear.dataset.armed) { call('clear_key', {}, reloadWith); } else { clear.dataset.armed = '1'; clear.textContent = 'Wirklich löschen?'; setTimeout(function () { clear.dataset.armed = ''; clear.textContent = 'Schlüssel löschen'; }, 4000); } }); }
	var health = document.getElementById('sms-health');
	if (health) { health.addEventListener('click', function () { call('health', {}, function (r) { say(r.message, !r.health_ok); }, document.getElementById('tool-msg')); }); }
	var test = document.getElementById('sms-test');
	if (test) { test.addEventListener('click', function () { call('test', { phone: document.getElementById('sms-phone').value }, function (r) { say(r.message, false); }, document.getElementById('tool-msg')); }); }
});
</script>
