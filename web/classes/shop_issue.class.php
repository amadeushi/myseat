<?php
/*
 * "Alles dabei?": after a delivery the guest can report on the status page that something is missing (a salad, a sauce ...): he ticks what is missing (the positions of his order and the usual
 * forgotten things), says whether he would like it brought afterwards or is happy with a credit, and may write a short note. One report per order, only for a delivery that is done, only for a
 * while after the delivery (setting issue_window_min, 90 minutes). The guest is found by the secret token of his order, like everything on the status page.
 *
 * The credit is a personal voucher of the stamp card system (source 'issue'): fixed amount, bound to the phone number / e-mail of the order, taken off the next order automatically.
 * Up to the cap (issue_cap, 10 euro) it is issued at once if every ticked position has a price in the order and the guest has not had more than issue_auto_30d automatic credits in 30 days;
 * everything above, positions without a price ("Etwas anderes") and the third report within 30 days wait for the release of the staff (dispatch). A language model never decides this: the server does.
 * A redelivery is a decision of the staff (dispatch): it makes a free follow-up order (source 'redelivery', total 0) with the missing things as positions, that goes through the kitchen and the drivers.
 *
 * Switched off until the owner turns it on (setting issue_on). Nothing here sends a mail; SMS only to the driver's company phone (a new report) and to the guest when the staff has decided.
 */

const SHOP_ISSUE_SMS_OFFSET = 1100000000;   // reservation_id of sms_enqueue(): keeps these texts apart from the ones of the orders (1000000000 + id) and makes each go out once

function shop_issue_standard() {
	return array('sauce' => 'Soße / Dip', 'salad' => 'Beilagensalat', 'cutlery' => 'Besteck / Servietten', 'drink' => 'Getränk', 'other' => 'Etwas anderes');
}

function shop_issue_cfg() {
	$num = function ($k, $def, $min, $max) { $v = shop_setting($k); $v = ($v === null || $v === '') ? $def : (float)str_replace(',', '.', (string)$v); return max($min, min($max, $v)); };
	return array(
		'on' => shop_flag('issue_on'),
		'window' => (int)$num('issue_window_min', 90, 30, 1440),                 // minutes after "Geliefert"
		'cap' => (int)round($num('issue_cap', 10, 0, 100) * 100),                // cents: credits up to this amount are issued automatically
		'valid_days' => (int)$num('issue_valid_days', 60, 7, 365),
		'auto_30d' => (int)$num('issue_auto_30d', 2, 0, 20),                     // automatic credits per guest within 30 days
		'sms_driver' => shop_flag('issue_sms_driver'),
	);
}

function shop_issue_schema($db, $opts) {
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_issues')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `created_at` DATETIME NOT NULL, `items` MEDIUMTEXT NOT NULL, `note` VARCHAR(300) NOT NULL DEFAULT '',
		`wish` VARCHAR(10) NOT NULL DEFAULT 'credit', `status` VARCHAR(12) NOT NULL DEFAULT 'new', `credit_cents` INT NOT NULL DEFAULT 0, `proposed_cents` INT NOT NULL DEFAULT 0, `coupon_code` VARCHAR(40) NOT NULL DEFAULT '',
		`auto` TINYINT NOT NULL DEFAULT 0, `redelivery_order_id` INT UNSIGNED NULL, `eta_at` DATETIME NULL, `handled_by` VARCHAR(40) NOT NULL DEFAULT '', `handled_at` DATETIME NULL,
		UNIQUE KEY `ord` (`order_id`), KEY `st` (`status`, `created_at`)) $opts");
}

function shop_issue_get($id) { return fb_row("SELECT * FROM ".fb_t('tp_shop_issues')." WHERE id = ?", 'i', array((int)$id)); }
function shop_issue_by_order($orderId) { return fb_row("SELECT * FROM ".fb_t('tp_shop_issues')." WHERE order_id = ?", 'i', array((int)$orderId)); }

