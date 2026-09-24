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

/*
 * Decide a request straight from the notification mail (api/request.php). The link carries an
 * HMAC token instead of a login: it only proves the mail reached the restaurant, and the actual
 * decision is a POST (never a GET), so mail scanners that prefetch links cannot trigger it.
 */
function appr_secret() {
	global $settings;
	if (!empty($settings['feedbackCronKey']) && $settings['feedbackCronKey'] !== 'CHANGE-ME') { return $settings['feedbackCronKey']; }
	return hash('sha256', $settings['dbName'].'|'.$settings['dbUser'].'|'.$settings['dbPass']);
}

function appr_token($reservation_id, $booking_number) {
	return substr(hash_hmac('sha256', 'request|'.(int)$reservation_id.'|'.$booking_number, appr_secret()), 0, 32);
}

function appr_token_ok($reservation_id, $booking_number, $token) {
	return is_string($token) && hash_equals(appr_token($reservation_id, $booking_number), $token);
}

// public base URL of this installation, from the current request (the plugin and the pages that call this always run in a web request)
function appr_base_url() {
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
	return $scheme.$_SERVER['SERVER_NAME'].preg_replace('#/(api|web|PLC)/.*$#', '', $_SERVER['SCRIPT_NAME']);
}

function appr_request_url($reservation_id, $booking_number) {
	return appr_base_url().'/api/request.php?id='.(int)$reservation_id.'&t='.appr_token($reservation_id, $booking_number);
}

// build and send the guest mail for an approve/decline decision ($mode 'approved' | 'declined')
function appr_send_guest_mail($r, $mode) {
	global $dbTables, $general;
	require_once __DIR__.'/booking_mail.class.php';
	$link = $GLOBALS['__mysql_compat_link'];
	$outlet = mysqli_fetch_assoc(mysqli_query($link,
		"SELECT outlet_name, property_id, avg_duration, confirmation_email FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$r['reservation_outlet_id']." LIMIT 1"));
	$property = $outlet ? mysqli_fetch_assoc(mysqli_query($link,
		"SELECT * FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;
	if (!$outlet || !$property) { return; }

	$lang = (isset($r['reservation_email_lang']) && $r['reservation_email_lang'] === 'en') ? 'en' : 'de';
	$form = array(
		'reservation_guest_name' => $r['reservation_guest_name'],
		'reservation_guest_email' => $r['reservation_guest_email'],
		'reservation_guest_phone' => $r['reservation_guest_phone'],
		'reservation_pax' => $r['reservation_pax'],
		'reservation_notes' => $r['reservation_notes'],
		'reservation_time' => $r['reservation_time'],
		'email_type' => $lang,
	);
	$cancel_url = appr_base_url().'/api/cancel.php?nr='.urlencode($r['reservation_bookingnumber']).'&email='.urlencode($r['reservation_guest_email']).'&lang='.$lang;

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

// approve a pending request: table assignment + confirmation mail. Returns false if it was not pending.
function appr_approve($r) {
	global $dbTables;
	if ($r['reservation_approval'] !== 'pending' || (int)$r['reservation_hidden'] === 1) { return false; }
	$link = $GLOBALS['__mysql_compat_link'];
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_approval='approved' WHERE reservation_id=".(int)$r['reservation_id']);
	require_once __DIR__.'/tableplan_assign.class.php';
	tp_hook_after_booking((int)$r['reservation_id']);
	appr_send_guest_mail($r, 'approved');
	return true;
}

// decline a pending request: hides it like a cancellation (frees the capacity) + decline mail
function appr_decline($r) {
	global $dbTables;
	if ($r['reservation_approval'] !== 'pending' || (int)$r['reservation_hidden'] === 1) { return false; }
	$link = $GLOBALS['__mysql_compat_link'];
	mysqli_query($link, "UPDATE `".$dbTables->reservations."` SET reservation_hidden='1', reservation_status='CXL', reservation_approval='declined' WHERE reservation_id=".(int)$r['reservation_id']);
	appr_send_guest_mail($r, 'declined');
	return true;
}
