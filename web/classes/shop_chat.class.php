<?php
/*
 * Order by chat ("Bestell-Chat"), stage 1: a guided conversation on the web (order/chat.php). The server holds the whole conversation: what was asked, what the guest chose,
 * the cart. The browser only shows messages and sends what the guest pressed or typed. Behind the setting "chat_on" (off).
 *
 * Every price, zone, time slot and the order itself come from the shop functions (shop_price_line, shop_find_zone, shop_slots, shop_offers_eval, shop_create_order): the chat never
 * works out a price. A button is accepted only when the server offered it in its last message. The guest's phone number or e-mail is proved with the sign-in code of the
 * guest account (shop_acc_request / shop_acc_verify_code), so a prank order is tied to a number.
 *
 * Tables: tp_shop_chats (one row per conversation: state and context as JSON), tp_shop_chat_msgs (the transcript, for the staff and for the later language model step).
 * A message of the bot: array(text, buttons => array(array(v, l)), input => '' | 'text' | 'code' | 'number', links => array(array(url, l))).
 */

require_once __DIR__.'/shop_chat_ai.class.php';

const SHOP_CHAT_MAX_EVENTS = 400;       // per conversation: anything beyond is a robot or a prank
const SHOP_CHAT_MAX_NEW_PER_HOUR = 20;  // new conversations per visitor and hour
const SHOP_CHAT_HANDOVER = '/allerg|unvertr|intoleran|gluten|laktose|nuss|nüss|zöliak|zoeliak|beschwer|reklam|erstattung|geld zurück|falsch geliefert/iu';

function shop_chat_on() { return shop_flag('chat_on'); }
// the kind of the input bar where the guest may write what he wants: free sentences with the AI, else a search of the menu
function shop_chat_free_kind() { return shop_chat_ai_ready() ? 'ask' : 'search'; }

function shop_chat_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	$done = true;
	$db = fb_db(); $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_chats')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `token` CHAR(32) NOT NULL, `channel` VARCHAR(10) NOT NULL DEFAULT 'web', `state` VARCHAR(16) NOT NULL DEFAULT 'type',
		`ctx` MEDIUMTEXT NULL, `ip_hash` CHAR(16) NOT NULL DEFAULT '', `events` INT NOT NULL DEFAULT 0, `handover` TINYINT NOT NULL DEFAULT 0, `order_id` INT UNSIGNED NULL,
		`created_at` DATETIME NOT NULL, `updated_at` DATETIME NOT NULL, UNIQUE KEY `tok` (`token`), KEY `upd` (`updated_at`)) $opts");
	if (!fb_rows("SHOW COLUMNS FROM ".fb_t('tp_shop_chats')." LIKE 'review'")) { mysqli_query($db, "ALTER TABLE ".fb_t('tp_shop_chats')." ADD `review` TINYINT NOT NULL DEFAULT 0"); }   // 1: worth a look (the AI may have misunderstood)
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".fb_t('tp_shop_chat_msgs')." (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `chat_id` INT UNSIGNED NOT NULL, `role` VARCHAR(6) NOT NULL, `body` MEDIUMTEXT NOT NULL, `created_at` DATETIME NOT NULL, KEY `chat` (`chat_id`, `id`)) $opts");
}

// ---- conversations
function shop_chat_new($ip) {
	shop_chat_ensure_schema();
	$h = substr(hash('sha256', $ip.'|myseat-chat'), 0, 16);
	$n = fb_row("SELECT COUNT(*) AS n FROM ".fb_t('tp_shop_chats')." WHERE ip_hash = ? AND created_at > ?", 'ss', array($h, date('Y-m-d H:i:s', time() - 3600)));
	if ($n && (int)$n['n'] >= SHOP_CHAT_MAX_NEW_PER_HOUR) { return null; }
	$token = bin2hex(random_bytes(16)); $now = date('Y-m-d H:i:s');
	$ctx = array('type' => 'delivery', 'cart' => array(), 'offered' => array());
	fb_exec("INSERT INTO ".fb_t('tp_shop_chats')." (token, channel, state, ctx, ip_hash, created_at, updated_at) VALUES (?, 'web', 'type', ?, ?, ?, ?)", 'sssss', array($token, json_encode($ctx), $h, $now, $now));
	$c = shop_chat_load($token);
	if (random_int(1, 40) === 1) { shop_chat_purge(); }
	return $c;
}
function shop_chat_load($token) {
	shop_chat_ensure_schema();
	if (!preg_match('/^[a-f0-9]{32}$/', (string)$token)) { return null; }
	$c = fb_row("SELECT * FROM ".fb_t('tp_shop_chats')." WHERE token = ?", 's', array((string)$token));
	if (!$c) { return null; }
	$c['ctx'] = json_decode((string)$c['ctx'], true); if (!is_array($c['ctx'])) { $c['ctx'] = array('type' => 'delivery', 'cart' => array(), 'offered' => array()); }
	return $c;
}
function shop_chat_save($c) {
	fb_exec("UPDATE ".fb_t('tp_shop_chats')." SET state = ?, ctx = ?, events = ?, handover = ?, review = ?, order_id = ?, updated_at = ? WHERE id = ?", 'ssiiiisi',
		array($c['state'], json_encode($c['ctx'], JSON_UNESCAPED_UNICODE), (int)$c['events'], (int)$c['handover'], !empty($c['review']) ? 1 : 0, empty($c['order_id']) ? null : (int)$c['order_id'], date('Y-m-d H:i:s'), (int)$c['id']));
}
// conversations nobody touched for 14 days are deleted with their transcript (data minimisation)
function shop_chat_purge() {
	$old = date('Y-m-d H:i:s', time() - 14 * 86400);
	fb_exec("DELETE m FROM ".fb_t('tp_shop_chat_msgs')." m JOIN ".fb_t('tp_shop_chats')." c ON c.id = m.chat_id WHERE c.updated_at < ?", 's', array($old));
	fb_exec("DELETE FROM ".fb_t('tp_shop_chats')." WHERE updated_at < ?", 's', array($old));
}
function shop_chat_log($c, $role, $body) {
	$st = fb_exec("INSERT INTO ".fb_t('tp_shop_chat_msgs')." (chat_id, role, body, created_at) VALUES (?, ?, ?, ?)", 'isss', array((int)$c['id'], $role, is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (string)$body, date('Y-m-d H:i:s')));
	return (int)mysqli_insert_id(fb_db());
}
// the transcript for the page: array(role, text, buttons, links) - the buttons only of the last bot message (the others are answered)
function shop_chat_transcript($c) {
	// a finished order or a new start begins a clean view: what came before stays in the database for the staff but is no longer shown
	$rows = fb_rows("SELECT role, body FROM ".fb_t('tp_shop_chat_msgs')." WHERE chat_id = ? AND id >= ? ORDER BY id", 'ii', array((int)$c['id'], (int)(isset($c['ctx']['view_from']) ? $c['ctx']['view_from'] : 0)));
	$out = array(); $last = count($rows) - 1;
	foreach ($rows as $i => $r) {
		if ($r['role'] === 'bot') { $m = json_decode($r['body'], true); if (!is_array($m)) { continue; } if ($i !== $last) { $m['buttons'] = array(); $m['input'] = ''; } $m['role'] = 'bot'; $out[] = $m; }
		else { $out[] = array('role' => 'guest', 'text' => $r['body']); }
	}
	return $out;
}

