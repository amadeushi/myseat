<?php
// Gutscheine (p=15): redeem with the PIN of the employee, stock of all vouchers, sell a numbered blank, import the open vouchers of the former system, PINs.
// All work is done by web/js/vouchers.js through web/ajax/shop_vouchers.php; the rules (balance, lock against double spending, PIN lock) are in web/classes/shop_voucher.class.php.
require_once __DIR__.'/../classes/shop.class.php';
shop_ensure_schema();
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
?>
<div class="content sms-page nl-page gv-page" id="gv-page" data-token="<?php echo htmlspecialchars($_SESSION['shop_admin_token']); ?>">
	<div class="nl-tabs" role="tablist" aria-label="Gutscheine">
		<button type="button" role="tab" id="gv-tab-redeem" aria-controls="gv-panel-redeem" aria-selected="true">Einlösen</button>
		<button type="button" role="tab" id="gv-tab-orders" aria-controls="gv-panel-orders" aria-selected="false" tabindex="-1">Aufträge</button>
		<button type="button" role="tab" id="gv-tab-stock" aria-controls="gv-panel-stock" aria-selected="false" tabindex="-1">Bestand</button>
		<button type="button" role="tab" id="gv-tab-issue" aria-controls="gv-panel-issue" aria-selected="false" tabindex="-1">Vordruck verkaufen</button>
		<button type="button" role="tab" id="gv-tab-import" aria-controls="gv-panel-import" aria-selected="false" tabindex="-1">Import</button>
		<button type="button" role="tab" id="gv-tab-pins" aria-controls="gv-panel-pins" aria-selected="false" tabindex="-1">PINs</button>
		<button type="button" role="tab" id="gv-tab-settings" aria-controls="gv-panel-settings" aria-selected="false" tabindex="-1">Einstellungen</button>
	</div>

	<div id="gv-panel-redeem" role="tabpanel" aria-labelledby="gv-tab-redeem">
		<p class="sh-help">Gutscheinnummer eintippen, prüfen, Betrag eingeben und mit deiner PIN einlösen. Ein Teilbetrag lässt den Rest auf dem Gutschein stehen. Wer einlöst, steht mit Zeit und Betrag im Verlauf des Gutscheins.</p>
		<form class="gv-form" id="gv-lookup-form" autocomplete="off">
			<div class="gv-field"><label for="gv-number">Gutscheinnummer</label><input type="text" id="gv-number" class="gv-mono" maxlength="30" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="AMD-XXXX-XXXX"/></div>
			<button type="submit" class="sh-btn" id="gv-lookup">Prüfen</button>
		</form>
		<div class="gv-card" id="gv-card" hidden>
			<div class="gv-card-head"><span class="gv-card-number gv-mono" id="gv-card-number"></span><span class="offer-badge" id="gv-card-state"></span></div>
			<dl class="gv-facts">
				<div><dt>Guthaben</dt><dd id="gv-card-balance"></dd></div>
				<div><dt>Wert</dt><dd id="gv-card-face"></dd></div>
				<div><dt>Gültig bis</dt><dd id="gv-card-valid"></dd></div>
				<div><dt>Art</dt><dd id="gv-card-kind"></dd></div>
			</dl>
			<p class="sh-warn" id="gv-card-warn" hidden></p>
			<form class="gv-form gv-redeem" id="gv-redeem-form" autocomplete="off">
				<div class="gv-field"><label for="gv-amount">Betrag in Euro</label><input type="text" id="gv-amount" inputmode="decimal" maxlength="9" autocomplete="off" placeholder="alles"/></div>
				<div class="gv-field"><label for="gv-ref">Notiz (optional)</label><input type="text" id="gv-ref" maxlength="100" autocomplete="off" placeholder="z. B. Tisch 12"/></div>
				<div class="gv-field"><label for="gv-staff">Wer löst ein?</label><select id="gv-staff"></select></div>
				<div class="gv-field"><label for="gv-pin">PIN</label><input type="password" id="gv-pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off" class="gv-pin"/></div>
				<button type="submit" class="sh-btn is-primary" id="gv-redeem">Einlösen</button>
			</form>
			<h3 class="nl-h3">Verlauf</h3>
			<div class="nl-table-wrap"><table class="nl-table"><thead><tr><th scope="col">Zeit</th><th scope="col">Was</th><th scope="col">Betrag</th><th scope="col">Rest</th><th scope="col">Wer</th><th scope="col">Notiz</th></tr></thead><tbody id="gv-card-moves"></tbody></table></div>
		</div>
		<p class="detail-status" id="gv-redeem-msg" role="status" aria-live="polite"></p>
	</div>

	<div id="gv-panel-stock" role="tabpanel" aria-labelledby="gv-tab-stock" hidden>
		<ul class="gv-stats" id="gv-stats" aria-label="Kennzahlen"></ul>
		<div class="nl-counts" id="gv-counts" role="group" aria-label="Filter"></div>
		<div class="nl-bar">
			<label class="sh-vh" for="gv-q">Suchen</label>
			<input type="search" id="gv-q" placeholder="Nummer, Käufer oder Notiz" autocomplete="off" enterkeyhint="search"/>
			<a class="sh-btn gv-export" id="gv-export" href="#">Als CSV herunterladen</a>
			<span class="detail-status" id="gv-stock-msg" role="status" aria-live="polite"></span>
		</div>
		<div class="nl-table-wrap">
			<table class="nl-table" id="gv-table">
				<thead><tr><th scope="col">Nummer</th><th scope="col">Guthaben</th><th scope="col">Gültig bis</th><th scope="col">Käufer</th><th scope="col">Stand</th><th scope="col"><span class="sh-vh">Aktionen</span></th></tr></thead>
				<tbody id="gv-rows"></tbody>
			</table>
		</div>
		<p class="sh-help" id="gv-empty" hidden>Noch keine Gutscheine in dieser Ansicht.</p>
	</div>

	<div id="gv-panel-issue" role="tabpanel" aria-labelledby="gv-tab-issue" hidden>
		<p class="sh-help">Für einen nummerierten Vordruck, der verschickt oder im Restaurant übergeben wird. Tippe die aufgedruckte Nummer ein (leer lassen, dann vergibt das System eine). Danach kann der Gutschein sofort eingelöst werden. Der Sicherheitscode erscheint nach dem Anlegen einmal: schreib ihn auf den Vordruck, damit der Gast online einlösen kann.</p>
		<form class="gv-form gv-issue" id="gv-issue-form" autocomplete="off">
			<div class="gv-field"><label for="gv-i-number">Nummer des Vordrucks</label><input type="text" id="gv-i-number" class="gv-mono" maxlength="30" autocapitalize="characters" autocomplete="off" spellcheck="false" placeholder="automatisch"/></div>
			<div class="gv-field"><label for="gv-i-face">Wert in Euro</label><input type="text" id="gv-i-face" inputmode="decimal" maxlength="9" autocomplete="off" placeholder="50"/></div>
			<div class="gv-field"><label for="gv-i-valid">Gültig bis (optional)</label><input type="text" id="gv-i-valid" maxlength="10" autocomplete="off" placeholder="Standard: 31.12. in drei Jahren"/></div>
			<div class="gv-field"><label for="gv-i-buyer">Käufer (optional)</label><input type="text" id="gv-i-buyer" maxlength="80" autocomplete="off"/></div>
			<div class="gv-field"><label for="gv-i-email">E-Mail des Käufers (optional)</label><input type="email" id="gv-i-email" maxlength="160" autocomplete="off"/></div>
			<div class="gv-field"><label for="gv-i-recipient">Für (optional)</label><input type="text" id="gv-i-recipient" maxlength="80" autocomplete="off"/></div>
			<div class="gv-field gv-wide"><label for="gv-i-note">Notiz (optional)</label><input type="text" id="gv-i-note" maxlength="200" autocomplete="off" placeholder="z. B. bar bezahlt im Restaurant"/></div>
			<button type="submit" class="sh-btn is-primary" id="gv-issue">Gutschein anlegen</button>
		</form>
		<div class="gv-done" id="gv-issue-done" hidden></div>
		<p class="detail-status" id="gv-issue-msg" role="status" aria-live="polite"></p>
	</div>

	<div id="gv-panel-import" role="tabpanel" aria-labelledby="gv-tab-import" hidden>
		<p class="sh-help">Für die offenen Gutscheine aus dem alten System. Je Zeile: Nummer; Restwert; Wert (optional); gültig bis (optional, TT.MM.JJJJ). Die Datei kann aus Excel als CSV kommen oder hier eingefügt werden. Eine Kopfzeile wird erkannt. Nummern, die es schon gibt oder doppelt in der Liste stehen, werden übersprungen. Übernommene Gutscheine lassen sich im Restaurant mit Nummer und PIN einlösen.</p>
		<div class="nl-imp-step">
			<label class="sh-btn nl-file" for="gv-file">Datei wählen</label>
			<input type="file" id="gv-file" class="sh-vh" accept=".csv,.txt,.tsv,text/csv,text/plain"/>
			<span class="detail-status" id="gv-filename">oder die Liste hier einfügen</span>
			<label class="sh-vh" for="gv-text">Liste einfügen</label>
			<textarea id="gv-text" rows="6" placeholder="Nummer;Restwert;Wert;gültig bis&#10;4711-0815;32,50;50;31.12.2027" spellcheck="false"></textarea>
		</div>
		<div class="gv-form"><div class="gv-field"><label for="gv-imp-valid">Gültig bis, wenn die Liste kein Datum nennt</label><input type="text" id="gv-imp-valid" maxlength="10" autocomplete="off" placeholder="31.12.2028"/></div>
			<button type="button" class="sh-btn" id="gv-imp-check">Liste prüfen</button></div>
		<div id="gv-imp-preview" hidden>
			<ul class="nl-sum" id="gv-imp-sum"></ul>
			<div class="nl-table-wrap"><table class="nl-table nl-sample"><thead><tr><th scope="col">Zeile</th><th scope="col">Nummer</th><th scope="col">Rest</th><th scope="col">Wert</th><th scope="col">Gültig bis</th><th scope="col">Ergebnis</th></tr></thead><tbody id="gv-imp-sample"></tbody></table></div>
			<p class="sh-help" id="gv-imp-note"></p>
			<p class="nl-imp-actions"><button type="button" class="sh-btn is-primary" id="gv-imp-run">Importieren</button></p>
		</div>
		<p class="detail-status" id="gv-imp-msg" role="status" aria-live="polite"></p>
	</div>

	<div id="gv-panel-pins" role="tabpanel" aria-labelledby="gv-tab-pins" hidden>
		<p class="sh-help">Jede Person, die Gutscheine einlöst, hat eine eigene PIN aus 4 bis 8 Ziffern. Die PIN wird nur als Prüfwert gespeichert und ist nicht lesbar. Nach 5 falschen Eingaben hintereinander ist die Person 15 Minuten gesperrt. Hier kannst du eine PIN neu vergeben.</p>
		<div class="nl-table-wrap">
			<table class="nl-table"><thead><tr><th scope="col">Person</th><th scope="col">PIN</th><th scope="col"><span class="sh-vh">Aktionen</span></th></tr></thead><tbody id="gv-pin-rows"></tbody></table>
		</div>
		<p class="detail-status" id="gv-pin-msg" role="status" aria-live="polite"></p>
	</div>

	<div id="gv-panel-orders" role="tabpanel" aria-labelledby="gv-tab-orders" hidden>
		<p class="sh-help">Bestellungen aus dem Gutschein-Shop. "Zu erledigen" sind bezahlte Karten für Post und Abholung: Vordruck nehmen, Sicherheitscode darauf schreiben, die aufgedruckte Nummer hier eintragen. Danach bekommt der Käufer eine Mail. Bei einem Widerruf ist der Gutschein sofort gesperrt, die Erstattung löst du hier mit einem Klick aus.</p>
		<div class="nl-counts" id="gv-o-counts" role="group" aria-label="Filter"></div>
		<div class="nl-table-wrap">
			<table class="nl-table" id="gv-o-table">
				<thead><tr><th scope="col">Bestellung</th><th scope="col">Käufer</th><th scope="col">Gutschein</th><th scope="col">Stand</th><th scope="col"><span class="sh-vh">Aktionen</span></th></tr></thead>
				<tbody id="gv-o-rows"></tbody>
			</table>
		</div>
		<p class="sh-help" id="gv-o-empty" hidden>Keine Aufträge in dieser Ansicht.</p>
		<p class="detail-status" id="gv-o-msg" role="status" aria-live="polite"></p>
	</div>

	<div id="gv-panel-settings" role="tabpanel" aria-labelledby="gv-tab-settings" hidden>
		<p class="sh-help">Hier schaltest du den Gutschein-Shop für Gäste ein. Er öffnet erst, wenn "Shop eingeschaltet" und "Texte geprüft" beide gesetzt sind. Die Seiten dazu: Bedingungen und Widerrufsfunktion sind ein Entwurf nach deinen AGB und gehören vor dem Start geprüft (Anwalt, Steuerberater).</p>
		<form class="gv-set" id="gv-set-form" autocomplete="off">
			<fieldset class="gv-set-group"><legend>Öffnung</legend>
				<label class="co-check"><input type="checkbox" name="gv_pay_on"/> Gutscheine beim Online-Bestellen und an der Telefonkasse einlösen (Nummer und Sicherheitscode; funktioniert auch für übernommene Gutscheine)</label>
				<label class="co-check"><input type="checkbox" name="gv_terms_ok"/> Texte geprüft: Gutschein-Bedingungen, Widerrufsfunktion und Mails sind von mir (und bei Bedarf Anwalt und Steuerberater) freigegeben</label>
				<label class="co-check"><input type="checkbox" name="gv_on"/> Shop eingeschaltet (Gäste können Gutscheine kaufen)</label>
				<p class="sh-help" id="gv-set-link"></p>
			</fieldset>
			<fieldset class="gv-set-group"><legend>Formen</legend>
				<label class="co-check"><input type="checkbox" name="gv_pdf_on"/> PDF per E-Mail (sofort)</label>
				<label class="co-check"><input type="checkbox" name="gv_post_on"/> Per Post (gedruckter Vordruck)</label>
				<label class="co-check"><input type="checkbox" name="gv_pickup_on"/> Abholung im Restaurant (gedruckter Vordruck)</label>
				<label class="co-check"><input type="checkbox" name="gv_own_motif"/> Gäste dürfen ein eigenes Bild hochladen</label>
			</fieldset>
			<fieldset class="gv-set-group"><legend>Karten per Post</legend>
				<label class="co-check"><input type="radio" name="gv_post_mode" value="print"/> Karte aus dem Shop: du druckst die persönliche Karte (Motiv, Worte, Nummer) selbst auf Karton und schickst sie im Umschlag oder legst sie bereit (empfohlen)</label>
				<label class="co-check"><input type="radio" name="gv_post_mode" value="blank"/> Nummerierter Vordruck ohne Motiv: du schreibst Nummer und Code von Hand darauf</label>
				<label class="co-check"><input type="radio" name="gv_post_mode" value="echtpost"/> Postkarte über EchtPost: nach der Zahlung wird die Karte automatisch gedruckt, frankiert und verschickt</label>
				<div class="gv-form">
					<div class="gv-field"><label for="gv-ep-key">EchtPost-Schlüssel (API-Key)</label><input type="password" id="gv-ep-key" autocomplete="off" maxlength="200" placeholder="ep_test_… oder Live-Schlüssel"/></div>
				</div>
				<p class="sh-help" id="gv-ep-state"></p>
				<p><button type="button" class="sh-btn" id="gv-ep-save">Schlüssel speichern</button> <button type="button" class="sh-btn" id="gv-ep-test">Verbindung prüfen</button> <button type="button" class="sh-btn" id="gv-ep-clear">Schlüssel löschen</button> <span class="detail-status" id="gv-ep-msg" role="status" aria-live="polite"></span></p>
				<p class="sh-help">So richtest du es ein: Konto bei echtpost.de anlegen, Guthaben aufladen und den Support bitten, eigene Motive per API freizuschalten. Dann unter Account > API keys einen Schlüssel erzeugen (ep_test_ ist der Testschlüssel, es wird nichts gedruckt oder berechnet) und hier speichern. Unter Account > Webhooks trägt man diese Adresse für card.sent ein: <strong class="gv-mono" id="gv-ep-hook"></strong></p>
				<p class="sh-warn">Die Karte reist ohne Umschlag. Gutscheinnummer und Sicherheitscode stehen offen auf der Vorderseite und sind für alle lesbar, die die Karte in der Hand haben. Die Adresse des Beschenkten geht an EchtPost, das gehört in die Datenschutzerklärung (und ein Auftragsverarbeitungsvertrag mit EchtPost). Eine Karte kostet dich 2,90 € netto, im Preis "Porto und Verpackung" für Post (unten) sollte das stecken.</p>
			</fieldset>
			<fieldset class="gv-set-group"><legend>Beträge</legend>
				<div class="gv-form">
					<div class="gv-field"><label for="gv-s-amounts">Zur Auswahl (Euro, mit Komma getrennt)</label><input type="text" id="gv-s-amounts" name="gv_amounts" maxlength="80"/></div>
					<div class="gv-field"><label for="gv-s-min">Kleinster freier Betrag (Euro)</label><input type="text" id="gv-s-min" name="gv_min_eur" inputmode="decimal" maxlength="7"/></div>
					<div class="gv-field"><label for="gv-s-max">Größter freier Betrag (Euro)</label><input type="text" id="gv-s-max" name="gv_max_eur" inputmode="decimal" maxlength="7"/></div>
					<div class="gv-field"><label for="gv-s-fee">Porto und Verpackung für Post (Euro)</label><input type="text" id="gv-s-fee" name="gv_post_fee" inputmode="decimal" maxlength="7"/></div>
				</div>
			</fieldset>
			<fieldset class="gv-set-group"><legend>Widerruf bei PDF-Gutscheinen</legend>
				<label class="co-check"><input type="radio" name="gv_withdraw_mode" value="days14"/> 14 Tage Widerruf wie in den AGB (empfohlen)</label>
				<label class="co-check"><input type="radio" name="gv_withdraw_mode" value="lapse"/> Widerrufsrecht erlischt mit der sofortigen Bereitstellung (Gast stimmt beim Kauf zu)</label>
				<p class="sh-warn">Die zweite Variante ist rechtlich umstritten, weil ein Wertgutschein nicht wie ein digitaler Inhalt behandelt werden muss. Bitte nur nach Rücksprache mit einem Anwalt einschalten. Gedruckte Gutscheine behalten immer die 14 Tage.</p>
			</fieldset>
			<fieldset class="gv-set-group"><legend>Motive</legend>
				<div id="gv-s-motifs" class="gv-motif-checks"></div>
			</fieldset>
			<button type="submit" class="sh-btn is-primary" id="gv-set-save">Speichern</button>
			<span class="detail-status" id="gv-set-msg" role="status" aria-live="polite"></span>
		</form>
	</div>
</div>
<script src="js/vouchers.js?v=<?php echo @filemtime(__DIR__.'/../js/vouchers.js'); ?>"></script>
