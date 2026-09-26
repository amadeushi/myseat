<?php
/*
 * SMS via the own gateway (https://sms.amds.at, POST /v1/messages), used for booking confirmations and
 * the day-before reminder. Same rules as the gateway client of the shift planner:
 *  - a text has 1-160 characters and uses only the GSM 03.38 alphabet (see sms_gsm_clean; no emoji);
 *  - the gateway takes only 10 new jobs per minute and can be down for a moment (HTTP 429/503, network),
 *    so every SMS goes into the table tp_sms_outbox first, is tried once at once and otherwise retried
 *    by web/cron/sms_flush.php with growing gaps and the SAME Idempotency-Key (never sent twice);
 *  - the key is never in the repository and never in a log. Nothing in here throws to the caller: a gateway
 *    problem must never block a booking or the reminder run.
 * Settings (all optional): smsEnabled (false), smsApiKey, smsBaseUrl (https://sms.amds.at),
 * smsDefaultCountryCode (+49), smsTimeout (6). Enabled flag and key can also be set in the backend
 * (Einstellungen > SMS-Versand): those values win over config.general.php. The key is stored
 * encrypted (AES-256-GCM, secret derived from the database login in config.general.php, so a copy of
 * the database alone does not reveal it) and is never shown again, only its last 4 characters.
 */
require_once __DIR__.'/feedback.class.php';
require_once __DIR__.'/cancel_link.class.php';

const SMS_MAX_ATTEMPTS = 8;
const SMS_MAX_AGE_SECONDS = 21600; // 6 hours: after that a reminder is worthless
const SMS_BACKOFF = array(60, 120, 300, 900, 1800, 3600);

// ---- values set in the backend (table tp_sms_settings, key encrypted)
function sms_settings_schema() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_sms_settings')." (
		`k` VARCHAR(40) NOT NULL PRIMARY KEY,
		`v` TEXT NULL,
		`updated_at` DATETIME NOT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}
function sms_setting($k) {
	sms_settings_schema();
	$r = fb_rows("SELECT v FROM ".fb_t('tp_sms_settings')." WHERE k = ? LIMIT 1", 's', array($k));
	return $r ? $r[0]['v'] : null;
}
function sms_setting_set($k, $v) {
	sms_settings_schema();
	if ($v === null) { fb_exec("DELETE FROM ".fb_t('tp_sms_settings')." WHERE k = ?", 's', array($k)); }
	else { fb_exec("REPLACE INTO ".fb_t('tp_sms_settings')." (k, v, updated_at) VALUES (?, ?, NOW())", 'ss', array($k, $v)); }
	sms_cfg(true);
}
function sms_secret() {
	global $settings;
	return hash('sha256', 'myseat-sms|'.(isset($settings['dbName']) ? $settings['dbName'] : '').'|'.(isset($settings['dbUser']) ? $settings['dbUser'] : '').'|'.(isset($settings['dbPass']) ? $settings['dbPass'] : ''), true);
}
function sms_encrypt($plain) {
	$iv = random_bytes(12);
	$ct = openssl_encrypt($plain, 'aes-256-gcm', sms_secret(), OPENSSL_RAW_DATA, $iv, $tag);
	return $ct === false ? null : 'v1:'.base64_encode($iv.$tag.$ct);
}
function sms_decrypt($blob) {
	if (!is_string($blob) || strpos($blob, 'v1:') !== 0) { return null; }
	$raw = base64_decode(substr($blob, 3), true);
	if ($raw === false || strlen($raw) < 29) { return null; }
	$p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', sms_secret(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
	return $p === false ? null : $p;
}
// where the key in use comes from ('settings' = backend, 'config' = config.general.php, null = none) and its last 4 characters
function sms_key_info() {
	global $settings;
	$db = null;
	try { $db = sms_setting('api_key'); } catch (Throwable $e) {}
	$dec = $db ? sms_decrypt($db) : null;
	if ($dec !== null && $dec !== '') { return array('source' => 'settings', 'masked' => '••••'.substr($dec, -4)); }
	$c = trim((string)(isset($settings['smsApiKey']) ? $settings['smsApiKey'] : ''));
	return $c !== '' ? array('source' => 'config', 'masked' => '••••'.substr($c, -4)) : array('source' => null, 'masked' => null);
}