// the driver who delivered this order: first name and the company phone, or null
function shop_issue_driver($order) {
	if (empty($order['driver_id'])) { return null; }
	$d = fb_row("SELECT name, phone FROM ".fb_t('tp_shop_drivers')." WHERE id = ?", 'i', array((int)$order['driver_id']));
	if (!$d) { return null; }
	$first = trim((string)strtok(trim((string)$d['name']), ' '));
	return array('name' => $first, 'phone' => trim((string)$d['phone']));
}

// is the report open for this order right now? returns the time it closes (timestamp) or 0
function shop_issue_open_until($order, $cfg = null) {
	$cfg = $cfg ?: shop_issue_cfg();
	if (!$cfg['on'] || !$order || $order['type'] !== 'delivery' || $order['status'] !== 'done' || empty($order['done_at'])) { return 0; }
	if (in_array($order['source'], array('lieferando', 'uber_eats', 'redelivery', 'courier'), true)) { return 0; }
	$until = strtotime($order['done_at']) + $cfg['window'] * 60;
	return $until > time() ? $until : 0;
}

// what the guest can tick: the positions of the order (with the price of the line) and the usual forgotten things (no price known)
function shop_issue_choices($order) {
	$out = array();
	foreach (shop_order_items((int)$order['id']) as $it) {
		$label = ((int)$it['qty'] > 1 ? (int)$it['qty'].'× ' : '').$it['title'].($it['variation'] !== '' ? ' ('.$it['variation'].')' : '');
		$out[] = array('key' => 'p'.(int)$it['id'], 'label' => mb_substr($label, 0, 90), 'cents' => (int)$it['line_cents'], 'from' => 'order');
	}
	// a usual thing the order already holds as a position (with its price) is not offered a second time: the guest ticks the position
	$titles = ''; foreach ($out as $c) { $titles .= ' '.$c['label']; }
	$covered = array('sauce' => '/so(ß|ss)e|sauce|dip/iu', 'salad' => '/salat/iu', 'cutlery' => '/besteck|serviette/iu', 'drink' => '/cola|fanta|sprite|wasser|bier|limo|saft|getränk|wein|tee\b|kaffee/iu');
	foreach (shop_issue_standard() as $k => $label) {
		if (isset($covered[$k]) && preg_match($covered[$k], $titles)) { continue; }
		$out[] = array('key' => $k, 'label' => $label, 'cents' => null, 'from' => 'standard');
	}
	return $out;
}

// the decision, pure: picked = list of cents (null = price unknown); returns array('status' => 'credit'|'approval'|'new', 'credit' => cents, 'proposed' => cents)
function shop_issue_decide($picked, $wish, $cap, $autoUsed, $autoLimit, $hasKeys) {
	if ($wish !== 'credit') { return array('status' => 'new', 'credit' => 0, 'proposed' => 0); }
	$total = 0; $unknown = false;
	foreach ($picked as $c) { if ($c === null) { $unknown = true; } else { $total += (int)$c; } }
	if ($unknown || $total <= 0 || $total > $cap || !$hasKeys || $autoUsed >= $autoLimit) { return array('status' => 'approval', 'credit' => 0, 'proposed' => $unknown ? 0 : $total); }
	return array('status' => 'credit', 'credit' => $total, 'proposed' => $total);
}

function shop_issue_auto_used($order) {
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_issues')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE i.auto = 1 AND i.created_at > ? AND ((o.phone <> '' AND o.phone = ?) OR (o.email <> '' AND o.email = ?))", 'sss', array(date('Y-m-d H:i:s', time() - 30 * 86400), (string)$order['phone'], (string)$order['email']));
	return $r ? (int)$r['n'] : 0;
}