// ---- small helpers of the dialogue
function shop_chat_msg($text, $buttons = array(), $input = '', $links = array()) {
	$b = array(); foreach ($buttons as $v => $l) { $b[] = array('v' => (string)$v, 'l' => (string)$l); }
	$k = array(); foreach ($links as $url => $l) { $k[] = array('url' => (string)$url, 'l' => (string)$l); }
	return array('text' => $text, 'buttons' => $b, 'input' => $input, 'links' => $k);
}
// the menu does not change within one request: read it once
function shop_chat_menu() { static $m = null; if ($m === null) { $m = shop_menu(); } return $m; }
function shop_chat_priced($ctx) {
	// the same cart is priced several times in one request (bar, summary, totals): once is enough
	static $memo = array();
	$key = md5(json_encode($ctx['cart']));
	if (isset($memo[$key])) { return $memo[$key]; }
	return $memo[$key] = shop_chat_priced_now($ctx);
}
function shop_chat_priced_now($ctx) {
	$items = array(); $sub = 0;
	foreach ($ctx['cart'] as $l) {
		$r = shop_price_line($l);
		if (!$r['ok']) { return array('ok' => false, 'error' => $r['error']); }
		$items[] = $r['line']; $sub += $r['line']['line_cents'];
	}
	return array('ok' => true, 'items' => $items, 'sub' => $sub);
}
function shop_chat_line_text($it) {
	$o = array(); foreach ($it['options'] as $x) { $o[] = ($x['qty'] > 1 ? $x['qty'].'× ' : '').$x['title']; }
	return $it['qty'].'× '.$it['title'].($it['variation'] !== '' ? ' ('.$it['variation'].')' : '').($o ? ', '.implode(', ', $o) : '').' – '.shop_money($it['line_cents']);
}
function shop_chat_cart_text($ctx) {
	$p = shop_chat_priced($ctx);
	if (!$p['ok'] || !$p['items']) { return 'Dein Warenkorb ist noch leer.'; }
	$t = array(); foreach ($p['items'] as $it) { $t[] = shop_chat_line_text($it); }
	return implode("\n", $t)."\nZwischensumme: ".shop_money($p['sub']);
}
function shop_chat_phone_text() { global $settings; $p = !empty($settings['mailPhone']) ? trim($settings['mailPhone']) : ''; return $p !== '' ? ' unter '.$p : ''; }
function shop_chat_city() { $c = trim((string)shop_setting('origin_city')); return $c !== '' ? $c : 'Hildesheim'; }

function shop_chat_menu_buttons() {
	$b = array();
	foreach (shop_chat_menu() as $cat) { $b['cat:'.(int)$cat['id']] = trim($cat['name']); }
	return $b;
}

// what the dialogue says when it has to begin a step again (also after a reload of the page: the last message is shown with its buttons)
function shop_chat_ask_type(&$ctx) {
	$b = array('type:delivery' => 'Lieferung', 'type:pickup' => 'Abholung');
	// a signed-in guest who has ordered before gets the shortest way: the same as last time
	unset($ctx['last_order']);
	$acc = shop_acc_enabled() ? shop_acc_current() : null;
	if ($acc) {
		$o = shop_acc_orders($acc, 1);
		if ($o && $o[0]['items']) { $ctx['last_order'] = (int)$o[0]['id']; $b = array('last' => 'Wie letztes Mal · '.shop_money($o[0]['total'])) + $b; }
	}
	return shop_chat_msg('Hallo! Möchtest du dir etwas liefern lassen oder abholen?', $b);
}

// the phone of the house as a button of a message that needs a human (a tel: link)
function shop_chat_call_links() { global $settings; $p = !empty($settings['mailPhone']) ? trim($settings['mailPhone']) : ''; return $p !== '' ? array('tel:'.preg_replace('/[^0-9+]/', '', $p) => 'Anrufen') : array(); }

function shop_chat_ask_category($ctx, $lead = '') {
	$b = shop_chat_menu_buttons();
	if ($ctx['cart']) { $b['co'] = 'Zur Kasse'; $b['cart'] = 'Warenkorb ansehen'; }
	return shop_chat_msg(($lead !== '' ? $lead."\n" : '').'Was darf es sein?', $b, shop_chat_free_kind());
}

/*
 * One step of the dialogue. $ev: array('btn' => value) or array('text' => string). Returns array of bot messages; the conversation $c (state, ctx) is changed, saving is up to the caller.
 */
function shop_chat_step(&$c, $ev) {
	$ctx = &$c['ctx']; $st = $c['state']; $out = array();
	$btn = isset($ev['btn']) ? (string)$ev['btn'] : null; $text = isset($ev['text']) ? trim((string)$ev['text']) : null;
	$say = function ($m) use (&$out) { $out[] = $m; };
	// a button is only valid when the last message offered it (the browser cannot make up an answer)
	if ($btn !== null && !in_array($btn, $ctx['offered'], true) && !($btn === 'cart' && $ctx['cart'])) { return array(shop_chat_msg('Bitte wähle eine der Antworten unten.', array(), '')); }
	if ($text !== null && mb_strlen($text) > 300) { $text = mb_substr($text, 0, 300); }

	// the guest talks about an allergy or a complaint: the chat does not judge that, the team gets it and the guest is told to call
	if ($text !== null && preg_match(SHOP_CHAT_HANDOVER, $text) && $st !== 'code') {
		$c['handover'] = 1; $ctx['flag'] = isset($ctx['flag']) ? $ctx['flag'] : array(); $ctx['flag'][] = mb_substr($text, 0, 120);
		$say(shop_chat_msg('Dazu kann ich nichts Verbindliches sagen. Unser Team weiß Bescheid. Bei Allergien ruf uns bitte kurz an'.shop_chat_phone_text().', bevor wir kochen.', array(), '', shop_chat_call_links()));
		if ($st === 'note') { $ctx['note'] = trim((isset($ctx['note']) ? $ctx['note'].' ' : '').$text); return shop_chat_checkout($c, $out, '', null, null, true); }
		$last = shop_chat_last_message($c);
		if ($last) { $out[] = $last; }   // the question of the step is asked again, so the guest still has answers
		return shop_chat_finish($c, $out);
	}
	// buttons that work in every state
	if ($btn === 'cart') { $say(shop_chat_msg(shop_chat_cart_text($ctx), $ctx['cart'] ? array('co' => 'Zur Kasse', 'more' => 'Weiter bestellen', 'edit' => 'Ändern') : array('more' => 'Speisekarte ansehen'))); return shop_chat_finish($c, $out); }
	if ($btn === 'restart') { $ctx = array('type' => 'delivery', 'cart' => array(), 'offered' => array(), 'fresh' => 1); $c['state'] = 'type'; $say(shop_chat_ask_type($ctx)); return shop_chat_finish($c, $out); }
	if ($btn === 'more') { $c['state'] = 'cat'; $say(shop_chat_ask_category($ctx)); return shop_chat_finish($c, $out); }
	if ($btn === 'edit') { return shop_chat_edit($c, $out); }
	if ($btn !== null && strpos($btn, 'del:') === 0) {
		$i = (int)substr($btn, 4); if (isset($ctx['cart'][$i])) { array_splice($ctx['cart'], $i, 1); }
		if (!$ctx['cart']) { $c['state'] = 'cat'; $say(shop_chat_ask_category($ctx, 'Der Warenkorb ist jetzt leer.')); return shop_chat_finish($c, $out); }
		return shop_chat_edit($c, $out);
	}
	if ($btn === 'plus' && isset($ctx['cart'][isset($ctx['last']) ? $ctx['last'] : -1])) { $ctx['cart'][$ctx['last']]['qty'] = min(20, $ctx['cart'][$ctx['last']]['qty'] + 1); return shop_chat_added($c, $out); }
	if ($btn === 'to_pickup2') { $ctx['type'] = 'pickup'; unset($ctx['addr']); return shop_chat_run_checkout($c, $out); }
	if ($btn === 'co') { return shop_chat_run_checkout($c, $out); }
	return shop_chat_run($c, $out, $st, $btn !== null ? 'btn' : 'text', $btn !== null ? $btn : $text);
}

function shop_chat_edit(&$c, $out) {
	$p = shop_chat_priced($c['ctx']); $b = array();
	if ($p['ok']) { foreach ($p['items'] as $i => $it) { $b['del:'.$i] = 'Entfernen: '.shop_chat_line_text($it); } }
	$b['more'] = 'Fertig'; $c['state'] = 'cat';
	$out[] = shop_chat_msg('Was soll aus dem Warenkorb raus?', $b);
	return shop_chat_finish($c, $out);
}

// the offered buttons of the last message are remembered, the messages are written to the transcript by the caller
function shop_chat_finish(&$c, $out) {
	$last = $out ? $out[count($out) - 1] : null;
	$c['ctx']['offered'] = $last ? array_map(function ($b) { return $b['v']; }, $last['buttons']) : array();
	return $out;
}

