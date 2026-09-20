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
	$dayLabels = array(1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 0 => 'So');
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
				if($ava_passerby>0){
					echo "<option value='".date('H:i',$value)."'";
					if ( $select == date('H:i:s',$value) ) {
						echo ' selected="selected" ';
					}

					 $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
					 $pax_capacity = ($tbl_capacity >=1) ? $_SESSION['outlet_max_capacity']-$availability[date('H:i',$value)]-$_SESSION['pax'] : 0; 
					if ( $pax_capacity <= 0 || $tbl_capacity < 1) {
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
				if($ava_passerby>0){
					 $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
					 $pax_capacity = ($tbl_capacity >=1) ? $max_passerby-$availability[date('H:i',$value)]-$_SESSION['pax'] : 0;
					 $slot_disabled = ($pax_capacity < 0 || $tbl_capacity < 1);

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
			'reservation_notes', 'reservation_advertise', 'reservation_referer'
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

			if( (int)$res_pax > $val_capacity || $tbl_capacity < 1 ){
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
					$hook->execute_hook('after_booking');
				}
			
			// store new reservation in history
			$result = query("INSERT INTO `$dbTables->res_history` (reservation_id,author) VALUES ('%d','%s')",$resID,mysql_real_escape_string(isset($_SESSION['author']) ? $_SESSION['author'] : ''));
			// Reservation was done
			$waitlist = 2;
		  }	
			// reservation done, handle back waitlist status
			return $waitlist;
	 }
}
?>