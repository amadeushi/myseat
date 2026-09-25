<?php
/*
 * SMS settings from the backend (Einstellungen > SMS-Versand): switch SMS on/off, store or remove the gateway
 * key, test the connection, send a test SMS. The key is stored encrypted and never sent back to the browser,
 * only its last 4 characters. Answers JSON.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/db_queries.db.php');
include('../classes/business.class.php');
require_once('../classes/sms.class.php');

header('Content-Type: application/json; charset=utf-8');
function ss_out($data) { echo json_encode($data); exit; }

if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	ss_out(array('ok' => false, 'error' => 'Keine Berechtigung.'));
}
if (!isset($_POST['token']) || !hash_equals((string)$_SESSION['token'], (string)$_POST['token'])) {
	ss_out(array('ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.'));
}

$op = isset($_POST['op']) ? (string)$_POST['op'] : '';
$state = function () {
	$k = sms_key_info();
	return array('enabled_flag' => sms_cfg()['enabled'], 'ready' => sms_enabled(), 'key_source' => $k['source'], 'key_masked' => $k['masked'], 'stats' => sms_enabled() ? sms_stats() : null);
};

if ($op === 'save') {
	sms_setting_set('enabled', !empty($_POST['enabled']) ? '1' : '0');
	$new = isset($_POST['api_key']) ? trim((string)$_POST['api_key']) : '';
	if ($new !== '') {
		if (strlen($new) < 16 || strlen($new) > 200 || preg_match('/[\s\x00-\x1f]/', $new)) {
			ss_out(array('ok' => false, 'error' => 'Der Schlüssel sieht nicht richtig aus (16 bis 200 Zeichen, ohne Leerzeichen).'));
		}
		$enc = sms_encrypt($new);
		if ($enc === null) { ss_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		sms_setting_set('api_key', $enc);
	}
	ss_out(array('ok' => true, 'message' => 'Gespeichert.') + $state());
}

if ($op === 'clear_key') {
	sms_setting_set('api_key', null);
	ss_out(array('ok' => true, 'message' => 'Der in den Einstellungen hinterlegte Schlüssel wurde gelöscht.') + $state());
}

if ($op === 'health') {
	$k = sms_key_info();
	if ($k['source'] === null) { ss_out(array('ok' => false, 'error' => 'Es ist noch kein Schlüssel hinterlegt.')); }
	$h = sms_health();
	ss_out(array('ok' => true, 'health_ok' => $h['ok'], 'message' => $h['ok'] ? 'Verbindung in Ordnung'.($h['project'] ? ' (Projekt '.$h['project'].')' : '').'.' : 'Verbindung fehlgeschlagen: '.$h['error']));
}

if ($op === 'test') {
	if (!sms_enabled()) { ss_out(array('ok' => false, 'error' => 'SMS ist noch nicht aktiv (Schalter und Schlüssel nötig).')); }
	$mobile = sms_normalize_phone(isset($_POST['phone']) ? (string)$_POST['phone'] : '');
	if ($mobile === null) { ss_out(array('ok' => false, 'error' => 'Bitte gib eine Mobilnummer an (Festnetz kann keine SMS empfangen).')); }
	$st = sms_enqueue(null, $mobile, 'test', sms_fit('', 'mySeat', ': Test-SMS. Die SMS-Anbindung funktioniert, Umlaute: ä ö ü.'));
	if ($st === 'accepted') { ss_out(array('ok' => true, 'message' => 'Test-SMS an '.substr($mobile, 0, 5).'*** wurde vom Gateway angenommen.') + $state()); }
	if ($st === 'queued') { ss_out(array('ok' => true, 'message' => 'Das Gateway hat sie noch nicht angenommen (Limit oder kurz nicht erreichbar). Sie liegt in der Warteschlange und geht mit dem nächsten Lauf raus.') + $state()); }
	$row = fb_rows("SELECT last_error FROM ".fb_t('tp_sms_outbox')." WHERE event_type = 'test' ORDER BY id DESC LIMIT 1");
	ss_out(array('ok' => false, 'error' => 'Test-SMS fehlgeschlagen'.($row && $row[0]['last_error'] ? ': '.$row[0]['last_error'] : '.')));
}

if ($op === 'save_link') {
	$url = isset($_POST['yourls_url']) ? trim((string)$_POST['yourls_url']) : '';
	if ($url !== '' && (stripos($url, 'https://') !== 0 || !filter_var($url, FILTER_VALIDATE_URL))) {
		ss_out(array('ok' => false, 'error' => 'Die YOURLS-Adresse muss mit https:// beginnen.'));
	}
	$sig = isset($_POST['yourls_sig']) ? trim((string)$_POST['yourls_sig']) : '';
	if ($sig !== '') {
		if (strlen($sig) < 8 || strlen($sig) > 100 || preg_match('/[\s\x00-\x1f]/', $sig)) {
			ss_out(array('ok' => false, 'error' => 'Der Signaturschlüssel sieht nicht richtig aus.'));
		}
		$enc = sms_encrypt($sig);
		if ($enc === null) { ss_out(array('ok' => false, 'error' => 'Der Schlüssel konnte nicht verschlüsselt werden.')); }
		sms_setting_set('yourls_sig', $enc);
	}
	sms_setting_set('yourls_url', $url !== '' ? $url : null);
	sms_setting_set('cancel_link', !empty($_POST['cancel_link']) ? '1' : '0');
	ss_out(array('ok' => true, 'message' => 'Gespeichert.'));
}

if ($op === 'clear_link_key') {
	sms_setting_set('yourls_sig', null);
	sms_setting_set('cancel_link', '0');
	ss_out(array('ok' => true, 'message' => 'Der Signaturschlüssel wurde gelöscht, der Absage-Link ist aus.'));
}

if ($op === 'test_link') {
	$c = sms_link_cfg();
	if ($c['sig'] === '') { ss_out(array('ok' => false, 'error' => 'Es ist noch kein Signaturschlüssel hinterlegt.')); }
	$res = sms_yourls_shorten(cl_site_url().'/api/cancel.php?test='.bin2hex(random_bytes(4)), 60, cl_site_url().'/api/cancel.php');
	if (!$res['ok']) { ss_out(array('ok' => false, 'error' => 'YOURLS: '.$res['error'])); }
	ss_out(array('ok' => true, 'message' => 'Verbindung in Ordnung, Testlink '.$res['short'].' (läuft in 1 Stunde ab).'));
}

ss_out(array('ok' => false, 'error' => 'Unbekannte Aktion.'));
