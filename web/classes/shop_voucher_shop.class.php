<?php
/*
 * The voucher shop for guests: an order (amount, motif, names, message, form of delivery), the online payment at Mollie, the activation of the voucher when the payment is confirmed,
 * the mail with the PDF, the fulfilment of numbered blanks (post / pick-up), the withdrawal and its refund.
 * The voucher exists as "pending" from the moment of the order and is usable only after the payment arrived (gv_activate). Everything here is behind the setting gv_on.
 * Mehrzweck-Gutschein: the sale carries no value added tax, it arises when the voucher is redeemed (decision of the owner; the tax adviser confirms). The postage of a blank is a separate fee.
 */

const GV_WITHDRAW_DAYS = 14;
const GV_ORDERS_PER_HOUR = 8;     // orders per address and hour (every order makes a pending voucher)

function gv_cfg() {
	$max = min(GV_MAX_CENTS, max(100, (int)round((float)str_replace(',', '.', (string)shop_setting('gv_max_eur')) * 100)));
	$min = max(100, (int)round((float)str_replace(',', '.', (string)shop_setting('gv_min_eur')) * 100));
	$amounts = array();
	foreach (explode(',', (string)shop_setting('gv_amounts')) as $a) { $c = (int)round((float)str_replace(',', '.', trim($a)) * 100); if ($c >= 100 && $c <= $max) { $amounts[] = $c; } }
	sort($amounts); $amounts = array_values(array_unique($amounts));
	return array('on' => shop_flag('gv_on'), 'min' => min($min, $max), 'max' => $max, 'amounts' => $amounts, 'fee' => max(0, (int)round((float)str_replace(',', '.', (string)shop_setting('gv_post_fee')) * 100)),
		'forms' => array('pdf' => shop_flag('gv_pdf_on'), 'post' => shop_flag('gv_post_on'), 'pickup' => shop_flag('gv_pickup_on')), 'lapse' => shop_setting('gv_withdraw_mode') === 'lapse',
		'post_mode' => ep_active() ? 'echtpost' : (shop_setting('gv_post_mode') === 'blank' ? 'blank' : 'print'),   // print = the restaurant prints the personal card (backend: print file), blank = numbered blank filled by hand, echtpost = postcard printed and sent by EchtPost
		'own' => shop_flag('gv_own_motif'), 'terms_ok' => shop_flag('gv_terms_ok'), 'open' => shop_flag('gv_on') && shop_flag('gv_terms_ok'));   // open = switched on AND the owner has checked the wording of the pages
}
function gv_form_label($d) { $m = array('pdf' => 'PDF per E-Mail', 'post' => 'Per Post', 'pickup' => 'Abholung im Restaurant'); return isset($m[$d]) ? $m[$d] : $d; }

function gv_order_by_token($t) { shop_ensure_schema(); $t = preg_replace('/[^a-f0-9]/', '', (string)$t); return strlen($t) === 32 ? fb_row("SELECT * FROM ".fb_t('tp_gv_orders')." WHERE token = ?", 's', array($t)) : null; }
function gv_order_by_id($id) { shop_ensure_schema(); return fb_row("SELECT * FROM ".fb_t('tp_gv_orders')." WHERE id = ?", 'i', array((int)$id)); }
function gv_order_by_number($n, $email) {
	shop_ensure_schema();
	return fb_row("SELECT * FROM ".fb_t('tp_gv_orders')." WHERE number = ? AND buyer_email = ?", 'ss', array(strtoupper(trim((string)$n)), shop_nl_email($email)));
}

// unpaid orders disappear after three days: the order is canceled and its pending voucher blocked
function gv_order_gc() {
	foreach (fb_rows("SELECT id, voucher_id FROM ".fb_t('tp_gv_orders')." WHERE status = 'pending' AND created_at < ? LIMIT 50", 's', array(date('Y-m-d H:i:s', time() - 3 * 86400))) as $o) {
		$st = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET status = 'canceled', note = 'Zahlung nicht abgeschlossen' WHERE id = ? AND status = 'pending'", 'i', array((int)$o['id']));
		if ($st && mysqli_stmt_affected_rows($st) > 0) { gv_void((int)$o['voucher_id'], array(null, 'System'), 'Zahlung nicht abgeschlossen'); }
	}
}

