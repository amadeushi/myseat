<?php
/*
 * Edit form of a reservation (main_page.php?p=102). Same simple layout as the new form
 * (includes/new.inc.php); date change, outlet move and the like sit under "Details".
 * Saved by ajax/process_reservation.php, behaviour in js/resform.js.
 */
$rsv_staff = !empty($_SESSION['realname']) ? $_SESSION['realname'] : (isset($_SESSION['u_name']) ? $_SESSION['u_name'] : '');
$rsv_res_id = (int)$_SESSION['resID'];
$rsv_selected = tp_assigned_table_ids($rsv_res_id);
// keep the reservation's own mail language on save - it is not part of this form and not in the
// explicit column list the legacy queries use, so re-saving it here must not reset it to German
$rsv_lang_row = tp_rows("SELECT reservation_email_lang FROM `".$dbTables->reservations."` WHERE reservation_id = ?", 'i', array($rsv_res_id));
$rsv_email_lang = $rsv_lang_row ? $rsv_lang_row[0]['reservation_email_lang'] : 'de';
// the table picker asks for the day of the reservation, not for the day selected in the session
$rsv_pick_date = date('Y-m-d', strtotime($row->reservation_date));
?>
<div class="rsv-wrap">
<form method="post" action="ajax/process_reservation.php" id="new_reservation_form" class="rsv-form" novalidate="novalidate">
	<div class="rsv-card">
		<p class="rsv-meta"><?php echo _booknum; ?>: <strong><?php echo htmlspecialchars($row->reservation_bookingnumber); ?></strong>
			&nbsp;&middot;&nbsp; <?php echo _outlets; ?>: <?php echo htmlspecialchars($row->outlet_name); ?>
			&nbsp;&middot;&nbsp; <?php echo _created; ?>: <?php echo humanize($row->reservation_timestamp); ?></p>

		<div class="rsv-grid rsv-grid-top">
			<div class="rsv-field">
				<label><?php echo rt('date'); ?></label>
				<div class="rsv-static"><?php echo date($general['datepickerformat'], strtotime($row->reservation_date)); ?></div>
			</div>
			<div class="rsv-field">
				<label for="reservation_time"><?php echo rt('time'); ?> *</label>
				<?php getTimeList($general['timeformat'], $general['timeintervall'],'reservation_time',$row->reservation_time,$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],0,'required');?>
			</div>
			<div class="rsv-field">
				<label for="reservation_pax"><?php echo rt('pax'); ?> *</label>
				<div class="rsv-stepper">
					<button type="button" data-step="-1" aria-label="<?php echo htmlspecialchars(rt('less')); ?>">&minus;</button>
					<input type="text" inputmode="numeric" name="reservation_pax" id="reservation_pax" value="<?php echo (int)$row->reservation_pax; ?>" class="required digits" title=" " autocomplete="off"/>
					<button type="button" data-step="1" aria-label="<?php echo htmlspecialchars(rt('more')); ?>">+</button>
				</div>
			</div>
		</div>

		<div class="rsv-grid rsv-grid-guest">
			<div class="rsv-field">
				<label for="reservation_guest_name"><?php echo rt('name'); ?> *</label>
				<input type="text" name="reservation_guest_name" id="reservation_guest_name" class="required" title=" " minlength="2" autocomplete="off"
					value="<?php echo htmlspecialchars(isset($_SESSION['reservation_guest_name']) ? $_SESSION['reservation_guest_name'] : $row->reservation_guest_name, ENT_QUOTES, 'UTF-8', false); ?>"/>
			</div>
			<div class="rsv-field">
				<label for="reservation_guest_phone"><?php echo rt('phone'); ?></label>
				<input type="tel" inputmode="tel" name="reservation_guest_phone" id="reservation_guest_phone" autocomplete="off" placeholder="+49 151 2345678" aria-describedby="rsv-phone-msg"
					value="<?php echo htmlspecialchars($row->reservation_guest_phone, ENT_QUOTES, 'UTF-8', false); ?>"/>
				<p class="rsv-msg" id="rsv-phone-msg" role="alert"></p>
			</div>
			<div class="rsv-field">
				<label for="reservation_guest_email"><?php echo rt('email'); ?></label>
				<input type="email" name="reservation_guest_email" id="reservation_guest_email" autocomplete="off" aria-describedby="rsv-email-msg"
					value="<?php echo htmlspecialchars($row->reservation_guest_email, ENT_QUOTES, 'UTF-8', false); ?>"/>
				<p class="rsv-msg" id="rsv-email-msg" role="alert"></p>
			</div>
		</div>

		<div class="rsv-grid rsv-grid-notes">
			<div class="rsv-field rsv-notes">
				<label for="reservation_notes"><?php echo rt('note'); ?></label>
				<textarea name="reservation_notes" id="reservation_notes" rows="6" maxlength="254"><?php echo trim($row->reservation_notes); ?></textarea>
			</div>
			<div class="rsv-field rsv-tables-col">
				<?php include 'includes/rsv_tables.inc.php'; ?>
			</div>
		</div>

		<?php if ($_SESSION['selOutlet']['limit_password']!='') { ?>
		<div class="rsv-field rsv-pass">
			<label for="limit_password"><?php echo _enter_password; ?> *</label>
			<input type="text" name="limit_password" id="limit_password" class="required" title=" "/>
			<div id="status"></div>
		</div>
		<?php } ?>

		<details class="rsv-details" id="rsv-details">
			<summary><?php echo rt('details'); ?></summary>
			<div class="rsv-grid rsv-grid-details">
				<div class="rsv-field">
					<label><?php echo _date; ?></label>
					<div class="date dategroup">
						<div class="text" id="datetext"><?php echo date($general['datepickerformat'],strtotime($row->reservation_date));?></div>
						<input type="text" id="ev_datepicker"/>
						<input type="hidden" name="reservation_date" id="event_date" value="<?php echo date('Y-m-d',strtotime($row->reservation_date));?>"/>
					</div>
				</div>
				<div class="rsv-field">
					<label><?php echo _move_reservation_to; ?></label>
					<?php outletList($_SESSION['outletID'],'enabled','reservation_outlet_id'); ?>
				</div>
			</div>
		</details>

		<p class="rsv-msg rsv-form-msg" id="rsv-form-msg" role="alert"></p>
		<div class="rsv-final">
			<div class="rsv-field rsv-staff-final">
				<label for="reservation_booker_name"><?php echo rt('staff'); ?> *</label>
				<input type="text" name="reservation_booker_name" id="reservation_booker_name" class="required" title=" " minlength="3" maxlength="30" value="<?php echo htmlspecialchars($rsv_staff); ?>"/>
			</div>
			<div class="rsv-actions">
				<button id="submit_btn" type="submit" class="rsv-save"><?php echo rt('save'); ?></button>
			</div>
		</div>
	</div>

	<input type="hidden" name="reservation_hotelguest_yn" value="<?php echo htmlspecialchars($row->reservation_hotelguest_yn); ?>"/>
	<input type="hidden" name="reservation_id" value="<?php echo $_SESSION['resID'];?>">
	<input type="hidden" name="old_outlet_id" value="<?php echo $row->reservation_outlet_id;?>">
	<input type="hidden" name="reservation_bookingnumber" value="<?php echo $row->reservation_bookingnumber;?>">
	<input type="hidden" name="repeat_id" value="<?php echo $row->repeat_id;?>">
	<input type="hidden" name="email_type" value="no">
	<input type="hidden" name="reservation_email_lang" value="<?php echo htmlspecialchars($rsv_email_lang); ?>">
	<input type="hidden" name="reservation_ip" value="<?php echo $_SERVER['REMOTE_ADDR'];?>">
	<input type="hidden" name="token" value="<?php echo $token; ?>" />
	<input type="hidden" name="action" value="save_res">
</form>
</div>

<script type="text/javascript">
window.RSV = <?php echo json_encode(array(
	'phoneBad' => rt('phone_bad'), 'emailBad' => rt('email_bad'), 'emailNeed' => rt('email_need'), 'needTime' => rt('need_time'), 'needName' => rt('need_name'),
	'needPax' => rt('need_pax'), 'needStaff' => rt('need_staff'),
	'tablesAuto' => rt('tables_auto'), 'suggest' => rt('tables_suggest'), 'none' => rt('tables_none'),
	'pickTime' => rt('tables_pick_time'), 'loading' => rt('tables_loading'), 'fitOk' => rt('fit_ok'),
	'fitSeats' => rt('fit_seats'), 'fitBusy' => rt('fit_busy'), 'closed' => rt('closed_area'), 'busy' => rt('busy_by'),
	'seats' => defined('_seats') ? _seats : 'Seats',
), JSON_UNESCAPED_UNICODE); ?>;
</script>
<script type="text/javascript" src="js/resform.js?v=<?php echo @filemtime(__DIR__.'/../js/resform.js'); ?>"></script>
