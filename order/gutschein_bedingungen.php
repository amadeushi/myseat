<?php
/*
 * Gutschein-Bedingungen and the information about the withdrawal for the voucher shop. DRAFT in the wording of the AGB (§ 5 Gutscheine, § 6 Widerruf): the owner has to check it (setting gv_terms_ok)
 * before the shop opens, a lawyer should look at the withdrawal part. Not legal advice. Staff see a draft note while the wording is not released.
 */
require __DIR__.'/gutschein.inc.php';
$h = 'shop_h';
$lapse = $gv_cfg['lapse'];
$lines = gv_legal_lines();
gv_page_head('Gutschein-Bedingungen');
?>
	<main class="gv-page">
		<h1>Gutschein-Bedingungen</h1>
		<?php if (!$gv_cfg['terms_ok'] && $gv_staff): ?><p class="gv-draft">Entwurf: Diese Texte sind noch nicht freigegeben. Bitte prüfen (am besten mit Anwalt und Steuerberater), dann im Backend unter Gutscheine &gt; Einstellungen den Haken "Texte geprüft" setzen.</p><?php endif; ?>

		<div class="gv-card gv-short"><h2>Das Wichtigste in vier Sätzen</h2>
			<ol>
				<li>Der Gutschein gilt bei uns im Restaurant und im Webshop (geliefert wird im Stadtgebiet Hildesheim).</li>
				<li>Du kannst Teilbeträge einlösen. Eine Barauszahlung gibt es nicht.</li>
				<li>Er ist drei Jahre gültig, gerechnet ab dem Ende des Jahres, in dem er ausgestellt wurde.</li>
				<li>Er gilt wie Bargeld: Wer Nummer und Code kennt, kann ihn einlösen, bei Verlust gibt es keinen Ersatz.</li>
			</ol>
			<p class="co-hint">Die genauen Regeln stehen in den Abschnitten darunter.</p>
		</div>

		<h2>1. Was du kaufst</h2>
		<p>Einen Gutschein über einen festen Eurobetrag für Speisen und Getränke bei <?php echo $h($gv_brand); ?> (Restaurant, Abholung und Lieferservice). Es ist ein Mehrzweck-Gutschein: Die Umsatzsteuer entsteht erst, wenn der Gutschein eingelöst wird.</p>

		<h2>2. Bestellung und Bezahlung</h2>
		<p>Mit dem Klick auf "Zahlungspflichtig bestellen" gibst du eine verbindliche Bestellung ab. Du bezahlst online über Mollie. Der Vertrag kommt zustande, sobald wir deine Zahlung bestätigt haben. Erst dann wird der Gutschein freigeschaltet.</p>

		<h2>3. Wie du ihn bekommst</h2>
		<ul>
			<li><strong>PDF per E-Mail:</strong> sofort nach der Zahlung, zum Ausdrucken oder Weiterleiten.</li>
			<li><strong>Per Post:</strong> als gedruckte Karte<?php echo $gv_cfg['post_mode'] === 'echtpost' ? ' (Postkarte A6 ohne Umschlag, Nummer und Code stehen offen darauf)' : ($gv_cfg['post_mode'] === 'blank' ? ', nummeriert,' : ' mit deinem Motiv und deinen Worten, im Umschlag,'); ?> an die angegebene Adresse. Die Versandkosten (<?php echo $h(gv_money($gv_cfg['fee'])); ?>) siehst du vor der Bestellung.</li>
			<li><strong>Abholung:</strong> die gedruckte Karte liegt im Restaurant für dich bereit, wir melden uns per E-Mail.</li>
		</ul>

		<h2>4. Einlösen</h2>
		<p>Der Gutschein wird im Restaurant mit der Gutscheinnummer eingelöst, beim Online-Bestellen mit Nummer und Sicherheitscode. Du kannst den ganzen Betrag oder einen Teil einlösen, das Restguthaben bleibt auf dem Gutschein. Eine Barauszahlung ist nicht möglich. Der Gutschein ist übertragbar und gilt wie Bargeld: Wer Nummer und Code kennt, kann ihn einlösen.</p>

		<h2>5. Gültigkeit</h2>
		<p>Der Gutschein ist drei Jahre gültig, gerechnet ab dem Ende des Jahres, in dem er ausgestellt wurde. Nicht eingelöstes Guthaben verfällt danach.</p>

		<h2>6. Verlust</h2>
		<p>Bitte bewahre Gutschein, Nummer und Sicherheitscode sorgfältig auf. Bei Verlust können wir den Gutschein nur ersetzen oder sperren, wenn du uns die Bestellung nachweisen kannst.</p>

		<h2>7. Dein eigenes Bild</h2>
		<p>Wenn du ein eigenes Bild hochlädst, bestätigst du, dass du es verwenden darfst und dass es keine Rechte Dritter verletzt und nichts Rechtswidriges zeigt. Wir verwenden das Bild nur für deinen Gutschein und löschen es, wenn der Gutschein nicht bestellt wird. Wir dürfen Bilder ablehnen.</p>

		<h2>8. Widerrufsrecht</h2>
		<?php if ($lapse): ?>
		<p><strong>Gedruckte Gutscheine (Post, Abholung):</strong> Du hast das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Frist beginnt, wenn du die Karte erhalten hast.</p>
		<p><strong>PDF-Gutscheine:</strong> Du hast beim Bestellen ausdrücklich zugestimmt, dass wir den Gutschein sofort bereitstellen, und bestätigt, dass dein Widerrufsrecht erlischt, sobald er bereitgestellt wurde.</p>
		<?php else: ?>
		<p>Du hast das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsschlusses (PDF-Gutschein) bzw. ab dem Tag, an dem du die gedruckte Karte erhalten hast.</p>
		<?php endif; ?>
		<p>Um dein Widerrufsrecht auszuüben, nutze die Funktion <a href="gutschein_widerruf.php">Vertrag widerrufen</a> auf unserer Website, oder schreib uns eindeutig, dass du den Vertrag widerrufst<?php echo $lines ? ' ('.$h(implode(', ', $lines)).')' : ''; ?>. Zur Wahrung der Frist genügt es, dass du die Mitteilung vor Ablauf der Frist absendest.</p>
		<p><strong>Folgen des Widerrufs:</strong> Wenn du widerrufst, erstatten wir dir alle Zahlungen, die wir von dir erhalten haben, einschließlich der Versandkosten, unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag, an dem dein Widerruf bei uns eingegangen ist. Wir nutzen dafür dasselbe Zahlungsmittel, das du bei der Bestellung eingesetzt hast. Der Gutschein wird mit dem Widerruf gesperrt. Ein schon teilweise eingelöster Gutschein wird mit dir persönlich geklärt.</p>

		<h2>9. Datenschutz</h2>
		<p>Wir verwenden deine Angaben nur, um die Bestellung abzuwickeln und den Gutschein zu erstellen. Mehr dazu in der <?php echo $gv_privacy ? '<a href="'.$h($gv_privacy).'">Datenschutzerklärung</a>' : 'Datenschutzerklärung'; ?>.</p>
		<?php if ($lines): ?><p style="margin-top:22px"><?php echo implode('<br>', array_map($h, $lines)); ?></p><?php endif; ?>
	</main>
<?php gv_page_foot(); ?>
