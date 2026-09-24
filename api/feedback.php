<?php
/*
 * Public guest feedback form, reached from the link in the "how was your visit?" mail
 * (web/cron/send_feedback_requests.php builds the link, web/classes/feedback.class.php holds the
 * data access). A 4-5 star overall rating asks the guest to also post it on Google/TripAdvisor
 * (links from the outlet's own settings); a lower rating just says thank you and stays private.
 */
session_start();
require_once '../config/config.general.php';
require_once '../web/classes/mysql_compat.php';
require_once '../web/classes/connect.db.php';
require_once '../web/classes/local.class.php';
require_once '../web/classes/business.class.php';
require_once '../web/classes/feedback.class.php';

$token = isset($_GET['token']) ? trim($_GET['token']) : (isset($_POST['token']) ? trim($_POST['token']) : '');
$f = $token !== '' ? fb_get_by_token($token) : null;

$lang = $f ? $f['lang'] : 'de';
$de = ($lang !== 'en');

$outlet = null;
$property = null;
if ($f) {
	$outlet = mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
		"SELECT outlet_name, outlet_tripadvisor_url, outlet_google_url, confirmation_email, property_id FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$f['outlet_id']." LIMIT 1"));
	if ($outlet) {
		$property = mysqli_fetch_assoc(mysqli_query($GLOBALS['__mysql_compat_link'],
			"SELECT name FROM `".$dbTables->properties."` WHERE id = ".(int)$outlet['property_id']." LIMIT 1"));
	}
}
$brand = $outlet ? ($outlet['outlet_name'] !== '' ? $outlet['outlet_name'] : ($property ? $property['name'] : 'Restaurant')) : 'Restaurant';
$brand = html_entity_decode($brand, ENT_QUOTES, 'UTF-8');

$submitted_now = null; // rating_overall right after a successful submit, for the thank-you branch
$error = '';

if ($f && $_SERVER['REQUEST_METHOD'] === 'POST' && $f['status'] === 'requested') {
	$food = isset($_POST['rating_food']) ? (int)$_POST['rating_food'] : 0;
	$service = isset($_POST['rating_service']) ? (int)$_POST['rating_service'] : 0;
	$comment = isset($_POST['comment']) ? trim(substr($_POST['comment'], 0, 2000)) : '';
	$consent_public = isset($_POST['consent_public']);
	if ($food < 1 || $food > 5 || $service < 1 || $service > 5) {
		$error = $de ? 'Bitte vergib für jede Kategorie 1 bis 5 Sterne.' : 'Please give 1 to 5 stars for every category.';
	} else {
		fb_submit($f['feedback_id'], $food, $service, $comment, $consent_public);
		$submitted_now = (int)round(($food + $service) / 2);
	}
}

