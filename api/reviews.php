<?php
/*
 * Public reviews page: the guest reviews that both the guest (consent_public) and the restaurant
 * (is_public) agreed to show, newest first, including the restaurant's own reply if there is one.
 * Data access: web/classes/feedback.class.php. See also reviews_widget.php for an embeddable,
 * compact version of the same list (e.g. for the restaurant's own website).
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

$outlet = mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
	"SELECT outlet_name, property_id FROM `".$dbTables->outlets."` WHERE outlet_id = ".$outlet_id." LIMIT 1"));
$property = $outlet ? mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
	"SELECT name FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1")) : null;
$brand = $outlet ? ($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : ($property ? $property['name'] : 'Restaurant')) : 'Restaurant';
$brand = html_entity_decode($brand, ENT_QUOTES, 'UTF-8');

$reviews = fb_public_reviews($outlet_id, 50);
$stats = fb_stats($reviews);

$t = $de ? array(
	'title' => 'Bewertungen', 'sub' => 'Was unsere Gäste sagen', 'based_on' => 'basierend auf', 'reviews' => 'Bewertungen',
	'food' => 'Speisen & Getränke', 'service' => 'Service', 'reply' => 'Antwort vom Restaurant', 'empty' => 'Noch keine öffentlichen Bewertungen.',
) : array(
	'title' => 'Reviews', 'sub' => 'What our guests say', 'based_on' => 'based on', 'reviews' => 'reviews',
	'food' => 'Food & Drinks', 'service' => 'Service', 'reply' => 'Reply from the restaurant', 'empty' => 'No public reviews yet.',
);

function fb_stars_static($n, $size = 18) {
	$n = (int)$n; $out = '';
	for ($i = 1; $i <= 5; $i++) { $out .= '<span class="star'.($i <= $n ? ' on' : '').'" style="font-size:'.$size.'px;">&#9733;</span>'; }
	return $out;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title><?php echo htmlspecialchars($t['title'].' – '.$brand); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;500&family=Raleway:wght@400;500;600;700&display=swap"/>
<style>
:root {
	--bg: #0c0b0a; --surface: #151312; --surface-2: #1c1a18;
	--border-soft: rgba(245,240,230,.08); --gold: #c9a259; --gold-strong: #e2c07f;
	--text: #f3ede1; --text-muted: #a89e8c;
	--font-display: 'Cormorant Garamond', Georgia, serif;
	--font-body: 'Raleway', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
	margin: 0; min-height: 100vh; padding: 40px 16px 60px;
	background: radial-gradient(120% 80% at 50% 0%, #1a1712 0%, var(--bg) 60%);
	color: var(--text-muted); font-family: var(--font-body); font-size: 15px; line-height: 1.5;
}
.wrap { max-width: 640px; margin: 0 auto; }
.brand { margin: 0 0 4px; font-family: var(--font-display); font-weight: 300; font-size: clamp(30px, 8vw, 40px); color: var(--text); text-align: center; }
.sub { text-align: center; margin: 0 0 28px; }
.summary { display: flex; align-items: center; justify-content: center; gap: 16px; margin-bottom: 32px; }
.summary .num { font-family: var(--font-display); font-size: 40px; color: var(--gold-strong); }
.summary .meta { font-size: 13px; }
.star { color: var(--surface-2); }
.star.on { color: var(--gold); }
.card { background: var(--surface); border: 1px solid var(--border-soft); border-radius: 14px; padding: 18px 22px; margin-bottom: 14px; }
.card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.card-name { color: var(--gold-strong); font-weight: 600; }
.card-date { font-size: 12px; }
.card-cats { display: flex; gap: 18px; flex-wrap: wrap; margin: 6px 0; font-size: 12px; }
.card-comment { color: var(--text); margin: 10px 0 0; }
.card-reply { margin-top: 12px; padding: 10px 14px; background: rgba(201,162,89,.08); border-left: 2px solid var(--gold); border-radius: 4px; font-size: 13px; }
.card-reply strong { color: var(--text); }
.empty { text-align: center; padding: 40px 0; }
</style>
</head>
<body>
	<div class="wrap">
		<div class="brand"><?php echo htmlspecialchars($brand); ?></div>
		<div class="sub"><?php echo htmlspecialchars($t['sub']); ?></div>
		<?php if ($stats['count']): ?>
		<div class="summary">
			<div class="num"><?php echo number_format($stats['avg_overall'], 1); ?></div>
			<div class="meta">
				<div><?php echo fb_stars_static(round($stats['avg_overall']), 20); ?></div>
				<div><?php echo $t['based_on'].' '.$stats['count'].' '.$t['reviews']; ?></div>
			</div>
		</div>
		<?php endif; ?>

		<?php if (!$reviews): ?>
			<p class="empty"><?php echo htmlspecialchars($t['empty']); ?></p>
		<?php else: foreach ($reviews as $r): ?>
			<div class="card">
				<div class="card-head">
					<span class="card-name"><?php echo htmlspecialchars(fb_public_name($r['guest_name'])); ?></span>
					<span><?php echo fb_stars_static($r['rating_overall'], 18); ?></span>
				</div>
				<div class="card-date"><?php echo date($de ? 'd.m.Y' : 'M j, Y', strtotime($r['visit_date'])); ?></div>
				<div class="card-cats">
					<span><?php echo htmlspecialchars($t['food']); ?>: <?php echo fb_stars_static($r['rating_food'], 13); ?></span>
					<span><?php echo htmlspecialchars($t['service']); ?>: <?php echo fb_stars_static($r['rating_service'], 13); ?></span>
				</div>
				<?php if ($r['comment'] !== ''): ?><p class="card-comment"><?php echo nl2br(htmlspecialchars($r['comment'])); ?></p><?php endif; ?>
				<?php if (!empty($r['reply'])): ?>
					<div class="card-reply"><strong><?php echo htmlspecialchars($t['reply']); ?>:</strong> <?php echo nl2br(htmlspecialchars($r['reply'])); ?></div>
				<?php endif; ?>
			</div>
		<?php endforeach; endif; ?>
	</div>
</body>
</html>
