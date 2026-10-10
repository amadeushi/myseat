<?php
/*
 * Tours ("Touren"): the dispatch puts two to four deliveries that are in the kitchen together into a tour (letter A or B, at most two tours at a time). The kitchen monitor shows them in one frame with one
 * common "raus bis" time, so that the kitchen sees that they must be finished together. Behind the setting "tours_on" (off): with it off nothing of this is visible and no order is touched.
 *
 * Columns of tp_shop_orders (shop_ensure_schema): tour (CHAR 1, 'A' or 'B'), tour_out_at (the common time the food has to leave the kitchen), tour_wait (1: the kitchen has reported this bon finished, it waits for
 * the others). A tour is "active" while at least one of its orders is in the kitchen (status accepted/preparing); when all of those are finished (tour_wait) the whole tour goes to "ready" at once, so
 * the drivers get it together. A tour with fewer than two orders in the kitchen (the others cancelled or taken out) is dissolved (shop_tour_tick).
 */

function shop_tours_on() { return shop_setting('tours_on') === '1'; }

// when the food of an order has to leave the kitchen: due time, minus the drive for a delivery (the same rule as the kitchen monitor)
function shop_order_out_ts($r) {
	$due = strtotime($r['scheduled_at'] ?: ($r['eta_at'] ?: $r['created_at']));
	$drive = ($r['type'] === 'delivery') ? max(0, min(60, (int)shop_setting('kitchen_drive_min'))) : 0;
	return $due - $drive * 60;
}

// the orders of a tour that are still in the kitchen
function shop_tour_members($letter) {
	return fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE tour = ? AND order_date = ? AND status IN ('accepted', 'preparing') ORDER BY id", 'ss', array((string)$letter, date('Y-m-d')));
}

// the letters of the tours that are running today
function shop_tour_letters_active() {
	$out = array();
	foreach (fb_rows("SELECT DISTINCT tour FROM ".fb_t('tp_shop_orders')." WHERE tour IS NOT NULL AND order_date = ? AND status IN ('accepted', 'preparing')", 's', array(date('Y-m-d'))) as $r) { $out[] = (string)$r['tour']; }
	return $out;
}

// puts the deliveries into a tour; returns array(ok, letter, out) or array(ok false, error)
function shop_tour_create($ids, $by) {
	if (!shop_tours_on()) { return array('ok' => false, 'error' => 'Die Touren sind nicht eingeschaltet.'); }
	$ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
	if (count($ids) < 2) { return array('ok' => false, 'error' => 'Wähle mindestens zwei Lieferungen.'); }
	if (count($ids) > 4) { return array('ok' => false, 'error' => 'Eine Tour hat höchstens vier Bons.'); }
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE id IN (".implode(',', $ids).")");
	if (count($rows) !== count($ids)) { return array('ok' => false, 'error' => 'Eine der Bestellungen gibt es nicht.'); }
	foreach ($rows as $r) {
		if ($r['type'] !== 'delivery') { return array('ok' => false, 'error' => 'Nur Lieferungen können in eine Tour.'); }
		if (!in_array($r['status'], array('accepted', 'preparing'), true)) { return array('ok' => false, 'error' => '#'.(int)$r['day_no'].' ist nicht mehr in der Küche.'); }
		if ($r['tour'] !== null && $r['tour'] !== '') { return array('ok' => false, 'error' => '#'.(int)$r['day_no'].' gehört schon zu Tour '.$r['tour'].'.'); }
	}
	$used = shop_tour_letters_active(); $letter = '';
	foreach (array('A', 'B') as $l) { if (!in_array($l, $used, true)) { $letter = $l; break; } }
	if ($letter === '') { return array('ok' => false, 'error' => 'Es laufen schon zwei Touren.'); }
	// the earliest "raus bis" of the bons: nobody is late; never in the past
	$ts = null; foreach ($rows as $r) { $o = shop_order_out_ts($r); if ($ts === null || $o < $ts) { $ts = $o; } }
	$ts = max($ts, time());
	$at = date('Y-m-d H:i:s', $ts);
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET tour = ?, tour_out_at = ?, tour_wait = 0 WHERE id IN (".implode(',', $ids).")", 'ss', array($letter, $at));
	foreach ($rows as $r) { shop_log((int)$r['id'], 'Tour', 'Tour '.$letter.', raus bis '.date('H:i', $ts).' ('.$by.')'); }
	return array('ok' => true, 'letter' => $letter, 'out' => date('H:i', $ts));
}

// one order leaves its tour; a tour with fewer than two bons dissolves with it
function shop_tour_remove($orderId, $by) {
	$o = shop_order($orderId);
	if (!$o || $o['tour'] === null || $o['tour'] === '') { return array('ok' => false, 'error' => 'Diese Bestellung gehört zu keiner Tour.'); }
	$letter = $o['tour'];
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET tour = NULL, tour_out_at = NULL, tour_wait = 0 WHERE id = ?", 'i', array((int)$orderId));
	shop_log((int)$orderId, 'Tour', 'aus Tour '.$letter.' gelöst ('.$by.')');
	// a bon that had been reported finished while it waited goes on to the drivers now
	if ((int)$o['tour_wait'] === 1 && in_array($o['status'], array('accepted', 'preparing'), true)) { shop_set_status((int)$orderId, 'ready', 'Tour gelöst'); }
	shop_tour_tick();
	return array('ok' => true);
}

