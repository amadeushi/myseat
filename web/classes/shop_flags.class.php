<?php
/*
 * Marks of the dishes on the order page and in the chat:
 *  - "heute aus" (sold out for today): the dispatch switches a dish off for the rest of the day; it shows as "heute aus" and cannot be ordered, tomorrow it is back by itself (the column
 *    sold_out_on holds the date it is out for, today's date means out)
 *  - "Beliebt": the dishes ordered most often in the last 30 days (at least five orders, no drinks), looked up at most once an hour
 *  - "Neu": a dish added to the menu in the last 21 days (column added_at, set when the dish is created in the menu editor)
 */

const SHOP_NEW_DAYS = 21;
const SHOP_POPULAR_MIN = 5;      // orders in 30 days that make a dish popular
const SHOP_POPULAR_MAX = 6;      // how many dishes carry the mark

// "gesperrt" (blocked without an end): the same column holds the date 9999-12-31; the dish stays off until somebody releases it
const SHOP_BLOCKED_ON = '9999-12-31';
function shop_out_blocked($soldOutOn) { return (string)$soldOutOn === SHOP_BLOCKED_ON; }
// true for a dish that cannot be ordered: out for today or blocked
function shop_out_today($soldOutOn) { return (string)$soldOutOn !== '' && ((string)$soldOutOn === date('Y-m-d') || shop_out_blocked($soldOutOn)); }

// switch a dish off (or on again): $mode 'today' = for the rest of the day, 'always' = blocked until somebody releases it; returns array(ok, out, blocked, title) or array(ok false, error)
function shop_out_set($pid, $out, $by, $mode = 'today') {
	shop_ensure_schema();
	$p = fb_row("SELECT id, title FROM ".fb_t('tp_shop_products')." WHERE id = ?", 'i', array((int)$pid));
	if (!$p) { return array('ok' => false, 'error' => 'Dieses Gericht gibt es nicht.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_products')." SET sold_out_on = ? WHERE id = ?", 'si', array($out ? ($mode === 'always' ? SHOP_BLOCKED_ON : date('Y-m-d')) : null, (int)$pid));
	return array('ok' => true, 'out' => (bool)$out, 'blocked' => (bool)$out && $mode === 'always', 'title' => $p['title']);
}

// every dish of the menu for the dispatch list: id, title, category, out (today)
function shop_out_list() {
	shop_ensure_schema();
	$rows = fb_rows("SELECT p.id, p.title, p.sold_out_on, c.name AS cat FROM ".fb_t('tp_shop_products')." p LEFT JOIN ".fb_t('tp_shop_categories')." c ON c.id = p.category_id
		WHERE p.active = 1 ORDER BY c.sort, c.id, p.sort, p.id");
	$out = array();
	foreach ($rows as $r) { $out[] = array('id' => (int)$r['id'], 'title' => $r['title'], 'cat' => trim((string)$r['cat']), 'out' => shop_out_today($r['sold_out_on']), 'blocked' => shop_out_blocked($r['sold_out_on'])); }
	return $out;
}
function shop_out_count() {
	shop_ensure_schema();
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_products')." WHERE active = 1 AND sold_out_on IN (?, ?)", 'ss', array(date('Y-m-d'), SHOP_BLOCKED_ON));
	return $r ? (int)$r['n'] : 0;
}

// ids of the popular dishes (cached for an hour in the shop settings)
function shop_popular_ids() {
	static $ids = null;
	if ($ids !== null) { return $ids; }
	$c = json_decode((string)shop_setting('popular_cache'), true);
	if (is_array($c) && isset($c['at'], $c['ids']) && (int)$c['at'] > time() - 3600) { return $ids = array_map('intval', $c['ids']); }
	$ids = array();
	require_once __DIR__.'/shop_offers.class.php';
	$kinds = shop_offers_kinds();
	foreach (fb_rows("SELECT oi.product_id AS pid, COUNT(DISTINCT oi.order_id) AS n FROM ".fb_t('tp_shop_order_items')." oi JOIN ".fb_t('tp_shop_orders')." o ON o.id = oi.order_id
		WHERE oi.product_id IS NOT NULL AND o.is_test = 0 AND o.status NOT IN ('cancelled', 'failed', 'pending') AND o.source IN ('shop', 'chat', 'phone') AND o.created_at > ?
		GROUP BY oi.product_id HAVING n >= ".SHOP_POPULAR_MIN." ORDER BY n DESC, oi.product_id", 's', array(date('Y-m-d H:i:s', time() - 30 * 86400))) as $r) {
		$pid = (int)$r['pid'];
		if (!isset($kinds[$pid]) || $kinds[$pid]['kind'] === 'drink') { continue; }   // only dishes that are on the menu today, no drinks
		$ids[] = $pid;
		if (count($ids) >= SHOP_POPULAR_MAX) { break; }
	}
	shop_setting_set('popular_cache', json_encode(array('at' => time(), 'ids' => $ids)));
	return $ids;
}
function shop_is_new($addedAt) { return (string)$addedAt !== '' && strtotime((string)$addedAt) > time() - SHOP_NEW_DAYS * 86400; }
