<?php
/*
 * The service worker (order/sw.js) asks here what to show when the shop knocked on the push address of a guest (see web/classes/shop_push.class.php): the answer is the pending
 * message for that address, a plain fallback otherwise. It holds nothing personal (a status text and the link of the status page). No session: a service worker has none.
 */
require __DIR__.'/bootstrap.inc.php';
require_once __DIR__.'/../web/classes/shop_push.class.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$raw = file_get_contents('php://input');
$in = json_decode((string)$raw, true);
$endpoint = is_array($in) && isset($in['endpoint']) ? (string)$in['endpoint'] : '';
echo json_encode(shop_push_pull($endpoint), JSON_UNESCAPED_UNICODE);
