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
	include_once('../web/classes/online_block.class.php');
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
    $date_ok = function($d) { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
    if ($date_ok($_GET['selectedDate'] ?? null)) {
        $_SESSION['selectedDate'] = $_GET['selectedDate'];
    }elseif ($date_ok($_POST['selectedDate'] ?? null)) {
        $_SESSION['selectedDate'] = $_POST['selectedDate'];
    }elseif ($date_ok($_POST['dbdate'] ?? null)) {
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
    $bookingtime = formatTimeGuest($_POST['reservation_time'], isset($_POST['email_type']) ? $_POST['email_type'] : 'en');
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
		// the day was blocked for online bookings in the backend (closed party, sold out)
		!ob_is_blocked($_SESSION['outletID'], $_SESSION['selectedDate']) &&
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

  // newsletter, one more chance in the warm moment after the booking (a button, not a pre-set tick): only for a guest who is not on the list yet.
  // The address comes from the booking and stays in the session, the endpoint only ever asks this one.
  $nlBox = '';
  if (($waitlist == 2 || $waitlist == 3) && filter_var(trim((string)(isset($_POST['reservation_guest_email']) ? $_POST['reservation_guest_email'] : '')), FILTER_VALIDATE_EMAIL)) {
	try {
		require_once(__DIR__.'/../web/classes/shop.class.php');
		$nlEmail = trim((string)$_POST['reservation_guest_email']); $nlSt = shop_nl_state($nlEmail);
		$nlDe = isset($_POST['reservation_email_lang']) && substr((string)$_POST['reservation_email_lang'], 0, 2) === 'de';
		if (!$nlSt['news'] && shop_mail_from() !== '') {
			$_SESSION['nl_widget'] = array('email' => $nlEmail, 'name' => stripslashes(trim((string)(isset($_POST['reservation_guest_name']) ? $_POST['reservation_guest_name'] : ''))), 'lang' => $nlDe ? 'de' : 'en', 'text' => shop_nl_consent_text('news', $nlDe ? 'de' : 'en'));
			$nlBox = (($_POST['reservation_advertise'] ?? '') === 'YES' || $nlSt['want_news']) ? 'pending' : 'ask';
		}
	} catch (Throwable $e) { error_log('mySeat newsletter (widget after): '.$e->getMessage()); }
  }
  function nl_after_box($mode) {
	if ($mode === '') { return; }
	$de = isset($_SESSION['nl_widget']) && $_SESSION['nl_widget']['lang'] === 'de';
	$mail = preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', strtolower(trim((string)$_SESSION['nl_widget']['email'])));
	$t = $de ? array('ask_h' => 'Ab und zu Neuigkeiten vom Restaurant?', 'ask_p' => 'Neue Gerichte, Aktionen, Öffnungszeiten: ab und zu eine Mail. Du bestätigst es einmal per Mail, abmelden geht jederzeit.', 'yes' => 'Ja, gerne', 'no' => 'Nein danke',
		'p_h' => 'Fast fertig', 'p_p' => 'Wir haben dir eine Mail an <strong>'.htmlspecialchars($mail).'</strong> geschickt. Tippe dort auf „Anmeldung bestätigen“, erst dann gilt es. Schau auch im Spam-Ordner nach.', 'again' => 'Mail erneut senden', 'sent' => 'Die Mail ist unterwegs.',
		'bye' => 'Alles klar, wir fragen nicht noch einmal.', 'err' => 'Das hat nicht geklappt. Bitte versuche es noch einmal.', 'net' => 'Keine Verbindung. Bitte versuche es noch einmal.')
		: array('ask_h' => 'News from the restaurant now and then?', 'ask_p' => 'New dishes, offers, opening times: an e-mail from time to time. You confirm it once by e-mail and can unsubscribe at any time.', 'yes' => 'Yes, please', 'no' => 'No thanks',
		'p_h' => 'Almost done', 'p_p' => 'We sent an e-mail to <strong>'.htmlspecialchars($mail).'</strong>. Tap "Confirm sign-up" in it, only then does it count. Please check your spam folder too.', 'again' => 'Send e-mail again', 'sent' => 'The e-mail is on its way.',
		'bye' => 'All right, we will not ask again.', 'err' => 'That did not work. Please try again.', 'net' => 'No connection. Please try again.');
	echo '<section class="nl-after" id="nl-after" data-mode="'.htmlspecialchars($mode).'" data-barrier="'.htmlspecialchars($_SESSION['barrier']).'" data-t="'.htmlspecialchars(json_encode($t)).'" aria-live="polite"></section>';
	echo '<script>(function(){var b=document.getElementById("nl-after"),T=JSON.parse(b.dataset.t),busy=false;'
		.'function ask(){b.innerHTML="<h2>"+T.ask_h+"</h2><p>"+T.ask_p+"</p><div class=\"nl-after-btns\"><button type=\"button\" class=\"nl-after-btn is-yes\" data-yes>"+T.yes+"</button><button type=\"button\" class=\"nl-after-btn\" data-no>"+T.no+"</button></div>";}'
		.'function pend(){b.innerHTML="<h2>"+T.p_h+"</h2><p>"+T.p_p+"</p><div class=\"nl-after-btns\"><button type=\"button\" class=\"nl-after-btn\" data-again>"+T.again+"</button></div><p class=\"nl-fine\" data-state role=\"status\"></p>";}'
		.'function send(again){if(busy){return;}busy=true;var f=new FormData();f.append("barrier",b.dataset.barrier);fetch("newsletter_ask.php",{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(r){busy=false;'
		.'if(!r.ok){var s=b.querySelector("[data-state]");if(again&&s){s.textContent=r.error||T.err;}else{b.insertAdjacentHTML("beforeend","<p class=\"nl-fine\" role=\"alert\">"+(r.error||T.err).replace(/</g,"&lt;")+"</p>");}return;}'
		.'if(again){var st=b.querySelector("[data-state]");if(st){st.textContent=T.sent;}}else{pend();}}).catch(function(){busy=false;var s=b.querySelector("[data-state]");if(s){s.textContent=T.net;}else{b.insertAdjacentHTML("beforeend","<p class=\"nl-fine\" role=\"alert\">"+T.net+"</p>");}});}'
		.'b.addEventListener("click",function(e){if(e.target.closest("[data-yes]")){send(false);}else if(e.target.closest("[data-no]")){b.innerHTML="<p class=\"nl-fine\">"+T.bye+"</p>";}else if(e.target.closest("[data-again]")){send(true);}});'
		.'if(b.dataset.mode==="pending"){pend();}else{ask();}})();</script>';
  }
?>

<!DOCTYPE html>
<html lang="<?php echo $language; ?>">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

	<!-- CSS - Setup -->
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />

    <title><?php echo _reservations;?> &ndash; <?php echo htmlspecialchars($outlet_name); ?></title>
<link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png"><link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body onLoad="window.parent.scroll(0,0);">
<div class="booking-shell confirm-shell">
	<div class="confirm-card">
	<?php if ($waitlist == 2): ?>

		<div class="confirm-icon is-success">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5 10 17l9-10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo bt('confirmed'); ?></h1>
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

		<?php nl_after_box($nlBox); ?>

		<div class="confirm-actions">
			<a class="submit-button" href="<?php echo $website; ?>"><?php echo bt('back_site'); ?></a>
			<a class="confirm-secondary" href="cancel.php?nr=<?php echo urlencode($_SESSION['booking_number']); ?>&amp;email=<?php echo urlencode($_POST['reservation_guest_email']); ?>"><?php echo bt('cancel_res'); ?></a>
		</div>

	<?php elseif ($waitlist == 3): ?>

		<div class="confirm-icon is-waitlist">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo bt('pending_title'); ?></h1>
		<p class="confirm-text"><?php echo bt('pending_text'); ?> <strong><?php echo htmlspecialchars($_SESSION['booking_number']); ?></strong></p>

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

		<?php nl_after_box($nlBox); ?>

		<div class="confirm-actions">
			<a class="submit-button" href="<?php echo $website; ?>"><?php echo bt('back_site'); ?></a>
		</div>

	<?php elseif ($waitlist == 1): ?>

		<div class="confirm-icon is-waitlist">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo _wait_list; ?></h1>
		<p class="confirm-text"><?php echo bt('waitlist_text'); ?></p>

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
			<a class="submit-button" href="<?php echo $website; ?>"><?php echo bt('back_site'); ?></a>
		</div>

	<?php else: ?>

		<div class="confirm-icon is-error">
			<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 8v5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="16" r="1" fill="currentColor"/></svg>
		</div>
		<h1 class="confirm-title"><?php echo _sorry; ?></h1>
		<p class="confirm-text">
			<?php echo bt('error_text'); ?>
			<?php if ($contact_email): ?>
			<?php echo bt('error_retry'); ?><br/><a href="mailto:<?php echo htmlspecialchars($contact_email); ?>"><?php echo htmlspecialchars($contact_email); ?></a>
			<?php endif; ?>
		</p>

		<div class="confirm-actions">
			<a class="submit-button" href="reserve.php?outletID=<?php echo (int)$_SESSION['outletID']; ?>"><?php echo bt('retry'); ?></a>
		</div>

	<?php endif; ?>
	</div>
	<?php include __DIR__.'/legal_footer.php'; ?>
</div>

</body>
</html>