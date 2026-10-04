<?php
/*
 * Manual page of the pizza configurator for the team: how an ingredient gets into it, how its symbol is chosen, what needs a developer.
 * The rules of the automatic symbol and the names of the symbols come from the code itself (shop_item_icon_rules(), shop_item_icon_labels()),
 * the pictures from order/pizza.js, so the page cannot differ from what the shop does. Needs a backend login with Settings-General like the menu editor.
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Settings-General')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$labels = shop_item_icon_labels();
$rules = shop_item_icon_rules();
// "try a name": which symbol the system picks for it (a plain GET form, so the answer is the real function and not a copy of it)
$try = isset($_GET['name']) ? trim(mb_substr((string)$_GET['name'], 0, 120)) : '';
$tryKey = $try !== '' ? shop_item_icon($try) : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="color-scheme" content="dark light"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Handbuch Pizza-Konfigurator &ndash; <?php echo $h($brand); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<link rel="stylesheet" href="css/handbuch.css?v=<?php echo @filemtime(__DIR__.'/css/handbuch.css'); ?>"/>
</head>
<body class="hb">
	<header class="hb-top">
		<a class="hb-back" href="main_page.php?p=10">&larr; Zur Speisekarte</a>
		<h1>Handbuch: Pizza-Konfigurator</h1>
		<p class="hb-lead">Wie neue Zutaten in den Konfigurator kommen, woher das Bild auf der Pizza kommt und wann ein Entwickler gebraucht wird.</p>
		<nav class="hb-toc" aria-label="Inhalt">
			<a href="#kurz">Kurz gesagt</a><a href="#neu">Neue Zutat anlegen</a><a href="#symbol">Das Symbol</a><a href="#probe">Name ausprobieren</a><a href="#regeln">Erkennung am Namen</a><a href="#galerie">Alle Symbole</a><a href="#teig">Teig und Grundlage</a><a href="#fragen">Häufige Fragen</a><a href="#entwickler">Wann ein Entwickler nötig ist</a>
		</nav>
	</header>
	<main class="hb-main">
		<section id="kurz">
			<h2>Kurz gesagt</h2>
			<ul>
				<li>Alles, was der Konfigurator anbietet, kommt <strong>aus der Speisekarte</strong>: Zutaten, Preise, Höchstmengen, Reiter. Eine neue Zutat dort anzulegen genügt, sie erscheint sofort im Konfigurator und in der Bestellung.</li>
				<li>Das <strong>Bild auf der Pizza</strong> wird pro Zutat gewählt: entweder automatisch am Namen erkannt oder von dir im Feld „Symbol“ ausgesucht. Es gibt <?php echo count($labels); ?> fertige Symbole.</li>
				<li>Nur ein <strong>ganz neues Bild</strong> (zum Beispiel Avocado) oder eine neue Pizzaform braucht einen Entwickler. Bis dahin hilft ein ähnliches vorhandenes Symbol.</li>
			</ul>
		</section>

		<section id="neu">
			<h2>Neue Zutat anlegen</h2>
			<ol>
				<li>Im Backend auf <strong>Speisekarte</strong> gehen und oben <strong>Zubehörgruppen</strong> wählen.</li>
				<li>Die Gruppe öffnen, in der die Zutat stehen soll (zum Beispiel „Obst &amp; Gemüse“ oder „Fleisch“). Oder mit <strong>Neue Gruppe</strong> eine neue Gruppe anlegen, sie ist dann ein neuer Reiter im Konfigurator.</li>
				<li>Unter <strong>Optionen</strong> auf <strong>+ Option</strong> klicken (oder <strong>Mehrere einfügen</strong>, eine Zutat pro Zeile, Preis nach einem Semikolon, zum Beispiel <code>Avocado; 1,50</code>).</li>
				<li>Ausfüllen: <strong>Name</strong>, <strong>Aufpreis €</strong>, <strong>max.</strong> (wie oft wählbar) und <strong>Symbol</strong> (siehe unten).</li>
				<li><strong>Speichern</strong> klicken.</li>
				<li>Prüfen, ob die Gruppe zum Gericht gehört: bei der Gruppe steht unten „Verwendet bei:“ die Liste der Gerichte. Fehlt das Pizza-Gericht dort, im Reiter <strong>Gerichte</strong> das Gericht öffnen und unter <strong>Zubehörgruppen</strong> die Gruppe hinzufügen.</li>
				<li>Im Shop die Pizza öffnen und die neue Zutat antippen: Sie liegt auf der Pizza und im Warenkorb.</li>
			</ol>
			<p class="hb-note">Preise gelten immer so, wie sie in der Speisekarte stehen. Bei Gerichten mit mehreren Größen oder Teigen gibt es den Faktor „Zubehör ×“ an der Variante: bei 1,3 kostet ein Belag 30&nbsp;% mehr.</p>
		</section>

		<section id="symbol">
			<h2>Das Symbol: woher das Bild auf der Pizza kommt</h2>
			<p>Im Feld <strong>Symbol</strong> jeder Option stehen drei Möglichkeiten:</p>
			<dl class="hb-dl">
				<dt>Automatisch</dt><dd>Das System liest den <em>Namen</em> und sucht ein Wort aus der Liste unten. Gefunden: Das passende Symbol kommt von selbst. Nicht gefunden: Die Zutat bekommt ein „+“ statt eines Bildes (sie ist wählbar und bezahlbar, aber auf der Pizza nicht zu sehen).</dd>
				<dt>Ein Symbol aus der Liste</dt><dd>Du wählst selbst. Das gilt immer, egal wie die Zutat heißt. Gut, wenn der Name kein Stichwort enthält oder ein ähnliches Bild passt (zum Beispiel „Rucola“ für Basilikum).</dd>
				<dt>Kein Belag</dt><dd>Die Option bleibt wählbar, wird aber nicht auf die Pizza gelegt. Richtig für Extras ohne Bild, zum Beispiel „Extra Teig“ oder „geschnitten“.</dd>
			</dl>
			<p class="hb-note">Soßen zum Dippen stehen als kleine Schälchen neben der Pizza, nicht darauf. Wer „zum Dippen“ im Namen hat, bekommt automatisch das Dip-Symbol.</p>
		</section>

		<section id="probe">
			<h2>Name ausprobieren</h2>
			<p>Tippe den Namen einer Zutat ein, so wie du sie anlegen willst. Die Seite zeigt, welches Symbol das System dafür wählt.</p>
			<form class="hb-try" method="get" action="handbuch_konfigurator.php#probe">
				<label for="hb-name">Name der Option</label>
				<input type="text" id="hb-name" name="name" maxlength="120" value="<?php echo $h($try); ?>" placeholder="zum Beispiel Salami scharf" autocomplete="off"/>
				<button type="submit">Prüfen</button>
			</form>
			<?php if ($try !== ''): ?>
			<div class="hb-result" role="status">
				<?php if ($tryKey === ''): ?>
					<span class="hb-sym" data-sym="plus"></span>
					<div><strong>Kein Symbol erkannt</strong> für „<?php echo $h($try); ?>“. Die Zutat bekommt ein „+“ und erscheint nicht auf der Pizza. Wähle im Feld Symbol ein ähnliches Bild oder lass es so.</div>
				<?php else: ?>
					<span class="hb-sym" data-sym="<?php echo $h($tryKey); ?>"></span>
					<div><strong><?php echo $h(isset($labels[$tryKey]) ? $labels[$tryKey] : $tryKey); ?></strong> wird für „<?php echo $h($try); ?>“ automatisch gewählt.</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</section>

		<section id="regeln">
			<h2>Erkennung am Namen</h2>
			<p>Das System prüft von oben nach unten, <strong>das erste passende Wort gewinnt</strong>. Groß- und Kleinschreibung ist egal, das Wort darf irgendwo im Namen stehen. So wird aus „Salami scharf“ die Salami und aus „Sambal Hollandaise auf Pizza“ die Sambal-Soße, weil „Sambal“ weiter oben steht als „Hollandaise“.</p>
			<table class="hb-table">
				<thead><tr><th scope="col">Wort im Namen</th><th scope="col">Symbol</th></tr></thead>
				<tbody>
				<?php foreach ($rules as $r): ?>
					<tr><td><?php echo $h($r[2]); ?></td><td><span class="hb-sym hb-sym-s" data-sym="<?php echo $h($r[1]); ?>"></span> <?php echo $h(isset($labels[$r[1]]) ? $labels[$r[1]] : $r[1]); ?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="hb-note">Vorsicht bei allgemeinen Wörtern: „Käse“ erkennt immer geschmolzenen Käse (zum Beispiel auch „Doppelt Käse“), „Soße“ erkennt eine allgemeine Soße. Ein Name wie „Tomatensoße“ wird deshalb als Soße erkannt, nicht als Tomate. Wenn das nicht passt, im Feld Symbol selbst wählen.</p>
		</section>

		<section id="galerie">
			<h2>Alle Symbole</h2>
			<p>Das sind die fertigen Bilder, die im Feld Symbol zur Auswahl stehen. Die Art sagt, wie das Bild auf der Pizza wirkt.</p>
			<div class="hb-gallery" id="hb-gallery" aria-live="polite"><p class="hb-note">Die Symbole werden geladen ...</p></div>
		</section>

		<section id="teig">
			<h2>Teig und Grundlage</h2>
			<ul>
				<li><strong>Tomatensoße und Käse</strong> liegen auf jeder Pizza schon dabei. Sie sind keine Optionen und werden unter der Pizza als „Inklusive: Tomatensoße, Käse“ genannt. Die Tomatensoße ist zu sehen, wenn in der Beschreibung des Gerichts „Tomat…“ steht (oder die Beschreibung leer ist).</li>
				<li><strong>Vegane Pizza:</strong> Steht „vegan“ im Titel des Gerichts oder im Namen des Teigs, wird der Käse als blasser „Pizzaschmelz (vegan)“ gezeigt.</li>
				<li><strong>Dinkel, Roggen, Vollkorn:</strong> Steht eines dieser Wörter im Namen des Teigs, sieht der Teig körnig aus. Jeder andere Teigname gibt den normalen hellen Teig.</li>
				<li><strong>Teige</strong> sind die Varianten des Gerichts. Ein neuer Teig erscheint von selbst als Auswahl oben im Konfigurator.</li>
				<li><strong>Form:</strong> Beim Gericht gibt es das Feld „Wunschpizza-Konfigurator“: Aus, Pizza (rund) oder die ovale Flammkuchenart, bei der Tomatensoße und Käse immer inklusive sind.</li>
			</ul>
		</section>

		<section id="fragen">
			<h2>Häufige Fragen</h2>
			<details><summary>Die neue Zutat erscheint nicht im Konfigurator.</summary>
				<p>Prüfe, ob die Gruppe beim Gericht eingetragen ist (Gerichte, Gericht öffnen, Zubehörgruppen) und ob die Option einen Namen hat. Die Seite im Shop neu laden. Beim Symbol „Kein Belag“ erscheint die Zutat in der Liste, aber nicht auf der Pizza.</p></details>
			<details><summary>Die Zutat hat ein „+“ statt eines Bildes.</summary>
				<p>Der Name enthält kein Stichwort aus der Liste. Wähle im Feld Symbol ein ähnliches Bild, oder schau unter „Wann ein Entwickler nötig ist“.</p></details>
			<details><summary>Die Zutat hat ein falsches Bild.</summary>
				<p>Ein Wort im Namen hat ein anderes Stichwort ausgelöst (siehe Tabelle, weiter oben gewinnt). Wähle im Feld Symbol selbst das richtige Bild.</p></details>
			<details><summary>Die Zutat soll wählbar sein, aber nicht auf der Pizza liegen.</summary>
				<p>Im Feld Symbol „Kein Belag“ wählen.</p></details>
			<details><summary>Wo ändere ich den Preis?</summary>
				<p>Bei der Option unter Zubehörgruppen, Feld Aufpreis €. Die Pizza im Konfigurator und der Warenkorb folgen sofort.</p></details>
			<details><summary>Ich habe das Symbol geändert, im Shop sieht man es nicht.</summary>
				<p>Die Pizza im Shop schließen und neu öffnen, notfalls die Seite neu laden. Der Konfigurator holt die Daten beim Öffnen.</p></details>
		</section>

		<section id="entwickler">
			<h2>Wann ein Entwickler nötig ist</h2>
			<ul>
				<li>Eine Zutat soll ein <strong>eigenes neues Bild</strong> bekommen, das es noch nicht gibt (zum Beispiel Avocado, Ei, Speck). Das Bild wird gezeichnet und im Konfigurator, in der Auswahl der Speisekarte und bei Bedarf in der Namenserkennung eingetragen.</li>
				<li>Es soll eine <strong>neue Pizzaform</strong> geben (heute: rund und oval) oder eine <strong>andere Grundlage</strong>, zum Beispiel eine weiße Pizza ohne Tomatensoße, die trotz Beschreibung „Tomatensoße“ anders aussehen soll.</li>
				<li>Ein neuer Teig soll ein <strong>eigenes Aussehen</strong> haben. Heute gibt es nur den normalen und den körnigen Teig (Dinkel, Roggen, Vollkorn).</li>
				<li>Die <strong>Namenserkennung</strong> soll um neue Wörter erweitert werden, damit nicht jede neue Zutat von Hand ein Symbol bekommen muss.</li>
			</ul>
		</section>
	</main>
	<script>window.HB_SYMS = <?php echo json_encode(array_map(function ($k, $l) { return array('key' => $k, 'label' => $l); }, array_keys($labels), array_values($labels)), JSON_UNESCAPED_UNICODE); ?>;</script>
	<script src="../order/pizza.js?v=<?php echo @filemtime(__DIR__.'/../order/pizza.js'); ?>"></script>
	<script>
	(function () {
		'use strict';
		var KIND = { piece: 'liegt als Stück auf der Pizza', sauce: 'Soße, zieht sich als Schlieren über die Pizza', melt: 'geschmolzener Käse, kommt als Schicht', side: 'Beilage, steht neben der Pizza' };
		function svg(key) {
			var s = window.PizzaLab && window.PizzaLab.symbol ? window.PizzaLab.symbol(key) : null; if (!s) { return ''; }
			return '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false">' + s.g + '</svg>';
		}
		Array.prototype.forEach.call(document.querySelectorAll('[data-sym]'), function (el) { el.innerHTML = svg(el.dataset.sym); });
		var g = document.getElementById('hb-gallery'); if (!g || !window.HB_SYMS) { return; }
		g.innerHTML = window.HB_SYMS.map(function (x) {
			var s = window.PizzaLab.symbol(x.key);
			return '<figure class="hb-card"><span class="hb-sym">' + svg(x.key) + '</span><figcaption><strong>' + x.label + '</strong><small>' + (s ? KIND[s.t] || '' : '') + '</small></figcaption></figure>';
		}).join('') + '<figure class="hb-card"><span class="hb-sym">' + svg('plus') + '</span><figcaption><strong>Kein Symbol (+)</strong><small>wählbar, aber nicht auf der Pizza zu sehen</small></figcaption></figure>';
	})();
	</script>
</body>
</html>
