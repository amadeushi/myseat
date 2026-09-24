<?php
require_once __DIR__ . '/../web/classes/mysql_compat.php'; session_start();

// PHP part of page / business logic
// ** set configuration
	include('../config/config.general.php');
// ** business functions
	require('business.class.php');
// ** database functions
	include('../web/classes/database.class.php');
// ** localization functions
	include('../web/classes/local.class.php');
// ** business functions
	include('../web/classes/business.class.php');
// ** connect to database
	include('../web/classes/connect.db.php');
// ** all database queries
	include('../web/classes/db_queries.db.php');
// ** set configuration
	include('../config/config.inc.php');
// ** get superglobal variables
	include('../web/includes/get_variables.inc.php');
// translate to selected language
	$language = $general['language'];
	if (isset($_GET['lang']) && preg_match('/^[a-z]{2}$/', $_GET['lang'])) {
		$language = $_GET['lang'];
		$_SESSION['lang'] = $language;
	}
	if (empty($_SESSION['lang'])) {
		$_SESSION['lang'] = $language;
	}
	$lang = substr($_SESSION['lang'],0,2);
	translateSite($lang,'../web/');

// texts of this page (de / en)
	$tr = array(
		'de' => array(
			'lookup_title' => 'Reservierung stornieren',
			'lookup_text'  => 'Bitte gib deine Buchungsnummer und die E-Mail-Adresse ein, mit der du reserviert hast.',
			'lookup_btn'   => 'Reservierung suchen',
			'confirm_title'=> 'Reservierung stornieren?',
			'confirm_text' => 'Möchtest du diese Reservierung wirklich stornieren?',
			'confirm_btn'  => 'Ja, stornieren',
			'keep'         => 'Nein, Reservierung behalten',
			'done_title'   => 'Reservierung storniert',
			'done_text'    => 'Deine Reservierung wurde storniert. Wir hoffen, dich bald wieder bei uns zu sehen.',
			'again'        => 'Neue Reservierung',
			'nf_title'     => 'Reservierung nicht gefunden',
			'nf_text'      => 'Zu diesen Angaben gibt es keine aktive Reservierung. Sie wurde eventuell schon storniert. Bitte prüfe Buchungsnummer und E-Mail-Adresse.',
			'contact'      => 'Bei Fragen erreichst du uns direkt per E-Mail:',
			'booknum'      => 'Buchungsnummer',
			'email'        => 'E-Mail',
			'website'      => 'Zurück zur Website',
		),
		'en' => array(
			'lookup_title' => 'Cancel reservation',
			'lookup_text'  => 'Please enter your booking number and the email address you reserved with.',
			'lookup_btn'   => 'Find reservation',
			'confirm_title'=> 'Cancel reservation?',
			'confirm_text' => 'Do you really want to cancel this reservation?',
			'confirm_btn'  => 'Yes, cancel it',
			'keep'         => 'No, keep my reservation',
			'done_title'   => 'Reservation cancelled',
			'done_text'    => 'Your reservation has been cancelled. We hope to see you again soon.',
			'again'        => 'New reservation',
			'nf_title'     => 'Reservation not found',
			'nf_text'      => 'There is no active reservation for these details. It may already have been cancelled. Please check your booking number and email address.',
			'contact'      => 'If you have any questions, reach us directly by email:',
			'booknum'      => 'Booking number',
			'email'        => 'Email',
			'website'      => 'Back to website',
		),
	);
	$t = isset($tr[$lang]) ? $tr[$lang] : $tr['en'];

	$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// input: from the emailed link / the lookup form (GET) or the confirm button (POST)
	$src = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
	$nr    = isset($src['nr'])    ? trim($src['nr'])    : '';
	$email = isset($src['email']) ? trim($src['email']) : '';

// find the active reservation belonging to this booking number + email
	function findActiveReservation($nr, $email) {
		global $dbTables;
		if (!preg_match('/^[A-Za-z0-9]{1,12}$/', $nr) || $email === '') {
			return null;
		}
		$result = query("SELECT `reservation_id`, `reservation_outlet_id`, `reservation_date`, `reservation_time`, `reservation_pax` FROM `$dbTables->reservations` WHERE `reservation_bookingnumber` = '%s' AND `reservation_guest_email` = '%s' AND `reservation_hidden` = '0' LIMIT 1",
			mysql_real_escape_string($nr), mysql_real_escape_string($email));
		$row = mysql_fetch_assoc($result);
		return $row ? $row : null;
	}

	$state = 'lookup';
	$res = null;
	if ($nr !== '' || $email !== '') {
		$res = findActiveReservation($nr, $email);
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cncl_book') {
			if ($res) {
				// cancel only after the explicit confirmation click
				query("UPDATE `$dbTables->reservations` SET `reservation_hidden` = '1' WHERE `reservation_id` = '%d' AND `reservation_hidden` = '0'", (int)$res['reservation_id']);
				if (mysql_affected_rows() >= 1) {
					query("INSERT INTO `$dbTables->res_history` (reservation_id,author) VALUES ('%d','Online-Cancel')", (int)$res['reservation_id']);
					$state = 'done';
				} else {
					$state = 'notfound';
				}
			} else {
				$state = 'notfound';
			}
		} else {
			$state = $res ? 'confirm' : 'notfound';
		}
	}

