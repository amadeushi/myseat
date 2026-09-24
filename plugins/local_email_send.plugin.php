<?php

/*
Plugin Name: Local Email confirmation
Plugin URI: http://www.myseat.us
Description: This plugin sends a email confirmation after booking (texts and layout: web/classes/booking_mail.class.php)
Version: 2.0
Author: Bernd Orttenburger  modified by soho
Author URI: http://www.myseat.us
Kudus: Bernd Orttenburger
*/

/**
 * Initialisation routines
 *
 */

//set plugin id as file name of plugin
$plugin_id = basename(__FILE__);

//the plugin data
$data['name'] = "Local Email confirmation";
$data['author'] = "soho ";
$data['url'] = "http://www.myseat.us/";

//register plugin data
register_plugin($plugin_id, $data);

/**
 * Plugin function
 *
 */

// the main function: confirmation mail to the guest and a notification for the restaurant.
// $mode ('confirmed'|'pending') comes straight from the after_booking hook's $args - a pending
// request gets a different guest mail and a distinctly flagged admin notification (see
// web/classes/booking_mail.class.php bm_build()); the later approve/decline decision sends its
// own guest mail directly from web/ajax/reservation_approval_action.php, not through this hook
function my_email_send_conf($mode = 'confirmed') {
	global $global_basedir, $general, $settings;
	if (!is_string($mode) || $mode === '') { $mode = 'confirmed'; }
	try {
		require_once __DIR__ . '/../web/classes/booking_mail.class.php';
		$form = isset($_SESSION['form']) ? $_SESSION['form'] : array();
		$property = querySQL('property_info');
		if (!$property || empty($form['reservation_guest_name'])) { return; }

		// date: a single day or, for a recurring booking from the backend, the range
		$date_text = date($general['dateformat'], strtotime($_SESSION['selectedDate']));
		if (!empty($form['recurring_dbdate']) && strtotime($form['recurring_dbdate']) > strtotime($_SESSION['selectedDate'])) {
			$date_text .= ' - '.date($general['dateformat'], strtotime($form['recurring_dbdate']));
		}

		$to_guest = isset($form['reservation_guest_email']) ? trim(bm_clean($form['reservation_guest_email'])) : '';
		$to_admin = isset($_SESSION['selOutlet']['confirmation_email']) ? trim($_SESSION['selOutlet']['confirmation_email']) : '';

		// one-click cancel link (booking number + email prefilled, cancels only after confirmation)
		$cancel_scheme = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
		$cancel_url = $cancel_scheme.$_SERVER['SERVER_NAME'].preg_replace('#/(api|web)/.*$#','',$_SERVER['SCRIPT_NAME']).'/api/cancel.php?nr='.urlencode($_SESSION['booking_number']).'&email='.urlencode($to_guest).'&lang='.((isset($form['email_type']) && $form['email_type'] == 'en') ? 'en' : 'de');

		// links for the restaurant notification: a signed page to look at and decide a pending
		// request, and the backend day view (needs a login)
		require_once __DIR__ . '/../web/classes/approval.class.php';
		global $dbTables;
		$request_url = '';
		$res = mysqli_query($GLOBALS['__mysql_compat_link'], "SELECT reservation_id FROM `".$dbTables->reservations."` WHERE reservation_bookingnumber = '".mysqli_real_escape_string($GLOBALS['__mysql_compat_link'], (string)$_SESSION['booking_number'])."' ORDER BY reservation_id DESC LIMIT 1");
		$res_row = $res ? mysqli_fetch_assoc($res) : null;
		if ($res_row) { $request_url = appr_request_url($res_row['reservation_id'], $_SESSION['booking_number']); }
		$backend_url = appr_base_url().'/web/main_page.php?p=2&selectedDate='.urlencode($_SESSION['selectedDate']);

		$m = bm_build(array(
			'request_url' => $request_url,
			'backend_url' => $backend_url,
			'form' => $form,
			'outlet' => $_SESSION['selOutlet'],
			'property' => $property,
			'date' => $_SESSION['selectedDate'],
			'date_text' => $date_text,
			'time_text' => formatTime($form['reservation_time'], $general['timeformat']),
			'booking_number' => $_SESSION['booking_number'],
			'cancel_url' => $cancel_url,
			'origin' => (basename($_SERVER['SCRIPT_NAME']) == 'process_booking.php') ? 'online' : 'backend',
			'mode' => $mode,
		));

		$brand = bm_clean($property['name']);

		bm_send_guest_mail($to_guest, $m, $brand, $to_admin);
		bm_send_admin_mail($to_admin, $m, $brand);
	} catch (Throwable $e) {
		// a problem with the mail must never stop the booking itself
		error_log('mySeat booking mail: '.$e->getMessage());
	}
}
/**
* function add_hook($tag, $function, $priority)
*
* @param string $tag. The name of the hook.
* @param string $function. The function you wish to be called.
* @param int $priority optional. Used to specify the order in which the functions are executed.
* Range 0~20, 0 first call, 20 last call, standard setting is 10
*/

//add hook, where to execute a function
add_hook('after_booking','my_email_send_conf',0);
?>
