<!-- Begin one column box -->
<div class='onecolumn'>
 <div class='header'>
	<?php $de_lang = (substr($_SESSION['language'],0,2) == 'de'); ?>
	<div class="date-nav">
	<a href="?p=1&selectedDate=<?php echo buildDate($settings['dbdate'],$sd,$sm,$sj,-1); ?>" class="navgroup navgroup-prev" aria-label="<?php echo $de_lang ? 'Vorheriger Tag' : 'Previous day'; ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><polyline points="15 5, 8 12, 15 19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
	<div class="date dategroup">
		<div class="text" id="datetext"><?php echo $_SESSION['selectedDate_user']; ?></div>
		<input type="text" id="datepicker"/>
		<input type="hidden" id="dbdate" value="<?php echo $_SESSION['selectedDate']; ?>"/>
	</div>
	<a href="?p=1&selectedDate=<?php echo buildDate($settings['dbdate'],$sd,$sm,$sj,1); ?>" class="navgroup navgroup-next" aria-label="<?php echo $de_lang ? 'Nächster Tag' : 'Next day'; ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><polyline points="9 5, 16 12, 9 19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
	<?php if ($_SESSION['selectedDate'] != date('Y-m-d')): ?>
	<a href="?p=1&selectedDate=<?php echo date('Y-m-d'); ?>" class="today-btn"><?php echo _today; ?></a>
	<?php endif; ?>
	</div>
	<!-- Begin 2nd level tab -->
	<ul class='second_level_tab'>
		<?php
		// block / release online bookings for the selected day (closed party, sold out)
		$ob_can = current_user_can( 'Daily-Outlet-Edit' );
		if ($ob_can) {
			include_once 'classes/online_block.class.php';
			if (empty($_SESSION['ob_token'])) { $_SESSION['ob_token'] = bin2hex(random_bytes(16)); }
			$ob_day = ob_get($_SESSION['outletID'], $_SESSION['selectedDate']);
			echo "<li><a href='#' class='ob-toggle".($ob_day ? " is-blocked" : "")."' data-outlet='".(int)$_SESSION['outletID']."' data-date='".htmlspecialchars($_SESSION['selectedDate'])."' data-blocked='".($ob_day ? 1 : 0)."'>"
				.($ob_day ? _online_unblock : _online_block)."</a></li>";
		}
		?>
		<li>
			<a href='?p=2' class='button_dark'> <?php echo _back;?>
			</a>
		<li/>
	</ul>
	<?php if ($ob_can && !$ob_day): ?>
	<div class='ob-reason-panel' id='ob-reason-panel' hidden>
		<label for='ob-reason-input'><?php echo _online_block_reason_label; ?></label>
		<input type='text' id='ob-reason-input' maxlength='80' placeholder='<?php echo htmlspecialchars(_online_block_reason_placeholder, ENT_QUOTES); ?>'/>
		<div class='ob-reason-actions'>
			<button type='button' class='button_dark' id='ob-reason-save'><?php echo _save; ?></button>
			<button type='button' class='ob-reason-cancel' id='ob-reason-cancel'><?php echo _cancel; ?></button>
		</div>
	</div>
	<?php endif; ?>
	<div id='ob-feedback'></div>
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
				<li<?php echo ($q=='3') ? " class='active'" : ""; ?>>
					<a href="main_page.php?p=1&q=3">
						<?php echo uiIcon('bars', array('title' => $de_lang ? 'Statistik' : 'Statistics', 'alt' => $de_lang ? 'Statistik' : 'Statistics')); ?>
					</a>
				</li>
				<li<?php echo ($q=='1') ? " class='active'" : ""; ?>>
					<a href="main_page.php?p=1&q=1">
						<?php echo uiIcon('cal_week', array('title' => $de_lang ? 'Woche' : 'Week', 'alt' => $de_lang ? 'Woche' : 'Week')); ?>
					</a>
				</li>
				<li<?php echo ($q=='2') ? " class='active'" : ""; ?>>
					<a href="main_page.php?p=1&q=2">
						<?php echo uiIcon('cal_month', array('title' => $de_lang ? 'Monat' : 'Month', 'alt' => $de_lang ? 'Monat' : 'Month')); ?>
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
	var msgSaveFailed = <?php echo json_encode(_online_block_save_failed); ?>;
	var msgServerUnreachable = <?php echo json_encode(_online_block_server_unreachable); ?>;
	var msgSaved = <?php echo json_encode(_online_block_saved); ?>;
	var reasonPanel = document.getElementById('ob-reason-panel');
	var reasonInput = document.getElementById('ob-reason-input');
	var feedback = document.getElementById('ob-feedback');
	var pendingToggle = null;

	function showFeedback(kind, text) {
		if (!feedback) { return; }
		feedback.innerHTML = '';
		var box = document.createElement('div');
		box.className = kind;
		var p = document.createElement('p');
		p.textContent = text;
		box.appendChild(p);
		feedback.appendChild(box);
	}

	function submitBlock(a, blocked, reason) {
		fetch('ajax/online_block.php', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-OB-Token': token },
			body: JSON.stringify({ outlet_id: a.getAttribute('data-outlet'), date: a.getAttribute('data-date'), blocked: !blocked, reason: reason })
		}).then(function (r) { return r.json(); }).then(function (j) {
			if (!j.ok) { showFeedback('alert_error', j.error || msgSaveFailed); return; }
			showFeedback('alert_success', msgSaved);
			window.setTimeout(function () { window.location.reload(); }, 700);
		}, function () { showFeedback('alert_error', msgServerUnreachable); });
	}

	document.addEventListener('click', function (e) {
		var toggle = e.target.closest ? e.target.closest('.ob-toggle') : null;
		if (toggle) {
			e.preventDefault();
			var blocked = toggle.getAttribute('data-blocked') === '1';
			if (blocked) { submitBlock(toggle, true, ''); return; }
			pendingToggle = toggle;
			if (reasonPanel) {
				reasonPanel.hidden = false;
				reasonInput.value = '';
				reasonPanel.scrollIntoView({ block: 'nearest' });
				reasonInput.focus();
			}
			return;
		}
		if (e.target.id === 'ob-reason-cancel') {
			reasonPanel.hidden = true;
			pendingToggle = null;
			return;
		}
		if (e.target.id === 'ob-reason-save') {
			if (pendingToggle) { submitBlock(pendingToggle, false, reasonInput.value); }
			reasonPanel.hidden = true;
			pendingToggle = null;
		}
	});
})();
</script>
<?php endif; ?>