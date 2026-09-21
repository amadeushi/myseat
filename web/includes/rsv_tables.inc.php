<?php
/*
 * Table picker of the reservation forms (new and edit). Needs $rsv_res_id (0 for a new
 * reservation) and $rsv_selected (table ids already assigned) from the including form.
 * The chips are built by js/resform.js from ajax/tp.php (action free_tables).
 */
if (empty($_SESSION['tp_token'])) {
	$_SESSION['tp_token'] = bin2hex(random_bytes(16));
}
$rsv_res_id   = isset($rsv_res_id) ? (int)$rsv_res_id : 0;
$rsv_selected = isset($rsv_selected) ? array_map('intval', $rsv_selected) : array();
?>
<div class="rsv-tables" id="rsv-tables"
	data-token="<?php echo htmlspecialchars($_SESSION['tp_token']); ?>"
	data-date="<?php echo htmlspecialchars(isset($rsv_pick_date) ? $rsv_pick_date : $_SESSION['selectedDate']); ?>"
	data-res="<?php echo $rsv_res_id; ?>"
	data-selected="<?php echo htmlspecialchars(implode(',', $rsv_selected)); ?>">
	<div class="rsv-tables-head">
		<label><?php echo rt('tables'); ?></label>
		<div class="rsv-tables-tools">
			<select id="rsv-area" aria-label="<?php echo htmlspecialchars(rt('all_areas')); ?>"><option value="0"><?php echo rt('all_areas'); ?></option></select>
			<span class="rsv-seg" role="group">
				<button type="button" class="is-on" data-show="free"><?php echo rt('free'); ?></button><button type="button" data-show="all"><?php echo rt('all'); ?></button>
			</span>
		</div>
	</div>
	<div class="rsv-chips" id="rsv-chips" aria-live="polite"></div>
	<p class="rsv-tables-msg" id="rsv-tables-msg"></p>
	<div id="rsv-tables-inputs"></div>
	<input type="hidden" name="tp_tables_sent" value="1"/>
</div>
