<?php
/*
 * Renders one reservation as a <tr> (echoes it). Shared by reservations_table.inc.php (the full day
 * view) and web/ajax/reservations_new_rows.php (only the row(s) that just arrived, for the live "new
 * reservation" insert) - one place for this markup, never two versions drifting apart. Expects the
 * same context reservations_table.inc.php already needs: $_SESSION (page, wait, outletID,
 * selectedDate, outlet_max_tables), $q, $maitre, $general, $availability, $tbl_availability,
 * $resHighlightToday.
 */
function render_reservation_row_tr($row) {
	global $maitre, $general, $availability, $tbl_availability, $q, $resHighlightToday;

	$id = $row->reservation_id;
	$_SESSION['reservation_guest_name'] = $row->reservation_guest_name;
	// check if reservation is tautologous
	$tautologous = querySQL('tautologous');
	// arrived less than an hour ago: highlight it
	$resCreatedTs = strtotime($row->reservation_timestamp);
	$resIsNew = $resHighlightToday && $resCreatedTs && (time() - $resCreatedTs) >= 0 && (time() - $resCreatedTs) < 3600;

	echo "<tr id='res-".$id."'".($resIsNew ? " class='res-new' data-created='".(int)$resCreatedTs."'" : "").">";
	echo "<td";
	// daylight coloring
	$shift = daytimeKind($row->reservation_time, $_SESSION['outletID'], $_SESSION['selectedDate']);
	if ($shift == 'evening'){
		echo " class='evening noprint'";
	}else if ($shift == 'sun'){
		echo " class='afternoon noprint'";
	}else{
		echo " class='morning noprint'";
	}

	echo " style='width:10px !important; padding:0px;'>&nbsp;</td>";
	echo "<td id='tb_time'";
	// reservation after maitre message
	if ($row->reservation_timestamp > $maitre['maitre_timestamp'] && $maitre['maitre_comment_day']!='') {
		echo " class='tautologous' title='"._sentence_13."' ";
	}
	echo ">";
	echo "<strong>".formatTime($row->reservation_time,$general['timeformat'])."</strong></td>";
	echo "<td id='tb_pax' class='big'>".tp_pax_cell($id, $row->reservation_pax)."</td><td id='tb_name'>";
	$sal = printTitle($row->reservation_title);
	if ($sal !== '') { echo "<span class='noprint'>".$sal." </span>"; }
	echo "<strong><a id='detlbuttontrigger' href='ajax/guest_detail.php?id=".$id."'";
	// color guest name if tautologous
	if($tautologous>1){echo" class='tautologous tipsy' title='"._tautologous_booking."'";}
	echo ">".$row->reservation_guest_name."</a></strong>";

	// old reservations symbol
	if( (strtotime($row->reservation_timestamp) + $general['old_days']*86400) <= time() ){
		echo uiIcon('clock', array('class' => 'help tipsyold dt-old', 'title' => _sentence_11, 'alt' => _sentence_11));
	}
	// recurring symbol
	if ($row->repeat_id !=0) {
        echo "&nbsp;".uiIcon('loop', array('class' => 'tipsy', 'title' => _recurring, 'alt' => _recurring));
    }

	echo"</td><td id='tb_note'>";
		if ($_SESSION['page'] == 1) {
	 		echo $row->outlet_name;
	 	}else{
			echo $row->reservation_notes;
		}
	echo "</td>";
	if($_SESSION['wait'] == 0){
		echo "<td class='big tb_nr' id='tb_table'>".uiIcon('table', array('class' => 'tipsy leftside noprint', 'title' => _table, 'alt' => _table)).tp_table_cell($id, $row->reservation_table, $_SESSION['selectedDate'])."</td>";
	}
	echo "<td class='noprint'><div>";
		getStatusList($id, $row->reservation_status, '', isset($row->reservation_approval) && $row->reservation_approval === 'pending');
	echo "</div></td>";
	echo "<td class='noprint'>";
	echo "<small>".$row->reservation_booker_name." | ".humanize($row->reservation_timestamp)."</small>";
	echo "</td>";
	echo "<td class='noprint'>";
	// MOVE BUTTON
	//	echo "<a href=''><img src='images/icons/arrow.png' alt='move' class='help' title='"._move_reservation_to."'/></a>";

	// WAITLIST ALLOW BUTTON
	if($_SESSION['wait'] == 1){
		$leftspace = leftSpace(substr($row->reservation_time,0,5), $availability);
		if($leftspace >= $row->reservation_pax && $_SESSION['outlet_max_tables']-$tbl_availability[substr($row->reservation_time,0,5)] >= 1){
			echo"&nbsp;<a href='#' name='".$id."' class='alwbtn'>".uiIcon('check', array('class' => 'help', 'title' => _allow, 'alt' => _allow))."</a>&nbsp;&nbsp;";
		}
	}
	// TABLE SIGN on the receipt printer
	if ( current_user_can('Reservation-Edit') && $q!=3 ){
		echo "<a href='#' class='resbon' data-url='reservation_bon.php?id=".$id."'>".uiIcon('print', array('class' => 'help', 'title' => 'Reservierungsschild drucken', 'alt' => 'Drucken'))."</a>&nbsp;&nbsp;";
	}
	// EDIT/DETAIL BUTTON
	echo "<a href='?p=102&resID=".$id."'>".uiIcon('pen', array('class' => 'help', 'title' => _detail, 'alt' => _detail))."</a>&nbsp;&nbsp;";
	// DELETE BUTTON
	if ( current_user_can( 'Reservation-Delete' ) && $q!=3 ){
    	echo"<a href='#modalsecurity' name='".$row->repeat_id."' id='".$id."' class='delbtn'>
			".uiIcon('cross', array('class' => 'help', 'title' => _delete, 'alt' => _cancelled))."</a>";
	}
	echo"</td></tr>";
}
