<?php
/*
 * Look at a reservation request and confirm or decline it straight from the notification mail.
 * The link is signed (web/classes/approval.class.php appr_token), so no login is needed; the
 * decision itself only happens on a POST, never on opening the link.
 */
require_once __DIR__ . '/../web/classes/mysql_compat.php';
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/connect.db.php');
include(__DIR__.'/../web/classes/database.class.php');
include(__DIR__.'/../web/classes/local.class.php');
include(__DIR__.'/../web/classes/business.class.php');
include(__DIR__.'/../web/classes/db_queries.db.php');
include(__DIR__.'/../config/config.inc.php');
require_once(__DIR__.'/../web/classes/approval.class.php');

header('X-Robots-Tag: noindex, nofollow');
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$link = $GLOBALS['__mysql_compat_link'];
appr_ensure_schema();

$src = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
$id = isset($src['id']) ? (int)$src['id'] : 0;
$token = isset($src['t']) ? (string)$src['t'] : '';

$r = $id > 0 ? mysqli_fetch_assoc(mysqli_query($link, "SELECT * FROM `".$dbTables->reservations."` WHERE reservation_id = ".$id." LIMIT 1")) : null;
if ($r && !appr_token_ok($id, $r['reservation_bookingnumber'], $token)) { $r = null; }

// state: invalid | pending | approved | declined | other
$state = 'invalid';
$done = '';
if ($r) {
	if ($r['reservation_approval'] === 'pending' && (int)$r['reservation_hidden'] === 0) { $state = 'pending'; }
	elseif ($r['reservation_approval'] === 'declined' || (int)$r['reservation_hidden'] === 1) { $state = 'declined'; }
	elseif ($r['reservation_approval'] === 'approved') { $state = 'approved'; }
	else { $state = 'other'; }

	if ($_SERVER['REQUEST_METHOD'] === 'POST' && $state === 'pending' && isset($_POST['decision'])) {
		if ($_POST['decision'] === 'approve' && appr_approve($r)) { $done = 'approved'; }
		elseif ($_POST['decision'] === 'decline' && appr_decline($r)) { $done = 'declined'; }
		if ($done !== '') { $state = $done; }
	}
}

$backend_url = '';
$outlet_name = '';
if ($r) {
	$backend_url = appr_base_url().'/web/main_page.php?p=2&selectedDate='.urlencode($r['reservation_date']);
	$o = mysqli_fetch_assoc(mysqli_query($link, "SELECT outlet_name FROM `".$dbTables->outlets."` WHERE outlet_id = ".(int)$r['reservation_outlet_id']." LIMIT 1"));
	$outlet_name = $o ? $o['outlet_name'] : '';
}
$self = 'request.php';
$weekdays = array('Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag');
$date_txt = $r ? mb_substr($weekdays[(int)date('w', strtotime($r['reservation_date']))], 0, 2).', '.date('d.m.Y', strtotime($r['reservation_date'])) : '';
$time_txt = $r ? date('H:i', strtotime($r['reservation_time'])).' Uhr' : '';
$tel = $r ? preg_replace('/[^\d+]/', '', $r['reservation_guest_phone']) : '';
if (substr($tel, 0, 2) === '00') { $tel = '+'.substr($tel, 2); } elseif (substr($tel, 0, 1) === '0') { $tel = '+49'.substr($tel, 1); }

