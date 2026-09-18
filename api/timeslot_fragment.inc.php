<?php
// Renders the time-slot picker + any special-event ads for the current
// $_SESSION (outletID, selectedDate, pax). Shared by reserve.php's initial
// render and ajax_timeslots.php's pax-change refresh, so both always stay
// in sync.
if ($time_selector == "radio") {
	timeFields($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
}else{
	timeList($general['timeformat'], $general['timeintervall'],'reservation_time',$time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0);
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
