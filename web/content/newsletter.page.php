<?php
// Newsletter (p=14): who may get which mails, and the proof of it. The list is filled by the ticks in the shop, the chat, the account and the reservation form (each confirmed by mail);
// all work is done by web/js/newsletter.js through web/ajax/newsletter.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
?>
<div class="content sms-page nl-page" id="nl-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="nl-tabs" role="tablist" aria-label="Newsletter">
		<button type="button" role="tab" id="nl-tab-subs" aria-controls="nl-panel-subs" aria-selected="true">Abonnenten</button>
		<button type="button" role="tab" id="nl-tab-mail" aria-controls="nl-panel-mail" aria-selected="false" tabindex="-1">Mailings</button>
		<button type="button" role="tab" id="nl-tab-auto" aria-controls="nl-panel-auto" aria-selected="false" tabindex="-1">Automatiken</button>
		<button type="button" role="tab" id="nl-tab-sender" aria-controls="nl-panel-sender" aria-selected="false" tabindex="-1">Absender</button>
	</div>
	<div id="nl-panel-subs" role="tabpanel" aria-labelledby="nl-tab-subs">
		<p class="sh-help">Hier siehst du, wer welche Mails von uns bekommen darf und wie das belegt ist. Aufgenommen wird nur, wer per Mail bestätigt hat (Double-Opt-in). Jede Anmeldung, Bestätigung und Abmeldung steht mit Zeit, Adresse des Besuchers und Quelle im Nachweis.</p>
		<div class="nl-counts" id="nl-counts" role="group" aria-label="Filter"></div>
		<div class="nl-bar">
			<label class="sh-vh" for="nl-q">Suchen</label>
			<input type="search" id="nl-q" placeholder="E-Mail oder Name" autocomplete="off" enterkeyhint="search"/>
			<span class="detail-status" id="nl-msg" role="status" aria-live="polite"></span>
		</div>
		<p class="sh-warn" id="nl-legacy" hidden></p>
		<div class="nl-table-wrap">
			<table class="nl-table" id="nl-table">
				<thead><tr><th scope="col">Abonnent</th><th scope="col">Mails</th><th scope="col">Quelle</th><th scope="col">Stand</th><th scope="col"><span class="sh-vh">Aktionen</span></th></tr></thead>
				<tbody id="nl-rows"></tbody>
			</table>
		</div>
		<details class="nl-import" id="nl-import">
			<summary>Liste importieren</summary>
			<div class="nl-import-body">
				<p class="sh-help">Für Adressen aus dem alten System. Die Datei braucht je Zeile eine E-Mail-Adresse und einen Namen (CSV, Excel-Export als CSV oder einfach eine Liste). Doppelte Adressen werden erkannt: in der Datei selbst und in der Liste, auch wenn eine Adresse nur ein +Zusatz oder Großbuchstaben unterscheidet. Wer sich abgemeldet hat, wird nie wieder aufgenommen.</p>
				<div class="nl-imp-step">
					<label class="sh-btn nl-file" for="nl-file">Datei wählen</label>
					<input type="file" id="nl-file" class="sh-vh" accept=".csv,.txt,.tsv,text/csv,text/plain"/>
					<span class="detail-status" id="nl-filename">oder die Liste hier einfügen</span>
					<label class="sh-vh" for="nl-text">Liste einfügen</label>
					<textarea id="nl-text" rows="5" placeholder="E-Mail;Name&#10;anna@example.com;Anna Muster" spellcheck="false"></textarea>
				</div>
				<fieldset class="nl-imp-mode">
					<legend>Wie wurden die Gäste im alten System aufgenommen?</legend>
					<label class="co-check"><input type="radio" name="nl-mode" value="blocked" checked/> Ohne Nachweis: Adressen nur ablegen, gesperrt. Es geht keine Mail raus, bis der Gast selbst bestätigt.</label>
					<label class="co-check"><input type="radio" name="nl-mode" value="confirmed"/> Mit dokumentierter Einwilligung: die Gäste bekommen ab sofort Neuigkeiten.</label>
					<div class="nl-imp-attest" id="nl-attest" hidden>
						<label for="nl-origin">Woher stammt die Einwilligung?</label>
						<input type="text" id="nl-origin" maxlength="160" placeholder="z. B. Anmeldung im alten Webshop mit Bestätigungsmail, 2021 bis 2026" autocomplete="off"/>
						<label class="co-check"><input type="checkbox" id="nl-attest-box"/> Ich bestätige, dass diese Gäste ausdrücklich in Mails mit Neuigkeiten eingewilligt haben und ich das nachweisen kann. Meine Bestätigung wird bei jeder Adresse als Nachweis gespeichert.</label>
					</div>
				</fieldset>
				<p class="nl-imp-actions"><button type="button" class="sh-btn" id="nl-check">Liste prüfen</button></p>
				<div id="nl-preview" hidden>
					<ul class="nl-sum" id="nl-sum"></ul>
					<div class="nl-table-wrap"><table class="nl-table nl-sample"><thead><tr><th scope="col">Zeile</th><th scope="col">Adresse</th><th scope="col">Name</th><th scope="col">Ergebnis</th></tr></thead><tbody id="nl-sample"></tbody></table></div>
					<p class="sh-help" id="nl-sample-note"></p>
					<p class="nl-imp-actions"><button type="button" class="sh-btn is-primary" id="nl-run">Importieren</button></p>
				</div>
				<p class="detail-status" id="nl-imp-msg" role="status" aria-live="polite"></p>
				<div id="nl-batches" hidden>
					<h3 class="nl-h3">Bisherige Importe</h3>
					<ul class="nl-batch" id="nl-batch-list"></ul>
				</div>
			</div>
		</details>
		<p class="sh-help" id="nl-empty" hidden>Noch keine Abonnenten. Gäste melden sich im Bestellablauf, im Chat, im Kundenkonto und bei der Reservierung an und bestätigen per Mail.</p>
	</div>
	<div id="nl-panel-mail" role="tabpanel" aria-labelledby="nl-tab-mail" hidden>
		<div id="nlm-root"></div>
		<p class="detail-status" id="nlm-live" role="status" aria-live="polite"></p>
	</div>
	<div id="nl-panel-auto" role="tabpanel" aria-labelledby="nl-tab-auto" hidden>
		<div id="nla-root"></div>
	</div>
	<div id="nl-panel-sender" role="tabpanel" aria-labelledby="nl-tab-sender" hidden>
		<div id="nls-root"></div>
	</div>
</div>
<script src="js/newsletter.js?v=<?php echo @filemtime(__DIR__.'/../js/newsletter.js'); ?>"></script>
<script src="js/newsletter_mail.js?v=<?php echo @filemtime(__DIR__.'/../js/newsletter_mail.js'); ?>"></script>
<script src="js/newsletter_sender.js?v=<?php echo @filemtime(__DIR__.'/../js/newsletter_sender.js'); ?>"></script>
