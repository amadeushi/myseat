<?php
/*
 * Sends the "how was your visit?" feedback request for reservations whose visit happened
 * 24-96 hours ago (see fb_find_due_reservations()). Meant to run every 30-60 minutes, either as
 * a shell cron job:
 *     php /path/to/web/cron/send_feedback_requests.php
 * or, on hosts without shell cron (most shared hosters), as a webcron job hitting this URL with
 * the secret key set via $settings['feedbackCronKey'] in config/config.general.php:
 *     https://your-domain/web/cron/send_feedback_requests.php?key=<your key>
 *
 * Not reachable without that key, and does nothing when called twice for the same reservation
 * (tp_feedback has a unique key on reservation_id).
 */

require __DIR__.'/../../config/config.general.php';

// ** set $settings['feedbackCronKey'] to a random string of your own in config.general.php
// before enabling the webcron URL above - it is kept out of this file (and version control)
// on purpose, since this file is world-readable source code
$feedback_cron_key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';

$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $feedback_cron_key || $feedback_cron_key === 'CHANGE-ME')) {
	http_response_code(403);
	exit('Forbidden');
}

// the domain the feedback links point to - this app runs for a single restaurant on this domain
define('FEEDBACK_SITE_URL', 'https://reservierung.amds.at');

require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require __DIR__.'/../classes/feedback.class.php';
require __DIR__.'/../classes/booking_mail.class.php';

// timezone: same value the rest of the app uses (settings table, single row per property)
$tz_row = mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'], "SELECT timezone FROM `".$dbTables->settings."` LIMIT 1"));
date_default_timezone_set($tz_row && $tz_row['timezone'] !== '' ? $tz_row['timezone'] : 'Europe/Berlin');

$due = fb_find_due_reservations(24, 96);
$sent = 0;
$failed = 0;

foreach ($due as $r) {
	$token = fb_create_request($r);
	if ($token === false) { continue; } // already requested (race) or insert failed

	$outlet = mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
		"SELECT o.outlet_name, o.property_id FROM `".$dbTables->outlets."` o WHERE o.outlet_id = ".(int)$r['reservation_outlet_id']." LIMIT 1"));
	$property = $outlet ? mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
		"SELECT * FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;
	if (!$outlet || !$property) { continue; }

	$brand = bm_clean($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : $property['name']);
	$lang = ($r['reservation_email_lang'] === 'en') ? 'en' : 'de';
	$form_url = FEEDBACK_SITE_URL.'/api/feedback.php?token='.urlencode($token);

	$m = fb_mail_build(array(
		'lang' => $lang, 'brand' => $brand, 'guest_name' => bm_clean($r['reservation_guest_name']),
		'form_url' => $form_url, 'legal' => bm_legal_lines($property),
		'imprint_url' => !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '',
		'privacy_url' => !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : '',
	));

	$to = trim($r['reservation_guest_email']);
	if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }

	$from_email = !empty($property['email']) ? $property['email'] : $settings['emailUser'];
	$from = mb_encode_mimeheader($brand, 'UTF-8', 'B').' <'.$from_email.'>';
	$subject = mb_encode_mimeheader($m['subject'], 'UTF-8', 'B');
	$boundary = '=_'.md5(uniqid('', true));
	$headers = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nReply-To: ".$from."\r\nX-Mailer: mySeat\r\n";
	$headers .= "Content-Type: multipart/alternative; boundary=\"".$boundary."\"\r\n";
	$body  = "--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['plain']));
	$body .= "--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['html']));
	$body .= "--".$boundary."--\r\n";

	if (mail($to, $subject, $body, $headers)) { $sent++; } else { $failed++; error_log('mySeat feedback mail failed for reservation '.$r['reservation_id']); }
}

$msg = 'mySeat feedback cron: '.count($due).' due, '.$sent.' sent, '.$failed.' failed.';
if ($is_cli) { echo $msg."\n"; } else { header('Content-Type: text/plain'); echo $msg; }