$t = $de ? array(
	'title' => 'Dein Feedback', 'invalid' => 'Dieser Link ist ungültig oder abgelaufen.',
	'already_h' => 'Danke, das haben wir schon!', 'already' => 'Zu dieser Reservierung liegt uns bereits dein Feedback vor.',
	'heading' => 'Wie war dein Besuch?', 'sub' => 'Sag uns ehrlich, was gut lief und was wir besser machen können. Beides hilft uns.',
	'food' => 'Speisen & Getränke', 'service' => 'Service',
	'comment_l' => 'Möchtest du uns noch etwas mitteilen? (optional)', 'submit' => 'Feedback senden',
	'consent_l' => 'Diese Bewertung darf (mit Vorname und Initiale) öffentlich gezeigt werden.',
	'thanks_high_h' => 'Das freut uns riesig, danke!',
	'thanks_high' => 'Wenn du magst, hilft uns eine kurze Bewertung auf TripAdvisor sehr, damit andere uns finden. Es dauert nur eine Minute.',
	'thanks_low_h' => 'Danke für deine Ehrlichkeit.',
	'thanks_low' => 'Das tut uns leid, und wir nehmen es ernst. Schreib uns gern kurz, was schiefgelaufen ist, wir melden uns persönlich bei dir.',
	'google' => 'Auf Google bewerten', 'google_alt' => 'Oder auf Google bewerten', 'tripadvisor' => 'Auf TripAdvisor bewerten', 'write_us' => 'Uns direkt schreiben',
) : array(
	'title' => 'Your feedback', 'invalid' => 'This link is invalid or has expired.',
	'already_h' => 'Thanks, we already have that!', 'already' => 'We already have your feedback for this reservation.',
	'heading' => 'How was your visit?', 'sub' => 'Tell us honestly what went well and what we can do better. Both help us.',
	'food' => 'Food & Drinks', 'service' => 'Service',
	'comment_l' => 'Anything else you would like to tell us? (optional)', 'submit' => 'Send feedback',
	'consent_l' => 'This review may be shown publicly (with first name and initial).',
	'thanks_high_h' => 'That makes us so happy, thank you!',
	'thanks_high' => 'If you like, a short review on TripAdvisor helps others find us. It only takes a minute.',
	'thanks_low_h' => 'Thank you for being honest.',
	'thanks_low' => 'We are sorry, and we take it seriously. Please write to us and tell us what went wrong, we will get back to you personally.',
	'google' => 'Rate us on Google', 'google_alt' => 'Or rate us on Google', 'tripadvisor' => 'Rate us on TripAdvisor', 'write_us' => 'Write to us directly',
);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex,nofollow"/>
<title><?php echo htmlspecialchars($t['title'].' – '.$brand); ?></title>
<link rel="stylesheet" href="../web/fonts/fonts.css"/>
<style>
:root {
	--bg: #0c0b0a; --surface: #151312; --surface-2: #1c1a18;
	--border: rgba(201,164,89,.22); --border-soft: rgba(245,240,230,.08);
	--gold: #c9a259; --gold-strong: #e2c07f; --text: #f3ede1; --text-muted: #a89e8c; --danger: #e2867c;
	--font-display: 'Cormorant Garamond', Georgia, serif;
	--font-body: 'Raleway', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
html { background: var(--bg); }
body {
	margin: 0; min-height: 100vh; min-height: 100dvh;
	display: flex; flex-direction: column; align-items: center;
	padding: max(28px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) 40px max(16px, env(safe-area-inset-left));
	background: radial-gradient(120% 80% at 50% 0%, #1a1712 0%, var(--bg) 60%);
	color: var(--text-muted); font-family: var(--font-body); font-size: 15px; line-height: 1.5;
}
.brand { margin: 0 0 24px; font-family: var(--font-display); font-weight: 300; font-size: clamp(32px, 9vw, 44px); color: var(--text); text-align: center; }
.card { width: 100%; max-width: 480px; padding: clamp(22px, 6vw, 34px); background: var(--surface); border: 1px solid var(--border-soft); border-radius: 16px; }
.card h1 { margin: 0 0 6px; font-family: var(--font-display); font-weight: 500; font-size: 26px; color: var(--text); }
.card .sub { margin: 0 0 26px; color: var(--text-muted); }
.notice { margin: 0 0 20px; padding: 12px 14px; border-radius: 10px; font-size: 14px; border: 1px solid var(--danger); background: rgba(226,134,124,.1); color: var(--danger); }
.group { margin-bottom: 24px; }
.group > span { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; letter-spacing: .04em; color: var(--text); }
.stars { display: flex; flex-direction: row-reverse; justify-content: flex-end; gap: 4px; }
.stars input { position: static; opacity: 0; width: 0; height: 0; margin: 0; padding: 0; border: 0; }
.stars label { font-size: 34px; line-height: 1; color: var(--surface-2); cursor: pointer; transition: color .1s; }
.stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: var(--gold); }
.stars input:focus-visible + label { outline: 2px solid var(--gold-strong); outline-offset: 2px; border-radius: 4px; }
textarea { width: 100%; box-sizing: border-box; min-height: 100px; padding: 12px 14px; font: inherit; font-size: 15px; color: var(--text); background: var(--surface-2); border: 1px solid var(--border-soft); border-radius: 10px; resize: vertical; }
textarea:focus { outline: none; border-color: var(--gold); }
.consent { display: flex; align-items: flex-start; gap: 10px; margin: 4px 0 20px; font-size: 13px; color: var(--text-muted); cursor: pointer; }
.consent input { margin-top: 3px; flex: 0 0 auto; }
.submit { width: 100%; height: 50px; margin-top: 8px; font: inherit; font-size: 16px; font-weight: 700; color: var(--bg); background: var(--gold); border: 0; border-radius: 999px; cursor: pointer; }
.submit:hover { background: var(--gold-strong); }
.stars-static { font-size: 28px; letter-spacing: 2px; color: var(--gold); margin: 4px 0 20px; }
.ext-links { display: flex; flex-direction: column; gap: 12px; margin-top: 22px; }
.ext-btn { display: block; text-align: center; padding: 14px; border-radius: 999px; text-decoration: none; font-weight: 700; border: 1px solid var(--border); color: var(--text); }
.ext-btn:hover { border-color: var(--gold); color: var(--gold-strong); }
.ext-btn-primary { background: var(--gold); border-color: var(--gold); color: #1a1408; }
.ext-btn-primary:hover { background: var(--gold-strong); border-color: var(--gold-strong); color: #1a1408; }
.legal-footer { display: flex; justify-content: center; align-items: center; gap: 4px; padding: 20px 16px 28px; font-size: 14px; letter-spacing: .02em; color: var(--text-muted); }
.legal-footer a { color: var(--text-muted); padding: 10px 8px; text-decoration: none; border-bottom: 1px solid transparent; transition: color .2s ease, border-color .2s ease; }
.legal-footer a:hover, .legal-footer a:focus-visible { color: var(--gold-strong); border-bottom-color: currentColor; }
</style>
</head>
<body>
	<div class="brand"><?php echo htmlspecialchars($brand); ?></div>
	<main class="card">
		<?php if (!$f): ?>
			<h1><?php echo htmlspecialchars($t['invalid']); ?></h1>

		<?php elseif ($submitted_now !== null): ?>
			<?php if ($submitted_now >= 4): ?>
				<h1><?php echo htmlspecialchars($t['thanks_high_h']); ?></h1>
				<p class="sub"><?php echo htmlspecialchars($t['thanks_high']); ?></p>
				<?php $has_ta = !empty($outlet['outlet_tripadvisor_url']); $has_go = !empty($outlet['outlet_google_url']); ?>
				<div class="ext-links">
					<?php if ($has_ta): ?>
						<a class="ext-btn ext-btn-primary" href="<?php echo htmlspecialchars($outlet['outlet_tripadvisor_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($t['tripadvisor']); ?></a>
					<?php endif; ?>
					<?php if ($has_go): ?>
						<a class="ext-btn<?php echo $has_ta ? '' : ' ext-btn-primary'; ?>" href="<?php echo htmlspecialchars($outlet['outlet_google_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($has_ta ? $t['google_alt'] : $t['google']); ?></a>
					<?php endif; ?>
				</div>
			<?php else: ?>
				<h1><?php echo htmlspecialchars($t['thanks_low_h']); ?></h1>
				<p class="sub"><?php echo htmlspecialchars($t['thanks_low']); ?></p>
				<?php if (!empty($outlet['confirmation_email'])): ?>
					<div class="ext-links">
						<a class="ext-btn ext-btn-primary" href="mailto:<?php echo htmlspecialchars($outlet['confirmation_email']); ?>"><?php echo htmlspecialchars($t['write_us']); ?></a>
					</div>
				<?php endif; ?>
			<?php endif; ?>

		<?php elseif ($f['status'] === 'submitted'): ?>
			<h1><?php echo htmlspecialchars($t['already_h']); ?></h1>
			<p class="sub"><?php echo htmlspecialchars($t['already']); ?></p>
			<?php if ($f['rating_overall']) { echo '<div class="stars-static">'.str_repeat('&#9733;', (int)$f['rating_overall']).str_repeat('&#9734;', 5 - (int)$f['rating_overall']).'</div>'; } ?>

		<?php else: ?>
			<h1><?php echo htmlspecialchars($t['heading']); ?></h1>
			<p class="sub"><?php echo htmlspecialchars($t['sub']); ?></p>
			<?php if ($error !== ''): ?><div class="notice"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
			<form method="post" action="feedback.php">
				<input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>"/>
				<?php foreach (array('rating_food' => $t['food'], 'rating_service' => $t['service']) as $field => $label): ?>
				<div class="group">
					<span><?php echo htmlspecialchars($label); ?> *</span>
					<div class="stars">
						<?php for ($i = 5; $i >= 1; $i--): ?>
							<input type="radio" name="<?php echo $field; ?>" id="<?php echo $field.$i; ?>" value="<?php echo $i; ?>" required/>
							<label for="<?php echo $field.$i; ?>" aria-label="<?php echo $i; ?>">&#9733;</label>
						<?php endfor; ?>
					</div>
				</div>
				<?php endforeach; ?>
				<label for="comment" style="display:block;margin-bottom:8px;font-size:13px;font-weight:600;letter-spacing:.04em;color:var(--text);"><?php echo htmlspecialchars($t['comment_l']); ?></label>
				<textarea name="comment" id="comment" maxlength="2000"></textarea>
				<label class="consent"><input type="checkbox" name="consent_public" value="1"/> <span><?php echo htmlspecialchars($t['consent_l']); ?></span></label>
				<button type="submit" class="submit"><?php echo htmlspecialchars($t['submit']); ?></button>
			</form>
		<?php endif; ?>
	</main>
	<?php include __DIR__.'/legal_footer.php'; ?>
</body>
</html>
