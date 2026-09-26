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
// ** cancel links (token check, phone comparison)
	require_once('../web/classes/cancel_link.class.php');
// ** shared guest info (menus, arrival, parking ...), the same texts as in the confirmation mail
	require_once('../web/classes/booking_mail.class.php');
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
			'lookup_text'  => 'Bitte gib deine Buchungsnummer und die E-Mail-Adresse oder Mobilnummer ein, mit der du reserviert hast.',
			'lookup_btn'   => 'Reservierung suchen',
			'confirm_title'=> 'Reservierung stornieren?',
			'confirm_text' => 'Möchtest du diese Reservierung wirklich stornieren?',
			'confirm_btn'  => 'Ja, stornieren',
			'keep'         => 'Nein, Reservierung behalten',
			'done_title'   => 'Reservierung storniert',
			'done_text'    => 'Deine Reservierung wurde storniert. Wir hoffen, dich bald wieder bei uns zu sehen.',
			'again'        => 'Neue Reservierung',
			'nf_title'     => 'Reservierung nicht gefunden',
			'nf_text'      => 'Zu diesen Angaben gibt es keine aktive Reservierung. Sie wurde eventuell schon storniert. Bitte prüfe Buchungsnummer und E-Mail-Adresse oder Mobilnummer.',
			'contact'      => 'Bei Fragen erreichst du uns direkt per E-Mail:',
			'booknum'      => 'Buchungsnummer',
			'email'        => 'E-Mail oder Mobilnummer',
			'website'      => 'Zurück zur Website',
			'title_ok'     => 'Dein Tisch ist reserviert',
			'lead_ok'      => 'Wir freuen uns auf dich. Hier findest du alles für deinen Besuch.',
			'title_pend'   => 'Deine Anfrage',
			'lead_pend'    => 'Wir melden uns so schnell wie möglich mit einer festen Zusage.',
			'date'         => 'Datum',
			'time'         => 'Uhrzeit',
			'guests'       => 'Personen',
			'note'         => 'Deine Anmerkung',
			'contact_h'    => 'Fragen oder Änderungen?',
			'contact_t'    => 'Ruf uns an oder schreib uns, wir helfen gern.',
			'cancel_h'     => 'Doch verhindert?',
			'cancel_t'     => 'Sag uns bitte Bescheid, dann kann jemand anderes deinen Platz bekommen.',
			'cancel_open'  => 'Reservierung stornieren',
			'cancel_open_p'=> 'Anfrage zurückziehen',
			'past'         => 'Diese Reservierung liegt in der Vergangenheit und kann nicht mehr storniert werden.',
			'title_past'   => 'Deine Reservierung',
			'clock'        => ' Uhr',
		),
		'en' => array(
			'lookup_title' => 'Cancel reservation',
			'lookup_text'  => 'Please enter your booking number and the email address or mobile number you reserved with.',
			'lookup_btn'   => 'Find reservation',
			'confirm_title'=> 'Cancel reservation?',
			'confirm_text' => 'Do you really want to cancel this reservation?',
			'confirm_btn'  => 'Yes, cancel it',
			'keep'         => 'No, keep my reservation',
			'done_title'   => 'Reservation cancelled',
			'done_text'    => 'Your reservation has been cancelled. We hope to see you again soon.',
			'again'        => 'New reservation',
			'nf_title'     => 'Reservation not found',
			'nf_text'      => 'There is no active reservation for these details. It may already have been cancelled. Please check your booking number and email address or mobile number.',
			'contact'      => 'If you have any questions, reach us directly by email:',
			'booknum'      => 'Booking number',
			'email'        => 'Email or mobile number',
			'website'      => 'Back to website',
			'title_ok'     => 'Your table is reserved',
			'lead_ok'      => 'We are looking forward to seeing you. Everything for your visit is here.',
			'title_pend'   => 'Your request',
			'lead_pend'    => 'We will get back to you as soon as possible with a firm answer.',
			'date'         => 'Date',
			'time'         => 'Time',
			'guests'       => 'Guests',
			'note'         => 'Your note',
			'contact_h'    => 'Questions or changes?',
			'contact_t'    => 'Call us or write to us, we are happy to help.',
			'cancel_h'     => 'Cannot make it?',
			'cancel_t'     => 'Please let us know so someone else can take your seat.',
			'cancel_open'  => 'Cancel reservation',
			'cancel_open_p'=> 'Withdraw request',
			'past'         => 'This reservation is in the past and can no longer be cancelled.',
			'title_past'   => 'Your reservation',
			'clock'        => '',
		),
	);
	$t = isset($tr[$lang]) ? $tr[$lang] : $tr['en'];

	$h = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// input: from the emailed link / the lookup form (GET) or the confirm button (POST)
	$src = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : $_GET;
	$nr    = isset($src['nr'])    ? trim($src['nr'])    : '';
	$email = isset($src['email']) ? trim($src['email']) : '';   // e-mail address or mobile number
	$token = isset($src['t']) && is_string($src['t']) && preg_match('/^[a-f0-9]{24}$/', $src['t']) ? $src['t'] : '';

