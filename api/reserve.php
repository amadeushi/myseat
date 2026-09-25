<?php session_start();
// MS IE not to forget the session variables
header('P3P: CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');

/*
* TERMS OF USE - mySeat
* 
* Open source under the GNU General Public License. 
* 
* Copyright © 2011 Bernd Orttenburger
* All rights reserved.
*
* This booking form was created with the help and work
* of the guys at http://www.reservaenrestaurantes.com/
*
* COPYRIGHT:
* This file is part of mySeat.
*
* mySeat is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* any later version.
*
* mySeat is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with mySeat.  If not, see <http://www.gnu.org/licenses/>.
*/

// ** SETTINGS **
// Select the type of time selector:
// 'radio': radio buttons; 'drop': select box 
$time_selector = "radio";

// link to the terms / privacy page of the restaurant: $settings['termsLink'] in config.general.php
// (full https address). Without it the consent text of the form is shown without a link.
$terms_link = (isset($settings['termsLink']) && preg_match('#^https?://#i', trim($settings['termsLink']))) ? trim($settings['termsLink']) : '';

// END settings

// initial standard settings
$_SESSION['role'] = 6;
$_SESSION['resID'] = 0;
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
// ** php hooks class
	include_once "../web/classes/phphooks.config.php";
	include_once "../web/classes/phphooks.class.php";
	$plugin_path = '../plugins/';
	//create instance of plugin class
	include "../config/plugins.init.php";
			
// get and define referer
	$ref = getHost($_SERVER['HTTP_REFERER']);
	$_SESSION['referer'] = ($_SESSION['referer']!='') ? $_SESSION['referer'] : $ref;
	// a source tag in the link (e.g. the Google business profile: ...reserve.php?outletID=1&quelle=google)
	// wins over the referring host and shows up in the reservation details and the statistics
	if (isset($_GET['quelle'])) {
		$quelle = preg_replace('/[^a-z0-9_\-]/', '', strtolower(substr((string)$_GET['quelle'], 0, 30)));
		if ($quelle !== '') { $_SESSION['referer'] = $quelle; }
	}

// Check if outlet or property booking
	if (isset($_SESSION['single_outlet']) && (isset($_GET['outletID']) && empty($_GET['propertyID']))) {
		$_SESSION['single_outlet'] = 'ON';
	}else{
		$_SESSION['single_outlet'] = 'OFF';
	}

// outlet ID
	if (isset($_GET['outletID'])) {
		$_SESSION['outletID'] = (int)$_GET['outletID'];
		$_SESSION['property'] = querySQL('property_id_outlet');
		$_SESSION['propertyID'] = $_SESSION['property'];
	}

	// prevent injection with false outlet id's
	$check_web_outlet = querySQL('check_web_outlet');

// property ID
   if ($_GET['propertyID']) {
       $_SESSION['property'] = (int)$_GET['propertyID'];
	   $_SESSION['outletID'] = querySQL('web_standard_outlet');
	   $_SESSION['propertyID'] = $_SESSION['property'];
	
	   // prevent injection with false outlet id's
	   $check_web_outlet = 1;
   }
	

// selected time	
	if (isset($_GET['times'])) {
		// set selected time
		$time = $_GET['times'].":00";	
	}

// selected pax	
	if ($_GET['pax']) {
		// set selected time
		$_SESSION['pax'] = max(1, min(500, (int)$_GET['pax']));
	}elseif($_SESSION['selected_pax']<1){
		$_SESSION['pax'] = 2;
	}

	// ** set configuration
	include('../config/config.inc.php');

