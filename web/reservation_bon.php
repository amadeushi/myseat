<?php
/*
 * Table sign of one reservation (?id=) or of all reservations of a day (?date=YYYY-MM-DD&outlet=) for the receipt printer (80 mm roll, 72 mm printable). One sign per table: a reservation at
 * three tables prints three signs "Schild 1 von 3" ... so that every table can be marked. Without an assigned table one sign
 * is printed with the free text table (or an empty line to write on). Needs a backend login (Reservation-Edit). Opened with
 * ?print=1 it prints at once; the reservation list loads it in a hidden frame (see includes/reservations_grid.inc.php).
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/feedback.class.php');
require_once('classes/tableplan.class.php');
require_once('classes/brand.class.php');
date_default_timezone_set('Europe/Berlin');

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
global $dbTables, $settings;
$cols = "`reservation_id`,`reservation_date`,`reservation_time`,`reservation_pax`,`reservation_guest_name`,`reservation_table`";
$resTable = "`".$dbTables->reservations."`";
if (!empty($_GET['date'])) {
	// all reservations of a day (the list of the day: confirmed, not cancelled, not on the waiting list; departed and no-shows skipped)
	$date = (string)$_GET['date'];
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) { http_response_code(400); echo 'Ungültiges Datum.'; exit; }
	$outlet = (int)(isset($_GET['outlet']) ? $_GET['outlet'] : 0);
	$reservations = fb_rows("SELECT $cols FROM $resTable WHERE `reservation_date` = ? AND `reservation_outlet_id` = ?
		AND IFNULL(`reservation_hidden`,0) = 0 AND IFNULL(`reservation_wait`,0) = 0 AND (`reservation_status` IS NULL OR `reservation_status` NOT IN ('DEP','NSW'))
		ORDER BY `reservation_time`, `reservation_id`", 'si', array($date, $outlet));
	if (!$reservations) { http_response_code(404); echo 'Für diesen Tag gibt es keine Reservierungen zum Drucken.'; exit; }
} else {
	$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
	$one = $id ? fb_row("SELECT $cols FROM $resTable WHERE `reservation_id` = ?", 'i', array($id)) : null;
	if (!$one) { http_response_code(404); echo 'Reservierung nicht gefunden.'; exit; }
	$reservations = array($one);
}

// one sign per table: the tables of the plan; else the free text split at , + / ; &; else one empty sign
$slips = array();
foreach ($reservations as $r) {
	$tables = array();
	try {
		foreach (tp_rows("SELECT t.`table_name` FROM ".tp_t('reservation_tables')." rt JOIN ".tp_t('tables')." t ON t.`table_id` = rt.`table_id`
			WHERE rt.`reservation_id` = ?", 'i', array((int)$r['reservation_id'])) as $t) { $tables[] = $t['table_name']; }
	} catch (Throwable $e) { $tables = array(); }
	if (!$tables) {
		foreach (preg_split('/[,+\/;&]+/', (string)$r['reservation_table']) as $t) { $t = trim($t); if ($t !== '') { $tables[] = $t; } }
	}
	if (!$tables) { $tables = array(''); }
	natsort($tables);
	$tables = array_values($tables);
	foreach ($tables as $i => $t) { $slips[] = array('r' => $r, 'table' => $t, 'i' => $i, 'n' => count($tables)); }
}

// names are stored partly as HTML entities ("L&uuml;tjen"): decode first, then escape once
$h = function ($s) { return htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8'); };
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$days = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
$title = count($reservations) > 1 ? 'Reservierungsschilder '.$reservations[0]['reservation_date'] : 'Reservierungsschild '.$reservations[0]['reservation_guest_name'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title><?php echo $h($title); ?></title>
	<link rel="stylesheet" href="fonts/fonts.css"/>
	<style>
		@page { size: 72mm auto; margin: 0; }
		* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		html, body { margin: 0; background: #fff; color: #000; }
		body { width: 72mm; margin: 0 auto; font-family: 'Raleway', Arial, Helvetica, sans-serif; text-align: center; font-variant-numeric: lining-nums; }
		.slip { width: 72mm; padding: 3mm 3.5mm 7mm; break-inside: avoid; }
		.slip + .slip { break-before: page; page-break-before: always; }
		/* the logo is white on transparent: print it black */
		.logo { display: block; width: 46mm; height: auto; margin: 0 auto 2mm; filter: brightness(0); }
		.logo-text { font-size: 16pt; font-weight: 800; letter-spacing: .3em; text-transform: uppercase; margin-bottom: 3mm; }
		.rule { border: 0; border-top: 1.1mm solid #000; margin: 0; }
		.kind { margin-top: 3mm; font-size: 15pt; font-weight: 800; letter-spacing: .2em; text-transform: uppercase; padding-left: .2em; }
		.date { margin-top: .6mm; font-size: 11pt; font-weight: 600; }
		.time { margin: 2mm 0 1mm; font-size: 58pt; font-weight: 800; line-height: 1; letter-spacing: -.02em; white-space: nowrap; }
		.time small { font-size: 16pt; font-weight: 700; letter-spacing: 0; margin-left: 1.2mm; }
		.who { padding: 2.4mm 0 2.6mm; border-top: .5mm solid #000; }
		.name { font-size: 25pt; font-weight: 800; line-height: 1.08; overflow-wrap: normal; word-break: normal; hyphens: manual; }
				.pax { margin-top: 1.6mm; font-size: 17pt; font-weight: 700; }
		.pax b { font-size: 26pt; font-weight: 800; }
		.table { border: 1.3mm solid #000; padding: 2.4mm 2mm 2.6mm; border-radius: 2.5mm; }
		.table .lbl { font-size: 11pt; font-weight: 700; letter-spacing: .32em; text-transform: uppercase; padding-left: .32em; }
		.table .no { font-size: 58pt; font-weight: 800; line-height: 1.02; overflow-wrap: normal; }
		.table .no.long { font-size: 30pt; }
		.table .blank { height: 15mm; border-bottom: .5mm dotted #000; margin: 2mm 6mm 0; }
		.table .seq { margin-top: 1mm; padding-top: 1.6mm; border-top: .5mm solid #000; font-size: 10.5pt; font-weight: 700; letter-spacing: .1em; }
		.foot { margin-top: 3mm; font-size: 8.5pt; font-weight: 600; }
		/* a two-line guest name already adds a full extra line of height; trim other vertical spacing back by about the
		   same amount (~6mm total) so the sign doesn't grow as tall and the paper length stays close to the one-line case */
		.slip.two-line { padding-top: 2mm; padding-bottom: 5mm; }
		.slip.two-line .time { margin: 1mm 0 0.5mm; }
		.slip.two-line .foot { margin-top: 1.5mm; }
		body.solo .slip { display: none; break-before: auto !important; page-break-before: auto !important; }
		body.solo .slip.cur { display: block; }
		@media screen { body { margin: 12px auto; } .slip { border: 1px dashed #999; margin-bottom: 12px; } }
	</style>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body>
<?php foreach ($slips as $sl): $r = $sl['r']; $i = $sl['i']; $n = $sl['n']; $label = preg_replace('/^Tisch\s+/i', '', $sl['table']);
	$ts = strtotime($r['reservation_date']); $dateText = $days[(int)date('w', $ts)].', '.date('d.m.Y', $ts); $time = substr($r['reservation_time'], 0, 5); $pax = (int)$r['reservation_pax']; ?>
	<section class="slip">
		<img class="logo" src="<?php echo $h(brand_logo_url()); ?>" alt="<?php echo $h($brand); ?>" onerror="this.outerHTML='&lt;div class=&quot;logo-text&quot;&gt;'+this.alt+'&lt;/div&gt;'"/>
		<hr class="rule"/>
		<div class="kind">Reservierung</div>
		<div class="date"><?php echo $h($dateText); ?></div>
		<div class="time"><?php echo $h($time); ?><small>Uhr</small></div>
		<div class="who">
			<div class="name"><?php echo $h($r['reservation_guest_name']); ?></div>
			<div class="pax"><b><?php echo $pax; ?></b> <?php echo $pax === 1 ? 'Person' : 'Personen'; ?></div>
		</div>
		<div class="table"><div class="lbl">Tisch</div>
			<?php if ($label !== ''): ?><div class="no<?php echo mb_strlen($label) > 6 ? ' long' : ''; ?>"><?php echo $h($label); ?></div>
			<?php else: ?><div class="blank"></div><?php endif; ?>
			<?php if ($n > 1): ?><div class="seq">Schild <?php echo $i + 1; ?> von <?php echo $n; ?></div><?php endif; ?>
		</div>
		<div class="foot">Wir freuen uns, dass Sie da sind</div>
	</section>
<?php endforeach; ?>
<script>
	(function () {
		// long names break only at spaces and hyphens: the type shrinks until the longest word fits its line
		var fit = function () {
			Array.prototype.forEach.call(document.querySelectorAll('.name, .table .no'), function (el) {
				var size = parseFloat(getComputedStyle(el).fontSize) * 0.75, min = 12;
				while (size > min && el.scrollWidth > el.clientWidth + 1) { size -= 0.5; el.style.fontSize = size + 'pt'; }
				if (el.scrollWidth > el.clientWidth + 1) { el.style.overflowWrap = 'anywhere'; }
			});
			// a two-line guest name already adds a full extra line of height: trim other vertical spacing back
			// (see .slip.two-line in the stylesheet) so the sign doesn't grow as tall as name-height + full spacing
			Array.prototype.forEach.call(document.querySelectorAll('.name'), function (el) {
				var lineHeight = parseFloat(getComputedStyle(el).lineHeight) || parseFloat(getComputedStyle(el).fontSize) * 1.08;
				var slip = el.closest('.slip');
				if (slip) { slip.classList.toggle('two-line', el.clientHeight > lineHeight * 1.4); }
			});
		};
		var go = function () { try { window.focus(); window.print(); } catch (e) {} };
		var auto = <?php echo !empty($_GET['print']) ? 'true' : 'false'; ?>;
		var wait = function (ms) { return new Promise(function (ok) { setTimeout(ok, ms); }); };
		// every sign is its own print job with the page height of exactly this sign: printers and browsers that would join
		// several pages into one endless strip then cut after each sign
		var printAll = function () {
			var slips = Array.prototype.slice.call(document.querySelectorAll('.slip')), st = document.createElement('style');
			document.head.appendChild(st);
			var chain = Promise.resolve();
			slips.forEach(function (sl, i) {
				chain = chain.then(function () {
					document.body.className = 'solo';
					slips.forEach(function (o, j) { o.classList.toggle('cur', j === i); });
					var mm = Math.ceil(sl.getBoundingClientRect().height / 3.7795) + 1;
					st.textContent = '@page { size: 72mm ' + mm + 'mm; margin: 0; }';
					return wait(150).then(go).then(function () { return wait(i < slips.length - 1 ? 2200 : 0); });
				});
			});
			return chain.then(function () { document.body.className = ''; st.textContent = ''; });
		};
		// the printer only gets the signs once the web fonts and the logo are in
		window.addEventListener('load', function () {
			var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
			var imgs = Array.prototype.map.call(document.images, function (im) { return im.complete ? 0 : new Promise(function (ok) { im.onload = im.onerror = ok; }); });
			Promise.all([fonts].concat(imgs)).then(function () { fit(); if (parent !== window || auto) { return wait(150).then(printAll); } });
		});
	})();
</script>
</body>
</html>
