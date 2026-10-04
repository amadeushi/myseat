<?php
/*
 * Backend of the customer page (web/content/customers.page.php): the list with search and views, the card of one customer, and what staff does by hand (note and marks,
 * stamps, vouchers, account, link, delete). Needs a login with Reservation-Edit; blocking an account, linking two customers and deleting a customer need Settings-General.
 * Everything that changes something is a POST with the admin token of the page.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/shop_customers.class.php');
date_default_timezone_set('Europe/Berlin');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function cu_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	cu_out(array('ok' => false, 'error' => 'Keine Berechtigung. Bitte melde dich neu an.'));
}
shop_ensure_schema();
$body = array();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$body = json_decode((string)file_get_contents('php://input'), true);
	if (!is_array($body)) { $body = $_POST; }
}
$op = isset($_GET['op']) ? (string)$_GET['op'] : (isset($body['op']) ? (string)$body['op'] : '');

if ($op === 'list') {
	$views = array('all', 'regular', 'new', 'sleeping', 'almost', 'account', 'note', 'voucher', 'problem');
	$view = isset($_GET['view']) && in_array($_GET['view'], $views, true) ? $_GET['view'] : 'all';
	cu_out(array_merge(array('ok' => true), shop_cust_list(isset($_GET['q']) ? mb_substr((string)$_GET['q'], 0, 80) : '', $view, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0), 50)));
}
if ($op === 'card') {
	$c = shop_cust_card(preg_replace('/[^a-f0-9]/', '', (string)(isset($_GET['id']) ? $_GET['id'] : '')));
	cu_out($c ? array('ok' => true, 'card' => $c, 'can_admin' => current_user_can('Settings-General')) : array('ok' => false, 'error' => 'Diesen Kunden gibt es nicht (mehr).'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($body['token']) || empty($_SESSION['shop_admin_token']) || !hash_equals($_SESSION['shop_admin_token'], (string)$body['token'])) {
	cu_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}
$by = isset($_SESSION['valid_user']) && is_string($_SESSION['valid_user']) ? $_SESSION['valid_user'] : 'Personal';
$id = preg_replace('/[^a-f0-9]/', '', (string)(isset($body['id']) ? $body['id'] : ''));
$admin = array('account_block', 'account_reset', 'link', 'unlink', 'erase');
if (in_array($op, $admin, true) && !current_user_can('Settings-General')) { cu_out(array('ok' => false, 'error' => 'Dafür fehlt dir die Berechtigung (Einstellungen).')); }

if ($op === 'note') { cu_out(shop_cust_note_save($id, isset($body['note']) ? $body['note'] : '', isset($body['flags']) ? $body['flags'] : array(), $by)); }
if ($op === 'stamp_add') { cu_out(shop_cust_stamp_add($id, (int)(isset($body['base']) ? $body['base'] : 0), isset($body['reason']) ? $body['reason'] : '', $by, !empty($body['notify']))); }
if ($op === 'stamp_base') { cu_out(shop_cust_stamp_base($id, (int)(isset($body['stamp']) ? $body['stamp'] : 0), (int)(isset($body['base']) ? $body['base'] : 0), $by)); }
if ($op === 'stamp_remove') { cu_out(shop_cust_stamp_remove($id, (int)(isset($body['stamp']) ? $body['stamp'] : 0), isset($body['reason']) ? $body['reason'] : '', $by)); }
if ($op === 'coupon_issue') { cu_out(shop_cust_coupon_issue($id, (int)(isset($body['value']) ? $body['value'] : 0), (int)(isset($body['days']) ? $body['days'] : 0), isset($body['reason']) ? $body['reason'] : '', $by, !empty($body['notify']))); }
if ($op === 'coupon_toggle') { cu_out(shop_cust_coupon_toggle($id, (int)(isset($body['coupon']) ? $body['coupon'] : 0), $by)); }
if ($op === 'account_block') { cu_out(shop_cust_account_block($id, (int)(isset($body['account']) ? $body['account'] : 0), !empty($body['on']), $by)); }
if ($op === 'account_reset') { cu_out(shop_cust_account_reset($id, (int)(isset($body['account']) ? $body['account'] : 0), $by)); }
if ($op === 'link') { cu_out(shop_cust_link($id, preg_replace('/[^a-f0-9]/', '', (string)(isset($body['other']) ? $body['other'] : '')), $by)); }
if ($op === 'unlink') { cu_out(shop_cust_unlink($id, $by)); }
if ($op === 'erase') { cu_out(shop_cust_erase($id, isset($body['confirm']) ? $body['confirm'] : '', $by)); }
cu_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
