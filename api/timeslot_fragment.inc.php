<?php
// Renders the time-slot picker + any special-event ads for the current
// $_SESSION (outletID, selectedDate, pax). Shared by reserve.php's initial
// render and ajax_timeslots.php's pax-change refresh, so both always stay
// in sync.
$contact_email = isset($prp_info['email']) ? $prp_info['email'] : '';

if (!function_exists('reserve_contact_message')) {
	function reserve_contact_message($text, $contact_email) {
		echo "<div class='alert_info'><p>".$text;
		if ($contact_email) {
			echo "<br/>Bitte kontaktiere uns direkt per E-Mail: <a href='mailto:".$contact_email."'>".$contact_email."</a>";
		}
		echo "</p></div>";
	}
}

// is the outlet closed on this weekday at all? (the datepicker already
// greys these days out client-side, but selectedDate can also arrive
// via a direct link/URL, so this needs to be enforced here too)
$selected_weekday = date('w', strtotime($_SESSION['selectedDate']));
$closed_weekdays = array_filter(explode(',', $_SESSION['selOutlet']['outlet_closeday'] ?? ''), 'strlen');
$outlet_closed_today = in_array((string)$selected_weekday, $closed_weekdays, true);

// is this party bigger than what we take online at all?
$max_menu = (int)$general['max_menu'];
$party_too_big = ($max_menu > 0 && (int)$_SESSION['pax'] > $max_menu);

// blocked for online bookings in the backend (closed party, sold out)
include_once __DIR__.'/../web/classes/online_block.class.php';
$online_blocked = ob_is_blocked($_SESSION['outletID'], $_SESSION['selectedDate']);

if ($outlet_closed_today) {
	reserve_contact_message("An diesem Tag haben wir leider geschlossen. Bitte wähle ein anderes Datum.", $contact_email);
} elseif ($online_blocked) {
	reserve_contact_message("An diesem Tag sind Online-Reservierungen leider nicht möglich. Bitte wähle ein anderes Datum.", $contact_email);
} elseif ($party_too_big) {
	reserve_contact_message("Für Gruppen ab ".($max_menu + 1)." Personen bitten wir um eine persönliche Anfrage.", $contact_email);
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
		echo $timeslots_html;
	} else {
		reserve_contact_message("Für ".(int)$_SESSION['pax']." Personen sind an diesem Tag leider keine Tische mehr frei.", $contact_email);
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
