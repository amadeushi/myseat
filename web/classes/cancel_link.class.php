<?php
/*
 * Cancel links for guests (api/cancel.php). Two ways to prove that a guest may cancel a reservation:
 *  - the booking number plus the e-mail address OR the mobile number the booking was made with, or
 *  - a signed token (HMAC over reservation id + booking number) as in the link of the SMS. It needs no
 *    contact data, so it also works for bookings entered by hand that only have a phone number.
 * The cancellation itself is always a POST after a confirmation click (see cancel.php), so link previews
 * of messengers and mail scanners cannot cancel anything. No database access in here on purpose.
 */

function cl_secret() {
	global $settings;
	if (!empty($settings['feedbackCronKey']) && $settings['feedbackCronKey'] !== 'CHANGE-ME') { return $settings['feedbackCronKey']; }
	return hash('sha256', (isset($settings['dbName']) ? $settings['dbName'] : '').'|'.(isset($settings['dbUser']) ? $settings['dbUser'] : '').'|'.(isset($settings['dbPass']) ? $settings['dbPass'] : ''));
}

function cl_token($reservation_id, $booking_number) {
	return substr(hash_hmac('sha256', 'cancel|'.(int)$reservation_id.'|'.$booking_number, cl_secret()), 0, 24);
}

function cl_token_ok($reservation_id, $booking_number, $token) {
	return is_string($token) && $token !== '' && hash_equals(cl_token($reservation_id, $booking_number), $token);
}

// public base URL of this installation (a cron run has no request, so callers may define REMINDER_SITE_URL)
function cl_site_url() {
	if (defined('REMINDER_SITE_URL')) { return rtrim(REMINDER_SITE_URL, '/'); }
	if (defined('FEEDBACK_SITE_URL')) { return rtrim(FEEDBACK_SITE_URL, '/'); }
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
	return $scheme.$_SERVER['SERVER_NAME'].preg_replace('#/(api|web|PLC)/.*$#', '', $_SERVER['SCRIPT_NAME']);
}

function cl_cancel_url($reservation_id, $booking_number, $lang = 'de') {
	return cl_site_url().'/api/cancel.php?nr='.urlencode($booking_number).'&t='.cl_token($reservation_id, $booking_number).'&lang='.($lang === 'en' ? 'en' : 'de');
}

/*
 * Comparable form of a phone number: digits only, international, so "0151 2345678", "+49 151 2345678" and
 * "0049151 2345678" match. Returns '' for anything that is not a plausible number.
 */
function cl_phone_key($input) {
	$s = preg_replace('/\(\s*0\s*\)/', '', html_entity_decode((string)$input, ENT_QUOTES, 'UTF-8'));
	$d = preg_replace('/\D+/', '', $s);
	if ($d === '') { return ''; }
	if (strpos($d, '00') === 0) { $d = substr($d, 2); }
	elseif ($d[0] === '0') { $d = '49'.substr($d, 1); }
	if (strpos($d, '490') === 0) { $d = '49'.substr($d, 3); }
	return strlen($d) >= 8 ? $d : '';
}

// "a@b.c" is an e-mail address, everything else is taken as a phone number
function cl_is_email($s) { return strpos((string)$s, '@') !== false; }
