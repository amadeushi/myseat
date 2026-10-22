<?php
/*
 * EchtPost (echtpost.de): prints a postcard per order and sends it with a stamp, through their REST API (https://api.echtpost.de/v2, docs: /v2/docs, spec: /v2/openapi.json).
 * Used for the voucher form "Per Post": the front of the card is the voucher itself (number, security code, value, motif: gv_postcard_front_jpeg), uploaded as a temporary motive of
 * 1795 x 1287 px (A6 landscape + 2 mm bleed); the back carries the words of the giver as text (their handwriting font) and the address.
 * The key is stored encrypted (tp_shop_settings.gv_ep_key). A key starting with ep_test_ is the sandbox: nothing is printed or charged.
 * Requires an EchtPost account whose support has switched on custom motives (upload "nach Account-Verifikation") and a topped-up credit.
 */

function ep_base() { return defined('EP_BASE_URL') ? EP_BASE_URL : 'https://api.echtpost.de/v2'; }

function ep_key() {
	$blob = shop_setting('gv_ep_key');
	$k = $blob ? sms_decrypt($blob) : null;
	return ($k !== null && preg_match('/^[A-Za-z0-9_.\-]{16,200}$/', $k)) ? $k : '';
}
function ep_info() {
	$k = ep_key();
	return array('set' => $k !== '', 'mode' => $k === '' ? '' : (strpos($k, 'ep_test_') === 0 ? 'test' : 'live'), 'masked' => $k === '' ? '' : '••••'.substr($k, -4));
}

/*
 * One call. $data: array (JSON body) or, with $file (path of a JPEG), the form fields of the upload. Returns array(http, body, error); http 0 = no answer (network).
 */
function ep_request($method, $path, $data = null, $file = null) {
	$key = ep_key();
	if ($key === '') { return array('http' => 0, 'body' => null, 'error' => 'Kein EchtPost-Schlüssel hinterlegt'); }
	$ch = curl_init(ep_base().$path);
	$headers = array('Authorization: Bearer '.$key, 'Accept: application/json');
	$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'mySeat-Gutschein');
	if ($method === 'DELETE') { $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE'; }
	if ($method === 'POST') {
		$opts[CURLOPT_POST] = true;
		if ($file !== null) { $data['image'] = new CURLFile($file, 'image/jpeg', 'front.jpg'); $opts[CURLOPT_POSTFIELDS] = $data; }
		else { $opts[CURLOPT_POSTFIELDS] = json_encode($data); $headers[] = 'Content-Type: application/json'; }
	}
	$opts[CURLOPT_HTTPHEADER] = $headers;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch);
	$errno = curl_errno($ch); $err = $errno ? curl_error($ch) : null;
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$j = is_string($raw) ? json_decode($raw, true) : null;
	return array('http' => $errno ? 0 : $http, 'body' => is_array($j) ? $j : null, 'error' => $err);
}
// the answer in words for the owner (problem+json: title, detail)
function ep_describe($r) {
	if ($r['http'] === 0) { return 'EchtPost nicht erreichbar'.($r['error'] ? ' ('.$r['error'].')' : ''); }
	$b = is_array($r['body']) ? $r['body'] : array();
	$detail = isset($b['detail']) && is_string($b['detail']) ? $b['detail'] : (isset($b['title']) && is_string($b['title']) ? $b['title'] : '');
	if ($r['http'] === 401) { $detail = 'Der Schlüssel wird von EchtPost nicht akzeptiert'; }
	if ($r['http'] === 402) { $detail = 'Das Guthaben bei EchtPost reicht nicht, bitte aufladen'; }
	return substr(($detail !== '' ? $detail : 'Fehler').' (HTTP '.$r['http'].')', 0, 240);
}
// the key works? array(ok, error, mode, credits)
function ep_test() {
	$r = ep_request('GET', '/me');
	if ($r['http'] !== 200) { return array('ok' => false, 'error' => ep_describe($r)); }
	$i = ep_info();
	return array('ok' => true, 'mode' => $i['mode'], 'account' => (string)($r['body']['account']['name'] ?? ''), 'credits' => (string)($r['body']['balance']['credits_eur'] ?? ''), 'cards' => (int)($r['body']['balance']['cards_regular'] ?? 0));
}

// the post is switched to EchtPost: choice in the settings AND a key is there
function ep_active() { return shop_setting('gv_post_mode') === 'echtpost' && ep_key() !== ''; }

