<?php
require_once __DIR__ . '/../web/classes/mysql_compat.php';
function getHost($Address) {
   $parseUrl = parse_url(trim($Address));
   if (!empty($parseUrl['host'])) {
       return trim($parseUrl['host']);
   }
   $pathParts = explode('/', $parseUrl['path'] ?? '', 2);
   return trim($pathParts[0]);
}

// build a grouped weekly opening-hours summary, e.g.
// [['days' => 'Mo - Mi', 'hours' => '12:00 - 22:00'], ['days' => 'Fr - Sa', 'hours' => '14:30 - 00:00'], ...]
function getWeeklyHoursSummary($outlet) {
	$isDe = !isset($_SESSION['lang']) || substr($_SESSION['lang'], 0, 2) === 'de';
	$dayLabels = $isDe
		? array(1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 0 => 'So')
		: array(1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun');
	$order = array(1, 2, 3, 4, 5, 6, 0);
	$closedDays = array_filter(explode(',', $outlet['outlet_closeday'] ?? ''), 'strlen');

	$rows = array();
	foreach ($order as $day) {
		if (in_array((string)$day, $closedDays, true)) {
			$rows[] = array('day' => $day, 'label' => null);
			continue;
		}
		$dayOpen = $outlet[$day.'_open_time'] ?? '00:00:00';
		$dayClose = $outlet[$day.'_close_time'] ?? '00:00:00';
		$isCustom = ($dayOpen !== '00:00:00');
		$open = $isCustom ? $dayOpen : $outlet['outlet_open_time'];
		$close = $isCustom ? $dayClose : $outlet['outlet_close_time'];
		$rows[] = array('day' => $day, 'label' => substr($open, 0, 5).' - '.substr($close, 0, 5));
	}

	// group consecutive days that share the same hours
	$groups = array();
	foreach ($rows as $row) {
		$lastIndex = count($groups) - 1;
		if ($lastIndex >= 0 && $groups[$lastIndex]['label'] === $row['label']) {
			$groups[$lastIndex]['days'][] = $row['day'];
		} else {
			$groups[] = array('label' => $row['label'], 'days' => array($row['day']));
		}
	}

	$result = array();
	foreach ($groups as $g) {
		$dayNames = array_map(function($d) use ($dayLabels) { return $dayLabels[$d]; }, $g['days']);
		$rangeLabel = (count($dayNames) > 1) ? $dayNames[0].' - '.end($dayNames) : $dayNames[0];
		$result[] = array('days' => $rangeLabel, 'hours' => $g['label']);
	}
	return $result;
}

// how many minutes before closing the last online booking is still accepted
// ($settings['lastBookingMinutes'] in config.general.php, default 60)
function lastBookingMinutes() {
	global $settings;
	return isset($settings['lastBookingMinutes']) ? max(0, (int)$settings['lastBookingMinutes']) : 60;
}

// true if $time on $date lies after the last accepted online booking
// (uses the daily open/close times already resolved into $_SESSION['selOutlet'])
function isPastLastBooking($date, $time) {
	$open  = date('H:i:s', strtotime($_SESSION['selOutlet']['outlet_open_time']));
	$close = date('H:i:s', strtotime($_SESSION['selOutlet']['outlet_close_time']));
	$slot  = date('H:i:s', strtotime($time));
	$closeTs = strtotime($date.' '.$close);
	$slotTs  = strtotime($date.' '.$slot);
	if ($closeTs === false || $slotTs === false) {
		return false;
	}
	// closing after midnight (e.g. 14:30 - 00:00): close and late slots belong to the next day
	if ($close <= $open) {
		$closeTs += 86400;
		if ($slot < $open) {
			$slotTs += 86400;
		}
	}
	return $slotTs > $closeTs - lastBookingMinutes() * 60;
}

// texts of the public booking form that are not in the language files (German / English; every
// other language falls back to English, like cancel.php). Usage: bt('key') or bt('key', 3)
function bt($key, $arg = null) {
	static $tr = null;
	if ($tr === null) {
		$tr = array(
			'de' => array(
				'subtitle'       => 'Online-Reservierung',
				'hours_title'    => 'Öffnungszeiten',
				'closed'         => 'Geschlossen',
				'pax_less'       => 'weniger Gäste',
				'pax_more'       => 'mehr Gäste',
				'pick_time'      => 'Bitte wähle eine Uhrzeit aus.',
				'next'           => 'Weiter',
				'back'           => '‹ Zurück',
				'details_title'  => 'Reservierungsdetails',
				'checkout'       => 'Check-out',
				'contact_mail'   => 'E-Mail schreiben',
				'contact_call'   => 'Anrufen',
				'closed_day'     => 'An diesem Tag haben wir geschlossen.',
				'closed_day_hint' => 'Wähle bitte ein anderes Datum, wir freuen uns auf dich.',
				'online_blocked' => 'Für diesen Tag nehmen wir online keine Reservierungen an.',
				'online_blocked_hint' => 'Wähle ein anderes Datum oder frag uns direkt, vielleicht finden wir eine Lösung.',
				'group_big'      => 'Gruppen ab %d Personen planen wir gern persönlich.',
				'group_big_hint' => 'Schreib uns oder ruf kurz an, dann richten wir alles passend für euch ein.',
				'no_tables'      => 'Für %d Personen ist an diesem Tag alles belegt.',
				'no_tables_hint' => 'Vielleicht klappt ein anderes Datum. Oder frag uns direkt, manchmal ist noch etwas möglich.',
				'confirmed'      => 'Reservierung bestätigt',
				'back_site'      => 'Zurück zur Website',
				'cancel_res'     => 'Reservierung stornieren',
				'pending_title'  => 'Anfrage eingegangen',
				'pending_text'   => 'Vielen Dank für deine Anfrage! Da es sich um eine größere Gruppe handelt, bestätigen wir dir deinen Tisch persönlich. Du erhältst in Kürze eine finale Bestätigung per E-Mail.',
				'waitlist_text'  => 'Für deinen Wunschtermin sind aktuell keine Tische mehr frei. Wir haben dich auf die Warteliste gesetzt und melden uns, sobald ein Platz frei wird.',
				'error_text'     => 'Deine Reservierung konnte leider nicht angelegt werden.',
				'error_retry'    => 'Bitte versuche es noch einmal oder schreib uns direkt:',
				'retry'          => 'Erneut versuchen',
			),
			'en' => array(
				'subtitle'       => 'Online reservation',
				'hours_title'    => 'Opening hours',
				'closed'         => 'Closed',
				'pax_less'       => 'fewer guests',
				'pax_more'       => 'more guests',
				'pick_time'      => 'Please select a time.',
				'next'           => 'Next',
				'back'           => '‹ Back',
				'details_title'  => 'Reservation details',
				'checkout'       => 'Checkout',
				'contact_mail'   => 'Send an email',
				'contact_call'   => 'Call us',
				'closed_day'     => 'We are closed on this day.',
				'closed_day_hint' => 'Please pick another date, we would love to see you.',
				'online_blocked' => 'We are not taking online reservations for this day.',
				'online_blocked_hint' => 'Pick another date or ask us directly, we may still find a solution.',
				'group_big'      => 'We like to plan groups of %d or more personally.',
				'group_big_hint' => 'Write to us or give us a quick call and we will set everything up for you.',
				'no_tables'      => 'Everything is booked for %d guests on this day.',
				'no_tables_hint' => 'Another date may work. Or ask us directly, sometimes something is still possible.',
				'confirmed'      => 'Reservation confirmed',
				'back_site'      => 'Back to website',
				'cancel_res'     => 'Cancel reservation',
				'pending_title'  => 'Request received',
				'pending_text'   => 'Thank you for your request! As this is a larger group, we will confirm your table personally. You will receive a final confirmation by email shortly.',
				'waitlist_text'  => 'There are currently no tables left for your requested time. We have put you on the waiting list and will get in touch as soon as a table becomes available.',
				'error_text'     => 'Unfortunately your reservation could not be created.',
				'error_retry'    => 'Please try again or write to us directly:',
				'retry'          => 'Try again',
			),
		);
	}
	$lang = isset($_SESSION['lang']) ? substr($_SESSION['lang'], 0, 2) : 'de';
	$set = ($lang === 'de') ? $tr['de'] : $tr['en'];
	$text = isset($set[$key]) ? $set[$key] : $key;
	return ($arg !== null) ? sprintf($text, $arg) : $text;
}

// table plan verdict for a slot of the selected day and party size: true/false, or null when the
// table plan is not switched on / can not decide (then the counter logic applies)
function tableplanSlotFits($time, $pax = null) {
	if (!is_file(__DIR__.'/../web/classes/tableplan_assign.class.php')) {
		return null;
	}
	require_once(__DIR__.'/../web/classes/tableplan_assign.class.php');
	$pax = ($pax === null) ? (int)$_SESSION['pax'] : (int)$pax;
	if ($pax < 1) { $pax = 1; }
	return tp_online_fits($_SESSION['outletID'], $_SESSION['selectedDate'], $pax, substr((string)$time, 0, 5));
}

function language_navigation($language, $show_cancel = true) {
		// keep the cancel link's booking number/email when switching language
		$keep = '';
		foreach (array('nr', 'email') as $k) {
			if (isset($_GET[$k]) && $_GET[$k] !== '') {
				$keep .= '&'.$k.'='.urlencode($_GET[$k]);
			}
		}
		echo '<div class="langnav">';
		echo '<div class="lang-picker">';
		echo '<a href="'.$_SERVER['PHP_SELF'].'?lang=en'.htmlspecialchars($keep).'" title="English"'.($language=='en'?' class="active"':'').'>EN</a>';
		echo '<a href="'.$_SERVER['PHP_SELF'].'?lang=de'.htmlspecialchars($keep).'" title="Deutsch"'.($language=='de'?' class="active"':'').'>DE</a>';
		echo '</div>';
		if ($show_cancel) {
			echo '<a href="cancel.php" class="lang-cancel" title="'._delete.'">';
			echo '<svg width="14" height="14" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
			echo '</a>';
		}
		echo '</div>';
}

function checkMobile(){
// Mobile Browser 
// Device Detection
// (c) by Andy Moore
 
	$mobile_browser = '0';
 
	if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|android)/i', strtolower($_SERVER['HTTP_USER_AGENT']))) {
	    $mobile_browser++;
	}
 
	if ((strpos(strtolower($_SERVER['HTTP_ACCEPT']),'application/vnd.wap.xhtml+xml') > 0) or ((isset($_SERVER['HTTP_X_WAP_PROFILE']) or isset($_SERVER['HTTP_PROFILE'])))) {
	    $mobile_browser++;
	}    
 
	$mobile_ua = strtolower(substr($_SERVER['HTTP_USER_AGENT'], 0, 4));
	$mobile_agents = array(
	    'w3c ','acs-','alav','alca','amoi','audi','avan','benq','bird','blac',
	    'blaz','brew','cell','cldc','cmd-','dang','doco','eric','hipt','inno',
	    'ipaq','java','jigs','kddi','keji','leno','lg-c','lg-d','lg-g','lge-',
	    'maui','maxo','midp','mits','mmef','mobi','mot-','moto','mwbp','nec-',
	    'newt','noki','oper','palm','pana','pant','phil','play','port','prox',
	    'qwap','sage','sams','sany','sch-','sec-','send','seri','sgh-','shar',
	    'sie-','siem','smal','smar','sony','sph-','symb','t-mo','teli','tim-',
	    'tosh','tsm-','upg1','upsi','vk-v','voda','wap-','wapa','wapi','wapp',
	    'wapr','webc','winw','winw','xda ','xda-');
 
	if (in_array($mobile_ua,$mobile_agents)) {
	    $mobile_browser++;
	}
 
	if (strpos(strtolower($_SERVER['ALL_HTTP']),'OperaMini') > 0) {
	    $mobile_browser++;
	}
 
	if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']),'windows') > 0) {
	    $mobile_browser = 0;
	}

	// return result
	return $mobile_browser;
}

