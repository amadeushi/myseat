<div class="twocolumn_wrapper">
 <div class="twocolumn form-height">
  <div class="content detailbig content-height">
	<br/>
	<label><?php echo _status;?></label>
	<p><span class='bold'><?php echo showReservation_status($row->reservation_hidden); ?></strong></p>
	<br/>
	<label><?php echo _booknum;?></label>
	<p><span class='bold'><?php echo $row->reservation_bookingnumber; ?></strong></p>
	<br/>
	<label><?php echo _outlets;?></label>
	<p><?php echo $row->outlet_name; ?></p>
	<br/>
	<label><?php echo _date;?></label>
	<p>
		<?php echo date($general['dateformat'],strtotime($row->reservation_date));?>
	</p>
			<label><?php echo _time; ?></label>
			<p>
				<?php echo formatTime($row->reservation_time,$general['timeformat']); ?>
			</p>
			<label><?php echo _title; ?></label>
			<p>
				<?php getTitleList($row->reservation_title,'disabled');?>
			</p>
			<label><?php echo _guest_name; ?></label>
			<p>
				<span class='bold'>
				<?php
				$_SESSION['reservation_guest_name'] = $row->reservation_guest_name;
				echo $_SESSION['reservation_guest_name']; 
				?></strong>
			</p>
			<label><?php echo _pax; ?></label>
			<p>
				<?php echo $row->reservation_pax; ?>
			</p>
			<label><?php echo rt('phone'); ?></label>
			<p>
				<?php echo $row->reservation_guest_phone; ?>
			</p>
			<label><?php echo rt('tables'); ?></label>
			<p>
				<?php $rsv_names = tp_assigned_table_names($row->reservation_id); echo $rsv_names !== '' ? htmlspecialchars($rsv_names) : htmlspecialchars((string)$row->reservation_table); ?>
			</p>
			<label><?php echo _note; ?></label>
			<p>
				<?php echo $row->reservation_notes; ?>
			</p>
			<label><?php echo _author; ?></label>
			<p>
				<?php echo $row->reservation_booker_name; ?>
			</p>
			<br/>
		</div></div></div> <!-- end left column -->

	<!-- Beginn right column -->	
		<div class="twocolumn_wrapper right">
		 <div class="twocolumn form-height">
		  <div class="content detailbig content-height">
			<br/>
			<?php // old fields (address, discount, parking, payment) are only shown when an old reservation still has data in them ?>
			<?php if (trim($row->reservation_guest_adress) !== '' || trim($row->reservation_guest_city) !== ''): ?>
			<label><?php echo _adress; ?></label>
			<p>
				<?php echo $row->reservation_guest_adress; ?><br/><?php echo $row->reservation_guest_city; ?>
			</p>
			<?php endif; ?>
			<label><?php echo _email; ?></label>
			<p>
				<?php
					echo $row->reservation_guest_email; 
					if ( $row->reservation_advertise =='YES' ) {
						echo uiIcon('mail', array('class' => 'mail-icon', 'title' => 'Advertise allowed', 'alt' => 'Advertise allowed'));
					}else{
						echo uiIcon('mail_no', array('class' => 'mail-icon', 'title' => 'No advertise', 'alt' => 'No advertise'));
					}
				?>
			</p>
			<?php
			// group pre-order (n8n): the guest becomes the organizer and gets the links by mail
			require_once __DIR__.'/../classes/grouporder.class.php';
			$go = go_find($row->reservation_id);
			$go_pickup_ts = strtotime(substr($row->reservation_date, 0, 10).' '.substr($row->reservation_time, 0, 5));
			// not for cancelled reservations, and not for a visit that is already over (unless a group exists: then just show it)
			if ( current_user_can('Reservation-Edit') && (int)$row->reservation_hidden === 0 && ($go || $go_pickup_ts > time()) ):
				$go_email = html_entity_decode(trim($row->reservation_guest_email), ENT_QUOTES, 'UTF-8');
				// suggested deadline: three days before at noon, or the day before if that is already over
				$go_default = strtotime('-3 days', $go_pickup_ts); $go_default = mktime(12, 0, 0, date('n', $go_default), date('j', $go_default), date('Y', $go_default));
				if ($go_default <= time() + 3600) { $go_default = strtotime('-1 day', $go_pickup_ts); $go_default = mktime(12, 0, 0, date('n', $go_default), date('j', $go_default), date('Y', $go_default)); }
			?>
			<label>Gruppenbestellung</label>
			<div id="group-order" data-id="<?php echo (int)$row->reservation_id; ?>" data-token="<?php echo htmlspecialchars($token); ?>">
			<?php if ($go): ?>
				<p>Angelegt am <?php echo date('d.m.Y H:i', strtotime($go['created_at'])); ?><br/>
				<small>Organisator: <?php echo htmlspecialchars($go['organizer_email']); ?><?php echo $go['deadline'] ? ' &middot; Bestellschluss '.date('d.m.Y H:i', strtotime($go['deadline'])) : ''; ?></small></p>
				<?php if (!empty($go['participant_url'])): ?>
				<p><small>Teilnehmerlink: <a href="<?php echo htmlspecialchars($go['participant_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($go['participant_url']); ?></a><br/>
				Organisatorlink (vertraulich, gehört dem Gast): <a href="<?php echo htmlspecialchars($go['organizer_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($go['organizer_url']); ?></a></small></p>
				<p><button type="button" class="go-cancel" id="go-resend">Einladung erneut senden</button> <span id="go-resend-status" class="detail-status" role="status" aria-live="polite"></span></p>
				<?php endif; ?>
			<?php elseif (!filter_var($go_email, FILTER_VALIDATE_EMAIL)): ?>
				<p><small>Dafür braucht die Reservierung eine E-Mail-Adresse des Gastes.</small></p>
			<?php else: ?>
				<p><button type="button" class="button_dark" id="go-open">Gruppenbestellung anlegen</button></p>
				<div id="go-form" hidden>
					<p><small><?php echo htmlspecialchars($go_email); ?> wird als Organisator eingetragen und erhält per Mail den Link für die Teilnehmer und den vertraulichen Organisatorlink. Besuch: <?php echo date('d.m.Y H:i', $go_pickup_ts); ?> Uhr.</small></p>
					<p><label class="go-check"><input type="checkbox" id="go-deadline-on"/> Bestellschluss festlegen</label></p>
					<p id="go-deadline-row" hidden><input type="datetime-local" id="go-deadline" value="<?php echo date('Y-m-d\TH:i', $go_default); ?>" max="<?php echo date('Y-m-d\TH:i', $go_pickup_ts); ?>"/></p>
					<p><button type="button" class="button_dark" id="go-submit">Anlegen und Mail senden</button> <button type="button" class="go-cancel" id="go-cancel">Abbrechen</button> <span id="go-status" class="detail-status" role="status" aria-live="polite"></span></p>
				</div>
			<?php endif; ?>
			</div>
			<script>
			window.addEventListener('load', function () {
				var $ = window.jQuery, $box = $ && $('#group-order');
				if (!$box || !$box.length) { return; }
				var $status = $('#go-status'), $submit = $('#go-submit');
				$('#go-open').click(function () { $('#go-form').prop('hidden', false); $(this).hide(); });
				$('#go-cancel').click(function () { $('#go-form').prop('hidden', true); $('#go-open').show(); $status.text(''); });
				$('#go-deadline-on').change(function () { $('#go-deadline-row').prop('hidden', !this.checked); });
				// the guest lost the mail, or it never went out: same links, same mail once more
				$('#go-resend').click(function () {
					var $b = $(this), $s = $('#go-resend-status');
					$b.prop('disabled', true);
					$s.text('Wird gesendet ...').removeClass('is-error');
					$.ajax({
						type: 'POST', url: 'ajax/group_order.php', dataType: 'json',
						data: { id: $box.data('id'), token: $box.data('token'), op: 'resend' },
						success: function (r) {
							if (!r || !r.ok) { $s.text((r && r.error) || 'Das hat nicht geklappt.').addClass('is-error'); $b.prop('disabled', false); return; }
							$s.text(r.message);
						},
						error: function () { $s.text('Das hat nicht geklappt. Bitte versuche es noch einmal.').addClass('is-error'); $b.prop('disabled', false); }
					});
				});
				$submit.click(function () {
					$submit.prop('disabled', true);
					$status.text('Wird angelegt ...').removeClass('is-error');
					$.ajax({
						type: 'POST', url: 'ajax/group_order.php', dataType: 'json',
						data: { id: $box.data('id'), token: $box.data('token'), deadline: $('#go-deadline-on').is(':checked') ? $('#go-deadline').val() : '' },
						success: function (r) {
							if (!r || !r.ok) { $status.text((r && r.error) || 'Das hat nicht geklappt.').addClass('is-error'); $submit.prop('disabled', false); return; }
							var esc = function (t) { return $('<span>').text(t || '').html(); };
							var link = function (u) { return '<a href="' + esc(u) + '" target="_blank" rel="noopener">' + esc(u) + '</a>'; };
							$box.html('<p>Angelegt am ' + r.created_at + '<br/><small>' + esc(r.message) + '</small></p>'
								+ (r.participant_url ? '<p><small>Teilnehmerlink: ' + link(r.participant_url) + '<br/>Organisatorlink (vertraulich, gehört dem Gast): ' + link(r.organizer_url) + '</small></p>' : ''));
						},
						error: function () { $status.text('Das hat nicht geklappt. Bitte versuche es noch einmal.').addClass('is-error'); $submit.prop('disabled', false); }
					});
				});
			});
			</script>
			<?php endif; ?>
			<?php if (!empty($row->reservation_discount) || !empty($row->reservation_parkticket)): ?>
			<label><?php echo _discount; ?> / <?php echo _parking; ?></label>
			<p>
				<?php echo $row->reservation_discount; ?> / <?php echo $row->reservation_parkticket; ?>
			</p>
			<?php endif; ?>
			<!-- <label><?php echo _table; ?></label>
				 <p>
					<?php echo $row->reservation_table; ?>
				 </p>
			-->

			<?php $rsv_has_pay = ($row->reservation_bill_paid || $row->reservation_billet_sent || (trim((string)$row->reservation_bill) !== '' && strtolower(trim((string)$row->reservation_bill)) !== 'mail')); if ($rsv_has_pay): ?>
			<label><?php echo _payment; ?></label>
			<p>
				<span class="width-250">
				<?php
				echo _paid."<br>";
				$paid = ($row->reservation_bill_paid) ? 1 : 0;
				echo printOnOff($paid,'reservation_bill_paid')."&nbsp;&nbsp;";
				if($paid){
					humanize($row->reservation_bill_paid);
				} 
				echo "<br>"._shipped."<br>";
				$paid = ($row->reservation_billet_sent) ? 1 : 0;
				echo printOnOff($paid,'reservation_billet_sent')."&nbsp;&nbsp;";
				if($paid){
					humanize($row->reservation_billet_sent);
				} 
				?>
				</span>
			</p>
			<label><?php echo _paid_by; ?></label>
			<p>
				<?php getPaidList($row->reservation_bill,'disabled');?>
			</p>
			<?php endif; ?>
			<label><?php echo _multi_booking; ?></label>
			<p>
				<?php echo $row->start_date;?>
			</p>
			<p>
				<?php echo $row->end_date;?>
			</p>
			<br/>
			<label><?php echo _created; ?></label>
			<p><small>
				<?php echo humanize($row->reservation_timestamp);?>
			</small></p>
			<br/>
		</div></div></div> <!-- end right column -->
</div>