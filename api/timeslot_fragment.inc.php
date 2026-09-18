<?php
// Renders the time-slot picker + any special-event ads for the current
// $_SESSION (outletID, selectedDate, pax). Shared by reserve.php's initial
// render and ajax_timeslots.php's pax-change refresh, so both always stay
// in sync.
ob_start();
if ($time_selector == "radio") {
	timeFields($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
}else{
	timeList($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
}
$timeslots_html = ob_get_clean();

// count slots that are actually still bookable (not the closed/full ones)
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
	$contact_email = isset($prp_info['email']) ? $prp_info['email'] : '';
	echo "<div class='alert_info'><p>";
	echo "Für ".(int)$_SESSION['pax']." Personen sind an diesem Tag leider keine Tische mehr frei.";
	if ($contact_email) {
		echo "<br/>Bitte kontaktiere uns direkt per E-Mail: <a href='mailto:".$contact_email."'>".$contact_email."</a>";
	}
	echo "</p></div>";
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
