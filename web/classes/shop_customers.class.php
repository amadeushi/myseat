<?php
/*
 * Customer backend (web/content/customers.page.php, web/ajax/shop_customers.php): who has ordered, who has an account, a stamp card, a voucher.
 * There is no customer table: a customer is a group of the guest keys that the orders, the accounts, the stamps and the vouchers already carry (sha1 of the phone
 * number's last nine digits, sha1 of the e-mail address - shop_coupon_guest_keys()). Keys that meet in one order or one account are one customer; two customers that
 * are one person can be linked by hand. What is new: a note and marks per customer, the links, and the log of what staff did by hand.
 * Orders without a phone number or e-mail (Lieferando, the till without a number) cannot be assigned and are not customers.
 */
require_once __DIR__.'/shop.class.php';
require_once __DIR__.'/shop_account.class.php';

function shop_cust_flag_labels() {
	return array('stamm' => 'Stammgast', 'vip' => 'VIP', 'allergie' => 'Allergie', 'vorsicht' => 'Vorsicht', 'passend' => 'Nur passend bar');
}

function shop_cust_uf_find(&$p, $k) {
	if (!isset($p[$k])) { $p[$k] = $k; return $k; }
	while ($p[$k] !== $k) { $p[$k] = $p[$p[$k]]; $k = $p[$k]; }
	return $k;
}
function shop_cust_uf_union(&$p, $a, $b) {
	if ($a === '' || $b === '') { return; }
	$ra = shop_cust_uf_find($p, $a); $rb = shop_cust_uf_find($p, $b);
	if ($ra !== $rb) { if ($ra < $rb) { $p[$rb] = $ra; } else { $p[$ra] = $rb; } }
}
function shop_cust_digits($phone) { $d = preg_replace('/\D/', '', (string)$phone); return $d; }

