<?php
/*
 * Offers of the delivery shop that work by themselves (no code to type): they are worked out on the server from the priced cart, the browser only shows them.
 *   1. A free extra (a dessert or a small drink, the guest chooses) once the goods reach a threshold. The threshold grows with the number of main dishes
 *      (one main: low, two: medium, three or more: high), because two people do not buy a third dish for a cake. Below it the cart shows what is missing
 *      and suggests snacks, sides and drinks whose price closes the gap. Whoever does not want the extra gets a small personal voucher for the next order
 *      (only with a guest account, the voucher is bound to the account).
 *   2. "Pizza + Getränk": for every pizza one drink (soft drinks and beer, no wine) costs a fixed amount less.
 * The numbers are settings (Einstellungen > Lieferservice > Angebote). The free extra is an order line with price 0 and no product id (so it does not count as
 * sold, and "Nochmal bestellen" does not put it into the next cart); the combo discount is stored in offer_cents / offer_note of the order.
 */
require_once __DIR__.'/shop.class.php';

function shop_offers_cfg() {
	$eur = function ($k, $def, $max) { $v = shop_setting($k); $v = ($v === null || $v === '') ? $def : (float)str_replace(',', '.', (string)$v); return (int)round(max(0, min($max, $v)) * 100); };
	$ids = array();
	foreach (explode(',', (string)shop_setting('offer_extras')) as $i) { $i = (int)trim($i); if ($i > 0) { $ids[] = $i; } }
	return array(
		'on' => shop_flag('offers_on'),
		't' => array($eur('offer_t1', 22, 500), $eur('offer_t2', 38, 500), $eur('offer_t3', 55, 500)),
		'extras' => $ids,
		'voucher_cents' => $eur('offer_voucher', 2, 100), 'voucher_min_cents' => $eur('offer_voucher_min', 20, 500), 'voucher_days' => max(7, min(365, (int)(shop_setting('offer_voucher_days') ?: 30))),
		'combo_cents' => $eur('offer_combo', 1, 20),
	);
}

