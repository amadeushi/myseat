<?php session_start();

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

// this endpoint only refreshes an already-initialized booking session
// (outletID + selectedDate come from the page's own initial load)
if (empty($_SESSION['outletID']) || empty($_SESSION['selectedDate'])) {
	http_response_code(400);
	exit;
}

// update guest count, clamped to a sane range
if (isset($_GET['pax'])) {
	$pax = (int)$_GET['pax'];
	$max_pax_allowed = ($general['max_menu'] > 0) ? $general['max_menu'] : 20;
	if ($pax < 1) { $pax = 1; }
	if ($pax > $max_pax_allowed) { $pax = $max_pax_allowed; }
	$_SESSION['pax'] = $pax;
}

$time_selector = "radio";

// get Pax by timeslot
$resbyTime = reservationsByTime('pax');
$tblbyTime = reservationsByTime('tbl');
$_SESSION['passbyTime'] = reservationsByTime('pass');
// get availability by timeslot
$availability = getAvailability($resbyTime,$general['timeintervall']);
$tbl_availability = getAvailability($tblbyTime,$general['timeintervall']);

$lang = substr($_SESSION['lang'],0,2);
translateSite($lang,'../web/');

// needed for the "no availability - contact us" fallback message
$prp_info = querySQL('property_info');

include 'timeslot_fragment.inc.php';