if($check_web_outlet==1){		
	// get property info for logo path
	$prp_info = querySQL('property_info');
	
	if (strtolower(substr($prp_info['website'],0,4)) =="http") {
		$website = $prp_info['website'];
	}else{
		$website = "http://".$prp_info['website'];
	}

	// selected date
    if ($_GET['selectedDate'] && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['selectedDate'])) {
        $_SESSION['selectedDate'] = $_GET['selectedDate'];
    }
	
	// +++ memorize selected outlet details; maybe moved reservation +++
	$rows = querySQL('db_outlet_info');
	if($rows){
		foreach ($rows as $key => $value) {
			$_SESSION['selOutlet'][$key] = $value;
		}
	}
	
	// ** get superglobal variables
		include('../web/includes/get_variables.inc.php');
		
	// CSRF - Secure forms with token
		$barrier = md5(uniqid(rand(), true)); 
		$_SESSION['barrier'] = $barrier;		
	
  	//prepare selected Date
    list($sy,$sm,$sd) = explode("-",$_SESSION['selectedDate']);
  
	// get outlet maximum capacity
	$maxC = maxCapacity();
	 
	// get Pax by timeslot
    $resbyTime = reservationsByTime('pax');
    $tblbyTime = reservationsByTime('tbl');
	$_SESSION['passbyTime'] = reservationsByTime('pass');
	// print_r($_SESSION['passbyTime']);
	// echo $_SESSION['outletID'].", ".$_SESSION['selectedDate'].", ".$_SESSION['resID'];
    // get availability by timeslot
    $availability = getAvailability($resbyTime,$general['timeintervall']);
    $tbl_availability = getAvailability($tblbyTime,$general['timeintervall']);
	
	// some constants
    $outlet_name = querySQL('db_outlet');
	$max_pax = ($_SESSION['selOutlet']['passerby_max_pax'] <= 0) ? $_SESSION['selOutlet']['outlet_max_capacity'] : $_SESSION['selOutlet']['passerby_max_pax'];
	$max_passerby = ($_SESSION['passerby_max_pax'] <= 0) ? $max_pax : $_SESSION['passerby_max_pax'];
}
  // translate to selected language
	$language = $general['language'];
	$set_lang = substr($language,0,2);
	// the browser's preferred language, e.g. "en-US,en;q=0.9,de;q=0.8" - only the first,
	// highest-priority subtag matters here (the previous code compared the whole raw header
	// against "en", which a real Accept-Language value never equals, so it never actually fired)
	$browser_lang = '';
	if ( !empty($_SERVER['HTTP_ACCEPT_LANGUAGE']) && preg_match('/^\s*([a-z]{2})/i', $_SERVER['HTTP_ACCEPT_LANGUAGE'], $bl_m) ) {
		$browser_lang = strtolower($bl_m[1]);
	}

	if( isset($_GET['lang']) ){
		$language = $_GET['lang'];
		$_SESSION['lang'] = $language;
	}else if ( $_SESSION['lang'] == '' && $browser_lang !== '' && $browser_lang !== $set_lang ){
		// guest's browser is not in the site's own language - English is the only alternative
		// this form offers, so it is the reasonable default for any other language
		$language = 'en';
	}
	if( $_SESSION['lang'] == ''){
		$_SESSION['lang'] = $language;
	}
	//$_SESSION['lang'] = 'en';
	$lang = substr($_SESSION['lang'],0,2);
	translateSite($lang,'../web/');
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<html lang="<?php echo $language; ?>">
<head>
	<!-- Meta data for SEO -->
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/> 
	<meta http-equiv="X-UA-Compatible" content="IE=8" />
	<meta name="robots" content="follow,index,no-cache" />
	<meta name="author" lang="en" content="Bernd Orttenburger [www.myseat.us]" />
	<meta name="copyright" lang="en" content="mySeat [www.myseat.us]" />
	<meta name="keywords" content="mySeat, table reservation system, Bookings Diary, Reservation Diary, Restaurant Reservations, restaurant reservation system, open source, software, reservation management software, restaurant table management, table planner, restaurant table planner, table management, hotel" />
	<meta id="htmlTagMetaDescription" name="Description" content="Make online reservationsfor lunch and dinners. mySeat is a OpenSource online reservation system for restaurants." />
	<meta id="htmlTagMetaKeyword" name="Keyword" content="restaurant reservations, online restaurant reservations, restaurant management software, mySeat, free tables" />

	<!-- Meta data for all iDevices -->
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="apple-mobile-web-app-capable" content="yes" />
	<meta name="apple-mobile-web-app-status-bar-style" content="black" />
	<link rel="shortcut icon" href="http://www.myseat.us/favicon.ico">

	<!-- CSS - Setup -->
	<link rel="stylesheet" href="../web/fonts/fonts.css"/>
	<link href="style/datepicker.css" rel="stylesheet" type="text/css" />
	<link href="style/style.css?v=<?php echo @filemtime(__DIR__.'/style/style.css'); ?>" rel="stylesheet" type="text/css" />

    <!-- jQuery Library-->
    <script src="js/jquery-3.7.1.min.js" type="text/javascript"></script>
    <script src="js/jquery-ui-1.13.3.min.js" type="text/javascript"></script>
    <script src="js/functions.js" type="text/javascript"></script>
	<script src="../web/lang/jquery.ui.datepicker-<?php echo substr($_SESSION['lang'],0,2);?>.js" type="text/javascript"></script>

