<?php
/*
 * Live preview of the voucher in the shop (the picture of the card with sample number and code). Answers a JPEG. GET: amount (euro), motif, recipient, from, message.
 * Drawing costs some computing time, so one visitor gets at most 150 pictures in ten minutes. A picture of a guest is only shown to the session that uploaded it.
 */
require __DIR__.'/bootstrap.inc.php';
$cfg = gv_cfg();
$now = time();
$_SESSION['gv_prev'] = array_values(array_filter(isset($_SESSION['gv_prev']) ? (array)$_SESSION['gv_prev'] : array(), function ($t) use ($now) { return $t > $now - 600; }));
if (count($_SESSION['gv_prev']) >= 150) { http_response_code(429); exit; }
$_SESSION['gv_prev'][] = $now;
session_write_close();

$motif = isset($_GET['motif']) ? (string)$_GET['motif'] : 'kuchen';
if (strpos($motif, 'own:') === 0) {
	$own = isset($_SESSION['gv_own']) ? (array)$_SESSION['gv_own'] : array();
	if (!$cfg['own'] || !in_array(substr($motif, 4), $own, true) || gv_motif_file($motif) === '') { $motif = 'kuchen'; }
} elseif (!isset(gv_motifs_active()[$motif])) { $motif = 'kuchen'; }
$amount = gv_parse_amount(isset($_GET['amount']) ? $_GET['amount'] : '');
if ($amount === null || $amount < 100 || $amount > $cfg['max']) { $amount = 5000; }
$clean = function ($k, $len) { return mb_substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', isset($_GET[$k]) ? (string)$_GET[$k] : '')), 0, $len); };
$o = array('motif' => $motif, 'amount_cents' => $amount, 'recipient' => $clean('recipient', 80), 'from' => $clean('from', 80), 'message' => $clean('message', 160), 'number' => 'AMD-XXXX-XXXX', 'code' => 'XXXXXX',
	'valid_until' => gv_default_valid_until(), 'brand' => !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus');
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=60');
echo gv_card_jpeg($o, 1000, 84);