/*
 * Make an order from the form. $in: delivery, amount (euro, "50" or "37,50"), motif, recipient, from, message, name, email, phone, ship_name, ship_street, ship_zip, ship_city, terms (bool), consent (bool).
 * Returns array(ok, error, field, order).
 */
function gv_order_create($in) {
	shop_ensure_schema();
	$cfg = gv_cfg();
	$bad = function ($field, $msg) { return array('ok' => false, 'field' => $field, 'error' => $msg); };
	if (!$cfg['open']) { return $bad('', 'Der Gutscheinverkauf ist gerade nicht geöffnet.'); }
	$d = (string)($in['delivery'] ?? '');
	if (!isset($cfg['forms'][$d]) || !$cfg['forms'][$d]) { return $bad('delivery', 'Bitte wähle, wie du den Gutschein bekommen möchtest.'); }
	$amount = gv_parse_amount($in['amount'] ?? '');
	if ($amount === null || $amount < $cfg['min'] || $amount > $cfg['max'] || $amount % 100 !== 0) { return $bad('amount', 'Bitte wähle einen Betrag in vollen Euro zwischen '.gv_money($cfg['min']).' und '.gv_money($cfg['max']).'.'); }
	$motif = (string)($in['motif'] ?? '');
	$isOwn = strpos($motif, 'own:') === 0;
	if ($isOwn ? (!$cfg['own'] || gv_motif_file($motif) === '') : !isset(gv_motifs_active()[$motif])) { return $bad('motif', 'Bitte wähle ein Motiv.'); }
	$name = trim((string)($in['name'] ?? '')); $email = shop_nl_email($in['email'] ?? '');
	if ($name === '' || mb_strlen($name) > 80) { return $bad('name', 'Bitte gib deinen Namen an.'); }
	if (!shop_nl_valid($email)) { return $bad('email', 'Bitte gib eine gültige E-Mail-Adresse an.'); }
	$ship = array('ship_name' => '', 'ship_street' => '', 'ship_zip' => '', 'ship_city' => '');
	if ($d === 'post') {
		$ship = array('ship_name' => trim((string)($in['ship_name'] ?? '')), 'ship_street' => trim((string)($in['ship_street'] ?? '')), 'ship_zip' => trim((string)($in['ship_zip'] ?? '')), 'ship_city' => trim((string)($in['ship_city'] ?? '')));
		if ($ship['ship_name'] === '' || $ship['ship_street'] === '' || !preg_match('/^\d{5}$/', $ship['ship_zip']) || $ship['ship_city'] === '') { return $bad('ship_name', 'Bitte gib die vollständige Lieferadresse an (Name, Straße, fünfstellige PLZ, Ort).'); }
	}
	if (empty($in['terms'])) { return $bad('terms', 'Bitte bestätige die Gutschein-Bedingungen.'); }
	$lapse = $cfg['lapse'] && $d === 'pdf';
	if ($lapse && empty($in['consent'])) { return $bad('consent', 'Bitte stimme der sofortigen Bereitstellung zu und bestätige den Hinweis zum Widerrufsrecht.'); }
	$ip = gv_ip();
	$r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_gv_orders')." WHERE consent_ip = ? AND created_at > ?", 'ss', array($ip, date('Y-m-d H:i:s', time() - 3600)));
	if ($ip !== '' && (int)$r['n'] >= GV_ORDERS_PER_HOUR) { return $bad('', 'Von deiner Adresse kamen gerade viele Bestellungen. Bitte versuche es später noch einmal.'); }
	gv_order_gc();

	$fee = $d === 'post' ? $cfg['fee'] : 0;
	// a printed card (own print file or EchtPost) carries the own digital number and code, a blank its printed number
	$v = gv_issue(array('kind' => ($d === 'pdf' || $cfg['post_mode'] !== 'blank') ? 'pdf' : 'print', 'face_cents' => $amount, 'status' => 'pending', 'buyer_name' => $name, 'buyer_email' => $email, 'recipient' => mb_substr(trim((string)($in['recipient'] ?? '')), 0, 80),
		'message' => mb_substr(trim((string)($in['message'] ?? '')), 0, 160), 'note' => 'Online-Bestellung', 'staff_name' => 'Online-Shop'));
	if (!$v['ok']) { return $bad('', 'Der Gutschein konnte nicht angelegt werden. Bitte versuche es noch einmal.'); }
	$token = bin2hex(random_bytes(16)); $now = gv_now();
	$st = fb_exec("INSERT INTO ".fb_t('tp_gv_orders')." (token, voucher_id, delivery, amount_cents, fee_cents, total_cents, motif, recipient, from_name, message, buyer_name, buyer_email, buyer_phone, ship_name, ship_street, ship_zip, ship_city,
		status, consent_lapse, consent_at, consent_ip, consent_ua, terms_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)", 'sisiiisssssssssssisssss', array(
		$token, (int)$v['voucher']['id'], $d, $amount, $fee, $amount + $fee, $motif, mb_substr(trim((string)($in['recipient'] ?? '')), 0, 80), mb_substr(trim((string)($in['from'] ?? '')), 0, 80), mb_substr(trim((string)($in['message'] ?? '')), 0, 160),
		$name, $email, mb_substr(trim((string)($in['phone'] ?? '')), 0, 40), mb_substr($ship['ship_name'], 0, 80), mb_substr($ship['ship_street'], 0, 120), $ship['ship_zip'], mb_substr($ship['ship_city'], 0, 80),
		$lapse ? 1 : 0, $lapse ? $now : null, $ip, substr(isset($_SERVER['HTTP_USER_AGENT']) ? preg_replace('/[\x00-\x1f]/', '', (string)$_SERVER['HTTP_USER_AGENT']) : '', 0, 200), $now, $now));
	if (!$st) { gv_void((int)$v['voucher']['id'], array(null, 'System'), 'Bestellung nicht angelegt'); return $bad('', 'Die Bestellung konnte nicht angelegt werden. Bitte versuche es noch einmal.'); }
	$id = (int)mysqli_insert_id(fb_db());
	fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET number = ? WHERE id = ?", 'si', array('G'.str_pad((string)$id, 5, '0', STR_PAD_LEFT), $id));
	fb_exec("UPDATE ".fb_t('tp_gv_vouchers')." SET order_id = ?, motif_id = NULL WHERE id = ?", 'ii', array($id, (int)$v['voucher']['id']));
	return array('ok' => true, 'order' => gv_order_by_id($id));
}