// state machine: $kind 'btn' | 'text'
function shop_chat_run(&$c, $out, $st, $kind, $val) {
	$ctx = &$c['ctx'];
	$btn = $kind === 'btn' ? $val : null; $text = $kind === 'text' ? $val : null;

	if ($st === 'type') {
		if ($btn === 'last' && !empty($ctx['last_order']) && ($acc = shop_acc_current())) { return shop_chat_reorder($c, $out, $acc, (int)$ctx['last_order']); }
		if ($btn === 'type:delivery' || $btn === 'type:pickup') {
			$ctx['type'] = substr($btn, 5); $c['state'] = 'cat';
			$s = shop_state($ctx['type']);
			$lead = !$s['open'] ? (!empty($s['paused']) ? 'Im Moment ist das pausiert, du kannst aber trotzdem schon auswählen und eine spätere Zeit wählen.' : 'Gerade haben wir geschlossen, du kannst aber schon auswählen und eine spätere Zeit wählen.') : '';
			$out[] = shop_chat_ask_category($ctx, $lead); return shop_chat_finish($c, $out);
		}
		$out[] = shop_chat_ask_type($ctx); return shop_chat_finish($c, $out);
	}

	if (in_array($st, array('cat', 'prod', 'var', 'grp', 'qty'), true)) {
		if ($btn !== null && strpos($btn, 'cat:') === 0) {
			$cid = (int)substr($btn, 4);
			foreach (shop_chat_menu() as $cat) {
				if ((int)$cat['id'] !== $cid) { continue; }
				$b = array(); $gone = array();
				foreach ($cat['products'] as $p) { if (!empty($p['out'])) { $gone[] = $p['title']; } else { $b['prod:'.(int)$p['id']] = shop_chat_product_label($p); } }
				$out[] = shop_chat_msg('Das gibt es bei „'.trim($cat['name']).'“:'.($gone ? "\nHeute leider aus: ".implode(', ', $gone).'.' : ''), $b + array('more' => 'Andere Kategorie'));
			}
			$c['state'] = 'prod'; return shop_chat_finish($c, $out);
		}
		if ($btn !== null && strpos($btn, 'prod:') === 0) { return shop_chat_pick_product($c, $out, (int)substr($btn, 5)); }
		if ($st === 'var' && $btn !== null && strpos($btn, 'var:') === 0) { $ctx['pend']['vid'] = (int)substr($btn, 4); $ctx['pend']['gi'] = 0; return shop_chat_next_group($c, $out); }
		if ($st === 'grp' && $btn !== null) {
			if ($btn === 'grpdone') { $ctx['pend']['gi']++; return shop_chat_next_group($c, $out); }
			if (strpos($btn, 'opt:') === 0) { $id = (int)substr($btn, 4); $ctx['pend']['opts'][$id] = (isset($ctx['pend']['opts'][$id]) ? $ctx['pend']['opts'][$id] : 0) + 1; return shop_chat_next_group($c, $out); }
		}
		if ($st === 'qty' && isset($ctx['pend'])) { return shop_chat_add_line($c, $out, 1); }   // a conversation from before the quantity step was dropped
		if ($text !== null && $text !== '' && in_array($st, array('cat', 'prod'), true)) {
			$ai = shop_chat_ai_turn($c, $out, $text);   // free sentences: null when the AI is off or does not answer, then the plain word search
			return $ai !== null ? $ai : shop_chat_search($c, $out, $text);
		}
		$c['state'] = 'cat'; $out[] = shop_chat_ask_category($ctx); return shop_chat_finish($c, $out);
	}

	if ($st === 'offer') { return shop_chat_offer($c, $out, $btn); }
	if ($st === 'auth' || $st === 'code') { return shop_chat_auth($c, $out, $st, $btn, $text); }
	if (in_array($st, array('addr', 'name', 'phone', 'when', 'pay', 'note', 'coupon', 'confirm'), true)) { return shop_chat_checkout($c, $out, $st, $btn, $text); }
	if ($st === 'done') { $out[] = shop_chat_msg('Deine Bestellung ist aufgegeben. Möchtest du eine neue beginnen?', array('restart' => 'Neue Bestellung')); return shop_chat_finish($c, $out); }
	$c['state'] = 'type'; $out[] = shop_chat_ask_type($ctx); return shop_chat_finish($c, $out);
}

// "Wie letztes Mal": the lines of the last order go into the cart (what is gone from the menu is named), then it is the usual way to the checkout
function shop_chat_reorder(&$c, $out, $acc, $orderId) {
	$ctx = &$c['ctx'];
	$r = shop_acc_reorder($acc, $orderId);
	if (!$r['ok']) { $c['state'] = 'type'; $out[] = shop_chat_msg($r['error']); $out[] = shop_chat_ask_type($ctx); return shop_chat_finish($c, $out); }
	$ctx['type'] = $r['type'] === 'pickup' ? 'pickup' : 'delivery'; $ctx['cart'] = array();
	foreach ($r['lines'] as $l) { $ctx['cart'][] = array('pid' => (int)$l['pid'], 'vid' => (int)$l['vid'], 'opts' => (array)$l['opts'], 'qty' => (int)$l['qty'], 'note' => (string)$l['note']); }
	$ctx['last'] = count($ctx['cart']) - 1; unset($ctx['addr'], $ctx['when']);
	$c['state'] = 'cat';
	$note = ($r['gone'] ? "\nNicht mehr auf der Karte: ".implode(', ', $r['gone']).'.' : '').($r['changed'] ? "\nNeuer Preis: ".implode(', ', $r['changed']).'.' : '');
	$out[] = shop_chat_msg(($ctx['type'] === 'delivery' ? 'Lieferung wie letztes Mal:' : 'Abholung wie letztes Mal:')."\n".shop_chat_cart_text($ctx).$note, array('co' => 'Zur Kasse', 'more' => 'Etwas dazu bestellen', 'edit' => 'Ändern'));
	return shop_chat_finish($c, $out);
}

function shop_chat_product_label($p) {
	$from = ((int)$p['nvar'] > 0) ? min((int)$p['price_cents'], (int)$p['vmin']) : (int)$p['price_cents'];
	$mark = !empty($p['isnew']) ? ' (neu)' : (!empty($p['popular']) ? ' (beliebt)' : '');   // the marks of the order page, as words
	return $p['title'].$mark.' · '.((int)$p['nvar'] > 0 ? 'ab ' : '').shop_money($from);
}

// free text instead of a button: dishes whose name or description contains all the words
function shop_chat_search(&$c, $out, $text) {
	$words = array_filter(preg_split('/\s+/u', mb_strtolower($text, 'UTF-8')), function ($w) { return mb_strlen($w) >= 2; });
	$hits = array();
	if ($words) {
		foreach (shop_chat_menu() as $cat) { foreach ($cat['products'] as $p) {
			$hay = mb_strtolower($p['title'].' '.$p['description'], 'UTF-8'); $ok = true;
			foreach ($words as $w) { if (mb_strpos($hay, $w) === false) { $ok = false; break; } }
			if ($ok) { $hits[] = $p; }
		} }
	}
	$c['state'] = 'prod';
	$gone = array(); $hits = array_values(array_filter($hits, function ($p) use (&$gone) { if (!empty($p['out'])) { $gone[] = $p['title']; return false; } return true; }));
	if (!$hits && $gone) { $out[] = shop_chat_msg('„'.$gone[0].'“ ist heute leider aus. Etwas anderes?', shop_chat_menu_buttons(), shop_chat_free_kind()); return shop_chat_finish($c, $out); }
	if (!$hits) { $out[] = shop_chat_msg('Dazu finde ich nichts. Anderer Begriff oder eine Kategorie?', shop_chat_menu_buttons(), 'text'); return shop_chat_finish($c, $out); }
	$b = array();
	foreach (array_slice($hits, 0, 8) as $p) { $b['prod:'.(int)$p['id']] = shop_chat_product_label($p); }
	$out[] = shop_chat_msg((count($hits) > 8 ? 'Die ersten acht Treffer:' : 'Meinst du eins davon?').($gone ? "\nHeute leider aus: ".implode(', ', $gone).'.' : ''), $b + array('more' => 'Andere Kategorie'), 'text');
	return shop_chat_finish($c, $out);
}

function shop_chat_pick_product(&$c, $out, $pid) {
	$p = shop_catalog_product($pid);
	if (!$p) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($c['ctx'], 'Das Gericht gibt es leider nicht mehr.'); return shop_chat_finish($c, $out); }
	if (!empty($p['out'])) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($c['ctx'], '„'.$p['title'].'“ ist heute leider aus.'); return shop_chat_finish($c, $out); }
	$c['ctx']['pend'] = array('pid' => $pid, 'vid' => 0, 'opts' => array(), 'gi' => 0);
	if ($p['variations']) {
		$b = array(); foreach ($p['variations'] as $v) { $b['var:'.$v['id']] = $v['title'].' · '.shop_money($v['price']); }
		$c['state'] = 'var'; $out[] = shop_chat_msg($p['title'].' – bitte wählen', $b);
		return shop_chat_finish($c, $out);
	}
	return shop_chat_next_group($c, $out);
}

