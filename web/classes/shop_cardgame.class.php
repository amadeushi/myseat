<?php
/*
 * "Kartenspiel": a guest without an account who has been on the order page for a while without ordering is offered a small game: four face-down cards, three are
 * turned over, the third one shows a personal voucher (a percentage off the goods of one order). Everybody who plays gets the voucher, and nothing in the game says
 * otherwise (no chance, no competition). Every game makes a coupon of its own (KARTE-XXXX, note 'Kartenspiel'): one use, valid for a few days, found by the
 * checkout through the browser (localStorage amadeusPromoCode, see order/cardgame.js and order/checkout.js).
 * Limits: once per browser session and a few games per address and period (tp_shop_card_plays). Whoever wins a voucher can redeem it: there is no rule against a second
 * voucher of the game at the checkout (shop_coupon_check), the code is single-use. The coupons are not listed with the coupons of the owner (shop_me_coupons); the settings page
 * shows how many were made and redeemed. Off until the owner switches it on (Einstellungen > Lieferservice > Kartenspiel).
 */
require_once __DIR__.'/shop.class.php';

const SHOP_CARD_NOTE = 'Kartenspiel';
const SHOP_CARD_PER_IP = 3;   // games per address within the validity period of a voucher (a household, a cafe with one connection)

function shop_card_cfg() {
	$n = function ($k, $def, $lo, $hi) { $v = shop_setting($k); $v = ($v === null || $v === '') ? $def : (float)str_replace(',', '.', (string)$v); return max($lo, min($hi, $v)); };
	return array('on' => shop_flag('cardgame_on'), 'percent' => (int)$n('cardgame_percent', 10, 1, 50), 'min_cents' => (int)round($n('cardgame_min', 15, 0, 500) * 100),
		'days' => (int)$n('cardgame_days', 7, 1, 30), 'after' => (int)$n('cardgame_after', 60, 15, 600));
}
function shop_card_ip_hash($ip) { return substr(hash('sha256', (string)$ip.'|myseat-shop'), 0, 16); }

// once a day: vouchers of the game that nobody used and that ran out 30 days ago, and the lines (hashed address) of the games after 90 days (the privacy statement says so)
function shop_card_gc() {
	$last = (int)shop_setting('cardgame_gc');
	if (time() - $last < 86400) { return; }
	shop_setting_set('cardgame_gc', (string)time());
	fb_exec("DELETE FROM ".fb_t('tp_shop_coupons')." WHERE note = ? AND used = 0 AND valid_until < ?", 'ss', array(SHOP_CARD_NOTE, date('Y-m-d H:i:s', time() - 30 * 86400)));
	fb_exec("DELETE FROM ".fb_t('tp_shop_card_plays')." WHERE created_at < ?", 's', array(date('Y-m-d H:i:s', time() - 90 * 86400)));
}

// may this visitor be offered the game right now? (signed-in guests have the stamp card instead)
function shop_card_state($ip) {
	shop_ensure_schema();
	shop_card_gc();
	$c = shop_card_cfg();
	$out = array('eligible' => false, 'percent' => $c['percent'], 'min' => $c['min_cents'], 'days' => $c['days'], 'after' => $c['after']);
	if (!$c['on'] || shop_acc_current() || !empty($_SESSION['shop_card_played'])) { return $out; }
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_card_plays')." WHERE ip_hash = ? AND created_at > ?", 'ss', array(shop_card_ip_hash($ip), date('Y-m-d H:i:s', time() - $c['days'] * 86400)));
	$out['eligible'] = !$r || (int)$r['n'] < SHOP_CARD_PER_IP;
	return $out;
}

// the third card: makes the voucher of this game. Returns ok + code, until (date), percent, min, days, or ok false + error
function shop_card_play($ip) {
	$st = shop_card_state($ip);
	if (!$st['eligible']) { return array('ok' => false, 'error' => 'Das Spiel gibt es gerade nicht.'); }
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
	for ($try = 0; $try < 12; $try++) {
		$k = 'KARTE-';
		for ($i = 0; $i < 4; $i++) { $k .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
		if (!shop_coupon_by_code($k)) { $code = $k; break; }
	}
	if ($code === '') { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'); }
	$until = date('Y-m-d 23:59:59', strtotime('+'.(int)$st['days'].' days'));
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_until, max_uses, per_guest, used, active, created_at)
		VALUES (?, ?, 'percent', ?, 0, ?, 'all', ?, 1, 1, 0, 1, NOW())", 'ssiis', array($code, SHOP_CARD_NOTE, (int)$st['percent'], (int)$st['min'], $until));
	if (!$ok) { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'); }
	fb_exec("INSERT INTO ".fb_t('tp_shop_card_plays')." (ip_hash, code, created_at) VALUES (?, ?, NOW())", 'ss', array(shop_card_ip_hash($ip), $code));
	$_SESSION['shop_card_played'] = 1;
	return array('ok' => true, 'code' => $code, 'until' => date('d.m.Y', strtotime($until)), 'percent' => (int)$st['percent'], 'min' => (int)$st['min'], 'days' => (int)$st['days']);
}

// how long guests were on the order page before they sent an order: the statistic for the best moment of the game (cardgame_after). visit = seconds from the first page of the
// visit (a tab) to the order, cart = seconds from the first article in the cart to the order. The orders of the last $days days that carry the measured seconds; p25 / p50 / p75 / p90 =
// a quarter / half / three quarters / nine tenths of the orders were sent within this many seconds
function shop_visit_stats($days = 30) {
	shop_ensure_schema();
	$since = date('Y-m-d H:i:s', time() - (int)$days * 86400);
	$out = array();
	foreach (array('visit' => 'visit_s', 'cart' => 'cart_s') as $k => $col) {
		$v = array();
		foreach (fb_rows("SELECT $col AS s FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND source = 'shop' AND $col IS NOT NULL AND created_at > ? ORDER BY $col", 's', array($since)) as $r) { $v[] = (int)$r['s']; }
		$n = count($v);
		$q = function ($p) use ($v, $n) { return $n ? $v[(int)min($n - 1, floor($p * $n))] : 0; };
		$out[$k] = array('n' => $n, 'p25' => $q(0.25), 'p50' => $q(0.5), 'p75' => $q(0.75), 'p90' => $q(0.9));
	}
	return $out;
}

// for the settings page: how many vouchers the game made, how many were redeemed
function shop_card_stats() {
	shop_ensure_schema();
	$r = fb_row("SELECT COUNT(*) AS n, COALESCE(SUM(used), 0) AS u FROM ".fb_t('tp_shop_coupons')." WHERE note = ?", 's', array(SHOP_CARD_NOTE));
	return array('made' => $r ? (int)$r['n'] : 0, 'used' => $r ? (int)$r['u'] : 0);
}