// find the active reservation belonging to this booking number + (signed token | e-mail | mobile number)
	function findActiveReservation($nr, $email, $token = '') {
		global $dbTables;
		if (!preg_match('/^[A-Za-z0-9]{1,12}$/', $nr) || ($email === '' && $token === '')) {
			return null;
		}
		$result = query("SELECT * FROM `$dbTables->reservations` WHERE `reservation_bookingnumber` = '%s' AND `reservation_hidden` = '0'",
			mysql_real_escape_string($nr));
		while ($row = mysql_fetch_assoc($result)) {
			if ($token !== '' && cl_token_ok($row['reservation_id'], $nr, $token)) { return $row; }
			if ($email === '') { continue; }
			if (cl_is_email($email)) {
				if (strcasecmp($row['reservation_guest_email'], $email) === 0) { return $row; }
			} else {
				$k = cl_phone_key($email);
				if ($k !== '' && $k === cl_phone_key($row['reservation_guest_phone'])) { return $row; }
			}
		}
		return null;
	}

	$state = 'lookup';
	$res = null;
	if ($nr !== '' || $email !== '' || $token !== '') {
		$res = findActiveReservation($nr, $email, $token);
		if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cncl_book') {
			if ($res && $res['reservation_date'] < date('Y-m-d')) {
				$state = 'view';   // a reservation of the past is not cancelled any more
			} elseif ($res) {
				// cancel only after the explicit confirmation click
				query("UPDATE `$dbTables->reservations` SET `reservation_hidden` = '1', `reservation_status` = 'CXL', `reservation_timestamp` = now() WHERE `reservation_id` = '%d' AND `reservation_hidden` = '0'", (int)$res['reservation_id']);
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
			$state = $res ? 'view' : 'notfound';
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

// the guest page: facts of the reservation plus what the confirmation mail says (menus, arrival, contact)
	if ($state == 'view') {
		$contact_phone = !empty($settings['mailPhone']) ? $settings['mailPhone'] : (!empty($prp_info['phone']) ? $prp_info['phone'] : '');
		$gi = bm_guest_info($lang, $contact_phone, $prp_info);
		$is_pending = (isset($res['reservation_approval']) && $res['reservation_approval'] === 'pending');
		$is_past = ($res['reservation_date'] < date('Y-m-d'));
		$brand = $outlet_name !== '' ? $outlet_name : $prp_info['name'];
		$when = bm_weekday($res['reservation_date'], $lang).', '.date($general['dateformat'], strtotime($res['reservation_date']));
		$at = formatTime($res['reservation_time'], $general['timeformat']).$t['clock'];
		$tel_digits = preg_replace('/\D/', '', (string)$contact_phone);
		$tel_href = strlen($tel_digits) >= 6 ? 'tel:'.(substr($tel_digits, 0, 1) === '0' ? '+49'.substr($tel_digits, 1) : '+'.$tel_digits) : '';
		$page_title = $is_past ? $t['title_past'] : ($is_pending ? $t['title_pend'] : $t['title_ok']);
	}
?>
<!DOCTYPE html>
<html lang="<?php echo $h($lang); ?>">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex,nofollow" />

	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />

	<title><?php echo $h($state == 'view' ? $page_title : $t['lookup_title']); ?><?php echo $prp_info['name'] ? ' &ndash; '.$prp_info['name'] : ''; ?></title>
</head>
<body>
<div class="booking-shell <?php echo $state == 'view' ? 'guest-shell' : 'confirm-shell'; ?>">
	<div class="<?php echo $state == 'view' ? 'guest-page' : 'confirm-card'; ?>">
		<div class="brand-row"><?php echo brand_logo_html($prp_info['name']); ?><?php language_navigation($lang, false); ?></div>

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

	<?php elseif ($state == 'view'): ?>

		<header class="guest-head">
			<h1 class="guest-title"><?php echo $h($page_title); ?></h1>
			<p class="guest-lead"><?php echo $h($is_past ? $t['past'] : ($is_pending ? $t['lead_pend'] : $t['lead_ok'])); ?></p>
			<?php if (!$is_past): ?><a class="guest-jump" href="#g-cancel"><?php echo $h($is_pending ? $t['cancel_open_p'] : $t['cancel_open']); ?></a><?php endif; ?>
		</header>

		<dl class="guest-facts">
			<div class="guest-when"><dt><?php echo $h($t['date']); ?></dt><dd><?php echo $h($when); ?></dd></div>
			<div><dt><?php echo $h($t['time']); ?></dt><dd><?php echo $h($at); ?></dd></div>
			<div><dt><?php echo $h($t['guests']); ?></dt><dd><?php echo (int)$res['reservation_pax']; ?></dd></div>
			<div class="guest-nr"><dt><?php echo $h($t['booknum']); ?></dt><dd class="guest-number"><?php echo $h($nr); ?></dd></div>
			<?php if (trim((string)$res['reservation_notes']) !== ''): ?>
			<div class="guest-note"><dt><?php echo $h($t['note']); ?></dt><dd><?php echo nl2br($h(trim($res['reservation_notes']))); ?></dd></div>
			<?php endif; ?>
		</dl>

		<?php if (!$is_pending && !$is_past): ?>
		<section class="guest-section" aria-labelledby="g-menu">
			<h2 id="g-menu"><?php echo $h(rtrim($gi['menu_t'], ':')); ?></h2>
			<div class="guest-buttons">
				<?php foreach ($gi['menu_links'] as $label => $url): ?><a class="guest-btn" href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><?php echo $h($label); ?></a><?php endforeach; ?>
			</div>
		</section>

		<section class="guest-section" aria-labelledby="g-info">
			<h2 id="g-info"><?php echo $h($gi['info_h']); ?></h2>
			<dl class="guest-info">
				<?php if ($gi['route_url'] !== ''): ?>
				<div><dt><?php echo $h($gi['addr_l']); ?></dt><dd><?php echo $h($gi['addr_plain']); ?><br/><a href="<?php echo $h($gi['route_url']); ?>" target="_blank" rel="noopener"><?php echo $h($gi['route_l']); ?></a></dd></div>
				<?php endif; ?>
				<?php foreach ($gi['info'] as $label => $text): if ($label === $gi['bus_key']): ?>
				<div><dt><?php echo $h($label); ?></dt><dd><?php echo $h($text); ?><br/><a href="<?php echo $h($gi['bus_url']); ?>" target="_blank" rel="noopener"><?php echo $h($gi['bus_l']); ?></a></dd></div>
				<?php endif; endforeach; ?>
			</dl>
			<?php foreach ($gi['info'] as $label => $text): if ($label !== $gi['bus_key']): ?>
			<details class="guest-fold">
				<summary><?php echo $h($label); ?></summary>
				<p><?php echo $h($text); ?></p>
			</details>
			<?php endif; endforeach; ?>
		</section>
		<?php endif; ?>

		<section class="guest-section" aria-labelledby="g-contact">
			<h2 id="g-contact"><?php echo $h($t['contact_h']); ?></h2>
			<p class="guest-text"><?php echo $h($t['contact_t']); ?></p>
			<p class="guest-contact">
				<?php if ($tel_href !== ''): ?><a href="<?php echo $h($tel_href); ?>"><?php echo $h($contact_phone); ?></a><?php endif; ?>
				<?php if ($contact_email): ?><a href="mailto:<?php echo $h($contact_email); ?>"><?php echo $h($contact_email); ?></a><?php endif; ?>
			</p>
		</section>

		<?php if (!$is_past): ?>
		<section class="guest-section guest-cancel" aria-labelledby="g-cancel">
			<h2 id="g-cancel"><?php echo $h($t['cancel_h']); ?></h2>
			<p class="guest-text"><?php echo $h($t['cancel_t']); ?></p>
			<details class="guest-cancel-box">
				<summary><?php echo $h($is_pending ? $t['cancel_open_p'] : $t['cancel_open']); ?></summary>
				<form method="post" action="cancel.php">
					<p class="guest-text"><?php echo $h($t['confirm_text']); ?></p>
					<input type="hidden" name="action" value="cncl_book">
					<input type="hidden" name="nr" value="<?php echo $h($nr); ?>">
					<input type="hidden" name="email" value="<?php echo $h($email); ?>">
					<?php if ($token !== ''): ?><input type="hidden" name="t" value="<?php echo $h($token); ?>"><?php endif; ?>
					<button type="submit" class="guest-danger"><?php echo $h($t['confirm_btn']); ?></button>
				</form>
			</details>
		</section>
		<?php endif; ?>

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
