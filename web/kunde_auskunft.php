<?php
/*
 * Auskunft über die gespeicherten Daten eines Kunden (Datenschutz): everything the delivery service holds about one customer on one printable page - contact data,
 * account, orders with the dishes, stamps, vouchers, note and the log of what staff did. Needs a login with Settings-General, like deleting a customer.
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop_customers.class.php');
date_default_timezone_set('Europe/Berlin');

require_once('classes/session_restore.php'); myseat_restore_session();
if (empty($_SESSION['valid_user'])) { header('Location: ../PLC/index.php'); exit; }
if (!current_user_can('Settings-General')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
shop_ensure_schema();
$id = preg_replace('/[^a-f0-9]/', '', isset($_GET['c']) ? (string)$_GET['c'] : '');
$c = shop_cust_find($id);
if (!$c) { http_response_code(404); echo 'Kunde nicht gefunden.'; exit; }
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
$in = shop_cust_in($c['keys']);
$accIds = $c['accounts'] ? implode(',', array_map('intval', $c['accounts'])) : '0';
$orders = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND (guest_key IN ($in) OR guest_key2 IN ($in)".($c['accounts'] ? " OR account_id IN ($accIds)" : '').") ORDER BY id");
$accs = $c['accounts'] ? fb_rows("SELECT * FROM ".fb_t('tp_shop_accounts')." WHERE id IN ($accIds)") : array();
$stamps = fb_rows("SELECT * FROM ".fb_t('tp_shop_stamps')." WHERE guest_key IN ($in) OR guest_key2 IN ($in) ORDER BY earned_at");
$coupons = fb_rows("SELECT code, note, value, valid_until, used, max_uses, active, created_at FROM ".fb_t('tp_shop_coupons')." WHERE source = 'stamp' AND (guest_key IN ($in) OR guest_key2 IN ($in)) ORDER BY id");
$note = fb_row("SELECT note, flags, updated_at, updated_by FROM ".fb_t('tp_shop_customer_notes')." WHERE ckey IN ($in)");
$log = fb_rows("SELECT event, detail, by_user, at FROM ".fb_t('tp_shop_customer_log')." WHERE ckey IN ($in) ORDER BY id");
$favs = $c['accounts'] ? fb_rows("SELECT f.created_at, p.title FROM ".fb_t('tp_shop_favorites')." f LEFT JOIN ".fb_t('tp_shop_products')." p ON p.id = f.product_id WHERE f.account_id IN ($accIds)") : array();
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Auskunft &ndash; <?php echo $h($c['name']); ?></title>
	<style>
		@page { size: A4; margin: 16mm; }
		body { max-width: 190mm; margin: 0 auto; padding: 16px; font: 11pt/1.4 Arial, Helvetica, sans-serif; color: #000; background: #fff; }
		h1 { font-size: 18pt; margin: 0 0 4px; } h2 { font-size: 13pt; margin: 18px 0 6px; padding-top: 8px; border-top: 1px solid #000; }
		table { width: 100%; border-collapse: collapse; font-size: 10pt; } th, td { text-align: left; padding: 3px 6px; border-bottom: 1px solid #ccc; vertical-align: top; } th { font-weight: 700; }
		.small { font-size: 9.5pt; color: #333; } .bar { margin-bottom: 14px; } button { font-size: 12pt; padding: 8px 16px; }
		@media print { .bar { display: none; } }
	</style>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body>
	<div class="bar"><button type="button" onclick="window.print()">Drucken</button></div>
	<h1>Auskunft über gespeicherte Daten</h1>
	<p class="small"><?php echo $h($brand); ?> · erstellt am <?php echo date('d.m.Y H:i'); ?> Uhr von <?php echo $h(isset($_SESSION['valid_user']) && is_string($_SESSION['valid_user']) ? $_SESSION['valid_user'] : 'Personal'); ?></p>

	<h2>Kontaktdaten aus den Bestellungen</h2>
	<table><tr><th>Name</th><th>Telefon</th><th>E-Mail</th><th>Adresse</th><th>Bestellungen</th></tr>
	<?php
	$ident = array();
	foreach ($orders as $o) { $k = $o['customer_name'].'|'.$o['phone'].'|'.$o['email'].'|'.$o['street'].'|'.$o['zip'].'|'.$o['city']; if (!isset($ident[$k])) { $ident[$k] = array($o, 0); } $ident[$k][1]++; }
	foreach ($ident as $r): $o = $r[0]; ?>
		<tr><td><?php echo $h($o['customer_name']); ?></td><td><?php echo $h($o['phone']); ?></td><td><?php echo $h($o['email']); ?></td><td><?php echo $h(trim($o['street'].', '.$o['zip'].' '.$o['city'], ' ,')); ?></td><td><?php echo (int)$r[1]; ?></td></tr>
	<?php endforeach; ?></table>

	<h2>Kundenkonto</h2>
	<?php if (!$accs): ?><p>Kein Kundenkonto.</p><?php else: ?>
	<table><tr><th>Angelegt</th><th>Telefon</th><th>E-Mail</th><th>Name</th><th>Adresse</th><th>Letzte Anmeldung</th></tr>
	<?php foreach ($accs as $a): ?><tr><td><?php echo $h($a['created_at']); ?></td><td><?php echo $h($a['contact_phone'] !== '' ? $a['contact_phone'] : $a['mask_phone']); ?></td><td><?php echo $h($a['contact_mail'] !== '' ? $a['contact_mail'] : $a['mask_mail']); ?></td><td><?php echo $h($a['name']); ?></td>
		<td><?php echo $h(trim($a['addr_street'].', '.$a['addr_zip'].' '.$a['addr_city'], ' ,')); ?></td><td><?php echo $h($a['last_login_at']); ?></td></tr><?php endforeach; ?></table>
	<?php if ($favs): ?><p class="small">Favoriten: <?php echo $h(implode(', ', array_map(function ($f) { return $f['title']; }, $favs))); ?></p><?php endif; endif; ?>

	<h2>Bestellungen (<?php echo count($orders); ?>)</h2>
	<table><tr><th>Nr.</th><th>Datum</th><th>Art</th><th>Status</th><th>Zahlung</th><th>Betrag</th><th>Gerichte</th></tr>
	<?php foreach ($orders as $o):
		$items = array(); foreach (shop_order_items((int)$o['id']) as $it) { $items[] = (int)$it['qty'].'× '.$it['title']; } ?>
		<tr><td><?php echo $h($o['number']); ?></td><td><?php echo $h(substr($o['created_at'], 0, 16)); ?></td><td><?php echo $o['type'] === 'delivery' ? 'Lieferung' : 'Abholung'; ?></td><td><?php echo $h($o['status']); ?></td><td><?php echo $h($o['payment_method']); ?></td><td><?php echo $h(shop_money($o['total_cents'])); ?></td><td><?php echo $h(implode(', ', $items)); ?></td></tr>
	<?php endforeach; ?></table>

	<h2>Stempel und Gutscheine</h2>
	<p>Stempel: <?php echo count($stamps); ?> insgesamt, davon offen <?php echo (int)$c['stamps']; ?>.</p>
	<?php if ($coupons): ?><table><tr><th>Code</th><th>Wert</th><th>Gültig bis</th><th>Eingelöst</th><th>Angelegt</th></tr>
	<?php foreach ($coupons as $v): ?><tr><td><?php echo $h($v['code']); ?></td><td><?php echo $h(shop_money($v['value'])); ?></td><td><?php echo $h($v['valid_until']); ?></td><td><?php echo (int)$v['used'] >= (int)$v['max_uses'] ? 'ja' : 'nein'; ?></td><td><?php echo $h($v['created_at']); ?></td></tr><?php endforeach; ?></table><?php endif; ?>

	<h2>Notiz und Merkmale</h2>
	<?php if (!$note): ?><p>Keine.</p><?php else: ?><p><?php echo $h($note['note']); ?></p><p class="small">Merkmale: <?php echo $h($note['flags'] !== '' ? $note['flags'] : '–'); ?> · zuletzt geändert <?php echo $h($note['updated_at']); ?> von <?php echo $h($note['updated_by']); ?></p><?php endif; ?>

	<h2>Protokoll der Handaktionen</h2>
	<?php if (!$log): ?><p>Keine.</p><?php else: ?><table><tr><th>Wann</th><th>Wer</th><th>Was</th></tr><?php foreach ($log as $l): ?><tr><td><?php echo $h($l['at']); ?></td><td><?php echo $h($l['by_user']); ?></td><td><?php echo $h($l['detail']); ?></td></tr><?php endforeach; ?></table><?php endif; ?>
</body>
</html>
