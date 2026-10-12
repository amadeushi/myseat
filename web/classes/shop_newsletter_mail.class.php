<?php
/*
 * Newsletter mailings (step 3): a mailing is a list of blocks (heading, text, picture, button, box, divider) made in the block editor of the backend page
 * "Newsletter". The server turns the blocks into the mail (html and plain text) and never trusts html from the browser: text is cleaned to a few tags and links.
 * Sending goes through a queue: "start" copies the guests who may get news (confirmed, not blocked, not unsubscribed) into the queue, a runner sends a few
 * mails per run (web/cron/send_newsletter.php, and while the backend page is open). Before every mail the permission is checked again.
 * Every mail carries the link to unsubscribe (footer and List-Unsubscribe header, one tap). No tracking of opens or clicks.
 * Merge tags in text, subject and links: {vorname} {name} (with a fallback {vorname|Gast}), {abmelden} (= the page to manage or leave).
 */

require_once __DIR__.'/shop_mail.class.php';   // brings the mail sender (bm_send_guest_mail) and the legal lines

const SHOP_NLM_MAX_BLOCKS = 40;
const SHOP_NLM_PER_RUN = 30;

function shop_nlm_schema($db, $opts) {
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_nl_campaigns')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `name` VARCHAR(120) NOT NULL DEFAULT '', `subject` VARCHAR(200) NOT NULL DEFAULT '', `preheader` VARCHAR(200) NOT NULL DEFAULT '',
		`blocks` MEDIUMTEXT NULL, `status` VARCHAR(12) NOT NULL DEFAULT 'draft', `created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, `started_at` DATETIME NULL, `finished_at` DATETIME NULL) $opts");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_nl_queue')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `campaign_id` INT UNSIGNED NOT NULL, `sub_id` INT UNSIGNED NOT NULL, `email` VARCHAR(160) NOT NULL, `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
		`error` VARCHAR(120) NOT NULL DEFAULT '', `sent_at` DATETIME NULL, UNIQUE KEY `cs` (`campaign_id`, `sub_id`), KEY `st` (`status`, `campaign_id`)) $opts");
}

// ---- cleaning what the browser sends -------------------------------------------------------------------------------------------------------------------

