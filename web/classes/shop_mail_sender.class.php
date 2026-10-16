<?php
/*
 * The sender of the mails by purpose: "news" (newsletter and its confirmation mails), "order" (orders, status, stamp card, codes, feedback), "reservation" (everything about reservations).
 * Each purpose can have its own address, name, reply address and its own SMTP access (the mailbox of that address), so that the mails of a purpose come from one domain with its own
 * reputation (SPF/DKIM/DMARC belong to the domain of the sender). Nothing set = the mails go as before (bm_send_guest_mail uses the profile only when a sender address is saved).
 * The profiles are kept as one setting (tp_shop_settings.k = 'mail_senders', JSON); the SMTP password is stored encrypted (sms_encrypt), never sent back to the browser.
 * Self-contained on purpose: the reservation mails are sent without the shop classes, so this file only needs the database helpers and sms.class.php.
 */
require_once __DIR__.'/sms.class.php';

function shop_sender_purposes() {
	return array('news' => 'Newsletter', 'order' => 'Bestellung und Lieferung', 'reservation' => 'Reservierung');
}

// all saved profiles as stored (password still encrypted); empty array without any. Read again on every call: a few reads per mail are nothing against the sending itself.
function shop_sender_raw() {
	try {
		$r = @fb_row("SELECT v FROM ".fb_t('tp_shop_settings')." WHERE k = 'mail_senders' LIMIT 1");
		$j = ($r && isset($r['v'])) ? json_decode((string)$r['v'], true) : null;
		return is_array($j) ? $j : array();
	} catch (Throwable $e) { return array(); }
}
function shop_sender_store($all) {
	fb_exec("REPLACE INTO ".fb_t('tp_shop_settings')." (k, v, updated_at) VALUES (?, ?, NOW())", 'ss', array('mail_senders', json_encode($all, JSON_UNESCAPED_UNICODE)));
}

// the profile of one purpose for sending: from, name, reply, host, port, secure ('', 'tls', 'ssl'), user, pass (plain) - or null when no sender address is saved
function shop_sender_profile($purpose) {
	$all = shop_sender_raw();
	$p = isset($all[$purpose]) && is_array($all[$purpose]) ? $all[$purpose] : null;
	if (!$p || empty($p['from']) || !filter_var($p['from'], FILTER_VALIDATE_EMAIL)) { return null; }
	$pass = '';
	if (!empty($p['pass'])) { $d = sms_decrypt($p['pass']); $pass = $d === null ? '' : $d; }
	return array('from' => $p['from'], 'name' => isset($p['name']) ? (string)$p['name'] : '', 'reply' => isset($p['reply']) ? (string)$p['reply'] : '',
		'host' => isset($p['host']) ? (string)$p['host'] : '', 'port' => isset($p['port']) ? (int)$p['port'] : 587, 'secure' => isset($p['secure']) ? (string)$p['secure'] : 'tls',
		'user' => isset($p['user']) ? (string)$p['user'] : '', 'pass' => $pass);
}

// for the backend: every purpose with its fields, without the password (only whether one is set)
function shop_sender_view() {
	$all = shop_sender_raw(); $out = array();
	foreach (shop_sender_purposes() as $k => $label) {
		$p = isset($all[$k]) && is_array($all[$k]) ? $all[$k] : array();
		$out[] = array('purpose' => $k, 'label' => $label, 'from' => isset($p['from']) ? $p['from'] : '', 'name' => isset($p['name']) ? $p['name'] : '', 'reply' => isset($p['reply']) ? $p['reply'] : '',
			'host' => isset($p['host']) ? $p['host'] : '', 'port' => isset($p['port']) ? (int)$p['port'] : 587, 'secure' => isset($p['secure']) ? $p['secure'] : 'tls', 'user' => isset($p['user']) ? $p['user'] : '',
			'pass_set' => !empty($p['pass']));
	}
	return $out;
}

