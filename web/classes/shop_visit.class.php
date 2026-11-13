<?php
/*
 * How long guests take on the order page until they send an order: the statistic for the best moment of the card game ("Das Spiel erscheint nach", cardgame_after).
 *
 * Measured by the SERVER, nothing is stored in or read from the guest's browser for it: the PHP session (its cookie is there for the shop anyway) holds the time of the first
 * page of the visit (shop_visit_t0) and of the first article in the cart (shop_visit_c0, noted when the cart's offers are asked for with at least one article). When an order is
 * sent, the seconds since both are counted into a table of time slots (tp_shop_visit_buckets: day, kind, slot of 15 seconds, number). The table has no order, no guest and
 * no address in it, only "on this day one order took 60 to 75 seconds". Only real orders count (not test orders, not the staff's own).
 * A pause of more than 30 minutes starts a new visit; a visit of less than 3 seconds or more than 4 hours is not counted.
 */
require_once __DIR__.'/shop.class.php';

const SHOP_VISIT_SLOT = 15;     // seconds per slot
const SHOP_VISIT_SLOTS = 80;    // slots 0..79 are up to 20 minutes, slot 80 is "longer"
const SHOP_VISIT_GAP = 1800;    // a pause longer than this (seconds) starts a new visit

function shop_visit_ensure() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_visit_buckets')." (`day` DATE NOT NULL, `kind` VARCHAR(5) NOT NULL, `slot` SMALLINT NOT NULL, `n` INT NOT NULL DEFAULT 0,
		PRIMARY KEY (`day`, `kind`, `slot`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

// a page of the visit was opened ($withCart: the cart has at least one article). Starts the visit, or a new one after a long pause
function shop_visit_touch($withCart = false) {
	if (!isset($_SESSION) || !is_array($_SESSION)) { return; }
	$now = time();
	$last = isset($_SESSION['shop_visit_last']) ? (int)$_SESSION['shop_visit_last'] : 0;
	if (!isset($_SESSION['shop_visit_t0']) || ($last > 0 && $now - $last > SHOP_VISIT_GAP)) { $_SESSION['shop_visit_t0'] = $now; unset($_SESSION['shop_visit_c0']); }
	$_SESSION['shop_visit_last'] = $now;
	if ($withCart && !isset($_SESSION['shop_visit_c0'])) { $_SESSION['shop_visit_c0'] = $now; }
}

// when an order is sent: seconds of the visit and of the cart (null = not known or not plausible); the visit is over, the next order starts a new one
function shop_visit_take() {
	$now = time(); $out = array(null, null);
	if (isset($_SESSION) && is_array($_SESSION)) {
		foreach (array(0 => 'shop_visit_t0', 1 => 'shop_visit_c0') as $i => $k) {
			if (isset($_SESSION[$k])) { $s = $now - (int)$_SESSION[$k]; if ($s >= 3 && $s <= 14400) { $out[$i] = $s; } }
		}
		unset($_SESSION['shop_visit_t0'], $_SESSION['shop_visit_c0'], $_SESSION['shop_visit_last']);
	}
	return $out;
}

// counts one order into the slots of the day
function shop_visit_record($visit, $cart) {
	shop_visit_ensure();
	$day = date('Y-m-d');
	foreach (array('visit' => $visit, 'cart' => $cart) as $kind => $s) {
		if ($s === null || (int)$s < 3 || (int)$s > 14400) { continue; }
		$slot = min(SHOP_VISIT_SLOTS, intdiv((int)$s, SHOP_VISIT_SLOT));
		fb_exec("INSERT INTO ".fb_t('tp_shop_visit_buckets')." (`day`, `kind`, `slot`, `n`) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE `n` = `n` + 1", 'ssi', array($day, $kind, $slot));
	}
	// once a day: slots older than 120 days (the page shows 30)
	if (time() - (int)shop_setting('visit_gc') > 86400) {
		shop_setting_set('visit_gc', (string)time());
		fb_exec("DELETE FROM ".fb_t('tp_shop_visit_buckets')." WHERE `day` < ?", 's', array(date('Y-m-d', time() - 120 * 86400)));
	}
}

// for the settings page: of the orders of the last $days days, after how many seconds a quarter / half / three quarters / nine tenths were sent. The value is the end of the
// slot the order falls into ("within at most ... seconds"); null = more than 20 minutes. n = orders counted
function shop_visit_stats($days = 30) {
	shop_visit_ensure();
	$since = date('Y-m-d', time() - (int)$days * 86400);
	$out = array();
	foreach (array('visit', 'cart') as $kind) {
		$rows = fb_rows("SELECT `slot`, SUM(`n`) AS n FROM ".fb_t('tp_shop_visit_buckets')." WHERE `kind` = ? AND `day` >= ? GROUP BY `slot` ORDER BY `slot`", 'ss', array($kind, $since));
		$total = 0; foreach ($rows as $r) { $total += (int)$r['n']; }
		$q = function ($p) use ($rows, $total) {
			if ($total < 1) { return 0; }
			$need = (int)ceil($p * $total); $cum = 0;
			foreach ($rows as $r) { $cum += (int)$r['n']; if ($cum >= $need) { return ((int)$r['slot'] >= SHOP_VISIT_SLOTS) ? null : ((int)$r['slot'] + 1) * SHOP_VISIT_SLOT; } }
			return null;
		};
		$out[$kind] = array('n' => $total, 'p25' => $q(0.25), 'p50' => $q(0.5), 'p75' => $q(0.75), 'p90' => $q(0.9));
	}
	return $out;
}
