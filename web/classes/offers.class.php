<?php
/*
 * "Angebotszeiten": time ranges (weekdays, hours, optional date range) that are highlighted in the
 * booking widget with a title (emoji allowed), an optional text and an optional picture. Own table
 * tp_offers (utf8mb4, so emoji survive), created on first use.
 */
require_once __DIR__.'/feedback.class.php';

function offers_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_offers')." (
		`offer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`outlet_id` INT NOT NULL,
		`title` VARCHAR(120) NOT NULL DEFAULT '',
		`description` TEXT,
		`weekdays` VARCHAR(20) NOT NULL DEFAULT '',
		`time_from` TIME NOT NULL DEFAULT '00:00:00',
		`time_to` TIME NOT NULL DEFAULT '23:59:00',
		`all_day` TINYINT(1) NOT NULL DEFAULT 0,
		`date_from` DATE NULL,
		`date_to` DATE NULL,
		`image` VARCHAR(80) NULL,
		`active` TINYINT(1) NOT NULL DEFAULT 1,
		`created_at` DATETIME NOT NULL,
		KEY `outlet` (`outlet_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

// emoji need a 4-byte connection; utf8mb4 also reads the older utf8 columns correctly
function offers_utf8mb4() { @mysqli_set_charset(fb_db(), 'utf8mb4'); }

function offers_weekday_labels() { return array(1 => 'Mo.', 2 => 'Di.', 3 => 'Mi.', 4 => 'Do.', 5 => 'Fr.', 6 => 'Sa.', 0 => 'So.'); }

function offers_all($outlet_id) {
	offers_ensure_schema(); offers_utf8mb4();
	return fb_rows("SELECT * FROM ".fb_t('tp_offers')." WHERE outlet_id = ? ORDER BY active DESC, time_from, offer_id", 'i', array((int)$outlet_id));
}

function offers_find($offer_id) {
	offers_ensure_schema(); offers_utf8mb4();
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_offers')." WHERE offer_id = ? LIMIT 1", 'i', array((int)$offer_id));
	return $rows ? $rows[0] : null;
}

// active offers that apply on this date (weekday and optional date range)
function offers_for_day($outlet_id, $date) {
	$w = (string)date('w', strtotime($date));
	$out = array();
	foreach (offers_all($outlet_id) as $o) {
		if (!(int)$o['active']) { continue; }
		if (!in_array($w, explode(',', $o['weekdays']), true)) { continue; }
		if ($o['date_from'] !== null && $date < $o['date_from']) { continue; }
		if ($o['date_to'] !== null && $date > $o['date_to']) { continue; }
		$out[] = $o;
	}
	return $out;
}

// does the time slot ('HH:MM') fall into the offer? the end time itself counts
function offers_slot_in($o, $hhmm) {
	if ((int)$o['all_day']) { return true; }
	return $hhmm >= substr($o['time_from'], 0, 5) && $hhmm <= substr($o['time_to'], 0, 5);
}

function offers_time_text($o) {
	return (int)$o['all_day'] ? 'ganztägig' : substr($o['time_from'], 0, 5).' – '.substr($o['time_to'], 0, 5);
}

// escaped text, http(s) links clickable, line breaks kept
function offers_linkify($text) {
	$h = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
	$h = preg_replace_callback('#https?://[^\s<]+#i', function ($m) {
		$url = $m[0]; $trail = '';
		while ($url !== '' && strpos('.,;:!?)', substr($url, -1)) !== false) { $trail = substr($url, -1).$trail; $url = substr($url, 0, -1); }
		return '<a href="'.$url.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>'.$trail;
	}, $h);
	return nl2br($h);
}

/*
 * Widget: highlights the matching time slots in the picker html and puts one chip per offer above it
 * (title, time range, "more" button that opens a dialog with text and picture). $slots_html is the
 * html of the time picker; returns it unchanged when no offer applies.
 */
function offers_decorate($slots_html, $outlet_id, $date) {
	$offers = offers_for_day($outlet_id, $date);
	if (!$offers) { return $slots_html; }
	$shown = array();
	$slots_html = preg_replace_callback("#<label class='timeslot( timeslot-disabled)?'><input name='reservation_time' type='radio' value='(\d\d:\d\d)'#", function ($m) use ($offers, &$shown) {
		foreach ($offers as $o) {
			if (offers_slot_in($o, $m[2])) {
				$shown[$o['offer_id']] = true;
				return "<label class='timeslot".$m[1]." timeslot-offer' data-offer='".(int)$o['offer_id']."' title='".htmlspecialchars($o['title'], ENT_QUOTES, 'UTF-8')."'><input name='reservation_time' type='radio' value='".$m[2]."'";
			}
		}
		return $m[0];
	}, $slots_html);
	if (!$shown) { return $slots_html; }
	$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
	$chips = ''; $dialogs = '';
	foreach ($offers as $o) {
		if (empty($shown[$o['offer_id']])) { continue; }
		$id = (int)$o['offer_id'];
		$has_more = trim((string)$o['description']) !== '' || !empty($o['image']);
		$inner = "<span class='offer-title'>".$e($o['title'])."</span><span class='offer-time'>".$e(offers_time_text($o))."</span>";
		if ($has_more) {
			$chips .= "<button type='button' class='offer-chip offer-chip-more' data-offer-open='offer-dlg-$id'>".$inner."<span class='offer-more'>Mehr erfahren</span></button>";
			$dialogs .= "<dialog class='offer-dialog' id='offer-dlg-$id' aria-labelledby='offer-dlg-t-$id'><h2 id='offer-dlg-t-$id'>".$e($o['title'])."</h2>"
				.(!empty($o['image']) ? "<img src='../uploads/offers/".$e($o['image'])."' alt=''/>" : '')
				.(trim((string)$o['description']) !== '' ? "<p>".offers_linkify($o['description'])."</p>" : '')
				."<form method='dialog'><button class='offer-close'>Schließen</button></form></dialog>";
		} else {
			$chips .= "<span class='offer-chip'>".$inner."</span>";
		}
	}
	return "<div class='offer-legend'>".$chips."</div>".$slots_html.$dialogs;
}