// the choices of a dish: first the required ones (size is asked before, then every group with a minimum), then the optional groups; a dish without choices is added at once
function shop_chat_next_group(&$c, $out) {
	$ctx = &$c['ctx']; $pend = &$ctx['pend'];
	$p = shop_catalog_product((int)$pend['pid']);
	if (!$p) { $c['state'] = 'cat'; unset($ctx['pend']); $out[] = shop_chat_ask_category($ctx, 'Das Gericht gibt es leider nicht mehr.'); return shop_chat_finish($c, $out); }
	$req = array(); $opt = array();
	foreach ($p['groups'] as $g) { if ($g['min'] > 0) { $req[] = $g; } else { $opt[] = $g; } }
	$groups = array_merge($req, $opt);
	while (isset($groups[$pend['gi']])) {
		$g = $groups[$pend['gi']]; $sum = 0; $left = array();
		foreach ($g['items'] as $it) { $q = isset($pend['opts'][$it['id']]) ? (int)$pend['opts'][$it['id']] : 0; $sum += $q; if ($q < $it['max']) { $left[] = $it; } }
		if (($g['max'] > 0 && $sum >= $g['max']) || !$left) { $pend['gi']++; continue; }   // full or nothing left: next group
		$b = array();
		// the way on comes first for an optional group (most guests want nothing extra), a required group has none until the minimum is reached
		if ($sum >= $g['min']) { $b['grpdone'] = $sum > 0 ? 'Weiter' : 'Ohne, weiter'; }
		foreach ($left as $it) { $b['opt:'.$it['id']] = $it['title'].($it['price'] > 0 ? ' (+'.shop_money($it['price']).')' : ''); }
		$have = array(); foreach ($g['items'] as $it) { if (!empty($pend['opts'][$it['id']])) { $have[] = $it['title'].($pend['opts'][$it['id']] > 1 ? ' ×'.$pend['opts'][$it['id']] : ''); } }
		$c['state'] = 'grp';
		$out[] = shop_chat_msg(trim($g['title']).($g['min'] > $sum ? ($g['min'] > 1 ? ' – bitte '.$g['min'].' wählen' : ' – bitte wählen') : ' – optional').($have ? "\nGewählt: ".implode(', ', $have) : ''), $b);
		return shop_chat_finish($c, $out);
	}
	return shop_chat_add_line($c, $out, 1);
}

function shop_chat_add_line(&$c, $out, $qty) {
	$ctx = &$c['ctx']; $pend = $ctx['pend']; unset($ctx['pend']);
	$line = array('pid' => (int)$pend['pid'], 'vid' => (int)$pend['vid'], 'opts' => $pend['opts'], 'qty' => $qty, 'note' => '');
	$r = shop_price_line($line);
	if (!$r['ok']) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($ctx, $r['error']); return shop_chat_finish($c, $out); }
	// the same dish with the same choices is one line
	$at = -1;
	foreach ($ctx['cart'] as $i => $l) {
		if ($l['pid'] === $line['pid'] && $l['vid'] === $line['vid'] && $l['opts'] == $line['opts']) { $ctx['cart'][$i]['qty'] = min(20, $l['qty'] + $qty); $at = $i; break; }
	}
	if ($at < 0) { $ctx['cart'][] = $line; $at = count($ctx['cart']) - 1; }
	$ctx['last'] = $at;
	return shop_chat_added($c, $out);
}

// one short line after a dish went into the cart; the cart itself is always one tap away (the bar above the input)
function shop_chat_added(&$c, $out) {
	$ctx = &$c['ctx'];
	$l = isset($ctx['cart'][$ctx['last']]) ? $ctx['cart'][$ctx['last']] : null;
	$r = $l ? shop_price_line($l) : null;
	$c['state'] = 'cat';
	$out[] = shop_chat_msg($r && $r['ok'] ? $r['line']['qty'].'× '.$r['line']['title'].($r['line']['variation'] !== '' ? ' ('.$r['line']['variation'].')' : '').' im Warenkorb.' : 'Im Warenkorb.',
		array('co' => 'Zur Kasse', 'plus' => 'Noch eine davon', 'more' => 'Weiter bestellen'), shop_chat_free_kind());
	return shop_chat_finish($c, $out);
}

// ---- checkout: offers, proof of the contact, address, name, number, time, payment, note, summary
function shop_chat_run_checkout(&$c, $out) {
	$ctx = &$c['ctx'];
	if (!$ctx['cart']) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($ctx, 'Dein Warenkorb ist noch leer.'); return shop_chat_finish($c, $out); }
	if (!shop_flag('accepting')) { $out[] = shop_chat_msg('Wir nehmen gerade keine Bestellungen an.', array('cart' => 'Warenkorb ansehen')); return shop_chat_finish($c, $out); }
	unset($ctx['offer_done'], $ctx['when']);
	$c['state'] = 'offer';
	return shop_chat_offer($c, $out, null);
}

function shop_chat_offer(&$c, $out, $btn) {
	$ctx = &$c['ctx'];
	if ($btn === 'offer:skip') { $ctx['offer_done'] = 1; }
	elseif ($btn !== null && strpos($btn, 'extra:') === 0) { $v = substr($btn, 6); $ctx['extra'] = ($v === 'voucher') ? 'voucher' : (int)$v; $ctx['offer_done'] = 1; }
	elseif ($btn !== null && strpos($btn, 'fill:') === 0) { return shop_chat_pick_product($c, $out, (int)substr($btn, 5)); }
	$p = shop_chat_priced($ctx);
	if (!$p['ok']) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($ctx, $p['error']); return shop_chat_finish($c, $out); }
	$e = shop_offers_eval($p['items'], isset($ctx['extra']) ? $ctx['extra'] : null);
	if ($e['on'] && empty($ctx['offer_done'])) {
		if ($e['reached'] && $e['extras']) {
			$b = array(); foreach ($e['extras'] as $x) { $b['extra:'.(int)$x['id']] = $x['title'].' gratis'; }
			if ($e['voucher_cents'] > 0) { $b['extra:voucher'] = 'Lieber '.shop_money($e['voucher_cents']).' Gutschein für das nächste Mal'; }
			$out[] = shop_chat_msg('Ein Gratis-Extra gehört dir:', $b); return shop_chat_finish($c, $out);
		}
		if (!$e['reached'] && $e['mains'] > 0 && $e['gap'] > 0) {
			$ids = array(); foreach ($p['items'] as $it) { if (!empty($it['product_id'])) { $ids[] = (int)$it['product_id']; } }
			$b = array();
			foreach (shop_offers_fillers($e['gap'], $ids, 3) as $f) { $b['fill:'.(int)$f['id']] = '+ '.$f['title'].' · '.shop_money($f['price']); }
			if ($b) { $b['offer:skip'] = 'Nein danke, weiter'; $out[] = shop_chat_msg('Noch '.shop_money($e['gap']).' bis zum Gratis-Extra. Passt dazu:', $b); return shop_chat_finish($c, $out); }
		}
	}
	return shop_chat_auth($c, $out, 'auth', null, null, true);
}

