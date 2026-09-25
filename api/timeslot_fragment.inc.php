<?php
// Renders the time-slot picker + any special-event ads for the current
// $_SESSION (outletID, selectedDate, pax). Shared by reserve.php's initial
// render and ajax_timeslots.php's pax-change refresh, so both always stay
// in sync.
$contact_email = isset($prp_info['email']) ? $prp_info['email'] : '';

// phone shown as a call button next to the mail button (same number as in the guest mails)
$contact_phone = !empty($settings['mailPhone']) ? trim($settings['mailPhone']) : (isset($prp_info['phone']) ? trim($prp_info['phone']) : '');

if (!function_exists('reserve_contact_message')) {
	// $title says what is going on, $hint what the guest can do about it; contact buttons below
	function reserve_contact_message($title, $hint, $contact_email, $contact_phone = '') {
		$e = function ($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
		echo "<div class='alert_info' role='status'>"
			."<svg class='notice-icon' viewBox='0 0 24 24' fill='none' aria-hidden='true'><circle cx='12' cy='12' r='9' stroke='currentColor' stroke-width='1.6'/><path d='M12 11v5' stroke='currentColor' stroke-width='1.8' stroke-linecap='round'/><circle cx='12' cy='8' r='1' fill='currentColor'/></svg>"
			."<div class='notice-body'><p class='notice-title'>".$e($title)."</p><p class='notice-hint'>".$e($hint)."</p>";
		$tel = preg_replace('/[^\d+]/', '', $contact_phone);
		if (substr($tel, 0, 2) === '00') { $tel = '+'.substr($tel, 2); } elseif (substr($tel, 0, 1) === '0') { $tel = '+49'.substr($tel, 1); }
		if ($contact_email || $tel !== '') {
			echo "<div class='notice-actions'>";
			if ($contact_email) { echo "<a class='notice-btn' href='mailto:".$e($contact_email)."'>".$e(bt('contact_mail'))."</a>"; }
			if ($tel !== '') { echo "<a class='notice-btn' href='tel:".$e($tel)."'>".$e(bt('contact_call').' '.$contact_phone)."</a>"; }
			echo "</div>";
		}
		echo "</div></div>";
	}
}

// is the outlet closed on this date? getDayoff() combines the weekly closing days of the outlet with
// the single-day setting from the backend ("Ruhetag" in the day details, which can also open a day
// that is normally closed). The datepicker greys these days out client-side, but selectedDate can
// also arrive via a direct link/URL, so this needs to be enforced here too
$outlet_closed_today = (getDayoff() == 1);

// is this party bigger than what we take online at all?
$max_menu = (int)$general['max_menu'];
$party_too_big = ($max_menu > 0 && (int)$_SESSION['pax'] > $max_menu);

// blocked for online bookings in the backend (closed party, sold out)
include_once __DIR__.'/../web/classes/online_block.class.php';
$online_blocked = ob_is_blocked($_SESSION['outletID'], $_SESSION['selectedDate']);

if ($outlet_closed_today) {
	reserve_contact_message(bt('closed_day'), bt('closed_day_hint'), '', '');
} elseif ($online_blocked) {
	reserve_contact_message(bt('online_blocked'), bt('online_blocked_hint'), $contact_email, $contact_phone);
} elseif ($party_too_big) {
	reserve_contact_message(bt('group_big', $max_menu + 1), bt('group_big_hint'), $contact_email, $contact_phone);
} else {
	ob_start();
	if ($time_selector == "radio") {
		timeFields($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
	}else{
		timeList($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
	}
	$timeslots_html = ob_get_clean();

	// count slots that are actually still bookable (not the full ones)
	preg_match_all("/<input name='reservation_time' type='radio'[^>]*>/", $timeslots_html, $slot_matches);
	$available_slot_count = 0;
	foreach ($slot_matches[0] as $slot_tag) {
		if (strpos($slot_tag, 'disabled') === false) {
			$available_slot_count++;
		}
	}

	if ($available_slot_count > 0) {
		// offer times (settings > Angebotszeiten): highlight the matching slots, legend chip with a dialog
		require_once __DIR__.'/../web/classes/offers.class.php';
		echo offers_decorate($timeslots_html, $_SESSION['outletID'], $_SESSION['selectedDate']);
	} else {
		reserve_contact_message(bt('no_tables', (int)$_SESSION['pax']), bt('no_tables_hint'), $contact_email, $contact_phone);
	}
}

// Special event of the day and outlet
$special_events = querySQL('event_data_day');

if ( $special_events ) {
	echo "<div class='alert_ads'>";
	// special events today at outlet
	foreach($special_events as $row) {
		echo "<span class='bold'>
		<a href='".$_SERVER['SCRIPT_NAME']."?outletID=".$row->outlet_id."&selectedDate=".$row->event_date."'>".
		_today.": ".$row->subject."</a></span>
		<p>".$row->description."<br/><cite><span class='bold'>
		".date($general['dateformat'],strtotime($row->event_date)).
		"</span> ".formatTime($row->start_time,$general['timeformat']).
		" - ".formatTime($row->end_time,$general['timeformat'])." | ".
		_ticket_price.": ".number_format($row->price,2).
		"</cite></p>";
		if( key($row) != count($special_events)-1 && key($row) > 1) {
			// BR between special events
			echo"<br/>";
		}
	}
	echo "</div>";
}
