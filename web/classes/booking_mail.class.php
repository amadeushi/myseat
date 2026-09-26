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

// escape a value for use inside an .ics TEXT property (RFC 5545 §3.3.11)
function bm_ics_text($s) {
	$s = str_replace(array("\\", ";", ","), array("\\\\", "\\;", "\\,"), (string)$s);
	return str_replace(array("\r\n", "\r", "\n"), '\\n', $s);
}

// fold a "NAME:value" line to the 75-octet limit (RFC 5545 §3.1), continuation lines start with a space
function bm_ics_fold($line) {
	if (strlen($line) <= 75) { return $line."\r\n"; }
	$out = substr($line, 0, 75);
	$rest = substr($line, 75);
	while ($rest !== '') {
		$out .= "\r\n ".substr($rest, 0, 74);
		$rest = substr($rest, 74);
	}
	return $out."\r\n";
}

/*
 * The calendar invite attached to the guest mail: date, time (as a start/end span using the
 * outlet's average stay), location and a description with the booking number and the cancel /
 * website links. $ctx keys: lang, brand, address (one line or ''), date (Y-m-d), time (H:i[:s]),
 * duration (H:i:s, outlet avg_duration), pax, number, notes, cancel_url, website_url, uid_domain.
 */
function bm_ics_build($ctx) {
	$start = strtotime($ctx['date'].' '.$ctx['time']);
	if ($start === false) { return ''; }
	$parts = array_map('intval', explode(':', $ctx['duration'] !== '' ? $ctx['duration'] : '02:00:00'));
	$dur = (isset($parts[0]) ? $parts[0] : 2) * 3600 + (isset($parts[1]) ? $parts[1] : 0) * 60 + (isset($parts[2]) ? $parts[2] : 0);
	if ($dur <= 0) { $dur = 7200; }
	$end = $start + $dur;

	$de = ($ctx['lang'] === 'de');
	$summary = ($de ? 'Reservierung im ' : 'Reservation at ').$ctx['brand'].' ('.$ctx['pax'].($de ? ' Pers.)' : ' guests)');
	$desc = array();
	$desc[] = ($de ? 'Buchungsnummer' : 'Booking number').': '.$ctx['number'];
	$desc[] = ($de ? 'Personen' : 'Guests').': '.$ctx['pax'];
	if ($ctx['notes'] !== '') { $desc[] = ($de ? 'Anmerkung' : 'Note').': '.$ctx['notes']; }
	if ($ctx['cancel_url'] !== '') { $desc[] = ($de ? 'Stornieren' : 'Cancel').': '.$ctx['cancel_url']; }
	if ($ctx['website_url'] !== '') { $desc[] = ($de ? 'Webseite' : 'Website').': '.$ctx['website_url']; }

	$lines = array();
	$lines[] = 'BEGIN:VCALENDAR';
	$lines[] = 'VERSION:2.0';
	$lines[] = 'PRODID:-//mySeat//Reservation//'.strtoupper($ctx['lang']);
	$lines[] = 'CALSCALE:GREGORIAN';
	$lines[] = 'METHOD:PUBLISH';
	$lines[] = 'BEGIN:VEVENT';
	$lines[] = 'UID:'.$ctx['number'].'@'.$ctx['uid_domain'];
	$lines[] = 'DTSTAMP:'.gmdate('Ymd\THis\Z');
	$lines[] = 'DTSTART:'.gmdate('Ymd\THis\Z', $start);
	$lines[] = 'DTEND:'.gmdate('Ymd\THis\Z', $end);
	$lines[] = 'SUMMARY:'.bm_ics_text($summary);
	if ($ctx['address'] !== '') { $lines[] = 'LOCATION:'.bm_ics_text($ctx['address']); }
	$lines[] = 'DESCRIPTION:'.bm_ics_text(implode("\n", $desc));
	if ($ctx['website_url'] !== '') { $lines[] = 'URL:'.bm_ics_text($ctx['website_url']); }
	$lines[] = 'STATUS:CONFIRMED';
	$lines[] = 'TRANSP:OPAQUE';
	$lines[] = 'END:VEVENT';
	$lines[] = 'END:VCALENDAR';

	$out = '';
	foreach ($lines as $l) { $out .= bm_ics_fold($l); }
	return $out;
}

/*
 * Everything a guest needs before the visit, in the guest's language ('de' or anything else = English): menu
 * links, address with a destination-only route link (nothing about the guest goes into the URL), bus timetable,
 * parking, accessibility. Used by the mails (bm_build) and by the guest page api/cancel.php.
 */