// visitors arriving from the emailed link have no session yet: derive
// outlet + property from the reservation, else from the first web outlet
	if ($res) {
		$_SESSION['outletID'] = (int)$res['reservation_outlet_id'];
	}
	if (empty($_SESSION['outletID'])) {
		$r = query("SELECT `outlet_id` FROM `$dbTables->outlets` WHERE `webform` = '1' ORDER BY `outlet_id` LIMIT 1");
		$row = mysql_fetch_assoc($r);
		$_SESSION['outletID'] = $row ? (int)$row['outlet_id'] : 1;
	}
	$_SESSION['property'] = querySQL('property_id_outlet');
	$_SESSION['propertyID'] = $_SESSION['property'];
	$prp_info = querySQL('property_info');

// details for the summary
	$outlet_name = '';
	$outlet_id = (int)$_SESSION['outletID'];
	if ($res) {
		$r = query("SELECT `outlet_name` FROM `$dbTables->outlets` WHERE `outlet_id` = '%d' LIMIT 1", $outlet_id);
		$row = mysql_fetch_assoc($r);
		$outlet_name = $row ? $row['outlet_name'] : '';
	}
	$new_url = 'reserve.php'.($outlet_id ? '?outletID='.$outlet_id : '');

	if (strtolower(substr($prp_info['website'],0,4)) == "http") {
		$website = $prp_info['website'];
	} else {
		$website = "http://".$prp_info['website'];
	}
	$contact_email = isset($prp_info['email']) ? $prp_info['email'] : '';
?>
<!DOCTYPE html>
<html lang="<?php echo $h($lang); ?>">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow" />

	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />

	<title><?php echo $h($t['lookup_title']); ?><?php echo $prp_info['name'] ? ' &ndash; '.$prp_info['name'] : ''; ?></title>
</head>
<body>
<div class="booking-shell confirm-shell">
	<div class="confirm-card">
		<?php language_navigation($lang, false); ?>

	<?php if ($state == 'done'): ?>

		<div class="confirm-icon is-success">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5 10 17l9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo $h($t['done_title']); ?></h1>
		<p class="confirm-text"><?php echo $h($t['done_text']); ?></p>
		<div class="confirm-actions">
			<a class="submit-button" href="<?php echo $h($new_url); ?>"><?php echo $h($t['again']); ?></a>
			<a class="confirm-secondary" href="<?php echo $h($website); ?>"><?php echo $h($t['website']); ?></a>
		</div>

	<?php elseif ($state == 'confirm'): ?>

		<div class="confirm-icon is-waitlist">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 10h16M9 3v4M15 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo $h($t['confirm_title']); ?></h1>
		<p class="confirm-text">
			<?php echo $h($t['confirm_text']); ?><br/>
			<?php if ($outlet_name) { echo $h($outlet_name).' &middot; '; } ?><?php echo $h($t['booknum']); ?> <strong><?php echo $h($nr); ?></strong>
		</p>

		<div class="wizard-summary">
			<div class="summary-item">
				<span class="summary-label"><?php echo _date; ?></span>
				<span class="summary-value"><?php echo $h(date($general['dateformat'], strtotime($res['reservation_date']))); ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo _time; ?></span>
				<span class="summary-value"><?php echo $h(formatTime($res['reservation_time'], $general['timeformat'])); ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo ucfirst(_people_); ?></span>
				<span class="summary-value"><?php echo (int)$res['reservation_pax']; ?></span>
			</div>
		</div>

		<form method="post" action="cancel.php" class="confirm-actions">
			<input type="hidden" name="action" value="cncl_book">
			<input type="hidden" name="nr" value="<?php echo $h($nr); ?>">
			<input type="hidden" name="email" value="<?php echo $h($email); ?>">
			<button type="submit" class="submit-button"><?php echo $h($t['confirm_btn']); ?></button>
			<a class="confirm-secondary" href="<?php echo $h($website); ?>"><?php echo $h($t['keep']); ?></a>
		</form>

	<?php else: ?>

		<?php if ($state == 'notfound'): ?>
		<div class="confirm-icon is-error">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo $h($t['nf_title']); ?></h1>
		<p class="confirm-text">
			<?php echo $h($t['nf_text']); ?>
			<?php if ($contact_email): ?>
			<br/><?php echo $h($t['contact']); ?> <a href="mailto:<?php echo $h($contact_email); ?>"><?php echo $h($contact_email); ?></a>
			<?php endif; ?>
		</p>
		<?php else: ?>
		<div class="confirm-icon is-waitlist">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M4 10h16M9 3v4M15 3v4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo $h($t['lookup_title']); ?></h1>
		<p class="confirm-text"><?php echo $h($t['lookup_text']); ?></p>
		<?php endif; ?>

		<form method="get" action="cancel.php" class="wizard-step">
			<div class="field">
				<label for="nr"><?php echo $h($t['booknum']); ?></label>
				<input type="text" name="nr" id="nr" value="<?php echo $h($nr); ?>" autocapitalize="off" autocomplete="off" required />
			</div>
			<div class="field">
				<label for="email"><?php echo $h($t['email']); ?></label>
				<input type="text" name="email" id="email" value="<?php echo $h($email); ?>" inputmode="email" autocapitalize="off" autocomplete="email" required />
			</div>
			<div class="confirm-actions">
				<button type="submit" class="submit-button"><?php echo $h($t['lookup_btn']); ?></button>
				<a class="confirm-secondary" href="<?php echo $h($website); ?>"><?php echo $h($t['website']); ?></a>
			</div>
		</form>

	<?php endif; ?>
	</div>
	<?php include __DIR__.'/legal_footer.php'; ?>
</div>
</body>
</html>