$titles = array(
	'invalid' => 'Link ungültig',
	'pending' => 'Anfrage ansehen',
	'approved' => $done === 'approved' ? 'Bestätigt' : 'Bereits bestätigt',
	'declined' => $done === 'declined' ? 'Abgelehnt' : 'Nicht mehr offen',
	'other' => 'Reservierung',
);
$texts = array(
	'invalid' => 'Dieser Link passt zu keiner Reservierung. Bitte öffne die Anfrage direkt im Backend.',
	'pending' => 'Die Plätze sind für diese Anfrage geblockt. Deine Entscheidung löst automatisch die passende Mail an den Gast aus.',
	'approved' => $done === 'approved' ? 'Der Gast hat seine Bestätigung per Mail erhalten und der Tisch wird zugewiesen.' : 'Diese Anfrage wurde bereits bestätigt, hier ist nichts mehr zu tun.',
	'declined' => $done === 'declined' ? 'Der Gast wurde per Mail informiert, die Plätze sind wieder frei.' : 'Diese Anfrage wurde abgelehnt oder die Reservierung storniert. Im Backend kannst du sie über den Status wieder öffnen.',
	'other' => 'Für diese Reservierung ist keine Entscheidung nötig.',
);
$icon_ok = '<path d="M5 12.5 10 17l9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>';
$icon_cal = '<rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 10h16M9 3v4M15 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>';
$icon_err = '<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1" fill="currentColor"/>';
$icon = ($state === 'approved') ? $icon_ok : (($state === 'pending' || $state === 'other') ? $icon_cal : $icon_err);
$icon_class = ($state === 'approved') ? 'is-success' : (($state === 'pending' || $state === 'other') ? 'is-waitlist' : 'is-error');
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow" />
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />
	<title><?php echo $h($titles[$state]); ?><?php echo $outlet_name !== '' ? ' &ndash; '.$h($outlet_name) : ''; ?></title>
</head>
<body>
<div class="booking-shell confirm-shell">
	<div class="confirm-card">
		<div class="confirm-icon <?php echo $icon_class; ?>">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><?php echo $icon; ?></svg>
		</div>
		<h1 class="confirm-title"><?php echo $h($titles[$state]); ?></h1>
		<p class="confirm-text"><?php echo $h($texts[$state]); ?></p>

	<?php if ($r): ?>
		<div class="wizard-summary">
			<div class="summary-item"><span class="summary-label">Datum</span><span class="summary-value"><?php echo $h($date_txt); ?></span></div>
			<div class="summary-item"><span class="summary-label">Uhrzeit</span><span class="summary-value"><?php echo $h($time_txt); ?></span></div>
			<div class="summary-item"><span class="summary-label">Personen</span><span class="summary-value"><?php echo (int)$r['reservation_pax']; ?></span></div>
		</div>
		<div class="wizard-summary" style="display:block;text-align:left;">
			<?php foreach (array(
				'Gast' => $h($r['reservation_guest_name']),
				'Telefon' => $tel !== '' ? '<a href="tel:'.$h($tel).'">'.$h($r['reservation_guest_phone']).'</a>' : '-',
				'E-Mail' => $r['reservation_guest_email'] !== '' ? '<a href="mailto:'.$h($r['reservation_guest_email']).'">'.$h($r['reservation_guest_email']).'</a>' : '-',
				'Buchungsnummer' => $h($r['reservation_bookingnumber']),
			) as $label => $value): ?>
			<div class="summary-item" style="display:flex;flex-direction:row;align-items:baseline;justify-content:space-between;gap:16px;padding:6px 0;"><span class="summary-label"><?php echo $h($label); ?></span><span class="summary-value" style="text-align:right;"><?php echo $value; ?></span></div>
			<?php endforeach; ?>
		</div>
		<?php if (trim($r['reservation_notes']) !== ''): ?>
		<p class="confirm-text"><strong>Notiz des Gastes</strong><br/><?php echo nl2br($h($r['reservation_notes'])); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ($state === 'pending'): ?>
		<form method="post" action="<?php echo $self; ?>" class="confirm-actions">
			<input type="hidden" name="id" value="<?php echo (int)$id; ?>">
			<input type="hidden" name="t" value="<?php echo $h($token); ?>">
			<button type="submit" name="decision" value="approve" class="submit-button">Bestätigen</button>
			<button type="submit" name="decision" value="decline" class="confirm-secondary" style="background:none;border:0;padding:12px 16px;font:inherit;font-size:14px;cursor:pointer;width:100%;">Ablehnen</button>
		</form>
	<?php endif; ?>
	<?php if ($backend_url !== ''): ?>
		<div class="confirm-actions"><a class="confirm-secondary" href="<?php echo $h($backend_url); ?>">Im Backend öffnen</a></div>
	<?php endif; ?>
	</div>
</div>
</body>
</html>
