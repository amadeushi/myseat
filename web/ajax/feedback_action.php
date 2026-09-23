<?php
/*
 * Staff actions on a submitted feedback entry: save a reply and/or toggle "public". Called from
 * the plain form in content/feedback.page.php (full page reload, no JS/fetch involved).
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
require_once('../classes/feedback.class.php');

$back = '../main_page.php?p=8&fb_from='.urlencode(isset($_POST['fb_from']) ? $_POST['fb_from'] : '').'&fb_to='.urlencode(isset($_POST['fb_to']) ? $_POST['fb_to'] : '');

if (empty($_SESSION['valid_user']) || !current_user_can('Page-Feedback')) {
	header('Location: ../PLC/index.php');
	exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['token']) || !isset($_SESSION['token']) || $_POST['token'] !== $_SESSION['token']) {
	header('Location: '.$back);
	exit;
}

$feedback_id = isset($_POST['feedback_id']) ? (int)$_POST['feedback_id'] : 0;
if ($feedback_id > 0) {
	fb_set_public($feedback_id, isset($_POST['is_public']));

	$reply_text = isset($_POST['reply']) ? trim(substr($_POST['reply'], 0, 2000)) : '';
	// only the "Antwort speichern" button sends $_POST['action'] (the "Öffentlich" checkbox's own
	// auto-submit does not) - that is also the only case that should mail the guest
	$is_reply_submit = isset($_POST['action']) && $_POST['action'] === 'reply';
	if ($reply_text !== '') {
		fb_reply($feedback_id, $reply_text);
	}

	if ($is_reply_submit && $reply_text !== '') {
		require_once('../classes/booking_mail.class.php');
		$f = fb_row("SELECT * FROM ".fb_t('tp_feedback')." WHERE feedback_id = ? LIMIT 1", 'i', array($feedback_id));
		$outlet = $f ? mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
			"SELECT outlet_name, property_id FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$f['outlet_id']." LIMIT 1")) : null;
		$property = $outlet ? mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
			"SELECT * FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;

		if ($f && $outlet && $property && filter_var($f['guest_email'], FILTER_VALIDATE_EMAIL)) {
			$brand = bm_clean($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : $property['name']);
			$m = fb_reply_mail_build(array(
				'lang' => $f['lang'], 'brand' => $brand, 'guest_name' => bm_clean($f['guest_name']), 'reply' => $reply_text,
				'legal' => bm_legal_lines($property),
				'imprint_url' => !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '',
				'privacy_url' => !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : '',
			));
			$from_email = !empty($property['email']) ? $property['email'] : $settings['emailUser'];
			$from = mb_encode_mimeheader($brand, 'UTF-8', 'B').' <'.$from_email.'>';
			$subject = mb_encode_mimeheader($m['subject'], 'UTF-8', 'B');
			$boundary = '=_'.md5(uniqid('', true));
			$headers = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nReply-To: ".$from."\r\nX-Mailer: mySeat\r\n";
			$headers .= "Content-Type: multipart/alternative; boundary=\"".$boundary."\"\r\n";
			$body  = "--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['plain']));
			$body .= "--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['html']));
			$body .= "--".$boundary."--\r\n";
			if (!mail($f['guest_email'], $subject, $body, $headers)) {
				error_log('mySeat feedback reply mail failed for feedback_id '.$feedback_id);
			}
		}
	}
}

header('Location: '.$back);
