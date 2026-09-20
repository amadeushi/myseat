<?php session_start();

// reset single outlet indicator
$_SESSION['single_outlet'] = 'OFF';

$_SESSION['role'] = 6;
$_SESSION['language'] = 'en_EN';

// PHP part of page / business logic
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
// translate to selected language
	translateSite($_POST['email_type'],'../web/');
// ** get superglobal variables
	include('../web/includes/get_variables.inc.php');
// ** php hooks class
	include_once "../web/classes/phphooks.config.php";
	include_once "../web/classes/phphooks.class.php";
	$plugin_path = '../plugins/';
	//create instance of plugin class
	include "../config/plugins.init.php";
// ** get property info for logo path
$prp_info = querySQL('property_info');

// Get POST data	
   // outlet id
    if (!$_SESSION['outletID']) {
	$_SESSION['outletID'] = ($_GET['outletID']) ? (int)$_GET['outletID'] : querySQL('web_standard_outlet');
    }elseif ($_GET['id']) {
        $_SESSION['outletID'] = (int)$_GET['id'];
    }elseif ($_POST['id']) {
        $_SESSION['outletID'] = (int)$_POST['id'];
    }
    // property id
    if ($_GET['prp']) {
        $_SESSION['property'] = (int)$_GET['prp'];
    }elseif ($_POST['prp']) {
        $_SESSION['property'] = (int)$_POST['prp'];
    }
    // selected date
    if ($_GET['selectedDate']) {
        $_SESSION['selectedDate'] = $_GET['selectedDate'];
    }elseif ($_POST['selectedDate']) {
        $_SESSION['selectedDate'] = $_POST['selectedDate'];
    }elseif ($_POST['dbdate']) {
        $_SESSION['selectedDate'] = $_POST['dbdate'];
    }elseif (!$_SESSION['selectedDate']){
        //$_SESSION['selectedDate'] = date('Y-m-d');
    }

  //prepare selected Date
    list($sy,$sm,$sd) = explode("-",$_SESSION['selectedDate']);
  
  // get Pax by timeslot
    $resbyTime = reservationsByTime();
  // get availability by timeslot
    $availability = getAvailability($resbyTime,$general['timeintervall']); 
 // some constants
    $bookingdate = date($general['dateformat'],strtotime($_POST['dbdate']));
    $bookingtime = formatTime($_POST['reservation_time'],$general['timeformat']);
    $outlet_name = querySQL('db_outlet');
    //$_SESSION['booking_number'] = '';
  
  //The subject of the confirmation email
  $subject = $lang["email_subject"]." ".$outlet_name;
  //Email address of the confirmation email
  $mailTo = $_POST['reservation_guest_email'];

  // =-=-=-=-=-=-=-=-=-=-=
  //  Process the Booking
  // =-=-=-=-=-=-=-=-=-=-=
  // CSRF - Secure forms with token
  if ($_SESSION['barrier'] == $_POST['barrier']) {
	// get day off days
	// (returns '0' = open OR '1' = dayoff)
	$dayoff = getDayoff();
	// double check if no neccessary field is empty
	if (isset($_POST['dbdate']) &&
		isset($_POST['reservation_pax']) &&
		isset($_POST['reservation_time']) &&
		isset($_POST['reservation_guest_name']) &&
		isset($_POST['reservation_guest_email']) &&
		// the terms at the reservation form must have been aceppted
		$_POST['terms'] == 'YES' &&
		$dayoff == 0 &&
		// no bookings later than the configured last-booking time before closing
		!isPastLastBooking($_SESSION['selectedDate'], $_POST['reservation_time'])
	) {
		// <Do booking>
		$waitlist = processBooking();
	}else{
		$waitlist = 0;
	}
  }
  // CSRF - Secure forms with token
  $barrier = md5(uniqid(rand(), true));
  $_SESSION['barrier'] = $barrier;

  // outlet website, for the "back to site" link (same pattern as reserve.php)
  if (strtolower(substr($prp_info['website'],0,4)) =="http") {
	$website = $prp_info['website'];
  }else{
	$website = "http://".$prp_info['website'];
  }
  $contact_email = isset($prp_info['email']) ? $prp_info['email'] : '';

  $_SESSION['messages'] = array();
?>

<!DOCTYPE html>
<html lang="<?php echo $language; ?>">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

	<!-- CSS - Setup -->
	<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;500;600&family=Raleway:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />

    <title><?php echo _reservations;?> &ndash; <?php echo htmlspecialchars($outlet_name); ?></title>
</head>
<body onLoad="window.parent.scroll(0,0);">
<div class="booking-shell confirm-shell">
	<div class="confirm-card">
	<?php if ($waitlist == 2): ?>

		<div class="confirm-icon is-success">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5 10 17l9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title">Reservierung bestätigt</h1>
		<p class="confirm-text"><?php echo _contact_form_success; ?> <strong><?php echo htmlspecialchars($_SESSION['booking_number']); ?></strong></p>

		<div class="wizard-summary">
			<div class="summary-item">
				<span class="summary-label"><?php echo _date;?></span>
				<span class="summary-value"><?php echo buildDate($general['dateformat'],$sd,$sm,$sy); ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo _time;?></span>
				<span class="summary-value"><?php echo $bookingtime; ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo ucfirst(_people_);?></span>
				<span class="summary-value"><?php echo (int)$_POST['reservation_pax']; ?></span>
			</div>
		</div>

		<div class="confirm-actions">
			<a class="submit-button" href="<?php echo $website; ?>">Zurück zur Website</a>
		</div>

	<?php elseif ($waitlist == 1): ?>

		<div class="confirm-icon is-waitlist">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo _wait_list; ?></h1>
		<p class="confirm-text">Für Ihren Wunschtermin sind aktuell keine Tische mehr frei. Wir haben Sie auf die Warteliste gesetzt und melden uns, sobald ein Platz frei wird.</p>

		<div class="wizard-summary">
			<div class="summary-item">
				<span class="summary-label"><?php echo _date;?></span>
				<span class="summary-value"><?php echo buildDate($general['dateformat'],$sd,$sm,$sy); ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo _time;?></span>
				<span class="summary-value"><?php echo $bookingtime; ?></span>
			</div>
			<div class="summary-item">
				<span class="summary-label"><?php echo ucfirst(_people_);?></span>
				<span class="summary-value"><?php echo (int)$_POST['reservation_pax']; ?></span>
			</div>
		</div>

		<div class="confirm-actions">
			<a class="submit-button" href="<?php echo $website; ?>">Zurück zur Website</a>
		</div>

	<?php else: ?>

		<div class="confirm-icon is-error">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo _sorry; ?></h1>
		<p class="confirm-text">
			Ihre Reservierung konnte leider nicht angelegt werden.
			<?php if ($contact_email): ?>
			Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt per E-Mail: <a href="mailto:<?php echo $contact_email; ?>"><?php echo $contact_email; ?></a>
			<?php endif; ?>
		</p>

		<div class="confirm-actions">
			<a class="submit-button" href="reserve.php?outletID=<?php echo (int)$_SESSION['outletID']; ?>">Erneut versuchen</a>
		</div>

	<?php endif; ?>
	</div>
</div>

</body>
</html>