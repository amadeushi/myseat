<?php
/*
 * Newsletter of the restaurant: one list of subscribers with two topics ("Sparten"):
 *   news   - news from the restaurant (offers, new dishes, opening times)
 *   stamps - reminders about the stamp card (only for guests with an account)
 * A guest is only mailable for a topic after the confirmation link of the double opt-in mail was used. Every step is written to an
 * append-only log (time, address of the visitor, browser, source, text of the tick) as the proof the sender has to be able to give.
 * Addresses of the former system or of reservations without proof are kept "blocked": they never get a mail until the guest confirms.
 * Sources: shop (checkout), chat, konto, widget (reservation form), backend (typed in by staff, the guest confirms), import.
 */

const SHOP_NL_CONFIRM_DAYS = 14;       // how long the link of the confirmation mail works
const SHOP_NL_KEEP_YEARS = 3;          // the proof is kept this long after the guest unsubscribed, then deleted
const SHOP_NL_TOPICS = array('news', 'stamps');
const SHOP_NL_NEWS_MAX = 2;            // the promise in the tick: at most this many news mails per guest in 30 days (mailings and the news automations together)

function shop_nl_schema($db, $opts) {
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_subscribers')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `email` VARCHAR(160) NOT NULL, `email_key` CHAR(40) NOT NULL, `name` VARCHAR(80) NOT NULL DEFAULT '', `account_id` INT UNSIGNED NULL,
		`news` TINYINT NOT NULL DEFAULT 0, `stamps` TINYINT NOT NULL DEFAULT 0, `want_news` TINYINT NOT NULL DEFAULT 0, `want_stamps` TINYINT NOT NULL DEFAULT 0, `blocked` TINYINT NOT NULL DEFAULT 0,
		`token` CHAR(32) NOT NULL, `confirm_token` CHAR(32) NULL, `confirm_sent_at` DATETIME NULL, `confirmed_at` DATETIME NULL, `unsub_at` DATETIME NULL,
		`source` VARCHAR(16) NOT NULL DEFAULT '', `lang` CHAR(2) NOT NULL DEFAULT 'de', `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL,
		UNIQUE KEY `email` (`email`), UNIQUE KEY `token` (`token`), UNIQUE KEY `ctoken` (`confirm_token`), KEY `ekey` (`email_key`), KEY `acc` (`account_id`)) $opts");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_consent_log')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `sub_id` INT UNSIGNED NOT NULL, `event` VARCHAR(16) NOT NULL, `topics` VARCHAR(24) NOT NULL DEFAULT '', `source` VARCHAR(16) NOT NULL DEFAULT '',
		`ip` VARCHAR(45) NOT NULL DEFAULT '', `ua` VARCHAR(200) NOT NULL DEFAULT '', `detail` VARCHAR(255) NOT NULL DEFAULT '', `at` DATETIME NOT NULL, KEY `sub` (`sub_id`)) $opts");
	shop_nlm_schema($db, $opts);
}

function shop_nl_email($e) { return mb_strtolower(trim((string)$e), 'UTF-8'); }
function shop_nl_valid($e) { $e = shop_nl_email($e); return $e !== '' && strlen($e) <= 160 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }
// the same person with "name+tag@x" or other upper/lower case is one person (for coupons and double entries later)
function shop_nl_key($e) { $e = shop_nl_email($e); $p = explode('@', $e, 2); if (count($p) === 2) { $e = preg_replace('/\+.*$/', '', $p[0]).'@'.$p[1]; } return sha1($e); }
function shop_nl_ip() { return substr(isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '', 0, 45); }
function shop_nl_ua() { return substr(isset($_SERVER['HTTP_USER_AGENT']) ? preg_replace('/[\x00-\x1f]/', '', (string)$_SERVER['HTTP_USER_AGENT']) : '', 0, 200); }
function shop_nl_topics($t) { $o = array(); foreach ((array)$t as $x) { if (in_array($x, SHOP_NL_TOPICS, true) && !in_array($x, $o, true)) { $o[] = $x; } } return $o; }
function shop_nl_topic_label($t, $lang = 'de') {
	$m = array('de' => array('news' => 'Neuigkeiten vom Restaurant', 'stamps' => 'Erinnerungen zu deiner Stempelkarte'), 'en' => array('news' => 'news from the restaurant', 'stamps' => 'reminders about your stamp card'));
	return $m[$lang === 'en' ? 'en' : 'de'][$t];
}

// the words of the two consents exactly as the pages show them (checkout, status page, account, chat); they are written to the proof
function shop_nl_consent_text($topic, $lang = 'de') {
	$t = array(
		'de' => array('news' => 'Ich möchte Neuigkeiten und Aktionen vom Restaurant per Mail erhalten, höchstens 2 Mal im Monat (neue Gerichte, Angebote, Öffnungszeiten)',
			'stamps' => 'Ich möchte Bescheid bekommen, wenn meine Stempelkarte fast voll ist und bevor Stempel verfallen'),
		'en' => array('news' => 'I would like to get news and offers from the restaurant by e-mail, at most twice a month (new dishes, offers, opening times)',
			'stamps' => 'I would like to be told when my stamp card is almost full and before stamps expire'));
	$l = $lang === 'en' ? 'en' : 'de';
	return isset($t[$l][$topic]) ? $t[$l][$topic] : '';
}
function shop_nl_log($subId, $event, $topics, $source, $detail = '') {
	fb_exec("INSERT INTO ".fb_t('tp_shop_consent_log')." (sub_id, event, topics, source, ip, ua, detail, at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)", 'isssssss',
		array((int)$subId, $event, implode(',', (array)$topics), (string)$source, shop_nl_ip(), shop_nl_ua(), mb_substr((string)$detail, 0, 255), date('Y-m-d H:i:s')));
}

function shop_nl_find($email) { shop_ensure_schema(); return fb_row("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE email = ?", 's', array(shop_nl_email($email))); }
function shop_nl_by_token($token) { shop_ensure_schema(); $t = preg_replace('/[^a-f0-9]/', '', (string)$token); return strlen($t) === 32 ? fb_row("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE token = ?", 's', array($t)) : null; }
function shop_nl_by_confirm($token) { shop_ensure_schema(); $t = preg_replace('/[^a-f0-9]/', '', (string)$token); return strlen($t) === 32 ? fb_row("SELECT * FROM ".fb_t('tp_shop_subscribers')." WHERE confirm_token = ?", 's', array($t)) : null; }

// how many news mails this guest got in the last 30 days: mailings that were sent to him and the automations of the news topic (the confirmation mail does not count)
function shop_nl_news_count($subId) {
	$since = date('Y-m-d H:i:s', time() - 30 * 86400); $n = 0;
	$a = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_nl_queue')." WHERE sub_id = ? AND status = 'sent' AND sent_at > ?", 'is', array((int)$subId, $since)); if ($a) { $n += (int)$a['n']; }
	$b = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_nl_auto_log')." WHERE sub_id = ? AND ok = 1 AND kind IN ('welcome', 'winback', 'sunset') AND sent_at > ?", 'is', array((int)$subId, $since)); if ($b) { $n += (int)$b['n']; }
	return $n;
}
// may a mail of this topic go to this address? (confirmed, not blocked, not unsubscribed)
function shop_nl_allows($email, $topic) {
	if (!shop_nl_valid($email) || !in_array($topic, SHOP_NL_TOPICS, true)) { return false; }
	$r = shop_nl_find($email);
	return $r && empty($r['blocked']) && !empty($r[$topic]);
}

/*
 * A guest ticked a box: remember the wish and send the confirmation mail. Nothing is mailable before the link is used.
 * $source: shop, chat, konto, widget, backend. $o: lang (de|en), account_id, text (what the box said, kept as proof).
 * Returns array(ok, state: pending|already, error).
 */
function shop_nl_request($email, $name, $topics, $source, $o = array()) {
	shop_ensure_schema();
	$topics = shop_nl_topics($topics);
	if (!$topics) { return array('ok' => false, 'error' => 'Nichts ausgewählt.'); }
	if (!shop_nl_valid($email)) { return array('ok' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse an.'); }
	if (shop_mail_from() === '') { return array('ok' => false, 'error' => 'Der Mailversand ist noch nicht eingerichtet.'); }
	if (random_int(1, 40) === 1) { shop_nl_purge(); }
	$e = shop_nl_email($email); $lang = (isset($o['lang']) && $o['lang'] === 'en') ? 'en' : 'de'; $now = date('Y-m-d H:i:s');
	$name = mb_substr(trim((string)$name), 0, 80);
	$accId = !empty($o['account_id']) ? (int)$o['account_id'] : null;
	$row = shop_nl_find($e);
	if ($row) {
		// everything asked for is already confirmed: nothing to do (and no second mail to a person who may not even have asked)
		$have = true; foreach ($topics as $t) { if (empty($row[$t]) || !empty($row['blocked'])) { $have = false; } }
		if ($have) { return array('ok' => true, 'state' => 'already'); }
		// not more than three confirmation mails to one address per day (nobody is to be flooded by a stranger typing his address)
		$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ? AND event = 'request' AND at > ?", 'is', array((int)$row['id'], date('Y-m-d H:i:s', time() - 86400)));
		if ($n && (int)$n['n'] >= 3) { return array('ok' => false, 'error' => 'Wir haben dir heute schon Bestätigungsmails geschickt. Bitte schau in dein Postfach (auch im Spam-Ordner).'); }
	}
	// and not more than ten requests from one visitor per hour
	$ip = shop_nl_ip();
	$m = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_consent_log')." WHERE event = 'request' AND ip = ? AND at > ?", 'ss', array($ip, date('Y-m-d H:i:s', time() - 3600)));
	if ($m && (int)$m['n'] >= 10) { return array('ok' => false, 'error' => 'Das waren heute zu viele Anmeldungen von hier. Bitte versuche es später noch einmal.'); }
	$ct = bin2hex(random_bytes(16));
	if ($row) {
		$wn = (!empty($row['want_news']) || in_array('news', $topics, true)) ? 1 : 0; $ws = (!empty($row['want_stamps']) || in_array('stamps', $topics, true)) ? 1 : 0;
		fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET want_news = ?, want_stamps = ?, confirm_token = ?, confirm_sent_at = ?, lang = ?, name = IF(name = '', ?, name), account_id = COALESCE(account_id, ?), updated_at = ? WHERE id = ?",
			'iissssisi', array($wn, $ws, $ct, $now, $lang, $name, $accId, $now, (int)$row['id']));
		$id = (int)$row['id'];
	} else {
		fb_exec("INSERT INTO ".fb_t('tp_shop_subscribers')." (email, email_key, name, account_id, want_news, want_stamps, token, confirm_token, confirm_sent_at, source, lang, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			'sssiiisssssss', array($e, shop_nl_key($e), $name, $accId, in_array('news', $topics, true) ? 1 : 0, in_array('stamps', $topics, true) ? 1 : 0, bin2hex(random_bytes(16)), $ct, $now, (string)$source, $lang, $now, $now));
		$id = (int)mysqli_insert_id(fb_db());
		if ($id <= 0) { return array('ok' => false, 'error' => 'Das hat nicht geklappt. Bitte versuche es noch einmal.'); }
	}
	shop_nl_log($id, 'request', $topics, $source, isset($o['text']) ? (string)$o['text'] : '');
	shop_nl_mail_confirm($e, $name, $topics, $ct, $lang);
	return array('ok' => true, 'state' => 'pending');
}

// the page behind the link of the confirmation mail (only after a tap on its button: mail programs open links on their own)
function shop_nl_confirm($token) {
	shop_ensure_schema();
	$r = shop_nl_by_confirm($token);
	if (!$r) { return array('ok' => false, 'error' => 'Dieser Link ist abgelaufen oder wurde schon benutzt.'); }
	if (!$r['confirm_sent_at'] || strtotime($r['confirm_sent_at']) < time() - SHOP_NL_CONFIRM_DAYS * 86400) { return array('ok' => false, 'error' => 'Dieser Link ist abgelaufen. Bitte melde dich beim nächsten Besuch noch einmal an.'); }
	$topics = array(); if (!empty($r['want_news'])) { $topics[] = 'news'; } if (!empty($r['want_stamps'])) { $topics[] = 'stamps'; }
	$now = date('Y-m-d H:i:s');
	fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET news = GREATEST(news, want_news), stamps = GREATEST(stamps, want_stamps), want_news = 0, want_stamps = 0, blocked = 0, confirm_token = NULL, confirmed_at = ?, unsub_at = NULL, updated_at = ? WHERE id = ?",
		'ssi', array($now, $now, (int)$r['id']));
	shop_nl_log((int)$r['id'], 'confirm', $topics, '', 'Bestätigungslink benutzt');
	if (in_array('news', $topics, true) && function_exists('shop_nla_welcome_now')) { shop_nla_welcome_now((int)$r['id']); }
	return array('ok' => true, 'topics' => $topics, 'token' => $r['token'], 'lang' => $r['lang']);
}

// "yes, I still want the mails" (answer to the question of the sunset mail): written to the proof, the guest counts as active from now on
function shop_nl_keep($token) {
	$r = shop_nl_by_token($token);
	if (!$r || empty($r['news']) || !empty($r['blocked'])) { return array('ok' => false, 'error' => 'Dieser Link ist ungültig.'); }
	shop_nl_log((int)$r['id'], 'keep', array('news'), '', 'Möchte weiter Neuigkeiten erhalten (Antwort auf die Frage nach langer Pause)');
	return array('ok' => true);
}

// the guest (known by his personal link) switches a topic on or off; "on" is fine here because the link proves the mailbox
function shop_nl_set($token, $news, $stamps) {
	$r = shop_nl_by_token($token);
	if (!$r) { return array('ok' => false, 'error' => 'Dieser Link ist ungültig.'); }
	$now = date('Y-m-d H:i:s'); $news = $news ? 1 : 0; $stamps = $stamps ? 1 : 0;
	$was = array(); $nowOn = array();
	foreach (SHOP_NL_TOPICS as $t) { if (!empty($r[$t])) { $was[] = $t; } }
	if ($news) { $nowOn[] = 'news'; } if ($stamps) { $nowOn[] = 'stamps'; }
	$off = (!$news && !$stamps) ? 1 : 0;
	fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET news = ?, stamps = ?, want_news = 0, want_stamps = 0, blocked = IF(? = 1, blocked, 0), unsub_at = IF(? = 1, ?, NULL), confirmed_at = IF(? = 1, confirmed_at, COALESCE(confirmed_at, ?)), updated_at = ? WHERE id = ?",
		'iiiisissi', array($news, $stamps, $off, $off, $now, $off, $now, $now, (int)$r['id']));
	shop_nl_log((int)$r['id'], $off ? 'unsubscribe' : 'change', $nowOn, '', 'vorher: '.implode(',', $was));
	return array('ok' => true, 'news' => $news, 'stamps' => $stamps, 'off' => (bool)$off);
}


// the account page: the guest sets the two topics for an address. Turning one off is immediate; turning one on sends the confirmation mail.
function shop_nl_account_set($acc, $email, $news, $stamps) {
	$email = shop_nl_email($email);
	if (($news || $stamps) && !shop_nl_valid($email)) { return array('ok' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse an.'); }
	$st = shop_nl_state($email); $row = shop_nl_valid($email) ? shop_nl_find($email) : null;
	$need = array(); $msg = '';
	// a tick taken out again while its confirmation is still waiting: the request is withdrawn
	if ($row && ((!$news && !empty($row['want_news'])) || (!$stamps && !empty($row['want_stamps'])))) {
		fb_exec("UPDATE ".fb_t('tp_shop_subscribers')." SET want_news = IF(? = 1, want_news, 0), want_stamps = IF(? = 1, want_stamps, 0), updated_at = ? WHERE id = ?", 'iisi', array($news ? 1 : 0, $stamps ? 1 : 0, date('Y-m-d H:i:s'), (int)$row['id']));
		shop_nl_log((int)$row['id'], 'withdraw', array(), 'konto', 'Anfrage zurückgezogen');
		$msg = 'Gespeichert.';
	}
	if ($news && !$st['news']) { $need[] = 'news'; }
	if ($stamps && !$st['stamps']) { $need[] = 'stamps'; }
	// off: only what is confirmed now and not wanted any more
	if ($row && empty($row['blocked']) && ((!$news && !empty($row['news'])) || (!$stamps && !empty($row['stamps'])))) {
		$keepNews = ($news && !empty($row['news'])) ? 1 : 0; $keepStamps = ($stamps && !empty($row['stamps'])) ? 1 : 0;
		shop_nl_set($row['token'], $keepNews, $keepStamps);
		$msg = 'Gespeichert.';
	}
	if ($need) {
		$r = shop_nl_request($email, isset($acc['name']) ? $acc['name'] : '', $need, 'konto', array('account_id' => (int)$acc['id'], 'lang' => 'de',
			'text' => implode(' | ', array_map(function ($t) { return shop_nl_consent_text($t, 'de'); }, $need))));
		if (!$r['ok']) { return $r; }
		$msg = $r['state'] === 'already' ? 'Gespeichert.' : 'Wir haben dir eine Mail zum Bestätigen geschickt. Erst danach gilt die Anmeldung.';
	}
	return array('ok' => true, 'message' => $msg !== '' ? $msg : 'Nichts geändert.', 'newsletter' => shop_nl_state($email));
}


// one line for the reservation views: what is known about this guest's address
function shop_nl_badge($email) {
	$r = shop_nl_valid($email) ? shop_nl_find($email) : null;
	if (!$r) { return array('state' => 'none', 'label' => 'Newsletter: nein'); }
	if (!empty($r['blocked'])) { return array('state' => 'blocked', 'label' => 'Newsletter: gesperrt (Altbestand, nicht bestätigt)'); }
	if (!empty($r['news']) || !empty($r['stamps'])) { return array('state' => 'confirmed', 'label' => 'Newsletter: bestätigt'); }
	if (!empty($r['want_news']) || !empty($r['want_stamps'])) { return array('state' => 'pending', 'label' => 'Newsletter: Bestätigung ausstehend'); }
	return array('state' => 'off', 'label' => 'Newsletter: abgemeldet');
}

// state of an address for the pages: which topics are confirmed, which wait for the confirmation
function shop_nl_state($email) {
	$out = array('news' => false, 'stamps' => false, 'pending' => false, 'want_news' => false, 'want_stamps' => false, 'email' => shop_nl_email($email));
	if (!shop_nl_valid($email)) { return $out; }
	$r = shop_nl_find($email);
	if (!$r) { return $out; }
	if (empty($r['blocked'])) { $out['news'] = !empty($r['news']); $out['stamps'] = !empty($r['stamps']); }
	$out['pending'] = (!empty($r['want_news']) || !empty($r['want_stamps'])) && $r['confirm_sent_at'] && strtotime($r['confirm_sent_at']) > time() - SHOP_NL_CONFIRM_DAYS * 86400;
	if ($out['pending']) { $out['want_news'] = !empty($r['want_news']); $out['want_stamps'] = !empty($r['want_stamps']); }
	return $out;
}

// an order or a reservation came with ticks: the request goes out for the ticked topics
function shop_nl_from_form($email, $name, $news, $stamps, $source, $accountId = 0, $lang = 'de', $shownText = null) {
	$t = array(); if ($news) { $t[] = 'news'; } if ($stamps) { $t[] = 'stamps'; }
	if (!$t || !shop_nl_valid($email)) { return null; }
	$shown = array(); foreach ($t as $x) { $shown[] = shop_nl_consent_text($x, $lang); }
	// the page may pass the text it really showed (the reservation form has its own wording)
	$text = ($shownText !== null && trim((string)$shownText) !== '') ? trim((string)$shownText) : implode(' | ', $shown);
	try { return shop_nl_request($email, $name, $t, $source, array('lang' => $lang, 'account_id' => $accountId, 'text' => $text)); }
	catch (Throwable $e) { error_log('mySeat newsletter: '.$e->getMessage()); return null; }
}

function shop_nl_base() { return shop_stamp_shop_url(); }

// the confirmation mail (german or english), in the look of the other mails to guests
function shop_nl_mail_confirm($email, $name, $topics, $confirmToken, $lang) {
	try {
		$brand = function_exists('shop_acc_brand') ? shop_acc_brand() : 'Amadeus'; $en = ($lang === 'en');
		$first = function_exists('shop_stamp_first_name') ? shop_stamp_first_name($name) : '';
		$labels = array(); foreach ($topics as $t) { $labels[] = shop_nl_topic_label($t, $lang); }
		$what = implode($en ? ' and ' : ' und ', $labels);
		$url = shop_nl_base().'newsletter.php?t='.$confirmToken;
		// the subject names what was asked for, so the guest recognises his own request at a glance
		$only = count($topics) === 1 ? $topics[0] : '';
		$subEn = $only === 'stamps' ? 'Please confirm: stamp card reminders from '.$brand : ($only === 'news' ? 'Please confirm: news from '.$brand : 'Please confirm your sign-up with '.$brand);
		$subDe = $only === 'stamps' ? 'Bitte bestätige: Stempel-Erinnerungen vom '.$brand : ($only === 'news' ? 'Bitte bestätige: Neuigkeiten vom '.$brand : 'Bitte bestätige deine Anmeldung beim '.$brand);
		shop_stamp_mail($email, $en ? array(
			'subject' => $subEn, 'purpose' => 'news', 'headline' => 'Almost done'.($first !== '' ? ', '.$first : '').'!',
			'lead' => 'You signed up with '.$brand.' for: '.$what.'. Please confirm with one tap so that we know this address is yours.',
			'image' => '', 'alt' => '', 'box' => null,
			'after' => 'You did not sign up? Then just ignore this mail, without your tap you will not get anything. The link works for '.SHOP_NL_CONFIRM_DAYS.' days. You can unsubscribe at any time.',
			'btn' => 'Confirm sign-up', 'url' => $url, 'zeus' => false, 'signoff' => 'Your team at '.$brand
		) : array(
			'subject' => $subDe, 'purpose' => 'news', 'headline' => 'Fast geschafft'.($first !== '' ? ', '.$first : '').'!',
			'lead' => 'Du hast dich bei '.$brand.' angemeldet für: '.$what.'. Bitte bestätige das mit einem Tipp, damit wir wissen, dass diese Adresse dir gehört.',
			'image' => '', 'alt' => '', 'box' => null,
			'after' => 'Du hast dich nicht angemeldet? Dann ignoriere diese Mail einfach, ohne deinen Tipp bekommst du nichts. Der Link gilt '.SHOP_NL_CONFIRM_DAYS.' Tage. Abmelden kannst du dich jederzeit.',
			'btn' => 'Anmeldung bestätigen', 'url' => $url, 'zeus' => false, 'signoff' => 'Dein Team vom '.$brand));
	} catch (Throwable $e) { error_log('mySeat newsletter mail: '.$e->getMessage()); }
}

// the list of the backend: counts and rows
function shop_nl_counts() {
	shop_ensure_schema();
	$r = fb_row("SELECT COUNT(*) AS total, SUM(blocked = 0 AND news = 1) AS news, SUM(blocked = 0 AND stamps = 1) AS stamps, SUM(blocked = 0 AND news = 0 AND stamps = 0 AND (want_news = 1 OR want_stamps = 1)) AS pending,
		SUM(news = 0 AND stamps = 0 AND unsub_at IS NOT NULL) AS unsub, SUM(blocked = 1) AS blocked FROM ".fb_t('tp_shop_subscribers'));
	return array('total' => (int)$r['total'], 'news' => (int)$r['news'], 'stamps' => (int)$r['stamps'], 'pending' => (int)$r['pending'], 'unsub' => (int)$r['unsub'], 'blocked' => (int)$r['blocked']);
}
function shop_nl_list($filter = 'all', $q = '', $limit = 200) {
	shop_ensure_schema();
	$w = array('1 = 1'); $t = ''; $p = array();
	if ($filter === 'news') { $w[] = 'blocked = 0 AND news = 1'; }
	elseif ($filter === 'stamps') { $w[] = 'blocked = 0 AND stamps = 1'; }
	elseif ($filter === 'pending') { $w[] = 'blocked = 0 AND news = 0 AND stamps = 0 AND (want_news = 1 OR want_stamps = 1)'; }
	elseif ($filter === 'unsub') { $w[] = 'news = 0 AND stamps = 0 AND unsub_at IS NOT NULL'; }
	elseif ($filter === 'blocked') { $w[] = 'blocked = 1'; }
	$q = trim((string)$q);
	if ($q !== '') { $w[] = '(email LIKE ? OR name LIKE ?)'; $t = 'ss'; $p = array('%'.$q.'%', '%'.$q.'%'); }
	return fb_rows("SELECT id, email, name, news, stamps, want_news, want_stamps, blocked, source, confirmed_at, unsub_at, created_at FROM ".fb_t('tp_shop_subscribers')." WHERE ".implode(' AND ', $w)." ORDER BY id DESC LIMIT ".(int)$limit, $t, $p);
}
function shop_nl_proof($id) { shop_ensure_schema(); return fb_rows("SELECT event, topics, source, ip, ua, detail, at FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ? ORDER BY id", 'i', array((int)$id)); }
// erase one subscriber with his proof (a guest asks for deletion)
function shop_nl_erase($id) { shop_ensure_schema(); fb_exec("DELETE FROM ".fb_t('tp_shop_consent_log')." WHERE sub_id = ?", 'i', array((int)$id)); fb_exec("DELETE FROM ".fb_t('tp_shop_subscribers')." WHERE id = ?", 'i', array((int)$id)); }

// tidy up: requests nobody confirmed (30 days), and unsubscribed addresses with their proof after three years
function shop_nl_purge() {
	shop_ensure_schema();
	$old = date('Y-m-d H:i:s', time() - 30 * 86400);
	$ids = fb_rows("SELECT id FROM ".fb_t('tp_shop_subscribers')." WHERE news = 0 AND stamps = 0 AND blocked = 0 AND unsub_at IS NULL AND confirmed_at IS NULL AND confirm_sent_at IS NOT NULL AND confirm_sent_at < ?", 's', array($old));
	$gone = date('Y-m-d H:i:s', strtotime('-'.SHOP_NL_KEEP_YEARS.' years'));
	foreach (fb_rows("SELECT id FROM ".fb_t('tp_shop_subscribers')." WHERE news = 0 AND stamps = 0 AND unsub_at IS NOT NULL AND unsub_at < ?", 's', array($gone)) as $r) { $ids[] = $r; }
	foreach ($ids as $r) { shop_nl_erase((int)$r['id']); }
}
require_once __DIR__.'/shop_newsletter_import.class.php';
require_once __DIR__.'/shop_newsletter_mail.class.php';
require_once __DIR__.'/shop_newsletter_auto.class.php';
