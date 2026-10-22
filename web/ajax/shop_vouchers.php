<?php
/*
 * Gift vouchers of the restaurant (backend page p=15): look up, redeem with the PIN of the employee, issue blanks, block, correct, import the old list, set PINs.
 * Answers JSON (the export answers a CSV file). Needs a login with Settings-General and the token of the page (own key in the session, shop_admin_token).
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

header('Cache-Control: no-store');
function gva_out($data) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	gva_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';
// the person at the keyboard (for the log of changes that need no PIN)
$me = array(isset($_SESSION['u_id']) ? (int)$_SESSION['u_id'] : null, isset($_SESSION['u_name']) ? (string)$_SESSION['u_name'] : '');

function gva_voucher_view($v) {
	if (!$v) { return null; }
	$o = array('id' => (int)$v['id'], 'number' => $v['number'], 'kind' => $v['kind'], 'face' => (int)$v['face_cents'], 'balance' => (int)$v['balance_cents'], 'state' => gv_state($v),
		'valid_until' => $v['valid_until'] ? date('d.m.Y', strtotime($v['valid_until'])) : '', 'valid_iso' => (string)$v['valid_until'], 'buyer_name' => $v['buyer_name'], 'buyer_email' => $v['buyer_email'],
		'recipient' => $v['recipient'], 'note' => $v['note'], 'created' => date('d.m.Y H:i', strtotime($v['created_at'])), 'has_code' => !empty($v['code_hash']), 'blocked' => gv_block_reason($v));
	return $o;
}
function gva_moves_view($id) {
	$o = array();
	foreach (gv_moves($id) as $m) { $o[] = array('kind' => $m['kind'], 'amount' => (int)$m['amount_cents'], 'balance' => (int)$m['balance_after'], 'who' => $m['staff_name'], 'ref' => $m['ref'], 'at' => date('d.m.Y H:i', strtotime($m['at']))); }
	return $o;
}

if ($op === 'load') {
	$state = isset($_GET['state']) && in_array($_GET['state'], array('all', 'open', 'used', 'expired', 'void', 'pending'), true) ? $_GET['state'] : 'all';
	$rows = array();
	foreach (gv_list($state, isset($_GET['q']) ? (string)$_GET['q'] : '', 300) as $r) { $rows[] = gva_voucher_view($r); }
	gva_out(array('ok' => true, 'counts' => gv_counts(), 'stats' => gv_stats(), 'rows' => $rows, 'staff' => gv_staff_list(), 'on' => shop_flag('gv_on'), 'me' => $me[0]));
}
if ($op === 'lookup') {
	$v = gv_find(isset($_GET['number']) ? (string)$_GET['number'] : '');
	if (!$v) { gva_out(array('ok' => false, 'error' => 'Diese Gutscheinnummer gibt es nicht.')); }
	gva_out(array('ok' => true, 'voucher' => gva_voucher_view($v), 'moves' => gva_moves_view($v['id'])));
}
if ($op === 'moves') {
	gva_out(array('ok' => true, 'moves' => gva_moves_view((int)(isset($_GET['id']) ? $_GET['id'] : 0))));
}
if ($op === 'export') {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="gutscheine-'.date('Y-m-d').'.csv"');
	echo gv_export_csv();
	exit;
}

if ($op === 'order_print') {   // the print file of a card (post / pick-up): personal motif and words, number and code of the voucher
	$o = gv_order_by_id((int)(isset($_GET['id']) ? $_GET['id'] : 0)); $v = $o ? gv_by_id((int)$o['voucher_id']) : null;
	if (!$o || !$v || $o['status'] !== 'paid' || $o['delivery'] === 'pdf' || $v['kind'] === 'print' || gv_code_plain($v) === null) { http_response_code(404); echo 'Für diesen Auftrag gibt es keine Druckdatei.'; exit; }
	header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Gutschein-Karte-'.$o['number'].'.pdf"'); header('Cache-Control: private, no-store');
	echo gv_print_pdf(gv_order_pdf_opts($o, $v));
	exit;
}
if ($op === 'orders') {
	$f = isset($_GET['filter']) && in_array($_GET['filter'], array('todo', 'refund', 'paid', 'withdrawn', 'pending', 'all'), true) ? $_GET['filter'] : 'todo';
	$counts = array(); foreach (array('todo', 'refund', 'paid', 'withdrawn', 'pending', 'all') as $k) { $counts[$k] = count(gv_orders_list($k, 2000)); }
	$rows = array();
	foreach (gv_orders_list($f, 300) as $o) {
		$o = gv_post_sync($o);   // a card EchtPost has sent meanwhile (at most every ten minutes per order)
		$v = gv_by_id((int)$o['voucher_id']);
		$todo = $o['status'] === 'paid' && $o['delivery'] !== 'pdf' && $o['fulfilled_at'] === null && $o['ep_state'] !== 'queued';
		$rows[] = array('id' => (int)$o['id'], 'number' => $o['number'], 'status' => $o['status'], 'delivery' => $o['delivery'], 'amount' => (int)$o['amount_cents'], 'fee' => (int)$o['fee_cents'], 'total' => (int)$o['total_cents'],
			'buyer' => $o['buyer_name'], 'email' => $o['buyer_email'], 'phone' => $o['buyer_phone'], 'recipient' => $o['recipient'], 'from' => $o['from_name'], 'message' => $o['message'], 'motif' => $o['motif'],
			'ship' => $o['delivery'] === 'post' ? $o['ship_name'].', '.$o['ship_street'].', '.$o['ship_zip'].' '.$o['ship_city'] : '', 'created' => date('d.m.Y H:i', strtotime($o['created_at'])),
			'paid_at' => $o['paid_at'] ? date('d.m.Y H:i', strtotime($o['paid_at'])) : '', 'fulfilled' => $o['fulfilled_at'] !== null, 'withdrawn_at' => $o['withdrawn_at'] ? date('d.m.Y H:i', strtotime($o['withdrawn_at'])) : '',
			'refunded' => !empty($o['refund_id']), 'lapse' => (int)$o['consent_lapse'] === 1, 'voucher' => $v ? $v['number'] : '', 'code' => $todo && $v ? (string)gv_code_plain($v) : '', 'mail' => $o['mail_at'] !== null,
			'own' => $v && $v['kind'] !== 'print', 'todo' => $todo, 'ep' => (string)$o['ep_state'], 'ep_error' => (string)$o['ep_error']);
	}
	gva_out(array('ok' => true, 'rows' => $rows, 'counts' => $counts, 'filter' => $f));
}
if ($op === 'settings') {
	$keys = array('gv_on', 'gv_pay_on', 'gv_terms_ok', 'gv_pdf_on', 'gv_post_on', 'gv_pickup_on', 'gv_own_motif', 'gv_amounts', 'gv_min_eur', 'gv_max_eur', 'gv_post_fee', 'gv_withdraw_mode', 'gv_motifs_off', 'gv_post_mode', 'gv_site_host');
	$s = array(); foreach ($keys as $k) { $s[$k] = (string)shop_setting($k); }
	$m = array(); foreach (gv_motifs() as $k => $x) { $m[] = array('key' => $k, 'label' => $x['label'], 'group' => $x['group']); }
	gva_out(array('ok' => true, 's' => $s, 'ep' => ep_info(), 'ep_hook' => rtrim(shop_site_url(), '/').'/order/echtpost_webhook.php', 'motifs' => $m, 'shop_url' => rtrim(shop_site_url(), '/').'/order/gutschein.php'));
}

// everything below changes something: POST with the token
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$_POST['token'])) {
	gva_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
$p = function ($k) { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; };
$id = (int)$p('id');
$cents = function ($k) use ($p) { return gv_parse_amount($p($k)); };

switch ($op) {
	case 'redeem':
		$amount = $p('amount') === '' ? null : $cents('amount');
		if ($p('amount') !== '' && $amount === null) { gva_out(array('ok' => false, 'error' => 'Der Betrag ist keine Zahl, zum Beispiel 12,50.')); }
		$r = gv_redeem_staff((int)$p('staff'), $p('pin'), $p('number'), $amount, $p('ref'));
		if (!empty($r['voucher'])) { $r['voucher'] = gva_voucher_view($r['voucher']); $r['moves'] = gva_moves_view($r['voucher']['id']); }
		gva_out($r);

	case 'issue':
		$face = $cents('face');
		if ($face === null) { gva_out(array('ok' => false, 'error' => 'Der Wert ist keine Zahl, zum Beispiel 50.')); }
		$valid = $p('valid') === '' ? null : gv_import_date($p('valid'));
		if ($p('valid') !== '' && $valid === null) { gva_out(array('ok' => false, 'error' => 'Das Datum ist nicht lesbar, bitte TT.MM.JJJJ.')); }
		$r = gv_issue(array('kind' => 'print', 'number' => $p('number'), 'face_cents' => $face, 'valid_until' => $valid, 'buyer_name' => $p('buyer_name'), 'buyer_email' => $p('buyer_email'),
			'recipient' => $p('recipient'), 'note' => $p('note'), 'staff_id' => $me[0], 'staff_name' => $me[1]));
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);

	case 'void':
		$r = gv_void($id, $me, $p('reason') !== '' ? $p('reason') : 'Gesperrt');
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);
	case 'unvoid':
		$r = gv_unvoid($id, $me, $p('reason') !== '' ? $p('reason') : 'Entsperrt');
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);
	case 'adjust':
		$bal = $cents('balance');
		if ($bal === null) { gva_out(array('ok' => false, 'error' => 'Das Guthaben ist keine Zahl, zum Beispiel 12,50.')); }
		$r = gv_adjust($id, $bal, $me, $p('reason'));
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);
	case 'valid':
		$d = gv_import_date($p('valid'));
		if ($d === null || $d === '') { gva_out(array('ok' => false, 'error' => 'Das Datum ist nicht lesbar, bitte TT.MM.JJJJ.')); }
		$r = gv_set_valid($id, $d, $me, $p('reason'));
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);
	case 'refund':
		$amt = $cents('amount');
		if ($amt === null) { gva_out(array('ok' => false, 'error' => 'Der Betrag ist keine Zahl, zum Beispiel 12,50.')); }
		$r = gv_refund($id, $amt, $me, $p('reason') !== '' ? $p('reason') : 'Gutschrift');
		if ($r['ok']) { $r['voucher'] = gva_voucher_view($r['voucher']); }
		gva_out($r);

	case 'import_check':
	case 'import_run':
		$valid = $p('valid') === '' ? gv_default_valid_until() : gv_import_date($p('valid'));
		if ($valid === null) { gva_out(array('ok' => false, 'error' => 'Das Datum ist nicht lesbar, bitte TT.MM.JJJJ.')); }
		$text = isset($_POST['text']) ? (string)$_POST['text'] : '';
		if (strlen($text) > 500000) { gva_out(array('ok' => false, 'error' => 'Die Liste ist zu groß.')); }
		if ($op === 'import_run') { gva_out(gv_import_run($text, $valid, $me)); }
		$rows = gv_import_parse($text, $valid);
		$sum = array('new' => 0, 'dup_file' => 0, 'dup_db' => 0, 'error' => 0, 'cents' => 0);
		foreach ($rows as $r) { $sum[$r['status']]++; if ($r['status'] === 'new') { $sum['cents'] += $r['balance']; } }
		gva_out(array('ok' => true, 'sum' => $sum, 'rows' => array_slice($rows, 0, 60), 'total' => count($rows)));

	case 'pin_set':
		$uid = (int)$p('user');
		if ($uid < 1 || gv_staff_name($uid) === '') { gva_out(array('ok' => false, 'error' => 'Diese Person gibt es nicht.')); }
		gva_out(gv_pin_set($uid, $p('pin')) + array('staff' => gv_staff_list()));
	case 'pin_remove':
		gv_pin_remove((int)$p('user'));
		gva_out(array('ok' => true, 'staff' => gv_staff_list()));

	case 'setting':
		if ($p('key') === 'gv_on') { shop_setting_set('gv_on', $p('value') === '1' ? '1' : '0'); gva_out(array('ok' => true)); }
		gva_out(array('ok' => false, 'error' => 'Unbekannte Einstellung.'));

	case 'order_fulfil':
		gva_out(gv_order_fulfil($id, $p('number'), $me));
	case 'order_refund':
		gva_out(gv_order_refund($id));
	case 'order_resend':   // the postcard again (after an error; "unclear" only with the confirmation of the owner)
		gva_out(gv_post_send($id, $p('again') === '1'));
	case 'ep_save':
		$key = $p('key');
		if (!preg_match('/^[A-Za-z0-9_.\-]{16,200}$/', $key)) { gva_out(array('ok' => false, 'error' => 'Der Schlüssel sieht nicht richtig aus.')); }
		$enc = sms_encrypt($key);
		if ($enc === null) { gva_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		shop_setting_set('gv_ep_key', $enc);
		gva_out(array('ok' => true, 'ep' => ep_info()));
	case 'ep_clear':
		shop_setting_set('gv_ep_key', null); if (shop_setting('gv_post_mode') === 'echtpost') { shop_setting_set('gv_post_mode', 'print'); }
		gva_out(array('ok' => true, 'ep' => ep_info()));
	case 'ep_test':
		gva_out(ep_test());

	case 'settings_save':
		$d = json_decode(isset($_POST['data']) ? (string)$_POST['data'] : '', true);
		if (!is_array($d)) { gva_out(array('ok' => false, 'error' => 'Die Einstellungen konnten nicht gelesen werden.')); }
		$flag = function ($k) use ($d) { return !empty($d[$k]) ? '1' : '0'; };
		$eur = function ($k, $dflt) use ($d) { $v = str_replace(',', '.', trim(isset($d[$k]) ? (string)$d[$k] : '')); return is_numeric($v) && (float)$v >= 0 ? number_format((float)$v, 2, '.', '') : $dflt; };
		$min = (float)$eur('gv_min_eur', '10'); $max = (float)$eur('gv_max_eur', '500');
		if ($min < 1 || $max < $min || $max * 100 > GV_MAX_CENTS) { gva_out(array('ok' => false, 'error' => 'Die Grenzen für einen freien Betrag passen nicht: mindestens 1 €, höchstens '.gv_money(GV_MAX_CENTS).', und der kleinste Betrag darf nicht über dem größten liegen.')); }
		$amounts = array();
		foreach (explode(',', isset($d['gv_amounts']) ? (string)$d['gv_amounts'] : '') as $a) { $c = trim(str_replace(',', '.', $a)); if ($c !== '' && is_numeric($c) && (float)$c >= 1 && (float)$c <= $max) { $amounts[] = rtrim(rtrim(number_format((float)$c, 2, '.', ''), '0'), '.'); } }
		if (!$amounts) { gva_out(array('ok' => false, 'error' => 'Bitte mindestens einen Betrag zur Auswahl angeben, getrennt durch Komma, zum Beispiel 25, 50, 100.')); }
		$valid = array_keys(gv_motifs()); $off = array();
		foreach (explode(',', isset($d['gv_motifs_off']) ? (string)$d['gv_motifs_off'] : '') as $k) { if (in_array($k, $valid, true)) { $off[] = $k; } }
		if (count($off) >= count($valid)) { gva_out(array('ok' => false, 'error' => 'Mindestens ein Motiv muss eingeschaltet bleiben.')); }
		$site = preg_replace('~^https?://~i', '', trim(isset($d['gv_site_host']) ? (string)$d['gv_site_host'] : '')); $site = rtrim($site, '/');
		if ($site !== '' && !preg_match('~^[A-Za-z0-9][A-Za-z0-9.\-/]{2,59}$~', $site)) { gva_out(array('ok' => false, 'error' => 'Die Website sieht nicht richtig aus. Bitte nur die Adresse eintragen, zum Beispiel amadeus-hildesheim.de.')); }
		$mode = isset($d['gv_withdraw_mode']) && $d['gv_withdraw_mode'] === 'lapse' ? 'lapse' : 'days14';
		shop_setting_set('gv_on', $flag('gv_on')); shop_setting_set('gv_pay_on', $flag('gv_pay_on')); shop_setting_set('gv_terms_ok', $flag('gv_terms_ok'));
		shop_setting_set('gv_pdf_on', $flag('gv_pdf_on')); shop_setting_set('gv_post_on', $flag('gv_post_on')); shop_setting_set('gv_pickup_on', $flag('gv_pickup_on')); shop_setting_set('gv_own_motif', $flag('gv_own_motif'));
		shop_setting_set('gv_amounts', implode(',', $amounts)); shop_setting_set('gv_min_eur', $eur('gv_min_eur', '10')); shop_setting_set('gv_max_eur', $eur('gv_max_eur', '500')); shop_setting_set('gv_post_fee', $eur('gv_post_fee', '0.00'));
		shop_setting_set('gv_site_host', $site); shop_setting_set('gv_withdraw_mode', $mode); shop_setting_set('gv_motifs_off', implode(',', $off));
		shop_setting_set('gv_post_mode', isset($d['gv_post_mode']) && in_array($d['gv_post_mode'], array('echtpost', 'blank'), true) ? $d['gv_post_mode'] : 'print');
		gva_out(array('ok' => true));
}
gva_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