// saves one purpose. $in: from, name, reply, host, port, secure, user, pass (empty = keep the saved one), clear (1 = remove the profile)
function shop_sender_save($purpose, $in) {
	$purposes = shop_sender_purposes();
	if (!isset($purposes[$purpose])) { return array('ok' => false, 'error' => 'Unbekannter Zweck.'); }
	$all = shop_sender_raw();
	if (!empty($in['clear'])) { unset($all[$purpose]); shop_sender_store($all); return array('ok' => true); }
	$f = function ($k, $max) use ($in) { return trim(mb_substr((string)(isset($in[$k]) ? $in[$k] : ''), 0, $max)); };
	$from = $f('from', 160); $name = $f('name', 80); $reply = $f('reply', 160); $host = strtolower($f('host', 120)); $user = $f('user', 160); $pass = (string)(isset($in['pass']) ? mb_substr($in['pass'], 0, 200) : '');
	$port = (int)(isset($in['port']) ? $in['port'] : 587); $secure = isset($in['secure']) && in_array($in['secure'], array('tls', 'ssl', ''), true) ? $in['secure'] : 'tls';
	if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Bitte eine gültige Absenderadresse eintragen.'); }
	if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Die Antwortadresse sieht nicht richtig aus.'); }
	if ($host !== '' && !preg_match('/^[a-z0-9][a-z0-9.\-]{1,118}[a-z0-9]$/', $host)) { return array('ok' => false, 'error' => 'Der Name des Mailservers sieht nicht richtig aus.'); }
	if ($host !== '' && ($port < 1 || $port > 65535)) { return array('ok' => false, 'error' => 'Der Port sieht nicht richtig aus (üblich: 587 oder 465).'); }
	if ($host !== '' && $user === '') { return array('ok' => false, 'error' => 'Für den Mailserver brauchst du auch den Benutzernamen (meist die Mailadresse).'); }
	$old = isset($all[$purpose]) && is_array($all[$purpose]) ? $all[$purpose] : array();
	$enc = isset($old['pass']) ? $old['pass'] : '';
	if ($pass !== '') { $enc = sms_encrypt($pass); if ($enc === null) { return array('ok' => false, 'error' => 'Das Passwort konnte nicht gespeichert werden.'); } }
	if ($host === '') { $enc = ''; $user = ''; }   // no server = no access data kept
	$all[$purpose] = array('from' => $from, 'name' => $name, 'reply' => $reply, 'host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $user, 'pass' => $enc);
	shop_sender_store($all);
	return array('ok' => true);
}

// a test mail with the profile of the purpose to an address the staff types; the answer says what the server said when it fails
function shop_sender_test($purpose, $to) {
	$purposes = shop_sender_purposes();
	if (!isset($purposes[$purpose])) { return array('ok' => false, 'error' => 'Unbekannter Zweck.'); }
	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Bitte eine gültige Empfängeradresse für den Test eintragen.'); }
	$p = shop_sender_profile($purpose);
	if (!$p) { return array('ok' => false, 'error' => 'Für diesen Zweck ist noch keine Absenderadresse gespeichert.'); }
	require_once __DIR__.'/booking_mail.class.php';
	global $settings; $brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
	$label = $purposes[$purpose];
	$via = $p['host'] !== '' ? 'über den Mailserver '.$p['host'].':'.$p['port'] : 'über den Server (ohne eigenen Mailserver)';
	$plain = "Das ist eine Testmail aus dem Backend.\r\nZweck: ".$label."\r\nAbsender: ".$p['from']."\r\nVersand: ".$via."\r\n\r\nWenn diese Mail im Posteingang ankommt (nicht im Spam), sind Absender und Zugang in Ordnung.";
	$html = '<p>Das ist eine Testmail aus dem Backend.</p><p><strong>Zweck:</strong> '.htmlspecialchars($label).'<br><strong>Absender:</strong> '.htmlspecialchars($p['from']).'<br><strong>Versand:</strong> '.htmlspecialchars($via).'</p><p>Wenn diese Mail im Posteingang ankommt (nicht im Spam), sind Absender und Zugang in Ordnung.</p>';
	$GLOBALS['bm_last_mail_error'] = '';
	$ok = bm_send_guest_mail($to, array('subject' => 'Testmail ('.$label.')', 'plain' => $plain, 'html' => $html, 'ics' => '', 'ics_filename' => '', 'purpose' => $purpose), $brand, $p['from']);
	if ($ok) { return array('ok' => true); }
	$why = trim((string)(isset($GLOBALS['bm_last_mail_error']) ? $GLOBALS['bm_last_mail_error'] : ''));
	return array('ok' => false, 'error' => 'Die Testmail konnte nicht gesendet werden'.($why !== '' ? ': '.mb_substr($why, 0, 240) : '.'));
}