// the guest sends the report. $keys = array of the keys of the picked choices
function shop_issue_create($order, $keys, $note, $wish) {
	$cfg = shop_issue_cfg();
	if (!shop_issue_open_until($order, $cfg)) { return array('ok' => false, 'error' => 'Das geht bei dieser Bestellung nicht mehr. Bitte ruf uns kurz an.'); }
	if (shop_issue_by_order((int)$order['id'])) { return array('ok' => false, 'error' => 'Du hast uns schon etwas gemeldet. Wir kümmern uns darum.'); }
	$wish = ($wish === 'redeliver') ? 'redeliver' : 'credit';
	$note = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$note))), 0, 300);
	$allowed = array(); foreach (shop_issue_choices($order) as $c) { $allowed[$c['key']] = $c; }
	$picked = array();
	foreach ((is_array($keys) ? $keys : array()) as $k) { $k = (string)$k; if (isset($allowed[$k]) && !isset($picked[$k])) { $picked[$k] = $allowed[$k]; } }
	if (!$picked) { return array('ok' => false, 'error' => 'Bitte wähle aus, was fehlt.'); }
	if (count($picked) === 1 && isset($picked['other']) && mb_strlen($note) < 3) { return array('ok' => false, 'error' => 'Bitte schreib kurz dazu, was fehlt.'); }
	$gk = shop_coupon_guest_keys($order['phone'], $order['email']);
	$dec = shop_issue_decide(array_map(function ($p) { return $p['cents']; }, array_values($picked)), $wish, $cfg['cap'], shop_issue_auto_used($order), $cfg['auto_30d'], shop_stamp_has_keys($gk));
	$items = array(); foreach ($picked as $p) { $items[] = array('key' => $p['key'], 'label' => $p['label'], 'cents' => $p['cents']); }
	$st = fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_issues')." (order_id, created_at, items, note, wish, status, proposed_cents) VALUES (?, ?, ?, ?, ?, ?, ?)", 'isssssi',
		array((int)$order['id'], date('Y-m-d H:i:s'), json_encode($items, JSON_UNESCAPED_UNICODE), $note, $wish, $dec['status'] === 'credit' ? 'new' : $dec['status'], $dec['proposed']));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return array('ok' => false, 'error' => 'Du hast uns schon etwas gemeldet. Wir kümmern uns darum.'); }
	$id = (int)mysqli_insert_id(fb_db());
	shop_log((int)$order['id'], 'issue', 'Gast meldet: '.mb_substr(implode(', ', array_map(function ($i) { return $i['label']; }, $items)), 0, 150));
	if ($dec['status'] === 'credit') { shop_issue_credit($id, $dec['credit'], 'automatisch', true); }
	shop_issue_notify_driver($id, $order, $items);
	return array('ok' => true, 'issue' => shop_issue_public_issue(shop_issue_get($id)));
}

// a new report: the driver's company phone gets a short text (no names): he may still be near, the dispatch sees it on the board anyway
function shop_issue_notify_driver($id, $order, $items) {
	try {
		$cfg = shop_issue_cfg();
		if (!$cfg['sms_driver'] || !function_exists('sms_enabled') || !sms_enabled()) { return; }
		$d = shop_issue_driver($order);
		$m = $d && $d['phone'] !== '' ? sms_normalize_phone($d['phone']) : null;
		if ($m === null) { return; }
		global $settings; $b = html_entity_decode(!empty($settings['brandName']) ? (string)$settings['brandName'] : 'Amadeus', ENT_QUOTES, 'UTF-8');
		$what = mb_substr(implode(', ', array_map(function ($i) { return $i['label']; }, $items)), 0, 80);
		sms_enqueue(SHOP_ISSUE_SMS_OFFSET + (int)$id, $m, 'issue_driver', sms_gsm_clean($b.': Bei Nr. '.(int)$order['day_no'].' fehlt etwas: '.$what.'. Bitte in der Disposition nachsehen.'));
	} catch (Throwable $e) { error_log('mySeat issue sms: '.$e->getMessage()); }
}
function shop_issue_notify_guest($issue, $order, $text, $event) {
	try {
		if (!function_exists('sms_enabled') || !sms_enabled() || !shop_flag('sms_orders')) { return; }
		$m = sms_normalize_phone(html_entity_decode((string)$order['phone'], ENT_QUOTES, 'UTF-8'));
		if ($m === null) { return; }
		global $settings; $b = html_entity_decode(!empty($settings['brandName']) ? (string)$settings['brandName'] : 'Amadeus', ENT_QUOTES, 'UTF-8');
		$link = shop_site_url().'/order/status.php?t='.$order['token'];
		if (function_exists('sms_link_ready') && sms_link_ready()) { $r = sms_yourls_shorten($link, 24 * 60, 'mySeat Meldung'); if (!empty($r['ok']) && !empty($r['short'])) { $link = $r['short']; } }
		sms_enqueue(SHOP_ISSUE_SMS_OFFSET + (int)$issue['id'], $m, $event, sms_gsm_clean($b.': '.$text.' Details: '.$link));
	} catch (Throwable $e) { error_log('mySeat issue guest sms: '.$e->getMessage()); }
}

