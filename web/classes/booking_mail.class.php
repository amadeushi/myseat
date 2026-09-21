<?php
/*
 * Texts and layout of the booking mails (guest confirmation and notification for the restaurant).
 * Plain text and HTML without any images. German by default, English when the guest used the
 * English booking form (email_type = 'en').
 *
 * Legal footer (config/config.general.php, all optional):
 *   $settings['mailLegal']  lines of the imprint data, one per line (name, address, contact, VAT id ...)
 *   $settings['imprintUrl'] address of the imprint page
 *   $settings['privacyUrl'] address of the privacy page
 * Without mailLegal the footer falls back to the property data of the system.
 */

// value from a booking form as clean text: the forms escape values for SQL and HTML, undo that
function bm_clean($s) {
	$s = (string)$s;
	$s = str_replace(array('\\r\\n', '\\n', '\\r'), "\n", $s);
	$s = stripslashes($s);
	return trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
}

function bm_lang($form) {
	return (isset($form['email_type']) && $form['email_type'] === 'en') ? 'en' : 'de';
}

function bm_weekday($date, $lang) {
	$de = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
	$w = (int)date('w', strtotime($date));
	return $lang === 'de' ? $de[$w] : date('l', strtotime($date));
}

// the legal lines shown at the end of the mail: array of strings
function bm_legal_lines($property) {
	global $settings;
	if (!empty($settings['mailLegal'])) {
		return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $settings['mailLegal'])), 'strlen'));
	}
	$lines = array();
	if (!empty($property['name'])) { $lines[] = bm_clean($property['name']); }
	$addr = trim(bm_clean(isset($property['street']) ? $property['street'] : '').', '.bm_clean(isset($property['zip']) ? $property['zip'] : '').' '.bm_clean(isset($property['city']) ? $property['city'] : ''), ' ,');
	if ($addr !== '') { $lines[] = $addr; }
	$contact = array();
	if (!empty($property['phone'])) { $contact[] = 'Tel. '.bm_clean($property['phone']); }
	if (!empty($property['email'])) { $contact[] = bm_clean($property['email']); }
	if ($contact) { $lines[] = implode(' · ', $contact); }
	return $lines;
}

/*
 * Build the mails. $d keys: form (the submitted booking form), outlet (selOutlet), property,
 * date (Y-m-d), time_text (formatted time), date_text (formatted date or range), booking_number,
 * cancel_url, origin ('online' | 'backend').
 * Returns array(lang, subject, plain, html, admin_subject, admin_text).
 */