// ---- payment (Mollie) ----

// start the online payment: returns array(ok, url) - the guest is sent to Mollie's page
function gv_order_pay_start($order, $baseUrl) {
	$r = shop_mollie_request('POST', '/payments', array(
		'amount' => array('currency' => 'EUR', 'value' => number_format($order['total_cents'] / 100, 2, '.', '')),
		'description' => 'Gutschein '.$order['number'].' - Amadeus',
		'redirectUrl' => $baseUrl.'/order/gutschein_status.php?t='.$order['token'],
		'webhookUrl' => $baseUrl.'/order/mollie_webhook.php',
		'locale' => 'de_DE',
		'metadata' => array('gv_order' => $order['number']),
	));
	if (!in_array($r['http'], array(200, 201), true) || empty($r['body']['id']) || empty($r['body']['_links']['checkout']['href'])) {
		error_log('mySeat voucher payment: '.shop_mollie_describe($r));
		return array('ok' => false, 'error' => 'Die Online-Zahlung ist gerade nicht erreichbar. Bitte versuche es gleich noch einmal.');
	}
	fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET mollie_id = ? WHERE id = ?", 'si', array($r['body']['id'], (int)$order['id']));
	return array('ok' => true, 'url' => $r['body']['_links']['checkout']['href']);
}