// the credit: a personal voucher (fixed amount, used up completely) bound to the phone number / e-mail of the order. $auto = issued by the rule, else by a person.
function shop_issue_credit($id, $cents, $by, $auto = false) {
	$issue = shop_issue_get($id); if (!$issue) { return array('ok' => false, 'error' => 'Die Meldung gibt es nicht.'); }
	if ($issue['coupon_code'] !== '') { return array('ok' => false, 'error' => 'Die Gutschrift ist schon ausgestellt.'); }
	if ($issue['status'] === 'closed') { return array('ok' => false, 'error' => 'Die Meldung ist schon erledigt.'); }
	$cents = (int)$cents;
	if ($cents < 50 || $cents > 10000) { return array('ok' => false, 'error' => 'Bitte einen Betrag zwischen 0,50 € und 100 € eintragen.'); }
	$order = shop_order((int)$issue['order_id']); if (!$order) { return array('ok' => false, 'error' => 'Die Bestellung gibt es nicht.'); }
	$gk = shop_coupon_guest_keys($order['phone'], $order['email']);
	if (!shop_stamp_has_keys($gk)) { return array('ok' => false, 'error' => 'Zu dieser Bestellung gibt es keine Telefonnummer oder E-Mail für den Gutschein.'); }
	$cfg = shop_issue_cfg();
	$code = shop_stamp_code();
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_coupons')." (code, note, kind, value, max_discount_cents, min_order_cents, applies, valid_from, valid_until, max_uses, per_guest, used, active, created_at, source, guest_key, guest_key2, parent_id)
		VALUES (?, ?, 'fixed', ?, 0, ?, 'all', NULL, ?, 1, 0, 0, 1, ?, 'issue', ?, ?, NULL)", 'ssiissss',
		array($code, 'Gutschrift Bestellung #'.(int)$order['day_no'], $cents, $cents, date('Y-m-d H:i:s', time() + $cfg['valid_days'] * 86400), date('Y-m-d H:i:s'), $gk[0], $gk[1]));
	if (!$ok) { return array('ok' => false, 'error' => 'Der Gutschein konnte nicht angelegt werden.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_issues')." SET status = 'credit', credit_cents = ?, coupon_code = ?, auto = ?, handled_by = ?, handled_at = ? WHERE id = ?", 'isissi',
		array($cents, $code, $auto ? 1 : 0, mb_substr((string)$by, 0, 40), date('Y-m-d H:i:s'), (int)$id));
	shop_log((int)$order['id'], 'issue_credit', shop_money($cents).' '.$code.($auto ? ' (automatisch)' : ' ('.$by.')'));
	if (!$auto) { shop_issue_notify_guest($issue, $order, 'Deine Gutschrift über '.shop_money($cents).' ist ausgestellt. Wir ziehen sie bei deiner nächsten Bestellung automatisch ab.', 'issue_credit'); }
	return array('ok' => true, 'code' => $code, 'cents' => $cents);
}

