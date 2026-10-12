<?php
/*
 * The small logo in the head of every mail (instead of the name in capital letters). The picture is order/mail/logo-mail.png (brown on transparent, made for the white mail card),
 * the address comes from config/hosts.inc.php (guest domain). Without a known address the name is shown as text, so a mail never gets a broken picture.
 */
function bm_mail_logo_url() {
	require_once __DIR__.'/hosts.class.php';
	$h = myseat_hosts();
	if ($h['app'] !== '') { return $h['app'].'/order/mail/logo-mail.png'; }
	if (function_exists('shop_site_url')) { try { $u = rtrim((string)shop_site_url(), '/'); if ($u !== '') { return $u.'/order/mail/logo-mail.png'; } } catch (Throwable $e) { /* fall back to the name */ } }
	return '';
}
// the row at the top of the white card
function bm_mail_logo_row($brand, $pad = '26px 32px 0') {
	$h = htmlspecialchars((string)$brand, ENT_QUOTES, 'UTF-8'); $u = bm_mail_logo_url();
	if ($u === '') { return '<tr><td style="font-family:Arial,Helvetica,sans-serif;padding:28px 32px 4px;font-size:12px;font-weight:bold;letter-spacing:.16em;text-transform:uppercase;color:#8a6d3b;">'.$h.'</td></tr>'; }
	return '<tr><td style="padding:'.$pad.';"><img src="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'" width="110" height="40" alt="'.$h.'" style="display:block;border:0;width:110px;height:auto;"></td></tr>';
}
// the same logo inside a line of text (the notice to the staff, next to the status badge)
function bm_mail_logo_inline($brand) {
	$h = htmlspecialchars((string)$brand, ENT_QUOTES, 'UTF-8'); $u = bm_mail_logo_url();
	if ($u === '') { return ' <span style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8a6d3b;font-weight:bold;">&nbsp;'.$h.'</span>'; }
	return ' <img src="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'" width="80" height="29" alt="'.$h.'" style="border:0;vertical-align:middle;margin-left:8px;width:80px;height:auto;">';
}

// Zeus, the mascot of Amadeus Delivery, as a small greeting next to the closing line of the mails about orders and the stamp card (not for reservations: there the restaurant logo speaks)
function bm_mail_zeus_url() {
	$u = bm_mail_logo_url();
	return $u === '' ? '' : str_replace('/mail/logo-mail.png', '/zeus_dot.png', $u);
}
function bm_mail_with_zeus($html) {
	$u = bm_mail_zeus_url();
	if ($u === '') { return $html; }
	return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td valign="middle">'.$html.'</td><td width="68" align="right" valign="middle" style="padding-left:12px;"><img src="'.htmlspecialchars($u, ENT_QUOTES, 'UTF-8').'" width="56" height="56" alt="Zeus" style="display:block;border:0;width:56px;height:56px;"></td></tr></table>';
}
