<?php
require_once __DIR__.'/../classes/sms.class.php';
$rsv_sms = sms_enabled();
/*
 * "New reservation" form (main_page.php?p=2&q=2). Simple main part (date, time, guests, name,
 * phone, note, table) and a collapsible "Details" part. Saved by ajax/process_reservation.php,
 * behaviour (phone check, table picker) in js/resform.js.
 */
$rsv_staff = !empty($_SESSION['realname']) ? $_SESSION['realname'] : (isset($_SESSION['u_name']) ? $_SESSION['u_name'] : '');
$rsv_res_id = 0;
$rsv_selected = array();
?>
<div id="content_wrapper" class="rsv-wrap">
<form method="post" action="ajax/process_reservation.php" id="new_reservation_form" class="rsv-form" novalidate="novalidate">

	<div class="rsv-card">
		<div class="rsv-grid rsv-grid-top">
			<div class="rsv-field">
				<label><?php echo rt('date'); ?></label>
				<div class="rsv-static"><?php echo htmlspecialchars($_SESSION['selectedDate_user']); ?></div>
			</div>
			<div class="rsv-field">
				<label for="reservation_time"><?php echo rt('time'); ?> *</label>
				<?php getTimeList($general['timeformat'], $general['timeintervall'],'reservation_time','',$_SESSION['selOutlet']['outlet_open_time'],$_SESSION['selOutlet']['outlet_close_time'],1,'required');?>
			</div>
			<div class="rsv-field">
				<label for="reservation_pax"><?php echo rt('pax'); ?> *</label>
				<div class="rsv-stepper">
					<button type="button" data-step="-1" aria-label="<?php echo htmlspecialchars(rt('less')); ?>">&minus;</button>
					<input type="text" inputmode="numeric" name="reservation_pax" id="reservation_pax" value="2" class="required digits" title=" " autocomplete="off"/>
					<button type="button" data-step="1" aria-label="<?php echo htmlspecialchars(rt('more')); ?>">+</button>
				</div>
			</div>
		</div>

		<div class="rsv-grid rsv-grid-guest">
			<div class="rsv-field">
				<label for="reservation_guest_name"><?php echo rt('name'); ?> *</label>
				<input type="text" name="reservation_guest_name" id="reservation_guest_name" class="required" title=" " minlength="2" autocomplete="off"/>
			</div>
			<div class="rsv-field">
				<label for="reservation_guest_phone"><?php echo rt('phone'); ?></label>
				<input type="tel" inputmode="tel" name="reservation_guest_phone" id="reservation_guest_phone" autocomplete="off" placeholder="+49 151 2345678" aria-describedby="rsv-phone-msg"/>
				<p class="rsv-msg" id="rsv-phone-msg" role="alert"></p>
			</div>
			<div class="rsv-field">
				<label for="reservation_guest_email"><?php echo rt('email'); ?></label>
				<input type="email" name="reservation_guest_email" id="reservation_guest_email" autocomplete="off" aria-describedby="rsv-email-msg"/>
				<p class="rsv-msg" id="rsv-email-msg" role="alert"></p>
				<input type="hidden" name="email_type" value="no"/>
				<input type="hidden" name="reservation_email_lang" value="de"/>
				<label class="rsv-check"><input type="checkbox" name="email_type" id="rsv-mail-confirm" value="loc" disabled="disabled"<?php echo $rsv_sms ? ' data-sms="1"' : ''; ?>/> <span><?php echo rt($rsv_sms ? 'email_confirm_sms' : 'email_confirm'); ?></span></label>
			</div>
		</div>

		<div class="rsv-grid rsv-grid-notes">
			<div class="rsv-field rsv-notes">
				<label for="reservation_notes"><?php echo rt('note'); ?></label>
				<textarea name="reservation_notes" id="reservation_notes" rows="6" maxlength="254"></textarea>
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
					<input type="hidden" name="reservation_advertise" value=""/>
					<label class="rsv-check"><input type="checkbox" name="reservation_advertise" id="reservation_advertise" value="YES"/> <span><?php echo _reservation_advertise; ?></span></label>
				</div>
				<div class="rsv-field rsv-series">
					<label><?php echo rt('series'); ?></label>
					<div class="input-prepend">
						<span class="add-on"><?php echo rt('series_until'); ?></span>
						<input type="text" name="recurring_date" id="recurring_date"/>
						<input type="hidden" name="recurring_dbdate" id="recurring_dbdate" value="<?php echo $_SESSION['selectedDate']; ?>"/>
					</div>
					<div class="rsv-inline">
						<label><input type="radio" class="radio" name="recurring_span" value="1" checked="checked"/> <?php echo rt('daily'); ?></label>
						<label><input type="radio" class="radio" name="recurring_span" value="7"/> <?php echo rt('weekly'); ?></label>
					</div>
				</div>
			</div>
		</details>

		<p class="rsv-msg rsv-form-msg" id="rsv-form-msg" role="alert"></p>
		<div class="rsv-final">
			<div class="rsv-field rsv-staff-final">
				<label for="reservation_booker_name"><?php echo rt('staff'); ?> *</label>
				<?php if ($_SESSION['autofill']==1) { ?>
					<input type="text" disabled="disabled" value="<?php echo htmlspecialchars($rsv_staff); ?>"/>
					<input type="hidden" name="reservation_booker_name" id="reservation_booker_name" value="<?php echo htmlspecialchars($rsv_staff); ?>"/>
				<?php } else { ?>
					<input type="text" name="reservation_booker_name" id="reservation_booker_name" class="required" title=" " minlength="3" maxlength="30" value="<?php echo htmlspecialchars($rsv_staff); ?>"/>
				<?php } ?>
			</div>
			<div class="rsv-actions">
				<button id="submit_btn" type="submit" class="rsv-save"><?php echo rt('save'); ?></button>
			</div>
		</div>
		<?php
			if (isset($special_event_subject) && ($special_event_subject!='')) {
				echo "<p class='rsv-event'><strong>"._reservations." "._for_." ".htmlspecialchars($special_event_subject)." !</strong></p>";
			}
		?>
	</div>

	<input type="hidden" name="reservation_hotelguest_yn" value="PASS"/>
	<input type="hidden" name="reservation_outlet_id" value="<?php echo $_SESSION['outletID'];?>"/>
	<input type="hidden" name="reservation_timestamp" value="<?php echo date('Y-m-d H:i:s');?>"/>
	<input type="hidden" name="reservation_ip" value="<?php echo $_SERVER['REMOTE_ADDR'];?>"/>
	<input type="hidden" name="token" value="<?php echo $token; ?>" />
	<input type="hidden" name="action" value="save_res"/>
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

<script type="text/javascript">
// limit password check (runs when jQuery is available, i.e. after the page has loaded)
document.addEventListener('DOMContentLoaded', function () {
	if (!window.jQuery) { return; }
	var $ = window.jQuery;
	$("#limit_password").keyup(function() {
		var pwd = $("#limit_password").val();
		if (pwd.length >= 3) {
			$("#status").html('<img align="absmiddle" src="images/ajax-loader.gif" />');
			$.ajax({
				type: "POST",
				url: "ajax/check_password.php",
				data: "password=" + pwd,
				success: function(msg){
					if (msg.length < 4) { $("#status").html("&nbsp;" + <?php echo json_encode(uiIcon('check')); ?> + msg); }
					else { $("#status").html(msg); }
				}
			});
		}
	});
});
</script>