// calculate and print select list with interval times
function timeList($format,$intervall,$field='',$select='',$open_time='00:00:00',$close_time='24:00:00',$showtime=0) 
{ 
		GLOBAL $general,$availability, $tbl_availability;

		// calculate after midnight
		$day    = date("d");
		$endday = ($open_time < $close_time) ? date("d") : date("d")+1;
		$month  = date("m");
		$year   = date("Y");
		
		// init timeslots array
		$timeslots = array();
		// build list of timeslots from starttime to endtime
		// in predefined intervall
		
		// set weather we have a daily or general break
		// daily has priority
		$week_day = date('w', strtotime($_SESSION['selectedDate']) );
		$breaktime_open = ($_SESSION['selOutlet'][$week_day.'_open_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_open_break'] : $_SESSION['selOutlet']['outlet_open_break'];
		$breaktime_close = ($_SESSION['selOutlet'][$week_day.'_close_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_close_break'] : $_SESSION['selOutlet']['outlet_close_break'];
		
		// limit booking time at actual day
		// not to book past times
		// Blocking reservations x hour(s) before an outlet starts
		// in second e.g. 3600 = 1 hour
		$before_start = 0;
		if ($_SESSION['selectedDate'] == date('Y-m-d') && date('H:i:s',time()+$before_start) > $open_time) {
				//Set opentime to rounded actual time
				$minutes = ceil(date('i')/$general['timeintervall'])*$general['timeintervall'];
				$open_time = date('H',time()+$before_start).":".$minutes;
		}
		// floor($min/60*4)/4*60

		// calculate dates & times
		list($h1,$m1)		= explode(":",$open_time);
		list($h2,$m2)		= explode(":",$close_time);
		list($h3,$m3)		= explode(":",$breaktime_open);
		list($h4,$m4)		= explode(":",$breaktime_close);
		$value  		= mktime($h1+0,$m1+0,0,$month,$day,$year);
		$endtime		= mktime($h2+0,$m2+0,0,$month,$endday,$year) - lastBookingMinutes()*60;
		$open_break  		= mktime($h3+0,$m3+0,0,$month,$day,$year);
		$close_break  		= mktime($h4+0,$m4+0,0,$month,$day,$year);
		$i 			= 1;
		
		echo"<select name='$field' id='$field' size='1' class='drop required' title=' ' >\n";
		echo "<option value='' ";
		if ($select=='') {
			echo "selected='selected'";
		}
		echo ">--</option>\n";
		while( $value <= $endtime )
		{ 
			// get loose of break
			if( $value <= $open_break || ($value >= $close_break && $value<=$endtime) ){
			// Generating the time drop down menu
			//check for maximum passerby
			$max_passerby = ($_SESSION['passerby_max_pax'] == 0) ? $_SESSION['selOutlet']['outlet_max_capacity'] : $_SESSION['passerby_max_pax'];
			$ava_passerby = $max_passerby - $_SESSION['passbyTime'][date('H:i:s',$value)];
			// table plan decides (null = counter logic): only an explicit passerby limit still applies
			$tp_fit = tableplanSlotFits(date('H:i',$value));
			if ($tp_fit !== null && $_SESSION['passerby_max_pax'] == 0) { $ava_passerby = 1; }
				if($ava_passerby>0){
					echo "<option value='".date('H:i',$value)."'";
					if ( $select == date('H:i:s',$value) ) {
						echo ' selected="selected" ';
					}

					 $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
					 $pax_capacity = ($tbl_capacity >=1) ? $_SESSION['outlet_max_capacity']-$availability[date('H:i',$value)]-$_SESSION['pax'] : 0;
					if ( $tp_fit !== null ? !$tp_fit : ($pax_capacity <= 0 || $tbl_capacity < 1) ) {
						echo ' disabled="disabled" ';
					 }
				
					echo " >";
				
					$txt_value = ($format == 24) ? date('H:i',$value) : date("g:i a", $value);
					echo $txt_value;
					if ($showtime == 1) {
						echo " - ".$pax_capacity." Seats free";
					}
					echo"</option>\n";
				}
			}
			// calculate new time
			$value = mktime($h1+0,$m1+$i*$intervall,0,$month,$day,$year); 
			$i++;
		} 
		echo"</select>\n";
}

// calculate and print select list with interval times
function timeFields($format,$intervall,$field='',$select='',$open_time='00:00:00',$close_time='24:00:00',$showtime=0) 
{ 
		GLOBAL $general,$availability, $tbl_availability;
		// calculate after midnight
		$day    = date("d");
		$endday = ($open_time < $close_time) ? date("d") : date("d")+1;
		$month  = date("m");
		$year   = date("Y");
		
		// counter for 2nd time column
		$nd == FALSE;
		
		// init timeslots array
		$timeslots = array();
		// build list of timeslots from starttime to endtime
		// in predefined intervall
		
		// set weather we have a daily or general break
		// daily has priority
		$week_day = date('w', strtotime($_SESSION['selectedDate']) );
		$breaktime_open = ($_SESSION['selOutlet'][$week_day.'_open_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_open_break'] : $_SESSION['selOutlet']['outlet_open_break'];
		$breaktime_close = ($_SESSION['selOutlet'][$week_day.'_close_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_close_break'] : $_SESSION['selOutlet']['outlet_close_break'];
		
		// limit booking time at actual day
		// not to book past times
		// Blocking reservations x hour(s) before an outlet starts
		// in second e.g. 3600 = 1 hour
		$before_start = 0;
		if ($_SESSION['selectedDate'] == date('Y-m-d') && date('H:i:s',time()+$before_start) > $open_time) {
				//Set opentime to rounded actual time
				$minutes = ceil(date('i')/$general['timeintervall'])*$general['timeintervall'];
				$open_time = date('H',time()+$before_start).":".$minutes;
		}
		// floor($min/60*4)/4*60

		// calculate dates & times
		list($h1,$m1)		= explode(":",$open_time);
		list($h2,$m2)		= explode(":",$close_time);
		list($h3,$m3)		= explode(":",$breaktime_open);
		list($h4,$m4)		= explode(":",$breaktime_close);
		$value  		= mktime($h1+0,$m1+0,0,$month,$day,$year);
		$endtime		= mktime($h2+0,$m2+0,0,$month,$endday,$year) - lastBookingMinutes()*60;
		$open_break  		= mktime($h3+0,$m3+0,0,$month,$day,$year);
		$close_break  		= mktime($h4+0,$m4+0,0,$month,$day,$year);
		$i 			= 1;
		
		// calculate the half of the time to make 2 columns
		$halftime = $value + ceil(($endtime - $value)/2);
		
		echo "<div id='timefield' class='required radio'>";
		 echo "<div class='elem1'>";
		
		while( $value <= $endtime ){
		 if( true ){
			// get loose of break
			if( $value <= $open_break || ($value >= $close_break && $value<=$endtime) ){
			// Generating the time drop down menu
			//check for maximum passerby
			$max_passerby = ($_SESSION['passerby_max_pax'] == 0) ? $_SESSION['selOutlet']['outlet_max_capacity'] : $_SESSION['passerby_max_pax'];
			$ava_passerby = $max_passerby - $_SESSION['passbyTime'][date('H:i:s',$value)];
			// table plan decides (null = counter logic): only an explicit passerby limit still applies
			$tp_fit = tableplanSlotFits(date('H:i',$value));
			if ($tp_fit !== null && $_SESSION['passerby_max_pax'] == 0) { $ava_passerby = 1; }
				if($ava_passerby>0){
					 if ($tp_fit !== null) {
						$slot_disabled = !$tp_fit;
					 } else {
						$tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
						$pax_capacity = ($tbl_capacity >=1) ? $max_passerby-$availability[date('H:i',$value)]-$_SESSION['pax'] : 0;
						$slot_disabled = ($pax_capacity < 0 || $tbl_capacity < 1);
					 }

					echo "<label class='timeslot".($slot_disabled ? " timeslot-disabled" : "")."'>";
					echo "<input name='$field' type='radio' value='".date('H:i',$value)."'";
					if ( $select == date('H:i:s',$value) ) {
						echo ' selected="selected" ';
					}
					if ( $slot_disabled ) {
						echo ' disabled="disabled" ';
					 }
					echo " ><span class='radiotext'>";
				
					$txt_value = ($format == 24) ? date('H:i',$value) : date("g:i a", $value);
					echo $txt_value;
					echo "</span></label>";
				}
			}
			// calculate new time
			$value = mktime($h1+0,$m1+$i*$intervall,0,$month,$day,$year); 
			$i++;
			if ($value >= $halftime && $nd == FALSE) {
				echo "</div><div class='elem2'>";
				$nd = TRUE;
			}
		 }else{
			$value = mktime($h1+0,$m1+$i*$intervall,0,$month,$day,$year); 
			$i++;	
		 } // end if $i%2
		}
		 echo"</div>\n";
		echo"</div>\n";
}

function personsList($max_pax = '12', $standard = '4',$tablename='reservation_pax'){
	GLOBAL $availability, $time;
	 $selected_time = substr($time,0,5);
	
	 $max_passerby = ($_SESSION['passerby_max_pax'] == 0) ? $_SESSION['selOutlet']['outlet_max_capacity'] : $_SESSION['passerby_max_pax'];
	 $pax_capacity = $max_passerby - $availability[$selected_time];
	
	echo"<select name='".$tablename."' id='".$tablename."' class='drop' size='1' $disabled>\n";	
		
		for ($i=1; $i <= $max_pax; $i++) { 
			echo "<option value='".$i."'";

			if ( $i > $pax_capacity ) {
				echo " disabled='disabled' ";
			}else{
				echo ($i == $standard) ? "selected='selected'" : "";
			}
			
			echo ">".$i."</option>\n";
		}

	echo "</select>\n";
}

function titleList($title='',$disabled=''){
	        // translation
		GLOBAL $lang;
   
		echo "<select name='reservation_title' id='reservation_title' class='drop' title=' ' size='1' $disabled>\n";

		// Empty
		/*
		echo "<option value='' ";
		echo ($title=="") ? "selected='selected'" : "";
		echo ">--</option>\n";
		*/
		// Sir
		echo "<option value='M' ";
		echo ($title=='M') ? "selected='selected'" : "";
		echo ">"._M_."</option>\n";
		// Madam
		echo "<option value='W' ";
		echo ($title=='W') ? "selected='selected'" : "";
		echo ">"._W_."</option>\n";
		// Dr.
		echo "<option value='D' ";
		echo ($title=='D') ? "selected='selected'" : "";
		echo ">"._DR_."</option>\n";
		// Prof.
		echo "<option value='P' ";
		echo ($title=='P') ? "selected='selected'" : "";
		echo ">"._PROF_."</option>\n";
		// Family
		echo "<option value='F' ";
		echo ($title=='F') ? "selected='selected'" : "";
		echo ">"._F_."</option>\n";
		// Company
		echo "<option value='C' ";
		echo ($title=='C') ? "selected='selected'" : "";
		echo ">"._C_."</option>\n";
		
		echo "</select>\n";
}

function defineOffDays(){
	
	$date_string = "";
	
	$dayoffs  =	querySQL('maitre_dayoffs');
	
	if($dayoffs){
		foreach ($dayoffs as $dayoff) {
			$date_string .= "'".$dayoff->maitre_date."',";
		}
	}
	
	// days blocked for online bookings in the backend (closed party, sold out)
	if (is_file(__DIR__.'/../web/classes/online_block.class.php')) {
		require_once(__DIR__.'/../web/classes/online_block.class.php');
		foreach (ob_blocked_dates($_SESSION['outletID'], date('Y-m-d'), date('Y-m-d', strtotime('+6 months'))) as $blocked_date => $blocked_reason) {
			$date_string .= "'".$blocked_date."',";
		}
	}

	$outlet_closedays   = querySQL('outlet_closedays');
	$outlet_closedays = "'".$outlet_closedays."'";
	
	$day		= mktime(0, 0, 0, date('m'), date('d'), date('y'));
	$enddate 	= mktime(0, 0, 0, date('m')+6, date('d'), date('y'));

	while ($day < $enddate) {
		if ( strpos($outlet_closedays, date("w",$day)) === false) {
			// do nothing ; '=== false' is manatory
		}else{
			$date_string .= "'".date('Y-m-d',$day)."',";
		}
		//add 1 day
		$day = $day + 86400;
	}
	
	$date_string = substr($date_string,0,-1);
	//print_r($dayoffs);
	//echo $outlet_closedays;
	echo $date_string;
}

function processBooking(){
// rather than recursively calling query, insert all rows with one query
	 GLOBAL $general, $global_basedir,$dbTables,$hook; 
	 // reservation date
	 $reservation_date = $_SESSION['selectedDate'];

	// prepare POST data for storage in database:
	// $keys
	// $values 
	if( $_POST['action'] == 'submit') {
		$keys = array();
		$values = array();
		$i=1;
		
		// Only these form fields may become database columns. The field
		// NAMES of $_POST go straight into the SQL as column names, so they
		// must never be taken from the request (that allowed writing any
		// column, e.g. reservation_id to overwrite another booking through
		// ON DUPLICATE KEY UPDATE). The VALUES were already escaped by
		// secureSuperGlobals() in get_variables.inc.php - do not escape twice.
		$allowed_fields = array(
			'reservation_outlet_id', 'reservation_time', 'reservation_title',
			'reservation_guest_name', 'reservation_guest_email', 'reservation_guest_phone',
			'reservation_pax', 'reservation_hotelguest_yn', 'reservation_booker_name',
			'reservation_notes', 'reservation_advertise', 'reservation_referer',
			'reservation_email_lang'
		);
		foreach ($allowed_fields as $key) {
			if (!isset($_POST[$key]) || is_array($_POST[$key])) {
				continue;
			}
			$value = trim($_POST[$key]);
			if ($key == 'reservation_outlet_id' || $key == 'reservation_pax') {
				$value = (string)(int)$value;
			} elseif ($key == 'reservation_time' && !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
				return 0;
			}
			$keys[$i] = $key;
			$values[$i] = "'".$value."'";
			// remember some values
			if ($key == 'reservation_booker_name') {
				$_SESSION['author'] = $value;
			} elseif ($key == 'reservation_time') {
				$_SESSION['reservation_time'] = "'".$value."'";
			} elseif ($key == 'reservation_pax') {
				$_SESSION['reservation_pax'] = "'".$value."'";
			}
			$i++;
		}
		// the reservation date always comes from the (validated) session date
		$keys[$i] = 'reservation_date';
		$values[$i] = "'".$_SESSION['selectedDate']."'";
		$i++;

		// server-side sanity checks (the form only checks these in the browser)
		$check_pax = (int)$_POST['reservation_pax'];
		if (trim($_POST['reservation_guest_name']) === ''
			|| !filter_var($_POST['reservation_guest_email'], FILTER_VALIDATE_EMAIL)
			|| $check_pax < 1
			|| ((int)$general['max_menu'] > 0 && $check_pax > (int)$general['max_menu'])) {
			return 0;
		}

		// =-=-=-=Store in database =-=-=-=-=-=-=-=-=-=-=-=-=-=-=
			// clear old booking number
			$_SESSION['booking_number'] = '';
			// variables
			$res_pax = ($_POST['reservation_pax']) ? (int)$_POST['reservation_pax'] : 0;
			
			// sanitize old booking numbers
			$clr = querySQL('sanitize_unique_id');
			
			// create and store booking number
			{ // online bookings are always new (reservation_id is not accepted from the form)
			    $_SESSION['booking_number'] = uniqueBookingnumber();
			    //$_SESSION['messages'][] = _booknum.":&nbsp;&nbsp;' ".$_SESSION['booking_number']." '";
			    $keys[] = 'reservation_bookingnumber';
			    $values[] = "'".$_SESSION['booking_number']."'";
			}
			
		  // =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
		  // enter into database
		  // =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
			
			// build new reservation date
			$index = array_search('reservation_date',$keys);
			// build for availability calculation

			$index = array_search('reservation_wait',$keys);
			if($index){
				$values[$index] = '1';
				$waitlist = '1';
			}
			
			
			//Check Availability
			// =-=-=-=-=-=-=-=-=
			
			// get Pax by timeslot
			$resbyTime = reservationsByTime('pax');
			$tblbyTime = reservationsByTime('tbl');
			// get availability by timeslot
			$occupancy = getAvailability($resbyTime,$general['timeintervall']);
			$tbl_occupancy = getAvailability($tblbyTime,$general['timeintervall']);
			
			//cut both " ' " from reservation_pax
			$res_pax = substr($_SESSION['reservation_pax'], 0, -1);
			$res_pax = substr($_SESSION['reservation_pax'], 1);
			
			$startvalue = $_SESSION['reservation_time'];
			//cut both " ' " from reservation_time
			$startvalue = substr($startvalue, 0, -1);
			$startvalue = substr($startvalue, 1);
			
			  $val_capacity = $_SESSION['outlet_max_capacity']-$occupancy[$startvalue];
			  $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_occupancy[$startvalue]; 

			// table plan decides when it is switched on (null = counter logic)
			$tp_fit = tableplanSlotFits($startvalue, (int)$res_pax);
			$is_full = ($tp_fit !== null) ? !$tp_fit : ((int)$res_pax > $val_capacity || $tbl_capacity < 1);
			if( $is_full ){
				//prevent double entry 	
				$index = array_search('reservation_wait',$keys);
				if($index>0){			
					  $values[$index] = '1'; // = waitlist
					  $waitlist = '1';
				}else{
					  // error on new entry
					  $keys[] = 'reservation_wait';
					  $values[] = '1'; // = waitlist
					  $waitlist = '1';
				}
			}
			// END Availability

			// Approval layer: outlets can require staff sign-off for large parties. A pending
			// request still counts fully against capacity (same rows/columns as a confirmed
			// booking above), it just gets a different guest mail and confirmation page, and
			// waits for web/ajax/reservation_approval_action.php to approve or decline it.
			require_once(__DIR__.'/../web/classes/approval.class.php');
			appr_ensure_schema();
			$approval_threshold = isset($_SESSION['selOutlet']['approval_pax_threshold']) ? (int)$_SESSION['selOutlet']['approval_pax_threshold'] : 0;
			$needs_approval = ($waitlist != 1) && $approval_threshold > 0 && (int)$res_pax > $approval_threshold;
			if ($needs_approval) {
				$keys[] = 'reservation_approval';
				$values[] = "'pending'";
			}

		  if ($waitlist != 1){
			// enter into database - a new online booking must only ever INSERT,
			// never update an existing reservation (no ON DUPLICATE KEY UPDATE)
			$query = "INSERT INTO `$dbTables->reservations` (`".implode('`,`', $keys)."`) VALUES (".implode(',', $values).")";
			$result = query($query);
			$_SESSION['result'] = $result;

			// Reservation ID
	 		$resID = mysql_insert_id();

			// *** send confirmation email
				// ** PHPMailer class
				require_once('../web/classes/phpmailer/class.phpmailer.php');
				// ** plugin hook
				if ($hook->hook_exist('after_booking')) {
					$_SESSION['form'] = $_POST;
					$hook->execute_hook('after_booking', $needs_approval ? 'pending' : 'confirmed');
				}

			// store new reservation in history
			$result = query("INSERT INTO `$dbTables->res_history` (reservation_id,author) VALUES ('%d','%s')",$resID,mysql_real_escape_string(isset($_SESSION['author']) ? $_SESSION['author'] : ''));
			// table plan: put the new reservation on a table (optional, never breaks the booking) -
			// skipped for a pending request, since staff might still decline it
			if (!$needs_approval && is_file(__DIR__.'/../web/classes/tableplan_assign.class.php')) {
				require_once(__DIR__.'/../web/classes/tableplan_assign.class.php');
				tp_hook_after_booking($resID);
			}
			// Reservation was done - 2 = confirmed, 3 = pending staff approval
			$waitlist = $needs_approval ? 3 : 2;
		  }
			// reservation done, handle back waitlist status
			return $waitlist;
	 }
}
?>