// what kind of dish a product is: 'pizza', 'main', 'sweet', 'drink', 'snack' or 'wine' (by the name of its category, like the "Noch etwas dazu?" suggestions)
function shop_offers_kinds() {
	static $map = null;
	if ($map !== null) { return $map; }
	$map = array();
	foreach (fb_rows("SELECT p.id, p.title, p.price_cents, p.configurator, c.name AS cat,
			(SELECT MIN(price_cents) FROM ".fb_t('tp_shop_variations')." v2 WHERE v2.product_id = p.id) AS vmin,
			(SELECT COUNT(*) FROM ".fb_t('tp_shop_variations')." v WHERE v.product_id = p.id) AS nvar,
			(SELECT COUNT(*) FROM ".fb_t('tp_shop_product_groups')." pg WHERE pg.product_id = p.id) AS ngroup
		FROM ".fb_t('tp_shop_products')." p LEFT JOIN ".fb_t('tp_shop_categories')." c ON c.id = p.category_id WHERE p.active = 1") as $p) {
		$cat = (string)$p['cat']; $kind = shop_upsell_kind($cat);
		if ($kind === '') {
			if (preg_match('/wein/iu', $cat)) { $kind = 'wine'; }
			elseif (!empty($p['configurator']) || preg_match('/pizza|calzone/iu', $cat) || (preg_match('/(^|\s)(pizza|calzone)/iu', $p['title']) && !preg_match('/pizzabrot/iu', $p['title']))) { $kind = 'pizza'; }
			else { $kind = 'main'; }
		}
		$price = ((int)$p['nvar'] > 0 && $p['vmin'] !== null) ? min((int)$p['price_cents'], (int)$p['vmin']) : (int)$p['price_cents'];
		$map[(int)$p['id']] = array('kind' => $kind, 'title' => $p['title'], 'price' => $price, 'choices' => ((int)$p['nvar'] > 0 || (int)$p['ngroup'] > 0));
	}
	return $map;
}

// the offers of a priced cart ($items: lines of shop_price_line); $choice: the extra the guest chose (a product id, 'voucher', or nothing = the first)
function shop_offers_eval($items, $choice = null) {
	$cfg = shop_offers_cfg();
	$out = array('on' => false, 'mains' => 0, 'pizzas' => 0, 'threshold' => 0, 'sub' => 0, 'reached' => false, 'gap' => 0, 'extras' => array(), 'choice' => 0,
		'combo_cents' => 0, 'combo_pairs' => 0, 'combo_text' => '', 'combo_missing_drinks' => 0, 'voucher_cents' => $cfg['voucher_cents'], 'voucher_min_cents' => $cfg['voucher_min_cents']);
	if (!$cfg['on']) { return $out; }
	$out['on'] = true;
	$kinds = shop_offers_kinds(); $drinks = array(); $sub = 0;
	foreach ($items as $it) {
		$pid = (int)$it['product_id']; $k = isset($kinds[$pid]) ? $kinds[$pid]['kind'] : '';
		$qty = (int)$it['qty']; $sub += (int)$it['line_cents'];
		if ($k === 'pizza') { $out['pizzas'] += $qty; $out['mains'] += $qty; }
		elseif ($k === 'main') { $out['mains'] += $qty; }
		elseif ($k === 'drink') { for ($i = 0; $i < $qty; $i++) { $drinks[] = (int)$it['unit_cents']; } }
	}
	$out['sub'] = $sub;
	// the free extra
	if ($out['mains'] > 0) {
		$out['threshold'] = $out['mains'] >= 3 ? $cfg['t'][2] : $cfg['t'][$out['mains'] - 1];
		$out['reached'] = $sub >= $out['threshold']; $out['gap'] = max(0, $out['threshold'] - $sub);
	}
	foreach ($cfg['extras'] as $pid) {
		if (isset($kinds[$pid]) && !$kinds[$pid]['choices']) { $out['extras'][] = array('id' => $pid, 'title' => $kinds[$pid]['title'], 'price' => $kinds[$pid]['price']); }
	}
	if ($choice === 'voucher') { $out['choice'] = 'voucher'; }
	else {
		$ids = array_map(function ($e) { return $e['id']; }, $out['extras']); $c = (int)$choice;
		$out['choice'] = in_array($c, $ids, true) ? $c : ($ids ? $ids[0] : 0);
	}
	// pizza + drink: per pizza one drink costs less (never more than the drink itself)
	if ($cfg['combo_cents'] > 0 && $out['pizzas'] > 0) {
		$pairs = min($out['pizzas'], count($drinks)); $off = 0;
		for ($i = 0; $i < $pairs; $i++) { $off += min($cfg['combo_cents'], $drinks[$i]); }
		$out['combo_pairs'] = $pairs; $out['combo_cents'] = $off;
		$out['combo_missing_drinks'] = max(0, $out['pizzas'] - count($drinks));
		if ($off > 0) { $out['combo_text'] = 'Kombi Pizza + Getränk'.($pairs > 1 ? ' (×'.$pairs.')' : ''); }
	}
	return $out;
}

// snacks, sides, drinks and sweets whose price (the lowest, when there are sizes) closes the gap to the threshold, the nearest above first, one of each kind
function shop_offers_fillers($gap, $cartIds, $limit = 3) {
	if ($gap <= 0) { return array(); }
	$pop = array();
	foreach (fb_rows("SELECT oi.product_id AS pid, COUNT(DISTINCT oi.order_id) AS n FROM ".fb_t('tp_shop_order_items')." oi JOIN ".fb_t('tp_shop_orders')." o ON o.id = oi.order_id
		WHERE oi.product_id IS NOT NULL AND o.is_test = 0 AND o.status <> 'cancelled' GROUP BY oi.product_id") as $r) { $pop[(int)$r['pid']] = (int)$r['n']; }
	$cands = array();
	foreach (shop_offers_kinds() as $pid => $k) {
		if (!in_array($k['kind'], array('snack', 'drink', 'sweet'), true) || $k['price'] < $gap || $k['price'] > SHOP_UPSELL_MAX_CENTS || in_array($pid, $cartIds, true)) { continue; }
		$cands[] = array('id' => $pid, 'kind' => $k['kind'], 'title' => $k['title'], 'price' => $k['price'], 'choices' => $k['choices'], 'over' => $k['price'] - $gap, 'pop' => isset($pop[$pid]) ? $pop[$pid] : 0);
	}
	// inside a kind the nearest to the gap first, then the one bought more often; a snack or side first (it is the point of the suggestion), then a drink, a second snack, a sweet
	usort($cands, function ($a, $b) { return $a['over'] - $b['over'] ?: $b['pop'] - $a['pop'] ?: $a['id'] - $b['id']; });
	$out = array(); $used = array();
	foreach (array('snack', 'drink', 'snack', 'sweet') as $slot => $kind) {
		foreach ($cands as $c) { if ($c['kind'] === $kind && empty($used[$c['id']])) { $out[] = $c; $used[$c['id']] = 1; break; } }
	}
	return array_slice($out, 0, $limit);
}

// the line of the free extra for an order, or null
function shop_offers_extra_line($eval) {
	if (!$eval['on'] || !$eval['reached'] || !$eval['choice'] || $eval['choice'] === 'voucher') { return null; }
	foreach ($eval['extras'] as $e) {
		if ((int)$e['id'] === (int)$eval['choice']) {
			return array('product_id' => null, 'vid' => 0, 'title' => $e['title'].' (gratis)', 'variation' => '', 'options' => array(), 'qty' => 1, 'unit_cents' => 0, 'line_cents' => 0, 'note' => 'Gratis-Extra');
		}
	}
	return null;
}

// the voucher instead of the extra: personal, one use, a goods value of at least the set minimum; the guest is told by mail or SMS. Returns the coupon or null.
function shop_offers_issue_voucher($order, $keys) {
	$cfg = shop_offers_cfg();
	if ($cfg['voucher_cents'] <= 0 || !shop_stamp_has_keys($keys) || !$order) { return null; }
	$note = 'Ersatz für das Gratis-Extra, Bestellung '.$order['number'];
	$dup = fb_row("SELECT id FROM ".fb_t('tp_shop_coupons')." WHERE source = 'offer' AND note = ? LIMIT 1", 's', array($note));
	if ($dup) { return null; }
	$until = date('Y-m-d H:i:s', strtotime('+'.$cfg['voucher_days'].' days'));
	$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
	for ($t = 0; $t < 6 && $code === ''; $t++) { $c = 'EXTRA-'; for ($i = 0; $i < 6; $i++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; } if (!shop_coupon_by_code($c)) { $code = $c; } }
	if ($code === '') { $code = 'EXTRA-'.strtoupper(bin2hex(random_bytes(4))); }
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_from, valid_until, max_uses, per_guest, used, active, created_at, source, guest_key, guest_key2, parent_id)
		VALUES (?, ?, 'fixed', ?, 0, ?, 'all', NULL, ?, 1, 0, 0, 1, ?, 'offer', ?, ?, NULL)", 'ssiissss',
		array($code, $note, $cfg['voucher_cents'], $cfg['voucher_min_cents'], $until, date('Y-m-d H:i:s'), $keys[0], $keys[1]));
	$c = $st ? shop_coupon_by_code($code) : null;
	if ($c) { shop_offers_notify_voucher($order, $c); }
	return $c;
}
function shop_offers_notify_voucher($order, $c) {
	try {
		$first = shop_stamp_first_name($order['customer_name']); $money = shop_money((int)$c['value']);
		$until = date('d.m.Y', strtotime($c['valid_until']));
		if (trim((string)$order['email']) !== '') {
			shop_stamp_mail(trim($order['email']), array(
				'subject' => ($first !== '' ? $first.', ' : '').'dein Gutschein über '.$money.' für die nächste Bestellung',
				'headline' => 'Dein Gutschein über '.$money, 'lead' => 'Du wolltest kein Extra, dafür schenken wir dir etwas für das nächste Mal.',
				'image' => '', 'alt' => '', 'box' => array('label' => 'Dein Gutschein', 'value' => $money, 'note' => 'gültig bis '.$until),
				'after' => 'Wir ziehen ihn bei deiner nächsten Bestellung ab einem Warenwert von '.shop_money((int)$c['min_order_cents']).' automatisch ab, du musst nichts eingeben. Er gilt nur für dich und nur einmal.',
				'btn' => 'Jetzt bestellen', 'url' => shop_stamp_shop_url(), 'signoff' => shop_stamp_signoff()));
			return;
		}
		$m = sms_normalize_phone(html_entity_decode((string)$order['phone'], ENT_QUOTES, 'UTF-8'));
		if ($m !== null && sms_enabled()) {
			global $settings; $b = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
			sms_enqueue(null, $m, 'voucher', sms_gsm_clean($b.': Dein Gutschein über '.$money.' für die nächste Bestellung ab '.shop_money((int)$c['min_order_cents']).', gültig bis '.$until.'. Wir ziehen ihn automatisch ab.'));
		}
	} catch (Throwable $e) { error_log('mySeat offer voucher notice: '.$e->getMessage()); }
}
