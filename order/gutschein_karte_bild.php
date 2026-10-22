<?php
/*
 * The picture of one voucher as it stands now (?t=<token of the voucher>): the card with the remaining balance, without the security code. Used by order/gutschein_karte.php (the page behind the QR code).
 */
require __DIR__.'/bootstrap.inc.php';
$v = gv_by_token(isset($_GET['t']) ? (string)$_GET['t'] : '');
if (!$v) { http_response_code(404); exit; }
$ord = !empty($v['order_id']) ? fb_row("SELECT motif, from_name, crop_x, crop_y, crop_z FROM ".fb_t('tp_gv_orders')." WHERE id = ?", 'i', array((int)$v['order_id'])) : null;
$o = array('motif' => $ord ? $ord['motif'] : '', 'crop' => $ord ? array((int)$ord['crop_x'], (int)$ord['crop_y'], (int)$ord['crop_z']) : array(500, 500, 1000), 'amount_cents' => (int)$v['balance_cents'], 'recipient' => $v['recipient'], 'from' => $ord ? $ord['from_name'] : '', 'message' => $v['message'],
	'number' => $v['number'], 'code' => '', 'token' => $v['token'], 'valid_until' => $v['valid_until'], 'label' => (int)$v['balance_cents'] < (int)$v['face_cents'] ? 'RESTGUTHABEN' : 'GUTSCHEIN',
	'brand' => !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus');
header('Content-Type: image/jpeg');
header('Cache-Control: private, no-store');
echo gv_card_jpeg($o, 1100, 86);
