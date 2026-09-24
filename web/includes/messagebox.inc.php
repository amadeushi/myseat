<?php
// get outlet maximum capacity
$maxC = maxCapacity();
// get Pax by timeslot
$passbyTime = reservationsByTime('pax');

// online booking block of the day
include_once 'classes/online_block.class.php';
$ob_note = ($_SESSION['page'] == 1 || $_SESSION['page'] == 2) ? ob_get($_SESSION['outletID'], $_SESSION['selectedDate']) : false;
if ($ob_note) {
	echo "<div class='alert_tip'>
	<p class='center margin-bottom-10'>".uiIcon('info')." Online-Reservierungen sind für diesen Tag gesperrt".($ob_note['reason'] !== '' ? " &ndash; ".htmlspecialchars($ob_note['reason']) : "").". Im Backend können weiterhin Reservierungen erfasst werden.</p>
	</div>";
}

// Maitre day comment
if (isset($maitre) && trim($maitre['maitre_comment_day']) != "" && $_SESSION['page'] == 2 ) {
	echo "<div class='alert_tip'>
	<p class='center margin-bottom-10'>".uiIcon('info')." ";
		// maitre comment
		echo $maitre['maitre_comment_day']."<br>";
	echo "</p></div>";
	$maitre['maitre_comment_day'] = '';	
}

// Max passerby warning
$set = 0;
if (isset($passbyTime) && $_SESSION['passerby_max_pax'] > 0) {
	// one alert with all affected times, not one line per time slot
	$full_times = array();
	foreach ($passbyTime as $key => $value) {
		if ( $_SESSION['passerby_max_pax']-$value <= 0 && $_SESSION['page'] == 2 ) {
			$full_times[] = formatTime($key,$general['timeformat']);
		}
	}
	if ($full_times) {
		$set = 1;
		echo "<div class='alert_warning'><p>".uiIcon('warning')." <strong>"._sentence_16."</strong> <span class='alert-times'>".implode(' &middot; ', $full_times)."</span></p></div>";
	}
}

// Messages
if (isset($_SESSION['messages']) && count($_SESSION['messages']) > 0) {
	echo "<div class='alert_error'>
	<p>".uiIcon('warning')." ";
	foreach ($_SESSION['messages'] as $key => $value) {
		echo $value."<br/>";
	}
	echo "</p></div>";
	//Clear messages after printing
	$_SESSION['messages'] = array();
}

// Error & success messages
if ( !empty($_SESSION['errors']) ) {
	echo "<div id='messageBox'>";
	echo "<div class='alert_error'>
	<p>".uiIcon('error')." ";
	foreach ($_SESSION['errors'] as $key => $value) {
		echo $value."<br/>";
	}
	echo "</p></div></div>";
	//Clear errors after printing
	$_SESSION['errors'] = array();
}else if (!empty($_SESSION['result']) ) {
	echo "<div id='messageBox'>";
	echo "<div class='alert_success'><p>".uiIcon('check')." ". _new_entry ."</p></div></div>";
	unset($_SESSION['result']);
}

// Special event advertise
$events_advertise = querySQL('event_advertise');
if ($events_advertise && ($_SESSION['page'] == 2 || $_SESSION['page'] == 1) ) {
	echo "<div class='alert_ads'>
	<div class='ads'>"._ads."</div>";
		// special events
		foreach($events_advertise as $row) {
			echo "
			".uiIcon('cutlery')."
			<span class='bold'>
			<a href='".$_SERVER['SCRIPT_NAME']."?outletID=".$row->outlet_id."&selectedDate=".$row->event_date."'>".
			_sp_events.": ".date($general['dateformat'],strtotime($row->event_date))." ".
			$row->subject."</a> | ".$row->outlet_name."</span>
			<p>".$row->description."</p><p><cite><span class='bold'>
			".date($general['dateformat'],strtotime($row->event_date)).
			"</span> ".formatTime($row->start_time,$general['timeformat']).
			" - ".formatTime($row->end_time,$general['timeformat'])." | ".
			_ticket_price.": ".number_format($row->price,2).
			"</cite></p>";
			if( key($row) != count($events_advertise)-1 ) {
				echo"<br/>";
			} 
		}
	echo "</div>";
}

// Special event of the day and outlet
$special_events = querySQL('event_data_day');
if ($special_events && $_SESSION['page'] == 2 ) {
	echo "<div class='alert_info'>";
		// special events
		foreach($special_events as $row) {
			$special_event_subject = $row->subject;
			echo "
			".uiIcon('cutlery')."
			<span class='bold'>
			<a href='".$_SERVER['SCRIPT_NAME']."?outletID=".$row->outlet_id."&selectedDate=".$row->event_date."'>".
			_today.": ".$row->subject."</a></span>
			<p class='margin-bottom-10'>".$row->description."</p><p><cite>
			".date($general['dateformat'],strtotime($row->event_date)).
			" ".formatTime($row->start_time,$general['timeformat']).
			" - ".formatTime($row->end_time,$general['timeformat'])." | ".
			_ticket_price.": ".number_format($row->price,2).
			"</cite></p>";
		}
	echo "</div>";
}

echo "<div id='realtimeupdate'></div>";

?>