// "Maria von Berg" => array('Maria von', 'Berg'); one word => last name only
function ep_split_name($full) {
	$full = trim(preg_replace('/\s+/u', ' ', (string)$full));
	$p = mb_strrpos($full, ' ');
	return $p === false ? array('', $full) : array(trim(mb_substr($full, 0, $p)), trim(mb_substr($full, $p + 1)));
}
// no emojis and control characters (EchtPost refuses emojis in text fields)
function ep_clean($s) {
	$s = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2190}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', (string)$s);
	return trim(preg_replace('/[ \t]{2,}/', ' ', preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $s)));
}
// the text on the back: for / message / from, then a short line on the voucher. Lines separated by \n.
function ep_card_text($o, $withHint) {
	$parts = array();
	$for = ep_clean($o['recipient'] ?? ''); $msg = ep_clean($o['message'] ?? ''); $from = ep_clean($o['from_name'] ?? '');
	if ($for !== '') { $parts[] = 'Für '.$for; }
	$parts[] = $msg !== '' ? $msg : 'Ein Gutschein für dich.';
	if ($from !== '') { $parts[] = 'Von '.$from; }
	if ($withHint) { $parts[] = 'Nummer und Sicherheitscode stehen auf der Vorderseite. Einlösen kannst du den Gutschein im Restaurant und im Webshop.'; }
	return implode("\n\n", $parts);
}

/*
 * Order the postcard of a paid order. Only one call wins the claim (the webhook of the payment and a click in the backend can come together). $again: also take an order that stands on "unclear"
 * (the last attempt got no answer, the card may exist: check in the EchtPost account first). Returns array(ok, error).
 */
function gv_post_send($orderId, $again = false) {
	$o = gv_order_by_id($orderId);
	if (!$o || $o['status'] !== 'paid' || $o['delivery'] !== 'post' || $o['fulfilled_at'] !== null) { return array('ok' => false, 'error' => 'Für diesen Auftrag gibt es nichts zu verschicken.'); }
	if (ep_key() === '') { return array('ok' => false, 'error' => 'Kein EchtPost-Schlüssel hinterlegt.'); }
	$states = $again ? "'error','unclear'" : "'error'";
	$claim = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = 'sending', ep_at = ?, ep_error = '' WHERE id = ? AND (ep_state IS NULL OR ep_state IN ($states))", 'si', array(gv_now(), (int)$orderId));
	if (!$claim || mysqli_stmt_affected_rows($claim) < 1) { return array('ok' => false, 'error' => 'Die Karte ist schon bestellt oder wird gerade bestellt.'); }
	$fail = function ($state, $msg) use ($orderId, $o) {
		fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = ?, ep_error = ?, ep_at = ? WHERE id = ?", 'sssi', array($state, mb_substr($msg, 0, 250), gv_now(), (int)$orderId));
		error_log('mySeat EchtPost '.$o['number'].': '.$msg);
		$to = shop_mail_from();
		if ($to !== '') { try { shop_stamp_mail($to, array('subject' => 'Postkarte zu Gutschein '.$o['number'].' nicht bestellt', 'headline' => 'Postkarte nicht bestellt', 'purpose' => 'order', 'zeus' => false,
			'lead' => 'Die Karte zu Bestellung '.$o['number'].' konnte nicht bei EchtPost bestellt werden: '.$msg.'. Bitte im Backend unter Gutscheine > Aufträge noch einmal versuchen oder die Karte von Hand schicken.',
			'image' => '', 'alt' => '', 'box' => null, 'after' => 'Versand an: '.$o['ship_name'].', '.$o['ship_street'].', '.$o['ship_zip'].' '.$o['ship_city'], 'btn' => '', 'url' => '', 'signoff' => 'mySeat')); } catch (Throwable $e) { error_log('mySeat EchtPost mail: '.$e->getMessage()); } }
		return array('ok' => false, 'error' => $msg);
	};
	$v = gv_by_id((int)$o['voucher_id']);
	if (!$v || gv_code_plain($v) === null) { return $fail('error', 'Gutschein oder Sicherheitscode fehlt'); }
	$tmp = tempnam(sys_get_temp_dir(), 'gvpc');
	try {
		// 1. the front: the voucher as picture (kept for a retry: the motive is only made once)
		$motive = (int)$o['ep_motive'];
		if ($motive < 1) {
			file_put_contents($tmp, gv_postcard_front_jpeg(gv_order_pdf_opts($o, $v)));
			$r = ep_request('POST', '/motives', array('name' => mb_substr('Gutschein '.$o['number'], 0, 60), 'orientation' => 'landscape', 'content_available' => 'true', 'content_ps_available' => 'true', 'vertical_text_available' => 'false', 'qr_code_available' => 'false'), $tmp);
			if ($r['http'] !== 201 || empty($r['body']['id'])) { return $fail('error', 'Das Motiv wurde nicht angenommen: '.ep_describe($r)); }
			$motive = (int)$r['body']['id'];
			fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_motive = ? WHERE id = ?", 'ii', array($motive, (int)$orderId));
		}
		// 2. the text of the back: with the hint when it fits
		$text = ep_card_text($o, true);
		$fit = ep_request('POST', '/cards/preview_fit', array('content' => $text));
		if ($fit['http'] === 200 && isset($fit['body']['fits']) && $fit['body']['fits'] === false) { $text = ep_card_text($o, false); }
		// 3. the card
		list($first, $last) = ep_split_name($o['ship_name']);
		$rcpt = array('last_name' => $last, 'street' => $o['ship_street'], 'zip' => $o['ship_zip'], 'city' => $o['ship_city'], 'country_code' => 'DE');
		if ($first !== '') { $rcpt['first_name'] = $first; }
		$body = array('motive_id' => $motive, 'content' => $text, 'content_ps' => 'Gültig bis '.($v['valid_until'] ? date('d.m.Y', strtotime($v['valid_until'])) : ''), 'deliver_at' => 'asap', 'recipients' => array($rcpt));
		$r = ep_request('POST', '/cards', $body);
		if ($r['http'] === 0) { return $fail('unclear', 'Keine Antwort von EchtPost, die Karte könnte trotzdem bestellt sein. Bitte im EchtPost-Konto nachsehen, bevor du es noch einmal versuchst'); }
		if ($r['http'] !== 201 || empty($r['body']['id'])) { return $fail('error', 'Die Karte wurde nicht angenommen: '.ep_describe($r)); }
		fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = 'queued', ep_card = ?, ep_error = '', ep_at = ? WHERE id = ?", 'ssi', array(substr((string)$r['body']['id'], 0, 40), gv_now(), (int)$orderId));
		return array('ok' => true);
	} finally { @unlink($tmp); }
}

