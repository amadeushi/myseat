<?php
/*
 * Change a reservation's status from the dropdown in the main list (see
 * web/classes/business.class.php getStatusList()). Two special cases beyond a plain status
 * update:
 *
 * - value='CXL' ("Storniert"): not a real reservation_status - hides the reservation exactly like
 *   the separate cancel button always did (reservation_hidden=1), and additionally mails the guest
 *   a decline notice if this was still an unconfirmed large-party request (reservation_approval=
 *   'pending', see web/classes/approval.class.php).
 * - picking any real status on a reservation that is currently hidden, or still pending: reopens
 *   it (reservation_hidden=0) or approves it. A confirmation mail only goes out if this reservation
 *   had a decline mail sent for it before (reservation_approval='declined') or is a still-pending
 *   request being approved for the first time - a plain cancel/reopen with no prior approval-flow
 *   involvement never mails the guest, matching the previous cancel-button behaviour.
 */
session_start();
include('../../config/config.general.php');
include('../classes/mysql_compat.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/local.class.php');
include('../classes/business.class.php');
include('../classes/db_queries.db.php');
include('../../config/config.inc.php');
require_once('../classes/approval.class.php');
require_once('../classes/booking_mail.class.php');

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) {
	http_response_code(403);
	exit;
}

secureSuperGlobals();
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$value = isset($_POST['value']) ? $_POST['value'] : '';
$valid_status = array('NYA', 'ARR', 'STD', 'PKD', 'DEP', 'NSW');

if ($id <= 0 || !in_array($value, array_merge($valid_status, array('CXL')), true)) {
	echo 'AJAX Error';
	exit;
}

appr_ensure_schema();
$link = $GLOBALS['__mysql_compat_link'];
$r = mysqli_fetch_assoc(mysqli_query($link, "SELECT * FROM `".$dbTables->reservations."` WHERE reservation_id = ".$id." LIMIT 1"));
if (!$r) {
	echo 'AJAX Error';
	exit;
}

if ($value === 'CXL') {
	$was_pending = ($r['reservation_approval'] === 'pending');
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_hidden='1', reservation_status='CXL'"
		.($was_pending ? ", reservation_approval='declined'" : '')
		." WHERE reservation_id=".$id);
	if ($was_pending) { appr_send_guest_mail($r, 'declined'); }
	echo 'OK';
	exit;
}

if ((int)$r['reservation_hidden'] === 1) {
	// reopening a hidden reservation - only mail the guest again if a decline mail had actually
	// gone out for it (a plain cancel/reopen with no approval-flow history stays silent)
	$was_declined = ($r['reservation_approval'] === 'declined');
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_hidden='0'"
		.($was_declined ? ", reservation_approval='approved'" : '')
		." WHERE reservation_id=".$id);
	if ($was_declined) {
		require_once(__DIR__.'/../classes/tableplan_assign.class.php');
		tp_hook_after_booking($id);
		appr_send_guest_mail($r, 'approved');
	}
} elseif ($r['reservation_approval'] === 'pending') {
	// first real decision on a still-pending request: approve it
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_approval='approved' WHERE reservation_id=".$id);
	require_once(__DIR__.'/../classes/tableplan_assign.class.php');
	tp_hook_after_booking($id);
	appr_send_guest_mail($r, 'approved');
}

mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_status='".mysqli_real_escape_string($link, $value)."' WHERE reservation_id=".$id);
echo 'OK';
