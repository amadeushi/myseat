<?php
/*
 * DSGVO/GDPR data minimization: once a reservation's visit date is more than
 * $general['reservation_retention_days'] (backend: Allgemeine Einstellungen) days in the past, the
 * guest-identifying fields are wiped from it - name, phone, email, address, city, notes, the booking
 * IP and referer. The row itself, its date/time/pax/table/status/billing figures stay (needed for
 * statistics and the legally required bookkeeping retention, e.g. German GoBD), only re-identifying
 * data disappears. Guest feedback (tp_feedback) is a separate, intentionally kept table and is never
 * touched here - see the feedback_hint in generalsettings.inc.php.
 *
 * Also purges by-product PII that mirrors reservation contact data elsewhere:
 *  - tp_sms_outbox rows (each carries the guest's own mobile number) for the purged reservations
 *  - tp_group_orders.organizer_email (the guest who organized a group pre-order) for the purged ones
 *
 * Meant to run once a day as a webcron job, same key as the other crons:
 *     https://your-domain/web/cron/purge_reservation_data.php?key=<$settings['feedbackCronKey']>
 * or as a shell cron job:
 *     php /path/to/web/cron/purge_reservation_data.php
 * Idempotent: reservations already purged (reservation_guest_name = '') are excluded from the WHERE
 * clause, so running it twice on the same day changes nothing the second time.
 */

require __DIR__.'/../../config/config.general.php';

$cron_key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $cron_key || $cron_key === 'CHANGE-ME')) {
	http_response_code(403);
	exit('Forbidden');
}

require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require __DIR__.'/../classes/feedback.class.php'; // fb_db()/fb_t()/fb_rows()/fb_exec() helpers

$link = fb_db();
$tz_row = mysqli_fetch_assoc(mysqli_query($link, "SELECT timezone, reservation_retention_days FROM `".$dbTables->settings."` LIMIT 1"));
date_default_timezone_set($tz_row && $tz_row['timezone'] !== '' ? $tz_row['timezone'] : 'Europe/Berlin');
$retention_days = ($tz_row && (int)$tz_row['reservation_retention_days'] > 0) ? (int)$tz_row['reservation_retention_days'] : 30;

$due = fb_rows("SELECT reservation_id FROM `".$dbTables->reservations."`
	WHERE reservation_date < DATE_SUB(CURDATE(), INTERVAL ? DAY)
	AND reservation_guest_name <> ''", 'i', array($retention_days));

$ids = array();
foreach ($due as $row) { $ids[] = (int)$row['reservation_id']; }

$sms_deleted = 0;
$group_orders_scrubbed = 0;

if ($ids) {
	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	$types = str_repeat('i', count($ids));

	$st = fb_exec("DELETE FROM ".fb_t('tp_sms_outbox')." WHERE reservation_id IN ($placeholders)", $types, $ids);
	if ($st) { $sms_deleted = mysqli_stmt_affected_rows($st); mysqli_stmt_close($st); }

	$st = fb_exec("UPDATE ".fb_t('tp_group_orders')." SET organizer_email = '' WHERE organizer_email <> '' AND reservation_id IN ($placeholders)", $types, $ids);
	if ($st) { $group_orders_scrubbed = mysqli_stmt_affected_rows($st); mysqli_stmt_close($st); }

	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET
		reservation_guest_name = '', reservation_guest_email = '', reservation_guest_phone = '',
		reservation_guest_adress = '', reservation_guest_city = '', reservation_notes = '',
		reservation_ip = '', reservation_referer = ''
		WHERE reservation_date < DATE_SUB(CURDATE(), INTERVAL ".(int)$retention_days." DAY)
		AND reservation_guest_name <> ''");
}

$msg = 'mySeat purge cron: '.count($ids).' reservations anonymized (retention '.$retention_days.' days), '
	.$sms_deleted.' SMS log rows deleted, '.$group_orders_scrubbed." group-order emails scrubbed.\n";
if ($is_cli) { echo $msg; } else { header('Content-Type: text/plain'); echo $msg; }
