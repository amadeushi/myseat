<?php
/*
 * Newsletter list of the backend (page p=14): counts, the list with search and filter, the proof of one subscriber, erasing one subscriber.
 * Answers JSON. Needs a login with Settings-General; everything that changes something is a POST with the admin token of the page.
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
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function nl_out($d) { echo json_encode($d); exit; }

require_once('../classes/session_restore.php'); myseat_restore_session();
if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) { http_response_code(403); nl_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.')); }
if (empty($_SESSION['shop_admin_token'])) { $_SESSION['shop_admin_token'] = myseat_admin_token(); }
shop_ensure_schema();
$op = isset($_REQUEST['op']) ? (string)$_REQUEST['op'] : '';

if ($op === 'list') {
	$filter = isset($_GET['filter']) && in_array($_GET['filter'], array('news', 'stamps', 'pending', 'unsub', 'blocked'), true) ? $_GET['filter'] : 'all';
	$rows = array();
	foreach (shop_nl_list($filter, isset($_GET['q']) ? (string)$_GET['q'] : '', 300) as $r) {
		$rows[] = array('id' => (int)$r['id'], 'email' => $r['email'], 'name' => $r['name'], 'news' => (int)$r['news'], 'stamps' => (int)$r['stamps'], 'want_news' => (int)$r['want_news'], 'want_stamps' => (int)$r['want_stamps'],
			'blocked' => (int)$r['blocked'], 'source' => $r['source'], 'confirmed' => $r['confirmed_at'] ? date('d.m.Y H:i', strtotime($r['confirmed_at'])) : '', 'unsub' => $r['unsub_at'] ? date('d.m.Y', strtotime($r['unsub_at'])) : '', 'created' => date('d.m.Y', strtotime($r['created_at'])));
	}
	// addresses of reservations whose old tick was never confirmed: they are not on the list and get nothing until the guest confirms
	global $dbTables;
	$old = fb_row("SELECT COUNT(DISTINCT LOWER(reservation_guest_email)) AS n FROM `".$dbTables->reservations."` WHERE reservation_advertise = 'YES' AND reservation_guest_email <> ''");
	$oldN = $old ? (int)$old['n'] : 0;
	$known = fb_row("SELECT COUNT(DISTINCT LOWER(r.reservation_guest_email)) AS n FROM `".$dbTables->reservations."` r JOIN ".fb_t('tp_shop_subscribers')." s ON s.email = LOWER(r.reservation_guest_email) WHERE r.reservation_advertise = 'YES'");
	nl_out(array('ok' => true, 'counts' => shop_nl_counts(), 'rows' => $rows, 'legacy' => max(0, $oldN - ($known ? (int)$known['n'] : 0)), 'batches' => array_map(function ($b) { return array('batch' => (int)$b['batch'], 'at' => date('d.m.Y H:i', strtotime($b['at'])), 'n' => (int)$b['n'], 'confirmed' => strpos($b['detail'], 'Inhaber bestätigt') !== false); }, shop_nl_import_batches())));
}
if ($op === 'proof') {
	$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
	$out = array();
	foreach (shop_nl_proof($id) as $p) { $out[] = array('event' => $p['event'], 'topics' => $p['topics'], 'source' => $p['source'], 'ip' => $p['ip'], 'ua' => $p['ua'], 'detail' => $p['detail'], 'at' => date('d.m.Y H:i:s', strtotime($p['at']))); }
	nl_out(array('ok' => true, 'proof' => $out));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || !hash_equals((string)$_SESSION['shop_admin_token'], (string)$_POST['token'])) { nl_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.')); }
if ($op === 'import_preview' || $op === 'import_run') {
	$text = isset($_POST['text']) ? (string)$_POST['text'] : '';
	if (trim($text) === '') { nl_out(array('ok' => false, 'error' => 'Die Liste ist leer.')); }
	$mode = (isset($_POST['mode']) && $_POST['mode'] === 'confirmed') ? 'confirmed' : 'blocked';
	$rows = shop_nl_import_parse($text);
	if (!$rows) { nl_out(array('ok' => false, 'error' => 'Ich finde in der Liste keine Zeilen. Erwartet wird eine Datei mit E-Mail und Name, je Zeile ein Gast.')); }
	if (count($rows) > SHOP_NL_IMPORT_MAX_ROWS) { nl_out(array('ok' => false, 'error' => 'Die Liste hat mehr als '.SHOP_NL_IMPORT_MAX_ROWS.' Zeilen. Bitte teile sie.')); }
	if ($op === 'import_preview') {
		$plan = shop_nl_import_plan($rows, $mode);
		$sample = array();
		foreach ($plan['rows'] as $r) { if ($r['kind'] !== 'new' || count(array_filter($sample, function ($x) { return $x['kind'] === 'new'; })) < 5) { $sample[] = $r; } if (count($sample) >= 60) { break; } }
		nl_out(array('ok' => true, 'counts' => $plan['counts'], 'sample' => $sample));
	}
	if ($mode === 'confirmed' && empty($_POST['attest'])) { nl_out(array('ok' => false, 'error' => 'Bitte bestätige die Einwilligung der Gäste mit dem Haken.')); }
	$res = shop_nl_import_run($rows, array('mode' => $mode, 'file' => isset($_POST['file']) ? $_POST['file'] : '', 'origin' => isset($_POST['origin']) ? $_POST['origin'] : '', 'user' => is_string($_SESSION['valid_user']) ? $_SESSION['valid_user'] : ''));
	nl_out($res);
}
if ($op === 'import_undo') {
	$n = shop_nl_import_undo((int)(isset($_POST['batch']) ? $_POST['batch'] : 0));
	nl_out(array('ok' => true, 'removed' => $n));
}
if ($op === 'erase') {
	$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
	if ($id <= 0) { nl_out(array('ok' => false, 'error' => 'Unbekannter Abonnent.')); }
	shop_nl_erase($id);
	nl_out(array('ok' => true));
}
nl_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
