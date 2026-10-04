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
		$out[$kind] = array('open' => $s['open'], 'until' => $s['until'] ? date('H:i', $s['until']) : '', 'next' => shop_when_text($s['next']), 'lead' => $s['lead'],
			'paused' => !empty($s['paused']), 'paused_until' => !empty($s['paused_until']) ? date('H:i', $s['paused_until']) : '');
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
	api_out(array('ok' => true, 'asap' => $s['open'], 'asap_min' => $s['lead'], 'days' => $out, 'paused' => !empty($s['paused']), 'paused_until' => !empty($s['paused_until']) ? date('H:i', $s['paused_until']) : ''));
}

if ($op === 'zone') {
	// the address lookup asks an outside service: a visitor may do it a limited number of times
	$_SESSION['shop_zone_hits'] = isset($_SESSION['shop_zone_hits']) ? $_SESSION['shop_zone_hits'] : array();
	$_SESSION['shop_zone_hits'] = array_values(array_filter($_SESSION['shop_zone_hits'], function ($t) { return $t > time() - 3600; }));
	if (count($_SESSION['shop_zone_hits']) >= 30) { api_out(array('ok' => false, 'error' => 'Zu viele Adressprüfungen. Bitte versuche es später noch einmal oder ruf uns an.')); }
	$_SESSION['shop_zone_hits'][] = time();
	// a what3words code (an escape hatch offered only once a typed address was not found) replaces street/zip/city
	$words = mb_substr((string)(isset($body['words']) ? $body['words'] : ''), 0, 40);
	$r = ($words !== '')
		? shop_find_zone_w3w($words)
		: shop_find_zone(mb_substr((string)(isset($body['street']) ? $body['street'] : ''), 0, 120), mb_substr((string)(isset($body['zip']) ? $body['zip'] : ''), 0, 10), mb_substr((string)(isset($body['city']) ? $body['city'] : ''), 0, 80));
	if (!$r['ok']) {
		api_out(array('ok' => false, 'error' => $r['error'], 'reason' => isset($r['reason']) ? $r['reason'] : '', 'w3w_available' => shop_w3w_key() !== '',
			'candidates' => isset($r['candidates']) ? $r['candidates'] : array()));
	}
	$min = $r['zone']['min_order_cents'] > 0 ? $r['zone']['min_order_cents'] : shop_cents(shop_setting('min_order_delivery'));
	api_out(array('ok' => true, 'zone' => array('id' => $r['zone']['id'], 'name' => $r['zone']['name'], 'fee' => $r['zone']['fee_cents'], 'min' => $min), 'lat' => $r['lat'], 'lng' => $r['lng'],
		'words' => isset($r['words']) ? $r['words'] : '', 'postcode' => isset($r['postcode']) ? $r['postcode'] : '', 'road' => isset($r['road']) ? $r['road'] : ''));
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
	// personal coupons (stamp card) are checked with the phone / e-mail the guest typed in the checkout
	$r = shop_coupon_check(isset($body['code']) ? (string)$body['code'] : '', $type, $sub, shop_coupon_guest_keys(mb_substr((string)(isset($body['phone']) ? $body['phone'] : ''), 0, 40), mb_substr((string)(isset($body['email']) ? $body['email'] : ''), 0, 160)));
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

// the driver's own page (order/driver.php?device=<traccar device id>, no login - the device id itself is
// the credential, mapped to a name in Einstellungen > Lieferservice). GPS itself arrives separately and
// continuously at order/driver_gps.php from the Traccar app in the background; these ops are the open
// pool, his own queue (claimed, not yet started), claiming/starting/pausing/releasing/failing/completing one.
if (in_array($op, array('driver_list', 'driver_claim', 'driver_start', 'driver_pause', 'driver_fail', 'driver_release', 'driver_complete'), true)) {
	$driver = shop_driver_by_device(isset($body['device']) ? $body['device'] : '');
	if (!$driver) { api_out(array('ok' => false, 'error' => 'Dieses Gerät ist keinem Fahrer zugeordnet.'), 403); }
	$driverId = (int)$driver['id'];
	if ($op === 'driver_list') { api_out(array_merge(array('ok' => true), shop_driver_state($driverId))); }
	$orderId = (int)(isset($body['order_id']) ? $body['order_id'] : 0);
	if ($orderId <= 0) { api_out(array('ok' => false, 'error' => 'Unbekannte Lieferung.')); }
	if ($op === 'driver_claim') { $r = shop_driver_claim_order($driverId, $orderId); }
	if ($op === 'driver_start') { $r = shop_driver_start_order($driverId, $orderId); }
	if ($op === 'driver_pause') { $r = shop_driver_pause_order($driverId, $orderId); }
	if ($op === 'driver_fail') { $r = shop_driver_fail_order($driverId, $orderId, isset($body['reason']) ? (string)$body['reason'] : ''); }
	if ($op === 'driver_release') { $r = shop_driver_release_order($driverId, $orderId); }
	if ($op === 'driver_complete') { $r = shop_driver_complete_order($driverId, $orderId); }
	if (!$r['ok']) { api_out($r); }
	api_out(array_merge(array('ok' => true), shop_driver_state($driverId)));
}

// stamp card of a guest who types phone / e-mail in the checkout: stamps, voucher and what it takes off this cart (limited per visitor)
if ($op === 'stamp_state') {
	$_SESSION['shop_stamp_hits'] = isset($_SESSION['shop_stamp_hits']) ? $_SESSION['shop_stamp_hits'] : array();
	$_SESSION['shop_stamp_hits'] = array_values(array_filter($_SESSION['shop_stamp_hits'], function ($t) { return $t > time() - 3600; }));
	if (count($_SESSION['shop_stamp_hits']) >= 60) { api_out(array('ok' => false, 'error' => 'Zu viele Abfragen.')); }
	$_SESSION['shop_stamp_hits'][] = time();
	$keys = shop_coupon_guest_keys(mb_substr((string)(isset($body['phone']) ? $body['phone'] : ''), 0, 40), mb_substr((string)(isset($body['email']) ? $body['email'] : ''), 0, 160));
	$lines = (isset($body['lines']) && is_array($body['lines'])) ? array_slice($body['lines'], 0, 60) : array(); $sub = 0;
	foreach ($lines as $l) { $r = shop_price_line(is_array($l) ? $l : array()); if ($r['ok']) { $sub += $r['line']['line_cents']; } }
	// anybody can type a number here: the number of stamps is only shown to the signed-in owner of those keys, and then it is the
	// account's own keys that are counted (not a typed number next to the own e-mail). The voucher still has to be shown, it is
	// taken off the order whoever places it
	$acc = shop_acc_current(); $ak = $acc ? shop_acc_keys($acc) : array('', '');
	$mine = $acc && (($keys[0] !== '' && $keys[0] === $ak[0]) || ($keys[1] !== '' && $keys[1] === $ak[1]));
	$st = shop_stamp_state($mine ? $ak : $keys, (isset($body['type']) && $body['type'] === 'pickup') ? 'pickup' : 'delivery', $sub);
	$st['authed'] = (bool)$mine;
	if (!$mine) { $st['count'] = 0; $st['saved'] = 0; $st['until'] = ''; }
	api_out(array_merge(array('ok' => true), $st));
}

// ---- guest account: sign-in by code or link, overview, reorder, favorites (see shop_account.class.php)
if (in_array($op, array('me', 'login_request', 'login_verify', 'link_request', 'addr_save', 'logout', 'logout_all', 'acc_delete', 'reorder', 'fav_add', 'fav_remove'), true)) {
	if (!shop_acc_enabled()) { api_out(array('ok' => false, 'error' => 'Das Kundenkonto ist gerade nicht verfügbar.', 'off' => true)); }
	$acc = shop_acc_current();
	$str = function ($k, $max) use ($body) { return mb_substr((string)(isset($body[$k]) ? $body[$k] : ''), 0, $max); };
	if ($op === 'me') {
		if (!$acc) { api_out(array('ok' => true, 'signed_in' => false, 'sms' => sms_enabled() && shop_flag('account_sms'))); }
		api_out(array_merge(shop_acc_overview($acc, (isset($_GET['type']) && $_GET['type'] === 'pickup') ? 'pickup' : 'delivery'), array('signed_in' => true)));
	}
	if ($op === 'login_request') { api_out(shop_acc_request($str('target', 160))); }
	if ($op === 'login_verify') {
		$r = shop_acc_verify_code($str('target', 160), $str('code', 12));
		if (!$r['ok']) { api_out($r); }
		api_out(array_merge(shop_acc_overview(shop_acc_current()), array('signed_in' => true)));
	}
	if (!$acc) { api_out(array('ok' => false, 'error' => 'Bitte melde dich an.', 'signed_out' => true), 401); }
	if ($op === 'link_request') { api_out(shop_acc_request($str('target', 160), 'link', (int)$acc['id'])); }
	if ($op === 'addr_save') {
		// the address lookup asks an outside service: it shares the limit of the zone check
		$_SESSION['shop_zone_hits'] = isset($_SESSION['shop_zone_hits']) ? $_SESSION['shop_zone_hits'] : array();
		$_SESSION['shop_zone_hits'] = array_values(array_filter($_SESSION['shop_zone_hits'], function ($t) { return $t > time() - 3600; }));
		if (count($_SESSION['shop_zone_hits']) >= 30) { api_out(array('ok' => false, 'error' => 'Zu viele Adressprüfungen. Bitte versuche es später noch einmal.')); }
		$_SESSION['shop_zone_hits'][] = time();
		$r = shop_acc_address_save($acc, $str('street', 120), $str('zip', 10), $str('city', 80), $str('note', 200), $str('name', 80), $str('phone', 30));
		if (!$r['ok']) { api_out($r); }
		shop_acc_current(true);
		api_out(array_merge(shop_acc_overview(shop_acc_current()), array('signed_in' => true, 'removed' => !empty($r['removed']), 'zone' => isset($r['zone']) ? $r['zone'] : null)));
	}
	if ($op === 'logout') { shop_acc_logout(false); api_out(array('ok' => true)); }
	if ($op === 'logout_all') { shop_acc_logout(true); api_out(array('ok' => true)); }
	if ($op === 'acc_delete') { shop_acc_delete($acc); api_out(array('ok' => true)); }
	if ($op === 'reorder') { api_out(shop_acc_reorder($acc, (int)(isset($body['order_id']) ? $body['order_id'] : 0))); }
	if ($op === 'fav_add') { $r = shop_acc_fav_add($acc, isset($body['line']) && is_array($body['line']) ? $body['line'] : array()); api_out($r['ok'] ? array('ok' => true, 'favs' => shop_acc_favs($acc)) : $r); }
	if ($op === 'fav_remove') { shop_acc_fav_remove($acc, (int)(isset($body['id']) ? $body['id'] : 0)); api_out(array('ok' => true, 'favs' => shop_acc_favs($acc))); }
}

// shared basket ("Gemeinsam bestellen"): several guests fill one basket, the organizer orders and pays (see shop_basket_* in shop.class.php)
if (strpos($op, 'basket_') === 0) {
	$g = (string)(isset($body['g']) ? $body['g'] : ''); $me = (string)(isset($body['me']) ? $body['me'] : '');
	if ($op === 'basket_create' || $op === 'basket_join') {
		// creating and joining are limited per visitor, like the address lookup
		$_SESSION['shop_basket_hits'] = isset($_SESSION['shop_basket_hits']) ? $_SESSION['shop_basket_hits'] : array();
		$_SESSION['shop_basket_hits'] = array_values(array_filter($_SESSION['shop_basket_hits'], function ($t) { return $t > time() - 3600; }));
		if (count($_SESSION['shop_basket_hits']) >= 20) { api_out(array('ok' => false, 'error' => 'Zu viele Versuche. Bitte versuche es später noch einmal.')); }
		$_SESSION['shop_basket_hits'][] = time();
		$nm = isset($body['name']) ? $body['name'] : '';
		api_out($op === 'basket_create' ? shop_basket_create($nm) : shop_basket_join($g, $nm));
	}
	if ($op === 'basket_view') { api_out(shop_basket_view($g, $me, (string)(isset($body['rev']) ? $body['rev'] : ''))); }
	if ($op === 'basket_add') { api_out(shop_basket_add($g, $me, isset($body['line']) ? $body['line'] : array())); }
	if ($op === 'basket_qty') { api_out(shop_basket_set_qty($g, $me, (int)(isset($body['id']) ? $body['id'] : 0), (int)(isset($body['qty']) ? $body['qty'] : 0))); }
	if ($op === 'basket_status') { api_out(shop_basket_set_status($g, $me, (string)(isset($body['to']) ? $body['to'] : ''))); }
	if ($op === 'basket_link') { api_out(shop_basket_link($g, $me, shop_base_url().'/order/?g='.preg_replace('/[^a-f0-9]/', '', $g))); }
	if ($op === 'basket_close') { api_out(shop_basket_close($g, $me)); }
	api_out(array('ok' => false, 'error' => 'Unbekannte Anfrage.'), 400);
}

if ($op === 'create') {
	if (!empty($body['website'])) { api_out(array('ok' => false, 'error' => 'Die Bestellung konnte nicht abgeschickt werden.')); } // hidden field: only robots fill it
	$body['ip'] = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
	// an order from a shared basket: the lines come from the basket (never from the browser), each marked with who it is for
	$basket = (string)(isset($body['group']) ? $body['group'] : '');
	if ($basket !== '') {
		$gl = shop_basket_order_lines($basket, isset($body['me']) ? (string)$body['me'] : '', isset($body['rev']) ? (string)$body['rev'] : '');
		if (!$gl['ok']) { api_out(array('ok' => false, 'error' => $gl['error'])); }
		$body['lines'] = $gl['lines'];
		$own = trim((string)(isset($body['note']) ? $body['note'] : ''));
		$body['note'] = mb_substr('Gemeinsame Bestellung: '.implode(', ', $gl['names']).($own !== '' ? ' – '.mb_substr($own, 0, 300) : ''), 0, 500);
	}
	// signed in: the order belongs to the account (stamps, voucher); taken from the session, never from the browser
	unset($body['acc_id'], $body['acc_keys']);
	$accNow = shop_acc_current();
	if ($accNow) { $body['acc_id'] = (int)$accNow['id']; $body['acc_keys'] = shop_acc_keys($accNow); }
	$r = shop_create_order($body);
	if (!$r['ok']) { api_out(array('ok' => false, 'error' => $r['error'])); }
	$order = $r['order'];
	// the account learns name and number from its first order, so the customer record is complete without typing it twice
	if ($accNow) { fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET name = IF(name = '', ?, name), contact_phone = IF(contact_phone = '', ?, contact_phone) WHERE id = ?", 'ssi', array(mb_substr(trim((string)$order['customer_name']), 0, 80), mb_substr(trim((string)$order['phone']), 0, 30), (int)$accNow['id'])); }
	if ($basket !== '') { shop_basket_mark($basket, 'ordered', (int)$order['id']); }
	if ($order['payment_method'] === 'mollie') {
		$p = shop_mollie_create($order, shop_base_url());
		if (!$p['ok']) { shop_set_status((int)$order['id'], 'cancelled', 'Zahlung nicht möglich'); if ($basket !== '') { shop_basket_mark($basket, 'locked', 0); } api_out(array('ok' => false, 'error' => $p['error'])); }
		api_out(array('ok' => true, 'redirect' => $p['url']));
	}
	shop_after_order_placed($order['id']);
	api_out(array('ok' => true, 'redirect' => 'status.php?t='.$order['token']));
}

api_out(array('ok' => false, 'error' => 'Unbekannte Anfrage.'), 400);
