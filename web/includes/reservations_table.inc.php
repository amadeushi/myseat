<?php
/*
 * The actual reservation table (moved out of reservations_grid.inc.php so the exact same markup can
 * be re-rendered standalone by web/ajax/reservations_table.php for the live "new reservation" refresh
 * - one place for this logic, never two versions drifting apart). Expects the same context as before:
 * $_SESSION (page, wait, outletID, selectedDate, outlet_max_tables), $q, $maitre, $general,
 * $availability, $tbl_availability, $resHighlightToday (from reservations_grid.inc.php, or set by the
 * caller directly when this file is included on its own).
 */
require_once __DIR__.'/reservation_row_render.inc.php';
?>
<br/>
<table class="global resv-table-small" cellpadding="0" cellspacing="0">
	<tbody id="res-tbody">
		<tr></tr>
		<?php
		// Clear reservation variable
		$reservations ='';

		if ($_SESSION['page'] == 1) {
			$reservations =	querySQL('all_reservations');
		}else{
			$reservations =	querySQL('reservations');
		}

		// reset total counters
		$tablesum = 0;
		$guestsum = 0;

		if ($reservations) {

			//start printing out reservation grid
			foreach($reservations as $row) {
				render_reservation_row_tr($row);
				$tablesum ++;
				$guestsum += $row->reservation_pax;
			}
		}
		?>
	</tbody>
	<tfoot>
		<tr style="border:1px #000;">
			<td class=" noprint"></td><td></td>
			<td colspan="2" class="bold"><?php echo $guestsum;?>&nbsp;&nbsp;<?php echo _guest_summary;?></td>
			<td></td>
			<td colspan="2" class="bold"><?php echo $tablesum;?>&nbsp;&nbsp;<?php echo _tables_summary;?></td>
			<?php
			if($_SESSION['wait'] == 0){
				//echo "<td></td>";
			}
			?>
		</tr>
	</tfoot>
</table>