function bm_guest_info($lang, $phone_contact, $property) {
	$de = ($lang === 'de');
	if ($de) {
		$g = array('info_h' => 'So kommst du gut an', 'addr_l' => 'Adresse', 'route_l' => 'Route planen', 'bus_key' => 'Mit dem Bus', 'bus_l' => 'Fahrplanauskunft',
			'menu_t' => 'Schau vorab, worauf du Lust hast:', 'menu_links' => array('Speisekarte' => 'https://amds.at/menu', 'Getränkekarte' => 'https://amds.at/drinks'),
			'info' => array(
				'Mit dem Bus' => 'Haltestellen Rathausstraße und Schuhstraße, fast alle Linien halten dort.',
				'Parken' => 'Tiefgaragen am Ratsbauhof und unter dem Marktplatz (Zufahrt Jakobistraße), Parkhaus Arnekengalerie. Am Straßenrand (Rathaus- und Osterstraße) kostenlos werktags ab 19 Uhr, samstags ab 16 Uhr.',
				'Barrierefreiheit' => 'Zwei Stufen am Eingang. Ruf vorab kurz an'.($phone_contact !== '' ? ' ('.$phone_contact.')' : '').', dann bauen wir eine Rollstuhlrampe auf und setzen dich ins Erdgeschoss. Barrierefreie öffentliche Toilette 10 Meter entfernt.',
			));
	} else {
		$g = array('info_h' => 'Getting here', 'addr_l' => 'Address', 'route_l' => 'Get directions', 'bus_key' => 'By bus', 'bus_l' => 'Timetable',
			'menu_t' => 'Take a look at what you fancy:', 'menu_links' => array('Food menu' => 'https://amds.at/menu', 'Drinks menu' => 'https://amds.at/drinks'),
			'info' => array(
				'By bus' => 'Stops Rathausstraße and Schuhstraße, served by almost all lines.',
				'Parking' => 'Underground car parks at Ratsbauhof and below the Marktplatz (entrance Jakobistraße), Arnekengalerie car park. Street parking (Rathaus- and Osterstraße) is free on weekdays from 7 pm and Saturdays from 4 pm.',
				'Accessibility' => 'Two steps at the entrance. Please call ahead'.($phone_contact !== '' ? ' ('.$phone_contact.')' : '').' and we will set up a wheelchair ramp and seat you on the ground floor. Accessible public toilet 10 metres away.',
			));
	}
	$g['addr_plain'] = trim(bm_clean(isset($property['street']) ? $property['street'] : '').', '.bm_clean(isset($property['zip']) ? $property['zip'] : '').' '.bm_clean(isset($property['city']) ? $property['city'] : ''), ' ,');
	$g['bus_url'] = 'https://www.svhi-hildesheim.de/de/Fahrplan/Fahrplanauskunft/';
	$g['route_url'] = $g['addr_plain'] !== '' ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($g['addr_plain']) : '';
	return $g;
}

/*
 * Build the mails. $d keys: form (the submitted booking form), outlet (selOutlet), property,
 * date (Y-m-d), time_text (formatted time), date_text (formatted date or range), booking_number,
 * cancel_url, origin ('online' | 'backend').
 * Returns array(lang, subject, plain, html, admin_subject, admin_text, admin_html, reply_to, ics, ics_filename).
 */