function shop_chat_auth(&$c, $out, $st, $btn, $text, $enter = false) {
	$ctx = &$c['ctx'];
	if (!shop_acc_enabled()) {
		$out[] = shop_chat_msg('Im Chat kann ich gerade nicht bestellen. Du kannst über die Bestellseite bestellen.', array(), '', array('./' => 'Zur Bestellseite')); return shop_chat_finish($c, $out);
	}
	if (shop_acc_current()) { return shop_chat_checkout($c, $out, 'addr', null, null, true); }
	$retry = array('again' => 'Neuen Code schicken', 'other' => 'Andere Nummer oder E-Mail');
	if ($st === 'auth') {
		if ($text !== null && !$enter) {
			$r = shop_acc_request($text);
			if (!$r['ok']) { $out[] = shop_chat_msg($r['error'], array(), 'text'); return shop_chat_finish($c, $out); }
			$ctx['target'] = $text; $c['state'] = 'code';
			$out[] = shop_chat_msg('Code gesendet an '.$r['mask'].'. Bitte hier eingeben.', $retry, 'code');
			return shop_chat_finish($c, $out);
		}
		$c['state'] = 'auth';
		$out[] = shop_chat_msg('Deine Handynummer oder E-Mail-Adresse? Du bekommst einen Code zur Bestätigung.', array(), 'text');
		return shop_chat_finish($c, $out);
	}
	// state 'code'
	if ($btn === 'other') { $c['state'] = 'auth'; unset($ctx['target']); $out[] = shop_chat_msg('Handynummer oder E-Mail-Adresse?', array(), 'text'); return shop_chat_finish($c, $out); }
	if ($btn === 'again') {
		$r = shop_acc_request(isset($ctx['target']) ? $ctx['target'] : '');
		$out[] = shop_chat_msg($r['ok'] ? 'Neuer Code gesendet an '.$r['mask'].'.' : $r['error'], $retry, 'code'); return shop_chat_finish($c, $out);
	}
	if ($text !== null) {
		$r = shop_acc_verify_code(isset($ctx['target']) ? $ctx['target'] : '', $text);
		if (!$r['ok']) { $out[] = shop_chat_msg($r['error'], $retry, 'code'); return shop_chat_finish($c, $out); }
		$t = shop_acc_target($ctx['target']);
		if ($t['ok'] && $t['kind'] === 'phone') { $ctx['phone'] = $t['to']; }
		unset($ctx['target']); shop_acc_current(true);
		return shop_chat_checkout($c, $out, 'addr', null, null, true);
	}
	$out[] = shop_chat_msg('Bitte gib den Code ein.', $retry, 'code');
	return shop_chat_finish($c, $out);
}

// the money the order costs now, as far as the chat can tell before the order exists (a voucher of the stamp card is taken off when the order is placed)
function shop_chat_totals($ctx) {
	$p = shop_chat_priced($ctx);
	if (!$p['ok']) { return $p; }
	$e = shop_offers_eval($p['items'], isset($ctx['extra']) ? $ctx['extra'] : null);
	$combo = $e['on'] ? (int)$e['combo_cents'] : 0;
	$fee = ($ctx['type'] === 'delivery' && isset($ctx['addr'])) ? (int)$ctx['addr']['fee'] : 0;
	// a coupon code the guest typed: checked again here, the discount is the one the order will take off (shop_create_order checks it a last time)
	$disc = 0; $code = '';
	if (!empty($ctx['coupon'])) {
		$acc = shop_acc_current(); $r = shop_coupon_check($ctx['coupon'], $ctx['type'], $p['sub'], $acc ? shop_acc_keys($acc) : array('', ''));
		if ($r['ok']) { $disc = (int)$r['discount']; $code = $r['coupon']['code']; }
	}
	return array('ok' => true, 'items' => $p['items'], 'sub' => $p['sub'], 'combo' => $combo, 'combo_text' => $e['combo_text'], 'fee' => $fee, 'discount' => $disc, 'coupon' => $code, 'total' => max(0, $p['sub'] - $disc - $combo + $fee), 'eval' => $e);
}