<!-- Uncomment to define your own color scheme for the booking form -->
<!-- The example here is from the Monmarthe DEMO page at myseat.us -->

<!--
	<style type="text/css">
		html {
			background:url(images/html-bg.jpg) left top repeat !important;
		}
		.data1, .data2, .data3, .register{
			background-color: #F6E6CC;			
		}
		h1, .data1 .number, .data2 .number, .data3 .number, .register .number{
			color: #AB245E;			
		}
		a, a:active, a:visited {
		color: #42032C;
		}
		a:hover {
			color:#7e4e7f;
			background-color: #F6E6CC;
		}
		.button:hover {
			color:#7e4e7f;
			background-color: #F6E6CC;
		}
			button, .button, .btn_pax {
			background-color: #561C40;
			color: #F6E6CC;
			border: 1px solid #B89394;
			text-shadow: none;
		}
	</style>
-->
<!-- color scheme for the booking form END -->

    <title><?php echo _reservations;?></title>
</head>
<body>
	    
<?php
	if( $check_web_outlet<1 ){
		echo "<div class='booking-shell'><div class='alert_error'><p><img src='../web/images/icon_error.png' alt='error' class='middle'/>&nbsp;&nbsp;";
		echo _sorry."<br></p></div></div>";
		exit; //stop script
	}
	$num_outlets = 0;
	if ($_SESSION['single_outlet'] == 'OFF') {
		$num_outlets = querySQL('num_outlets');
	}
	$hours_summary = getWeeklyHoursSummary($_SESSION['selOutlet']);
	$page_title = ($num_outlets > 1) ? $prp_info['name'] : $outlet_name;
