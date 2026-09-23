<?php
/*
 * Guest feedback tab: aggregate rating for a date range (by visit date) plus the individual
 * reviews, each with a staff reply and a public/private toggle. Data access in
 * classes/feedback.class.php; guests submit through api/feedback.php after the request mail
 * sent by cron/send_feedback_requests.php.
 */
require_once __DIR__.'/../classes/feedback.class.php';

$today = date('Y-m-d');
$date_from = isset($_GET['fb_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fb_from']) ? $_GET['fb_from'] : date('Y-m-01');
$date_to   = isset($_GET['fb_to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['fb_to'])   ? $_GET['fb_to']   : $today;
if ($date_from > $date_to) { list($date_from, $date_to) = array($date_to, $date_from); }

$rows = fb_list($_SESSION['outletID'], $date_from, $date_to);
$stats = fb_stats($rows);

function fb_stars_html($n, $size = 18) {
	$n = (int)$n;
	$out = '';
	for ($i = 1; $i <= 5; $i++) {
		$out .= '<span class="fb-star'.($i <= $n ? ' is-on' : '').'" style="font-size:'.$size.'px;">&#9733;</span>';
	}
	return $out;
}
?>
<div class="onecolumn">
	<div class="header">
		<h2><?php echo _feedback; ?></h2>
		<form method="get" action="main_page.php" class="fb-filter">
			<input type="hidden" name="p" value="8"/>
			<input type="date" name="fb_from" value="<?php echo htmlspecialchars($date_from); ?>"/>
			<span>&ndash;</span>
			<input type="date" name="fb_to" value="<?php echo htmlspecialchars($date_to); ?>"/>
			<button type="submit" class="button_dark"><?php echo defined('_filter') ? _filter : 'Filtern'; ?></button>
		</form>
	</div>

	<div class="content">
		<div class="fb-summary">
			<div class="fb-summary-avg">
				<div class="fb-summary-num"><?php echo $stats['count'] ? number_format($stats['avg_overall'], 1) : '–'; ?></div>
				<div class="fb-summary-label"><?php echo _feedback_avg; ?></div>
				<div class="fb-summary-sub"><?php echo _feedback_based_on.' '.$stats['count'].' '._feedback_reviews; ?></div>
				<div class="fb-stars-row"><?php echo fb_stars_html(round($stats['avg_overall'])); ?></div>
			</div>
			<div class="fb-summary-dist">
				<?php for ($i = 5; $i >= 1; $i--): $pct = $stats['count'] ? round($stats['dist'][$i] / $stats['count'] * 100) : 0; ?>
				<div class="fb-dist-row">
					<span class="fb-dist-n"><?php echo $i; ?> &#9733;</span>
					<div class="fb-dist-bar"><div class="fb-dist-fill" style="width:<?php echo $pct; ?>%;"></div></div>
					<span class="fb-dist-pct"><?php echo $pct; ?>%</span>
				</div>
				<?php endfor; ?>
			</div>
			<div class="fb-summary-cats">
				<div class="fb-cat-row"><span><?php echo _feedback_food; ?></span><?php echo fb_stars_html(round($stats['avg_food']), 16); ?></div>
				<div class="fb-cat-row"><span><?php echo _feedback_service; ?></span><?php echo fb_stars_html(round($stats['avg_service']), 16); ?></div>
			</div>
		</div>

		<?php if (!$rows): ?>
			<p class="fb-empty"><?php echo _feedback_no_entries; ?></p>
		<?php else: ?>
		<div class="fb-list">
			<?php foreach ($rows as $r): ?>
			<div class="fb-item">
				<div class="fb-item-head">
					<div>
						<strong class="fb-item-name"><?php echo htmlspecialchars($r['guest_name']); ?></strong>
						<span class="fb-item-date"><?php echo _feedback_from.' '.date($general['dateformat'], strtotime($r['visit_date'])); ?></span>
					</div>
					<div class="fb-stars-row"><?php echo fb_stars_html($r['rating_overall'], 20); ?></div>
				</div>
				<div class="fb-item-cats">
					<span><?php echo _feedback_food; ?>: <?php echo fb_stars_html($r['rating_food'], 14); ?></span>
					<span><?php echo _feedback_service; ?>: <?php echo fb_stars_html($r['rating_service'], 14); ?></span>
				</div>
				<?php if ($r['comment'] !== ''): ?><p class="fb-item-comment"><?php echo nl2br(htmlspecialchars($r['comment'])); ?></p><?php endif; ?>

				<?php if ($r['reply'] !== null && $r['reply'] !== ''): ?>
					<div class="fb-reply-existing">
						<strong><?php echo _feedback_reply; ?>:</strong> <?php echo nl2br(htmlspecialchars($r['reply'])); ?>
					</div>
				<?php endif; ?>

				<form method="post" action="ajax/feedback_action.php" class="fb-reply-form">
					<input type="hidden" name="token" value="<?php echo $token; ?>"/>
					<input type="hidden" name="feedback_id" value="<?php echo (int)$r['feedback_id']; ?>"/>
					<input type="hidden" name="fb_from" value="<?php echo htmlspecialchars($date_from); ?>"/>
					<input type="hidden" name="fb_to" value="<?php echo htmlspecialchars($date_to); ?>"/>
					<textarea name="reply" placeholder="<?php echo htmlspecialchars(_feedback_reply_placeholder); ?>"><?php echo htmlspecialchars($r['reply'] !== null ? $r['reply'] : ''); ?></textarea>
					<div class="fb-reply-row">
						<?php if ($r['consent_public']): ?>
						<label class="fb-public"><input type="checkbox" name="is_public" value="1" onchange="this.form.submit()" <?php echo $r['is_public'] ? 'checked' : ''; ?>/> <?php echo _feedback_public; ?></label>
					<?php else: ?>
						<span class="fb-no-consent"><?php echo _feedback_no_consent; ?></span>
					<?php endif; ?>
						<button type="submit" name="action" value="reply" class="button_dark"><?php echo _feedback_send_reply; ?></button>
					</div>
				</form>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
</div>
