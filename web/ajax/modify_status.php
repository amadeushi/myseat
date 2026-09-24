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

// builds and sends the guest confirmation/decline mail for $r - only called for an actual
// approve/decline transition, never for routine arrival-status tracking
function msr_send_guest_mail($r, $mode) {
	global $dbTables, $general;
	$link = $GLOBALS['__mysql_compat_link'];
	$outlet = mysqli_fetch_assoc(mysqli_query($link,
		"SELECT outlet_name, property_id, avg_duration, confirmation_email FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$r['reservation_outlet_id']." LIMIT 1"));
	$property = $outlet ? mysqli_fetch_assoc(mysqli_query($link,
		"SELECT * FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;
	if (!$outlet || !$property) { return; }

	$form = array(
		'reservation_guest_name' => $r['reservation_guest_name'],
		'reservation_guest_email' => $r['reservation_guest_email'],
		'reservation_guest_phone' => $r['reservation_guest_phone'],
		'reservation_pax' => $r['reservation_pax'],
		'reservation_notes' => $r['reservation_notes'],
		'reservation_time' => $r['reservation_time'],
		'email_type' => (isset($r['reservation_email_lang']) && $r['reservation_email_lang'] === 'en') ? 'en' : 'de',
	);
	$cancel_scheme = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
	$cancel_url = $cancel_scheme.$_SERVER['SERVER_NAME'].preg_replace('#/(api|web)/.*$#', '', $_SERVER['SCRIPT_NAME'])
		.'/api/cancel.php?nr='.urlencode($r['reservation_bookingnumber']).'&email='.urlencode($r['reservation_guest_email']).'&lang='.$form['email_type'];

	$m = bm_build(array(
		'form' => $form, 'outlet' => $outlet, 'property' => $property,
		'date' => $r['reservation_date'],
		'date_text' => date($general['dateformat'], strtotime($r['reservation_date'])),
		'time_text' => formatTime($r['reservation_time'], $general['timeformat']),
		'booking_number' => $r['reservation_bookingnumber'],
		'cancel_url' => $cancel_url, 'origin' => 'backend', 'mode' => $mode,
	));
	$brand = bm_clean($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : $property['name']);
	$admin_email = !empty($outlet['confirmation_email']) ? $outlet['confirmation_email'] : $property['email'];
	bm_send_guest_mail($r['reservation_guest_email'], $m, $brand, $admin_email);
}

if ($value === 'CXL') {
	$was_pending = ($r['reservation_approval'] === 'pending');
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_hidden='1', reservation_status='CXL'"
		.($was_pending ? ", reservation_approval='declined'" : '')
		." WHERE reservation_id=".$id);
	if ($was_pending) { msr_send_guest_mail($r, 'declined'); }
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
		msr_send_guest_mail($r, 'approved');
	}
} elseif ($r['reservation_approval'] === 'pending') {
	// first real decision on a still-pending request: approve it
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_approval='approved' WHERE reservation_id=".$id);
	require_once(__DIR__.'/../classes/tableplan_assign.class.php');
	tp_hook_after_booking($id);
	msr_send_guest_mail($r, 'approved');
}

mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_status='".mysqli_real_escape_string($link, $value)."' WHERE reservation_id=".$id);
echo 'OK';
