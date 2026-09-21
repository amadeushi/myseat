<?php
/*
 * Online booking block per day and outlet ("geschlossene Gesellschaft", "voll").
 * A blocked day accepts no bookings from the public form, but staff can still enter
 * reservations in the backend. This is separate from the "day off" of the daily outlet
 * settings, which closes the day completely.
 *
 * Own table (created on first use, prefix from config): online_blocks
 * Reads never throw: when anything goes wrong the day counts as not blocked, so a
 * problem here can not stop guests from booking.
 */
require_once __DIR__ . '/tableplan.class.php';

function ob_table() {
	global $settings;
	$prefix = isset($settings['dbTablePrefix']) ? $settings['dbTablePrefix'] : '';
	return '`'.$prefix.'online_blocks`';
}

function ob_valid_date($s) {
	return is_string($s) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function ob_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(tp_db(), "CREATE TABLE IF NOT EXISTS ".ob_table()." (
		`outlet_id` INT NOT NULL,
		`block_date` DATE NOT NULL,
		`reason` VARCHAR(80) NOT NULL DEFAULT '',
		`created_by` VARCHAR(80) NOT NULL DEFAULT '',
		`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (`outlet_id`,`block_date`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

// the block row of a day or false
function ob_get($outlet_id, $date) {
	try {
		if (!ob_valid_date($date)) { return false; }
		ob_ensure_schema();
		$r = tp_rows("SELECT `reason`,`created_by` FROM ".ob_table()." WHERE `outlet_id` = ? AND `block_date` = ?", 'is', array((int)$outlet_id, $date));
		return $r ? $r[0] : false;
	} catch (Throwable $e) {
		return false;
	}
}

function ob_is_blocked($outlet_id, $date) {
	return (bool)ob_get($outlet_id, $date);
}

// blocked dates (Y-m-d => reason) of an outlet between two dates, inclusive
function ob_blocked_dates($outlet_id, $from, $to) {
	$out = array();
	try {
		if (!ob_valid_date($from) || !ob_valid_date($to)) { return $out; }
		ob_ensure_schema();
		foreach (tp_rows("SELECT `block_date`,`reason` FROM ".ob_table()." WHERE `outlet_id` = ? AND `block_date` BETWEEN ? AND ? ORDER BY `block_date`", 'iss', array((int)$outlet_id, $from, $to)) as $r) {
			$out[$r['block_date']] = $r['reason'];
		}
	} catch (Throwable $e) {
	}
	return $out;
}

// block or unblock a day; returns the new state (true = blocked) or null on error
function ob_set($outlet_id, $date, $blocked, $reason, $by) {
	if (!ob_valid_date($date)) { return null; }
	ob_ensure_schema();
	if ($blocked) {
		$reason = mb_substr(trim((string)$reason), 0, 80);
		$by = mb_substr((string)$by, 0, 80);
		$st = tp_exec("INSERT INTO ".ob_table()." (`outlet_id`,`block_date`,`reason`,`created_by`) VALUES (?,?,?,?)
			ON DUPLICATE KEY UPDATE `reason` = VALUES(`reason`), `created_by` = VALUES(`created_by`)", 'isss', array((int)$outlet_id, $date, $reason, $by));
	} else {
		$st = tp_exec("DELETE FROM ".ob_table()." WHERE `outlet_id` = ? AND `block_date` = ?", 'is', array((int)$outlet_id, $date));
	}
	if (!$st) { return null; }
	mysqli_stmt_close($st);
	return (bool)$blocked;
}