// ask Mollie what happened to the payment of this order (webhook and the guest coming back); returns the order as it is now
function gv_order_sync($order) {
	if (!$order || empty($order['mollie_id']) || !in_array($order['status'], array('pending', 'canceled'), true)) { return $order; }   // a payment that arrives after the order was canceled (three days) still counts
	$r = shop_mollie_request('GET', '/payments/'.rawurlencode($order['mollie_id']));
	if ($r['http'] !== 200 || empty($r['body']['status'])) { return $order; }
	$st = $r['body']['status'];
	if (in_array($st, array('paid', 'authorized'), true)) {
		$paid = isset($r['body']['amount']['value']) ? (int)round((float)$r['body']['amount']['value'] * 100) : -1;
		if ($paid !== (int)$order['total_cents']) { error_log('mySeat voucher payment: amount differs for '.$order['number']); return $order; }
		gv_order_mark_paid((int)$order['id']);
	} elseif ($order['status'] === 'pending' && in_array($st, array('canceled', 'expired', 'failed'), true)) {
		$u = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET status = 'canceled', note = ? WHERE id = ? AND status = 'pending'", 'si', array('Zahlung '.$st, (int)$order['id']));
		if ($u && mysqli_stmt_affected_rows($u) > 0) { gv_void((int)$order['voucher_id'], array(null, 'System'), 'Zahlung '.$st); }
	}
	return gv_order_by_id((int)$order['id']);
}

// the payment is confirmed: only the call whose UPDATE really changes the order goes on (webhook and return of the guest come at the same moment)
function gv_order_mark_paid($orderId) {
	$was = gv_order_by_id($orderId);
	$u = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET status = 'paid', paid_at = ? WHERE id = ? AND status IN ('pending','canceled')", 'si', array(gv_now(), (int)$orderId));
	if (!$u || mysqli_stmt_affected_rows($u) < 1) { return false; }
	$o = gv_order_by_id($orderId);
	if ($was && $was['status'] === 'canceled') { gv_unvoid((int)$o['voucher_id'], array(null, 'System'), 'Zahlung nachträglich eingegangen'); }
	gv_activate((int)$o['voucher_id']);
	try { gv_order_send_mail($o); gv_order_notify_owner($o); } catch (Throwable $e) { error_log('mySeat voucher mail: '.$e->getMessage()); }
	if ($o['delivery'] === 'post' && ep_active()) { try { gv_post_send((int)$o['id']); } catch (Throwable $e) { error_log('mySeat EchtPost: '.$e->getMessage()); } }   // failures are kept on the order and mailed to the owner
	return true;
}

// ---- mails ----

