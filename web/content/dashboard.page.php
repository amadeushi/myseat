<!-- Begin one column box -->
<div class='onecolumn'>
 <div class='header'>
	<a href="?selectedDate=<?php echo buildDate($settings['dbdate'],$sd,$sm,$sj,-1); ?>" class="navgroup">
		&laquo;
	</a>
	<div class="date dategroup">
		<div class="text" id="datetext"><?php echo $_SESSION['selectedDate_user']; ?></div>
		<input type="text" id="datepicker"/>
		<input type="hidden" id="dbdate" value="<?php echo $_SESSION['selectedDate']; ?>"/>
	</div>
	<a href="?selectedDate=<?php echo buildDate($settings['dbdate'],$sd,$sm,$sj,1); ?>" class="navgroup">
		&raquo;
	</a>
	<!-- Begin 2nd level tab -->
	<ul class='second_level_tab'>
		<?php
		// block / release online bookings for the selected day (closed party, sold out)
		$ob_can = current_user_can( 'Daily-Outlet-Edit' );
		if ($ob_can) {
			include_once 'classes/online_block.class.php';
			if (empty($_SESSION['ob_token'])) { $_SESSION['ob_token'] = bin2hex(random_bytes(16)); }
			$ob_day = ob_get($_SESSION['outletID'], $_SESSION['selectedDate']);
			echo "<li><a href='#' class='button_dark ob-toggle".($ob_day ? " is-blocked" : "")."' data-outlet='".(int)$_SESSION['outletID']."' data-date='".htmlspecialchars($_SESSION['selectedDate'])."' data-blocked='".($ob_day ? 1 : 0)."'>"
				.($ob_day ? "Online wieder freigeben" : "Online sperren")."</a></li>";
		}
		?>
		<li>
			<a href='?p=2' class='button_dark'> <?php echo _back;?>
			</a>
		<li/>
	</ul>
	<!-- End 2nd level tab -->
 </div>
<div id='content_wrapper'>
		<?php
			// MESSAGE boxes goes here
			include('includes/messagebox.inc.php'); 
		?>
</div>
</div>
<br class='cl' />
			<?php
			// ** print out the overview **
			// memorize actual selected outlet
			$rem_outlet = $_SESSION['outletID'];
			
			echo"<div class='onecolumn'><div class='header'>\n";
			
			switch($q){
				case '3':
					echo"<div class='dategroup_name'>"._dashboard." "._statistics."/"._time."</div>";	
				break;
				case '1':
					echo"<div class='dategroup_name'>"._occupancy_per_week."/"._pax."</div>";	
				break;
				case '2':
					echo"<div class='dategroup_name'>"._occupancy_per_month."/"._pax."</div>";	
				break;
			}
			?>
						<!-- Begin 2nd level tab -->
			<ul class="second_level_tab noprint">
				<li class='disabled'>
					<a href="main_page.php?p=1&q=3">
						<?php echo uiIcon('bars', array('alt' => 'Statistics')); ?>
					</a>
				</li>
				<li>
					<a href="main_page.php?p=1&q=1">
						<?php echo uiIcon('cal_week', array('alt' => 'Week')); ?>
					</a>
				</li>
				<li>
					<a href="main_page.php?p=1&q=2">
						<?php echo uiIcon('cal_month', array('alt' => 'Month')); ?>
					</a>
				</li>
			</ul>
			<!-- End 2nd level tab -->

			</div>
<?php		
			//reset zebra containers
			$c = 0;

			// ** content of pages **
			
			switch($q){
				case '3':
					include('includes/dash_sparkline.inc.php');	
				break;
				case '1':
					include('includes/dash_week.inc.php');	
				break;
				case '2':
					include('includes/dash_month.inc.php');		
				break;
			}
			
			echo "</div><br class='clear' />";

			// memorize actual selected outlet
			$_SESSION['outletID'] = $rem_outlet;
			// memorize selected outlet details
			$rows = querySQL('db_outlet_info');
			if($rows){
				foreach ($rows as $key => $value) {
					$_SESSION['selOutlet'][$key] = $value;
				}
			}
			
			// ** print out all reservations **
			echo"<div class='onecolumn'><div class='header'>\n";
			echo"<div class='dategroup_name'>".$_SESSION['selectedDate_user'].", "._confirmed_reservations."</div>
			</div>\n
			<div class='content'>\n";
			
			// no waitlist
			$_SESSION['wait'] = 0;
			include('includes/reservations_grid.inc.php');
			
			echo"\n<br class='cl' /><br/>\n</div></div><br/>";
			
			?>

<br class="clear"/><br/>
<?php if (!empty($ob_can)): ?>
<script type="text/javascript">
(function () {
	var token = <?php echo json_encode($_SESSION['ob_token']); ?>;
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('.ob-toggle') : null;
		if (!a) { return; }
		e.preventDefault();
		var blocked = a.getAttribute('data-blocked') === '1';
		var reason = '';
		if (!blocked) {
			reason = window.prompt('Online-Reservierungen für ' + a.getAttribute('data-date').split('-').reverse().join('.') + ' sperren.\nGrund (optional, nur intern), z. B. Geschlossene Gesellschaft:', '');
			if (reason === null) { return; }
		}
		fetch('ajax/online_block.php', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-OB-Token': token },
			body: JSON.stringify({ outlet_id: a.getAttribute('data-outlet'), date: a.getAttribute('data-date'), blocked: !blocked, reason: reason })
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.ok) { window.alert(j.error || 'Speichern fehlgeschlagen'); return; }
			window.location.reload();
		}, function () { window.alert('Server nicht erreichbar'); });
	});
})();
</script>
<?php endif; ?>