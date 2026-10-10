<?php
/*
 * JSON interface of the order chat (order/chat.php): "open" returns the conversation of this visitor (a new one on the first call), "send" takes a pressed button or typed text and
 * returns the new messages of the bot. The conversation itself lives on the server (web/classes/shop_chat.class.php); the browser only holds the session cookie.
 */
require __DIR__.'/bootstrap.inc.php';
require_once __DIR__.'/../web/classes/shop_chat.class.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function chat_out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

if (!$shop_public || !(shop_chat_on() || $shop_staff)) { chat_out(array('ok' => false, 'error' => 'Der Chat ist noch nicht freigegeben.'), 404); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { chat_out(array('ok' => false, 'error' => 'Unbekannte Anfrage.'), 405); }
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) { $body = array(); }
if (!shop_token_ok(isset($body['token']) ? $body['token'] : '')) { chat_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'), 403); }
$op = isset($body['op']) ? (string)$body['op'] : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

$c = shop_chat_open(isset($_SESSION['shop_chat_token']) ? (string)$_SESSION['shop_chat_token'] : '', $ip);
if (!$c) { chat_out(array('ok' => false, 'error' => 'Gerade sind zu viele Gespräche offen. Bitte versuche es später noch einmal oder ruf uns an.')); }
$_SESSION['shop_chat_token'] = $c['token'];

if ($op === 'open') { chat_out(array('ok' => true, 'messages' => shop_chat_transcript($c), 'cart' => shop_chat_cart_info($c))); }
if ($op === 'reset') {
	unset($_SESSION['shop_chat_token']);
	$c = shop_chat_open('', $ip);
	if (!$c) { chat_out(array('ok' => false, 'error' => 'Gerade sind zu viele Gespräche offen. Bitte versuche es später noch einmal oder ruf uns an.')); }
	$_SESSION['shop_chat_token'] = $c['token'];
	chat_out(array('ok' => true, 'messages' => shop_chat_transcript($c), 'cart' => shop_chat_cart_info($c)));
}
if ($op === 'import') { chat_out(array('ok' => true, 'taken' => shop_chat_import($c, isset($body['lines']) ? $body['lines'] : array(), isset($body['mode']) ? (string)$body['mode'] : ''))); }
if ($op === 'export') { chat_out(array_merge(array('ok' => true), shop_chat_export($c))); }
if ($op === 'send') {
	$ev = array();
	if (isset($body['btn']) && is_string($body['btn'])) { $ev['btn'] = mb_substr($body['btn'], 0, 60); }
	elseif (isset($body['text']) && is_string($body['text'])) { $ev['text'] = mb_substr($body['text'], 0, 300); }
	else { chat_out(array('ok' => false, 'error' => 'Leere Eingabe.')); }
	list($c, $msgs) = shop_chat_handle($c, $ev);
	foreach ($msgs as $i => $m) { $msgs[$i]['role'] = 'bot'; }
	chat_out(array('ok' => true, 'messages' => $msgs, 'cart' => shop_chat_cart_info($c), 'ordered' => $c['state'] === 'done'));
}
chat_out(array('ok' => false, 'error' => 'Unbekannte Anfrage.'), 400);
