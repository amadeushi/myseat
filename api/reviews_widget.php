<?php
/*
 * Compact, embeddable version of reviews.php - meant to be dropped into the restaurant's own
 * website via an iframe:
 *   <iframe src="https://reservierung.amds.at/api/reviews_widget.php" style="width:100%;max-width:420px;height:520px;border:0;"></iframe>
 * (add &lang=en for the English texts). No page chrome, transparent background, its own internal
 * scroll so a fixed iframe height still works without JS-based auto-resize.
 */
session_start();
require_once '../config/config.general.php';
require_once '../web/classes/mysql_compat.php';
require_once '../web/classes/connect.db.php';
require_once '../web/classes/local.class.php';
require_once '../web/classes/business.class.php';
require_once '../web/classes/feedback.class.php';

$outlet_id = isset($_GET['outletID']) ? (int)$_GET['outletID'] : 1;
$lang = (isset($_GET['lang']) && $_GET['lang'] === 'en') ? 'en' : 'de';
$de = ($lang !== 'en');
$limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 10;

$reviews = fb_public_reviews($outlet_id, $limit);
$stats = fb_stats($reviews);

$t = $de ? array(
	'based_on' => 'basierend auf', 'reviews' => 'Bewertungen', 'empty' => 'Noch keine öffentlichen Bewertungen.',
) : array(
	'based_on' => 'based on', 'reviews' => 'reviews', 'empty' => 'No public reviews yet.',
);

function fbw_stars($n, $size = 15) {
	$n = (int)$n; $out = '';
	for ($i = 1; $i <= 5; $i++) { $out .= '<span style="font-size:'.$size.'px;color:'.($i <= $n ? '#c9a259' : '#3a352c').';">&#9733;</span>'; }
	return $out;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Reviews widget</title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Raleway:wght@400;500;600;700&display=swap"/>
<style>
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; background: transparent; }
body {
	font-family: 'Raleway', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
	color: #f3ede1; font-size: 13px; line-height: 1.45;
	display: flex; flex-direction: column; height: 100%; overflow: hidden;
}
.head { flex: 0 0 auto; display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-bottom: 1px solid rgba(245,240,230,.08); }
.head .num { font-size: 22px; font-weight: 700; color: #e2c07f; }
.head .meta { font-size: 11px; color: #a89e8c; }
.list { flex: 1 1 auto; overflow-y: auto; padding: 10px 14px; }
.item { padding: 10px 0; border-bottom: 1px solid rgba(245,240,230,.06); }
.item:last-child { border-bottom: 0; }
.item-head { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
.item-name { font-weight: 600; color: #e2c07f; }
.item-comment { margin: 4px 0 0; color: #f3ede1; }
.empty { padding: 20px 14px; color: #a89e8c; }
</style>
</head>
<body>
	<?php if ($stats['count']): ?>
	<div class="head">
		<span class="num"><?php echo number_format($stats['avg_overall'], 1); ?></span>
		<span class="meta"><?php echo fbw_stars(round($stats['avg_overall']), 15); ?><br><?php echo $t['based_on'].' '.$stats['count'].' '.$t['reviews']; ?></span>
	</div>
	<?php endif; ?>
	<div class="list">
		<?php if (!$reviews): ?>
			<p class="empty"><?php echo htmlspecialchars($t['empty']); ?></p>
		<?php else: foreach ($reviews as $r): ?>
			<div class="item">
				<div class="item-head">
					<span class="item-name"><?php echo htmlspecialchars(fb_public_name($r['guest_name'])); ?></span>
					<span><?php echo fbw_stars($r['rating_overall'], 14); ?></span>
				</div>
				<?php if ($r['comment'] !== ''): ?><p class="item-comment"><?php echo nl2br(htmlspecialchars($r['comment'])); ?></p><?php endif; ?>
			</div>
		<?php endforeach; endif; ?>
	</div>
</body>
</html>