function bm_build($d) {
	global $settings;
	$form = $d['form'];
	$lang = bm_lang($form);
	$de = ($lang === 'de');
	// 'confirmed' (default, unchanged) | 'pending' (awaiting staff approval) | 'declined'
	$mode = isset($d['mode']) ? $d['mode'] : 'confirmed';
	// the person signing the guest mails ("Hamun vom Amadeus-Team"); optional $settings['mailSignName']
	$who = !empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun';
	// arrival, parking, accessibility and the menus only belong in a mail that confirms a table
	$with_info = in_array($mode, array('confirmed', 'approved', 'reminder'), true);

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
		$greeting = 'Hallo '.$name.',';
		$rows     = array('Datum' => $date_txt, 'Uhrzeit' => $time_txt, 'Personen' => (string)$pax, 'Buchungsnummer' => $number);
		if ($notes !== '') { $rows['Deine Anmerkung'] = $notes; }
		$contact  = 'Für Fragen oder Änderungswünsche erreichst du uns'.($phone_contact !== '' ? ' telefonisch unter '.$phone_contact : '').($phone_contact !== '' && $mail_contact !== '' ? ' oder' : '').($mail_contact !== '' ? ' per E-Mail an '.$mail_contact : '').'.';
		$closing  = 'Bis bald!';
		$sign     = $who.' vom '.$brand.'-Team';
		$legal_h  = 'Angaben zum Anbieter';
		$imprint_l = 'Impressum'; $privacy_l = 'Datenschutz';
		if ($mode === 'pending') {
			$subject  = 'Deine Anfrage für '.$pax.' Personen ist bei uns angekommen';
			$intro    = 'danke für dein Vertrauen! Größere Gruppen planen wir gern persönlich, damit am Abend alles passt. Wir melden uns so schnell wie möglich mit einer festen Zusage. Wenn du besondere Wünsche hast (Allergien, Kinderstühle, ein Anlass), antworte einfach auf diese Mail.';
			$head     = 'Deine Anfrage';
			$cancel_t = 'Hat sich etwas geändert? Du kannst deine Anfrage jederzeit zurückziehen:';
			$cancel_l = 'Anfrage zurückziehen';
			$auto     = 'Diese E-Mail wurde automatisch nach deiner Online-Anfrage versendet.';
		} elseif ($mode === 'declined') {
			$subject  = 'Zu deiner Anfrage für den '.$date_txt;
			$intro    = 'danke für deine Anfrage. Für diesen Abend können wir eure Gruppe leider nicht unterbringen, das tut uns wirklich leid. Wir helfen gern weiter: Wenn du magst, suchen wir gemeinsam nach einem Ausweichtermin. Melde dich einfach bei uns.';
			$head     = 'Deine Anfrage';
			$cancel_t = ''; $cancel_l = '';
			$auto     = 'Diese E-Mail wurde automatisch nach der Bearbeitung deiner Online-Anfrage versendet.';
		} elseif ($mode === 'reminder') {
			$subject  = 'Bis morgen! Dein Tisch um '.$time_txt;
			$intro    = 'morgen ist es so weit, wir freuen uns auf dich! Hier noch einmal das Wichtigste für die Anreise.';
			$head     = 'Bis morgen';
			$cancel_t = 'Schaffst du es doch nicht? Dann sag uns bitte jetzt kurz Bescheid, damit wir den Tisch weitergeben können:';
			$cancel_l = 'Reservierung stornieren';
			$auto     = 'Diese E-Mail wurde automatisch am Tag vor deiner Reservierung versendet.';
		} elseif ($mode === 'approved') {
			$subject  = 'Gute Nachrichten: Dein Tisch für '.$pax.' ist bestätigt';
			$intro    = 'wir haben deinen Tisch fest für euch eingeplant und freuen uns auf euch! Unten findest du alles, was du für die Anreise wissen musst.';
			$head     = 'Dein Tisch ist bestätigt';
			$cancel_t = 'Falls etwas dazwischenkommt, sag uns bitte kurz Bescheid, dann kann jemand anderes deinen Platz bekommen:';
			$cancel_l = 'Reservierung stornieren';
			$auto     = 'Diese E-Mail wurde automatisch nach der Bestätigung deiner Anfrage versendet.';
		} else {
			$subject  = 'Dein Tisch am '.$date_txt.' um '.$time_txt.' ist reserviert';
			$intro    = 'schön, dass du kommst! Dein Tisch für '.$pax.' Personen ist reserviert, wir freuen uns auf dich.';
			$head     = 'Dein Tisch ist reserviert';
			$cancel_t = 'Falls etwas dazwischenkommt, sag uns bitte kurz Bescheid, dann kann jemand anderes deinen Platz bekommen:';
			$cancel_l = 'Reservierung stornieren';
			$auto     = 'Diese E-Mail wurde automatisch nach deiner Online-Reservierung versendet.';
		}
	} else {
		$greeting = 'Hello '.$name.',';
		$rows     = array('Date' => $date_txt, 'Time' => $time_txt, 'Guests' => (string)$pax, 'Booking number' => $number);
		if ($notes !== '') { $rows['Your note'] = $notes; }
		$contact  = 'If you have any questions or want to change something, reach us'.($phone_contact !== '' ? ' by phone at '.$phone_contact : '').($phone_contact !== '' && $mail_contact !== '' ? ' or' : '').($mail_contact !== '' ? ' by email at '.$mail_contact : '').'.';
		$closing  = 'See you soon!';
		$sign     = $who.' from the '.$brand.' team';
		$legal_h  = 'Provider information';
		$imprint_l = 'Legal notice'; $privacy_l = 'Privacy policy';
		if ($mode === 'pending') {
			$subject  = 'Your request for '.$pax.' guests has reached us';
			$intro    = 'thank you for your trust! We like to plan larger groups personally so that everything works on the night. We will get back to you as soon as possible with a firm answer. If you have special wishes (allergies, high chairs, an occasion), just reply to this email.';
			$head     = 'Your request';
			$cancel_t = 'Something changed? You can withdraw your request at any time:';
			$cancel_l = 'Withdraw request';
			$auto     = 'This email was sent automatically after your online request.';
		} elseif ($mode === 'declined') {
			$subject  = 'About your request for '.$date_txt;
			$intro    = 'thank you for your request. Unfortunately we cannot fit your group in that evening, and we are truly sorry. We are happy to help: if you like, we can look for another date together. Just get in touch.';
			$head     = 'Your request';
			$cancel_t = ''; $cancel_l = '';
			$auto     = 'This email was sent automatically after your online request was reviewed.';
		} elseif ($mode === 'reminder') {
			$subject  = 'See you tomorrow! Your table at '.$time_txt;
			$intro    = 'tomorrow is the day, and we are looking forward to seeing you! Here is the most important information for getting here once more.';
			$head     = 'See you tomorrow';
			$cancel_t = 'Cannot make it after all? Please let us know now so we can pass the table on:';
			$cancel_l = 'Cancel reservation';
			$auto     = 'This email was sent automatically the day before your reservation.';
		} elseif ($mode === 'approved') {
			$subject  = 'Good news: your table for '.$pax.' is confirmed';
			$intro    = 'we have set your table aside and are looking forward to seeing you! Below you will find everything you need to know for getting here.';
			$head     = 'Your table is confirmed';
			$cancel_t = 'If something comes up, please let us know so someone else can take your seat:';
			$cancel_l = 'Cancel reservation';
			$auto     = 'This email was sent automatically after your request was confirmed.';
		} else {
			$subject  = 'Your table on '.$date_txt.' at '.$time_txt.' is reserved';
			$intro    = 'lovely that you are coming! Your table for '.$pax.' guests is reserved, and we are looking forward to seeing you.';
			$head     = 'Your table is reserved';
			$cancel_t = 'If something comes up, please let us know so someone else can take your seat:';
			$cancel_l = 'Cancel reservation';
			$auto     = 'This email was sent automatically after your online reservation.';
		}
	}

	// arrival, parking, accessibility, menus, address and route link: one source for the mail and the guest page (api/cancel.php)
	$gi = bm_guest_info($lang, $phone_contact, $d['property']);
	$info_h = $gi['info_h']; $info = $gi['info']; $addr_l = $gi['addr_l']; $route_l = $gi['route_l']; $bus_key = $gi['bus_key']; $bus_l = $gi['bus_l'];
	$menu_t = $gi['menu_t']; $menu_links = $gi['menu_links']; $addr_plain = $gi['addr_plain']; $bus_url = $gi['bus_url']; $route_url = $gi['route_url'];

	$legal = bm_legal_lines($d['property']);
	$imprint_url = !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '';
	$privacy_url = !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : '';

	// ---- plain text
	$p  = $greeting."\r\n\r\n".$intro."\r\n\r\n".$head."\r\n";
	foreach ($rows as $k => $v) { $p .= '  '.$k.': '.$v."\r\n"; }
	$p .= "\r\n";
	if ($cancel_t !== '') { $p .= $cancel_t."\r\n".$cancel."\r\n\r\n"; }
	if ($with_info) {
		$p .= $menu_t."\r\n";
		foreach ($menu_links as $k => $v) { $p .= '  '.$k.': '.$v."\r\n"; }
		$p .= "\r\n".$info_h."\r\n";
		if ($route_url !== '') { $p .= '- '.$addr_l.': '.$addr_plain."\r\n  ".$route_l.': '.$route_url."\r\n"; }
		foreach ($info as $k => $v) {
			$p .= '- '.$k.': '.$v."\r\n";
			if ($k === $bus_key) { $p .= '  '.$bus_l.': '.$bus_url."\r\n"; }
		}
		$p .= "\r\n";
	}
	$p .= $contact."\r\n\r\n".$closing."\r\n".$sign."\r\n";
	$p .= "\r\n--\r\n".$legal_h."\r\n".implode("\r\n", $legal)."\r\n";
	if ($imprint_url !== '') { $p .= $imprint_l.': '.$imprint_url."\r\n"; }
	if ($privacy_url !== '') { $p .= $privacy_l.': '.$privacy_url."\r\n"; }
	$p .= "\r\n".$auto."\r\n";

	// ---- HTML (no images)
	$h = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
	// make the restaurant's phone number tappable (tel: link) wherever it appears in an already
	// escaped text, however it is spelled there (with or without spaces/dashes)
	$phone_digits = preg_replace('/\D/', '', (string)$phone_contact);
	$tel = function ($escaped) use ($phone_digits) {
		if (strlen($phone_digits) < 6) { return $escaped; }
		$pattern = '/'.implode('[ \-]?', str_split($phone_digits)).'/';
		$href = 'tel:'.(substr($phone_digits, 0, 1) === '0' ? '+49'.substr($phone_digits, 1) : '+'.$phone_digits);
		return preg_replace_callback($pattern, function ($m) use ($href) {
			return '<a href="'.$href.'" style="color:#8a6d3b;font-weight:bold;white-space:nowrap;">'.$m[0].'</a>';
		}, $escaped);
	};
	$font = "font-family:Arial,Helvetica,sans-serif;";
	$row_html = '';
	foreach ($rows as $k => $v) {
		$row_html .= '<tr><td style="'.$font.'font-size:14px;color:#777777;padding:6px 16px 6px 0;vertical-align:top;white-space:nowrap;">'.$h($k).'</td>'
			.'<td style="'.$font.'font-size:16px;color:#1c1a18;padding:6px 0;vertical-align:top;'.($k === 'Buchungsnummer' || $k === 'Booking number' ? 'font-weight:bold;letter-spacing:.04em;' : '').'">'.nl2br($h($v)).'</td></tr>';
	}
	$legal_html = $tel(implode('<br>', array_map($h, $legal)));
	$links = array();
	if ($imprint_url !== '') { $links[] = '<a href="'.$h($imprint_url).'" style="color:#8a6d3b;">'.$h($imprint_l).'</a>'; }
	if ($privacy_url !== '') { $links[] = '<a href="'.$h($privacy_url).'" style="color:#8a6d3b;">'.$h($privacy_l).'</a>'; }

	$label_css = $font.'font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#6b5330;';
	$btn = function ($url, $label) use ($h, $font) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td align="center" style="border:2px solid #8a6d3b;border-radius:6px;">'
			.'<a href="'.$h($url).'" style="'.$font.'display:block;padding:12px 10px;font-size:15px;font-weight:bold;line-height:1.2;color:#6b5330;text-decoration:none;">'.$h($label).'</a></td></tr></table>';
	};
	$menu_html = '';
	$info_html = '';
	if ($with_info) {
		// menus: two clear buttons side by side
		$cells = array();
		foreach ($menu_links as $k => $v) { $cells[] = '<td width="50%" style="padding:0 4px;">'.$btn($v, $k).'</td>'; }
		$menu_html = '<tr><td style="'.$font.'padding:20px 32px 8px;font-size:16px;line-height:1.6;color:#333333;">'.$h($menu_t).'</td></tr>'
			.'<tr><td style="padding:0 28px 8px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr>'.implode('', $cells).'</tr></table></td></tr>';

		// arrival: last and compact, one labelled row per topic
		$irow = function ($label, $body_html) use ($h, $label_css, $font) {
			return '<tr><td valign="top" style="'.$label_css.'padding:9px 14px 9px 0;width:104px;">'.$h($label).'</td>'
				.'<td valign="top" style="'.$font.'font-size:15px;line-height:1.55;color:#333333;padding:9px 0;">'.$body_html.'</td></tr>';
		};
		$rows_html = '';
		if ($route_url !== '') {
			$rows_html .= $irow($addr_l, $h($addr_plain).'<br><a href="'.$h($route_url).'" style="color:#6b5330;font-weight:bold;">'.$h($route_l).'</a>');
		}
		foreach ($info as $k => $v) {
			$extra = ($k === $bus_key) ? '<br><a href="'.$h($bus_url).'" style="color:#6b5330;font-weight:bold;">'.$h($bus_l).'</a>' : '';
			$rows_html .= $irow($k, $tel($h($v)).$extra);
		}
		$info_html = '<tr><td style="padding:16px 32px 8px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-top:1px solid #e6e0d2;"><tr><td style="padding-top:16px;">'
			.'<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:20px;color:#1c1a18;padding-bottom:4px;">'.$h($info_h).'</div>'
			.'<table role="presentation" cellpadding="0" cellspacing="0" width="100%">'.$rows_html.'</table></td></tr></table></td></tr>';
	}
	$html ='<!DOCTYPE html><html lang="'.$lang.'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="'.$font.'padding:28px 32px 4px;font-size:12px;font-weight:bold;letter-spacing:.16em;text-transform:uppercase;color:#8a6d3b;">'.$h($brand).'</td></tr>'
		.'<tr><td style="font-family:Georgia,\'Times New Roman\',serif;padding:0 32px 8px;font-size:26px;line-height:1.25;color:#1c1a18;">'.$h($head).'</td></tr>'
		.'<tr><td style="'.$font.'padding:12px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($greeting).'<br><br>'.$h($intro).'</td></tr>'
		.'<tr><td style="padding:12px 32px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#faf8f3;border:1px solid #e6e0d2;border-radius:6px;"><tr><td style="padding:12px 18px;"><table role="presentation" cellpadding="0" cellspacing="0">'.$row_html.'</table></td></tr></table></td></tr>'
		.($cancel_t !== '' ? '<tr><td style="'.$font.'padding:8px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$h($cancel_t).'<br><a href="'.$h($cancel).'" style="color:#8a6d3b;font-weight:bold;">'.$h($cancel_l).'</a></td></tr>' : '')
		.$menu_html
		.$info_html
		.'<tr><td style="'.$font.'padding:12px 32px 4px;font-size:16px;line-height:1.6;color:#333333;">'.$tel($h($contact)).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 28px;font-size:16px;line-height:1.6;color:#333333;">'.$h($closing).'<br>'.$h($sign).'</td></tr>'
		.'<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#8a8577;"><strong>'.$h($legal_h).'</strong><br>'.$legal_html
		.($links ? '<br>'.implode(' &middot; ', $links) : '').'<br><br>'.$h($auto).'</td></tr>'
		.'</table></td></tr></table></body></html>';

	// ---- calendar invite (date, time, location; cancel and website links in the description)
	$website_url = '';
	if (!empty($d['property']['website'])) {
		$website_url = bm_clean($d['property']['website']);
		if (!preg_match('#^https?://#i', $website_url)) { $website_url = 'https://'.$website_url; }
	}
	$address_line = trim(bm_clean(isset($d['property']['street']) ? $d['property']['street'] : '').', '.bm_clean(isset($d['property']['zip']) ? $d['property']['zip'] : '').' '.bm_clean(isset($d['property']['city']) ? $d['property']['city'] : ''), ' ,');
	$location = trim($brand.($address_line !== '' ? ', '.$address_line : ''), ' ,');
	$uid_host = 'myseat.local';
	if ($cancel !== '' && ($host = parse_url($cancel, PHP_URL_HOST))) { $uid_host = $host; }
	// no calendar invite for a pending/declined request - there is nothing confirmed to put on
	// the guest's calendar yet
	$ics = ($with_info && $mode !== 'reminder') ? bm_ics_build(array(
		'lang' => $lang, 'brand' => $brand, 'address' => $location,
		'date' => $d['date'], 'time' => $form['reservation_time'],
		'duration' => isset($d['outlet']['avg_duration']) ? $d['outlet']['avg_duration'] : '',
		'pax' => $pax, 'number' => $number, 'notes' => $notes,
		'cancel_url' => $cancel, 'website_url' => $website_url, 'uid_domain' => $uid_host,
	)) : '';
	$ics_filename = ($de ? 'reservierung' : 'reservation').'-'.$number.'.ics';

	// ---- notification for the restaurant (plain text + HTML). $d['request_url'] (signed page to
	// look at and decide a pending request) and $d['backend_url'] (day view in the backend) are optional.
	$request_url = isset($d['request_url']) ? $d['request_url'] : '';
	$backend_url = isset($d['backend_url']) ? $d['backend_url'] : '';
	$pending = ($mode === 'pending');
	$online  = ($d['origin'] === 'online');
	$booker  = (!empty($form['reservation_booker_name']) && !$online) ? bm_clean($form['reservation_booker_name']) : '';
	$a_subject = ($pending
		? ($de ? 'Entscheidung nötig: ' : 'Decision needed: ')
		: ($de ? 'Neue Reservierung: ' : 'New reservation: ')).$name.', '.$pax.($de ? ' Personen, ' : ' guests, ').$date_txt.', '.$time_txt;

	$L = $de
		? array('badge_new' => 'Neue Reservierung', 'badge_req' => 'Entscheidung nötig', 'head_new' => 'Reservierung für '.$pax.' Personen', 'head_req' => 'Anfrage für '.$pax.' Personen',
			'date' => 'Datum', 'time' => 'Uhrzeit', 'pax' => 'Personen', 'guest' => 'Gast', 'phone' => 'Telefon', 'note' => 'Notiz des Gastes', 'nr' => 'Buchungsnummer',
			'source' => 'Quelle', 'online' => 'Online-Formular', 'backend' => 'Backend', 'by' => 'Erfasst von',
			'req_text' => 'Die Plätze sind für diese Anfrage bereits geblockt. Der Gast wartet auf deine Antwort: mit einem Klick bestätigst oder lehnst du ab, und er bekommt automatisch die passende Mail.',
			'new_text' => 'Der Tisch ist zugesagt, der Gast hat seine Bestätigung bereits erhalten.',
			'cta_req' => 'Anfrage ansehen & entscheiden', 'cta_new' => 'Im Backend öffnen', 'backend' => 'Backend', 'open_backend' => 'Oder direkt im Backend öffnen',
			'foot' => 'Automatische Nachricht von mySeat. Wenn du auf diese Mail antwortest, geht die Antwort direkt an den Gast.', 'none' => '-')
		: array('badge_new' => 'New reservation', 'badge_req' => 'Decision needed', 'head_new' => 'Reservation for '.$pax.' guests', 'head_req' => 'Request for '.$pax.' guests',
			'date' => 'Date', 'time' => 'Time', 'pax' => 'Guests', 'guest' => 'Guest', 'phone' => 'Phone', 'note' => 'Note from the guest', 'nr' => 'Booking number',
			'source' => 'Source', 'online' => 'Online form', 'backend' => 'Backend', 'by' => 'Entered by',
			'req_text' => 'The seats for this request are already blocked. The guest is waiting for your answer: confirm or decline with one click and they automatically get the matching email.',
			'new_text' => 'The table is confirmed and the guest has already received their confirmation.',
			'cta_req' => 'View request & decide', 'cta_new' => 'Open in backend', 'backend' => 'Backend', 'open_backend' => 'Or open it directly in the backend',
			'foot' => 'Automatic message from mySeat. If you reply to this email, the reply goes straight to the guest.', 'none' => '-');

	$a  = $pending
		? ($de ? 'Neue Reservierungsanfrage - deine Entscheidung ist nötig' : 'New reservation request - your decision is needed')."\r\n\r\n"
		: ($de ? 'Neue Reservierung' : 'New reservation').($online ? ' (online)' : ($de ? ' (Backend)' : ' (backend)'))."\r\n\r\n";
	$a .= $L['nr'].': '.$number."\r\n";
	$a .= $L['date'].': '.$date_txt."\r\n";
	$a .= $L['time'].': '.$time_txt."\r\n";
	$a .= $L['pax'].': '.$pax."\r\n";
	$a .= 'Name: '.$name."\r\n";
	$a .= $L['phone'].': '.($phone !== '' ? $phone : '-')."\r\n";
	$a .= 'E-Mail: '.($email !== '' ? $email : '-')."\r\n";
	if ($notes !== '') { $a .= ($de ? 'Notiz' : 'Note').': '.$notes."\r\n"; }
	if ($booker !== '') { $a .= $L['by'].': '.$booker."\r\n"; }
	if ($pending && $request_url !== '') { $a .= "\r\n".$L['cta_req'].":\r\n".$request_url."\r\n"; }
	if ($backend_url !== '') { $a .= "\r\n".($pending ? $L['open_backend'] : $L['cta_new']).":\r\n".$backend_url."\r\n"; }

	$primary_url = $pending ? $request_url : $backend_url;
	$btn = function ($url, $label, $primary) use ($h, $font) {
		return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;"><tr><td align="center" style="border-radius:6px;background-color:'.($primary ? '#8a6d3b' : '#ffffff').';'.($primary ? '' : 'border:2px solid #8a6d3b;').'">'
			.'<a href="'.$h($url).'" style="'.$font.'display:inline-block;padding:14px 26px;font-size:16px;font-weight:bold;line-height:1.2;color:'.($primary ? '#ffffff' : '#6b5330').';text-decoration:none;border-radius:6px;">'.$h($label).'</a></td></tr></table>';
	};
	$fact = function ($label, $value) use ($h, $font) {
		return '<td valign="top" style="padding:0 20px 0 0;"><div style="'.$font.'font-size:11px;font-weight:bold;letter-spacing:.1em;text-transform:uppercase;color:#8a8577;">'.$h($label).'</div>'
			.'<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:22px;line-height:1.3;color:#1c1a18;padding-top:2px;white-space:nowrap;">'.$h($value).'</div></td>';
	};
	$detail = function ($label, $value_html) use ($h, $font) {
		return '<tr><td style="'.$font.'font-size:14px;color:#777777;padding:7px 16px 7px 0;vertical-align:top;white-space:nowrap;">'.$h($label).'</td>'
			.'<td style="'.$font.'font-size:16px;line-height:1.45;color:#1c1a18;padding:7px 0;vertical-align:top;">'.$value_html.'</td></tr>';
	};
	$tel_href = preg_replace('/[^\d+]/', '', $phone);
	if (substr($tel_href, 0, 2) === '00') { $tel_href = '+'.substr($tel_href, 2); } elseif (substr($tel_href, 0, 1) === '0') { $tel_href = '+49'.substr($tel_href, 1); }
	$details = $detail($L['guest'], '<strong>'.$h($name).'</strong>')
		.$detail($L['phone'], $phone !== '' ? '<a href="tel:'.$h($tel_href).'" style="color:#6b5330;font-weight:bold;text-decoration:underline;">'.$h($phone).'</a>' : $h($L['none']))
		.$detail('E-Mail', $email !== '' ? '<a href="mailto:'.$h($email).'" style="color:#6b5330;text-decoration:underline;">'.$h($email).'</a>' : $h($L['none']))
		.$detail($L['nr'], '<span style="font-weight:bold;letter-spacing:.04em;">'.$h($number).'</span>')
		.$detail($L['source'], $h($online ? $L['online'] : $L['backend'].($booker !== '' ? ' ('.$L['by'].' '.$booker.')' : '')));
	$note_html = $notes !== ''
		? '<tr><td style="padding:4px 32px 12px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#fff8e6;border:1px solid #ecd9a6;border-radius:6px;"><tr><td style="'.$font.'padding:12px 16px;font-size:15px;line-height:1.55;color:#333333;"><strong style="display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#8a6d3b;padding-bottom:3px;">'.$h($L['note']).'</strong>'.nl2br($h($notes)).'</td></tr></table></td></tr>'
		: '';
	$badge_bg = $pending ? '#fbe9c0' : '#e4efe1';
	$badge_fg = $pending ? '#6b4a00' : '#2c5a2a';
	$a_html = '<!DOCTYPE html><html lang="'.$lang.'"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($a_subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'
		.'<div style="display:none;max-height:0;overflow:hidden;opacity:0;">'.$h($name.', '.$pax.' - '.$date_txt.', '.$time_txt).'</div>'
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="width:100%;max-width:560px;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.'<tr><td style="'.$font.'padding:26px 32px 0;"><span style="display:inline-block;padding:5px 12px;border-radius:999px;background-color:'.$badge_bg.';color:'.$badge_fg.';font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;">'.$h($pending ? $L['badge_req'] : $L['badge_new']).'</span>'
		.' <span style="font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:#8a6d3b;font-weight:bold;">&nbsp;'.$h($brand).'</span></td></tr>'
		.'<tr><td style="font-family:Georgia,\'Times New Roman\',serif;padding:12px 32px 6px;font-size:26px;line-height:1.25;color:#1c1a18;">'.$h($pending ? $L['head_req'] : $L['head_new']).'</td></tr>'
		.'<tr><td style="padding:10px 32px 16px;"><table role="presentation" cellpadding="0" cellspacing="0"><tr>'.$fact($L['date'], $date_txt).$fact($L['time'], $time_txt).$fact($L['pax'], (string)$pax).'</tr></table></td></tr>'
		.'<tr><td style="padding:0 32px 8px;"><table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-top:1px solid #e6e0d2;"><tr><td style="padding-top:8px;"><table role="presentation" cellpadding="0" cellspacing="0">'.$details.'</table></td></tr></table></td></tr>'
		.$note_html
		.'<tr><td style="'.$font.'padding:8px 32px 16px;font-size:15px;line-height:1.6;color:#333333;">'.$h($pending ? $L['req_text'] : $L['new_text']).'</td></tr>'
		.($primary_url !== '' ? '<tr><td style="padding:0 32px 8px;">'.$btn($primary_url, $pending ? $L['cta_req'] : $L['cta_new'], $pending).'</td></tr>' : '')
		.($pending && $backend_url !== '' ? '<tr><td style="'.$font.'padding:8px 32px 0;font-size:14px;line-height:1.6;color:#555555;"><a href="'.$h($backend_url).'" style="color:#6b5330;text-decoration:underline;">'.$h($L['open_backend']).'</a></td></tr>' : '')
		.'<tr><td style="'.$font.'padding:22px 32px 24px;font-size:12px;line-height:1.6;color:#8a8577;">'.$h($L['foot']).'</td></tr>'
		.'</table></td></tr></table></body></html>';

	return array('lang' => $lang, 'subject' => $subject, 'plain' => $p, 'html' => $html, 'admin_subject' => $a_subject, 'admin_text' => $a, 'admin_html' => $a_html, 'reply_to' => $email, 'ics' => $ics, 'ics_filename' => $ics_filename);
}