function sms_cfg($reset = false) {
	global $settings;
	static $overlay = null;
	if ($reset) { $overlay = null; return null; }
	if ($overlay === null) {
		$overlay = array();
		try {
			if (fb_db()) {
				$en = sms_setting('enabled');
				if ($en !== null && $en !== '') { $overlay['enabled'] = ($en === '1'); }
				$kv = sms_setting('api_key');
				$dec = $kv ? sms_decrypt($kv) : null;
				if ($dec !== null && $dec !== '') { $overlay['api_key'] = $dec; }
			}
		} catch (Throwable $e) {}
	}
	$cc = trim((string)(isset($settings['smsDefaultCountryCode']) ? $settings['smsDefaultCountryCode'] : '+49'));
	return array(
		'enabled' => isset($overlay['enabled']) ? $overlay['enabled'] : !empty($settings['smsEnabled']),
		'api_key' => isset($overlay['api_key']) ? $overlay['api_key'] : trim((string)(isset($settings['smsApiKey']) ? $settings['smsApiKey'] : '')),
		'base_url' => rtrim(trim((string)(isset($settings['smsBaseUrl']) ? $settings['smsBaseUrl'] : '')) ?: 'https://sms.amds.at', '/'),
		'cc' => preg_match('/^\+[1-9]\d{0,3}$/', $cc) ? $cc : '+49',
		'timeout' => max(2, (int)(isset($settings['smsTimeout']) ? $settings['smsTimeout'] : 6)),
	);
}

// only HTTPS (the key never travels unencrypted), except localhost for tests
function sms_base_url() {
	$url = sms_cfg()['base_url'];
	$p = parse_url($url);
	if (!$p || empty($p['host'])) { return ''; }
	$local = in_array($p['host'], array('localhost', '127.0.0.1', '::1'), true);
	return (($p['scheme'] ?? '') !== 'https' && !$local) ? '' : $url;
}

function sms_enabled() {
	$c = sms_cfg();
	return $c['enabled'] && $c['api_key'] !== '' && sms_base_url() !== '';
}

function sms_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_sms_outbox')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`reservation_id` INT NULL,
		`event_type` VARCHAR(30) NOT NULL,
		`phone` VARCHAR(20) NOT NULL,
		`text` VARCHAR(200) NOT NULL,
		`idempotency_key` VARCHAR(60) NOT NULL,
		`status` ENUM('queued','accepted','failed') NOT NULL DEFAULT 'queued',
		`attempts` INT NOT NULL DEFAULT 0,
		`next_attempt_at` DATETIME NOT NULL,
		`api_message_id` VARCHAR(80) NULL,
		`last_error` VARCHAR(255) NULL,
		`created_at` DATETIME NOT NULL,
		`updated_at` DATETIME NOT NULL,
		UNIQUE KEY `idem` (`idempotency_key`),
		UNIQUE KEY `res_event` (`reservation_id`, `event_type`),
		KEY `due` (`status`, `next_attempt_at`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	// the first version of the table allowed 140 characters, texts have up to 160 now
	$col = fb_rows("SHOW COLUMNS FROM ".fb_t('tp_sms_outbox')." LIKE 'text'");
	if ($col && stripos($col[0]['Type'], 'varchar(140)') !== false) {
		mysqli_query(fb_db(), "ALTER TABLE ".fb_t('tp_sms_outbox')." MODIFY `text` VARCHAR(200) NOT NULL");
	}
	$done = true;
}

/*
 * E.164 mobile number or null. "0151 2345678" and "0049 151 ..." work; the default country code applies
 * to a leading 0. Germany (+49 15x/16x/17x) and Austria (+43 6xx) must be mobile numbers, a landline
 * cannot receive an SMS.
 */
function sms_normalize_phone($input) {
	$s = preg_replace('/[\p{Cf}\x{00A0}\x{2007}\x{202F}]/u', '', (string)$input);
	$s = preg_replace('/\(\s*0\s*\)/', '', trim($s));
	$s = preg_replace('/[\s\-\/.()]/', '', (string)$s);
	if ($s === '' || !preg_match('/^\+?\d+$/', $s)) { return null; }
	if (strpos($s, '00') === 0) { $s = '+'.substr($s, 2); }
	elseif ($s[0] === '0') { $s = sms_cfg()['cc'].substr($s, 1); }
	elseif ($s[0] !== '+') { return null; }
	if (strpos($s, '+490') === 0) { $s = '+49'.substr($s, 4); }
	if (strpos($s, '+430') === 0) { $s = '+43'.substr($s, 4); }
	if (!preg_match('/^\+[1-9]\d{7,14}$/', $s)) { return null; }
	if (strpos($s, '+43') === 0 && !preg_match('/^\+436\d{7,11}$/', $s)) { return null; }
	if (strpos($s, '+49') === 0 && !preg_match('/^\+491[567]\d{8,9}$/', $s)) { return null; }
	return $s;
}

