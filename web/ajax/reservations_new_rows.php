<?php session_start();
require_once __DIR__.'/../includes/require_login.inc.php';

/*
 * Renders the <tr> markup for confirmed reservations that arrived after ?since= (unix timestamp), for
 * the live "new reservation" insert (web/includes/reservations_grid.inc.php): a guest booked or staff
 * created something while the day view was already open, and it should appear without a page reload.
 * Reuses the exact row markup the full table uses (web/includes/reservation_row_render.inc.php).
 * Waitlisted new arrivals are still announced by reservations_since.php's count/sound, but are not
 * live-inserted here (they belong to a separate table section elsewhere on the page) - they show up on
 * the next real refresh, same as before this feature existed.
 */
include('../../config/config.general.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/local.class.php');
include('../classes/business.class.php');
translateSite(substr($_SESSION['language'],0,2),'../');
include('../classes/db_queries.db.php');
include('../../config/config.inc.php');
require_once __DIR__.'/../classes/tableplan.class.php'; // tp_table_cell(), used by render_reservation_row_tr()
require_once __DIR__.'/../includes/reservation_row_render.inc.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
if ($since <= 0 || empty($_SESSION['outletID']) || empty($_SESSION['selectedDate'])) { exit; }

// minimal context render_reservation_row_tr() needs; kept deliberately light (this only ever serves
// freshly arrived rows for "today", not the full day-view bootstrap with its own settings queries)
$q = 1;
$resHighlightToday = true;
$maitre = array('maitre_timestamp' => '', 'maitre_comment_day' => '');
$general = array();
$availability = array();
$tbl_availability = array();
$_SESSION['wait'] = 0;

if ($_SESSION['page'] == 1) {
	$result = query("SELECT reservation_id, reservation_bookingnumber, reservation_outlet_id,
		reservation_date, reservation_time, reservation_title,
		reservation_guest_name, reservation_guest_adress, reservation_guest_city,
		reservation_guest_email, reservation_guest_phone, reservation_pax,
		reservation_hotelguest_yn, reservation_notes, reservation_booker_name,
		reservation_timestamp, reservation_ip, reservation_hidden,
		reservation_wait, repeat_id, reservation_bill,
		reservation_discount, reservation_bill_paid, reservation_billet_sent,
		reservation_parkticket, reservation_table, reservation_status, reservation_approval,
		reservation_advertise,reservation_referer,$dbTables->outlets.outlet_name
			FROM `$dbTables->reservations`
			INNER JOIN `$dbTables->outlets` ON `outlet_id` = `reservation_outlet_id`
			WHERE `reservation_hidden` = '0' AND `reservation_wait` = '0' AND `property_id` = '%d'
			AND `reservation_date` = '%s' AND `reservation_timestamp` > '%s'
			ORDER BY `reservation_time` ASC", (int)$_SESSION['propertyID'], $_SESSION['selectedDate'], date('Y-m-d H:i:s', $since));
} else {
	$result = query("SELECT reservation_id, reservation_bookingnumber, reservation_outlet_id,
		reservation_date, reservation_time, reservation_title,
		reservation_guest_name, reservation_guest_adress, reservation_guest_city,
		reservation_guest_email, reservation_guest_phone, reservation_pax,
		reservation_hotelguest_yn, reservation_notes, reservation_booker_name,
		reservation_timestamp, reservation_ip, reservation_hidden,
		reservation_wait, repeat_id, reservation_bill,
		reservation_discount, reservation_bill_paid, reservation_billet_sent,
		reservation_parkticket, reservation_table, reservation_status, reservation_approval,
		reservation_advertise,reservation_referer
			FROM `$dbTables->reservations`
			INNER JOIN `$dbTables->outlets` ON `outlet_id` = `reservation_outlet_id`
			WHERE `reservation_hidden` = '0' AND `reservation_wait` = '0' AND `reservation_outlet_id` = '%d'
			AND `reservation_date` = '%s' AND `reservation_timestamp` > '%s'
			ORDER BY `reservation_time` ASC", (int)$_SESSION['outletID'], $_SESSION['selectedDate'], date('Y-m-d H:i:s', $since));
}
$rows = getRowList($result);
if ($rows) {
	foreach ($rows as $row) { render_reservation_row_tr($row); }
}
