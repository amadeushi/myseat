<?php
/*
 * Mails for orders of the delivery service: a confirmation to the guest (if an address was given) and a short notice to the
 * restaurant. Both leave from the address set in Einstellungen > Lieferservice; without it nothing is sent. Sending goes through
 * bm_send_guest_mail() like the reservation mails (local mail() or SMTP as configured).
 */
require_once __DIR__.'/booking_mail.class.php';

function shop_mail_item_lines($items) {
	$out = array();
	foreach ($items as $it) {
		$opts = array();
		foreach ((array)$it['options'] as $o) { $opts[] = ($o['qty'] > 1 ? $o['qty'].'× ' : '').$o['title']; }
		$out[] = array('head' => $it['qty'].'× '.$it['title'].($it['variation'] !== '' ? ' ('.$it['variation'].')' : ''), 'sub' => implode(', ', $opts), 'note' => $it['note'], 'price' => shop_money($it['line_cents']));
	}
	return $out;
}

function shop_notify_order($o) {
	global $settings;
	$from = shop_mail_from();
	if ($from === '') { return; }
	$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$delivery = ($o['type'] === 'delivery');
	$lines = shop_mail_item_lines(shop_order_items((int)$o['id']));
	$base = function_exists('shop_base_url') ? shop_base_url() : '';
	$link = $base !== '' ? $base.'/order/status.php?t='.$o['token'] : '';
	$when = $o['scheduled_at'] ? 'zur gewünschten Zeit um '.date('H:i', strtotime($o['scheduled_at'])).' Uhr' : ($o['eta_at'] ? 'gegen '.date('H:i', strtotime($o['eta_at'])).' Uhr' : '');
	$pay = $o['payment_method'] === 'mollie' ? 'online bezahlt' : (($o['payment_method'] === 'cash' ? 'bar' : 'mit Karte').($delivery ? ' bei Lieferung' : ' bei Abholung'));
	$font = 'font-family:Arial,Helvetica,sans-serif;';

	// ---- guest: a warm confirmation with the time in big letters, what was ordered, how it is paid, and where to follow it
	if ($o['email'] !== '') {
		$first = trim((string)strtok((string)$o['customer_name'], ' '));
		$timeTs = $o['scheduled_at'] ? strtotime($o['scheduled_at']) : ($o['eta_at'] ? strtotime($o['eta_at']) : 0);
		$clock = $timeTs ? date('H:i', $timeTs) : '';
		$timeLabel = $o['scheduled_at'] ? ($delivery ? 'Lieferung zur Wunschzeit um' : 'Abholung zur Wunschzeit um') : ($delivery ? 'Voraussichtlich bei dir um' : 'Abholbereit gegen');
		$phone = !empty($settings['mailPhone']) ? trim($settings['mailPhone']) : '';
		$total = shop_money($o['total_cents']);
		if ($o['payment_method'] === 'mollie') { $payLine = 'Bezahlt, danke dir!'; }
		elseif ($o['payment_method'] === 'cash') { $payLine = 'Bitte halte '.$total.' bereit ('.($delivery ? 'bar bei Lieferung' : 'bar bei Abholung').'), möglichst passend.'; }
		else { $payLine = $delivery ? 'Du zahlst '.$total.' mit Karte bei Lieferung, wir bringen das Kartengerät mit.' : 'Du zahlst '.$total.' mit Karte bei Abholung.'; }
		$lead = $delivery ? 'Wir haben deine Bestellung bekommen und legen los. Frisch aus der Küche ist sie gleich bei dir.' : 'Wir haben deine Bestellung bekommen und legen los. Alles ist frisch und warm für dich bereit, wenn du kommst.';
		$subject = $o['scheduled_at'] ? 'Deine Vorbestellung für '.$clock.' Uhr ist eingegangen ('.$o['number'].')' : 'Bestellung '.$o['number'].': '.($delivery ? 'Lieferung gegen ' : 'abholbereit gegen ').$clock.' Uhr';
		$where = $delivery ? $o['customer_name'].', '.$o['street'].', '.$o['zip'].' '.$o['city'].($o['address_note'] !== '' ? ' ('.$o['address_note'].')' : '') : 'Bei uns im '.$brand.'. Nenne beim Abholen deine Bestellnummer '.$o['number'].'.';
		$cell = 'font-size:15px;color:#1c1a18;padding:9px 0;border-bottom:1px solid #eee7d8;';
		$rows = '';
		foreach ($lines as $l) {
			$rows .= '<tr><td style="'.$font.$cell.'"><strong>'.$h($l['head']).'</strong>'.($l['sub'] !== '' ? '<br><span style="font-size:13px;color:#777;">'.$h($l['sub']).'</span>' : '').($l['note'] !== '' ? '<br><span style="font-size:13px;color:#8a6d3b;">Hinweis: '.$h($l['note']).'</span>' : '')
				.'</td><td align="right" style="'.$font.$cell.'white-space:nowrap;">'.$h($l['price']).'</td></tr>';
		}
		$sumRow = function ($label, $value, $big = false) use ($font, $h) {
			$st = $font.'font-size:'.($big ? '17px;color:#1c1a18;font-weight:bold;padding-top:8px;' : '14px;color:#777;padding-top:4px;');
			return '<tr><td style="'.$st.'">'.$h($label).'</td><td align="right" style="'.$st.'">'.$h($value).'</td></tr>';
		};
		$sums = $sumRow('Zwischensumme', shop_money($o['subtotal_cents'])).((int)$o['discount_cents'] > 0 ? $sumRow('Gutschein '.$o['coupon_code'], '-'.shop_money($o['discount_cents'])) : '').($delivery ? $sumRow('Liefergebühr', shop_money($o['fee_cents'])) : '').($o['tip_cents'] > 0 ? $sumRow('Trinkgeld', shop_money($o['tip_cents'])) : '').$sumRow('Gesamt', $total, true);
		$legal = bm_legal_lines(array());
		$imprint_url = !empty($settings['imprintUrl']) ? $settings['imprintUrl'] : '';
		$privacy_url = !empty($settings['privacyUrl']) ? $settings['privacyUrl'] : '';
		$terms_url = (!empty($settings['termsLink']) && preg_match('#^https?://#i', $settings['termsLink'])) ? $settings['termsLink'] : '';
		$legal_html = implode('<br>', array_map($h, $legal));
		$links = array();
		if ($terms_url !== '') { $links[] = '<a href="'.$h($terms_url).'" style="color:#8a6d3b;">AGB</a>'; }
		if ($imprint_url !== '') { $links[] = '<a href="'.$h($imprint_url).'" style="color:#8a6d3b;">Impressum</a>'; }
		if ($privacy_url !== '') { $links[] = '<a href="'.$h($privacy_url).'" style="color:#8a6d3b;">Datenschutz</a>'; }
		$footer_html = $legal
			? '<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#6e685c;"><strong>Angaben zum Anbieter</strong><br>'.$legal_html.($links ? '<br>'.implode(' &middot; ', $links) : '').'</td></tr>'
			: '';
		// same page background, card treatment and font stack as booking_mail.class.php/
		// feedback.class.php - this used to be its own uncoordinated third visual system
		$html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($subject).'</title></head><body style="margin:0;padding:0;background-color:#f4f1ea;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
			.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#ffffff;border:1px solid #e6e0d2;">'
			.'<tr><td style="padding:30px 32px 6px;"><div style="font-family:Georgia,\'Times New Roman\',serif;font-size:28px;color:#1c1a18;">Danke'.($first !== '' ? ', '.$h($first) : '').'!</div>'
			.'<div style="'.$font.'font-size:15px;color:#555;line-height:1.6;padding-top:8px;">'.$h($lead).'</div></td></tr>'
			.($clock !== '' ? '<tr><td style="padding:14px 32px 4px;"><div style="background:#f8f2e4;border:1px solid #e6d9b8;border-radius:10px;padding:14px 18px;"><div style="'.$font.'font-size:13px;color:#7a6a45;">'.$h($timeLabel).'</div>'
				.'<div style="font-family:Georgia,\'Times New Roman\',serif;font-size:40px;line-height:1.1;color:#6b5330;">'.$h($clock).' <span style="'.$font.'font-size:15px;color:#7a6a45;">Uhr</span></div></div></td></tr>' : '')
			.'<tr><td style="padding:10px 32px 0;"><div style="'.$font.'font-size:13px;color:#6e6e6e;text-transform:uppercase;letter-spacing:.06em;">'.($delivery ? 'Lieferung an' : 'Abholung').'</div><div style="'.$font.'font-size:15px;color:#1c1a18;line-height:1.5;padding-top:2px;">'.$h($where).'</div></td></tr>'
			.'<tr><td style="padding:16px 32px 4px;"><div style="'.$font.'font-size:13px;color:#6e6e6e;text-transform:uppercase;letter-spacing:.06em;padding-bottom:4px;">Deine Bestellung '.$h($o['number']).'</div>'
			.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'.$rows.$sums.'</table><div style="'.$font.'font-size:14px;color:#1c1a18;padding-top:12px;">'.$h($payLine).'</div></td></tr>'
			.($link !== '' ? '<tr><td style="padding:16px 32px 6px;"><a href="'.$h($link).'" style="'.$font.'display:inline-block;padding:13px 24px;background:#8a6d3b;border-radius:6px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">Bestellung verfolgen</a></td></tr>' : '')
			.'<tr><td style="padding:14px 32px 30px;"><div style="'.$font.'font-size:14px;color:#555;line-height:1.6;">Stimmt etwas nicht? Ruf uns kurz an'.($phone !== '' ? ': <a href="tel:'.$h(preg_replace('/\s+/', '', $phone)).'" style="color:#6b5330;">'.$h($phone).'</a>' : '').'.<br>Guten Appetit wünscht dein Team von '.$h($brand).'.</div></td></tr>'
			.$footer_html
			.'</table></td></tr></table></body></html>';
		$plain = 'Danke'.($first !== '' ? ', '.$first : '')."!\r\n\r\n".$lead."\r\n\r\n".($clock !== '' ? $timeLabel.' '.$clock." Uhr\r\n" : '').($delivery ? 'Lieferung an: ' : 'Abholung: ').$where."\r\n\r\nDeine Bestellung ".$o['number'].":\r\n";
		foreach ($lines as $l) { $plain .= $l['head'].'  '.$l['price']."\r\n".($l['sub'] !== '' ? '   '.$l['sub']."\r\n" : '').($l['note'] !== '' ? '   Hinweis: '.$l['note']."\r\n" : ''); }
		$plain .= "\r\nGesamt: ".$total."\r\n".$payLine."\r\n".($link !== '' ? "\r\nBestellung verfolgen: ".$link."\r\n" : '')."\r\nStimmt etwas nicht? Ruf uns kurz an".($phone !== '' ? ': '.$phone : '').".\r\nGuten Appetit wünscht dein Team von ".$brand.".\r\n";
		bm_send_guest_mail($o['email'], array('subject' => $subject, 'plain' => $plain, 'html' => $html, 'ics' => '', 'ics_filename' => ''), $brand, $from);
	}

	// ---- restaurant
	$plain = '#'.$o['day_no'].' '.($delivery ? 'Lieferung' : 'Abholung').' '.$when."\r\n".$o['customer_name'].', '.$o['phone']."\r\n".($delivery ? $o['street'].', '.$o['zip'].' '.$o['city'].($o['address_note'] !== '' ? ' ('.$o['address_note'].')' : '')."\r\n" : '')."\r\n";
	foreach ($lines as $l) { $plain .= $l['head'].($l['sub'] !== '' ? ' - '.$l['sub'] : '').($l['note'] !== '' ? ' ['.$l['note'].']' : '')."\r\n"; }
	$plain .= ($o['note'] !== '' ? "\r\nAnmerkung: ".$o['note']."\r\n" : '')."\r\nGesamt ".shop_money($o['total_cents']).', '.$pay."\r\n";
	$admin_subject = 'Neue Bestellung #'.$o['day_no'].' ('.($delivery ? 'Lieferung' : 'Abholung').', '.shop_money($o['total_cents']).')';
	$htmlR = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h($admin_subject).'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#ffffff;border:1px solid #e6e0d2;"><tr><td style="'.$font.'padding:24px 28px;font-size:15px;line-height:1.6;color:#1c1a18;">'.nl2br($h($plain)).'</td></tr></table>'
		.'</td></tr></table></body></html>';
	bm_send_guest_mail($from, array('subject' => $admin_subject, 'plain' => $plain, 'html' => $htmlR, 'ics' => '', 'ics_filename' => ''), $brand, $from);
}