// the steps after the proof of the contact; each one that is already answered is skipped, the first open one asks
function shop_chat_checkout(&$c, $out, $st, $btn, $text, $enter = false) {
	$ctx = &$c['ctx']; $acc = shop_acc_current();
	if (!$acc) { return shop_chat_auth($c, $out, 'auth', null, null, true); }
	$ask = function ($state, $m) use (&$c, &$out) { $c['state'] = $state; $out[] = $m; return shop_chat_finish($c, $out); };
	// the guest answers the step the dialogue is in (a button or text that belongs to an earlier step is not an answer here)
	if ($enter) { $btn = null; $text = null; $st = ''; }

	// ---- address (delivery only)
	if ($ctx['type'] === 'delivery' && !isset($ctx['addr'])) {
		// the address saved in the account, else the one of the last order
		$saved = shop_acc_address($acc);
		if (!$saved) { $pr = shop_acc_profile($acc); if ($pr && trim((string)$pr['street']) !== '') { $saved = array('street' => $pr['street'], 'zip' => $pr['zip'], 'city' => $pr['city'], 'note' => (string)$pr['address_note']); } }
		if ($btn === 'addr:saved' && $saved) { $text = $saved['street'].' '.$saved['zip']; $btn = null; $ctx['addr_note'] = $saved['note']; }
		elseif ($btn === 'addr:new') { $ctx['addr_new'] = 1; return $ask('addr', shop_chat_msg('Wohin liefern wir? Straße und Hausnummer, bei Bedarf mit PLZ.', array('to_pickup' => 'Doch lieber abholen'), 'text')); }
		elseif ($btn === 'to_pickup') { $ctx['type'] = 'pickup'; return shop_chat_checkout($c, $out, '', null, null, true); }
		elseif ($btn !== null && strpos($btn, 'cand:') === 0 && isset($ctx['cands'][(int)substr($btn, 5)])) {
			$k = $ctx['cands'][(int)substr($btn, 5)]; unset($ctx['cands']);
			$ctx['addr'] = array('street' => $k['street'], 'zip' => $k['postcode'], 'city' => shop_chat_city(), 'fee' => (int)$k['fee'], 'min' => (int)$k['min'], 'zone' => $k['zone']);
			shop_chat_pin_address($ctx['addr'], $k);
			return shop_chat_after_address($c, $out);
		}
		if ($text === null || $text === '') {
			if ($saved && empty($ctx['addr_new'])) { return $ask('addr', shop_chat_msg("Sollen wir an deine gespeicherte Adresse liefern?\n".$saved['street'].', '.$saved['zip'].' '.$saved['city'], array('addr:saved' => 'Ja, dorthin', 'addr:new' => 'Andere Adresse'))); }
			return $ask('addr', shop_chat_msg('Wohin liefern wir? Straße und Hausnummer, bei Bedarf mit PLZ.', array('to_pickup' => 'Doch lieber abholen'), 'text'));
		}
		$zip = ''; if (preg_match('/\b(\d{5})\b/', $text, $m)) { $zip = $m[1]; $text = trim(str_replace($m[1], '', $text), " ,\t"); }
		$city = shop_chat_city();
		// the address lookup asks an outside service: the same limit per visitor as the order page
		$_SESSION['shop_zone_hits'] = isset($_SESSION['shop_zone_hits']) ? array_values(array_filter((array)$_SESSION['shop_zone_hits'], function ($t) { return $t > time() - 3600; })) : array();
		if (count($_SESSION['shop_zone_hits']) >= 30) { return $ask('addr', shop_chat_msg('Zu viele Adressprüfungen. Bitte versuche es später noch einmal oder ruf uns an'.shop_chat_phone_text().'.', array(), '', shop_chat_call_links())); }
		$_SESSION['shop_zone_hits'][] = time();
		$z = shop_find_zone($text, $zip, $city);
		if ($z['ok']) {
			$min = $z['zone']['min_order_cents'] > 0 ? $z['zone']['min_order_cents'] : shop_cents(shop_setting('min_order_delivery'));
			$ctx['addr'] = array('street' => $text, 'zip' => $zip !== '' ? $zip : (string)$z['postcode'], 'city' => $city, 'fee' => (int)$z['zone']['fee_cents'], 'min' => (int)$min, 'zone' => $z['zone']['name']);
			if (empty($ctx['addr_note'])) { $ctx['addr_note'] = ''; }
			return shop_chat_after_address($c, $out);
		}
		if ($z['reason'] === 'ambiguous') {
			$b = array(); $ctx['cands'] = array(); $no = trim(preg_replace('/^\D+/u', '', $text));
			foreach ($z['candidates'] as $i => $k) {
				$ctx['cands'][$i] = array('street' => preg_match('/\d/', (string)$k['road']) ? trim($k['road']) : trim($k['road'].' '.$no), 'postcode' => (string)$k['postcode'], 'fee' => (int)$k['zone']['fee'], 'min' => (int)$k['zone']['min'], 'zone' => $k['zone']['name'], 'lat' => (float)$k['lat'], 'lng' => (float)$k['lng'], 'road' => (string)$k['road']);
				$b['cand:'.$i] = $k['road'].($k['postcode'] ? ', '.$k['postcode'] : '');
			}
			return $ask('addr', shop_chat_msg('Welche Adresse meinst du?', $b));
		}
		if ($z['reason'] === 'outside_zone') { return $ask('addr', shop_chat_msg('Dorthin liefern wir leider nicht. Abholen geht immer.', array('to_pickup' => 'Dann hole ich ab', 'addr:new' => 'Andere Adresse'))); }
		return $ask('addr', shop_chat_msg($z['error'], array('to_pickup' => 'Doch lieber abholen'), 'text'));
	}

	// ---- minimum order value
	$t = shop_chat_totals($ctx);
	if (!$t['ok']) { $c['state'] = 'cat'; $out[] = shop_chat_ask_category($ctx, $t['error']); return shop_chat_finish($c, $out); }
	$min = ($ctx['type'] === 'delivery' && isset($ctx['addr'])) ? (int)$ctx['addr']['min'] : shop_cents(shop_setting('min_order_pickup'));
	if ($t['sub'] < $min) {
		$c['state'] = 'cat';
		$out[] = shop_chat_msg('Der Mindestbestellwert ist '.shop_money($min).'. Dir fehlen noch '.shop_money($min - $t['sub']).'.', array('more' => 'Noch etwas bestellen') + ($ctx['type'] === 'delivery' ? array('to_pickup2' => 'Abholung statt Lieferung') : array()), 'text');
		return shop_chat_finish($c, $out);
	}

	// ---- name and number
	if (!isset($ctx['name'])) {
		$known = trim((string)$acc['name']);
		if ($known === '') { $pr = shop_acc_profile($acc); $known = $pr ? trim((string)$pr['name']) : ''; }
		if ($st === 'name' && $text !== null && mb_strlen($text) >= 2) { $ctx['name'] = mb_substr($text, 0, 80); }
		elseif ($st === 'name' && $btn === 'name:ok' && $known !== '') { $ctx['name'] = $known; }
		elseif ($known !== '') { return $ask('name', shop_chat_msg('Auf welchen Namen?', array('name:ok' => $known), 'text')); }
		else { return $ask('name', shop_chat_msg('Auf welchen Namen?', array(), 'text')); }
	}
	if (empty($ctx['phone'])) {
		$known = trim((string)$acc['contact_phone']);
		if ($known === '') { $pr = shop_acc_profile($acc); $known = $pr ? trim((string)$pr['phone']) : ''; }
		if ($st === 'phone' && $text !== null && shop_phone_ok($text)) { $ctx['phone'] = mb_substr($text, 0, 40); }
		elseif ($st === 'phone' && $btn === 'phone:ok' && $known !== '') { $ctx['phone'] = $known; }
		elseif ($known !== '') { return $ask('phone', shop_chat_msg('Unter welcher Nummer erreichen wir dich?', array('phone:ok' => $known), 'text')); }
		else { return $ask('phone', shop_chat_msg('Unter welcher Nummer erreichen wir dich?', array(), 'text')); }
	}

	// ---- time
	if (!isset($ctx['when'])) {
		$kind = $ctx['type']; $s = shop_state($kind);
		if ($st === 'when' && $btn === 'when:asap' && $s['open']) { $ctx['when'] = 'asap'; }
		elseif ($st === 'when' && $btn !== null && preg_match('/^when:(\d{4}-\d{2}-\d{2} \d{2}:\d{2})$/', $btn, $m)) { $ctx['when'] = $m[1]; }
		else {
			if ($st === 'when' && $btn === 'when:next') { $ctx['day_i'] = (isset($ctx['day_i']) ? (int)$ctx['day_i'] : 0) + 1; }
			$days = array(); $max = max(0, min(14, (int)shop_setting('days_ahead')));
			for ($d = 0; $d <= $max; $d++) { $date = date('Y-m-d', time() + $d * 86400); $sl = shop_slots($kind, $date); if ($sl) { $days[] = array($date, $sl, $d); } }
			if (!$days && !$s['open']) { return $ask('when', shop_chat_msg('Zurzeit kann ich keine Zeit anbieten. Bitte versuche es später noch einmal'.shop_chat_phone_text().'.', array(), '', shop_chat_call_links())); }
			$i = ($days && isset($ctx['day_i'])) ? ((int)$ctx['day_i'] % count($days)) : 0; $ctx['day_i'] = $i;
			$b = array();
			if ($s['open'] && $i === 0) { $b['when:asap'] = 'So schnell wie möglich (ca. '.(int)$s['lead'].' Min.)'; }
			if ($days) { foreach (array_slice($days[$i][1], 0, 4) as $h) { $b['when:'.$days[$i][0].' '.$h] = ($days[$i][2] === 0 ? 'Heute' : ($days[$i][2] === 1 ? 'Morgen' : date('d.m.', strtotime($days[$i][0])))).' '.$h.' Uhr'; } }
			if (count($days) > 1) { $b['when:next'] = 'Später oder anderer Tag'; }
			return $ask('when', shop_chat_msg($kind === 'delivery' ? 'Wann soll es geliefert werden?' : 'Wann möchtest du abholen?', $b));
		}
	}

	// ---- payment
	if (!isset($ctx['pay'])) {
		$allowed = array('mollie' => shop_flag('allow_online') && shop_mollie_key() !== '', 'cash' => shop_flag('allow_cash'), 'card_door' => shop_flag('allow_card_door'));
		$labels = array('mollie' => 'Online bezahlen', 'cash' => $ctx['type'] === 'delivery' ? 'Bar bei Lieferung' : 'Bar bei Abholung', 'card_door' => $ctx['type'] === 'delivery' ? 'Karte an der Tür' : 'Karte bei Abholung');
		if ($st === 'pay' && $btn !== null && strpos($btn, 'pay:') === 0 && !empty($allowed[substr($btn, 4)])) { $ctx['pay'] = substr($btn, 4); }
		else {
			$b = array(); foreach ($allowed as $k => $on) { if ($on) { $b['pay:'.$k] = $labels[$k]; } }
			if (!$b) { return $ask('pay', shop_chat_msg('Zurzeit ist keine Zahlungsart möglich. Bitte ruf uns an'.shop_chat_phone_text().'.', array(), '', shop_chat_call_links())); }
			return $ask('pay', shop_chat_msg('Wie möchtest du bezahlen?', $b));
		}
	}

	// ---- the coupon code is no step of its own either: the summary offers it
	if ($btn === 'coupon') { return $ask('coupon', shop_chat_msg('Dein Gutscheincode?', array('coupon:back' => 'Zurück'), 'text')); }
	if ($btn === 'couponx') { unset($ctx['coupon']); }
	if ($st === 'coupon' && $text !== null && $text !== '') {
		$ctx['coupon_tries'] = (isset($ctx['coupon_tries']) ? (int)$ctx['coupon_tries'] : 0) + 1;
		if ($ctx['coupon_tries'] > 8) { return $ask('confirm', shop_chat_summary($ctx, 'Zu viele Versuche mit Gutscheincodes.')); }
		$code = shop_coupon_normalize($text);
		$r = shop_coupon_check($code, $ctx['type'], $t['sub'], shop_acc_keys($acc));
		if (!$r['ok']) { return $ask('coupon', shop_chat_msg($r['error'], array('coupon:back' => 'Zurück'), 'text')); }
		$ctx['coupon'] = $r['coupon']['code'];
	}
	// a code that does not fit any more (the cart changed, the minimum is not reached): it goes, and the guest is told
	$couponNote = '';
	if (!empty($ctx['coupon'])) { $r = shop_coupon_check($ctx['coupon'], $ctx['type'], $t['sub'], shop_acc_keys($acc)); if (!$r['ok']) { $couponNote = 'Der Gutschein passt nicht mehr: '.$r['error']; unset($ctx['coupon']); } }

	// ---- the note is no step of its own: the summary offers it
	if ($btn === 'addnote') { return $ask('note', shop_chat_msg('Was möchtest du anmerken?', array('note:none' => 'Zurück'), 'text')); }
	if ($st === 'note' && $text !== null && $text !== '') { $ctx['note'] = trim((isset($ctx['note']) ? $ctx['note'].' ' : '').$text); }

	// ---- summary and the last word of the guest
	if ($st === 'confirm' && $btn === 'confirm') { return shop_chat_place($c, $out, $acc); }
	if ($st === 'confirm' && $btn === 'cancel') { $ctx = array('type' => 'delivery', 'cart' => array(), 'offered' => array(), 'fresh' => 1); $c['state'] = 'type'; $out[] = shop_chat_msg('Alles klar, ich habe die Bestellung verworfen.'); $out[] = shop_chat_ask_type($ctx); return shop_chat_finish($c, $out); }
	return $ask('confirm', shop_chat_summary($ctx, $couponNote));
}

