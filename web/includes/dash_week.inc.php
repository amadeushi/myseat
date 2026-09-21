<?php
echo "<div class='center width-70'><br/> <table class='bordered-table'>";
$outlets = querySQL('db_outlets');
$done = 0;
foreach($outlets as $row) {
 if ( ($row->saison_start<=$row->saison_end 
	 && $_SESSION['selectedDate_saison']>=$row->saison_start 
	 && $_SESSION['selectedDate_saison']<=$row->saison_end)
	){
		// outlet ID
		$_SESSION['outletID'] = $row->outlet_id;
		// outlet settings
		$rows = querySQL('db_outlet_info');
		if($rows){
			foreach ($rows as $key => $value) {
				$_SESSION['selOutlet'][$key] = $value;
			}
		}

		//prepare selected Date
		list($sy,$sm,$sd) = explode("-",$_SESSION['selectedDate']);
		if ($done != 1) {
			echo "<thead class='grey'><tr><th></th>";
			for ($i=0; $i < 7; $i++) { 
					$labeldate=strftime('%a, %d.%m.',mktime(0,0,0,$sm,$sd+$i,$sy));
					echo "<th>".$labeldate."</th>";
			}
			echo "</tr></thead><tbody>";
			$done = 1;
		}
		echo "<tr>";
		echo "<td>".$_SESSION['selOutlet']['outlet_name']."</td>";
		$i=0;
		include_once 'classes/online_block.class.php';
		$ob_week = ob_blocked_dates($_SESSION['selOutlet']['outlet_id'], date('Y-m-d',mktime(0,0,0,$sm,$sd,$sy)), date('Y-m-d',mktime(0,0,0,$sm,$sd+6,$sy)));
		while ($i<=6){
			// week day date
			$_SESSION['statistic_week'] = date('Y-m-d',mktime(0,0,0,$sm,$sd+$i,$sy));
			
			// noon
			$value	= $daylight_evening;
			$row = querySQL('statistic_week_def_noon');
			$statistic_noon = ($row[0]->paxsum) ? $row[0]->paxsum : 0;
			// evening
			$row = querySQL('statistic_week_def_evening');
			$statistic_evening = ($row[0]->paxsum) ? $row[0]->paxsum : 0;

		  echo"<td><strong><a href='main_page.php?p=2&outletID=".$_SESSION['selOutlet']['outlet_id']."&selectedDate=".$_SESSION['statistic_week']."'>";
		  if ( $statistic_noon == 0 && $statistic_evening == 0 ){
			echo "&nbsp;</a></strong>";
		}else{
		  	echo "<img src='images/icons/clock-sun.png' style='height:10px' class='middle'/>".$statistic_noon;
		  	echo "<img src='images/icons/clock-moon.png' style='height:10px' class='middle'/>".$statistic_evening."</a></strong>";
		  }
		  // online booking block of the day (closed party, sold out) with a toggle for staff
		  $ob_week_blocked = isset($ob_week[$_SESSION['statistic_week']]);
		  if ($ob_week_blocked) {
			$ob_text = "Online gesperrt".($ob_week[$_SESSION['statistic_week']] !== '' ? ": ".htmlspecialchars($ob_week[$_SESSION['statistic_week']], ENT_QUOTES) : "");
			echo "<div class='ob-cell' title='".$ob_text."'>".$ob_text."</div>";
		  }
		  if (!empty($ob_can)) {
			echo "<a href='#' class='ob-toggle ob-mini' data-outlet='".(int)$_SESSION['selOutlet']['outlet_id']."' data-date='".$_SESSION['statistic_week']."' data-blocked='".($ob_week_blocked ? 1 : 0)."'>".($ob_week_blocked ? "freigeben" : "online sperren")."</a>";
		  }
		  echo "</td>";
		  $i++;
		}
		echo "</tr>";
		if ($done != 1) {
			echo "</tbody>";
		}

	}
}
echo"</table><br/></div>";
?>