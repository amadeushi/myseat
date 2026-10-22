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
	if (!$outlet || !$property) { return array('mail' => false, 'sms' => 'skip'); }
	// the date/time settings of the restaurant: $general is only filled in the backend (it is read for the property of the session); the decision link in the mail
	// (api/request.php) runs without a session, so $general is empty there - the time then came out as "7:00 pm" and the date was missing
	$gen = (is_array($general) && !empty($general['timeformat']) && !empty($general['dateformat'])) ? $general : null;
	if (!$gen) {
		$gq = mysqli_query($link, "SELECT timeformat, dateformat FROM `".$dbTables->settings."` WHERE property_id = ".(int)$outlet['property_id']." LIMIT 1");
		$gen = $gq ? mysqli_fetch_assoc($gq) : null;
		if (!$gen || empty($gen['timeformat']) || empty($gen['dateformat'])) { $gen = array('timeformat' => 24, 'dateformat' => 'd.m.Y'); }
	}

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
		'date_text' => date($gen['dateformat'], strtotime($r['reservation_date'])),
		'time_text' => formatTime($r['reservation_time'], $gen['timeformat']),
		'booking_number' => $r['reservation_bookingnumber'],
		'cancel_url' => $cancel_url, 'origin' => 'backend', 'mode' => $mode,
	));
	$brand = bm_clean($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : $property['name']);
	$admin_email = !empty($outlet['confirmation_email']) ? $outlet['confirmation_email'] : $property['email'];
	$result = array('mail' => false, 'sms' => 'skip');
	$guest_mail = html_entity_decode(trim((string)$r['reservation_guest_email']), ENT_QUOTES, 'UTF-8');
	$result['mail'] = (bool)bm_send_guest_mail($r['reservation_guest_email'], $m, $brand, $admin_email);
	// a changed reservation: guests who left only a phone number get the news by SMS (with an address the mail is enough)
	if ($mode === 'updated' && !filter_var($guest_mail, FILTER_VALIDATE_EMAIL)) {
		require_once __DIR__.'/sms.class.php';
		$result['sms'] = sms_send_for_reservation('update', array(
			'reservation_id' => $r['reservation_id'], 'phone' => $r['reservation_guest_phone'], 'brand' => $brand,
			'date' => $r['reservation_date'], 'time' => $r['reservation_time'], 'pax' => $r['reservation_pax'], 'number' => $r['reservation_bookingnumber'],
			'restaurant_phone' => !empty($property['phone']) ? $property['phone'] : '',
		));
	}
	if ($mode === 'approved') {
		require_once __DIR__.'/sms.class.php';
		sms_send_for_reservation('confirmation', array(
			'reservation_id' => $r['reservation_id'], 'phone' => $r['reservation_guest_phone'], 'brand' => $brand,
			'date' => $r['reservation_date'], 'time' => $r['reservation_time'], 'pax' => $r['reservation_pax'], 'number' => $r['reservation_bookingnumber'],
			'restaurant_phone' => !empty($property['phone']) ? $property['phone'] : '',
		));
	}
	return $result;
}

// the current details of a reservation to the guest once more, with a note that something changed: mail, or SMS when there is no address.
// Returns array(ok, message|error) for the backend.
function appr_notify_update($r) {
	require_once __DIR__.'/booking_mail.class.php';
	$mail_ok = filter_var(html_entity_decode(trim((string)$r['reservation_guest_email']), ENT_QUOTES, 'UTF-8'), FILTER_VALIDATE_EMAIL) !== false;
	$res = appr_send_guest_mail($r, 'updated');
	if ($mail_ok) {
		return $res['mail']
			? array('ok' => true, 'message' => 'Die geänderten Angaben wurden per E-Mail an '.html_entity_decode(trim($r['reservation_guest_email']), ENT_QUOTES, 'UTF-8').' gesendet.')
			: array('ok' => false, 'error' => 'Die E-Mail ließ sich nicht senden. Bitte prüfe die Adresse oder versuche es später noch einmal.');
	}
	if ($res['sms'] === 'accepted' || $res['sms'] === 'queued') {
		return array('ok' => true, 'message' => 'Der Gast hat keine E-Mail-Adresse. Die geänderten Angaben gehen per SMS an '.html_entity_decode(trim($r['reservation_guest_phone']), ENT_QUOTES, 'UTF-8').'.');
	}
	if ($res['sms'] === 'off') { return array('ok' => false, 'error' => 'Der Gast hat keine E-Mail-Adresse, und der SMS-Versand ist nicht eingeschaltet.'); }
	if ($res['sms'] === 'skip') { return array('ok' => false, 'error' => 'Der Gast hat weder eine E-Mail-Adresse noch eine Handynummer, an die sich eine SMS senden lässt.'); }
	if ($res['sms'] === 'duplicate') { return array('ok' => false, 'error' => 'Die SMS wurde gerade eben schon gesendet.'); }
	return array('ok' => false, 'error' => 'Die SMS ließ sich nicht senden. Bitte versuche es später noch einmal.');
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