/*
 * The gateway sends up to 160 characters when the text uses only the GSM 03.38 alphabet (German umlauts
 * and ß are in it); any other character makes it an SMS of 70. So texts are cleaned to that alphabet:
 * typographic quotes/dashes/ellipsis become plain ones, accented letters outside the set lose their
 * accent, emoji and everything else is dropped. Characters of the extension table (^ { } \ [ ~ ] | and the
 * euro sign) count twice.
 */
const SMS_MAX_CHARS = 160;
const SMS_GSM_BASIC = '@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const SMS_GSM_EXT = '^{}\\[~]|€';

function sms_gsm_clean($s) {
	$s = strtr((string)$s, array("\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"', "\u{2013}" => '-', "\u{2014}" => '-', "\u{2011}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{00D7}" => 'x', "\u{00B4}" => "'", "`" => "'"));
	$out = '';
	foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
		if (preg_match('/\s/u', $ch)) { $out .= ' '; continue; }
		if (mb_strpos(SMS_GSM_BASIC, $ch) !== false || mb_strpos(SMS_GSM_EXT, $ch) !== false) { $out .= $ch; continue; }
		if (class_exists('Normalizer')) {
			$base = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($ch, Normalizer::FORM_D));
			if ($base !== '' && mb_strlen($base) === 1 && (mb_strpos(SMS_GSM_BASIC, $base) !== false)) { $out .= $base; }
		}
	}
	return trim(preg_replace('/ {2,}/', ' ', $out));
}

// length in GSM units: extension characters count twice
function sms_gsm_len($s) {
	$n = 0;
	foreach (preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY) as $ch) { $n += (mb_strpos(SMS_GSM_EXT, $ch) !== false) ? 2 : 1; }
	return $n;
}

// prefix + variable part + suffix within 160 GSM units; only the variable part is shortened (with "...")
function sms_fit($prefix, $variable, $suffix = '') {
	$prefix = sms_gsm_clean($prefix); $suffix = sms_gsm_clean($suffix); $var = sms_gsm_clean($variable);
	$room = SMS_MAX_CHARS - sms_gsm_len($prefix) - sms_gsm_len($suffix);
	if ($room < 4) { return sms_gsm_clean(mb_substr($prefix.$suffix, 0, SMS_MAX_CHARS)); }
	if (sms_gsm_len($var) > $room) {
		while ($var !== '' && sms_gsm_len($var) + 3 > $room) { $var = mb_substr($var, 0, mb_strlen($var) - 1); }
		$var = rtrim($var).'...';
	}
	return $prefix.$var.$suffix;
}

function sms_weekday($date) {
	$w = array('So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa');
	return $w[(int)date('w', strtotime($date))];
}

// $r: brand, date (Y-m-d), time (HH:MM[:SS]), pax, number, phone (the restaurant's phone number), link (short cancel link or '')
function sms_text($event, $r) {
	$d = sms_weekday($r['date']).' '.date('d.m.', strtotime($r['date']));
	$t = substr($r['time'], 0, 5);
	$pax = (int)$r['pax'];
	$people = $pax === 1 ? '1 Person' : $pax.' Personen';
	$tel = isset($r['phone']) ? trim($r['phone']) : '';
	$link = isset($r['link']) ? trim($r['link']) : '';
	if ($event === 'reminder') {
		$tail = $link !== '' ? ' Schaffst du es nicht? Absage: '.$link : ($tel !== '' ? ' Schaffst du es nicht, sag uns bitte kurz Bescheid: '.$tel.'.' : '');
		return sms_fit('', $r['brand'], ': Bis morgen! Dein Tisch um '.$t.' Uhr für '.$people.' ist reserviert.'.$tail);
	}
	$tail = $link !== '' ? ' Absage: '.$link : ($tel !== '' ? ' Fragen oder Absage: '.$tel.'.' : '');
	return sms_fit('', $r['brand'], ': Deine Reservierung ist bestätigt! '.$d.' um '.$t.' Uhr, '.$people.'. Buchungsnummer '.$r['number'].'.'.$tail.' Bis bald!');
}

// ---- short cancel link through the own YOURLS (plugin "Expiry": the short link expires by itself)
function sms_link_cfg() {
	$url = trim((string)sms_setting('yourls_url'));
	$sig = sms_setting('yourls_sig');
	$sig = $sig ? sms_decrypt($sig) : null;
	return array(
		'enabled' => sms_setting('cancel_link') === '1',
		'url' => $url !== '' ? $url : 'https://amds.at/yourls-api.php',
		'sig' => $sig !== null ? $sig : '',
		'sig_masked' => ($sig !== null && $sig !== '') ? '••••'.substr($sig, -4) : null,
	);
}

function sms_link_ready() {
	try { $c = sms_link_cfg(); } catch (Throwable $e) { return false; }
	return $c['enabled'] && $c['sig'] !== '' && stripos($c['url'], 'https://') === 0;
}

/*
 * Call YOURLS (action shorturl + Expiry plugin: expiry=clock, age in minutes). The keyword is random (8 characters,
 * ~2.8e12 possibilities) because a guessable short link would let anyone cancel other people's tables.
 * Returns array('ok' => bool, 'short' => url|null, 'error' => string|null, 'expiry' => YOURLS' answer, 'expiry_ok' => bool). Never throws.
 */
function sms_yourls_shorten($long_url, $minutes) {
	$c = sms_link_cfg();
	if ($c['sig'] === '' || stripos($c['url'], 'https://') !== 0) { return array('ok' => false, 'short' => null, 'error' => 'YOURLS ist nicht eingerichtet'); }
	$alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
	$last = null;
	for ($try = 0; $try < 2; $try++) {
		$kw = '';
		for ($i = 0; $i < 8; $i++) { $kw .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
		$ch = curl_init($c['url']);
		curl_setopt_array($ch, array(
			CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4,
			CURLOPT_POSTFIELDS => http_build_query(array(
				'signature' => $c['sig'], 'action' => 'shorturl', 'format' => 'json', 'url' => $long_url, 'keyword' => $kw,
				'title' => 'mySeat Absage', 'expiry' => 'clock', 'age' => max(60, (int)$minutes), 'ageMod' => 'min',
			)),
		));
		$raw = curl_exec($ch);
		$errno = curl_errno($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($errno || !is_string($raw)) { return array('ok' => false, 'short' => null, 'error' => 'YOURLS nicht erreichbar'); }
		$j = json_decode($raw, true);
		if (!is_array($j)) { return array('ok' => false, 'short' => null, 'error' => 'Unerwartete Antwort von YOURLS (HTTP '.$http.')'); }
		$short = isset($j['shorturl']) && is_string($j['shorturl']) ? $j['shorturl'] : '';
		// "already exists" also delivers the existing short link (same reservation asked twice)
		if ($short !== '' && preg_match('#^https://[^\s]+$#', $short) && (($j['status'] ?? '') === 'success' || ($j['code'] ?? '') === 'error:url')) {
			// the Expiry plugin adds 'expiry' to the answer ("60 min expiry set."). Newer YOURLS versions put a 'code' into
			// every answer, then the plugin's hook on new links does nothing: set the expiry with a second call instead
			$exp = isset($j['expiry']) && is_string($j['expiry']) ? substr($j['expiry'], 0, 160) : '';
			if (($j['status'] ?? '') === 'success' && !preg_match('/expiry set/i', $exp)) {
				$exp = sms_yourls_set_expiry($short, $minutes);
			}
			return array('ok' => true, 'short' => $short, 'error' => null, 'expiry' => $exp, 'expiry_ok' => (bool)preg_match('/expiry set/i', $exp), 'keys' => array_keys($j));
		}
		$last = isset($j['message']) && is_string($j['message']) ? substr($j['message'], 0, 120) : 'Fehler (HTTP '.$http.')';
		if (($j['code'] ?? '') !== 'error:keyword') { break; }
	}
	return array('ok' => false, 'short' => null, 'error' => $last);
}

// set the expiry of an existing short link (action=expiry, needs the signature); postx=none: delete it when expired
function sms_yourls_set_expiry($short, $minutes) {
	$c = sms_link_cfg();
	$ch = curl_init($c['url']);
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 4,
		CURLOPT_POSTFIELDS => http_build_query(array('signature' => $c['sig'], 'action' => 'expiry', 'shorturl' => $short, 'format' => 'json',
			'expiry' => 'clock', 'age' => max(60, (int)$minutes), 'ageMod' => 'min', 'postx' => 'none'))));
	$raw = curl_exec($ch);
	curl_close($ch);
	$j = is_string($raw) ? json_decode($raw, true) : null;
	if (is_array($j) && isset($j['expiry']) && is_string($j['expiry']) && preg_match('/expiry set/i', $j['expiry']) && (int)($j['statusCode'] ?? 200) < 400) { return substr($j['expiry'], 0, 160); }
	return is_array($j) && isset($j['message']) && is_string($j['message']) ? substr($j['message'], 0, 160) : '';
}

// what YOURLS/Expiry says about the expiry of a short link (action=expiry-stats): array('http' => int, 'message' => string, 'keys' => array)
function sms_yourls_expiry_stats($short) {
	$c = sms_link_cfg();
	$ch = curl_init($c['url']);
	curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
		CURLOPT_POSTFIELDS => http_build_query(array('signature' => $c['sig'], 'action' => 'expiry-stats', 'shorturl' => $short, 'format' => 'json'))));
	$raw = curl_exec($ch);
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$j = is_string($raw) ? json_decode($raw, true) : null;
	$msg = is_array($j) ? (isset($j['message']) && is_string($j['message']) ? $j['message'] : (isset($j['simple']) && is_string($j['simple']) ? $j['simple'] : '')) : '';
	return array('http' => $http, 'message' => substr($msg, 0, 200), 'keys' => is_array($j) ? array_keys($j) : array());
}

/*
 * Expired short links are deleted by the Expiry plugin when somebody opens them, so links nobody clicks would stay
 * in YOURLS. The cron therefore asks YOURLS to prune all expired links (action=prune, scope=expired) at most once
 * an hour. Never throws.
 */
function sms_yourls_prune_if_due() {
	try {
		if (!sms_link_ready()) { return; }
		$last = (int)sms_setting('yourls_prune_at');
		if ($last > time() - 3600) { return; }
		$c = sms_link_cfg();
		$ch = curl_init($c['url']);
		curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10,
			CURLOPT_POSTFIELDS => http_build_query(array('signature' => $c['sig'], 'action' => 'prune', 'scope' => 'expired', 'format' => 'json'))));
		$raw = curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$j = is_string($raw) ? json_decode($raw, true) : null;
		if ($http === 200 && is_array($j) && (int)($j['statusCode'] ?? 0) < 400) { sms_setting_set('yourls_prune_at', (string)time()); }
		else { error_log('mySeat YOURLS prune failed (HTTP '.$http.')'); }
	} catch (Throwable $e) {
		error_log('mySeat YOURLS prune: '.$e->getMessage());
	}
}

// short cancel link for a reservation, valid until the morning after the reservation day; null when off or on any problem
function sms_cancel_short_link($reservation_id, $booking_number, $date) {
	if (!$reservation_id || !sms_link_ready()) { return null; }
	try {
		$end = strtotime($date.' +1 day 06:00');
		$minutes = $end ? (int)ceil(($end - time()) / 60) : 0;
		if ($minutes < 60) { return null; }
		$res = sms_yourls_shorten(cl_cancel_url($reservation_id, $booking_number), $minutes);
		if (!$res['ok']) { error_log('mySeat cancel link: '.$res['error']); return null; }
		// the link still works without an expiry (cancel.php checks the token), but say so in the log
		if (empty($res['expiry_ok']) && strpos((string)($res['expiry'] ?? ''), 'error:url') === false && ($res['expiry'] ?? '') !== '') { error_log('mySeat cancel link: no expiry set ('.$res['expiry'].')'); }
		elseif (($res['expiry'] ?? '') === '') { error_log('mySeat cancel link: YOURLS did not confirm an expiry (plugin Expiry active?)'); }
		return $res['short'];
	} catch (Throwable $e) {
		error_log('mySeat cancel link: '.$e->getMessage());
		return null;
	}
}

/*
 * Queue and try once. $reservation_id + $event_type are unique: the same SMS is never queued twice
 * (a reminder run that overlaps itself, a confirmation for a reservation approved twice ...).
 * Returns 'accepted' | 'queued' | 'failed' | 'duplicate' | 'off'.
 */
function sms_enqueue($reservation_id, $phone, $event_type, $text) {
	if (!sms_enabled()) { return 'off'; }
	try {
		sms_ensure_schema();
		$db = fb_db();
		$key = 'myseat-'.bin2hex(random_bytes(12));
		$st = mysqli_prepare($db, "INSERT IGNORE INTO ".fb_t('tp_sms_outbox')." (reservation_id, event_type, phone, text, idempotency_key, next_attempt_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())");
		$rid = $reservation_id ? (int)$reservation_id : null;
		mysqli_stmt_bind_param($st, 'issss', $rid, $event_type, $phone, $text, $key);
		mysqli_stmt_execute($st);
		if (mysqli_stmt_affected_rows($st) !== 1) { return 'duplicate'; }
		$id = (int)mysqli_insert_id($db);
		sms_attempt($id);
		$row = fb_rows("SELECT status FROM ".fb_t('tp_sms_outbox')." WHERE id = ".$id);
		return $row ? $row[0]['status'] : 'failed';
	} catch (Throwable $e) {
		error_log('mySeat sms enqueue: '.$e->getMessage());
		return 'failed';
	}
}

// one attempt for an outbox row: accepted / retry later / dropped
function sms_attempt($id) {
	static $unreachable = false;
	if ($unreachable) { return; } // already down in this request: the rest waits for the cron
	$rows = fb_rows("SELECT *, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s FROM ".fb_t('tp_sms_outbox')." WHERE id = ".(int)$id." AND status = 'queued'");
	if (!$rows) { return; }
	$row = $rows[0];
	$res = sms_request('POST', '/v1/messages', array('to' => $row['phone'], 'text' => $row['text']), $row['idempotency_key']);
	$http = $res['http'];
	$attempts = (int)$row['attempts'] + 1;
	$db = fb_db();
	if ($http === 200 || $http === 202) {
		$mid = is_string($res['body']['id'] ?? null) ? substr($res['body']['id'], 0, 80) : null;
		$st = mysqli_prepare($db, "UPDATE ".fb_t('tp_sms_outbox')." SET status = 'accepted', attempts = ?, api_message_id = ?, last_error = NULL, updated_at = NOW() WHERE id = ?");
		$i = (int)$id; mysqli_stmt_bind_param($st, 'isi', $attempts, $mid, $i); mysqli_stmt_execute($st);
		return;
	}
	$reason = sms_describe($res);
	$retry = ($http === 0 || $http === 429 || $http >= 500);
	if ($http === 0) { $unreachable = true; }
	$i = (int)$id;
	if ($retry && $attempts < SMS_MAX_ATTEMPTS && (int)$row['age_s'] < SMS_MAX_AGE_SECONDS) {
		$delay = SMS_BACKOFF[min($attempts, count(SMS_BACKOFF)) - 1];
		$st = mysqli_prepare($db, "UPDATE ".fb_t('tp_sms_outbox')." SET attempts = ?, last_error = ?, next_attempt_at = DATE_ADD(NOW(), INTERVAL ? SECOND), updated_at = NOW() WHERE id = ?");
		mysqli_stmt_bind_param($st, 'isii', $attempts, $reason, $delay, $i); mysqli_stmt_execute($st);
		return;
	}
	$final = $retry ? 'verworfen nach '.$attempts.' Versuchen: '.$reason : $reason;
	$st = mysqli_prepare($db, "UPDATE ".fb_t('tp_sms_outbox')." SET status = 'failed', attempts = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
	mysqli_stmt_bind_param($st, 'isi', $attempts, $final, $i); mysqli_stmt_execute($st);
}

// cron: retry what is due (at most $limit per run - the gateway takes 10 per minute); returns how many were tried
function sms_flush($limit = 8) {
	if (!sms_enabled()) { return 0; }
	sms_ensure_schema();
	$ids = fb_rows("SELECT id FROM ".fb_t('tp_sms_outbox')." WHERE status = 'queued' AND next_attempt_at <= NOW() ORDER BY id LIMIT ".max(1, (int)$limit));
	foreach ($ids as $r) { sms_attempt((int)$r['id']); }
	sms_yourls_prune_if_due();
	// the numbers are personal data: keep finished entries for 30 days only
	mysqli_query(fb_db(), "DELETE FROM ".fb_t('tp_sms_outbox')." WHERE status <> 'queued' AND created_at < (NOW() - INTERVAL 30 DAY)");
	return count($ids);
}

// connection test against GET /v1/health (sends nothing)
function sms_health() {
	$res = sms_request('GET', '/v1/health');
	$ok = ($res['http'] === 200 && (($res['body']['status'] ?? '') === 'ok'));
	return array('ok' => $ok, 'project' => is_string($res['body']['project'] ?? null) ? $res['body']['project'] : null, 'error' => $ok ? null : sms_describe($res));
}

function sms_stats() {
	sms_ensure_schema();
	$r = fb_rows("SELECT SUM(status = 'queued') AS queued, SUM(status = 'failed' AND created_at >= (NOW() - INTERVAL 7 DAY)) AS failed_week, SUM(status = 'accepted' AND created_at >= CURDATE()) AS accepted_today FROM ".fb_t('tp_sms_outbox'));
	$r = $r ? $r[0] : array();
	return array('queued' => (int)($r['queued'] ?? 0), 'failed_week' => (int)($r['failed_week'] ?? 0), 'accepted_today' => (int)($r['accepted_today'] ?? 0));
}

function sms_describe($res) {
	if ($res['http'] === 0) { return 'Gateway nicht erreichbar'.($res['error'] ? ' ('.$res['error'].')' : ''); }
	$api = $res['body']['error'] ?? $res['body']['message'] ?? null;
	$labels = array(401 => 'Schlüssel fehlt oder ist falsch', 429 => 'Limit des Gateways erreicht', 503 => 'Gateway nicht verfügbar');
	$text = isset($labels[$res['http']]) ? $labels[$res['http']] : (is_string($api) ? $api : 'Fehler');
	return substr($text.' (HTTP '.$res['http'].')', 0, 250);
}

function sms_request($method, $path, $json = null, $idempotency_key = null) {
	$c = sms_cfg();
	$headers = array('Authorization: Bearer '.$c['api_key'], 'Accept: application/json');
	$ch = curl_init(sms_base_url().$path);
	$opts = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => $c['timeout'], CURLOPT_FOLLOWLOCATION => false);
	if ($method === 'POST') {
		$opts[CURLOPT_POST] = true;
		$opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
		$headers[] = 'Content-Type: application/json';
		if ($idempotency_key !== null) { $headers[] = 'Idempotency-Key: '.$idempotency_key; }
	}
	$opts[CURLOPT_HTTPHEADER] = $headers;
	curl_setopt_array($ch, $opts);
	$raw = curl_exec($ch);
	$errno = curl_errno($ch);
	$error = $errno ? curl_error($ch) : null;
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);
	$body = is_string($raw) ? json_decode($raw, true) : null;
	return array('http' => $errno ? 0 : $http, 'body' => is_array($body) ? $body : null, 'error' => $error);
}

/*
 * One call for the app: normalizes the number, builds the text and queues it. $r keys: reservation_id,
 * phone (guest, as stored), brand, date, time, pax, number, restaurant_phone. Silent when SMS is off or the
 * number is not a mobile number. Returns the enqueue status or 'skip'.
 */
function sms_send_for_reservation($event, $r) {
	if (!sms_enabled()) { return 'off'; }
	$mobile = sms_normalize_phone(html_entity_decode((string)$r['phone'], ENT_QUOTES, 'UTF-8'));
	if ($mobile === null) { return 'skip'; }
	global $settings;
	// the restaurant's number for questions and cancellations: given by the caller, else the one used in the mails
	$tel = !empty($r['restaurant_phone']) ? $r['restaurant_phone'] : (!empty($settings['mailPhone']) ? $settings['mailPhone'] : '');
	$link = sms_cancel_short_link(isset($r['reservation_id']) ? $r['reservation_id'] : 0, $r['number'], $r['date']);
	$text = sms_text($event, array('link' => (string)$link, 'brand' => html_entity_decode((string)$r['brand'], ENT_QUOTES, 'UTF-8'), 'date' => $r['date'], 'time' => $r['time'], 'pax' => $r['pax'], 'number' => $r['number'], 'phone' => html_entity_decode((string)$tel, ENT_QUOTES, 'UTF-8')));
	return sms_enqueue(isset($r['reservation_id']) ? $r['reservation_id'] : null, $mobile, $event, $text);
}