/*
 * Send the guest-facing mail built by bm_build() - the confirmation/pending/decline mail, with
 * the .ics attachment when there is one. Shared by the initial-booking hook
 * (plugins/local_email_send.plugin.php) and web/ajax/reservation_approval_action.php, which sends
 * the real confirmation or decline mail later, once staff have made a decision on a pending
 * request. $to_guest: guest email. $m: bm_build() result. $brand: outlet/property name.
 * $admin_email: the outlet's own address, used as the From/Reply-To.
 */
function bm_send_guest_mail($to_guest, $m, $brand, $admin_email) {
	global $settings;
	if ($to_guest === '' || !filter_var($to_guest, FILTER_VALIDATE_EMAIL) || $admin_email === '') { return; }

	$subject_guest = mb_encode_mimeheader($m['subject'], 'UTF-8', 'B');
	$from = mb_encode_mimeheader($brand, 'UTF-8', 'B').' <'.$admin_email.'>';

	if (isset($settings['emailSMTP']) && $settings['emailSMTP'] != 'LOCAL') {
		require_once __DIR__ . '/phpmailer/class.phpmailer.php';
		$mail = new PHPMailer();
		if ($settings['emailSMTP'] == 'SMTP') { $mail->IsSMTP(); } else { $mail->IsSendmail(); }
		$mail->SMTPAuth      = true;
		$mail->SMTPKeepAlive = true;
		$mail->CharSet       = 'UTF-8';
		$mail->Host          = $settings['emailHost'];
		$mail->Port          = $settings['emailPort'];
		$mail->Username      = $settings['emailUser'];
		$mail->Password      = $settings['emailPass'];
		$mail->SetFrom($admin_email, $brand);
		$mail->AddReplyTo($admin_email, $brand);
		$mail->Subject = $m['subject'];
		$mail->AltBody = $m['plain'];
		$mail->MsgHTML($m['html']);
		$mail->AddAddress($to_guest);
		if (!empty($m['ics'])) {
			$mail->AddStringAttachment($m['ics'], $m['ics_filename'], 'base64', 'text/calendar; method=PUBLISH; charset=UTF-8');
		}
		if (!$mail->Send()) { error_log('mySeat mail to guest failed: '.$mail->ErrorInfo); }
	} else {
		$alt_boundary = '=_'.md5(uniqid('', true));
		$alt  = "--".$alt_boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['plain']));
		$alt .= "--".$alt_boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['html']));
		$alt .= "--".$alt_boundary."--\r\n";

		$headers  = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nReply-To: ".$from."\r\nX-Mailer: mySeat\r\n";
		if (!empty($m['ics'])) {
			$mix_boundary = '=_'.md5(uniqid('', true));
			$headers .= "Content-Type: multipart/mixed; boundary=\"".$mix_boundary."\"\r\n";
			$body  = "--".$mix_boundary."\r\nContent-Type: multipart/alternative; boundary=\"".$alt_boundary."\"\r\n\r\n".$alt;
			$body .= "--".$mix_boundary."\r\nContent-Type: text/calendar; method=PUBLISH; charset=UTF-8; name=\"".$m['ics_filename']."\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"".$m['ics_filename']."\"\r\n\r\n".chunk_split(base64_encode($m['ics']));
			$body .= "--".$mix_boundary."--\r\n";
		} else {
			$headers .= "Content-Type: multipart/alternative; boundary=\"".$alt_boundary."\"\r\n";
			$body = $alt;
		}
		mail($to_guest, $subject_guest, $body, $headers);
	}
}