// the guest picked one of several matching addresses: that choice is remembered as the answer of the geocoder for exactly the text the order will be checked with (shop_create_order looks
// the address up again), so the order does not fall back into "several addresses found"
function shop_chat_pin_address($addr, $k) {
	shop_ensure_schema();
	$key = sha1(mb_strtolower(trim($addr['street']).'|'.trim($addr['zip']).'|'.trim($addr['city'])));
	fb_exec("REPLACE INTO ".fb_t('tp_shop_geocache')." (h, lat, lng, postcode, road, candidates, created_at) VALUES (?, ?, ?, ?, ?, NULL, NOW())", 'sddss', array($key, $k['lat'], $k['lng'], $k['postcode'], $k['road']));
}

function shop_chat_after_address(&$c, $out) {
	return shop_chat_checkout($c, $out, '', null, null, true);
}

function shop_chat_summary($ctx, $lead = '') {
	$t = shop_chat_totals($ctx);
	$lines = array(($lead !== '' ? $lead."\n\n" : '').'Deine Bestellung');
	foreach ($t['items'] as $it) { $lines[] = shop_chat_line_text($it); }
	if ($t['combo'] > 0) { $lines[] = $t['combo_text'].' – −'.shop_money($t['combo']); }
	if (isset($ctx['extra']) && $ctx['extra'] !== 'voucher' && $t['eval']['on'] && $t['eval']['reached']) { foreach ($t['eval']['extras'] as $x) { if ((int)$x['id'] === (int)$ctx['extra']) { $lines[] = $x['title'].' – gratis'; } } }
	if ($t['discount'] > 0) { $lines[] = 'Gutschein '.$t['coupon'].' – −'.shop_money($t['discount']); }
	if ($ctx['type'] === 'delivery') { $lines[] = 'Lieferung – '.shop_money($t['fee']); }
	$acc = shop_acc_current(); $voucher = ($acc && $t['discount'] <= 0) ? shop_stamp_voucher(shop_acc_keys($acc)) : null;
	$lines[] = 'Gesamt '.shop_money($t['total']).($voucher ? ', dein Gutschein wird noch abgezogen' : '');
	$lines[] = '';
	$pay = array('mollie' => 'online bezahlt', 'cash' => 'bar', 'card_door' => 'mit Karte');
	$lines[] = ($ctx['type'] === 'delivery' ? $ctx['addr']['street'].', '.$ctx['addr']['zip'].' '.$ctx['addr']['city'] : 'Abholung').' · '.($ctx['when'] === 'asap' ? 'so schnell wie möglich' : date('d.m. H:i', strtotime($ctx['when'])).' Uhr');
	$lines[] = $ctx['name'].', '.$ctx['phone'].' · '.$pay[$ctx['pay']];
	if (!empty($ctx['note'])) { $lines[] = 'Anmerkung: '.$ctx['note']; }
	return shop_chat_msg(implode("\n", $lines), array('confirm' => 'Verbindlich bestellen', 'addnote' => empty($ctx['note']) ? 'Anmerkung hinzufügen' : 'Anmerkung ändern', 'coupon' => empty($ctx['coupon']) ? 'Gutscheincode eingeben' : 'Gutschein ändern') + (empty($ctx['coupon']) ? array() : array('couponx' => 'Gutschein entfernen')) + array('edit' => 'Warenkorb ändern', 'cancel' => 'Abbrechen'));
}

// the little summary the page shows in the cart bar: array(count, total text); empty when nothing is in the cart
function shop_chat_cart_info($c) {
	if (empty($c['ctx']['cart']) || $c['state'] === 'done') { return array('count' => 0, 'total' => ''); }
	$p = shop_chat_priced($c['ctx']);
	if (!$p['ok']) { return array('count' => 0, 'total' => ''); }
	$n = 0; foreach ($p['items'] as $it) { $n += (int)$it['qty']; }
	return array('count' => $n, 'total' => shop_money($p['sub']));
}

// the order itself: shop_create_order checks everything again (prices, zone, time, minimum, payment), the chat only hands over what was agreed
function shop_chat_place(&$c, $out, $acc) {
	$ctx = &$c['ctx'];
	$in = array('type' => $ctx['type'], 'lines' => $ctx['cart'], 'name' => $ctx['name'], 'phone' => $ctx['phone'], 'email' => trim((string)$acc['contact_mail']), 'when' => $ctx['when'], 'payment' => $ctx['pay'],
		'note' => 'Bestellt über den Chat'.(!empty($ctx['note']) ? ': '.$ctx['note'] : '').(!empty($ctx['flag']) ? ' | ACHTUNG Hinweis des Gastes (Allergie/Beschwerde), bitte anrufen: '.implode(' / ', $ctx['flag']) : ''),
		'extra' => isset($ctx['extra']) ? $ctx['extra'] : null, 'coupon' => isset($ctx['coupon']) ? $ctx['coupon'] : '', 'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', 'acc_id' => (int)$acc['id'], 'acc_keys' => shop_acc_keys($acc));
	if ($ctx['type'] === 'delivery') { $in['street'] = $ctx['addr']['street']; $in['zip'] = $ctx['addr']['zip']; $in['city'] = $ctx['addr']['city']; $in['address_note'] = isset($ctx['addr_note']) ? $ctx['addr_note'] : ''; }
	$r = shop_create_order($in);
	if (!$r['ok']) { $c['state'] = 'confirm'; $out[] = shop_chat_msg($r['error'], array('edit' => 'Warenkorb ändern', 'cancel' => 'Abbrechen'), '', shop_chat_call_links()); return shop_chat_finish($c, $out); }
	$o = $r['order'];
	fb_exec("UPDATE ".fb_t('tp_shop_orders')." SET source = 'chat' WHERE id = ?", 'i', array((int)$o['id']));
	shop_log((int)$o['id'], 'chat', 'bestellt im Chat '.(int)$c['id']);
	$c['order_id'] = (int)$o['id']; $c['state'] = 'done'; $ctx['cart'] = array();
	if ($o['payment_method'] === 'mollie') {
		$p = shop_mollie_create($o, shop_base_url());
		if (!$p['ok']) { shop_set_status((int)$o['id'], 'cancelled', 'Zahlung nicht möglich'); $c['state'] = 'confirm'; $c['order_id'] = null; $out[] = shop_chat_msg($p['error'], array('cancel' => 'Abbrechen')); return shop_chat_finish($c, $out); }
		$out[] = shop_chat_msg('Fast geschafft: Bestellung '.$o['number'].' über '.shop_money($o['total_cents']).' ist erst fest, wenn du bezahlt hast.', array('restart' => 'Neue Bestellung'), '', array($p['url'] => 'Jetzt bezahlen'));
		return shop_chat_finish($c, $out);
	}
	shop_after_order_placed($o['id']);
	$out[] = shop_chat_msg('Danke, deine Bestellung ist da! Nummer '.$o['number'].', '.shop_money($o['total_cents']).'.', array('restart' => 'Neue Bestellung'), '', array('status.php?t='.$o['token'] => 'Status ansehen'));
	return shop_chat_finish($c, $out);
}

