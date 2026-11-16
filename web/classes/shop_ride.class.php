<?php
/*
 * "Zeus Ride": a small pixel game on its own page (order/ride.php, linked from the websites and a QR code). The guest rides the delivery scooter through Hildesheim and collects Zeus coins
 * (the golden coins with the head of the dog). From a number of coins the guest gets a personal voucher: a percentage off the goods of one order, valid one week. Three steps (all settings): consolation prize from 20 coins 5 %, voucher from 50 coins 10 %, high score from 70 coins 20 %; the best step the coins reach counts.
 * The game says nothing about chance: whoever collects enough coins gets the voucher.
 *
 * The browser plays alone, so the server only checks what it can: every run starts with a one-time run number (ride_start, kept in the session); when the run ends the browser reports the coins
 * (ride_finish). The run number is used up by that call; the time between start and finish is measured by the server clock and must be at least SHOP_RIDE_MIN_SECS (the fastest honest run to
 * 50 coins is longer); the coins cannot be more than the course has (SHOP_RIDE_COINS, order/ride.js: NTOK). The voucher is a coupon like the ones of the mini games (ZEUS-XXXX, note 'Zeus Ride',
 * one use, found by the checkout through the browser: localStorage amadeusPromoCode). The limits are shared with the mini games: a few vouchers per address and period (tp_shop_card_plays,
 * game 'ride') and one voucher per browser session. Whoever reads the code can invent a number of coins; the damage is limited by these rules and by the size of the voucher.
 * Off until the owner switches it on (Einstellungen > Lieferservice > Zeus Ride).
 */
require_once __DIR__.'/shop.class.php';

const SHOP_RIDE_NOTE = 'Zeus Ride';
const SHOP_RIDE_COINS = 79;      // coins on the course (order/ride.js counts them: NTOK); a report of more is not possible
const SHOP_RIDE_MIN_SECS = 30;   // server clock: a run to the coin minimum cannot be shorter (the countdown alone is two seconds, the course is 6100 px at speed 100 and up to 1.45 times that with the turbo)

function shop_ride_cfg() {
	$n = function ($k, $def, $lo, $hi) { $v = shop_setting($k); $v = ($v === null || $v === '') ? $def : (float)str_replace(',', '.', (string)$v); return max($lo, min($hi, $v)); };
	// three steps: consolation prize (20 coins, 5 %), voucher (50 coins, 10 %), high score (70 coins, 20 %); a step with 0 percent is off. The best step the coins reach is the voucher.
	$tiers = array();
	foreach (array(array('ride_lo_min', 20, 'ride_lo_percent', 5), array('ride_min', 50, 'ride_percent', 10), array('ride_hi_min', 70, 'ride_hi_percent', 20)) as $t) {
		$p = (int)$n($t[2], $t[3], 0, 50);
		if ($p > 0) { $tiers[] = array('min' => (int)$n($t[0], $t[1], 1, SHOP_RIDE_COINS), 'percent' => $p); }
	}
	usort($tiers, function ($a, $b) { return $a['min'] !== $b['min'] ? $a['min'] - $b['min'] : $a['percent'] - $b['percent']; });
	return array('on' => shop_flag('ride_on'), 'tiers' => $tiers, 'min' => $tiers ? $tiers[0]['min'] : SHOP_RIDE_COINS + 1,
		'min_cents' => (int)round($n('ride_min_order', 15, 0, 500) * 100), 'days' => (int)$n('ride_days', 7, 1, 30));
}

// what the page shows before the run: is the game on, and may this visitor still get a voucher (else the game can be played for fun)
function shop_ride_state($ip) {
	shop_ensure_schema();
	shop_card_gc();
	$c = shop_ride_cfg();
	$out = array('on' => $c['on'], 'eligible' => false, 'min' => $c['min'], 'tiers' => $c['tiers'], 'min_order' => $c['min_cents'], 'days' => $c['days']);
	if (!$c['on'] || !empty($_SESSION['shop_ride_won'])) { return $out; }
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_card_plays')." WHERE ip_hash = ? AND created_at > ?", 'ss', array(shop_card_ip_hash($ip), date('Y-m-d H:i:s', time() - $c['days'] * 86400)));
	$out['eligible'] = !$r || (int)$r['n'] < SHOP_CARD_PER_IP;
	return $out;
}