// the kitchen reports a bon of a tour finished: it waits for the others; the last one sends the whole tour on
function shop_tour_member_done($orderId) {
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET tour_wait = 1 WHERE id = ? AND tour IS NOT NULL AND status IN ('accepted', 'preparing')", 'i', array((int)$orderId));
	shop_log((int)$orderId, 'Tour', 'Küche: fertig, wartet auf die Tour');
	shop_tour_tick();
	$o = shop_order($orderId);
	return array('ok' => true, 'waiting' => $o && in_array($o['status'], array('accepted', 'preparing'), true));
}

// the dispatch sends the whole tour on, whatever the kitchen has reported
function shop_tour_ready_all($letter, $by) {
	$n = 0;
	foreach (shop_tour_members($letter) as $r) { shop_set_status((int)$r['id'], 'ready', 'Tour '.$letter.' ('.$by.')'); $n++; }
	return $n ? array('ok' => true) : array('ok' => false, 'error' => 'Diese Tour gibt es nicht mehr.');
}

/*
 * The common time of a tour: $input is "HH:MM" or a change in minutes ("+5", "-5"). A later time than a guest was promised (the wish time of the guest, the time Lieferando confirmed) is only taken
 * with $force (the dispatch confirms the warning).
 */
function shop_tour_set_time($letter, $input, $force, $by) {
	$members = shop_tour_members($letter);
	if (!$members) { return array('ok' => false, 'error' => 'Diese Tour gibt es nicht mehr.'); }
	$cur = strtotime($members[0]['tour_out_at']);
	$input = trim((string)$input);
	if (preg_match('/^[+-]\d{1,3}$/', $input)) { $ts = $cur + ((int)$input) * 60; }
	elseif (preg_match('/^(\d{1,2}):(\d{2})$/', $input, $m) && (int)$m[1] < 24 && (int)$m[2] < 60) { $ts = strtotime(date('Y-m-d').' '.sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2])); }
	else { return array('ok' => false, 'error' => 'Die Uhrzeit sieht nicht richtig aus.'); }
	if ($ts === false || $ts < time() - 3600) { return array('ok' => false, 'error' => 'Die Uhrzeit liegt zu weit zurück.'); }
	$late = array();
	foreach ($members as $r) {
		$promised = !empty($r['scheduled_at']) || $r['source'] === 'lieferando';
		if ($promised && $ts > shop_order_out_ts($r) + 59) { $late[] = '#'.(int)$r['day_no'].' wartet ab '.date('H:i', shop_order_out_ts($r)); }
	}
	if ($late && !$force) { return array('ok' => false, 'warn' => true, 'error' => 'Später als zugesagt: '.implode(', ', $late).'.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET tour_out_at = ? WHERE tour = ? AND order_date = ? AND status IN ('accepted', 'preparing')", 'sss', array(date('Y-m-d H:i:s', $ts), (string)$letter, date('Y-m-d')));
	foreach ($members as $r) { shop_log((int)$r['id'], 'Tour', 'Tour '.$letter.' raus bis '.date('H:i', $ts).' ('.$by.')'); }
	return array('ok' => true, 'out' => date('H:i', $ts));
}

/*
 * Tidies the running tours (called whenever the kitchen or the dispatch board is read): a tour with fewer than two orders in the kitchen is dissolved (the one left goes on if it had been reported
 * finished), and a tour whose orders in the kitchen have all been reported finished goes to "ready" together.
 */
function shop_tour_tick() {
	if (!shop_tours_on()) { return; }
	foreach (shop_tour_letters_active() as $letter) {
		$m = shop_tour_members($letter);
		if (count($m) < 2) {
			foreach ($m as $r) {
				fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET tour = NULL, tour_out_at = NULL, tour_wait = 0 WHERE id = ?", 'i', array((int)$r['id']));
				if ((int)$r['tour_wait'] === 1) { shop_set_status((int)$r['id'], 'ready', 'Tour aufgelöst'); }
			}
			continue;
		}
		$all = true; foreach ($m as $r) { if ((int)$r['tour_wait'] !== 1) { $all = false; break; } }
		if ($all) { foreach ($m as $r) { shop_set_status((int)$r['id'], 'ready', 'Tour '.$letter.' fertig'); } }
	}
}

// the tour fields of an order for the boards: empty when the setting is off or the order is in no tour
function shop_tour_fields($r) {
	if (!shop_tours_on() || !isset($r['tour']) || $r['tour'] === null || $r['tour'] === '' || empty($r['tour_out_at'])) { return array('tour' => '', 'tour_out' => '', 'tour_out_ts' => 0, 'tour_wait' => 0); }
	$ts = strtotime($r['tour_out_at']);
	return array('tour' => (string)$r['tour'], 'tour_out' => date('H:i', $ts), 'tour_out_ts' => $ts, 'tour_wait' => (int)$r['tour_wait']);
}
