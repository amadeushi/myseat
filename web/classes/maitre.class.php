<?php
// Day note banner shown above the reservation list. Used by the page (includes/messagebox.inc.php)
// and by ajax/save_maitre.php so a saved note can be swapped in without reloading the page.
function maitre_note_html($comment) {
	if (trim((string)$comment) === '') { return ''; }
	return "<div class='alert_tip'><p class='center margin-bottom-10'>".uiIcon('info')." ".$comment."</p></div>";
}

// ---- own opening hours for a single date (day details): the day record (table maitre) gets day_open_time / day_close_time. Both empty = the normal hours of the weekday.
// The hours are used where the weekday hours are used (includes/get_variables.inc.php for the backend and the booking widget, tp_day_hours for the table assignment). No break times.

// the two columns exist (added on first use, checked once per request)
function maitre_ensure_columns() {
	static $done = false;
	if ($done) { return; }
	$done = true;
	global $dbTables;
	$link = isset($GLOBALS['__mysql_compat_link']) ? $GLOBALS['__mysql_compat_link'] : null;
	if (!$link || !isset($dbTables->maitre)) { return; }
	$r = @mysqli_query($link, "SHOW COLUMNS FROM `".$dbTables->maitre."` LIKE 'day_open_time'");
	if ($r && mysqli_num_rows($r) === 0) {
		@mysqli_query($link, "ALTER TABLE `".$dbTables->maitre."` ADD `day_open_time` TIME NULL DEFAULT NULL");
		@mysqli_query($link, "ALTER TABLE `".$dbTables->maitre."` ADD `day_close_time` TIME NULL DEFAULT NULL");
	}
}
// "9:30", "09:30" or "09:30:00" -> "09:30:00"; null for empty or not a time
function maitre_parse_time($s) {
	$s = trim((string)$s);
	if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $s, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) { return null; }
	return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
}
// put the hours of a date into the outlet array ($_SESSION['selOutlet']); only when both are set and differ
function maitre_apply_day_hours(&$outlet, $open, $close) {
	$o = maitre_parse_time($open); $c = maitre_parse_time($close);
	if ($o === null || $c === null || $o === $c) { return false; }
	$outlet['outlet_open_time'] = $o; $outlet['outlet_close_time'] = $c;
	return true;
}
// the normal hours of the weekday of a date as text "17:00 - 22:00" (for the hint in the day details); $outlet = $_SESSION['selOutlet'] with the originals in $_SESSION['open_time'] / ['close_time']
function maitre_normal_hours_text($outlet, $date, $generalOpen, $generalClose) {
	$w = date('w', strtotime($date));
	$o = isset($outlet[$w.'_open_time']) ? $outlet[$w.'_open_time'] : '00:00:00';
	if ($o !== '00:00:00' && $o !== '' && $o !== null) { $open = $o; $close = isset($outlet[$w.'_close_time']) ? $outlet[$w.'_close_time'] : $generalClose; }
	else { $open = $generalOpen; $close = (isset($outlet[$w.'_close_time']) && $outlet[$w.'_close_time'] !== '00:00:00') ? $outlet[$w.'_close_time'] : $generalClose; }
	return substr((string)$open, 0, 5).' - '.substr((string)$close, 0, 5);
}