// the staff takes a credit back as long as nobody used it
function shop_issue_revoke($id, $by) {
	$issue = shop_issue_get($id);
	if (!$issue || $issue['coupon_code'] === '') { return array('ok' => false, 'error' => 'Es gibt keine Gutschrift zum Zurücknehmen.'); }
	$st = fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET active = 0 WHERE code = ? AND used = 0", 's', array($issue['coupon_code']));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return array('ok' => false, 'error' => 'Der Gutschein wurde schon eingelöst oder reserviert.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_issues')." SET status = 'new', credit_cents = 0, coupon_code = '', auto = 0, handled_by = ?, handled_at = ? WHERE id = ?", 'ssi', array(mb_substr((string)$by, 0, 40), date('Y-m-d H:i:s'), (int)$id));
	shop_log((int)$issue['order_id'], 'issue_revoke', $issue['coupon_code'].' ('.$by.')');
	return array('ok' => true);
}

// the redelivery: a free follow-up order with the missing things as positions; the kitchen packs it, the dispatch gives it to a driver
function shop_issue_redeliver($id, $etaMin, $by) {
	$issue = shop_issue_get($id); if (!$issue) { return array('ok' => false, 'error' => 'Die Meldung gibt es nicht.'); }
	if ($issue['redelivery_order_id'] !== null) { return array('ok' => false, 'error' => 'Die Nachlieferung ist schon angelegt.'); }
	if ($issue['status'] === 'closed') { return array('ok' => false, 'error' => 'Die Meldung ist schon erledigt.'); }
	$o = shop_order((int)$issue['order_id']); if (!$o) { return array('ok' => false, 'error' => 'Die Bestellung gibt es nicht.'); }
	$etaMin = max(5, min(180, (int)$etaMin));
	$items = json_decode((string)$issue['items'], true); if (!is_array($items) || !$items) { return array('ok' => false, 'error' => 'Es ist nichts zum Nachliefern angegeben.'); }
	$token = bin2hex(random_bytes(16)); $number = shop_order_number(); $today = date('Y-m-d'); $now = date('Y-m-d H:i:s'); $eta = date('Y-m-d H:i:s', time() + $etaMin * 60);
	shop_dayno_lock();
	$dayNo = (int)(fb_row("SELECT COALESCE(MAX(day_no), 0) + 1 AS n FROM ".fb_t('tp_shop_orders')." WHERE order_date = ?", 's', array($today))['n']);
	$note = 'NACHLIEFERUNG zu Nr. '.(int)$o['day_no'].($issue['note'] !== '' ? ': '.$issue['note'] : '');
	$ok = fb_exec("INSERT INTO ".fb_t('tp_shop_orders')."
		(token, number, day_no, order_date, type, status, scheduled_at, eta_at, customer_name, phone, email, street, zip, city, address_note, lat, lng, zone_id,
		 ip_hash, subtotal_cents, fee_cents, tip_cents, total_cents, payment_method, payment_status, note, lang, source, is_test, created_at, updated_at, accepted_at)
		VALUES (?, ?, ?, ?, 'delivery', 'accepted', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', 0, 0, 0, 0, 'cash', 'paid', ?, 'de', 'redelivery', 0, ?, ?, ?)",
		'ssis'.'s'.'sssssss'.'ddi'.'s'.'sss', array($token, $number, $dayNo, $today, $eta, $o['customer_name'], $o['phone'], $o['email'], $o['street'], $o['zip'], $o['city'], $o['address_note'],
			$o['lat'] === null ? 0 : $o['lat'], $o['lng'] === null ? 0 : $o['lng'], $o['zone_id'] === null ? 0 : $o['zone_id'], $note, $now, $now, $now));
	if (!$ok) { shop_dayno_unlock(); return array('ok' => false, 'error' => 'Die Nachlieferung konnte nicht angelegt werden.'); }
	$newId = (int)mysqli_insert_id(fb_db()); shop_dayno_unlock();
	foreach ($items as $it) {
		fb_exec("INSERT INTO ".fb_t('tp_shop_order_items')." (order_id, product_id, title, variation, options, qty, unit_cents, line_cents, note) VALUES (?, NULL, ?, '', '[]', 1, 0, 0, '')", 'is', array($newId, mb_substr((string)$it['label'], 0, 120)));
	}
	fb_exec("UPDATE ".fb_t('tp_shop_issues')." SET status = 'redelivery', redelivery_order_id = ?, eta_at = ?, handled_by = ?, handled_at = ? WHERE id = ?", 'isssi', array($newId, $eta, mb_substr((string)$by, 0, 40), $now, (int)$id));
	shop_log($newId, 'created', 'Nachlieferung zu Nr. '.(int)$o['day_no']);
	shop_log((int)$o['id'], 'issue_redelivery', 'Nachlieferung Nr. '.$dayNo.' gegen '.date('H:i', strtotime($eta)).' ('.$by.')');
	shop_issue_notify_guest($issue, $o, 'Wir bringen dir das Fehlende nach, gegen '.date('H:i', strtotime($eta)).' Uhr.', 'issue_redelivery');
	return array('ok' => true, 'order_id' => $newId, 'day_no' => $dayNo);
}

function shop_issue_close($id, $by) {
	$issue = shop_issue_get($id); if (!$issue) { return array('ok' => false, 'error' => 'Die Meldung gibt es nicht.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_issues')." SET status = 'closed', handled_by = ?, handled_at = ? WHERE id = ?", 'ssi', array(mb_substr((string)$by, 0, 40), date('Y-m-d H:i:s'), (int)$id));
	shop_log((int)$issue['order_id'], 'issue_closed', $by);
	return array('ok' => true);
}

// what the status page shows about the report (no internals)
function shop_issue_public_issue($i) {
	if (!$i) { return null; }
	$items = json_decode((string)$i['items'], true); if (!is_array($items)) { $items = array(); }
	$labels = array_map(function ($x) { return $x['label']; }, $items);
	$st = $i['status'];
	if ($st === 'credit') { $text = 'Deine Gutschrift über '.shop_money((int)$i['credit_cents']).' ist ausgestellt. Wir ziehen sie bei deiner nächsten Bestellung automatisch ab.'; }
	elseif ($st === 'redelivery') { $text = 'Wir bringen dir das Fehlende nach, gegen '.($i['eta_at'] ? date('H:i', strtotime($i['eta_at'])) : '').' Uhr.'; }
	elseif ($st === 'approval') { $text = 'Danke, wir prüfen deine Meldung und melden uns gleich.'; }
	elseif ($st === 'closed') { $text = 'Wir haben deine Meldung bearbeitet. Danke für deine Geduld.'; }
	else { $text = $i['wish'] === 'redeliver' ? 'Danke, wir haben deine Meldung. Wir melden uns gleich, ob und wann wir es nachliefern können.' : 'Danke, wir haben deine Meldung. Wir kümmern uns darum.'; }
	return array('status' => $st, 'text' => $text, 'items' => $labels, 'wish' => $i['wish'], 'code' => $st === 'credit' ? $i['coupon_code'] : '', 'credit' => $st === 'credit' ? shop_money((int)$i['credit_cents']) : '');
}

// everything the status page needs: whether to show the box, the way to call, the things to tick, the report (when there is one)
function shop_issue_public($order, $restPhone = '') {
	$cfg = shop_issue_cfg();
	$open = shop_issue_open_until($order, $cfg);
	$issue = shop_issue_public_issue(shop_issue_by_order((int)$order['id']));
	if (!$open && !$issue) { return array('show' => false); }
	$drv = shop_issue_driver($order);
	$call = ($drv && $drv['phone'] !== '') ? array('who' => $drv['name'], 'phone' => $drv['phone'], 'driver' => true) : ($restPhone !== '' ? array('who' => '', 'phone' => $restPhone, 'driver' => false) : null);
	$choices = $open && !$issue ? array_map(function ($c) { return array('key' => $c['key'], 'label' => $c['label']); }, shop_issue_choices($order)) : array();
	$raw = shop_issue_by_order((int)$order['id']);
	return array('show' => true, 'open' => (bool)$open, 'until' => $open ? date('H:i', $open) : '', 'call' => $call, 'choices' => $choices, 'issue' => $issue, 'chat' => $raw ? shop_issue_chat_state($raw) : null);
}

// the board of the dispatch: open reports of the last day (a closed one disappears)
function shop_issue_open_list() {
	$rows = fb_rows("SELECT i.*, o.day_no, o.customer_name, o.phone, o.driver_id FROM ".fb_t('tp_shop_issues')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE i.status <> 'closed' AND i.created_at > ? ORDER BY i.created_at DESC LIMIT 20", 's', array(date('Y-m-d H:i:s', time() - 86400)));
	$names = function_exists('shop_drivers_name_map') ? shop_drivers_name_map() : array();
	$out = array();
	foreach ($rows as $r) {
		$items = json_decode((string)$r['items'], true); if (!is_array($items)) { $items = array(); }
		$total = 0; $unknown = false; foreach ($items as $x) { if ($x['cents'] === null) { $unknown = true; } else { $total += (int)$x['cents']; } }
		$rdNo = 0; if ($r['redelivery_order_id']) { $rd = fb_row("SELECT day_no FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$r['redelivery_order_id'])); $rdNo = $rd ? (int)$rd['day_no'] : 0; }
		$out[] = array('id' => (int)$r['id'], 'status' => $r['status'], 'wish' => $r['wish'], 'day_no' => (int)$r['day_no'], 'name' => (string)$r['customer_name'], 'phone' => (string)$r['phone'],
			'driver' => ($r['driver_id'] && isset($names[(int)$r['driver_id']])) ? (string)$names[(int)$r['driver_id']] : '', 'items' => array_map(function ($x) { return $x['label']; }, $items),
			'note' => (string)$r['note'], 'value' => $unknown ? null : $total, 'value_text' => $unknown ? '' : shop_money($total), 'credit_text' => $r['status'] === 'credit' ? shop_money((int)$r['credit_cents']) : '',
			'auto' => (int)$r['auto'], 'code' => (string)$r['coupon_code'], 'redelivery_no' => $rdNo,
			'eta' => $r['eta_at'] ? date('H:i', strtotime($r['eta_at'])) : '', 'age_min' => max(0, (int)floor((time() - strtotime($r['created_at'])) / 60)), 'at' => date('H:i', strtotime($r['created_at'])),
			'unread' => (int)fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_issue_msgs')." WHERE issue_id = ? AND side = 'guest' AND id > ?", 'ii', array((int)$r['id'], (int)$r['staff_seen_msg']))['n'],
			'needs_human' => (int)$r['needs_human'], 'ai_off' => (int)$r['ai_off']);
	}
	return $out;
}

// the driver page: open reports of the deliveries this driver made in the last hours (so he can call the guest or bring the missing thing)
function shop_issue_driver_list($driverId) {
	$rows = fb_rows("SELECT i.id, i.status, i.wish, i.items, i.note, i.eta_at, o.day_no, o.customer_name, o.phone, o.street FROM ".fb_t('tp_shop_issues')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id
		WHERE o.driver_id = ? AND i.status <> 'closed' AND i.created_at > ? ORDER BY i.created_at DESC LIMIT 5", 'is', array((int)$driverId, date('Y-m-d H:i:s', time() - 3 * 3600)));
	$out = array();
	foreach ($rows as $r) {
		$items = json_decode((string)$r['items'], true); if (!is_array($items)) { $items = array(); }
		$out[] = array('id' => (int)$r['id'], 'status' => $r['status'], 'wish' => $r['wish'], 'day_no' => (int)$r['day_no'], 'name' => (string)$r['customer_name'], 'phone' => (string)$r['phone'], 'street' => (string)$r['street'],
			'items' => array_map(function ($x) { return $x['label']; }, $items), 'note' => (string)$r['note'], 'eta' => $r['eta_at'] ? date('H:i', strtotime($r['eta_at'])) : '');
	}
	return $out;
}