// the card is on its way (EchtPost sent it to the post): the buyer is told, once
function gv_post_mark_sent($orderId) {
	$u = fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = 'sent', fulfilled_at = ?, ep_at = ? WHERE id = ? AND ep_state = 'queued'", 'ssi', array(gv_now(), gv_now(), (int)$orderId));
	if (!$u || mysqli_stmt_affected_rows($u) < 1) { return false; }
	try { gv_order_mail_sent(gv_order_by_id((int)$orderId)); } catch (Throwable $e) { error_log('mySeat EchtPost mail: '.$e->getMessage()); }
	return true;
}

/*
 * Ask EchtPost what became of the card (the webhook card.sent, and a look every ten minutes at most when somebody opens the order). The body of a webhook is not trusted: the state comes from the API.
 * Returns the order as it is now.
 */
function gv_post_sync($order, $force = false) {
	if (!$order || $order['ep_state'] !== 'queued' || empty($order['ep_card']) || ep_key() === '') { return $order; }
	if (!$force && $order['ep_at'] !== null && strtotime($order['ep_at']) > time() - 600) { return $order; }
	fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_at = ? WHERE id = ?", 'si', array(gv_now(), (int)$order['id']));
	$r = ep_request('GET', '/cards/'.rawurlencode($order['ep_card']));
	if ($r['http'] === 200 && isset($r['body']['status'])) {
		if ($r['body']['status'] === 'sent') { gv_post_mark_sent((int)$order['id']); }
		elseif ($r['body']['status'] === 'canceled') { fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = 'error', ep_error = 'Die Karte wurde bei EchtPost storniert.' WHERE id = ? AND ep_state = 'queued'", 'i', array((int)$order['id'])); }
	}
	return gv_order_by_id((int)$order['id']);
}

// withdrawal: a card that is not printed yet is called back. Returns a sentence for the owner ('' when nothing was to do)
function gv_post_cancel($o) {
	if (empty($o['ep_card']) || !in_array($o['ep_state'], array('queued', 'sending', 'unclear'), true)) { return ''; }
	$r = ep_request('DELETE', '/cards/'.rawurlencode($o['ep_card']));
	if ($r['http'] === 202) { fb_exec("UPDATE ".fb_t('tp_gv_orders')." SET ep_state = 'canceled', ep_at = ? WHERE id = ?", 'si', array(gv_now(), (int)$o['id'])); return 'Die Postkarte wurde bei EchtPost zurückgerufen.'; }
	return $r['http'] === 409 ? 'Die Postkarte ist schon gedruckt und lässt sich nicht mehr zurückrufen, der Gutschein ist aber gesperrt.' : 'Die Postkarte konnte nicht zurückgerufen werden ('.ep_describe($r).'). Bitte im EchtPost-Konto prüfen.';
}
