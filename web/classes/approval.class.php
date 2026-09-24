<?php
/*
 * Schema for the reservation approval layer: an outlet can set a party-size threshold above
 * which a new online booking becomes a "pending" request instead of an instant confirmation.
 * Staff then approve (guest gets the normal confirmation mail) or decline (guest gets a polite
 * decline mail, and the reservation is hidden like a cancellation so it stops counting against
 * capacity). Self-provisioning, same lazy-ALTER pattern as web/classes/feedback.class.php.
 */

function appr_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	global $dbTables;
	$link = $GLOBALS['__mysql_compat_link'];

	$has = mysqli_query($link, "SHOW COLUMNS FROM `".$dbTables->outlets."` LIKE 'approval_pax_threshold'");
	if ($has && mysqli_num_rows($has) === 0) {
		mysqli_query($link, "ALTER TABLE `".$dbTables->outlets."` ADD `approval_pax_threshold` INT NOT NULL DEFAULT 0");
	}
	$has = mysqli_query($link, "SHOW COLUMNS FROM `".$dbTables->reservations."` LIKE 'reservation_approval'");
	if ($has && mysqli_num_rows($has) === 0) {
		mysqli_query($link, "ALTER TABLE `".$dbTables->reservations."` ADD `reservation_approval` VARCHAR(10) NOT NULL DEFAULT 'none'");
	}
	$done = true;
}
