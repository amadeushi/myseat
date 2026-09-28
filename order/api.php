<?php
/*
 * JSON interface of the order pages: one product with its choices, opening state, selectable times, the delivery
 * zone of an address, and a price quote for a cart. The prices always come from the menu of the server.
 */
require __DIR__.'/bootstrap.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function api_out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

if (!$shop_public) { api_out(array('ok' => false, 'error' => 'Die Bestellung ist noch nicht freigegeben.'), 404); }

$body = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$raw = file_get_contents('php://input');
	$body = json_decode((string)$raw, true);
	if (!is_array($body)) { $body = $_POST; }
	if (!shop_token_ok(isset($body['token']) ? $body['token'] : '')) { api_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'), 403); }
}
$op = isset($_GET['op']) ? (string)$_GET['op'] : (isset($body['op']) ? (string)$body['op'] : '');

if ($op === 'product') {
	$p = shop_catalog_product((int)(isset($_GET['id']) ? $_GET['id'] : 0));
	if (!$p) { api_out(array('ok' => false, 'error' => 'Dieses Gericht gibt es nicht mehr.'), 404); }
	api_out(array('ok' => true, 'product' => $p));
}

if ($op === 'state') {
	$out = array('ok' => true, 'accepting' => $shop_accepting, 'notice' => (string)shop_setting('notice'),
		'min_delivery' => shop_cents(shop_setting('min_order_delivery')), 'min_pickup' => shop_cents(shop_setting('min_order_pickup')),
		'pay' => array('online' => shop_flag('allow_online') && shop_mollie_key() !== '', 'cash' => shop_flag('allow_cash'), 'card_door' => shop_flag('allow_card_door'), 'tip' => shop_flag('tip_enabled')));
	foreach (array('delivery', 'pickup') as $kind) {
		$s = shop_state($kind);
		$out[$kind] = array('open' => $s['open'], 'until' => $s['until'] ? date('H:i', $s['until']) : '', 'next' => shop_when_text($s['next']), 'lead' => $s['lead']);
	}
	api_out($out);
}

if ($op === 'slots') {
	$kind = (isset($_GET['kind']) && $_GET['kind'] === 'pickup') ? 'pickup' : 'delivery';
	$days = max(0, min(14, (int)shop_setting('days_ahead')));
	$out = array();
	for ($d = 0; $d <= $days; $d++) {
		$date = date('Y-m-d', time() + $d * 86400);
		$slots = shop_slots($kind, $date);
		if ($slots) { $out[] = array('date' => $date, 'label' => ($d === 0 ? 'Heute' : ($d === 1 ? 'Morgen' : date('d.m.', strtotime($date)))), 'slots' => $slots); }
	}
	$s = shop_state($kind);
	api_out(array('ok' => true, 'asap' => $s['open'], 'asap_min' => $s['lead'], 'days' => $out));
}

if ($op === 'zone') {
	// the address lookup asks an outside service: a visitor may do it a limited number of times
	$_SESSION['shop_zone_hits'] = isset($_SESSION['shop_zone_hits']) ? $_SESSION['shop_zone_hits'] : array();
	$_SESSION['shop_zone_hits'] = array_values(array_filter($_SESSION['shop_zone_hits'], function ($t) { return $t > time() - 3600; }));
	if (count($_SESSION['shop_zone_hits']) >= 30) { api_out(array('ok' => false, 'error' => 'Zu viele Adressprüfungen. Bitte versuche es später noch einmal oder ruf uns an.')); }
	$_SESSION['shop_zone_hits'][] = time();
	$r = shop_find_zone(mb_substr((string)(isset($body['street']) ? $body['street'] : ''), 0, 120), mb_substr((string)(isset($body['zip']) ? $body['zip'] : ''), 0, 10), mb_substr((string)(isset($body['city']) ? $body['city'] : ''), 0, 80));
	if (!$r['ok']) { api_out(array('ok' => false, 'error' => $r['error'])); }
	$min = $r['zone']['min_order_cents'] > 0 ? $r['zone']['min_order_cents'] : shop_cents(shop_setting('min_order_delivery'));
	api_out(array('ok' => true, 'zone' => array('id' => $r['zone']['id'], 'name' => $r['zone']['name'], 'fee' => $r['zone']['fee_cents'], 'min' => $min)));
}

if ($op === 'upsell') {
	$lines = (isset($body['lines']) && is_array($body['lines'])) ? array_slice($body['lines'], 0, 60) : array();
	$ids = array();
	foreach ($lines as $l) { if (is_array($l) && !empty($l['pid'])) { $ids[] = (int)$l['pid']; } }
	$r = shop_upsell_candidates($ids, 3);
	api_out(array('ok' => true, 'learned' => $r['learned'], 'items' => $r['items']));
}

if ($op === 'quote') {
	$lines = (isset($body['lines']) && is_array($body['lines'])) ? array_slice($body['lines'], 0, 60) : array();
	$out = array(); $sub = 0;
	foreach ($lines as $l) {
		$r = shop_price_line(is_array($l) ? $l : array());
		if (!$r['ok']) { api_out(array('ok' => false, 'error' => $r['error'])); }
		$out[] = $r['line']; $sub += $r['line']['line_cents'];
	}
	api_out(array('ok' => true, 'lines' => $out, 'subtotal' => $sub));
}