// all customers, as array(id => record); built once per request
function shop_cust_index($reset = false) {
	static $idx = null;
	if ($reset) { $idx = null; }
	if ($idx !== null) { return $idx; }
	shop_ensure_schema(); shop_acc_backfill_keys();
	$t = function ($n) { return fb_t($n); };
	$now = date('Y-m-d H:i:s');
	$since = date('Y-m-d H:i:s', time() - max(1, (int)shop_setting('cust_regular_days')) * 86400);
	$ok = "o.is_test = 0 AND o.status <> 'pending' AND (o.guest_key NOT IN ('', '-') OR o.guest_key2 <> '')";
	$p = array(); $recs = array();
	$pairs = fb_rows("SELECT o.guest_key k1, o.guest_key2 k2,
			SUM(o.status NOT IN ('cancelled', 'failed')) n, SUM(o.status NOT IN ('cancelled', 'failed') AND o.created_at > ?) n_recent, SUM(o.status IN ('cancelled', 'failed')) bad,
			COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'failed') THEN o.total_cents ELSE 0 END), 0) rev, MIN(o.created_at) first_at, MAX(o.created_at) last_at
		FROM ".$t('tp_shop_orders')." o WHERE $ok GROUP BY o.guest_key, o.guest_key2", 's', array($since));
	$latest = fb_rows("SELECT o.guest_key k1, o.guest_key2 k2, o.customer_name, o.phone, o.email, o.created_at FROM ".$t('tp_shop_orders')." o
		WHERE o.id IN (SELECT MAX(o2.id) FROM ".$t('tp_shop_orders')." o2 WHERE o2.is_test = 0 AND o2.status <> 'pending' AND (o2.guest_key NOT IN ('', '-') OR o2.guest_key2 <> '') GROUP BY o2.guest_key, o2.guest_key2)");
	$accs = fb_rows("SELECT id, key_phone, key_mail, mask_phone, mask_mail, name, contact_phone, contact_mail, created_at, last_login_at, blocked FROM ".$t('tp_shop_accounts'));
	$stamps = fb_rows("SELECT guest_key k1, guest_key2 k2, COUNT(*) c, MIN(expires_at) expd FROM ".$t('tp_shop_stamps')." WHERE coupon_id IS NULL AND expires_at > ? GROUP BY guest_key, guest_key2", 's', array($now));
	$vouch = fb_rows("SELECT guest_key k1, guest_key2 k2, COUNT(*) c, MAX(value) v, MIN(valid_until) vuntil FROM ".$t('tp_shop_coupons')."
		WHERE source = 'stamp' AND active = 1 AND used < max_uses AND (valid_until IS NULL OR valid_until > ?) AND (guest_key <> '' OR guest_key2 <> '') GROUP BY guest_key, guest_key2", 's', array($now));
	$notes = fb_rows("SELECT ckey, note, flags FROM ".$t('tp_shop_customer_notes'));
	$links = fb_rows("SELECT a, b FROM ".$t('tp_shop_customer_links'));
	$clean = function ($k) { return ($k === '-' || $k === null) ? '' : (string)$k; };
	foreach ($pairs as $r) { $a = $clean($r['k1']); $b = $clean($r['k2']); if ($a !== '') { shop_cust_uf_find($p, $a); } if ($b !== '') { shop_cust_uf_find($p, $b); } shop_cust_uf_union($p, $a, $b); }
	foreach ($accs as $r) { $a = $clean($r['key_phone']); $b = $clean($r['key_mail']); if ($a !== '') { shop_cust_uf_find($p, $a); } if ($b !== '') { shop_cust_uf_find($p, $b); } shop_cust_uf_union($p, $a, $b); }
	foreach (array($stamps, $vouch) as $set) { foreach ($set as $r) { $a = $clean($r['k1']); $b = $clean($r['k2']); if ($a !== '') { shop_cust_uf_find($p, $a); } if ($b !== '') { shop_cust_uf_find($p, $b); } shop_cust_uf_union($p, $a, $b); } }
	foreach ($links as $r) { shop_cust_uf_find($p, $r['a']); shop_cust_uf_find($p, $r['b']); shop_cust_uf_union($p, $r['a'], $r['b']); }
	$rec = function ($root) use (&$recs) {
		if (!isset($recs[$root])) { $recs[$root] = array('id' => $root, 'keys' => array(), 'name' => '', 'phone' => '', 'email' => '', 'n' => 0, 'n_recent' => 0, 'bad' => 0, 'rev' => 0, 'first_at' => '', 'last_at' => '', 'accounts' => array(), 'blocked' => false,
			'stamps' => 0, 'stamp_until' => '', 'voucher' => 0, 'voucher_value' => 0, 'note' => '', 'flags' => array(), 'seen' => ''); }
		return $recs[$root];
	};
	foreach (array_keys($p) as $k) { $root = shop_cust_uf_find($p, $k); $r = $rec($root); $recs[$root]['keys'][] = $k; }
	foreach ($pairs as $r) {
		$key = $clean($r['k1']) !== '' ? $clean($r['k1']) : $clean($r['k2']); $root = shop_cust_uf_find($p, $key); $rec($root); $c = &$recs[$root];
		$c['n'] += (int)$r['n']; $c['n_recent'] += (int)$r['n_recent']; $c['bad'] += (int)$r['bad']; $c['rev'] += (int)$r['rev'];
		if ($c['first_at'] === '' || $r['first_at'] < $c['first_at']) { $c['first_at'] = $r['first_at']; }
		if ($r['last_at'] > $c['last_at']) { $c['last_at'] = $r['last_at']; }
		unset($c);
	}
	foreach ($latest as $r) {
		$key = $clean($r['k1']) !== '' ? $clean($r['k1']) : $clean($r['k2']); $root = shop_cust_uf_find($p, $key); $rec($root); $c = &$recs[$root];
		if ($r['created_at'] >= $c['seen']) { $c['seen'] = $r['created_at']; $c['name'] = (string)$r['customer_name']; $c['phone'] = (string)$r['phone']; $c['email'] = (string)$r['email']; }
		unset($c);
	}
	foreach ($accs as $a) {
		$key = $clean($a['key_phone']) !== '' ? $clean($a['key_phone']) : $clean($a['key_mail']); if ($key === '') { continue; }
		$root = shop_cust_uf_find($p, $key); $rec($root); $c = &$recs[$root];
		$c['accounts'][] = (int)$a['id']; if (!empty($a['blocked'])) { $c['blocked'] = true; }
		if ($c['name'] === '') { $c['name'] = (string)$a['name']; }
		if ($c['phone'] === '') { $c['phone'] = $a['contact_phone'] !== '' ? (string)$a['contact_phone'] : (string)$a['mask_phone']; }
		if ($c['email'] === '') { $c['email'] = $a['contact_mail'] !== '' ? (string)$a['contact_mail'] : (string)$a['mask_mail']; }
		if ($c['last_at'] === '' && $a['last_login_at']) { $c['last_at'] = (string)$a['last_login_at']; }
		unset($c);
	}
	foreach ($stamps as $s) { $key = $clean($s['k1']) !== '' ? $clean($s['k1']) : $clean($s['k2']); if ($key === '') { continue; } $root = shop_cust_uf_find($p, $key); $rec($root); $c = &$recs[$root]; $c['stamps'] += (int)$s['c']; if ($c['stamp_until'] === '' || $s['expd'] < $c['stamp_until']) { $c['stamp_until'] = (string)$s['expd']; } unset($c); }
	foreach ($vouch as $s) { $key = $clean($s['k1']) !== '' ? $clean($s['k1']) : $clean($s['k2']); if ($key === '') { continue; } $root = shop_cust_uf_find($p, $key); $rec($root); $c = &$recs[$root]; $c['voucher'] += (int)$s['c']; $c['voucher_value'] = max($c['voucher_value'], (int)$s['v']); unset($c); }
	foreach ($notes as $nt) { if (!isset($p[$nt['ckey']])) { continue; } $root = shop_cust_uf_find($p, $nt['ckey']); $rec($root); $recs[$root]['note'] = trim((string)$nt['note'] ?: $recs[$root]['note']); foreach (array_filter(explode(',', (string)$nt['flags'])) as $f) { $recs[$root]['flags'][$f] = true; } }
	$out = array();
	foreach ($recs as $root => $c) {
		sort($c['keys']); $c['id'] = $c['keys'] ? $c['keys'][0] : $root; $c['flags'] = array_keys($c['flags']);
		$c['digits'] = shop_cust_digits($c['phone']); $c['search'] = mb_strtolower($c['name'].' '.$c['email'].' '.$c['note']);
		$out[$c['id']] = $c;
	}
	$idx = $out;
	return $idx;
}

function shop_cust_segment_of($c, $cfg) {
	$seg = array();
	$days = $c['last_at'] ? (time() - strtotime($c['last_at'])) / 86400 : 9999;
	if ($c['n_recent'] >= $cfg['reg_n']) { $seg[] = 'regular'; }
	if ($c['n'] === 1 && $c['first_at'] && (time() - strtotime($c['first_at'])) / 86400 <= $cfg['new_days']) { $seg[] = 'new'; }
	if ($c['n'] >= 2 && $days > $cfg['sleep_days']) { $seg[] = 'sleeping'; }
	$goal = shop_stamp_cfg()['goal'];
	if ($c['stamps'] > 0 && $c['stamps'] >= $goal - 1) { $seg[] = 'almost'; }
	if ($c['accounts']) { $seg[] = 'account'; }
	if ($c['note'] !== '' || $c['flags']) { $seg[] = 'note'; }
	if ($c['voucher'] > 0) { $seg[] = 'voucher'; }
	if ($c['bad'] >= 2) { $seg[] = 'problem'; }
	return $seg;
}

function shop_cust_row($c) {
	$g = shop_stamp_cfg();
	return array('id' => $c['id'], 'name' => $c['name'] !== '' ? $c['name'] : 'Ohne Namen', 'phone' => $c['phone'], 'email' => $c['email'], 'n' => $c['n'], 'rev' => $c['rev'], 'last' => $c['last_at'] ? substr($c['last_at'], 0, 10) : '',
		'account' => (bool)$c['accounts'], 'blocked' => $c['blocked'], 'stamps' => $c['stamps'], 'goal' => $g['goal'], 'voucher' => $c['voucher_value'], 'flags' => $c['flags'], 'has_note' => $c['note'] !== '');
}

function shop_cust_list($q, $view, $offset, $limit) {
	$idx = shop_cust_index();
	$cfg = array('reg_n' => max(1, (int)shop_setting('cust_regular_n')), 'sleep_days' => max(1, (int)shop_setting('cust_sleep_days')), 'new_days' => max(1, (int)shop_setting('cust_new_days')));
	$q = trim((string)$q); $qd = shop_cust_digits($q); $ql = mb_strtolower($q);
	$counts = array('all' => 0, 'regular' => 0, 'new' => 0, 'sleeping' => 0, 'almost' => 0, 'account' => 0, 'note' => 0, 'voucher' => 0, 'problem' => 0);
	$rows = array();
	foreach ($idx as $c) {
		if ($q !== '') {
			$hit = (strlen($qd) >= 3 && strpos($c['digits'], $qd) !== false) || ($ql !== '' && mb_strpos($c['search'], $ql) !== false);
			if (!$hit) { continue; }
		}
		$seg = shop_cust_segment_of($c, $cfg);
		$counts['all']++; foreach ($seg as $s) { $counts[$s]++; }
		if ($view !== 'all' && !in_array($view, $seg, true)) { continue; }
		$rows[] = $c;
	}
	usort($rows, function ($a, $b) { return strcmp($b['last_at'], $a['last_at']) ?: strcmp($a['name'], $b['name']); });
	$page = array_slice($rows, max(0, (int)$offset), max(1, min(100, (int)$limit)));
	$unassigned = (int)fb_row("SELECT COUNT(*) n FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND status NOT IN ('pending', 'cancelled', 'failed') AND guest_key IN ('', '-') AND guest_key2 = ''")['n'];
	return array('rows' => array_map('shop_cust_row', $page), 'total' => count($rows), 'counts' => $counts, 'unassigned' => $unassigned);
}

// keys for what is given by hand (stamp, voucher): the keys of the account when there is one (that is what the guest sees in the shop), else the phone key and the e-mail key of the orders
function shop_cust_keys($c) {
	foreach (fb_rows("SELECT key_phone, key_mail FROM ".fb_t('tp_shop_accounts')." WHERE id IN (".($c['accounts'] ? implode(',', array_map('intval', $c['accounts'])) : '0').") ORDER BY id") as $a) {
		$k = shop_acc_keys($a); if ($k[0] !== '' || $k[1] !== '') { return $k; }
	}
	$o = fb_row("SELECT guest_key, guest_key2 FROM ".fb_t('tp_shop_orders')." WHERE is_test = 0 AND (guest_key IN (".shop_cust_in($c['keys']).") OR guest_key2 IN (".shop_cust_in($c['keys']).")) ORDER BY id DESC LIMIT 1");
	if ($o) { return array($o['guest_key'] === '-' ? '' : (string)$o['guest_key'], (string)$o['guest_key2']); }
	return array($c['keys'] ? $c['keys'][0] : '', '');
}
function shop_cust_in($keys) { $o = array(); foreach ($keys as $k) { $o[] = "'".preg_replace('/[^a-f0-9]/', '', (string)$k)."'"; } return $o ? implode(',', $o) : "''"; }
function shop_cust_match($keys) { $in = shop_cust_in($keys); return "(guest_key IN ($in) OR guest_key2 IN ($in))"; }

function shop_cust_log($ckey, $event, $detail, $by) {
	fb_exec("INSERT INTO ".fb_t('tp_shop_customer_log')." (ckey, event, detail, by_user, at) VALUES (?, ?, ?, ?, ?)", 'sssss', array((string)$ckey, mb_substr($event, 0, 20), mb_substr($detail, 0, 255), mb_substr((string)$by, 0, 60), date('Y-m-d H:i:s')));
}

function shop_cust_card($id) {
	$idx = shop_cust_index();
	if (!isset($idx[$id])) { return null; }
	$c = $idx[$id]; $m = shop_cust_match($c['keys']); $og = "(o.guest_key IN (".shop_cust_in($c['keys']).") OR o.guest_key2 IN (".shop_cust_in($c['keys'])."))"; $cfg = shop_stamp_cfg();
	$orders = array();
	foreach (fb_rows("SELECT o.id, o.number, o.day_no, o.created_at, o.type, o.status, o.source, o.payment_method, o.total_cents, o.customer_name FROM ".fb_t('tp_shop_orders')." o WHERE o.is_test = 0 AND o.status <> 'pending' AND $og ORDER BY o.id DESC LIMIT 15") as $r) {
		$orders[] = array('id' => (int)$r['id'], 'number' => $r['number'], 'day_no' => (int)$r['day_no'], 'at' => $r['created_at'], 'type' => $r['type'], 'status' => $r['status'], 'source' => $r['source'], 'pay' => $r['payment_method'], 'total' => (int)$r['total_cents']);
	}
	$ident = array();
	foreach (fb_rows("SELECT customer_name, phone, email, street, zip, city, COUNT(*) n, MAX(created_at) seen_at FROM ".fb_t('tp_shop_orders')." o WHERE o.is_test = 0 AND $og GROUP BY customer_name, phone, email, street, zip, city ORDER BY seen_at DESC LIMIT 6") as $r) {
		$ident[] = array('name' => $r['customer_name'], 'phone' => $r['phone'], 'email' => $r['email'], 'address' => trim($r['street'].($r['zip'] !== '' || $r['city'] !== '' ? ', '.trim($r['zip'].' '.$r['city']) : ''), ' ,'), 'n' => (int)$r['n']);
	}
	$top = array();
	foreach (fb_rows("SELECT i.title, SUM(i.qty) q FROM ".fb_t('tp_shop_order_items')." i JOIN ".fb_t('tp_shop_orders')." o ON o.id = i.order_id WHERE o.is_test = 0 AND o.status NOT IN ('pending', 'cancelled', 'failed') AND $og GROUP BY i.title ORDER BY q DESC, i.title LIMIT 3") as $r) { $top[] = array($r['title'], (int)$r['q']); }
	$pay = array();
	foreach (fb_rows("SELECT payment_method pm, COUNT(*) n FROM ".fb_t('tp_shop_orders')." o WHERE o.is_test = 0 AND o.status NOT IN ('pending', 'cancelled', 'failed') AND $og GROUP BY payment_method") as $r) { $pay[$r['pm']] = (int)$r['n']; }
	$accounts = array();
	if ($c['accounts']) {
		foreach (fb_rows("SELECT id, mask_phone, mask_mail, name, contact_phone, contact_mail, created_at, last_login_at, blocked, addr_street, addr_zip, addr_city FROM ".fb_t('tp_shop_accounts')." WHERE id IN (".implode(',', array_map('intval', $c['accounts'])).") ORDER BY id") as $a) {
			$accounts[] = array('id' => (int)$a['id'], 'phone' => $a['contact_phone'] !== '' ? $a['contact_phone'] : $a['mask_phone'], 'mail' => $a['contact_mail'] !== '' ? $a['contact_mail'] : $a['mask_mail'], 'created' => substr($a['created_at'], 0, 10), 'login' => $a['last_login_at'] ? substr($a['last_login_at'], 0, 16) : '', 'blocked' => !empty($a['blocked']),
				'address' => trim($a['addr_street'].($a['addr_zip'] !== '' || $a['addr_city'] !== '' ? ', '.trim($a['addr_zip'].' '.$a['addr_city']) : ''), ' ,'));
		}
	}
	$now = date('Y-m-d H:i:s'); $st = array();
	foreach (fb_rows("SELECT id, order_id, base_cents, earned_at, expires_at, coupon_id FROM ".fb_t('tp_shop_stamps')." WHERE $m ORDER BY earned_at DESC, id DESC LIMIT 30") as $s) {
		$st[] = array('id' => (int)$s['id'], 'manual' => (int)$s['order_id'] < 0, 'at' => substr($s['earned_at'], 0, 10), 'until' => substr($s['expires_at'], 0, 10), 'state' => $s['coupon_id'] !== null ? 'used' : ($s['expires_at'] <= $now ? 'expired' : 'open'), 'base' => (int)$s['base_cents'], 'order_id' => (int)$s['order_id']);
	}
	$vo = array();
	foreach (fb_rows("SELECT id, code, note, value, valid_until, used, max_uses, active, created_at FROM ".fb_t('tp_shop_coupons')." WHERE source = 'stamp' AND $m ORDER BY id DESC LIMIT 10") as $v) {
		$live = !empty($v['active']) && (int)$v['used'] < (int)$v['max_uses'] && (!$v['valid_until'] || $v['valid_until'] > $now);
		$vo[] = array('id' => (int)$v['id'], 'code' => $v['code'], 'note' => $v['note'], 'value' => (int)$v['value'], 'until' => $v['valid_until'] ? substr($v['valid_until'], 0, 10) : '', 'state' => $live ? 'live' : ((int)$v['used'] >= (int)$v['max_uses'] ? 'used' : (empty($v['active']) ? 'blocked' : 'expired')));
	}
	$links = array();
	foreach (fb_rows("SELECT a, b FROM ".fb_t('tp_shop_customer_links')." WHERE a IN (".shop_cust_in($c['keys']).") OR b IN (".shop_cust_in($c['keys']).")") as $l) { $links[] = array($l['a'], $l['b']); }
	$log = array();
	foreach (fb_rows("SELECT event, detail, by_user, at FROM ".fb_t('tp_shop_customer_log')." WHERE ckey IN (".shop_cust_in($c['keys']).") ORDER BY id DESC LIMIT 25") as $l) { $log[] = array('event' => $l['event'], 'detail' => $l['detail'], 'by' => $l['by_user'], 'at' => substr($l['at'], 0, 16)); }
	return array('id' => $c['id'], 'name' => $c['name'] !== '' ? $c['name'] : 'Ohne Namen', 'phone' => $c['phone'], 'email' => $c['email'], 'ident' => $ident, 'n' => $c['n'], 'rev' => $c['rev'], 'avg' => $c['n'] ? (int)round($c['rev'] / $c['n']) : 0, 'bad' => $c['bad'],
		'first' => $c['first_at'] ? substr($c['first_at'], 0, 10) : '', 'last' => $c['last_at'] ? substr($c['last_at'], 0, 10) : '', 'top' => $top, 'pay' => $pay, 'accounts' => $accounts, 'blocked' => $c['blocked'],
		'goal' => $cfg['goal'], 'percent' => $cfg['percent'], 'stamps_open' => $c['stamps'], 'stamp_until' => $c['stamp_until'] ? substr($c['stamp_until'], 0, 10) : '', 'stamps' => $st, 'vouchers' => $vo, 'note' => $c['note'], 'flags' => $c['flags'], 'flag_labels' => shop_cust_flag_labels(),
		'links' => $links, 'orders' => $orders, 'log' => $log, 'multi_keys' => count($c['keys']) > 2, 'suggest' => shop_cust_stamp_suggest($c), 'contact' => shop_cust_contact($c));
}

// ---- actions (each answers array(ok, ...); what staff does by hand goes to the log of the customer)
function shop_cust_find($id) { $idx = shop_cust_index(); return isset($idx[$id]) ? $idx[$id] : null; }
function shop_cust_reason($r) { $r = trim((string)$r); return in_array($r, array('Kulanz', 'Reklamation', 'Korrektur', 'Sonstiges'), true) ? $r : 'ohne Angabe'; }

function shop_cust_note_save($id, $note, $flags, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$allowed = array_keys(shop_cust_flag_labels()); $fl = array();
	foreach ((array)$flags as $f) { if (in_array($f, $allowed, true)) { $fl[$f] = $f; } }
	fb_exec("DELETE FROM ".fb_t('tp_shop_customer_notes')." WHERE ckey IN (".shop_cust_in($c['keys']).")");
	$note = mb_substr(trim((string)$note), 0, 1000);
	if ($note !== '' || $fl) { fb_exec("INSERT INTO ".fb_t('tp_shop_customer_notes')." (ckey, note, flags, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)", 'sssss', array($c['id'], $note, implode(',', $fl), date('Y-m-d H:i:s'), mb_substr((string)$by, 0, 60))); }
	shop_cust_log($c['id'], 'note', ($fl ? implode(', ', array_map(function ($f) { $l = shop_cust_flag_labels(); return $l[$f]; }, $fl)) : 'keine Merkmale').($note !== '' ? '; Notiz geändert' : '; Notiz leer'), $by);
	return array('ok' => true);
}

// what a stamp by hand is worth when nothing else is said: the average of the open stamps on the card (at least 5 EUR), else 15 EUR. The till asks the staff; this is only the suggestion.
function shop_cust_stamp_suggest($c) {
	$open = shop_stamp_open(shop_cust_keys($c)); $base = 1500;
	if ($open) { $sum = 0; foreach ($open as $s) { $sum += (int)$s['base_cents']; } $base = max(500, (int)round($sum / count($open))); }
	return $base;
}
// where a notice to the guest can go: a real e-mail address from the orders (the account only holds a masked one), and a mobile number when SMS is set up
function shop_cust_contact($c) {
	$mail = filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL) ? trim((string)$c['email']) : '';
	$sms = (function_exists('sms_enabled') && sms_enabled() && sms_normalize_phone(html_entity_decode((string)$c['phone'], ENT_QUOTES, 'UTF-8')) !== null);
	return array('mail' => $mail, 'sms' => $sms);
}
function shop_cust_stamp_add($id, $base, $reason, $by, $notify = false) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$base = (int)$base; if ($base < 100 || $base > 10000) { return array('ok' => false, 'error' => 'Der Grundbetrag liegt zwischen 1,00 und 100,00 Euro.'); }
	$keys = shop_cust_keys($c); if (!shop_stamp_has_keys($keys)) { return array('ok' => false, 'error' => 'Für diesen Kunden gibt es keine Nummer oder Mailadresse, an die ein Stempel gehören könnte.'); }
	$cfg = shop_stamp_cfg();
	$ok = false;
	for ($t = 0; $t < 5 && !$ok; $t++) {
		$st = fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_stamps')." (order_id, guest_key, guest_key2, base_cents, earned_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)", 'ississ',
			array(-random_int(1, 2000000000), $keys[0], $keys[1], $base, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', strtotime('+'.$cfg['months'].' months'))));
		$ok = $st && mysqli_stmt_affected_rows($st) === 1;
	}
	if (!$ok) { return array('ok' => false, 'error' => 'Der Stempel konnte nicht gespeichert werden.'); }
	$ct = shop_cust_contact($c);
	// a full card makes the voucher and tells the guest by itself; without the tick the notice is held back (no address, no mail)
	$made = shop_stamp_check($keys, $notify ? $c['phone'] : '', $notify ? $c['email'] : '', $c['name']);
	$told = false;
	if ($notify && !$made && $ct['mail'] !== '') { shop_stamp_notify_stamp($ct['mail'], $c['name'], $keys); $told = true; }
	if ($notify && $made && ($ct['mail'] !== '' || $ct['sms'])) { $told = true; }
	shop_cust_log($c['id'], 'stamp+', 'Stempel gutgeschrieben, Grundbetrag '.shop_money($base).' ('.shop_cust_reason($reason).')'.($made ? '; Karte voll, Gutschein ausgestellt' : '').($told ? '; Gast benachrichtigt' : '; Gast nicht benachrichtigt'), $by);
	shop_cust_index(true);
	return array('ok' => true, 'voucher' => $made > 0, 'notified' => $told);
}
// corrects the base amount of a stamp that was given by hand and is not yet part of a voucher (an earned stamp keeps what the guest paid)
function shop_cust_stamp_base($id, $stampId, $base, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$base = (int)$base; if ($base < 100 || $base > 10000) { return array('ok' => false, 'error' => 'Der Grundbetrag liegt zwischen 1,00 und 100,00 Euro.'); }
	$s = fb_row("SELECT id, order_id, coupon_id, base_cents FROM ".fb_t('tp_shop_stamps')." WHERE id = ? AND ".shop_cust_match($c['keys']), 'i', array((int)$stampId));
	if (!$s) { return array('ok' => false, 'error' => 'Diesen Stempel gibt es nicht.'); }
	if ((int)$s['order_id'] >= 0) { return array('ok' => false, 'error' => 'Nur Stempel von Hand lassen sich ändern. Ein Stempel aus einer Bestellung hält den Betrag fest, den der Gast bezahlt hat.'); }
	if ($s['coupon_id'] !== null) { return array('ok' => false, 'error' => 'Dieser Stempel ist schon Teil eines Gutscheins und lässt sich nicht mehr ändern.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_stamps')." SET base_cents = ? WHERE id = ? AND coupon_id IS NULL", 'ii', array($base, (int)$stampId));
	shop_cust_log($c['id'], 'stamp~', 'Grundbetrag eines Stempels von '.shop_money((int)$s['base_cents']).' auf '.shop_money($base).' geändert', $by);
	shop_cust_index(true);
	return array('ok' => true);
}
function shop_cust_stamp_remove($id, $stampId, $reason, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$s = fb_row("SELECT id, coupon_id, guest_key, guest_key2 FROM ".fb_t('tp_shop_stamps')." WHERE id = ? AND ".shop_cust_match($c['keys']), 'i', array((int)$stampId));
	if (!$s) { return array('ok' => false, 'error' => 'Diesen Stempel gibt es nicht.'); }
	if ($s['coupon_id'] !== null) { return array('ok' => false, 'error' => 'Dieser Stempel ist schon Teil eines Gutscheins.'); }
	fb_exec("DELETE FROM ".fb_t('tp_shop_stamps')." WHERE id = ? AND coupon_id IS NULL", 'i', array((int)$stampId));
	shop_cust_log($c['id'], 'stamp-', 'Stempel zurückgenommen ('.shop_cust_reason($reason).')', $by);
	shop_cust_index(true);
	return array('ok' => true);
}
function shop_cust_coupon_issue($id, $value, $days, $reason, $by, $notify = false) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$value = (int)$value; $days = (int)$days;
	if ($value < 100 || $value > 10000) { return array('ok' => false, 'error' => 'Der Wert liegt zwischen 1,00 und 100,00 Euro.'); }
	if ($days < 1 || $days > 365) { return array('ok' => false, 'error' => 'Die Gültigkeit liegt zwischen 1 und 365 Tagen.'); }
	$keys = shop_cust_keys($c); if (!shop_stamp_has_keys($keys)) { return array('ok' => false, 'error' => 'Für diesen Kunden gibt es keine Nummer oder Mailadresse, an die ein Gutschein gehören könnte.'); }
	$cp = shop_stamp_issue($keys, $value, date('Y-m-d H:i:s', time() + $days * 86400), 0, 'Kulanz');
	if (!$cp) { return array('ok' => false, 'error' => 'Der Gutschein konnte nicht angelegt werden.'); }
	$ct = shop_cust_contact($c); $told = false;
	if ($notify && ($ct['mail'] !== '' || $ct['sms'])) { shop_stamp_notify_voucher($c['phone'], $ct['mail'], $value, strtotime($cp['valid_until']), $c['name'], $cp['code']); $told = true; }
	shop_cust_log($c['id'], 'coupon+', 'Gutschein '.$cp['code'].' über '.shop_money($value).', '.$days.' Tage ('.shop_cust_reason($reason).')'.($told ? '; Gast benachrichtigt' : '; Gast nicht benachrichtigt'), $by);
	shop_cust_index(true);
	return array('ok' => true, 'code' => $cp['code'], 'notified' => $told);
}
function shop_cust_coupon_toggle($id, $couponId, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$v = fb_row("SELECT id, code, active FROM ".fb_t('tp_shop_coupons')." WHERE id = ? AND source = 'stamp' AND ".shop_cust_match($c['keys']), 'i', array((int)$couponId));
	if (!$v) { return array('ok' => false, 'error' => 'Diesen Gutschein gibt es nicht.'); }
	$new = empty($v['active']) ? 1 : 0;
	fb_exec("UPDATE ".fb_t('tp_shop_coupons')." SET active = ? WHERE id = ?", 'ii', array($new, (int)$v['id']));
	shop_cust_log($c['id'], $new ? 'coupon on' : 'coupon off', 'Gutschein '.$v['code'].($new ? ' wieder freigegeben' : ' gesperrt'), $by);
	shop_cust_index(true);
	return array('ok' => true);
}
function shop_cust_account_block($id, $accountId, $on, $by) {
	$c = shop_cust_find($id); if (!$c || !in_array((int)$accountId, $c['accounts'], true)) { return array('ok' => false, 'error' => 'Dieses Konto gehört nicht zu diesem Kunden.'); }
	fb_exec("UPDATE ".fb_t('tp_shop_accounts')." SET blocked = ? WHERE id = ?", 'ii', array($on ? 1 : 0, (int)$accountId));
	if ($on) { fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE account_id = ?", 'i', array((int)$accountId)); }
	shop_cust_log($c['id'], $on ? 'block' : 'unblock', 'Konto '.(int)$accountId.($on ? ' gesperrt' : ' entsperrt'), $by);
	shop_cust_index(true);
	return array('ok' => true);
}
function shop_cust_account_reset($id, $accountId, $by) {
	$c = shop_cust_find($id); if (!$c || !in_array((int)$accountId, $c['accounts'], true)) { return array('ok' => false, 'error' => 'Dieses Konto gehört nicht zu diesem Kunden.'); }
	$a = fb_row("SELECT key_phone, key_mail FROM ".fb_t('tp_shop_accounts')." WHERE id = ?", 'i', array((int)$accountId));
	fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE account_id = ?", 'i', array((int)$accountId));
	if ($a) { fb_exec("DELETE FROM ".fb_t('tp_shop_login_codes')." WHERE account_id = ? OR target_key IN (?, ?)", 'iss', array((int)$accountId, (string)$a['key_phone'], (string)$a['key_mail'])); }
	shop_cust_log($c['id'], 'reset', 'Anmeldung von Konto '.(int)$accountId.' zurückgesetzt (überall abgemeldet)', $by);
	return array('ok' => true);
}
function shop_cust_link($id, $otherId, $by) {
	$a = shop_cust_find($id); $b = shop_cust_find($otherId);
	if (!$a || !$b || $a['id'] === $b['id']) { return array('ok' => false, 'error' => 'Diese beiden Kunden lassen sich nicht zusammenführen.'); }
	fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_customer_links')." (a, b, created_at, created_by) VALUES (?, ?, ?, ?)", 'ssss', array($a['id'], $b['id'], date('Y-m-d H:i:s'), mb_substr((string)$by, 0, 60)));
	shop_cust_log($a['id'], 'link', 'Zusammengeführt mit '.($b['name'] !== '' ? $b['name'] : 'Kunde').' ('.$b['phone'].')', $by);
	$idx = shop_cust_index(true);
	$new = null; foreach ($idx as $c) { if (in_array($a['id'], $c['keys'], true)) { $new = $c['id']; break; } }
	return array('ok' => true, 'id' => $new ?: $a['id']);
}
function shop_cust_unlink($id, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	fb_exec("DELETE FROM ".fb_t('tp_shop_customer_links')." WHERE a IN (".shop_cust_in($c['keys']).") OR b IN (".shop_cust_in($c['keys']).")");
	shop_cust_log($c['id'], 'unlink', 'Zusammenführung aufgehoben', $by);
	shop_cust_index(true);
	return array('ok' => true);
}

// deletes the person, keeps the numbers: orders stay for the books without name, contact, address and note; account, stamps, vouchers, favorites, note, links and log go
function shop_cust_erase($id, $confirm, $by) {
	$c = shop_cust_find($id); if (!$c) { return array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht mehr.'); }
	$want = trim((string)$c['name']) !== '' ? mb_strtolower(trim($c['name'])) : $c['digits'];
	$got = mb_strtolower(trim((string)$confirm));
	if ($want === '' || ($got !== $want && shop_cust_digits($got) !== $c['digits'])) { return array('ok' => false, 'error' => 'Zur Bestätigung bitte den Namen oder die Telefonnummer des Kunden eintippen.'); }
	$in = shop_cust_in($c['keys']); $accIds = $c['accounts'] ? implode(',', array_map('intval', $c['accounts'])) : '0';
	$byAcc = $c['accounts'] ? " OR account_id IN ($accIds)" : ''; // never 'IN (0)': orders without an account are not this customer's
	$n = (int)fb_row("SELECT COUNT(*) n FROM ".fb_t('tp_shop_orders')." WHERE guest_key IN ($in) OR guest_key2 IN ($in)$byAcc")['n'];
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET customer_name = 'Gelöscht', phone = '', email = '', street = '', zip = '', city = '', address_note = '', note = '', lat = 0, lng = 0, guest_key = '', guest_key2 = '', account_id = NULL, ip_hash = ''
		WHERE guest_key IN ($in) OR guest_key2 IN ($in)$byAcc");
	fb_exec("UPDATE ".fb_t('tp_shop_coupon_uses')." SET guest_key = '', guest_key2 = '' WHERE guest_key IN ($in) OR guest_key2 IN ($in)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_stamps')." WHERE guest_key IN ($in) OR guest_key2 IN ($in)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_coupons')." WHERE source = 'stamp' AND (guest_key IN ($in) OR guest_key2 IN ($in))");
	fb_exec("DELETE FROM ".fb_t('tp_shop_favorites')." WHERE account_id IN ($accIds)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_sessions')." WHERE account_id IN ($accIds)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_login_codes')." WHERE account_id IN ($accIds) OR target_key IN ($in)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_accounts')." WHERE id IN ($accIds)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_customer_notes')." WHERE ckey IN ($in)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_customer_links')." WHERE a IN ($in) OR b IN ($in)");
	fb_exec("DELETE FROM ".fb_t('tp_shop_customer_log')." WHERE ckey IN ($in)");
	shop_cust_log('', 'erase', 'Kunde gelöscht, '.$n.' Bestellungen ohne Personenbezug behalten', $by);
	shop_cust_index(true);
	return array('ok' => true, 'orders' => $n);
}

// the customer of a phone number (for the till: the note, the marks, the card of the caller), or null
function shop_cust_by_phone($phone) {
	$k = shop_coupon_guest_keys($phone, '');
	if ($k[0] === '') { return null; }
	foreach (shop_cust_index() as $c) { if (in_array($k[0], $c['keys'], true)) { return $c; } }
	return null;
}
