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
	$t = $de ? array('ask_h' => 'Ab und zu Neuigkeiten vom Restaurant?', 'ask_p' => 'Neue Gerichte, Aktionen, Öffnungszeiten: höchstens 2 Mails im Monat. Du bestätigst es einmal per Mail, abmelden geht jederzeit.', 'yes' => 'Ja, gerne', 'no' => 'Nein danke',
		'p_h' => 'Fast fertig', 'p_p' => 'Wir haben dir eine Mail an <strong>'.htmlspecialchars($mail).'</strong> geschickt. Tippe dort auf „Anmeldung bestätigen“, erst dann gilt es. Schau auch im Spam-Ordner nach.', 'again' => 'Mail erneut senden', 'sent' => 'Die Mail ist unterwegs.',
		'bye' => 'Alles klar, wir fragen nicht noch einmal.', 'err' => 'Das hat nicht geklappt. Bitte versuche es noch einmal.', 'net' => 'Keine Verbindung. Bitte versuche es noch einmal.')
		: array('ask_h' => 'News from the restaurant now and then?', 'ask_p' => 'New dishes, offers, opening times: at most two e-mails a month. You confirm it once by e-mail and can unsubscribe at any time.', 'yes' => 'Yes, please', 'no' => 'No thanks',
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

  // ---- the final screen: what the guest has to know now (when, what happens next, how to arrive) and a quiet way to keep the date ----
  // One screen for the three answers of the booking (2 = confirmed, 3 = request that staff decides on, 1 = waiting list); the error screen stays below.
  function done_months($de) { return $de ? array('', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember') : array('', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'); }
  function done_screen($mode, $c) {
	$de = $c['de']; $h = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
	$first = $c['first']; $days = $c['days']; $n = $first !== '' ? $first : '';
	$rel = $days <= 0 ? ($de ? 'Heute' : 'Today') : ($days == 1 ? ($de ? 'Morgen' : 'Tomorrow') : ($de ? 'In '.$days.' Tagen' : 'In '.$days.' days'));
	$sms = $c['mobile'] ? ($de ? ' und per SMS' : ' and by text message') : '';
	if ($mode == 2) {
		$eyebrow = $de ? 'Reserviert' : 'Booked';
		$title = $days <= 0 ? ($de ? 'Bis später'.($n !== '' ? ', '.$n : '').'.' : 'See you later'.($n !== '' ? ', '.$n : '').'.')
			: ($days == 1 ? ($de ? 'Bis morgen'.($n !== '' ? ', '.$n : '').'.' : 'See you tomorrow'.($n !== '' ? ', '.$n : '').'.')
			: ($de ? ($n !== '' ? 'Danke, '.$n.'. Dein Tisch wartet.' : 'Dein Tisch wartet.') : ($n !== '' ? 'Thank you, '.$n.'. Your table is waiting.' : 'Your table is waiting.')));
		$lead = $de ? $rel.' ist es so weit. Wir freuen uns auf dich.' : $rel.' it is time. We look forward to seeing you.';
		$steps = array(array($de ? 'Jetzt' : 'Now', ($de ? 'Die Bestätigung ist unterwegs an ' : 'The confirmation is on its way to ').$c['mail'].'.'));
		if ($days >= 2 || ($days == 1 && $c['hour'] < 20)) { $steps[] = array($days >= 2 ? ($de ? 'Am Vortag' : 'The day before') : ($de ? 'Noch heute' : 'Today'), ($de ? 'Wir erinnern dich, mit allem für die Anfahrt' : 'We remind you, with everything for your trip').$sms.'.'); }
		$steps[] = array($days <= 0 ? ($de ? 'Heute' : 'Today') : ($days == 1 ? ($de ? 'Morgen' : 'Tomorrow') : $c['weekday']), $de ? 'Dein Tisch, unsere Küche. Wir freuen uns.' : 'Your table, our kitchen. We look forward to it.');
	} elseif ($mode == 3) {
		$eyebrow = $de ? 'Anfrage' : 'Request';
		$title = $de ? ($n !== '' ? 'Danke, '.$n.'. Deine Anfrage ist bei uns.' : 'Deine Anfrage ist bei uns.') : ($n !== '' ? 'Thank you, '.$n.'. Your request is with us.' : 'Your request is with us.');
		$lead = $de ? 'Noch ist nichts fest, das sagen wir dir lieber ehrlich. Wir antworten dir innerhalb von 24 Stunden per Mail.' : 'Nothing is final yet, and we would rather tell you honestly. We will answer by e-mail within 24 hours.';
		$steps = array(array($de ? 'Jetzt' : 'Now', ($de ? 'Deine Anfrage ist bei uns, eine Mail ist unterwegs an ' : 'Your request is with us, an e-mail is on its way to ').$c['mail'].'.'),
			array($de ? 'Innerhalb von 24 Stunden' : 'Within 24 hours', $de ? 'Wir antworten dir persönlich.' : 'We answer you personally.'),
			array($de ? 'Dann' : 'Then', $de ? 'Mit unserer Zusage ist dein Tisch fest.' : 'With our confirmation your table is fixed.'));
	} else {
		$eyebrow = $de ? 'Warteliste' : 'Waiting list';
		$title = $de ? ($n !== '' ? 'Du stehst auf der Warteliste, '.$n.'.' : 'Du stehst auf der Warteliste.') : ($n !== '' ? 'You are on the waiting list, '.$n.'.' : 'You are on the waiting list.');
		$lead = $de ? 'Wird ein Tisch frei, schreiben wir dir. Bis dahin ist nichts verloren.' : 'If a table becomes free, we will write to you. Nothing is lost until then.';
		$steps = array(array($de ? 'Jetzt' : 'Now', ($de ? 'Du stehst auf der Liste, eine Mail ist unterwegs an ' : 'You are on the list, an e-mail is on its way to ').$c['mail'].'.'),
			array($de ? 'Sobald ein Tisch frei ist' : 'As soon as a table is free', $de ? 'Wir schreiben dir.' : 'We write to you.'));
	}
	$t = $de ? array('date' => 'Datum', 'time' => 'Uhrzeit', 'guests' => 'Personen', 'nr' => 'Buchungsnummer', 'next' => 'Was jetzt passiert', 'ics' => 'In den Kalender', 'share' => 'Mit Mitgästen teilen', 'copied' => 'In die Zwischenablage kopiert', 'failed' => 'Kopieren nicht möglich, bitte manuell kopieren',
			'ics_done' => 'Die Kalender-Datei ist geladen. Öffne sie, dann ist der Termin gespeichert.', 'know' => 'Gut zu wissen', 'park' => 'Wo parke ich?', 'way' => 'Wie komme ich hin?', 'access' => 'Geht es barrierefrei?', 'late' => 'Verspätest du dich?', 'late_t' => 'Ruf kurz an, dann wissen wir Bescheid.',
			'menu' => 'Schau schon mal, worauf du Lust hast', 'change' => 'Planänderung? Kein Problem: Über den Link in deiner Mail änderst oder stornierst du.', 'cancel' => 'Reservierung stornieren', 'back' => 'Zurück zur Website', 'ask' => 'Fragen? Ruf uns an', 'bye' => 'Bis bald im Amadeus')
		: array('date' => 'Date', 'time' => 'Time', 'guests' => 'Guests', 'nr' => 'Booking number', 'next' => 'What happens now', 'ics' => 'Add to calendar', 'share' => 'Share with your guests', 'copied' => 'Copied to clipboard', 'failed' => 'Could not copy, please copy manually',
			'ics_done' => 'The calendar file is downloaded. Open it and the date is saved.', 'know' => 'Good to know', 'park' => 'Where do I park?', 'way' => 'How do I get there?', 'access' => 'Is it accessible?', 'late' => 'Running late?', 'late_t' => 'Give us a quick call and we will know.',
			'menu' => 'Take a look at what you fancy', 'change' => 'Change of plans? No problem: use the link in your e-mail to change or cancel.', 'cancel' => 'Cancel reservation', 'back' => 'Back to website', 'ask' => 'Questions? Call us', 'bye' => 'See you soon at Amadeus');
	$gi = $c['gi'];
	?>
		<?php if (function_exists('brand_logo_html')): ?><div class="brand-row done-brand"><?php echo brand_logo_html($c['brand']); ?></div><?php endif; ?>
		<p class="done-eyebrow"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><?php if ($mode == 2): ?><path d="M5 12.5 10 17l9-10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><?php else: ?><circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><?php endif; ?></svg><?php echo $h($eyebrow); ?></p>
		<h1 class="confirm-title" tabindex="-1" id="done-title"><?php echo $h($title); ?></h1>
		<p class="confirm-text"><?php echo $h($lead); ?></p>

		<dl class="guest-facts">
			<div class="guest-when"><dt><?php echo $h($t['date']); ?></dt><dd><?php echo $h($c['date_text']); ?></dd></div>
			<div><dt><?php echo $h($t['time']); ?></dt><dd><?php echo $h($c['time_text']); ?></dd></div>
			<div><dt><?php echo $h($t['guests']); ?></dt><dd><?php echo (int)$c['pax']; ?></dd></div>
			<div class="guest-nr"><dt><?php echo $h($t['nr']); ?></dt><dd class="guest-number"><?php echo $h($c['number']); ?></dd></div>
		</dl>

		<section class="guest-section" aria-labelledby="d-next">
			<h2 id="d-next"><?php echo $h($t['next']); ?></h2>
			<ol class="done-steps">
				<?php foreach ($steps as $st): ?><li><b><?php echo $h($st[0]); ?></b><span><?php echo $h($st[1]); ?></span></li><?php endforeach; ?>
			</ol>
		</section>

		<?php if ($mode == 2): ?>
		<div class="guest-quick-actions">
			<a class="guest-btn guest-ics" id="done-ics" href="<?php echo $h($c['ics_url']); ?>"><?php echo $h($t['ics']); ?></a>
			<button type="button" class="guest-btn guest-share" id="guest-share-btn" data-share-title="<?php echo $h($c['share']['subject']); ?>" data-share-text="<?php echo $h($c['share']['text']); ?>" data-share-copied="<?php echo $h($t['copied']); ?>" data-share-failed="<?php echo $h($t['failed']); ?>"><?php echo $h($t['share']); ?></button>
		</div>
		<p class="guest-toast" id="guest-toast" role="status" aria-live="polite" hidden></p>

		<section class="guest-section" aria-labelledby="d-know">
			<h2 id="d-know"><?php echo $h($t['know']); ?></h2>
			<details class="guest-fold"><summary><?php echo $h($t['park']); ?></summary><p><?php echo $gi['parking_html']; ?></p></details>
			<details class="guest-fold"><summary><?php echo $h($t['way']); ?></summary><p><?php echo $h($gi['addr_plain']); ?><?php if ($gi['route_url'] !== ''): ?><br/><a href="<?php echo $h($gi['route_url']); ?>" target="_blank" rel="noopener"><?php echo $h($gi['route_l']); ?></a><?php endif; ?><br/><?php echo $h($gi['info'][$gi['bus_key']]); ?> <a href="<?php echo $h($gi['bus_url']); ?>" target="_blank" rel="noopener"><?php echo $h($gi['bus_l']); ?></a></p></details>
			<details class="guest-fold"><summary><?php echo $h($t['access']); ?></summary><p><?php echo $gi['accessibility_html']; ?></p></details>
			<details class="guest-fold"><summary><?php echo $h($t['late']); ?></summary><p><?php echo $h($t['late_t']); ?><?php if ($c['tel_href'] !== ''): ?> <a href="<?php echo $h($c['tel_href']); ?>"><?php echo $h($c['phone']); ?></a><?php endif; ?></p></details>
		</section>

		<section class="guest-section" aria-labelledby="d-menu">
			<h2 id="d-menu"><?php echo $h($t['menu']); ?></h2>
			<div class="guest-buttons">
				<?php foreach ($gi['menu_links'] as $label => $url): ?><a class="guest-btn" href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><?php echo $h($label); ?></a><?php endforeach; ?>
			</div>
		</section>
		<?php elseif ($c['tel_href'] !== ''): ?>
		<p class="guest-text done-ask"><?php echo $h($t['ask']); ?>: <a href="<?php echo $h($c['tel_href']); ?>"><?php echo $h($c['phone']); ?></a></p>
		<?php endif; ?>

		<?php nl_after_box($c['nlBox']); ?>

		<div class="done-foot">
			<?php if ($mode != 1): ?><p class="guest-text"><?php echo $h($t['change']); ?></p><?php endif; ?>
			<p class="done-sign"><?php echo $h($c['sign']); ?></p>
			<a class="confirm-secondary" href="<?php echo $h($c['website']); ?>"><?php echo $h($t['back']); ?></a>
			<?php if ($mode == 2): ?><a class="confirm-secondary" href="<?php echo $h($c['cancel_url']); ?>"><?php echo $h($t['cancel']); ?></a><?php endif; ?>
		</div>
		<?php if ($mode == 2): ?>
		<script>
		(function () {
			var btn = document.getElementById('guest-share-btn'), toast = document.getElementById('guest-toast'), ics = document.getElementById('done-ics'), timer;
			var show = function (msg) { if (!toast) { return; } toast.textContent = msg; toast.hidden = false; requestAnimationFrame(function () { toast.classList.add('is-on'); }); clearTimeout(timer); timer = setTimeout(function () { toast.classList.remove('is-on'); }, 3600); };
			if (ics) { ics.addEventListener('click', function () { show(<?php echo json_encode($t['ics_done']); ?>); }); }
			if (btn) { btn.addEventListener('click', function () {
				var title = btn.getAttribute('data-share-title'), text = btn.getAttribute('data-share-text');
				if (navigator.share) { navigator.share({ title: title, text: text }).catch(function () {}); return; }
				if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(function () { show(btn.getAttribute('data-share-copied')); }).catch(function () { show(btn.getAttribute('data-share-failed')); }); }
				else { show(btn.getAttribute('data-share-failed')); }
			}); }
		})();
		</script>
		<?php endif; ?>
	<?php
  }

  // values of the final screen (language of the guest: the form's language)
  $dn = array('prp' => $prp_info, 'ok' => false);
  if ($waitlist >= 1 && $waitlist <= 3) {
	require_once(__DIR__.'/../web/classes/booking_mail.class.php');
	$gDe = isset($_POST['reservation_email_lang']) && substr((string)$_POST['reservation_email_lang'], 0, 2) === 'de';
	$gLang = $gDe ? 'de' : 'en'; $mon = done_months($gDe);
	$vd = isset($_SESSION['selectedDate']) ? $_SESSION['selectedDate'] : date('Y-m-d');
	$gEmail = trim((string)(isset($_POST['reservation_guest_email']) ? $_POST['reservation_guest_email'] : ''));
	$gName = trim((string)strtok(trim(stripslashes((string)(isset($_POST['reservation_guest_name']) ? $_POST['reservation_guest_name'] : ''))), ' '));
	$gPhone = !empty($settings['mailPhone']) ? $settings['mailPhone'] : (!empty($prp_info['phone']) ? $prp_info['phone'] : '');
	$gDigits = preg_replace('/\D/', '', (string)$gPhone);
	$gGi = bm_guest_info($gLang, $gPhone, $prp_info);
	$gWeekday = bm_weekday($vd, $gLang);
	$gDateText = $gDe ? $gWeekday.', '.(int)date('j', strtotime($vd)).'. '.$mon[(int)date('n', strtotime($vd))] : $gWeekday.', '.(int)date('j', strtotime($vd)).' '.$mon[(int)date('n', strtotime($vd))];
	$gTimeText = $bookingtime.($gDe && preg_match('/^[\d:]+$/', trim((string)$bookingtime)) ? ' Uhr' : '');
	$gBrand = $outlet_name !== '' ? $outlet_name : $prp_info['name'];
	$gMobile = false; try { require_once(__DIR__.'/../web/classes/sms.class.php'); $gMobile = sms_enabled() && sms_normalize_phone(isset($_POST['reservation_guest_phone']) ? (string)$_POST['reservation_guest_phone'] : '') !== null; } catch (Throwable $e) { /* the SMS note is only a convenience */ }
	$gNr = (string)$_SESSION['booking_number'];
	$dn = array('de' => $gDe, 'first' => $gName, 'days' => (int)floor((strtotime($vd) - strtotime(date('Y-m-d'))) / 86400), 'hour' => (int)date('G'), 'weekday' => $gWeekday, 'date_text' => $gDateText, 'time_text' => $gTimeText,
		'pax' => (int)$_POST['reservation_pax'], 'number' => $gNr, 'mail' => preg_replace('/^(.{2}).*(@.*)$/u', '$1***$2', strtolower($gEmail)), 'mobile' => $gMobile, 'gi' => $gGi, 'phone' => $gPhone,
		'tel_href' => strlen($gDigits) >= 6 ? 'tel:'.(substr($gDigits, 0, 1) === '0' ? '+49'.substr($gDigits, 1) : '+'.$gDigits) : '', 'brand' => $prp_info['name'], 'website' => $website, 'nlBox' => $nlBox,
		'sign' => ($gDe ? (!empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun').' und das Amadeus-Team' : (!empty($settings['mailSignName']) ? $settings['mailSignName'] : 'Hamun').' and the Amadeus team'),
		'ics_url' => 'cancel.php?nr='.urlencode($gNr).'&email='.urlencode($gEmail).'&lang='.$gLang.'&ics=1', 'cancel_url' => 'cancel.php?nr='.urlencode($gNr).'&email='.urlencode($gEmail).'&lang='.$gLang,
		'share' => bm_share_text($gLang, array('host' => $gName, 'brand' => bm_clean($gBrand), 'date_text' => $gDateText, 'time_text' => $gTimeText, 'table' => '', 'gi' => $gGi)));
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
	<div class="confirm-card<?php echo ($waitlist >= 1 && $waitlist <= 3) ? ' is-rich' : ''; ?>">
	<?php if ($waitlist >= 1 && $waitlist <= 3): done_screen($waitlist, $dn); ?>

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

<script>(function(){var t=document.getElementById('done-title');if(t){t.focus({preventScroll:true});}})();</script>
</body>
</html>