?>
<div class="booking-shell">
<div class="booking-grid">

	<aside class="booking-sidebar">
		<a class="back-link" href="<?php echo $website; ?>">
			<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3.5 5.5 8l4.5 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php echo $prp_info['name']; ?>
		</a>

		<div class="hours-card">
			<h3>
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v5l3.5 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<?php echo bt('hours_title'); ?>
			</h3>
			<dl>
				<?php foreach ($hours_summary as $row): ?>
				<div class="hours-row">
					<dt><?php echo htmlspecialchars($row['days']); ?></dt>
					<dd><?php echo $row['hours'] ? htmlspecialchars($row['hours']) : bt('closed'); ?></dd>
				</div>
				<?php endforeach; ?>
			</dl>
		</div>

		<div class="contact-list">
			<?php if (!empty($prp_info['street'])): ?>
			<div class="contact-item">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12Z" stroke="currentColor" stroke-width="1.6"/><circle cx="12" cy="9" r="2.5" stroke="currentColor" stroke-width="1.6"/></svg>
				<span><?php echo $prp_info['street'].', '.$prp_info['zip'].' '.$prp_info['city']; ?></span>
			</div>
			<?php endif; ?>
			<?php if (!empty($prp_info['phone'])): ?>
			<a class="contact-item" href="tel:<?php echo preg_replace('/\s+/', '', $prp_info['phone']); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M6 4h3l1.5 4.5-2 1.5a12 12 0 0 0 5.5 5.5l1.5-2L20 15v3a2 2 0 0 1-2 2C10.8 20 4 13.2 4 6a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
				<span><?php echo $prp_info['phone']; ?></span>
			</a>
			<?php endif; ?>
			<?php if (!empty($prp_info['email'])): ?>
			<a class="contact-item" href="mailto:<?php echo $prp_info['email']; ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="m4 6.5 8 6 8-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				<span><?php echo $prp_info['email']; ?></span>
			</a>
			<?php endif; ?>
		</div>
	</aside>

	<main class="booking-main">
		<?php language_navigation($lang);?>
		<h1 class="booking-title"><?php echo $page_title; ?></h1>
		<p class="booking-subtitle"><?php echo bt('subtitle'); ?></p>

		<form action="process_booking.php" method="post" name="contactForm" id="contactForm">
			<?php if ($num_outlets > 1): ?>
			<input type="hidden" name="reservation_outlet_id" id="single_outlet" value="<?php echo $_SESSION['outletID']; ?>">
			<?php else: ?>
			<input type="hidden" name="reservation_outlet_id" id="single_outlet" value="<?php echo $_SESSION['outletID']; ?>">
			<?php endif; ?>

			<input type="hidden" name="action" id="action" value="submit"/>
			<input type="hidden" name="barrier" value="<?php echo $barrier; ?>" />
			<input type="hidden" name="reservation_referer" value="<?php echo htmlspecialchars($_SESSION['referer'], ENT_QUOTES, 'UTF-8'); ?>" />
			<input type="hidden" name="reservation_hotelguest_yn" id="reservation_hotelguest_yn" value="PASS"/>
			<input type="hidden" name="reservation_booker_name" id="reservation_booker_name" value="Contact Form"/>
			<input type="hidden" name="reservation_author" id="reservation_author" value="<?php echo querySQL('db_property');?> Team"/>
			<input type="hidden" name="email_type" id="email_type" value="<?php echo $lang; ?>"/>
			<input type="hidden" name="reservation_email_lang" value="<?php echo $lang; ?>"/>

			<!-- Step 1: date, time, party size -->
			<div class="wizard-step" data-step="1">
				<?php if ($num_outlets > 1): ?>
				<div class="picker-row">
				<?php $outlet_result = outletListweb($_SESSION['outletID'],'enabled','reservation_outlet_id'); ?>
				</div>
				<?php endif; ?>

				<div class="picker-row">
					<div class="picker pax-picker">
						<span class="picker-label"><?php echo ucfirst(_people_);?></span>
						<div class="pax-stepper">
							<a href="javascript:void(0);" class="dec btn_pax" aria-label="<?php echo bt('pax_less'); ?>">–</a>
							<input type="text" name="reservation_pax" id="reservation_pax" value="<?php echo $_SESSION['pax'];?>"/>
							<a href="javascript:void(0);" class="inc btn_pax" aria-label="<?php echo bt('pax_more'); ?>">+</a>
						</div>
					</div>
					<div class="picker date-picker">
						<span class="picker-label"><?php echo _date;?></span>
						<input type="hidden" name="dbdate" id="dbdate" value="<?php echo $_SESSION['selectedDate']; ?>"/>
						<input id="reservation_date" name="reservation_date" readonly="readonly" value="<?php echo $_SESSION['selectedDate'];?>">
						<input type="hidden" name="recurring_dbdate" value="<?php echo $_SESSION['selectedDate']; ?>"/>
					</div>
				</div>

				<div class="timeslot-section">
					<span class="picker-label"><?php echo _time;?></span>
					<div id="timeslot-results">
					<?php include 'timeslot_fragment.inc.php'; ?>
					</div>
					<p class="wizard-error" id="timeslot-error"><?php echo bt('pick_time'); ?></p>
				</div>

				<div class="wizard-nav">
					<span></span>
					<button type="button" class="submit-button wizard-btn wizard-next" data-goto="2"><?php echo bt('next'); ?></button>
				</div>
			</div>

			<!-- Step 2: notes -->
			<div class="wizard-step wizard-step-hidden" data-step="2">
				<h3 class="wizard-step-title"><?php echo bt('details_title'); ?></h3>
				<div class="field">
					<label><?php echo _form_notes; ?></label>
					<textarea cols="50" rows="5" name="reservation_notes" id="reservation_notes"></textarea>
				</div>
				<div class="wizard-nav">
					<button type="button" class="wizard-btn wizard-back" data-goto="1"><?php echo bt('back'); ?></button>
					<button type="button" class="submit-button wizard-btn wizard-next" data-goto="3"><?php echo bt('next'); ?></button>
				</div>
			</div>

			<!-- Step 3: contact details + confirm -->
			<div class="wizard-step wizard-step-hidden" data-step="3">
				<h3 class="wizard-step-title"><?php echo bt('checkout'); ?></h3>

				<div class="wizard-summary">
					<div class="summary-item">
						<span class="summary-label"><?php echo _date;?></span>
						<span class="summary-value" id="summary-date"></span>
					</div>
					<div class="summary-item">
						<span class="summary-label"><?php echo _time;?></span>
						<span class="summary-value" id="summary-time"></span>
					</div>
					<div class="summary-item">
						<span class="summary-label"><?php echo ucfirst(_people_);?></span>
						<span class="summary-value" id="summary-pax"></span>
					</div>
				</div>

				<div class="field">
					<label><?php echo _name; ?></label>
					<input type="text" name="reservation_guest_name" class="required" id="reservation_guest_name" value="<?php if(isset($me['last_name'])){echo $me['last_name'].", ".$me['first_name'];} ?>" />
				</div>
				<div class="field">
					<label><?php echo _email; ?></label>
					<input type="text" name="reservation_guest_email" class="required email" id="reservation_guest_email" value="<?php if(isset($me['last_name'])){echo $me['email'];} ?>" />
				</div>
				<div class="field">
					<label><?php echo _phone; ?></label>
					<input type="text" name="reservation_guest_phone" class="required" id="reservation_guest_phone" value="" />
				</div>

				<div class="consent-group">
					<label class="checkbox-row">
						<input type="checkbox" name="reservation_advertise" id="reservation_advertise" value="YES"/>
						<span><?php echo _reservation_advertise; ?></span>
					</label>
					<label class="checkbox-row">
						<input type="checkbox" name="terms" class="required checkbox" id="terms" value="YES" checked="checked"/>
						<span class="checktext">
							<?php if ($terms_link !== ''): ?>
							<a href="<?php echo htmlspecialchars($terms_link); ?>" target="_blank" rel="noopener noreferrer"><?php echo _reservation_terms; ?></a>
							<?php else: echo _reservation_terms; endif; ?>
						</span>
					</label>
				</div>

				<div class="wizard-nav">
					<button type="button" class="wizard-btn wizard-back" data-goto="2"><?php echo bt('back'); ?></button>
					<button class="submit-button wizard-btn" type="submit"><?php echo _create; ?></button>
				</div>
			</div>
		</form>
	</main>

