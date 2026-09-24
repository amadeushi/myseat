<?php
/*
 * Sends the "see you tomorrow" reminder (arrival, parking, menus) for reservations that are on the
 * next day. Meant to run every 30-60 minutes as a webcron job, same key as the feedback cron:
 *     https://your-domain/web/cron/send_reminders.php?key=<$settings['feedbackCronKey']>
 * Sends only between 10:00 and 20:00 and never twice for the same reservation (tp_reminders).
 */

require __DIR__.'/../../config/config.general.php';

$cron_key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $cron_key || $cron_key === 'CHANGE-ME')) {
	http_response_code(403);
	exit('Forbidden');
}

// the domain the cancel link points to - this app runs for a single restaurant on this domain
define('REMINDER_SITE_URL', 'https://reservierung.amds.at');

require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require __DIR__.'/../classes/reminder.class.php';
require __DIR__.'/../classes/booking_mail.class.php';

$link = $GLOBALS['__mysql_compat_link'];
$tz_row = mysqli_fetch_assoc(mysqli_query($link, "SELECT timezone FROM `".$dbTables->settings."` LIMIT 1"));
date_default_timezone_set($tz_row && $tz_row['timezone'] !== '' ? $tz_row['timezone'] : 'Europe/Berlin');
$date_format = !empty($general['dateformat']) ? $general['dateformat'] : 'd.m.Y';
$time_format = !empty($general['timeformat']) ? $general['timeformat'] : 'H:i';

$due = rem_find_due();
$sent = 0;
$failed = 0;

foreach ($due as $r) {
	if (!rem_mark_sent($r['reservation_id'])) { continue; } // another run claimed it

	$to = trim($r['reservation_guest_email']);
	$outlet = mysqli_fetch_assoc(mysqli_query($link,
		"SELECT outlet_name, property_id, avg_duration, confirmation_email FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$r['reservation_outlet_id']." LIMIT 1"));
	$property = $outlet ? mysqli_fetch_assoc(mysqli_query($link,
		"SELECT * FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;
	if (!$outlet || !$property || !filter_var($to, FILTER_VALIDATE_EMAIL)) { $failed++; continue; }

	$lang = ($r['reservation_email_lang'] === 'en') ? 'en' : 'de';
	$form = array(
		'reservation_guest_name' => $r['reservation_guest_name'], 'reservation_guest_email' => $to,
		'reservation_guest_phone' => $r['reservation_guest_phone'], 'reservation_pax' => $r['reservation_pax'],
		'reservation_notes' => $r['reservation_notes'], 'reservation_time' => $r['reservation_time'], 'email_type' => $lang,
	);
	$m = bm_build(array(
		'form' => $form, 'outlet' => $outlet, 'property' => $property,
		'date' => $r['reservation_date'],
		'date_text' => date($date_format, strtotime($r['reservation_date'])),
		'time_text' => date($time_format, strtotime($r['reservation_time'])),
		'booking_number' => $r['reservation_bookingnumber'],
		'cancel_url' => REMINDER_SITE_URL.'/api/cancel.php?nr='.urlencode($r['reservation_bookingnumber']).'&email='.urlencode($to).'&lang='.$lang,
		'origin' => 'online', 'mode' => 'reminder',
	));
	$brand = bm_clean($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : $property['name']);
	$admin_email = !empty($outlet['confirmation_email']) ? $outlet['confirmation_email'] : $property['email'];
	bm_send_guest_mail($to, $m, $brand, $admin_email);
	$sent++;
}

$msg = 'mySeat reminder cron: '.count($due).' due, '.$sent.' sent, '.$failed.' failed.';
if ($is_cli) { echo $msg."\n"; } else { header('Content-Type: text/plain'); echo $msg; }
