<?php
/*
 * Endpoint of the print agent on the kitchen Raspberry Pi: it asks every few seconds for the next slip (op=next), prints it on the
 * receipt printer and reports it (op=done). No backend login here, the agent has its own key (header X-Agent-Key, see shop_print_agent_key()).
 * Every call also tells the system that the agent is alive, so the kitchen monitor knows it can send slips here instead of the browser.
 */
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
function pa_out($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

shop_ensure_schema();
$key = isset($_SERVER['HTTP_X_AGENT_KEY']) ? (string)$_SERVER['HTTP_X_AGENT_KEY'] : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $key === '' || !hash_equals(shop_print_agent_key(), $key)) { pa_out(array('ok' => false, 'error' => 'Kein Zugriff.'), 403); }
shop_setting_set('print_agent_seen', (string)time());

$op = isset($_POST['op']) ? (string)$_POST['op'] : '';
if ($op === 'next') {
	$job = shop_print_claim();
	pa_out(array('ok' => true, 'job' => $job));
}
if ($op === 'done') {
	shop_print_done((int)(isset($_POST['id']) ? $_POST['id'] : 0));
	pa_out(array('ok' => true));
}
if ($op === 'ping') { pa_out(array('ok' => true)); }
pa_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'), 400);
