<?php
/*
 * "See you tomorrow" reminder mail, sent the day before a reservation (web/cron/send_reminders.php).
 * tp_reminders remembers which reservation already got one, so a cron that runs every hour never
 * mails twice. Uses the small DB helpers of web/classes/feedback.class.php.
 */
require_once __DIR__.'/feedback.class.php';
require_once __DIR__.'/approval.class.php';

function rem_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	appr_ensure_schema();
	fb_ensure_schema();
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_reminders')." (
		`reservation_id` INT NOT NULL PRIMARY KEY,
		`sent_at` DATETIME NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

/*
 * Reservations for tomorrow that should get a reminder: still on (not cancelled, no-show, waitlisted,
 * or an undecided/declined large-party request), guest email on file, no reminder yet. Bookings made
 * less than 18 hours ago are skipped - the guest just received the confirmation. Only sent between
 * 10:00 and 20:00 so it never lands in the middle of the night.
 */
function rem_find_due() {
	rem_ensure_schema();
	global $dbTables;
	$hour = (int)date('G');
	if ($hour < 10 || $hour >= 20) { return array(); }
	return fb_rows("SELECT r.reservation_id, r.reservation_outlet_id, r.reservation_date, r.reservation_time,
			r.reservation_guest_name, r.reservation_guest_email, r.reservation_guest_phone, r.reservation_pax,
			r.reservation_notes, r.reservation_bookingnumber, r.reservation_email_lang
		FROM `".$dbTables->reservations."` r
		LEFT JOIN ".fb_t('tp_reminders')." m ON m.reservation_id = r.reservation_id
		LEFT JOIN ".fb_t('tp_mail_optout')." o ON o.reservation_id = r.reservation_id
		WHERE o.reservation_id IS NULL AND r.reservation_hidden = 0 AND r.reservation_wait = 0
		AND r.reservation_status NOT IN ('NSW', 'CXL')
		AND r.reservation_approval NOT IN ('pending', 'declined')
		AND r.reservation_guest_email <> ''
		AND r.reservation_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
		AND r.reservation_timestamp <= (NOW() - INTERVAL 18 HOUR)
		AND m.reservation_id IS NULL");
}

// true only for the process that actually claimed the reservation (unique key = overlap-safe)
function rem_mark_sent($reservation_id) {
	rem_ensure_schema();
	mysqli_query(fb_db(), "INSERT IGNORE INTO ".fb_t('tp_reminders')." (reservation_id, sent_at) VALUES (".(int)$reservation_id.", NOW())");
	return mysqli_affected_rows(fb_db()) === 1;
}
