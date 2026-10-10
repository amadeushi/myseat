<?php
/*
 * Paper slip of one order for a receipt printer (72 mm). Without "full" it is the kitchen slip: number, type, time, dishes, notes,
 * only name (and the postcode of a delivery, to talk through tours) about the guest. With full=1 it is the delivery slip: name, address, what to collect (no phone number). An order from Lieferando carries a black "LIEFERANDO" bar on top, on both slips, so it is not mixed up with the own deliveries. Needs a backend login
 * (Reservation-Edit). The monitors load it in a hidden frame and call print(); opened on its own, ?print=1 prints at once.
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

require_once('classes/session_restore.php'); myseat_restore_session();
if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$o = $id ? shop_order($id) : null;
if (!$o) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
$full = !empty($_GET['full']);
$items = shop_order_items($id);
$cnt = shop_slip_counts($items); $posNo = 0; // control figures and "Pos n von N": the same as on the slip of the kitchen printer (escpos.class.php)
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$delivery = ($o['type'] === 'delivery');
$due = $o['scheduled_at'] ?: ($o['eta_at'] ?: $o['created_at']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title>Bon #<?php echo (int)$o['day_no']; ?></title>
	<style>
		@page { size: 72mm auto; margin: 2mm; }
		* { box-sizing: border-box; }
		body { width: 68mm; margin: 0 auto; padding: 2mm 0; font-family: Arial, Helvetica, sans-serif; color: #000; background: #fff; font-size: 13pt; line-height: 1.25; }
		h1 { font-size: 34pt; margin: 0; line-height: 1; }
		.row { display: flex; justify-content: space-between; align-items: baseline; gap: 6px; }
		.type { font-size: 16pt; font-weight: 800; text-transform: uppercase; }
		.time { font-size: 20pt; font-weight: 800; }
		hr { border: 0; border-top: 2px dashed #000; margin: 8px 0; }
		.item { margin: 7px 0; font-size: 15pt; font-weight: 800; }
		.item .pos { display: block; font-size: 10.5pt; font-weight: 600; line-height: 1.1; margin-bottom: 1px; }
		.ctl { font-size: 12pt; font-weight: 800; }
		.end { margin-top: 4px; text-align: center; font-size: 11pt; font-weight: 800; letter-spacing: 0.04em; }
		.item .var { font-weight: 600; font-size: 12pt; }
		.item .opt { display: block; margin-left: 9mm; font-size: 12.5pt; font-weight: 500; }
		.item .note, .onote { display: block; margin: 3px 0 0 9mm; font-weight: 800; font-size: 12.5pt; border: 2px solid #000; padding: 1px 4px; }
		.onote { margin-left: 0; }
		.who { margin-top: 4px; font-size: 17pt; line-height: 1.15; }
		.small { font-size: 10pt; }
		.src { margin: 0 0 6px; padding: 4px 0; background: #000; color: #fff; text-align: center; font-size: 21pt; font-weight: 900; letter-spacing: 0.02em; white-space: nowrap; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
		.pay { font-size: 15pt; font-weight: 800; margin-top: 6px; }
		@media screen { body { padding: 12px; border: 1px dashed #999; margin-top: 12px; } }
	</style>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body>
	<?php if (isset($o['source']) && $o['source'] === 'lieferando'): ?><div class="src">LIEFERANDO</div><?php endif; ?>
	<?php if (isset($o['source']) && $o['source'] === 'uber_eats'): ?><div class="src">UBER EATS</div><?php endif; ?>
	<?php if (function_exists('shop_tours_on') && shop_tours_on() && !empty($o['tour']) && !empty($o['tour_out_at'])): ?><div class="src">TOUR <?php echo $h($o['tour']); ?> &middot; raus bis <?php echo $h(substr($o['tour_out_at'], 11, 5)); ?></div><?php endif; ?>
	<div class="row"><h1>#<?php echo (int)$o['day_no']; ?></h1><span class="type"><?php echo $delivery ? 'Lieferung' : 'Abholung'; ?></span></div>
	<div class="row"><span class="time"><?php echo $o['scheduled_at'] ? 'geplant ' : ''; ?><?php echo $h(substr($due, 11, 5)); ?></span><span class="small"><?php echo $h($o['number']); ?><?php echo $o['is_test'] ? ' TEST' : ''; ?></span></div>
	<?php if (!$full): ?>
		<div class="who"><strong><?php echo $h($o['customer_name']); ?></strong><?php echo ($delivery && trim((string)$o['zip']) !== '') ? ' · '.$h($o['zip']) : ''; ?></div>
	<?php endif; ?>
	<?php if ($full): ?>
		<hr/>
		<div><strong><?php echo $h($o['customer_name']); ?></strong>
		<?php if ($delivery): ?><br/><?php echo $h($o['street']); ?><br/><?php echo $h($o['zip'].' '.$o['city']); ?><?php echo $o['address_note'] !== '' ? '<br/>'.$h($o['address_note']) : ''; ?><?php endif; ?></div>
	<?php endif; ?>
	<hr/>
	<?php foreach ($items as $it): ?>
		<div class="item"><span class="pos">Pos <?php echo ++$posNo; ?> von <?php echo (int)$cnt['pos']; ?></span><?php echo (int)$it['qty']; ?>× <?php echo $h($it['title']); ?><?php echo $it['variation'] !== '' ? ' <span class="var">'.$h($it['variation']).'</span>' : ''; ?>
			<?php foreach ($it['options'] as $op): ?><span class="opt">+ <?php echo ($op['qty'] > 1 ? (int)$op['qty'].'× ' : '').$h($op['title']); ?></span><?php endforeach; ?>
			<?php if ($it['note'] !== ''): ?><span class="note"><?php echo $h($it['note']); ?></span><?php endif; ?></div>
	<?php endforeach; ?>
	<?php if ($o['note'] !== ''): ?><span class="onote"><?php echo $h($o['note']); ?></span><?php endif; ?>
	<hr/>
	<div class="ctl"><?php echo $h(shop_slip_control_text($cnt)); ?></div>
	<?php if ($full): ?>
		<hr/>
		<?php if (!empty($o['adjust_note'])): foreach (explode('; ', $o['adjust_note']) as $adj): ?><div class="small"><?php echo $h($adj); ?></div><?php endforeach; endif; ?>
		<?php if (!empty($o['offer_note'])): ?><div class="small"><?php echo $h($o['offer_note']); ?></div><?php endif; ?>
		<div class="pay"><?php echo (isset($o['source']) && $o['source'] === 'courier' && (int)$o['total_cents'] === 0) ? 'nichts zu kassieren' : (in_array($o['payment_method'], array('mollie', 'lieferando', 'uber_eats'), true) ? ($o['payment_method'] === 'lieferando' ? 'bei Lieferando bezahlt' : ($o['payment_method'] === 'uber_eats' ? 'bei Uber Eats bezahlt' : 'online bezahlt')) : ($o['payment_method'] === 'cash' ? 'BAR kassieren: ' : 'KARTE kassieren: ').shop_money($o['total_cents'])); ?></div>
		<?php if (!empty($o['pay_with_cents']) && $o['payment_method'] === 'cash'): ?><div class="small">Gast zahlt mit <?php echo shop_money((int)$o['pay_with_cents']); ?> &middot; Rückgeld <strong><?php echo shop_money((int)$o['pay_with_cents'] - (int)$o['total_cents']); ?></strong></div><?php endif; ?>
	<?php endif; ?>
	<hr/>
	<div class="small">gedruckt <?php echo date('H:i'); ?> Uhr</div>
	<div class="end">--- ENDE ---</div>
	<?php if (!empty($_GET['print'])): ?><script>window.onload = function () { window.print(); };</script><?php endif; ?>
</body>
</html>
