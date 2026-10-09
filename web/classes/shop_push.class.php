<?php
/*
 * Push messages for the status page of an order ("Wird zubereitet", "Unterwegs zu dir", "Abholbereit"): the guest allows notifications on the status page, the browser hands over a
 * subscription (the address of its push service), we keep it with the order. When the order changes its status the server knocks on that address (a push WITHOUT a payload, signed
 * with our VAPID key, so no payload encryption is needed); the service worker (order/sw.js) then asks order/push.php what to say, and shows it. Nothing personal travels through
 * the push service, and the page keeps working without it (SMS and the self-refreshing status page are unchanged).
 * Subscriptions belong to one order and are removed two days after it, or when the push service says the address is gone.
 */
require_once __DIR__.'/shop.class.php';

function shop_push_ensure() {
	static $done = false; if ($done) { return; } $done = true;
	shop_ensure_schema();
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_push')." (`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `order_id` INT UNSIGNED NOT NULL, `endpoint_hash` CHAR(40) NOT NULL,
		`endpoint` VARCHAR(600) NOT NULL, `created` DATETIME NOT NULL, `pending_title` VARCHAR(80) NOT NULL DEFAULT '', `pending_body` VARCHAR(200) NOT NULL DEFAULT '', `pending_url` VARCHAR(200) NOT NULL DEFAULT '',
		UNIQUE KEY `ep` (`order_id`, `endpoint_hash`), KEY `endpoint_hash` (`endpoint_hash`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	fb_exec("DELETE FROM ".fb_t('tp_shop_push')." WHERE created < ?", 's', array(date('Y-m-d H:i:s', time() - 2 * 86400)));
}
function shop_push_b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

// the VAPID key pair of this shop (made once, the private key is kept encrypted like the Mollie key); returns array(public key as the browser wants it, private key PEM) or null
function shop_push_keys() {
	$pub = (string)shop_setting('push_pub'); $enc = (string)shop_setting('push_priv');
	if ($pub !== '' && $enc !== '') { $pem = sms_decrypt($enc); if ($pem !== null && $pem !== '') { return array($pub, $pem); } }
	if (!function_exists('openssl_pkey_new')) { return null; }
	$k = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
	if (!$k) { return null; }
	$d = openssl_pkey_get_details($k);
	if (empty($d['ec']['x']) || empty($d['ec']['y'])) { return null; }
	$point = "\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
	openssl_pkey_export($k, $pem);
	$e = sms_encrypt($pem);
	if ($e === null) { return null; }
	shop_setting_set('push_pub', shop_push_b64($point)); shop_setting_set('push_priv', $e);
	return array(shop_push_b64($point), $pem);
}
// the signature of a JWT with ES256 is the two numbers r and s, 32 bytes each (openssl gives them as DER)
function shop_push_der_to_raw($der) {
	$o = 2; if (ord($der[1]) & 0x80) { $o += (ord($der[1]) & 0x7f); }
	$rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl;
	$sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
	return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function shop_push_jwt($aud, $pem) {
	$mail = trim((string)shop_setting('notify_email')); if ($mail === '') { $mail = 'info@amds.at'; }
	$h = shop_push_b64(json_encode(array('typ' => 'JWT', 'alg' => 'ES256'))); $p = shop_push_b64(json_encode(array('aud' => $aud, 'exp' => time() + 43200, 'sub' => 'mailto:'.$mail)));
	if (!openssl_sign($h.'.'.$p, $der, $pem, OPENSSL_ALGO_SHA256)) { return ''; }
	return $h.'.'.$p.'.'.shop_push_b64(shop_push_der_to_raw($der));
}
// only the push services of the big browsers (a guest must not make the server knock on any address)
function shop_push_endpoint_ok($ep) {
	$u = parse_url((string)$ep);
	if (!$u || empty($u['host']) || strtolower((string)$u['scheme']) !== 'https' || strlen($ep) > 600) { return false; }
	return (bool)preg_match('/(^|\.)(fcm\.googleapis\.com|push\.services\.mozilla\.com|push\.apple\.com|notify\.windows\.com|push\.mozilla\.com)$/i', $u['host']);
}

function shop_push_subscribe($order, $endpoint) {
	shop_push_ensure();
	if (!$order || !shop_push_endpoint_ok($endpoint)) { return array('ok' => false, 'error' => 'Diese Benachrichtigung wird nicht unterstützt.'); }
	if (in_array($order['status'], array('cancelled', 'failed', 'done'), true)) { return array('ok' => false, 'error' => 'Diese Bestellung ist schon abgeschlossen.'); }
	fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_push')." (order_id, endpoint_hash, endpoint, created) VALUES (?, ?, ?, ?)", 'isss', array((int)$order['id'], sha1($endpoint), $endpoint, date('Y-m-d H:i:s')));
	return array('ok' => true);
}

// what to say for a status of the order
function shop_push_text($o, $status) {
	$pickup = $o['type'] !== 'delivery';
	$t = array(
		'accepted' => array('Bestellung bestätigt', 'Wir kümmern uns um deine Bestellung.'),
		'preparing' => array('Wird zubereitet', 'Dein Essen ist in der Küche.'),
		'ready' => $pickup ? array('Abholbereit', 'Dein Essen ist fertig und wartet auf dich.') : array('Fertig', 'Dein Essen ist fertig, gleich geht es los.'),
		'delivering' => array('Unterwegs zu dir', 'Dein Fahrer ist auf dem Weg. Auf der Karte siehst du, wo er ist.'),
		'done' => array('Guten Appetit', 'Danke für deine Bestellung!'),
		'cancelled' => array('Bestellung storniert', 'Deine Bestellung wurde storniert. Bitte ruf uns an, wenn du Fragen hast.'),
	);
	return isset($t[$status]) ? $t[$status] : null;
}

// the order changed its status: say it to everybody who allowed notifications for it
function shop_push_status($orderId, $status) {
	try {
		shop_push_ensure();
		$o = shop_order((int)$orderId);
		if (!$o || !empty($o['is_test']) || !shop_flag('push_on')) { return; }
		$txt = shop_push_text($o, $status); if (!$txt) { return; }
		$subs = fb_rows("SELECT id, endpoint FROM ".fb_t('tp_shop_push')." WHERE order_id = ?", 'i', array((int)$orderId));
		foreach ($subs as $s) {
			fb_exec("UPDATE ".fb_t('tp_shop_push')." SET pending_title = ?, pending_body = ?, pending_url = ? WHERE id = ?", 'sssi', array($txt[0], $txt[1], 'status.php?t='.$o['token'], (int)$s['id']));
			$code = shop_push_send($s['endpoint']);
			if ($code === 404 || $code === 410) { fb_exec("DELETE FROM ".fb_t('tp_shop_push')." WHERE id = ?", 'i', array((int)$s['id'])); }
		}
	} catch (Throwable $e) { error_log('mySeat push: '.$e->getMessage()); }
}

// knock on the address of the push service (no payload); returns the HTTP code, 0 on a failure on our side
function shop_push_send($endpoint) {
	$k = shop_push_keys(); if (!$k || !shop_push_endpoint_ok($endpoint)) { return 0; }
	$u = parse_url($endpoint); $aud = $u['scheme'].'://'.$u['host'].(!empty($u['port']) ? ':'.$u['port'] : '');
	$jwt = shop_push_jwt($aud, $k[1]); if ($jwt === '') { return 0; }
	$ch = curl_init($endpoint);
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
		CURLOPT_HTTPHEADER => array('Authorization: vapid t='.$jwt.', k='.$k[0], 'TTL: 3600', 'Urgency: high', 'Content-Length: 0')));
	curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
	return $code;
}

// the service worker asks what to show for its address; the pending message is handed out once (a push must always show something, so there is a plain fallback)
function shop_push_pull($endpoint) {
	shop_push_ensure();
	$fallback = array('title' => 'Amadeus', 'body' => 'Es gibt Neuigkeiten zu deiner Bestellung.', 'url' => './');
	if (!shop_push_endpoint_ok($endpoint)) { return $fallback; }
	$r = fb_row("SELECT id, pending_title, pending_body, pending_url FROM ".fb_t('tp_shop_push')." WHERE endpoint_hash = ? AND pending_title <> '' ORDER BY id DESC LIMIT 1", 's', array(sha1($endpoint)));
	if (!$r) { return $fallback; }
	fb_exec("UPDATE ".fb_t('tp_shop_push')." SET pending_title = '', pending_body = '', pending_url = '' WHERE endpoint_hash = ?", 's', array(sha1($endpoint)));
	return array('title' => $r['pending_title'], 'body' => $r['pending_body'], 'url' => $r['pending_url']);
}