// a run starts: the one-time run number (a new start replaces the old one)
function shop_ride_start($ip) {
	$st = shop_ride_state($ip);
	if (!$st['on']) { return array('ok' => false, 'error' => 'Das Spiel gibt es gerade nicht.'); }
	$_SESSION['shop_ride_run'] = array('id' => bin2hex(random_bytes(8)), 't' => microtime(true));
	return array('ok' => true, 'run' => $_SESSION['shop_ride_run']['id'], 'eligible' => $st['eligible']);
}

// a run ends: the coins are reported. Returns ok + win (bool); with a voucher also code, until, percent, min_order, days; without: why = 'have' (voucher already made) or 'few' (not enough coins)
function shop_ride_finish($ip, $run, $coins) {
	$st = shop_ride_state($ip);
	if (!$st['on']) { return array('ok' => false, 'error' => 'Das Spiel gibt es gerade nicht.'); }
	$r = isset($_SESSION['shop_ride_run']) ? $_SESSION['shop_ride_run'] : null;
	unset($_SESSION['shop_ride_run']);   // used up, whatever comes next
	$coins = max(0, min(SHOP_RIDE_COINS, (int)$coins));
	$secs = $r ? microtime(true) - (float)$r['t'] : 0;
	if (!$r || !is_string($run) || !hash_equals((string)$r['id'], $run) || $secs < SHOP_RIDE_MIN_SECS || $secs > 3600) { return array('ok' => true, 'win' => false, 'why' => 'few', 'coins' => $coins); }
	$tier = null;   // the best step these coins reach
	foreach ($st['tiers'] as $t) { if ($coins >= $t['min'] && ($tier === null || $t['percent'] > $tier['percent'])) { $tier = $t; } }
	if ($tier === null) { return array('ok' => true, 'win' => false, 'why' => 'few', 'coins' => $coins); }
	if (!$st['eligible']) { return array('ok' => true, 'win' => false, 'why' => 'have', 'coins' => $coins); }
	$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $code = '';
	for ($try = 0; $try < 12; $try++) {
		$k = 'ZEUS-';
		for ($i = 0; $i < 4; $i++) { $k .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
		if (!shop_coupon_by_code($k)) { $code = $k; break; }
	}
	if ($code === '') { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'); }
	$until = date('Y-m-d 23:59:59', strtotime('+'.(int)$st['days'].' days'));
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_until, max_uses, per_guest, used, active, created_at)
		VALUES (?, ?, 'percent', ?, 0, ?, 'all', ?, 1, 1, 0, 1, NOW())", 'ssiis', array($code, SHOP_RIDE_NOTE, (int)$tier['percent'], (int)$st['min_order'], $until));
	if (!$ok) { return array('ok' => false, 'error' => 'Das hat gerade nicht geklappt. Bitte versuche es später noch einmal.'); }
	fb_exec("INSERT INTO ".fb_t('tp_shop_card_plays')." (ip_hash, code, game, created_at) VALUES (?, ?, 'ride', NOW())", 'ss', array(shop_card_ip_hash($ip), $code));
	$_SESSION['shop_ride_won'] = 1;
	return array('ok' => true, 'win' => true, 'coins' => $coins, 'code' => $code, 'until' => date('d.m.Y', strtotime($until)), 'percent' => (int)$tier['percent'], 'min_order' => (int)$st['min_order'], 'days' => (int)$st['days']);
}

// for the settings page: how many vouchers the game made, how many were redeemed
function shop_ride_stats() {
	shop_ensure_schema();
	$r = fb_row("SELECT COUNT(*) AS n, COALESCE(SUM(used), 0) AS u FROM ".fb_t('tp_shop_coupons')." WHERE note = ?", 's', array(SHOP_RIDE_NOTE));
	$by = array();   // per step (percent): made / redeemed
	foreach (fb_rows("SELECT value AS p, COUNT(*) AS n, COALESCE(SUM(used), 0) AS u FROM ".fb_t('tp_shop_coupons')." WHERE note = ? GROUP BY value ORDER BY value", 's', array(SHOP_RIDE_NOTE)) as $row) { $by[(int)$row['p']] = array('made' => (int)$row['n'], 'used' => (int)$row['u']); }
	return array('made' => $r ? (int)$r['n'] : 0, 'used' => $r ? (int)$r['u'] : 0, 'by' => $by);
}