function bm_build($d) {
	global $settings;
	$form = $d['form'];
	$lang = bm_lang($form);
	$de = ($lang === 'de');

	$outlet   = bm_clean($d['outlet']['outlet_name']);
	$name     = bm_clean($form['reservation_guest_name']);
	$pax      = (int)$form['reservation_pax'];
	$notes    = bm_clean(isset($form['reservation_notes']) ? $form['reservation_notes'] : '');
	$phone    = bm_clean(isset($form['reservation_guest_phone']) ? $form['reservation_guest_phone'] : '');
	$email    = bm_clean(isset($form['reservation_guest_email']) ? $form['reservation_guest_email'] : '');
	$number   = $d['booking_number'];
	$weekday  = bm_weekday($d['date'], $lang);
	$date_txt = ($d['date_text'] !== '' && strpos($d['date_text'], ' - ') !== false) ? $d['date_text'] : $weekday.', '.$d['date_text'];
	$time_txt = $de ? $d['time_text'].' Uhr' : $d['time_text'];
	$brand    = $outlet !== '' ? $outlet : bm_clean($d['property']['name']);
	$phone_contact = !empty($settings['mailPhone']) ? $settings['mailPhone'] : (!empty($d['property']['phone']) ? bm_clean($d['property']['phone']) : '');
	$mail_contact  = !empty($d['property']['email']) ? bm_clean($d['property']['email']) : '';
	$cancel = $d['cancel_url'];

	if ($de) {
		$subject  = 'Deine Reservierung im '.$brand.' am '.$date_txt.' um '.$time_txt;
		$greeting = 'Hallo '.$name.',';
		$intro    = 'vielen Dank für deine Reservierung! Wir haben einen Tisch für dich reserviert und freuen uns auf deinen Besuch.';
		$head     = 'Deine Reservierung';
		$rows     = array('Datum' => $date_txt, 'Uhrzeit' => $time_txt, 'Personen' => (string)$pax, 'Buchungsnummer' => $number);
		if ($notes !== '') { $rows['Deine Anmerkung'] = $notes; }
		$cancel_t = 'Es kommt etwas dazwischen? Du kannst deine Reservierung jederzeit mit einem Klick stornieren:';
		$cancel_l = 'Reservierung stornieren';
		$contact  = 'Für Fragen oder Änderungswünsche erreichst du uns'.($phone_contact !== '' ? ' telefonisch unter '.$phone_contact : '').($phone_contact !== '' && $mail_contact !== '' ? ' oder' : '').($mail_contact !== '' ? ' per E-Mail an '.$mail_contact : '').'.';
		$closing  = 'Bis bald im '.$brand.'!';
		$sign     = 'Dein '.$brand.'-Team';
		$legal_h  = 'Angaben zum Anbieter';
		$imprint_l = 'Impressum'; $privacy_l = 'Datenschutz';
		$auto     = 'Diese E-Mail wurde automatisch nach deiner Online-Reservierung versendet.';
	} else {
		$subject  = 'Your reservation at '.$brand.' on '.$date_txt.' at '.$time_txt;
		$greeting = 'Hello '.$name.',';
		$intro    = 'thank you for your reservation! We have reserved a table for you and look forward to seeing you.';
		$head     = 'Your reservation';
		$rows     = array('Date' => $date_txt, 'Time' => $time_txt, 'Guests' => (string)$pax, 'Booking number' => $number);
		if ($notes !== '') { $rows['Your note'] = $notes; }
		$cancel_t = 'Plans changed? You can cancel your reservation at any time with one click:';
		$cancel_l = 'Cancel reservation';
		$contact  = 'If you have any questions or want to change something, reach us'.($phone_contact !== '' ? ' by phone at '.$phone_contact : '').($phone_contact !== '' && $mail_contact !== '' ? ' or' : '').($mail_contact !== '' ? ' by email at '.$mail_contact : '').'.';
		$closing  = 'See you soon at '.$brand.'!';
		$sign     = 'The '.$brand.' team';
		$legal_h  = 'Provider information';
		$imprint_l = 'Legal notice'; $privacy_l = 'Privacy policy';
		$auto     = 'This email was sent automatically after your online reservation.';
	}

	$legal = bm_legal_lines($d['property']);
	$imprint_url = !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '';
	$privacy_url = !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : '';

	// ---- plain text
	$p  = $greeting."\r\n\r\n".$intro."\r\n\r\n".$head."\r\n";
	foreach ($rows as $k => $v) { $p .= '  '.$k.': '.$v."\r\n"; }
	$p .= "\r\n".$cancel_t."\r\n".$cancel."\r\n\r\n".$contact."\r\n\r\n".$closing."\r\n".$sign."\r\n";
	$p .= "\r\n--\r\n".$legal_h."\r\n".implode("\r\n", $legal)."\r\n";
	if ($imprint_url !== '') { $p .= $imprint_l.': '.$imprint_url."\r\n"; }
	if ($privacy_url !== '') { $p .= $privacy_l.': '.$privacy_url."\r\n"; }
	$p .= "\r\n".$auto."\r\n";

	// ---- HTML (no images)
	$h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
	$font = "font-family:Arial,Helvetica,sans-serif;";
	$row_html = '';
	foreach ($rows as $k => $v) {
		$row_html .= '<tr><td style="'.$font.'font-size:14px;color:#777777;padding:6px 16px 6px 0;vertical-align:top;white-space:nowrap;">'.$h($k).'</td>'
			.'<td style="'.$font.'font-size:16px;color:#1c1a18;padding:6px 0;vertical-align:top;'.($k === 'Buchungsnummer' || $k === 'Booking number' ? 'font-weight:bold;letter-spacing:.04em;' : '').'">'.nl2br($h($v)).'</td></tr>';
	}
	$legal_html = implode('<br>', array_map($h, $legal));
	$links = array();
	if ($imprint_url !== '') { $links[] = '<a href="'.$h($imprint_url).'" style="color:#8a6d3b;">'.$h($imprint_l).'</a>'; }
	if ($privacy_url !== '') { $links[] = '<a href="'.$h($privacy_url).'" style="color:#8a6d3b;">'.$h($privacy_l).'</a>'; }
	$html = '<!DOCTYPE html><html lang="'.$lang.'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="'.$font.'padding:28px 32px 4px;font-size:12px;font-weight:bold;letter-spacing:.16em;text-transform:uppercase;color:#8a6d3b;">'.$h($brand).'</td></tr>'
		.'<tr><td style="font-family:Georgia,\'Times New Roman\',serif;padding:0 32px 8px;font-size:26px;line-height:1.25;color:#1c1a18;">'.$h($head).'</td></tr>'
		.'<tr><td style="'.$font.'padding:12px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($greeting).'<br><br>'.$h($intro).'</td></tr>'
		.'<tr><td style="padding:12px 32px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#faf8f3;border-left:3px solid #c9a259;"><tr><td style="padding:12px 18px;"><table role="presentation" cellpadding="0" cellspacing="0">'.$row_html.'</table></td></tr></table></td></tr>'
		.'<tr><td style="'.$font.'padding:8px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($cancel_t).'<br><a href="'.$h($cancel).'" style="color:#8a6d3b;font-weight:bold;">'.$h($cancel_l).'</a></td></tr>'
		.'<tr><td style="'.$font.'padding:12px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($contact).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 28px;font-size:16px;line-height:1.6;color:#333333;">'.$h($closing).'<br>'.$h($sign).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#8a8577;"><strong>'.$h($legal_h).'</strong><br>'.$legal_html
		.($links ? '<br>'.implode(' &middot; ', $links) : '').'<br><br>'.$h($auto).'</td></tr>'
		.'</table></td></tr></table></body></html>';

	// ---- notification for the restaurant
	$a_subject = ($de ? 'Neue Reservierung: ' : 'New reservation: ').$name.', '.$pax.($de ? ' Personen, ' : ' guests, ').$date_txt.', '.$time_txt;
	$a  = ($de ? 'Neue Reservierung' : 'New reservation').($d['origin'] === 'online' ? ' (online)' : ($de ? ' (Backend)' : ' (backend)'))."\r\n\r\n";
	$a .= ($de ? 'Buchungsnummer' : 'Booking number').': '.$number."\r\n";
	$a .= ($de ? 'Datum' : 'Date').': '.$date_txt."\r\n";
	$a .= ($de ? 'Uhrzeit' : 'Time').': '.$time_txt."\r\n";
	$a .= ($de ? 'Personen' : 'Guests').': '.$pax."\r\n";
	$a .= 'Name: '.$name."\r\n";
	$a .= ($de ? 'Telefon' : 'Phone').': '.($phone !== '' ? $phone : '-')."\r\n";
	$a .= 'E-Mail: '.($email !== '' ? $email : '-')."\r\n";
	if ($notes !== '') { $a .= ($de ? 'Notiz' : 'Note').': '.$notes."\r\n"; }
	if (!empty($form['reservation_booker_name']) && $d['origin'] !== 'online') { $a .= ($de ? 'Erfasst von' : 'Entered by').': '.bm_clean($form['reservation_booker_name'])."\r\n"; }

	return array('lang' => $lang, 'subject' => $subject, 'plain' => $p, 'html' => $html, 'admin_subject' => $a_subject, 'admin_text' => $a);
}