if ($op === 'coupon') {
	// preview for the guest: does the code work for this cart? (the order checks it again; guessing codes is limited per visitor)
	$_SESSION['shop_coupon_hits'] = isset($_SESSION['shop_coupon_hits']) ? $_SESSION['shop_coupon_hits'] : array();
	$_SESSION['shop_coupon_hits'] = array_values(array_filter($_SESSION['shop_coupon_hits'], function ($t) { return $t > time() - 3600; }));
	if (count($_SESSION['shop_coupon_hits']) >= 20) { api_out(array('ok' => false, 'error' => 'Zu viele Versuche. Bitte versuche es später noch einmal.')); }
	$_SESSION['shop_coupon_hits'][] = time();
	$lines = (isset($body['lines']) && is_array($body['lines'])) ? array_slice($body['lines'], 0, 60) : array(); $sub = 0;
	foreach ($lines as $l) { $r = shop_price_line(is_array($l) ? $l : array()); if (!$r['ok']) { api_out(array('ok' => false, 'error' => $r['error'])); } $sub += $r['line']['line_cents']; }
	$type = (isset($body['type']) && $body['type'] === 'pickup') ? 'pickup' : 'delivery';
	$r = shop_coupon_check(isset($body['code']) ? (string)$body['code'] : '', $type, $sub);
	api_out($r['ok'] ? array('ok' => true, 'code' => $r['coupon']['code'], 'discount' => $r['discount'], 'label' => shop_coupon_describe($r['coupon'])) : array('ok' => false, 'error' => $r['error']));
}

if ($op === 'repay') {
	$order = shop_order_by_token(isset($body['order']) ? (string)$body['order'] : '');
	if (!$order || $order['status'] !== 'pending' || $order['payment_method'] !== 'mollie') { api_out(array('ok' => false, 'error' => 'Diese Bestellung kann nicht mehr bezahlt werden.')); }
	$order = shop_mollie_sync($order);
	if ($order['payment_status'] === 'paid') { api_out(array('ok' => true, 'redirect' => 'status.php?t='.$order['token'])); }
	$p = shop_mollie_create($order, shop_base_url());
	api_out($p['ok'] ? array('ok' => true, 'redirect' => $p['url']) : array('ok' => false, 'error' => $p['error']));
}

// the driver's phone (link with the key of the order): start the trip, send the position, hand over
if (in_array($op, array('driver_go', 'driver_pos', 'driver_done'), true)) {
	$o = shop_driver_order(isset($body['order']) ? (string)$body['order'] : '', isset($body['key']) ? (string)$body['key'] : '');
	if (!$o) { api_out(array('ok' => false, 'error' => 'Dieser Link gilt nicht.'), 403); }
	$id = (int)$o['id'];
	if ($op === 'driver_pos') {
		$lat = isset($body['lat']) ? (float)$body['lat'] : 999; $lng = isset($body['lng']) ? (float)$body['lng'] : 999;
		if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || !in_array($o['status'], array('ready', 'delivering'), true)) { api_out(array('ok' => false, 'error' => 'Keine gültige Position.')); }
		fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = ?, driver_lng = ?, driver_at = NOW() WHERE id = ?", 'ddi', array($lat, $lng, $id));
		api_out(array('ok' => true, 'status' => $o['status']));
	}
	if ($op === 'driver_go') {
		if (!in_array($o['status'], array('ready', 'delivering'), true)) { api_out(array('ok' => false, 'error' => 'Die Bestellung ist noch nicht fertig.')); }
		if ($o['status'] === 'ready') { shop_set_status($id, 'delivering', 'Fahrer'); }
		api_out(array('ok' => true));
	}
	if ($o['status'] !== 'delivering') { api_out(array('ok' => false, 'error' => 'Die Bestellung ist nicht unterwegs.')); }
	if ($o['payment_method'] !== 'mollie' && $o['payment_status'] !== 'paid') { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET payment_status = 'paid' WHERE id = ?", 'i', array($id)); }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET driver_lat = NULL, driver_lng = NULL, driver_at = NULL WHERE id = ?", 'i', array($id));
	shop_set_status($id, 'done', 'Fahrer');
	api_out(array('ok' => true));
}

if ($op === 'create') {
	if (!empty($body['website'])) { api_out(array('ok' => false, 'error' => 'Die Bestellung konnte nicht abgeschickt werden.')); } // hidden field: only robots fill it
	$body['ip'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	$r = shop_create_order($body);
	if (!$r['ok']) { api_out(array('ok' => false, 'error' => $r['error'])); }
	$order = $r['order'];
	if ($order['payment_method'] === 'mollie') {
		$p = shop_mollie_create($order, shop_base_url());
		if (!$p['ok']) { shop_set_status((int)$order['id'], 'cancelled', 'Zahlung nicht möglich'); api_out(array('ok' => false, 'error' => $p['error'])); }
		api_out(array('ok' => true, 'redirect' => $p['url']));
	}
	shop_after_order_placed($order['id']);
	api_out(array('ok' => true, 'redirect' => 'status.php?t='.$order['token']));
}

api_out(array('ok' => false, 'error' => 'Unbekannte Anfrage.'), 400);
