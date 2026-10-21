<?php
/*
 * A guest uploads an own picture for the voucher (POST file + token). The picture is checked and stored re-encoded without metadata (gv_upload_store); the key is remembered in the session,
 * only this session may use it for the preview and the order. At most 6 pictures per session. Answers JSON.
 */
require __DIR__.'/bootstrap.inc.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function gvu_out($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
$cfg = gv_cfg();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !shop_token_ok(isset($_POST['token']) ? $_POST['token'] : '')) { gvu_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.')); }
if (!$cfg['own']) { gvu_out(array('ok' => false, 'error' => 'Eigene Bilder sind gerade nicht möglich.')); }
$own = isset($_SESSION['gv_own']) ? (array)$_SESSION['gv_own'] : array();
if (count($own) >= 6) { gvu_out(array('ok' => false, 'error' => 'Du hast schon mehrere Bilder hochgeladen. Bitte wähle eines davon oder lade die Seite neu.')); }
$f = isset($_FILES['file']) ? $_FILES['file'] : null;
if (!is_array($f) || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
	$big = is_array($f) && in_array($f['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true);
	gvu_out(array('ok' => false, 'error' => $big ? 'Das Bild ist größer, als der Server erlaubt.' : 'Der Upload hat nicht geklappt. Bitte versuche es noch einmal.'));
}
$r = gv_upload_store((string)file_get_contents($f['tmp_name']));
if (!$r['ok']) { gvu_out($r); }
$own[] = $r['key']; $_SESSION['gv_own'] = $own;
if (mt_rand(1, 20) === 1) { gv_upload_gc(); }
gvu_out(array('ok' => true, 'motif' => 'own:'.$r['key']));