</div>
<?php include __DIR__.'/legal_footer.php'; ?>
</div><!-- booking-shell end -->
  <!-- Javascript at the bottom for fast page loading --> 
<script>
	/* utility functions */
	var unavailableDates = [<?php defineOffDays(); ?>];

	function unavailable(date) {
		// var m = date.getMonth(), d = date.getDate(), y = date.getFullYear();
		var m = date.getMonth(), d = dnz = date.getDate(), y = date.getFullYear();
		m = m+1;
		/* add leading zero */
		if (d < 10) d = "0" + d;
		if (m < 10) m = "0" + m;
	  ymd = y + "-" + m + "-" + d;
	  if ($.inArray(ymd, unavailableDates) == -1) {
	    return [true];
	  } else {
	    // return [false];
		return [false,'','<?php echo $_SESSION['selOutlet']['outlet_name']._closed; ?>' + getOrdinal(dnz)];
	  }
	}
	// Ref: http://myseat.cl0.vanillaforums.com/discussion/460/outlet-is-closed-tooltip-in-online-reservation-form-datepicker
	function getOrdinal(n) {
		var s=["th","st","nd","rd"],
		v=n%100;
		return n+(s[(v-20)%10]||s[v]||s[0]);
	} 

 jQuery(document).ready(function($) {
      // Setup datepicker input at customer reservation form
      $("#reservation_date").datepicker({
		  minDate: '0',
		  maxDate: '+12M',      
		  nextText: '»',
	      prevText: '«',
		  showOn: "focus",
	      firstDay: 1,
	      numberOfMonths: 1,
	      gotoCurrent: true,
	      altField: '#dbdate',
	      altFormat: 'yy-mm-dd',
	      defaultDate: 0,
		  beforeShowDay: unavailable,
	      dateFormat: '<?php echo $general['datepickerformat'];?>',
	      regional: '<?php echo substr($_SESSION['lang'],0,2);?>',
	      onSelect: function(dateText, inst) { window.location.href="?selectedDate=" + $("#dbdate").val() }
      });
      // month is 0 based, hence for Feb. we use 1
	     $("#reservation_date").datepicker('setDate', new Date(<?php echo $sy.", ".($sm-1).", ".$sd; ?>));
	     	<?php if ($_SESSION['selectedDate'] == date('Y-m-d')): ?>
	     	$("#reservation_date").val("<?php echo _today; ?>");
	     	<?php endif; ?>
	     	$("#ui-datepicker-div").hide();
	     	$("#reservation_outlet_id").on("change", function(){
	    		window.location.href='?propertyID=<?php echo $_SESSION['property'];?>&outletID=' + this.value;
	  	 	});
	
		// refresh the time-slot grid for a new guest count without reloading the page
		function refreshTimeslotsForPax(newVal) {
			var $results = $("#timeslot-results");
			$results.css("opacity", 0.5);
			$.ajax({
				url: "ajax_timeslots.php",
				data: { pax: newVal },
				success: function(html) {
					$results.html(html);
					$results.css("opacity", 1);
				},
				error: function() {
					// fall back to the old behaviour if the request itself fails
					window.location.href = "?pax=" + newVal;
				}
			});
		}

	 // +/- button for pax field  
		$(".btn_pax").on("click", function() {
		    var $button = $(this);
		    var oldValue = $button.parent().find("input").val();
  
		if ($button.text() == "+") {
				  var newVal = parseFloat(oldValue) + 1;
		        } else {
		          // Don't allow decrementing below zero
		          if (oldValue >= 1) {
		              var newVal = parseFloat(oldValue) - 1;
		          }else{
					  var newVal = parseFloat(oldValue);
				  }
		        }
		        $button.parent().find("input").val(newVal);
				refreshTimeslotsForPax(newVal);
		});

		// party size can also always be typed in directly
		$("#reservation_pax").on("change", function() {
			var $input = $(this);
			var newVal = parseInt($input.val(), 10);
			if (isNaN(newVal) || newVal < 1) {
				newVal = 1;
			}
			if (newVal > 500) {
				newVal = 500;
			}
			$input.val(newVal);
			refreshTimeslotsForPax(newVal);
		});


		// ---- preserve wizard progress across a language-switch reload ----
		// the EN/DE links are plain page reloads (?lang=en/de), which would
		// otherwise always land back on step 1 with every field emptied
		var WIZARD_STATE_KEY = "myseat_wizard_state";

		$(".lang-picker a").on("click", function() {
			try {
				var $checkedTime = $("input[name='reservation_time']:checked");
				sessionStorage.setItem(WIZARD_STATE_KEY, JSON.stringify({
					step: $(".wizard-step:not(.wizard-step-hidden)").data("step") || 1,
					time: $checkedTime.length ? $checkedTime.val() : "",
					notes: $("#reservation_notes").val(),
					name: $("#reservation_guest_name").val(),
					email: $("#reservation_guest_email").val(),
					phone: $("#reservation_guest_phone").val(),
					advertise: $("#reservation_advertise").is(":checked")
				}));
			} catch (e) {
				// sessionStorage unavailable (e.g. private browsing) - the
				// language switch still works, it just restarts the wizard
			}
		});

		function restoreWizardState() {
			var saved;
			try {
				saved = sessionStorage.getItem(WIZARD_STATE_KEY);
				sessionStorage.removeItem(WIZARD_STATE_KEY);
			} catch (e) {
				return;
			}
			if (!saved) {
				return;
			}
			try {
				saved = JSON.parse(saved);
			} catch (e) {
				return;
			}
			$("#reservation_notes").val(saved.notes || "");
			$("#reservation_guest_name").val(saved.name || "");
			$("#reservation_guest_email").val(saved.email || "");
			$("#reservation_guest_phone").val(saved.phone || "");
			// jQuery 1.4.4 (this app's bundled version) predates .prop() -
			// set the DOM property directly instead
			var $advertise = $("#reservation_advertise")[0];
			if ($advertise) {
				$advertise.checked = !!saved.advertise;
			}
			if (saved.time) {
				$("input[name='reservation_time']").each(function() {
					if (this.value === saved.time) {
						this.checked = true;
					}
				});
			}
			if (saved.step && saved.step > 1) {
				showWizardStep(saved.step);
			}
		}

		// ---- multi-step wizard navigation ----
		function showWizardStep(n) {
			$(".wizard-step").addClass("wizard-step-hidden");
			$(".wizard-step[data-step='" + n + "']").removeClass("wizard-step-hidden");
			if (n == 3) {
				// always show the real date in the summary, even when the
				// picker itself currently displays "Heute"
				var isoDate = $("#dbdate").val();
				var dateParts = isoDate.split("-");
				var displayDate = (dateParts.length == 3) ? dateParts[2] + "." + dateParts[1] + "." + dateParts[0] : isoDate;
				$("#summary-date").text(displayDate);
				var $checkedTime = $("input[name='reservation_time']:checked");
				$("#summary-time").text($checkedTime.length ? $checkedTime.val() : "");
				$("#summary-pax").text($("#reservation_pax").val());
			}
			var $shell = $(".booking-main");
			if ($shell.length) {
				$("html, body").animate({ scrollTop: $shell.offset().top - 20 }, 200);
			}
		}

		$(".wizard-next").on("click", function() {
			var gotoStep = $(this).data("goto");
			if (gotoStep == 2) {
				// require a time slot before leaving step 1
				if ($("input[name='reservation_time']:checked").length === 0) {
					$("#timeslot-error").addClass("wizard-error-visible");
					return;
				}
				$("#timeslot-error").removeClass("wizard-error-visible");
			}
			showWizardStep(gotoStep);
		});

		$(".wizard-back").on("click", function() {
			showWizardStep($(this).data("goto"));
		});

		// picking a time slot clears any pending "please choose a time" error
		// (delegated, so slots re-rendered by the AJAX refresh keep working)
		$(document).on("change", "input[name='reservation_time']", function() {
			$("#timeslot-error").removeClass("wizard-error-visible");
		});

		restoreWizardState();
    });
</script>

</body>
</html>