// a link that may be in a mail: http(s), mailto, tel, or one of our own tags
function shop_nlm_link($u) {
	$u = trim((string)$u);
	if (strlen($u) > 500) { return ''; }
	if ($u === '{abmelden}') { return $u; }
	if (preg_match('#^(mailto:|tel:)#i', $u) && !preg_match('/[\s"<>]/', $u)) { return $u; }
	if (preg_match('#^https?://#i', $u) && !preg_match('/[\s"<>]/', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false) { return $u; }
	return '';
}

function shop_nlm_walk($n) {
	$o = '';
	foreach ($n->childNodes as $c) {
		if ($c->nodeType === XML_TEXT_NODE) { $o .= htmlspecialchars($c->nodeValue, ENT_QUOTES, 'UTF-8'); continue; }
		if ($c->nodeType !== XML_ELEMENT_NODE) { continue; }
		$t = strtolower($c->nodeName);
		if (in_array($t, array('script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'), true)) { continue; }
		$in = shop_nlm_walk($c);
		if ($t === 'b' || $t === 'strong') { $o .= '<strong>'.$in.'</strong>'; }
		elseif ($t === 'i' || $t === 'em') { $o .= '<em>'.$in.'</em>'; }
		elseif ($t === 'u') { $o .= '<u>'.$in.'</u>'; }
		elseif ($t === 'br') { $o .= '<br>'; }
		elseif ($t === 'a') { $h = shop_nlm_link($c->getAttribute('href')); $o .= $h !== '' ? '<a href="'.htmlspecialchars($h, ENT_QUOTES, 'UTF-8').'" style="color:#8a6d3b;">'.$in.'</a>' : $in; }
		elseif ($t === 'ul' || $t === 'ol') { $o .= '<'.$t.' style="margin:0 0 10px;padding-left:22px;">'.$in.'</'.$t.'>'; }
		elseif ($t === 'li') { $o .= '<li style="margin:0 0 4px;">'.$in.'</li>'; }
		elseif ($t === 'div' || $t === 'p') { $o .= $in.($in !== '' && substr($in, -4) !== '<br>' ? '<br>' : ''); }
		else { $o .= $in; }
	}
	return $o;
}
// text of a block: only bold, italic, underline, links, line breaks and lists survive
function shop_nlm_inline($html) {
	$html = (string)$html;
	if (trim($html) === '') { return ''; }
	$d = new DOMDocument();
	libxml_use_internal_errors(true);
	$d->loadHTML('<?xml encoding="utf-8"?><div id="nlroot">'.mb_substr($html, 0, 12000).'</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
	libxml_clear_errors();
	$x = new DOMXPath($d); $root = $x->query('//div[@id="nlroot"]')->item(0);
	if (!$root) { return ''; }
	$o = shop_nlm_walk($root);
	$o = preg_replace('#(<br>\s*){3,}#', '<br><br>', $o);
	$o = preg_replace('#^(\s*<br>)+|(<br>\s*)+$#', '', $o);
	return mb_substr($o, 0, 8000);
}

function shop_nlm_str($v, $max) { return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max); }

function shop_nlm_clean_blocks($b) {
	if (is_string($b)) { $b = json_decode($b, true); }
	if (!is_array($b)) { return array(); }
	$out = array();
	foreach (array_slice($b, 0, SHOP_NLM_MAX_BLOCKS) as $x) {
		if (!is_array($x) || empty($x['t'])) { continue; }
		switch ($x['t']) {
			case 'h': $out[] = array('t' => 'h', 'text' => shop_nlm_str(isset($x['text']) ? $x['text'] : '', 160)); break;
			case 'p': $out[] = array('t' => 'p', 'html' => shop_nlm_inline(isset($x['html']) ? $x['html'] : '')); break;
			case 'img':
				$s = isset($x['src']) ? trim((string)$x['src']) : '';
				if (!preg_match('#^https?://#i', $s) || filter_var($s, FILTER_VALIDATE_URL) === false || strlen($s) > 500 || preg_match('/[\s"<>]/', $s)) { $s = ''; }
				$out[] = array('t' => 'img', 'src' => $s, 'alt' => shop_nlm_str(isset($x['alt']) ? $x['alt'] : '', 120), 'link' => shop_nlm_link(isset($x['link']) ? $x['link'] : ''));
				break;
			case 'btn': $out[] = array('t' => 'btn', 'text' => shop_nlm_str(isset($x['text']) ? $x['text'] : '', 40), 'url' => shop_nlm_link(isset($x['url']) ? $x['url'] : '')); break;
			case 'box': $out[] = array('t' => 'box', 'label' => shop_nlm_str(isset($x['label']) ? $x['label'] : '', 60), 'value' => shop_nlm_str(isset($x['value']) ? $x['value'] : '', 40), 'note' => shop_nlm_str(isset($x['note']) ? $x['note'] : '', 120)); break;
			case 'hr': $out[] = array('t' => 'hr'); break;
		}
	}
	return $out;
}

// ---- rendering -----------------------------------------------------------------------------------------------------------------------------------------

function shop_nlm_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// the tags: {vorname} {vorname|Gast} {name} {abmelden}; $html tells whether the values go into html
function shop_nlm_merge($s, $sub, $html) {
	$first = function_exists('shop_stamp_first_name') ? shop_stamp_first_name(isset($sub['name']) ? $sub['name'] : '') : '';
	$full = trim((string)(isset($sub['name']) ? $sub['name'] : ''));
	$url = shop_nl_base().'newsletter.php?m='.(!empty($sub['token']) ? $sub['token'] : '0');
	return preg_replace_callback('/\{(vorname|name|abmelden)(?:\|([^{}]{0,40}))?\}/u', function ($m) use ($first, $full, $url, $html) {
		$v = $m[1] === 'vorname' ? $first : ($m[1] === 'name' ? $full : $url);
		if ($v === '' && isset($m[2])) { $v = $m[2]; }
		return $html ? shop_nlm_h($v) : $v;
	}, (string)$s);
}

/*
 * The mail of a mailing for one guest. $c: subject, preheader, blocks. $sub: email, name, token. Returns array(subject, html, plain, headers).
 */
function shop_nlm_render($c, $sub, $o = array()) {
	global $settings;
	$brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
	$blocks = shop_nlm_clean_blocks(isset($c['blocks']) ? $c['blocks'] : array());
	$h = 'shop_nlm_h';
	$font = 'font-family:Arial,Helvetica,sans-serif;'; $serif = 'font-family:Georgia,\'Times New Roman\',serif;';
	$rows = ''; $plain = array();
	foreach ($blocks as $b) {
		if ($b['t'] === 'h' && $b['text'] !== '') { $rows .= '<tr><td style="padding:22px 32px 4px;"><div style="'.$serif.'font-size:26px;line-height:1.25;color:#1c1a18;">'.$h($b['text']).'</div></td></tr>'; $plain[] = $b['text']; }
		elseif ($b['t'] === 'p' && $b['html'] !== '') { $rows .= '<tr><td style="padding:10px 32px 4px;"><div style="'.$font.'font-size:15px;color:#555;line-height:1.6;">'.$b['html'].'</div></td></tr>'; $plain[] = shop_nlm_plain($b['html']); }
		elseif ($b['t'] === 'img' && $b['src'] !== '') {
			$img = '<img src="'.$h($b['src']).'" width="496" alt="'.$h($b['alt']).'" style="display:block;width:100%;max-width:496px;height:auto;border:0;border-radius:10px;">';
			$rows .= '<tr><td style="padding:14px 32px 4px;">'.($b['link'] !== '' ? '<a href="'.$h($b['link']).'">'.$img.'</a>' : $img).'</td></tr>';
		}
		elseif ($b['t'] === 'btn' && $b['text'] !== '' && $b['url'] !== '') { $rows .= '<tr><td style="padding:14px 32px 6px;"><a href="'.$h($b['url']).'" style="'.$font.'display:inline-block;padding:13px 24px;background:#8a6d3b;border-radius:6px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;">'.$h($b['text']).'</a></td></tr>'; $plain[] = $b['text'].': '.$b['url']; }
		elseif ($b['t'] === 'box' && ($b['label'] !== '' || $b['value'] !== '' || $b['note'] !== '')) {
			$rows .= '<tr><td style="padding:14px 32px 4px;"><div style="background:#f8f2e4;border:1px solid #e6d9b8;border-radius:10px;padding:14px 18px;"><div style="'.$font.'font-size:13px;color:#7a6a45;">'.$h($b['label']).'</div>'
				.'<div style="'.$serif.'font-size:36px;line-height:1.1;color:#6b5330;">'.$h($b['value']).'</div><div style="'.$font.'font-size:13px;color:#7a6a45;padding-top:2px;">'.$h($b['note']).'</div></div></td></tr>';
			$plain[] = trim(trim($b['label'].($b['value'] !== '' ? ': '.$b['value'] : '')).($b['note'] !== '' ? ' ('.$b['note'].')' : ''));
		}
		elseif ($b['t'] === 'hr') { $rows .= '<tr><td style="padding:14px 32px 4px;"><div style="border-top:1px solid #e6e0d2;font-size:1px;line-height:1px;">&nbsp;</div></td></tr>'; $plain[] = '----------'; }
	}
	$legal = function_exists('bm_legal_lines') ? bm_legal_lines(array()) : array();
	$links = array();
	if (!empty($settings['imprintUrl'])) { $links[] = '<a href="'.$h($settings['imprintUrl']).'" style="color:#8a6d3b;">Impressum</a>'; }
	if (!empty($settings['privacyUrl'])) { $links[] = '<a href="'.$h($settings['privacyUrl']).'" style="color:#8a6d3b;">Datenschutz</a>'; }
	$footer = '<tr><td style="'.$font.'padding:16px 32px 24px;border-top:1px solid #e6e0d2;font-size:12px;line-height:1.6;color:#6e685c;">'
		.'Du bekommst diese Mail, weil du dich für Neuigkeiten von '.$h($brand).' angemeldet hast. <a href="{abmelden}" style="color:#8a6d3b;">Abmelden oder Einstellungen ändern</a>'
		.($legal ? '<br><br><strong>Angaben zum Anbieter</strong><br>'.implode('<br>', array_map($h, $legal)) : '').($links ? '<br>'.implode(' &middot; ', $links) : '').'</td></tr>';
	$pre = trim((string)(isset($c['preheader']) ? $c['preheader'] : ''));
	$html = '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$h(isset($c['subject']) ? $c['subject'] : '').'</title></head>'
		.'<body style="margin:0;padding:0;background-color:#f4f1ea;">'.($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;">'.$h($pre).'</div>' : '')
		.'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f1ea;"><tr><td align="center" style="padding:24px 12px;">'
		.'<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background-color:#ffffff;border:1px solid #e6e0d2;">'
		.$rows.'<tr><td style="padding:14px 32px 22px;"><div style="'.$font.'font-size:14px;color:#555;line-height:1.6;">Dein Team von '.$h($brand).'</div></td></tr>'.$footer.'</table></td></tr></table></body></html>';
	$text = implode("\r\n\r\n", $plain)."\r\n\r\nDein Team von ".$brand."\r\n\r\n-- \r\nAbmelden oder Einstellungen ändern: {abmelden}\r\n".($legal ? implode("\r\n", $legal)."\r\n" : '');
	$real = !empty($sub['token']);
	$headers = array();
	if ($real) { $headers[] = 'List-Unsubscribe: <'.shop_nl_base().'newsletter.php?m='.$sub['token'].'&oneclick=1>'; $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'; $headers[] = 'Precedence: bulk'; }
	return array('subject' => (!empty($o['test']) ? '[Test] ' : '').shop_nlm_merge(isset($c['subject']) ? $c['subject'] : '', $sub, false), 'html' => shop_nlm_merge($html, $sub, true), 'plain' => shop_nlm_merge($text, $sub, false), 'headers' => $headers);
}
function shop_nlm_plain($html) {
	$s = preg_replace('#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
	$s = preg_replace('#<br\s*/?>#i', "\r\n", $s); $s = preg_replace('#</li>#i', "\r\n", $s); $s = preg_replace('#<li[^>]*>#i', '- ', $s);
	return trim(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'));
}

// ---- mailings -------------------------------------------------------------------------------------------------------------------------------------------

function shop_nlm_get($id) { shop_ensure_schema(); return fb_row("SELECT * FROM ".fb_t('tp_shop_nl_campaigns')." WHERE id = ?", 'i', array((int)$id)); }
function shop_nlm_stats($id) {
	$r = fb_rows("SELECT status, COUNT(*) AS n FROM ".fb_t('tp_shop_nl_queue')." WHERE campaign_id = ? GROUP BY status", 'i', array((int)$id));
	$o = array('pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0);
	foreach ($r as $x) { $o[$x['status']] = (int)$x['n']; $o['total'] += (int)$x['n']; }
	return $o;
}
function shop_nlm_list() {
	shop_ensure_schema();
	$out = array();
	foreach (fb_rows("SELECT id, name, subject, status, created_at, updated_at, started_at, finished_at FROM ".fb_t('tp_shop_nl_campaigns')." ORDER BY id DESC LIMIT 100") as $c) { $c['stats'] = shop_nlm_stats($c['id']); $out[] = $c; }
	return $out;
}
function shop_nlm_recipients_count() { shop_ensure_schema(); $r = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_subscribers')." WHERE news = 1 AND blocked = 0"); return $r ? (int)$r['n'] : 0; }

function shop_nlm_default_blocks() {
	return array(
		array('t' => 'h', 'text' => 'Hallo {vorname|Gast}!'),
		array('t' => 'p', 'html' => 'Das gibt es Neues bei uns.'),
		array('t' => 'btn', 'text' => 'Jetzt bestellen', 'url' => shop_stamp_shop_url()));
}
function shop_nlm_create($name, $copyFrom = 0) {
	shop_ensure_schema(); $now = date('Y-m-d H:i:s');
	$src = $copyFrom ? shop_nlm_get($copyFrom) : null;
	$blocks = $src ? shop_nlm_clean_blocks($src['blocks']) : shop_nlm_default_blocks();
	$name = shop_nlm_str($name, 120); if ($name === '') { $name = 'Neues Mailing'; }
	fb_exec("INSERT INTO ".fb_t('tp_shop_nl_campaigns')." (name, subject, preheader, blocks, status, created_at, updated_at) VALUES (?, ?, ?, ?, 'draft', ?, ?)", 'ssssss',
		array($src ? $name.' (Kopie)' : $name, $src ? $src['subject'] : '', $src ? $src['preheader'] : '', json_encode($blocks, JSON_UNESCAPED_UNICODE), $now, $now));
	return (int)mysqli_insert_id(fb_db());
}
// only a draft can be changed
function shop_nlm_save($id, $d) {
	$c = shop_nlm_get($id);
	if (!$c) { return array('ok' => false, 'error' => 'Das Mailing gibt es nicht mehr.'); }
	if ($c['status'] !== 'draft') { return array('ok' => false, 'error' => 'Dieses Mailing ist schon verschickt oder läuft. Dupliziere es für eine neue Fassung.'); }
	$blocks = shop_nlm_clean_blocks(isset($d['blocks']) ? $d['blocks'] : array());
	fb_exec("UPDATE ".fb_t('tp_shop_nl_campaigns')." SET name = ?, subject = ?, preheader = ?, blocks = ?, updated_at = ? WHERE id = ? AND status = 'draft'", 'sssssi',
		array(shop_nlm_str(isset($d['name']) ? $d['name'] : '', 120), shop_nlm_str(isset($d['subject']) ? $d['subject'] : '', 200), shop_nlm_str(isset($d['preheader']) ? $d['preheader'] : '', 200), json_encode($blocks, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), (int)$id));
	return array('ok' => true);
}
function shop_nlm_delete($id) {
	$c = shop_nlm_get($id); if (!$c || $c['status'] === 'sending') { return false; }
	fb_exec("DELETE FROM ".fb_t('tp_shop_nl_queue')." WHERE campaign_id = ?", 'i', array((int)$id));
	fb_exec("DELETE FROM ".fb_t('tp_shop_nl_campaigns')." WHERE id = ?", 'i', array((int)$id));
	return true;
}

// what is missing before it can be sent (empty list = fine)
function shop_nlm_problems($c) {
	$p = array(); $blocks = shop_nlm_clean_blocks($c['blocks']);
	if (trim($c['subject']) === '') { $p[] = 'Der Betreff fehlt.'; }
	$has = false; foreach ($blocks as $b) { if (($b['t'] === 'h' && $b['text'] !== '') || ($b['t'] === 'p' && $b['html'] !== '') || ($b['t'] === 'img' && $b['src'] !== '')) { $has = true; } }
	if (!$has) { $p[] = 'Das Mailing hat noch keinen Inhalt.'; }
	foreach ($blocks as $b) { if ($b['t'] === 'btn' && ($b['text'] === '' || $b['url'] === '')) { $p[] = 'Ein Button hat keinen Text oder keinen gültigen Link.'; break; } }
	foreach ($blocks as $b) { if ($b['t'] === 'img' && $b['src'] === '') { $p[] = 'Ein Bild hat keine Adresse.'; break; } }
	if (shop_mail_from() === '') { $p[] = 'Der Mailversand ist noch nicht eingerichtet.'; }
	return $p;
}

function shop_nlm_send_test($id, $to) {
	$c = shop_nlm_get($id); if (!$c) { return array('ok' => false, 'error' => 'Das Mailing gibt es nicht mehr.'); }
	$to = trim((string)$to); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { return array('ok' => false, 'error' => 'Bitte gib eine gültige Adresse für die Testmail an.'); }
	if (shop_mail_from() === '') { return array('ok' => false, 'error' => 'Der Mailversand ist noch nicht eingerichtet.'); }
	$m = shop_nlm_render($c, array('email' => $to, 'name' => 'Max Mustermann', 'token' => ''), array('test' => true));
	global $settings; $brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus';
	$ok = bm_send_guest_mail($to, array('subject' => $m['subject'], 'plain' => $m['plain'], 'html' => $m['html'], 'ics' => '', 'ics_filename' => '', 'headers' => array()), $brand, shop_mail_from());
	return $ok === false ? array('ok' => false, 'error' => 'Die Testmail konnte nicht verschickt werden.') : array('ok' => true);
}

// put the guests into the queue; from now on the runner sends
function shop_nlm_start($id) {
	$c = shop_nlm_get($id);
	if (!$c) { return array('ok' => false, 'error' => 'Das Mailing gibt es nicht mehr.'); }
	if ($c['status'] !== 'draft') { return array('ok' => false, 'error' => 'Dieses Mailing wurde schon gestartet.'); }
	$p = shop_nlm_problems($c); if ($p) { return array('ok' => false, 'error' => implode(' ', $p)); }
	$now = date('Y-m-d H:i:s');
	// claim the draft first: two clicks must not queue twice
	$st = fb_exec("UPDATE ".fb_t('tp_shop_nl_campaigns')." SET status = 'sending', started_at = ?, updated_at = ? WHERE id = ? AND status = 'draft'", 'ssi', array($now, $now, (int)$id));
	if (!$st || mysqli_stmt_affected_rows($st) !== 1) { return array('ok' => false, 'error' => 'Dieses Mailing wurde schon gestartet.'); }
	fb_exec("INSERT IGNORE INTO ".fb_t('tp_shop_nl_queue')." (campaign_id, sub_id, email, status) SELECT ?, id, email, 'pending' FROM ".fb_t('tp_shop_subscribers')." WHERE news = 1 AND blocked = 0", 'i', array((int)$id));
	$n = shop_nlm_stats($id);
	if ($n['total'] === 0) { fb_exec("UPDATE ".fb_t('tp_shop_nl_campaigns')." SET status = 'draft', started_at = NULL WHERE id = ?", 'i', array((int)$id)); return array('ok' => false, 'error' => 'Es gibt keine Empfänger.'); }
	return array('ok' => true, 'total' => $n['total']);
}
function shop_nlm_cancel($id) {
	$c = shop_nlm_get($id); if (!$c || $c['status'] !== 'sending') { return false; }
	fb_exec("UPDATE ".fb_t('tp_shop_nl_queue')." SET status = 'skipped', error = 'abgebrochen' WHERE campaign_id = ? AND status = 'pending'", 'i', array((int)$id));
	fb_exec("UPDATE ".fb_t('tp_shop_nl_campaigns')." SET status = 'cancelled', finished_at = ?, updated_at = ? WHERE id = ?", 'ssi', array(date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$id));
	return true;
}

/*
 * Send the next mails of the running mailings: at most $limit, and not longer than $seconds. One runner at a time (a lock), each queue row is claimed
 * before its mail goes out, so nobody gets a mailing twice. Returns the number of mails sent.
 */
function shop_nlm_run($limit = SHOP_NLM_PER_RUN, $seconds = 25) {
	shop_ensure_schema();
	$db = fb_db(); $lock = fb_t('tp_shop_nl_run');
	$r = mysqli_query($db, "SELECT GET_LOCK('".mysqli_real_escape_string($db, $lock)."', 0) AS l"); $row = $r ? mysqli_fetch_assoc($r) : null;
	if (!$row || (int)$row['l'] !== 1) { return 0; }
	$sent = 0; $t0 = time();
	try {
		global $settings; $brand = !empty($settings['brandName']) ? $settings['brandName'] : 'Amadeus'; $from = shop_mail_from();
		// a claim older than 15 minutes means a runner died during the mail: not sent again (it may have gone out), marked as failed
		fb_exec("UPDATE ".fb_t('tp_shop_nl_queue')." SET status = 'failed', error = 'unterbrochen' WHERE status = 'sending' AND sent_at < ?", 's', array(date('Y-m-d H:i:s', time() - 900)));
		$rows = fb_rows("SELECT q.id, q.campaign_id, q.sub_id FROM ".fb_t('tp_shop_nl_queue')." q JOIN ".fb_t('tp_shop_nl_campaigns')." c ON c.id = q.campaign_id WHERE q.status = 'pending' AND c.status = 'sending' ORDER BY q.id LIMIT ".(int)$limit);
		$camps = array();
		foreach ($rows as $q) {
			if (time() - $t0 > $seconds) { break; }
			$st = fb_exec("UPDATE ".fb_t('tp_shop_nl_queue')." SET status = 'sending', sent_at = ? WHERE id = ? AND status = 'pending'", 'si', array(date('Y-m-d H:i:s'), (int)$q['id']));
			if (!$st || mysqli_stmt_affected_rows($st) !== 1) { continue; }
			$sub = fb_row("SELECT id, email, name, token FROM ".fb_t('tp_shop_subscribers')." WHERE id = ?", 'i', array((int)$q['sub_id']));
			if (!$sub || !shop_nl_allows($sub['email'], 'news')) { fb_exec("UPDATE ".fb_t('tp_shop_nl_queue')." SET status = 'skipped', error = 'nicht mehr angemeldet' WHERE id = ?", 'i', array((int)$q['id'])); continue; }
			if (!isset($camps[$q['campaign_id']])) { $camps[$q['campaign_id']] = shop_nlm_get($q['campaign_id']); }
			$m = shop_nlm_render($camps[$q['campaign_id']], $sub);
			$ok = false;
			try { $ok = bm_send_guest_mail($sub['email'], array('subject' => $m['subject'], 'plain' => $m['plain'], 'html' => $m['html'], 'ics' => '', 'ics_filename' => '', 'headers' => $m['headers']), $brand, $from) !== false; }
			catch (Throwable $e) { error_log('mySeat newsletter send: '.$e->getMessage()); }
			fb_exec("UPDATE ".fb_t('tp_shop_nl_queue')." SET status = ?, error = ?, sent_at = ? WHERE id = ?", 'sssi', array($ok ? 'sent' : 'failed', $ok ? '' : 'Versand fehlgeschlagen', date('Y-m-d H:i:s'), (int)$q['id']));
			if ($ok) { $sent++; }
		}
		// a mailing without anything left to send is done
		foreach (fb_rows("SELECT id FROM ".fb_t('tp_shop_nl_campaigns')." WHERE status = 'sending'") as $c) {
			$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_nl_queue')." WHERE campaign_id = ? AND status IN ('pending', 'sending')", 'i', array((int)$c['id']));
			if ($n && (int)$n['n'] === 0) { fb_exec("UPDATE ".fb_t('tp_shop_nl_campaigns')." SET status = 'done', finished_at = ?, updated_at = ? WHERE id = ?", 'ssi', array(date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$c['id'])); }
		}
	} finally { mysqli_query($db, "SELECT RELEASE_LOCK('".mysqli_real_escape_string($db, $lock)."')"); }
	return $sent;
}
// the backend page asks for the list while it is open: that moves a running mailing on, once a minute at most
function shop_nlm_tick() {
	try {
		if (!fb_row("SELECT id FROM ".fb_t('tp_shop_nl_campaigns')." WHERE status = 'sending' LIMIT 1")) { return; }
		if (time() - (int)shop_setting('nl_last_run') < 55) { return; }
		shop_setting_set('nl_last_run', (string)time());
		shop_nlm_run(10, 15);
	} catch (Throwable $e) { error_log('mySeat newsletter tick: '.$e->getMessage()); }
}
