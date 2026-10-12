<?php
/*
 * "Neuigkeiten per Mail?" on the page after a reservation (and "Mail erneut senden"): one answer from the guest who has just booked.
 * The address is never taken from the browser: process_booking.php puts the address of the booking into the session, only that one can be asked.
 * The request is the double opt-in: nothing is mailable before the guest confirms in the mail (see web/classes/shop_newsletter.class.php).
 */
require_once __DIR__.'/../web/classes/mysql_compat.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function nla_out($d) { echo json_encode($d); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { nla_out(array('ok' => false, 'error' => 'Bitte lade die Seite neu.')); }
$ask = isset($_SESSION['nl_widget']) && is_array($_SESSION['nl_widget']) ? $_SESSION['nl_widget'] : null;
if (!$ask || empty($_POST['barrier']) || empty($_SESSION['barrier']) || !hash_equals((string)$_SESSION['barrier'], (string)$_POST['barrier'])) { nla_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.')); }
include('../config/config.general.php');
include('../web/classes/database.class.php');
include('../web/classes/connect.db.php');
require_once('../web/classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');
$en = $ask['lang'] === 'en';
$r = shop_nl_from_form($ask['email'], $ask['name'], true, false, 'widget_after', 0, $ask['lang'], $ask['text']);
if (!$r) { nla_out(array('ok' => false, 'error' => $en ? 'That did not work. Please try again.' : 'Das hat nicht geklappt. Bitte versuche es noch einmal.')); }
if (!$r['ok']) { nla_out(array('ok' => false, 'error' => $r['error'])); }
nla_out(array('ok' => true, 'state' => $r['state'], 'mail' => preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', shop_nl_email($ask['email']))));
