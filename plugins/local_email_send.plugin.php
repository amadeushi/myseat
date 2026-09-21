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

// the main function: confirmation mail to the guest and a notification for the restaurant
function my_email_send_conf() {
	global $global_basedir, $general, $settings;
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

		$m = bm_build(array(
			'form' => $form,
			'outlet' => $_SESSION['selOutlet'],
			'property' => $property,
			'date' => $_SESSION['selectedDate'],
			'date_text' => $date_text,
			'time_text' => formatTime($form['reservation_time'], $general['timeformat']),
			'booking_number' => $_SESSION['booking_number'],
			'cancel_url' => $cancel_url,
			'origin' => (basename($_SERVER['SCRIPT_NAME']) == 'process_booking.php') ? 'online' : 'backend',
		));

		$brand = bm_clean($property['name']);
		$subject_guest = mb_encode_mimeheader($m['subject'], 'UTF-8', 'B');
		$subject_admin = mb_encode_mimeheader($m['admin_subject'], 'UTF-8', 'B');
		$from = ($to_admin !== '') ? mb_encode_mimeheader($brand, 'UTF-8', 'B').' <'.$to_admin.'>' : '';

		if (isset($settings['emailSMTP']) && $settings['emailSMTP'] != 'LOCAL') {
			// PHPMailer (SMTP / sendmail)
			require_once __DIR__ . '/../web/classes/phpmailer/class.phpmailer.php';
			$mail = new PHPMailer();
			if ($settings['emailSMTP'] == 'SMTP') { $mail->IsSMTP(); } else { $mail->IsSendmail(); }
			$mail->SMTPAuth      = true;
			$mail->SMTPKeepAlive = true;
			$mail->CharSet       = 'UTF-8';
			$mail->Host          = $settings['emailHost'];
			$mail->Port          = $settings['emailPort'];
			$mail->Username      = $settings['emailUser'];
			$mail->Password      = $settings['emailPass'];
			$mail->SetFrom($to_admin, $brand);
			$mail->AddReplyTo($to_admin, $brand);

			if ($to_guest !== '' && filter_var($to_guest, FILTER_VALIDATE_EMAIL)) {
				$mail->Subject = $m['subject'];
				$mail->AltBody = $m['plain'];
				$mail->MsgHTML($m['html']);
				$mail->AddAddress($to_guest);
				if (!$mail->Send()) { error_log('mySeat mail to guest failed: '.$mail->ErrorInfo); }
				$mail->ClearAddresses();
			}
			if ($to_admin !== '') {
				$mail->IsHTML(false);
				$mail->Subject = $m['admin_subject'];
				$mail->Body    = $m['admin_text'];
				$mail->AltBody = '';
				$mail->AddAddress($to_admin);
				if (!$mail->Send()) { error_log('mySeat mail to restaurant failed: '.$mail->ErrorInfo); }
				$mail->ClearAddresses();
			}
		} else {
			// PHP mail(): UTF-8 everywhere, parts and subject encoded, no images or attachments
			if ($to_guest !== '' && filter_var($to_guest, FILTER_VALIDATE_EMAIL) && $from !== '') {
				$boundary = '=_'.md5(uniqid('', true));
				$headers  = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nReply-To: ".$from."\r\nX-Mailer: mySeat\r\n";
				$headers .= "Content-Type: multipart/alternative; boundary=\"".$boundary."\"\r\n";
				$body  = "--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['plain']));
				$body .= "--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['html']));
				$body .= "--".$boundary."--\r\n";
				mail($to_guest, $subject_guest, $body, $headers);
			}
			if ($to_admin !== '') {
				$headers  = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
				mail($to_admin, $subject_admin, chunk_split(base64_encode($m['admin_text'])), $headers);
			}
		}
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
