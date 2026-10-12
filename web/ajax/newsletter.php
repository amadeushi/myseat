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
if ($op === 'mail_list') {
	shop_nlm_tick();
	$out = array();
	foreach (shop_nlm_list() as $c) { $out[] = array('id' => (int)$c['id'], 'name' => $c['name'], 'subject' => $c['subject'], 'status' => $c['status'], 'stats' => $c['stats'], 'created' => date('d.m.Y', strtotime($c['created_at'])), 'started' => $c['started_at'] ? date('d.m.Y H:i', strtotime($c['started_at'])) : '', 'finished' => $c['finished_at'] ? date('d.m.Y H:i', strtotime($c['finished_at'])) : ''); }
	nl_out(array('ok' => true, 'mails' => $out, 'recipients' => shop_nlm_recipients_count(), 'from' => shop_mail_from()));
}
if ($op === 'auto_list') {
	shop_nlm_tick();
	nl_out(array('ok' => true, 'autos' => shop_nla_overview(), 'from' => shop_mail_from(), 'cap' => SHOP_NLA_CAP_DAYS));
}
if ($op === 'mail_get') {
	shop_nlm_tick();
	$c = shop_nlm_get((int)(isset($_GET['id']) ? $_GET['id'] : 0));
	if (!$c) { nl_out(array('ok' => false, 'error' => 'Das Mailing gibt es nicht mehr.')); }
	nl_out(array('ok' => true, 'mail' => array('id' => (int)$c['id'], 'name' => $c['name'], 'subject' => $c['subject'], 'preheader' => $c['preheader'], 'status' => $c['status'], 'kind' => $c['kind'], 'vars' => ($c['kind'] !== '' && isset(shop_nla_kinds()[$c['kind']])) ? shop_nla_kinds()[$c['kind']]['vars'] : array(), 'blocks' => shop_nlm_clean_blocks($c['blocks']), 'stats' => shop_nlm_stats($c['id']), 'problems' => shop_nlm_problems($c)), 'recipients' => shop_nlm_recipients_count(), 'base' => shop_stamp_shop_url()));
}
if ($op === 'proof') {
	$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
	$out = array();
	foreach (shop_nl_proof($id) as $p) { $out[] = array('event' => $p['event'], 'topics' => $p['topics'], 'source' => $p['source'], 'ip' => $p['ip'], 'ua' => $p['ua'], 'detail' => $p['detail'], 'at' => date('d.m.Y H:i:s', strtotime($p['at']))); }
	nl_out(array('ok' => true, 'proof' => $out));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || !hash_equals((string)$_SESSION['shop_admin_token'], (string)$_POST['token'])) { nl_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.')); }
if ($op === 'auto_save') {
	$p = json_decode(isset($_POST['params']) ? (string)$_POST['params'] : '{}', true);
	nl_out(shop_nla_cfg_set(isset($_POST['kind']) ? (string)$_POST['kind'] : '', !empty($_POST['on']), is_array($p) ? $p : array()) ? array('ok' => true, 'autos' => shop_nla_overview()) : array('ok' => false, 'error' => 'Unbekannte Automatik.'));
}
if (strpos($op, 'mail_') === 0) {
	$id = (int)(isset($_POST['id']) ? $_POST['id'] : 0);
	if ($op === 'mail_new') { nl_out(array('ok' => true, 'id' => shop_nlm_create(isset($_POST['name']) ? $_POST['name'] : '', (int)(isset($_POST['copy']) ? $_POST['copy'] : 0)))); }
	if ($op === 'mail_save') { $r = shop_nlm_save($id, array('name' => isset($_POST['name']) ? $_POST['name'] : '', 'subject' => isset($_POST['subject']) ? $_POST['subject'] : '', 'preheader' => isset($_POST['preheader']) ? $_POST['preheader'] : '', 'blocks' => isset($_POST['blocks']) ? $_POST['blocks'] : '[]')); if ($r['ok']) { $r['problems'] = shop_nlm_problems(shop_nlm_get($id)); } nl_out($r); }
	if ($op === 'mail_preview') {
		$m = shop_nlm_render(array('subject' => isset($_POST['subject']) ? shop_nlm_str($_POST['subject'], 200) : '', 'preheader' => isset($_POST['preheader']) ? shop_nlm_str($_POST['preheader'], 200) : '', 'blocks' => isset($_POST['blocks']) ? $_POST['blocks'] : '[]'), array('email' => 'max@example.com', 'name' => 'Max Mustermann', 'token' => '', 'vars' => shop_nla_sample_vars()));
		nl_out(array('ok' => true, 'html' => $m['html'], 'subject' => $m['subject']));
	}
	if ($op === 'mail_test') { nl_out(shop_nlm_send_test($id, isset($_POST['to']) ? $_POST['to'] : '')); }
	if ($op === 'mail_start') { nl_out(shop_nlm_start($id)); }
	if ($op === 'mail_cancel') { nl_out(array('ok' => shop_nlm_cancel($id))); }
	if ($op === 'mail_delete') { nl_out(shop_nlm_delete($id) ? array('ok' => true) : array('ok' => false, 'error' => 'Ein laufendes Mailing kann nicht gelöscht werden. Brich es zuerst ab.')); }
	if ($op === 'mail_upload') {
		$f = isset($_FILES['image']) ? $_FILES['image'] : null;
		if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { nl_out(array('ok' => false, 'error' => 'Das Bild konnte nicht hochgeladen werden.')); }
		if ($f['size'] > 1500000) { nl_out(array('ok' => false, 'error' => 'Das Bild ist größer als 1,5 MB. Bitte verkleinere es, große Bilder laden in Mails langsam.')); }
		$i = @getimagesize($f['tmp_name']); $ext = array(IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp');
		if (!$i || !isset($ext[$i[2]])) { nl_out(array('ok' => false, 'error' => 'Erlaubt sind JPG, PNG, GIF und WebP.')); }
		$dir = __DIR__.'/../../order/mail/nl'; if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		if (!is_dir($dir) || !is_writable($dir)) { nl_out(array('ok' => false, 'error' => 'Der Ordner für Bilder ist nicht beschreibbar.')); }
		$name = bin2hex(random_bytes(8)).'.'.$ext[$i[2]];
		if (!move_uploaded_file($f['tmp_name'], $dir.'/'.$name)) { nl_out(array('ok' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.')); }
		nl_out(array('ok' => true, 'url' => shop_stamp_shop_url().'mail/nl/'.$name, 'w' => $i[0], 'h' => $i[1]));
	}
	nl_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
}
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
