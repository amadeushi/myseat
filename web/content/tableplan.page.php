<?php
/* Table plan page (main_page.php?p=7) */
if ( !current_user_can( 'Reservation-Edit' ) && !current_user_can( 'Page-System' ) ) {
	redeclare_access();
	return;
}
include_once __DIR__ . '/../classes/tableplan.class.php';
tp_ensure_schema();

if (empty($_SESSION['tp_token'])) {
	$_SESSION['tp_token'] = bin2hex(random_bytes(16));
}
$tp_can_edit = (bool)current_user_can( 'Page-System' );
$tp_outlet_name = isset($_SESSION['selOutlet']['outlet_name']) ? $_SESSION['selOutlet']['outlet_name'] : '';
$tp_today = date('Y-m-d');
$tp_date  = (isset($_SESSION['selectedDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_SESSION['selectedDate'])) ? $_SESSION['selectedDate'] : $tp_today;
$tp_cfg = array(
	'date'    => $tp_date,
	'today'   => $tp_today,
	'token'   => $_SESSION['tp_token'],
	'canEdit' => $tp_can_edit,
	'canvasW' => TP_CANVAS_W,
	'canvasH' => TP_CANVAS_H,
);
?>
<div class="onecolumn tp-page" id="tp-page">
	<div class="header">
		<h2>Tischplan<?php echo $tp_outlet_name !== '' ? ' &ndash; '.$tp_outlet_name : ''; ?></h2>
		<ul class="second_level_tab noprint">
			<?php if ($tp_can_edit): ?>
			<li><a href="#" id="tp-mode-toggle" onclick="return false;">Plan bearbeiten</a></li>
			<?php endif; ?>
		</ul>
	</div>
	<div class="content">
		<div class="tp-layout">
			<div class="tp-main">
				<div class="tp-tabs" id="tp-tabs" role="tablist" aria-label="Bereiche"></div>
				<div class="tp-stage" id="tp-stage">
					<div class="tp-canvas" id="tp-canvas" style="width:<?php echo TP_CANVAS_W; ?>px;height:<?php echo TP_CANVAS_H; ?>px;">
						<svg class="tp-links" id="tp-links" width="<?php echo TP_CANVAS_W; ?>" height="<?php echo TP_CANVAS_H; ?>" aria-hidden="true"></svg>
					</div>
				</div>
			</div>
			<aside class="tp-panel" id="tp-panel" aria-live="polite"></aside>
		</div>
		<p class="tp-msg" id="tp-msg" role="status"></p>
	</div>
</div>
<script type="text/javascript">window.TP = <?php echo json_encode($tp_cfg); ?>;</script>
<script type="text/javascript" src="js/tableplan.js?v=<?php echo @filemtime(__DIR__.'/../js/tableplan.js'); ?>"></script>
