<?php
/*
Searches an array for a given value (insensitive case) and returns the corresponding key
*/
function array_isearch($value, $array)
{
   foreach ($array as $key => $val)
   {
      $val = strtolower($val);
	  $value = strtolower($value);

      if($val == $value) return $key;
   }
   return false;
}

// calculate and print select list with intervall times
function getTimeList($format,$intervall,$field='',$select='',$open_time='00:00:00',$close_time='24:00:00',$showtime=0,$required='') 
{ 
		GLOBAL $availability, $tbl_availability;
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
		
		// calculate dates & times
		list($h1,$m1)		= explode(":",$open_time);
		list($h2,$m2)		= explode(":",$close_time);
		list($h3,$m3)		= explode(":",$breaktime_open);
		list($h4,$m4)		= explode(":",$breaktime_close);
		$value  			= mktime($h1+0,$m1+0,0,$month,$day,$year);
		$endtime		 	= mktime($h2+0,$m2+0,0,$month,$endday,$year);
		$open_break  		= mktime($h3+0,$m3+0,0,$month,$day,$year);
		$close_break  		= mktime($h4+0,$m4+0,0,$month,$day,$year);
		$i 					= 1;
		
		//echo $value."/".$endtime."/".date('H:i',$endtime)."//"; // error reporting
		
		echo"<select name='".$field."' id='".$field."' size='1' title=' ' class='".$required."'>\n";
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
			      echo "<option value='".date('H:i',$value)."'";
			      if ( $select == date('H:i:s',$value) ) {
				      echo "selected='selected'";
			      }
			      echo " >";			
								
			      $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
			      $pax_capacity = ($tbl_capacity >=1) ? $_SESSION['outlet_max_capacity']-$availability[date('H:i',$value)] : 0;

			      $txt_value = ($format == 24) ? date('H:i',$value) : date("g:i a", $value);
			      echo $txt_value;
				
			      if ($showtime == 1) {
				 echo " - ".$pax_capacity." "._seats." "._free;
			      }
			      
			      echo"</option>\n";
			}
			// calculate new time
			$value = mktime($h1+0,$m1+$i*$intervall,0,$month,$day,$year); 
			$i++;
		} 
		echo"</select>\n";
}
// calculate and print select list with intervall times
function widgetTimeList($format,$intervall,$open_time='00:00:00',$close_time='24:00:00') 
{ 
		GLOBAL $availability, $tbl_availability;
		// calculate after midnight
		$day    = date("d");
		$endday = ($open_time < $close_time) ? date("d") : date("d")+1;
		$month  = date("m");
		$year   = date("Y");
		
		// init variables & arrays
		$timeslots = array();
		$output = '';
		$ava_passerby = '';
		$pax_capacity = '';
		
		// build list of timeslots from starttime to endtime
		// in predefined intervall
		
		// set weather we have a daily or general break
		// daily has priority
		$week_day = date('w', strtotime($_SESSION['selectedDate']) );
		$breaktime_open = ($_SESSION['selOutlet'][$week_day.'_open_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_open_break'] : $_SESSION['selOutlet']['outlet_open_break'];
		$breaktime_close = ($_SESSION['selOutlet'][$week_day.'_close_break'] != '00:00:00') ? $_SESSION['selOutlet'][$week_day.'_close_break'] : $_SESSION['selOutlet']['outlet_close_break'];
		
		// calculate dates & times
		list($h1,$m1)		= explode(":",$open_time);
		list($h2,$m2)		= explode(":",$close_time);
		list($h3,$m3)		= explode(":",$breaktime_open);
		list($h4,$m4)		= explode(":",$breaktime_close);
		$value  			= mktime($h1+0,$m1+0,0,$month,$day,$year);
		$endtime		 	= mktime($h2+0,$m2+0,0,$month,$endday,$year);
		$open_break  		= mktime($h3+0,$m3+0,0,$month,$day,$year);
		$close_break  		= mktime($h4+0,$m4+0,0,$month,$day,$year);
		$i 					= 1;
		$marker				= 'NO';
		
		// define dayoff
		$day_off = getDayoff();
		
		while( $value <= $endtime ) { 
			
				// get loose of open time break
				if( ($value <= $open_break || ($value >= $close_break && $value<=$endtime)) && $day_off == 0 ){
					// Generating the time drop down menu
					//check for maximum passerby
					$max_passerby = ($_SESSION['passerby_max_pax'] == 0) ? $_SESSION['selOutlet']['outlet_max_capacity'] : $_SESSION['passerby_max_pax'];
					 $ava_passerby = $max_passerby - $_SESSION['passbyTime'][date('H:i:s',$value)];
					 $tbl_capacity = $_SESSION['outlet_max_tables']-$tbl_availability[date('H:i',$value)];
					 $pax_capacity = ($tbl_capacity >=1) ? $_SESSION['outlet_max_capacity']-$availability[date('H:i',$value)] : 0;
					 if ( $ava_passerby > 0 && $pax_capacity > 0 ) {
						  // Generating the time drop down array
					      $txt_value = ($format == 24) ? date('H:i',$value) : date("g:i a", $value);
						  //$txt_value .= " - ".$ava_passerby.", ".$pax_capacity; Error reporting
						  $output .= "['".$_SESSION['selectedDate']."','".date('H:i',$value)."','".$txt_value."'],\n";
					 }
						  	
				}else if ($marker=='NO'){
					$output .= "['".$_SESSION['selectedDate']."','','-----'],\n";
					$marker = 'YES';
				}
				// calculate new time
				$value = mktime($h1+0,$m1+$i*$intervall,0,$month,$day,$year); 
				$i++;
			} 
		
		// handle back the array in a variable
		return $output;
}

// print select list with titles 
function getTitleList($title='',$disabled=''){
		echo "<select name='reservation_title' id='reservation_title' class='required' title=' ' size='1' $disabled>\n";

		// Empty
		echo "<option value='' ";
		echo ($title=="") ? "selected='selected'" : "";
		echo ">--</option>\n";
		// Sir
		echo "<option value='M' ";
		echo ($title=='M') ? "selected='selected'" : "";
		echo ">"._M_."</option>\n";
		// Madam
		echo "<option value='W' ";
		echo ($title=='W') ? "selected='selected'" : "";
		echo ">"._W_."</option>\n";
		// Doctor
		echo "<option value='D' ";
		echo ($title=='D') ? "selected='selected'" : "";
		echo ">"._DR_."</option>\n";
		// Professor
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

// print select list with titles 
function printTitle($title){

	switch($title){
		case '':
			// no salutation: nothing to print
			return "";
		break;
		case 'M':
			// Sir
			return _M_;
		break;
		case 'W':
			// Madam
			return _W_;
		break;
		case 'D':
			// Doctor
			return _DR_;
		break;
		case 'P':
			// Professor
			return _PROF_;
		break;
		case 'F':
			// Family
			return _F_;
		break;
		case 'C':
			// Company
			return _C_;
		break;
		case 'C':
			// Company
			return _C_;
		break;
	}
	
}

// print select list with type
function getTypeList($title='',$disabled=''){
		echo"<select name='reservation_hotelguest_yn' id='reservation_hotelguest_yn' class='required' title=' ' size='1' $disabled>";
		
		// Empty
		echo "<option value='' ";
		echo ($title=="") ? "selected='selected'" : "";
		echo ">--</option>\n";
		// HG
		echo "<option value='HG' ";
		echo ($title=='HG') ? "selected='selected'" : "";
		echo ">"._HG_."</option>\n";
		// PASS
		echo "<option value='PASS' ";
		echo ($title=='PASS') ? "selected='selected'" : "";
		echo ">"._PASS_."</option>\n";
		// WALK
		echo "<option value='WALK' ";
		echo ($title=='WALK') ? "selected='selected'" : "";
		echo ">"._WALK_."</option>\n";
		
		echo "</select>\n";
}

// print selected type
function printType($type=''){
		
		switch ($type) {
			case 'HG':
				echo _HG_;
				break;
			case 'PASS':
				echo _PASS_;
				break;
			case 'WALK':
				echo _WALK_;
				break;			

		}
}

function getOutletList($outlet_id = 0, $disabled = 'enabled',$tablename='outlet_id'){
	global $title;
	echo"<select name='".$tablename."' id='".$tablename."' size='1' $disabled>\n";
		
		// Empty
//		if (condition) {
			echo "<option value='' ";
			echo ($title=="") ? "selected='selected'" : "";
			echo ">"._general."</option>\n";
//		}

		$outlets = querySQL('db_outlets');
		
		foreach($outlets as $row) {
		 if ( ($row->saison_start<=$row->saison_end 
			 && $_SESSION['selectedDate_saison']>=$row->saison_start 
			 && $_SESSION['selectedDate_saison']<=$row->saison_end) 
			 || ($row->saison_start>$row->saison_end && 
				($_SESSION['selectedDate_saison']>=$row->saison_start && $_SESSION['selectedDate_saison']<='1231') 
				|| ($_SESSION['selectedDate_saison']>='0101' && $_SESSION['selectedDate_saison']<=$row->saison_end)
			   ) 
			) {
				echo "<option value='".$row->outlet_id."' ";
				echo ($outlet_id==$row->outlet_id) ? "selected='selected'" : "";
				echo ">".$row->outlet_name."</option>\n";
			}
		}
	echo "</select>\n";
}

function outletList($outlet_id = '1', $disabled = 'enabled',$tablename='outlet_id',$all='OFF'){
	//set dayoff memory for error message
	$mem_dayoff = 1;
	//remember outlet ID
	$mem_outletID = $_SESSION['outletID'];
	
	echo"<select name='".$tablename."' id='".$tablename."' class='drop' title=' ' size='1' $disabled>\n";
			
		$outlets = querySQL('db_outlets');

		//echo "<option value='0' selected='selected'> -- </option>\n";
		
		foreach($outlets as $row) {
		 if ( ($row->saison_start<=$row->saison_end 
			 && $_SESSION['selectedDate_saison']>=$row->saison_start 
			 && $_SESSION['selectedDate_saison']<=$row->saison_end)
			) {
				$_SESSION['outletID'] = $row->outlet_id;
				
				// get day off days
				$dayoff = getDayoff();
				
				//set dayoff memory for error message
				if ($mem_dayoff==1) {
					$mem_dayoff = ( $dayoff==0 ) ? 0 : 1;
				}
				
				echo "<option value='".$row->outlet_id."' ";
				echo ($outlet_id==$row->outlet_id ) ? "selected='selected'" : "";
				if ($dayoff > 0 && $all == 'OFF') {
					echo "disabled='disabled'";
				}else if($outlet_id==$row->outlet_id ){
					echo "selected='selected'";
				}				
				echo ">".$row->outlet_name."</option>\n";
				
			}
		}
	echo "</select>\n";
	
	//set back remembered outlet ID
	$_SESSION['outletID'] = $mem_outletID;
	return $mem_dayoff;
}

function outletListweb($outlet_id = '1', $disabled = 'enabled',$tablename='outlet_id'){
	//set dayoff memory for error message
	$mem_dayoff = 1;
	//remember outlet ID
	$mem_outletID = $_SESSION['outletID'];
	
	echo"<select name='".$tablename."' id='".$tablename."' title=' ' size='1' $disabled>\n";
			
		$outlets = querySQL('db_outlets_web');

		//echo "<option value='0' selected='selected'> -- </option>\n";
		
		foreach($outlets as $row) {
		 if ( ($row->saison_start<=$row->saison_end 
			 && $_SESSION['selectedDate_saison']>=$row->saison_start 
			 && $_SESSION['selectedDate_saison']<=$row->saison_end)
			) {
				$_SESSION['outletID'] = $row->outlet_id;
				
				// get day off days
				$dayoff = getDayoff();
				
				//set dayoff memory for error message
				if ($mem_dayoff==1) {
					$mem_dayoff = ( $dayoff==0 ) ? 0 : 1;
				}
				
				 echo "<option value='".$row->outlet_id."' ";
				 echo ($outlet_id==$row->outlet_id && $dayoff==0) ? "selected='selected'" : "";
					// uncomment to have the day off outlets greyed out
					echo ($dayoff > 0) ? "disabled='disabled'" : "";
				 echo ">".$row->outlet_name."</option>\n";
				 // echo ">".$dayoff." - ".$row->outlet_name."</option>\n";
				
			}
		}
	echo "</select>\n";
	
	//set back remembered outlet ID
	$_SESSION['outletID'] = $mem_outletID;
	return $mem_dayoff;
}

function getLangList($langTrans, $set, $disabled = 'enabled'){
	echo"<select name='language' id='language' size='1' $disabled>\n";
		
		foreach($langTrans as $key => $value) {
				echo "<option value='".$key."' ";
				echo ($key==$set) ? "selected='selected'" : "";
				echo ">".$value."</option>\n";
		}
	echo "</select>\n";
}

// print select list with status of reservation
// $pending: true for a reservation that is still an unconfirmed large-party request
// (reservation_approval='pending', see web/classes/approval.class.php) - shown as "Unbestätigt"
// in place of "Bestätigt" for the same underlying NYA value. Every list also gets a "Storniert"
// action: it is not a real reservation_status (handled specially in web/ajax/modify_status.php),
// picking it hides the reservation like the separate cancel button always did, and - only for a
// still-pending request - sends the guest a decline mail. Reopening a hidden reservation from any
// status option reverses that (see modify_status.php for the exact mail rules)
function getStatusList($id, $title='NYA', $disabled='', $pending=false){

		$status = explode( ",", _statuslist);
		$value	= array('NYA','ARR','STD','PKD','DEP','NSW');

		// icon per status; browsers with customizable selects (Chrome 135+) show it in the list and
		// in the closed field, all others fall back to the plain text of the options
		$icon = array('NYA' => 'st_confirmed', 'ARR' => 'st_arrived', 'STD' => 'st_seated', 'PKD' => 'st_bar', 'DEP' => 'check', 'NSW' => 'st_noshow');

		echo"<select name='status_id' id='stat_".$id."' size='1' class='status_dbox ".($pending ? 'st-PEN' : 'st-'.htmlspecialchars($title))."' $disabled>";
		echo "<button type='button'><selectedcontent></selectedcontent></button>";
		if ($pending) {
			// current state only - not a real status to pick, just shows that this reservation is
			// still awaiting a decision. Picking "Bestätigt" (or any other real status) below is
			// what actually approves it - it must stay a genuinely separate, always-clickable
			// option, never just a relabelled/already-selected NYA (that would never fire a
			// change event, so the approval could only ever be triggered by picking an unrelated
			// arrival status like "Angekommen")
			echo "<option value='PEN' class='st-PEN' selected='selected'>".uiIcon('clock')."<span>"._status_pending."</span></option>\n";
		}
		// loooping...
		for ($i=0; $i < 6; $i++) {
			echo "<option value='".$value[$i]."' class='st-".$value[$i]."' ";
			echo (!$pending && $title==$value[$i]) ? "selected='selected'" : "";
			echo ">".uiIcon($icon[$value[$i]])."<span>".$status[$i]."</span></option>\n";
		}
		echo "<option value='CXL' class='st-CXL' ";
		echo (!$pending && $title=='CXL') ? "selected='selected'" : "";
		echo ">".uiIcon('cross')."<span>"._status_cancel."</span></option>\n";

		echo "</select>\n";
}

// print select list with 'paid by'
function getPaidList($title='',$disabled=''){
		echo"<select name='reservation_bill' id='reservation_bill' size='1' style='top:-8px;' $disabled>\n";
		
		// Empty
		echo "<option value='' ";
		echo ($title=="") ? "selected='selected'" : "";
		echo ">--</option>\n";		
		// MAIL
		echo "<option value='MAIL' ";
		echo ($title=='MAIL') ? "selected='selected'" : "";
		echo ">"._mail."</option>\n";
		// ROOM
		echo "<option value='ROOM' ";
		echo ($title=='ROOM') ? "selected='selected'" : "";
		echo ">"._room."</option>\n";
		
		echo"</select>\n";
}

// calculate and print select list with intervall times
function getDurationList($intervall,$field='',$selected='1:00') 
{ 
		$hours = array(0, 1, 2, 3, 4, 5, 6);
		if ($intervall=='30') {
			$minutes = array("00", 30);
		}else{
			$minutes = array("00", 15, 30, 45);	
		}
		
		$out = "<select name='$field' id='$field'>\n";
		foreach($hours as $hour){
			foreach($minutes as $minute){
				$duration = $hour.":".$minute;
				$out .= "<option value='".$duration."'";
				if ($duration==$selected) {
					$out .= " selected='selected' ";
				}
				$out .= ">".$duration." h</option>\n";
			}
		}
		$out .= "</select>\n";
		
		echo $out;
}

// build on/off checkbox
function printOnOff($field='',$name='',$status='disabled'){
	if ($field == 1) {
		return "<input type='checkbox' name='$name' id='$name' value='1' checked='checked' $status/>";
	}else{
		return "<input type='checkbox' name='$name' id='$name' value='1' $status/>";
	}
}

// build checkboxes to select weekdays
function getWeekdays_select($outlet_closeday, $status=''){
	$outlet_closeday=explode(",",$outlet_closeday);
	$day = strtotime("next Monday");
	for ($i=1; $i <= 7; $i++) { 
		echo"<input type='checkbox' name='outlet_closeday_".$i."' value='".date("w",$day)."' ";
		
		if (in_array(date("w",$day), $outlet_closeday)) {
			echo "checked='checked'";
		}
		echo $status." >&nbsp;".date("D",$day)."&nbsp;&nbsp;";
		$day = $day + 86400;
	}
}

// build checkboxes to select maitre dayoff
// build checkboxes to select maitre dayoff
function getDayoff_select($dayoff,$id,$cando){
		echo "<input type='checkbox' id='outlet_child_dayoff' name='".$id."' value='";
		echo ($dayoff == 'ON') ? 'ON' : 'OFF';
		echo "' ";
		if ($dayoff == 1) {
			echo "checked='checked'";
		}
		echo " ".$cando."='".$cando."'><label> "._day_off."</label>";
}

// build checkboxes to select maitre dayoff
function showReservation_status($status){
		switch ($status) {
			case '0':
				return strtoupper(_active);
			break;
			case '1':
				return strtoupper(_cancelled);
			break;
		}
		
}

// build dropdown with year
function yearDropdown($name='year', $selected=0, $start_year = FALSE, $end_year = FALSE) {
	
    // Some setup of start and end years
    $start_year = ($start_year) ? $start_year - 1 : date('Y') - 5;
    $end_year = ($end_year) ? $end_year : date('Y') + 5;

    // the current year
	//$selected = $selected==0 ? date('Y', time()) : $selected;

	// Generate the select
    $dd = '<select name="'.$name.'" id="'.$name.'">';
	
	$dd .= '<option value="0" ';
	if ($selected == 0)
    {
            $dd .= 'selected="selected" ';
    }
	
	$dd .= '>&infin;</option>';

    for ($i = $end_year; $i > $start_year; $i -= 1) {
	
        $dd .= '<option value="'.$i.'" ';
		if ($i == $selected)
        {
                $dd .= 'selected="selected" ';
        }
		$dd .= '>'.$i.'</option>';
    }
    $dd .= '</select>';
    return $dd;
}

// build dropdown with month
function monthDropdown($name='month', $selected=null){
        
		$dd = '<select name="'.$name.'" id="'.$name.'">';
        /*** the current month ***/
        $selected = is_null($selected) ? date('n', time()) : $selected;

        for ($i = 1; $i <= 12; $i++)
        {
                $ii = (strlen($i)==1) ? "0".$i : $i;
				$dd .= '<option value="'.$ii.'"';
                if ($i == $selected)
                {
                        $dd .= ' selected="selected"';
                }
                /*** get the month ***/
                $mon = date("F", mktime(0, 0, 0, $i+1, 0, 0));
                $dd .= '>'.$mon.'</option>';
        }
        $dd .= '</select>';
        return $dd;
}

// build dropdown with days
function dayDropdown($name='day', $selected=null) {
        
		$dd = '<select name="'.$name.'" id="'.$name.'">';
        /*** the current month ***/
        $selected = is_null($selected) ? date('d', time()) : $selected;

        for ($i = 1; $i <= 31; $i++)
        {
                $ii = (strlen($i)==1) ? "0".$i : $i;                
				$dd .= '<option value="'.$ii.'"';
                if ($i == $selected)
                {
                        $dd .= ' selected="selected"';
                }
                /*** get the month ***/
                $dd .= '>'.$ii.'</option>';
        }
        $dd .= '</select>';
        return $dd;
}

function redeclare_access() {
echo"<br/><div class='alert_info' style='cursor:pointer;'>
<p><span class='bold'> &#70;&#111;&#108;&#108;&#111;&#119;&#32;&#116;&#104;&#101;&#32;&#119;&#104;&#105;&#116;&#101;&#32;&#114;&#97;&#98;&#98;&#105;&#116;&#46;&#32;&#75;&#110;&#111;&#99;&#107;,&#32;&#107;&#110;&#111;&#99;&#107;&#32;&#46;&#46;&#46;&#32;
</span></p></div><br/>";
}

/*
 * Shift of a reservation time: 'evening' from $daylight_evening on (config: 16:00) and for the
 * hours after midnight of an outlet that closes after midnight, 'sun' from $daylight_noon
 * (12:00) until then, 'morning' before that. Used for the icons, the colour marker and the
 * noon / evening numbers of the dashboard.
 */
function daytimeKind($time, $outlet_id = 0, $date = '') {
	global $daylight_noon, $daylight_evening;
	$mins = function ($t) { $p = explode(':', (string)$t); return ((int)$p[0]) * 60 + (isset($p[1]) ? (int)$p[1] : 0); };
	$m = $mins($time);
	if ($m >= $mins($daylight_evening)) { return 'evening'; }
	if ($m >= $mins($daylight_noon)) { return 'sun'; }
	// before noon: after midnight when the outlet closes after midnight and the time is not past closing
	if ($outlet_id && $date && function_exists('tp_day_hours')) {
		list($open, $close) = tp_day_hours($outlet_id, $date);
		if ($close <= $open && $m <= $close) { return 'evening'; }
	}
	return 'morning';
}

// guests of a day split into noon (sun) and evening (moon) shift: array(noon, evening)
function daytimeSums($outlet_id, $date) {
	global $dbTables;
	$noon = 0; $evening = 0;
	$result = query("SELECT reservation_time, reservation_pax FROM `$dbTables->reservations`
					WHERE `reservation_wait` = 0 AND `reservation_hidden` = 0
					AND `reservation_outlet_id` = '%d' AND `reservation_date` = '%s'", $outlet_id, $date);
	$rows = getRowList($result);
	if ($rows) {
		foreach ($rows as $r) {
			if (daytimeKind($r->reservation_time, $outlet_id, $date) == 'evening') { $evening += (int)$r->reservation_pax; }
			else { $noon += (int)$r->reservation_pax; }
		}
	}
	return array($noon, $evening);
}

// is this a plausible phone number? (empty is fine - the field is optional)
// digits, spaces, + ( ) - / . allowed, 6 to 15 digits, a "+" only at the start
function validPhone($value) {
	$v = trim((string)$value);
	if ($v === '') { return true; }
	if (!preg_match('/^\+?[0-9\s().\/\-]+$/', $v)) { return false; }
	$digits = preg_replace('/\D/', '', $v);
	return strlen($digits) >= 6 && strlen($digits) <= 15;
}

// texts of the reservation form (German / English, every other language falls back to English)
function rt($key, $arg = null) {
	static $tr = null;
	if ($tr === null) {
		$tr = array(
			'de' => array(
				'date' => 'Datum', 'time' => 'Zeit', 'pax' => 'Personen', 'name' => 'Name', 'phone' => 'Telefon',
				'phone_bad' => 'Bitte eine gültige Telefonnummer eingeben (z. B. +49 151 2345678 oder 0512 1234567).',
				'phone_hint' => 'optional, wird auf Gültigkeit geprüft',
				'email_bad' => 'Bitte eine gültige E-Mail-Adresse eingeben.',
				'email_confirm' => 'Bestätigung per E-Mail senden',
				'email_need' => 'Für die Bestätigung bitte eine E-Mail-Adresse eintragen.',
				'note' => 'Notiz', 'tables' => 'Tisch',
				'tables_auto' => 'Ohne Auswahl wird automatisch ein passender Tisch zugewiesen.',
				'tables_suggest' => 'Vorschlag',
				'tables_none' => 'Im Tischplan sind noch keine Tische angelegt.',
				'tables_pick_time' => 'Bitte zuerst eine Uhrzeit wählen.',
				'tables_loading' => 'Lade Tische …',
				'all_areas' => 'Alle Bereiche', 'free' => 'Verfügbar', 'all' => 'Alle',
				'fit_ok' => 'Die Auswahl passt für die Gruppe.',
				'fit_seats' => '%d Plätze für die Gruppe – bitte mehr Tische wählen oder bewusst überbuchen.',
				'fit_busy' => 'Achtung: mindestens ein gewählter Tisch ist zu dieser Zeit schon vergeben.',
				'closed_area' => 'Bereich an diesem Tag gesperrt',
				'busy_by' => 'belegt',
				'details' => 'Details', 'title' => 'Anrede', 'email' => 'E-Mail', 'staff' => 'Mitarbeiter',
				'series' => 'Serienreservierung', 'series_until' => 'Wiederholen bis',
				'daily' => 'täglich', 'weekly' => 'wöchentlich',
				'need_time' => 'Bitte eine Uhrzeit wählen.', 'need_name' => 'Bitte den Namen des Gastes eintragen.',
				'need_pax' => 'Bitte die Personenzahl eintragen.', 'need_staff' => 'Bitte den Mitarbeiter eintragen.',
				'save' => 'Speichern', 'more' => 'Mehr Gäste', 'less' => 'Weniger Gäste',
			),
			'en' => array(
				'date' => 'Date', 'time' => 'Time', 'pax' => 'Guests', 'name' => 'Name', 'phone' => 'Phone',
				'phone_bad' => 'Please enter a valid phone number (e.g. +49 151 2345678 or 0512 1234567).',
				'phone_hint' => 'optional, checked for validity',
				'email_bad' => 'Please enter a valid email address.',
				'email_confirm' => 'Send confirmation by email',
				'email_need' => 'Please enter an email address for the confirmation.',
				'note' => 'Note', 'tables' => 'Table',
				'tables_auto' => 'Without a selection a suitable table is assigned automatically.',
				'tables_suggest' => 'Suggestion',
				'tables_none' => 'No tables have been created in the table plan yet.',
				'tables_pick_time' => 'Please choose a time first.',
				'tables_loading' => 'Loading tables …',
				'all_areas' => 'All areas', 'free' => 'Available', 'all' => 'All',
				'fit_ok' => 'The selection fits the group.',
				'fit_seats' => '%d seats for the group – please pick more tables or overbook on purpose.',
				'fit_busy' => 'Attention: at least one selected table is already taken at this time.',
				'closed_area' => 'Area closed on this day',
				'busy_by' => 'taken',
				'details' => 'Details', 'title' => 'Title', 'email' => 'Email', 'staff' => 'Staff member',
				'series' => 'Recurring reservation', 'series_until' => 'Repeat until',
				'daily' => 'daily', 'weekly' => 'weekly',
				'need_time' => 'Please choose a time.', 'need_name' => 'Please enter the guest name.',
				'need_pax' => 'Please enter the number of guests.', 'need_staff' => 'Please enter the staff member.',
				'save' => 'Save', 'more' => 'More guests', 'less' => 'Fewer guests',
			),
		);
	}
	$lang = isset($_SESSION['language']) ? substr($_SESSION['language'], 0, 2) : 'de';
	$set = ($lang === 'de') ? $tr['de'] : $tr['en'];
	$text = isset($set[$key]) ? $set[$key] : $key;
	return ($arg !== null) ? sprintf($text, $arg) : $text;
}

/*
 * Crisp vector icons for the backend (replace the old pixel images).
 * uiIcon('pen', array('title' => 'Edit', 'class' => 'help', 'alt' => 'Edit'))
 */
function uiIcon($name, $opt = array()) {
	static $paths = array(
		'table'   => "<path d='M4 9h16v2.5H4zM6.5 11.5V19M17.5 11.5V19'/>",
		'pen'     => "<path d='M4 20l.9-4.1L16.6 4.2a2 2 0 0 1 2.8 2.8L7.7 18.7 4 20zM14.5 6.3l3.2 3.2'/>",
		'cross'   => "<circle cx='12' cy='12' r='9'/><path d='M9 9l6 6M15 9l-6 6'/>",
		'loop'    => "<path d='M17 3.5l3 3-3 3M4 11V9.5a3 3 0 0 1 3-3h13M7 20.5l-3-3 3-3M20 13v1.5a3 3 0 0 1-3 3H4'/>",
		'check'   => "<circle cx='12' cy='12' r='9'/><path d='M8 12.4l2.8 2.8L16 9.6'/>",
		'info'    => "<circle cx='12' cy='12' r='9'/><path d='M12 11v5.5M12 7.7h.01'/>",
		'warning' => "<path d='M12 3.5L21.5 20h-19z'/><path d='M12 10v4.5M12 17.2h.01'/>",
		'error'   => "<circle cx='12' cy='12' r='9'/><path d='M12 7.5V13M12 16.4h.01'/>",
		'mail'    => "<rect x='3' y='5.5' width='18' height='13' rx='2'/><path d='M4 7l8 6 8-6'/>",
		'mail_no' => "<rect x='3' y='5.5' width='18' height='13' rx='2'/><path d='M4 7l8 6 8-6M4 20L20 4'/>",
		'user'    => "<circle cx='12' cy='8' r='3.5'/><path d='M5 20c.6-3.6 3.4-5.5 7-5.5s6.4 1.9 7 5.5'/>",
		'logout'  => "<path d='M12 3v8M7.2 6.2a8 8 0 1 0 9.6 0'/>",
		'cutlery' => "<path d='M7 3v7a2 2 0 0 0 4 0V3M9 3v18M17 21V3c-2 1.5-3 4-3 7v3h3'/>",
		'chevron' => "<path d='M9 5l7 7-7 7'/>",
		'bars'    => "<path d='M5 20v-9M12 20V4M19 20v-6'/>",
		'cal_week'  => "<rect x='3.5' y='5' width='17' height='15' rx='2'/><path d='M3.5 10h17M8 3v4M16 3v4M7.5 14h9'/>",
		'cal_month' => "<rect x='3.5' y='5' width='17' height='15' rx='2'/><path d='M3.5 10h17M8 3v4M16 3v4M7.5 13.5h.01M12 13.5h.01M16.5 13.5h.01M7.5 17h.01M12 17h.01M16.5 17h.01'/>",
		'play'    => "<path d='M8 5.5v13l10-6.5z'/>",
		'pause'   => "<path d='M9 5.5v13M15 5.5v13'/>",
		'box_on'  => "<rect x='4' y='4' width='16' height='16' rx='3'/><path d='M8.2 12.3l2.6 2.6 5-5.4'/>",
		'box_off' => "<rect x='4' y='4' width='16' height='16' rx='3'/>",
		'clock'   => "<circle cx='12' cy='12' r='9'/><path d='M12 7v5.2l3.4 2'/>",
		// reservation status
		'st_confirmed' => "<rect x='3.5' y='5' width='17' height='15' rx='2'/><path d='M3.5 10h17M8 3v4M16 3v4M9 14.6l2.2 2.2 3.9-4.3'/>",
		'st_arrived'   => "<path d='M12 21s7-6.2 7-11.5a7 7 0 1 0-14 0C5 14.8 12 21 12 21z'/><path d='M9 9.8l2.2 2.2 3.8-4'/>",
		'st_seated'    => "<circle cx='8' cy='5.5' r='2'/><path d='M8 8.2v5h5M13 13.2V19M8 9.8h5.2M15.5 10.5H21M18.2 10.5V19'/>",
		'st_bar'       => "<path d='M5.5 4.5h13L12 12z'/><path d='M12 12v7M8.2 19.2h7.6'/>",
		'st_noshow'    => "<circle cx='12' cy='8' r='3.4' stroke-dasharray='2.2 2.4'/><path d='M5 20c.6-3.6 3.4-5.5 7-5.5s6.4 1.9 7 5.5' stroke-dasharray='2.2 2.4'/>",
	);
	if (!isset($paths[$name])) { return ''; }
	$cls = 'ui-ico ui-ico-'.$name.(!empty($opt['class']) ? ' '.$opt['class'] : '');
	$title = !empty($opt['title']) ? " title='".htmlspecialchars($opt['title'], ENT_QUOTES)."'" : '';
	$alt = !empty($opt['alt']) ? " role='img' aria-label='".htmlspecialchars($opt['alt'], ENT_QUOTES)."'" : '';
	return "<span class='$cls'$title$alt><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>".$paths[$name]."</svg></span>";
}

// crisp vector icon for noon ('sun') / evening ('moon') numbers in the dashboard
function daytimeIcon($kind) {
	$common = "class='dt-icon' viewBox='0 0 24 24' width='16' height='16' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'";
	if ($kind == 'moon') {
		return "<svg $common><path d='M20 14.2A8.2 8.2 0 0 1 9.8 4a8.2 8.2 0 1 0 10.2 10.2Z'/></svg>";
	}
	if ($kind == 'clock') {
		return "<svg $common><circle cx='12' cy='12' r='9'/><path d='M12 7v5.2l3.4 2'/></svg>";
	}
	return "<svg $common><circle cx='12' cy='12' r='4'/><path d='M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6'/></svg>";
}

// Whether current user has capability or role.
function current_user_can( $capability ) {
	$_SESSION['capability'] = $capability;
	
	if ( empty( $_SESSION['role'] ) )
		return false;

	$allow = querySQL('capability');

	return $allow;
}

// Creates a random password / id
function randomPassword($pw_length = 6, $use_caps = false, $use_numeric = true, $use_specials = false) {
	$caps = array();
	$numbers = array();
	$num_specials = 0;
	$reg_length = $pw_length;
	$pws = array();
	$pwn = array();
	$rs_keys = array();
	for ($ch = 97; $ch <= 122; $ch++) $chars[] = $ch; // create a-z
	if ($use_caps) for ($ca = 65; $ca <= 90; $ca++) $caps[] = $ca; // create A-Z
	if ($use_numeric) for ($nu = 49; $nu <= 57; $nu++) $numbers[] = $nu; // create 1-9
	if ($use_specials) $signs = array(33,35,36,37,38,42,43,45,46); // create signs
	$all = array_merge($chars, $caps);
	if ($use_numeric) {
		$reg_length =  ceil($pw_length*0.75);
		$num_numeric = $pw_length - $reg_length;
		if ($num_numeric > 5) $num_numeric = 5;
		if ($num_numeric < 2) $num_numeric = 2;
		$rs_keys = array_rand($numbers, $num_numeric);
		foreach ($rs_keys as $rs) {
			$pwn[] = chr($numbers[$rs]);
		}
	}
	if ($use_specials) {
		$reg_length =  ceil($pw_length*0.75);
		$num_specials = $pw_length - $reg_length;
		if ($num_specials > 5) $num_specials = 5;
		if ($num_specials < 2) $num_specials = 2;
		$rs_keys = array_rand($signs, $num_specials);
		foreach ($rs_keys as $rs) {
			$pws[] = chr($signs[$rs]);
		}
	}
	$reg_length = $pw_length - $num_numeric - $num_specials;

	$rand_keys = array_rand($all, $reg_length);
	foreach ($rand_keys as $rand) {
		$pw[] = chr($all[$rand]);
	}	

	$compl = array_merge($pw, $pwn, $pws);
	shuffle($compl);

	return implode('', $compl);
}

// compare a random password with the database to create a unique booking number
function uniqueBookingnumber(){
	do {
		$_SESSION['PWD'] = randomPassword();
		$num = querySQL('check_unique_id');
	} while( $num>=1 );
	return 	$_SESSION['PWD'];
}

function randomString()
{
  $length = 8;

  //string of all possible characters to go into the new password
  $passwordRandomString = "AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz0123456789";
  
  //initialize the new password string
  $newPW = "";
  
  //seed the random function
  srand();
  
  //go through to generate a random password.
  for($x=0; $x < $length; $x++)
  {
    $newPW .= substr($passwordRandomString,rand(0,62),1);
  }
  
  return $newPW;
}

// =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
// =-=               THE CORE              =-=
// =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=

// calculate the maximum capacity of outlet
function maxCapacity(){
	$capacity =	querySQL('maxcapacity');
		
	$_SESSION['outlet_max_capacity'] = $capacity['outlet_max_capacity'];
	$_SESSION['outlet_max_tables']   = $capacity['outlet_max_tables'];	
	$_SESSION['passerby_max_pax']    = $capacity['passerby_max_pax'];
	
	$_SESSION['outlet_max_capacity'] += (isset($capacity['outlet_child_capacity'])       ? $capacity['outlet_child_capacity']       : 0);
	$_SESSION['outlet_max_tables']   += (isset($capacity['outlet_child_tables'])         ? $capacity['outlet_child_tables']         : 0);
	$_SESSION['passerby_max_pax']    += (isset($capacity['outlet_child_passer_max_pax']) ? $capacity['outlet_child_passer_max_pax'] : 0);
	return TRUE;
}

// get reservations of day/outlet, grouped by time
// $kind = 'pax' (persons) or 'tbl' (tables) or 'pass' (passerby)
function reservationsByTime($kind='pax') {

	$availability_by_time = array();
	
	//return values
	if( $kind=='pax' ){
		$availability =	querySQL('availability');
		$pax_by_time = array();
		if ($availability) {
			foreach($availability as $row) {
				$pax_by_time[$row->reservation_time] = $row->pax_total;
				$tbl_by_time[$row->reservation_time] = $row->tbl_total;
			}
		}
	    return $pax_by_time;
	}else if( $kind=='tbl' ){
		$availability =	querySQL('availability');
		$tbl_by_time = array();
		if ($availability) {
			foreach($availability as $row) {
				$pax_by_time[$row->reservation_time] = $row->pax_total;
				$tbl_by_time[$row->reservation_time] = $row->tbl_total;
			}
		}
	    return $tbl_by_time;
	}else if( $kind=='pass' ){
		$pass_availability = querySQL('passerby_availability');
		$pass_by_time = array();
		if ($pass_availability) {
			foreach($pass_availability as $row) {
				$pass_by_time[$row->reservation_time] = $row->passerby_total;
			}
		}
		return $pass_by_time;
	}
}

// calculate the timeslot value availability
function getAvailability($ava_by_time, $intervall='15') {	

	//timeline open/close time
	// prevent NULL error
	$open_time = ($_SESSION['selOutlet']['outlet_open_time']!="") ? $_SESSION['selOutlet']['outlet_open_time'] : "00:00:00";
	$close_time = ($_SESSION['selOutlet']['outlet_close_time']!="") ? $_SESSION['selOutlet']['outlet_close_time'] : "23:45:00";
	
	// calculate after midnight
	$day    = date("d");
	$dayshift = ($close_time < $open_time) ? 1 : 0;
	$endday = date("d") + $dayshift;
	list($h1,$m1,$s1)	= explode(":",$open_time);
	list($h2,$m2,$s2)	= explode(":",$close_time);
	$value  			= mktime($h1+0,$m1+0,0,date("m"),$day,date("Y"));
	$opentime 			= $value;
	$endtime		 	= mktime($h2+0,$m2+15,0,date("m"),$endday,date("Y")); //set endtime +15 to prevent error
	$i 					= 1;
	
		//walk through timeslots
		while( $value <= $endtime )
		{ 
			$startvalue = timeDifference(date('H:i:s',$value),$_SESSION['selOutlet']['avg_duration'],'SUB',$dayshift);
			list($h3,$m3) = explode(":",$startvalue);
			$endvalue = timeDifference(date('H:i:s',$value),$_SESSION['selOutlet']['avg_duration'],'ADD',$dayshift);
			list($h4,$m4) = explode(":",$endvalue);
			
			// calculate after midnight
			$endday2 = ($startvalue < $endvalue) ? date("d") : date("d")+1;

			$startvalue = mktime($h3+0,$m3+0,0,date("m"),$day,date("Y"));
			$endvalue = mktime($h4+0,$m4+0,0,date("m"),$endday2,date("Y"));
			$out_ava_temp_before = 0;
			$out_ava_temp_after = 0;
			$ii = 1;

			//count the reservations after the timeslot's time by duration
			while ( $startvalue <= $value) {
				if ($startvalue >= $opentime){
					/* after midnight correction **FALSE**
					if($value-$startvalue > 3600 && $dayshift == 1){
						//$startvalue = $value-3600;
					} */
					$ava_temp = (isset($ava_by_time[date('H:i:s',$startvalue)])) ? $ava_by_time[date('H:i:s',$startvalue)] : 0;
					if (($startvalue >= $opentime)) {
						$out_ava_temp_after += $ava_temp;
					}
					
				}

				$startvalue = mktime($h3+1-1,$m3+$ii*$intervall,0,date("m",$startvalue),date("d",$startvalue),date("Y",$startvalue)); 
				if($startvalue>$endtime){break;}
				$ii++;
			}
			$ii = 1;
			//count the reservations before the timeslot's time by duration
			//DeBUGGING
			//echo "<b>".date('H:i d.m.y',$endvalue)."</b><br>";
			while ( $endvalue > $value) {
				// not bigger than endtime
				$endvalue = ($endvalue > $endtime) ? $endtime : $endvalue;
				$ava_temp = (isset($ava_by_time[date('H:i:s',$endvalue)])) ? $ava_by_time[date('H:i:s',$endvalue)] : 0;
				$out_ava_temp_before += $ava_temp;
				$endvalue = mktime($h4+0,$m4-$ii*$intervall,0,date("m"),$endday2,date("Y")); 
				$ii++;
			}
			
			// ***
			//store the occupancy by time
			// ***
			
			// block before and after reservation time
			$out_availability[date('H:i',$value)] = $out_ava_temp_before + $out_ava_temp_after; 
			//echo "out_availability[".date('H:i',$value)."] = ".$out_ava_temp_before." + ".$out_ava_temp_after."<br/>";
			
			// block after reservation time
			//$out_availability[date('H:i',$value)] = $out_ava_temp_after; 
			
		  $value = mktime($h1+0,$m1+$i*$intervall,0,date("m"),$day,date("Y")); 
		  $i++;
		}
		//DeBUGGING
		//print_r($out_availability);	
		return $out_availability;
}

// *** Define if selected date is dayoff
function getDayoff() {
	$day_off = 0;
	$today = date('w',strtotime($_SESSION['selectedDate']));
	//read infos from database
	$rows = querySQL('outlet_info');
		foreach($rows as $row) {
			$outlet_dayoff = explode (",",$row->outlet_closeday);
		}
	$rows = querySQL('maitre_info');
		foreach($rows as $row) {
			$maitre_dayoff = $row->outlet_child_dayoff;
		}
	// define dayoff or y/n
	if($outlet_dayoff){
		foreach ($outlet_dayoff as $closeday) {
			if ($closeday == $today ){
				$day_off = 1;
			}
		}
	}
	if (isset($maitre_dayoff)) {
		if ($maitre_dayoff == 'ON') {
			$day_off = 1;
		}else if ($maitre_dayoff == 'OFF') {
			$day_off = 0;
		}
	}
	return $day_off;
}

// *** Define availability by selected time (and duration) 
// *** by searching the lowest occupancy through reservation_time/duration 
function leftSpace($reservation_time, $occupancy){
	GLOBAL $general;
	$time = $reservation_time;
	if (substr($reservation_time, 0, 1) == "'") {
		$time = substr($reservation_time, 0, -1);
		$time = substr($time, 1);
	}
	
	//Check availability
	list($h,$m) = explode(":",$time);
	$leftspace = $_SESSION['outlet_max_capacity']-$occupancy[$time];
	$endtime = timeDifference($time,$_SESSION['selOutlet']['avg_duration'],'ADD',0);
	// check if end time is not exceedind outlet close time
	$endtime = ($endtime > $_SESSION['selOutlet']['outlet_close_time']) ? $_SESSION['selOutlet']['outlet_close_time'] : $endtime;

	if ($endtime<$time) {
		$endtime = $time;
	}

	$ii = 1;

	while ( $time <= $endtime ) {
		$space = $_SESSION['outlet_max_capacity'] - $occupancy[$time];
		//store lowest availability of space
		if ($space < $leftspace ){
			$leftspace = $space;
		}
		$time = date('H:i',mktime($h+0,$m+$ii*$general['timeintervall'],0,date("m"),date("d"),date("Y"))); 
		$ii++;
	}
	
         return $leftspace;
	
}

function build_calendar($month,$year,$dateArray) {
	 global $daylight_evening;
	 // get monday
     $weekStartTime = strtotime('Monday this week');
     // get today's date
     $today_date = date("d");
	 $today_date = ltrim($today_date, '0');

     // Create array containing abbreviations of days of week.
     for ($i=0; $i < 7; $i++) { 
     	$daysOfWeek[] = strftime('%a',$weekStartTime+($i*86400));
     }

     // What is the first day of the month in question?
     $firstDayOfMonth = mktime(0,0,0,$month,1,$year);

     // How many days does this month contain?
     $numberDays = date('t',$firstDayOfMonth);

     // Retrieve some information about the first day of the
     // month in question.
     $dateComponents = getdate($firstDayOfMonth);

     // What is the name of the month in question?
     $monthName = $dateComponents['month'];

     // What is the index value (0-6) of the first day of the
     // month in question.
     $dayOfWeek = $dateComponents['wday'];

     // Create the table tag opener and day headers

     $calendar = "<table class='bordered-table'>";
     $calendar .= "<caption><h3>";
     $calendar .= $monthName." ".$year."</h3></caption>";
     $calendar .= "<tr class='grey'>";

     // Create the calendar headers

     foreach($daysOfWeek as $day) {
          $calendar .= "<th>".$day."</th>";
     } 

     // Create the rest of the calendar

     // Initiate the day counter, starting with the 1st.

     $currentDay = 1;

     $calendar .= "</tr><tr>";

     // The variable $dayOfWeek is used to
     // ensure that the calendar
     // display consists of exactly 7 columns.

     if ($dayOfWeek > 0) {
        $calendar .= "<td colspan='".($dayOfWeek-1)."'>&nbsp;</td>";
     }else if ($dayOfWeek == 0) {
     	$calendar .= "<td colspan='6'>&nbsp;</td>";
     }

     $month = str_pad($month, 2, "0", STR_PAD_LEFT);

     while ($currentDay <= $numberDays) {

          // Seventh column (Saturday) reached. Start a new row.
          if ($dayOfWeek == 7) {
               $dayOfWeek = 0;
          }
		  if ($dayOfWeek == 1) {
          	$calendar .= "</tr><tr>";
 		  }

          $currentDayRel = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
          $date = "$year-$month-$currentDayRel";

          // get occupancy from database
          $_SESSION['statistic_week'] = $date;
			
			// noon
			$value	= $daylight_evening;
			$row = querySQL('statistic_week_def_noon');
			$statistic_noon = ($row[0]->paxsum) ? $row[0]->paxsum : 0;
			// evening
			$row = querySQL('statistic_week_def_evening');
			$statistic_evening = ($row[0]->paxsum) ? $row[0]->paxsum : 0;

			$stat_occupancy = ($statistic_noon+$statistic_evening == 0 ) ? '&nbsp;' : uiIcon('user').($statistic_noon+$statistic_evening);

		  if($currentDayRel == $today_date ){
          	$calendar .= "<td rel='$date' class='grey'><small>".$currentDay."</small>";
	      }else{
	      	$calendar .= "<td rel='$date'><small>".$currentDay."</small>";
	      }
          $calendar .= "<strong><a href='main_page.php?p=2&outletID=".$_SESSION['selOutlet']['outlet_id']."&selectedDate=".$_SESSION['statistic_week']."'>";
          $calendar .= "<div class='center'>".$stat_occupancy."</div>";
		  $calendar .= "</a><strong></td>";

          // Increment counters
          $currentDay++;
          $dayOfWeek++;
     }

     // Complete the row of the last week in month, if necessary
     if ($dayOfWeek != 1) { 
          $remainingDays = 8 - $dayOfWeek;
          $calendar .= "<td colspan='".$remainingDays."'>&nbsp;</td>"; 
     }

     $calendar .= "</tr>";
     $calendar .= "</table>";

     return $calendar;

}

// ++++++++++++++++++++++++++++++++++++++++
// ++++++++++++++++++++++++++++++++++++++++
?>