/*
 * Send the restaurant notification built by bm_build() (HTML + plain text). Replies go straight
 * to the guest (Reply-To = guest email) so the team can answer with one click.
 */
function bm_send_admin_mail($to_admin, $m, $brand) {
	global $settings;
	if ($to_admin === '' || !filter_var($to_admin, FILTER_VALIDATE_EMAIL)) { return; }
	$reply = (!empty($m['reply_to']) && filter_var($m['reply_to'], FILTER_VALIDATE_EMAIL)) ? $m['reply_to'] : '';

	if (isset($settings['emailSMTP']) && $settings['emailSMTP'] != 'LOCAL') {
		require_once __DIR__ . '/phpmailer/class.phpmailer.php';
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
		$mail->AddReplyTo($reply !== '' ? $reply : $to_admin, $reply !== '' ? '' : $brand);
		$mail->Subject = $m['admin_subject'];
		$mail->AltBody = $m['admin_text'];
		$mail->MsgHTML($m['admin_html']);
		$mail->AddAddress($to_admin);
		if (!$mail->Send()) { error_log('mySeat mail to restaurant failed: '.$mail->ErrorInfo); }
	} else {
		$boundary = '=_'.md5(uniqid('', true));
		$from = mb_encode_mimeheader($brand, 'UTF-8', 'B').' <'.$to_admin.'>';
		$headers  = "MIME-Version: 1.0\r\nFrom: ".$from."\r\nReply-To: ".($reply !== '' ? $reply : $from)."\r\nX-Mailer: mySeat\r\n";
		$headers .= "Content-Type: multipart/alternative; boundary=\"".$boundary."\"\r\n";
		$body  = "--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['admin_text']));
		$body .= "--".$boundary."\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($m['admin_html']));
		$body .= "--".$boundary."--\r\n";
		mail($to_admin, mb_encode_mimeheader($m['admin_subject'], 'UTF-8', 'B'), $body, $headers);
	}
}