function gv_order_pdf_opts($order, $v) {
	global $settings;
	return array('motif' => $order['motif'], 'amount_cents' => (int)$order['amount_cents'], 'recipient' => $order['recipient'], 'from' => $order['from_name'], 'message' => $order['message'], 'number' => $v['number'],
		'code' => (string)gv_code_plain($v), 'valid_until' => $v['valid_until'], 'order_number' => $order['number'], 'bought' => $order['paid_at'], 'token' => $v['token'], 'brand' => !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus');
}
function gv_order_status_url($order) { return rtrim(shop_site_url(), '/').'/order/gutschein_status.php?t='.$order['token']; }

// the mail to the buyer after the payment: with the PDF for the form "pdf", a confirmation for blanks
function gv_order_send_mail($o) {
	$v = gv_by_id((int)$o['voucher_id']);
	$status = gv_order_status_url($o);
	$valid = $v['valid_until'] ? date('d.m.Y', strtotime($v['valid_until'])) : '';
	if ($o['delivery'] === 'pdf') {
		$pdf = gv_pdf(gv_order_pdf_opts($o, $v));
		$m = array('subject' => 'Dein Gutschein vom Amadeus', 'headline' => 'Dein Gutschein ist da', 'purpose' => 'order', 'zeus' => false,
			'lead' => 'Danke für deine Bestellung '.$o['number'].'. Im Anhang liegt der Gutschein als PDF: ausdrucken, in einen Umschlag, fertig. Oder leite die Mail weiter, dann liegt er direkt im Postfach der Person, für die er gedacht ist.',
			'image' => '', 'alt' => '', 'box' => array('label' => 'Gutschein', 'value' => gv_money_short((int)$o['amount_cents']), 'note' => 'Nummer '.$v['number'].($valid !== '' ? ' · gültig bis '.$valid : '')),
			'after' => 'Einlösen kann man ihn bei uns mit der Nummer und beim Online-Bestellen mit Nummer und Sicherheitscode (beides steht im PDF). Ein Teilbetrag reicht, der Rest bleibt stehen. Über den Knopf siehst du deine Bestellung und findest die Widerrufsfunktion.',
			'btn' => 'Bestellung ansehen', 'url' => $status, 'signoff' => 'Hamun und das Amadeus-Team', 'files' => array(array('name' => 'Gutschein-'.$v['number'].'.pdf', 'mime' => 'application/pdf', 'data' => $pdf)));
	} else {
		$m = array('subject' => 'Deine Gutschein-Bestellung '.$o['number'], 'headline' => 'Danke, wir machen die Karte fertig', 'purpose' => 'order', 'zeus' => false,
			'lead' => 'Deine Zahlung ist angekommen. '.($o['delivery'] === 'post'
					? (($o['ep_state'] !== null || ep_active() ? 'Wir lassen die Karte drucken und schicken sie an ' : 'Wir machen die Karte fertig und schicken sie an ').$o['ship_name'].'. Sobald sie zur Post geht, schreiben wir dir.')
					: 'Wir machen die Karte fertig und legen sie im Restaurant für dich bereit. Sobald du sie holen kannst, schreiben wir dir.'),
				'image' => '', 'alt' => '', 'box' => array('label' => 'Gutschein', 'value' => gv_money_short((int)$o['amount_cents']), 'note' => gv_form_label($o['delivery']).($valid !== '' ? ' · gültig bis '.$valid : '')),
			'after' => 'Den Stand deiner Bestellung und die Widerrufsfunktion findest du über den Knopf.', 'btn' => 'Bestellung ansehen', 'url' => $status, 'signoff' => 'Hamun und das Amadeus-Team');
	}
	$ok = shop_stamp_mail($o['buyer_email'], $m);
	if ($ok) { fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET mail_at = ? WHERE id = ?", 'si', array(gv_now(), (int)$o['id'])); }
	return $ok;
}
// a mail to the restaurant for orders that need work (blank to send or hand over)
function gv_order_notify_owner($o) {
	if ($o['delivery'] === 'pdf' || ($o['delivery'] === 'post' && ep_active())) { return; }   // a postcard by EchtPost needs no work (a failure sends its own mail)
	$to = shop_mail_from(); if ($to === '') { return; }
	shop_stamp_mail($to, array('subject' => 'Neuer Gutschein-Auftrag '.$o['number'], 'headline' => 'Gutschein-Auftrag '.$o['number'], 'purpose' => 'order', 'zeus' => false,
		'lead' => gv_form_label($o['delivery']).': '.gv_money_short((int)$o['amount_cents']).' für '.($o['recipient'] !== '' ? $o['recipient'] : $o['buyer_name']).'. Bitte im Backend unter Gutscheine > Aufträge erledigen.',
		'image' => '', 'alt' => '', 'box' => null, 'after' => 'Käufer: '.$o['buyer_name'].' ('.$o['buyer_email'].')'.($o['delivery'] === 'post' ? '. Versand an: '.$o['ship_name'].', '.$o['ship_street'].', '.$o['ship_zip'].' '.$o['ship_city'] : ''), 'btn' => '', 'url' => '', 'signoff' => 'mySeat'));
}

// ---- fulfilment of blanks (post / pick-up) ----

// the blank with its printed number is ready: the voucher takes that number, the buyer is told. Returns array(ok, error)
function gv_order_fulfil($orderId, $blankNumber, $who) {
	$o = gv_order_by_id($orderId);
	if (!$o || $o['status'] !== 'paid' || $o['delivery'] === 'pdf') { return array('ok' => false, 'error' => 'Dieser Auftrag lässt sich nicht erledigen.'); }
	if ($o['fulfilled_at'] !== null) { return array('ok' => false, 'error' => 'Dieser Auftrag ist schon erledigt.'); }
	if (in_array($o['ep_state'], array('sending', 'queued', 'unclear'), true)) { return array('ok' => false, 'error' => 'Die Postkarte ist bei EchtPost bestellt oder wird bestellt. Bitte dort nachsehen, bevor du den Auftrag von Hand erledigst.'); }
	$v = gv_by_id((int)$o['voucher_id']);
	if ($v && $v['kind'] === 'print') {   // a blank takes its printed number; a card printed from the shop file keeps its own
		$r = gv_renumber((int)$o['voucher_id'], $blankNumber, $who);
		if (!$r['ok']) { return $r; }
	}
	fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET fulfilled_at = ? WHERE id = ?", 'si', array(gv_now(), (int)$orderId));
	gv_order_mail_sent(gv_order_by_id($orderId));
	return array('ok' => true);
}
// the mail "on its way" (post) / "ready" (pick-up) after the card went out
function gv_order_mail_sent($o) {
	shop_stamp_mail($o['buyer_email'], array('subject' => $o['delivery'] === 'post' ? 'Deine Karte ist unterwegs' : 'Deine Karte liegt bereit', 'headline' => $o['delivery'] === 'post' ? 'Deine Karte ist unterwegs' : 'Deine Karte liegt bereit',
		'purpose' => 'order', 'zeus' => false, 'lead' => $o['delivery'] === 'post' ? 'Die Karte zu Bestellung '.$o['number'].' ist heute zur Post gegangen.' : 'Die Karte zu Bestellung '.$o['number'].' liegt im Restaurant für dich bereit. Sag bei uns einfach, dass du einen Gutschein abholst, und nenne die Bestellnummer '.$o['number'].'.',
		'image' => '', 'alt' => '', 'box' => null, 'after' => 'Den Stand deiner Bestellung findest du über den Knopf.', 'btn' => 'Bestellung ansehen', 'url' => gv_order_status_url($o), 'signoff' => 'Hamun und das Amadeus-Team'));
}

// ---- withdrawal (Widerruf) and refund ----

// what the buyer can do about the withdrawal: array(state, text). states: open, unpaid, done, lapsed, late, partial
function gv_order_withdraw_state($o) {
	if ($o['status'] === 'withdrawn') { return array('done', 'Dein Widerruf ist am '.date('d.m.Y', strtotime($o['withdrawn_at'])).' bei uns eingegangen.'); }
	if ($o['status'] !== 'paid') { return array('unpaid', 'Diese Bestellung ist nicht bezahlt, ein Widerruf ist nicht nötig.'); }
	if ($o['delivery'] === 'pdf' && (int)$o['consent_lapse'] === 1) { return array('lapsed', 'Du hast zugestimmt, dass der Gutschein sofort bereitgestellt wird, und bestätigt, dass dein Widerrufsrecht damit endet. Ein Widerruf ist deshalb nicht mehr möglich.'); }
	$v = gv_by_id((int)$o['voucher_id']);
	if ($v && (int)$v['balance_cents'] < (int)$v['face_cents']) { return array('partial', 'Der Gutschein wurde schon teilweise eingelöst. Bitte schreib uns, wir klären das mit dir.'); }
	$from = ($o['delivery'] !== 'pdf' && $o['fulfilled_at'] === null) ? time() : strtotime($o['delivery'] === 'pdf' ? $o['paid_at'] : $o['fulfilled_at']);
	$until = strtotime('+'.GV_WITHDRAW_DAYS.' days', $from);
	if (time() > $until + 86399) { return array('late', 'Die Widerrufsfrist von '.GV_WITHDRAW_DAYS.' Tagen ist am '.date('d.m.Y', $until).' abgelaufen.'); }
	return array('open', 'Du kannst bis zum '.date('d.m.Y', $until).' widerrufen.');
}

// the withdrawal: voucher blocked, order marked, confirmation mail right away (durable medium), a note to the owner. The refund is the owner's click (gv_order_refund).
function gv_order_withdraw($o) {
	list($state, $text) = gv_order_withdraw_state($o);
	if ($state !== 'open') { return array('ok' => false, 'error' => $text); }
	$u = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET status = 'withdrawn', withdrawn_at = ?, withdraw_ip = ? WHERE id = ? AND status = 'paid'", 'ssi', array(gv_now(), gv_ip(), (int)$o['id']));
	if (!$u || mysqli_stmt_affected_rows($u) < 1) { return array('ok' => false, 'error' => 'Der Widerruf ist schon eingegangen.'); }
	gv_void((int)$o['voucher_id'], array(null, 'Widerruf'), 'Widerruf der Bestellung '.$o['number']);
	$cardNote = ''; try { $cardNote = gv_post_cancel($o); } catch (Throwable $e) { error_log('mySeat EchtPost cancel: '.$e->getMessage()); }   // a postcard not printed yet is called back
	$o = gv_order_by_id((int)$o['id']);
	$sent = shop_stamp_mail($o['buyer_email'], array('subject' => 'Dein Widerruf ist bei uns eingegangen', 'headline' => 'Dein Widerruf ist eingegangen', 'purpose' => 'order', 'zeus' => false,
		'lead' => 'Wir bestätigen, dass dein Widerruf der Bestellung '.$o['number'].' am '.date('d.m.Y', strtotime($o['withdrawn_at'])).' um '.date('H:i', strtotime($o['withdrawn_at'])).' Uhr bei uns eingegangen ist. Der Gutschein ist gesperrt. Wir erstatten '.gv_money_short((int)$o['total_cents']).' auf dem Weg, mit dem du bezahlt hast, in der Regel innerhalb von 14 Tagen.',
		'image' => '', 'alt' => '', 'box' => array('label' => 'Erstattung', 'value' => gv_money_short((int)$o['total_cents']), 'note' => 'auf dem Zahlungsweg deiner Bestellung'),
		'after' => 'Wenn du magst, sag uns kurz, woran es lag. Eine Antwort auf diese Mail genügt, du musst aber nicht.', 'btn' => '', 'url' => '', 'signoff' => 'Hamun und das Amadeus-Team'));
	$to = shop_mail_from();
	if ($to !== '') { shop_stamp_mail($to, array('subject' => 'Widerruf Gutschein '.$o['number'], 'headline' => 'Widerruf '.$o['number'], 'purpose' => 'order', 'zeus' => false, 'lead' => $o['buyer_name'].' hat die Bestellung '.$o['number'].' über '.gv_money_short((int)$o['total_cents']).' widerrufen. Der Gutschein ist gesperrt.'.($cardNote !== '' ? ' '.$cardNote : '').' Bitte im Backend unter Gutscheine > Aufträge die Erstattung auslösen.',
		'image' => '', 'alt' => '', 'box' => null, 'after' => '', 'btn' => '', 'url' => '', 'signoff' => 'mySeat')); }
	return array('ok' => true, 'mail' => (bool)$sent, 'order' => $o);
}

// refund the whole payment to the guest through Mollie (the owner clicks it in the backend). Returns array(ok, error)
function gv_order_refund($orderId) {
	$o = gv_order_by_id($orderId);
	if (!$o || $o['status'] !== 'withdrawn' || empty($o['mollie_id'])) { return array('ok' => false, 'error' => 'Hier gibt es nichts zu erstatten.'); }
	if (!empty($o['refund_id'])) { return array('ok' => false, 'error' => 'Die Erstattung ist schon ausgelöst.'); }
	$claim = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET refund_id = 'pending' WHERE id = ? AND refund_id IS NULL", 'i', array((int)$orderId));
	if (!$claim || mysqli_stmt_affected_rows($claim) < 1) { return array('ok' => false, 'error' => 'Die Erstattung läuft schon.'); }
	$r = shop_mollie_request('POST', '/payments/'.rawurlencode($o['mollie_id']).'/refunds', array('amount' => array('currency' => 'EUR', 'value' => number_format($o['total_cents'] / 100, 2, '.', '')), 'description' => 'Widerruf '.$o['number']));
	if (!in_array($r['http'], array(200, 201), true) || empty($r['body']['id'])) {
		fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET refund_id = NULL WHERE id = ?", 'i', array((int)$orderId));
		return array('ok' => false, 'error' => 'Mollie hat die Erstattung nicht angenommen: '.shop_mollie_describe($r));
	}
	fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET refund_id = ?, refunded_at = ? WHERE id = ?", 'ssi', array($r['body']['id'], gv_now(), (int)$orderId));
	return array('ok' => true);
}

// orders for the backend
function gv_orders_list($filter, $limit = 200) {
	shop_ensure_schema();
	// todo: a postcard EchtPost has taken ('queued') is not our work until it fails
	$w = array('todo' => "status = 'paid' AND delivery <> 'pdf' AND fulfilled_at IS NULL AND (ep_state IS NULL OR ep_state <> 'queued')", 'refund' => "status = 'withdrawn' AND refund_id IS NULL", 'paid' => "status = 'paid'", 'withdrawn' => "status = 'withdrawn'", 'pending' => "status = 'pending'");
	$where = isset($w[$filter]) ? $w[$filter] : '1=1';
	return fb_rows("SELECT * FROM ".fb_t('tp_gv_orders')." WHERE $where ORDER BY id DESC LIMIT ".(int)$limit);
}

// ---- paying a food order with a voucher (checkout, till) ----
// The order keeps what is still to be paid in total_cents (Mollie, the driver, the slip and the till then show the right amount by themselves); voucher_cents is the part the voucher paid.

// the voucher for number + security code that can pay now, or null with the words for the guest in $err
function gv_pay_check($number, $code, &$err) {
	$v = gv_lookup_guest($number, $code, $err);
	if (!$v) { return null; }
	$why = gv_block_reason($v);
	if ($why !== '') { $err = $why; return null; }
	return $v;
}
// take $cents from the voucher for the order number $ref; array(ok, error, move_id)
function gv_pay_take($voucherId, $cents, $ref) { return gv_redeem((int)$voucherId, (int)$cents, array(null, 'Online-Shop'), 'Bestellung '.$ref); }
// a cancelled or failed order gives the money back to the voucher (once)
function gv_pay_back($orderId) {
	$o = fb_row("SELECT id, number, voucher_id, voucher_cents, voucher_back FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$orderId));
	if (!$o || (int)$o['voucher_cents'] < 1 || empty($o['voucher_id']) || (int)$o['voucher_back'] === 1) { return; }
	$c = fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET voucher_back = 1 WHERE id = ? AND voucher_back = 0", 'i', array((int)$orderId));
	if (!$c || mysqli_stmt_affected_rows($c) < 1) { return; }
	$r = gv_refund((int)$o['voucher_id'], (int)$o['voucher_cents'], array(null, 'Storno'), 'Bestellung '.$o['number'].' storniert');
	if (!$r['ok']) { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET voucher_back = 0 WHERE id = ?", 'i', array((int)$orderId)); error_log('mySeat voucher back of order '.$o['number'].': '.$r['error']); }
}
// a failed delivery that is brought back takes the money from the voucher again (when it still holds it)
function gv_pay_again($orderId) {
	$o = fb_row("SELECT id, number, voucher_id, voucher_cents, voucher_back FROM ".fb_t('tp_shop_orders')." WHERE id = ?", 'i', array((int)$orderId));
	if (!$o || (int)$o['voucher_cents'] < 1 || empty($o['voucher_id']) || (int)$o['voucher_back'] !== 1) { return; }
	$r = gv_redeem((int)$o['voucher_id'], (int)$o['voucher_cents'], array(null, 'Online-Shop'), 'Bestellung '.$o['number'].' wieder aufgenommen');
	if ($r['ok']) { fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET voucher_back = 0 WHERE id = ?", 'i', array((int)$orderId)); }
	else { shop_log((int)$orderId, 'voucher', 'Gutschein konnte nicht erneut belastet werden: '.$r['error']); }
}