// ---- cart exchange with the order page (the cart of the order page lives in the browser, the one of the chat on the server; the page one leaves overwrites the other)
// the cart of the order page (lines pid, vid, opts, qty, note) becomes the cart of the chat; returns the number of lines taken over
function shop_chat_import(&$c, $lines, $mode, $clear = false) {
	$ctx = &$c['ctx']; $new = array(); $dropped = 0; $had = !empty($ctx['cart']);
	foreach (array_slice(is_array($lines) ? $lines : array(), 0, 60) as $l) {
		if (!is_array($l)) { continue; }
		$opts = array(); if (isset($l['opts']) && is_array($l['opts'])) { foreach ($l['opts'] as $id => $q) { if ((int)$q > 0) { $opts[(int)$id] = (int)$q; } } }
		$line = array('pid' => (int)(isset($l['pid']) ? $l['pid'] : 0), 'vid' => (int)(isset($l['vid']) ? $l['vid'] : 0), 'opts' => $opts, 'qty' => max(1, min(20, (int)(isset($l['qty']) ? $l['qty'] : 1))), 'note' => mb_substr(trim((string)(isset($l['note']) ? $l['note'] : '')), 0, 200));
		if (shop_price_line($line)['ok']) { $new[] = $line; } else { $dropped++; }
	}
	if (!$new) {
		// the page emptied its cart while the chat is open: the chat's cart is emptied too (never an order from a cart the guest no longer sees)
		if ($clear && $had) {
			$ctx['cart'] = array(); unset($ctx['addr'], $ctx['when'], $ctx['extra'], $ctx['pend'], $ctx['offer_done']); $c['state'] = 'cat';
			$m = shop_chat_msg('Dein Warenkorb ist jetzt leer.', shop_chat_menu_buttons(), shop_chat_free_kind());
			shop_chat_finish($c, array($m)); shop_chat_log($c, 'bot', $m); shop_chat_save($c);
		}
		return 0;
	}
	if ((int)$c['events'] === 0) { fb_exec("DELETE FROM ".fb_t('tp_shop_chat_msgs')." WHERE chat_id = ?", 'i', array((int)$c['id'])); }   // nothing said yet: no greeting needed
	$ctx['cart'] = $new; $ctx['type'] = $mode === 'pickup' ? 'pickup' : 'delivery'; $ctx['last'] = count($new) - 1;
	unset($ctx['addr'], $ctx['when'], $ctx['extra'], $ctx['pend'], $ctx['offer_done']);
	$c['state'] = 'cat';
	$m = shop_chat_msg(($had ? 'Dein Warenkorb wurde aktualisiert (' : 'Dein Warenkorb ist übernommen (').($ctx['type'] === 'pickup' ? 'Abholung' : 'Lieferung')."):\n".shop_chat_cart_text($ctx).($dropped ? "\nEinige Artikel gibt es nicht mehr." : ''), array('co' => 'Zur Kasse', 'more' => 'Etwas dazu bestellen', 'edit' => 'Ändern'));
	shop_chat_finish($c, array($m)); shop_chat_log($c, 'bot', $m); shop_chat_save($c);
	return count($new);
}
// the cart of the chat in the form of the order page (what its cart keeps in the browser)
function shop_chat_export($c) {
	$out = array();
	foreach ($c['ctx']['cart'] as $l) { $r = shop_acc_make_line($l); if ($r['ok']) { $out[] = $r['line']; } }
	return array('mode' => $c['ctx']['type'] === 'pickup' ? 'pickup' : 'delivery', 'lines' => $out);
}

// ---- the entry points of the page (order/chat_api.php)
// opens (or resumes) the conversation of this visitor
function shop_chat_open($token, $ip) {
	$c = $token !== '' ? shop_chat_load($token) : null;
	// an order that was placed a while ago: whoever comes back starts with a clean window
	if ($c && $c['state'] === 'done' && strtotime($c['updated_at']) < time() - 600) {
		$c['ctx'] = array('type' => 'delivery', 'cart' => array(), 'offered' => array()); $c['state'] = 'type';
		$m = shop_chat_ask_type($c['ctx']); shop_chat_finish($c, array($m)); $c['ctx']['view_from'] = shop_chat_log($c, 'bot', $m); shop_chat_save($c);
	}
	if (!$c) {
		$c = shop_chat_new($ip);
		if (!$c) { return null; }
		$m = shop_chat_ask_type($c['ctx']); shop_chat_finish($c, array($m)); shop_chat_log($c, 'bot', $m); shop_chat_save($c);
	}
	return $c;
}
// the guest pressed a button or typed text: returns array(conversation, new bot messages)
function shop_chat_handle($c, $ev) {
	if ((int)$c['events'] >= SHOP_CHAT_MAX_EVENTS) { return array($c, array(shop_chat_msg('Das Gespräch ist zu lang geworden. Bitte ruf uns an'.shop_chat_phone_text().' oder bestelle über die Bestellseite.', array(), '', array('./' => 'Zur Bestellseite')))); }
	$c['events'] = (int)$c['events'] + 1;
	// a button the server did not offer (an old page, a made-up request): nothing happens, the last question is asked again so the guest is never left without answers
	if (isset($ev['btn']) && !in_array((string)$ev['btn'], $c['ctx']['offered'], true) && !($ev['btn'] === 'cart' && $c['ctx']['cart'])) {
		shop_chat_save($c);
		$m = shop_chat_last_message($c);
		return array($c, $m ? array($m) : array(shop_chat_ask_type($c['ctx'])));
	}
	shop_chat_note_correction($c, $ev);
	// the guest's line in the transcript: the label of the button, or the typed text (a code is not kept)
	$label = '';
	if (isset($ev['btn'])) { foreach (shop_chat_last_buttons($c) as $b) { if ($b['v'] === (string)$ev['btn']) { $label = $b['l']; } } }
	elseif (isset($ev['text'])) { $label = $c['state'] === 'code' ? '••••••' : mb_substr((string)$ev['text'], 0, 300); }
	shop_chat_log($c, 'guest', $label);
	$msgs = shop_chat_step($c, $ev);
	$first = 0;
	foreach ($msgs as $m) { $id = shop_chat_log($c, 'bot', $m); $first = $first ?: $id; }
	// a new start: the view begins with the first new message (the page empties its window, a reload shows only this)
	$c['reset_view'] = !empty($c['ctx']['fresh']) && $first > 0;
	if ($c['reset_view']) { $c['ctx']['view_from'] = $first; }
	unset($c['ctx']['fresh']);
	shop_chat_save($c);
	return array($c, $msgs);
}
// a reason to look at this conversation later (backend list): at most five short notes
function shop_chat_review(&$c, $why) {
	$c['review'] = 1; $c['ctx']['review_why'] = isset($c['ctx']['review_why']) ? $c['ctx']['review_why'] : array();
	if (count($c['ctx']['review_why']) < 5) { $c['ctx']['review_why'][] = mb_substr($why, 0, 140); }
}
// the guest corrects what the AI has just put into the cart (within two moves): the AI probably understood something wrong
function shop_chat_note_correction(&$c, $ev) {
	if (empty($c['ctx']['ai_last'])) { return; }
	if ((int)$c['events'] - (int)$c['ctx']['ai_last'] > 2) { unset($c['ctx']['ai_last']); return; }
	$btn = isset($ev['btn']) ? (string)$ev['btn'] : ''; $text = isset($ev['text']) ? (string)$ev['text'] : '';
	if ($btn === 'edit' || strpos($btn, 'del:') === 0) { shop_chat_review($c, 'Gast ändert den Warenkorb direkt nach der KI'); unset($c['ctx']['ai_last']); }
	elseif ($text !== '' && preg_match('/\b(nein|nicht|falsch|doch|stattdessen|statt|anders|lieber|vergiss|rückgängig|zurück)\b/iu', $text)) { shop_chat_review($c, 'Gast korrigiert: '.$text); unset($c['ctx']['ai_last']); }
}
// the conversations the backend should show: the guest was sent to the phone (allergy, complaint) or the AI may have misunderstood
function shop_chat_review_list($limit = 12) {
	shop_chat_ensure_schema();
	$out = array();
	foreach (fb_rows("SELECT id, ctx, handover, review, order_id, updated_at FROM ".fb_t('tp_shop_chats')." WHERE handover = 1 OR review = 1 ORDER BY updated_at DESC LIMIT ".(int)$limit) as $r) {
		$ctx = json_decode((string)$r['ctx'], true); $ctx = is_array($ctx) ? $ctx : array();
		$msgs = array();
		foreach (fb_rows("SELECT role, body FROM ".fb_t('tp_shop_chat_msgs')." WHERE chat_id = ? ORDER BY id", 'i', array((int)$r['id'])) as $m) {
			if ($m['role'] === 'bot') { $b = json_decode($m['body'], true); $msgs[] = 'Assistent: '.(is_array($b) && isset($b['text']) ? $b['text'] : ''); }
			else { $msgs[] = 'Gast: '.$m['body']; }
		}
		$why = array_merge(!empty($r['handover']) ? array('Gast zum Anruf gebeten (Allergie oder Beschwerde)') : array(), isset($ctx['review_why']) ? $ctx['review_why'] : array(), isset($ctx['flag']) ? array_map(function ($f) { return 'Hinweis: '.$f; }, $ctx['flag']) : array());
		$out[] = array('id' => (int)$r['id'], 'at' => $r['updated_at'], 'ordered' => !empty($r['order_id']), 'why' => $why, 'transcript' => implode("\n", $msgs));
	}
	return $out;
}

function shop_chat_last_message($c) {
	$r = fb_row("SELECT body FROM ".fb_t('tp_shop_chat_msgs')." WHERE chat_id = ? AND role = 'bot' ORDER BY id DESC LIMIT 1", 'i', array((int)$c['id']));
	$m = $r ? json_decode($r['body'], true) : null;
	return is_array($m) ? $m : null;
}
function shop_chat_last_buttons($c) { $m = shop_chat_last_message($c); return ($m && !empty($m['buttons'])) ? $m['buttons'] : array(); }
