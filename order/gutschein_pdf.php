<?php
/*
 * The voucher of a paid order as PDF (?t=<order token>) or as picture of the card (?t=...&fmt=jpg), for the status page and the download. Only for paid orders of the form "PDF"; the 32 character
 * token of the order is the secret (it is in the mail to the buyer). A withdrawn or unpaid order gets nothing.
 */
require __DIR__.'/bootstrap.inc.php';
$o = gv_order_by_token(isset($_GET['t']) ? (string)$_GET['t'] : '');
if (!$o || $o['delivery'] !== 'pdf' || $o['status'] !== 'paid') { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Nicht gefunden.'; exit; }
$v = gv_by_id((int)$o['voucher_id']);
$opts = gv_order_pdf_opts($o, $v);
session_write_close();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
if (isset($_GET['fmt']) && $_GET['fmt'] === 'jpg') { header('Content-Type: image/jpeg'); echo gv_card_jpeg($opts, 1100, 86); exit; }
$pdf = gv_pdf($opts);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Gutschein-'.preg_replace('/[^A-Za-z0-9\-]/', '', $v['number']).'.pdf"');
header('Content-Length: '.strlen($pdf));
echo $pdf;
