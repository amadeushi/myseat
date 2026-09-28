<?php session_start();
require_once __DIR__.'/../includes/require_login.inc.php';

/*
 * Polled by the new-reservation highlighter (web/includes/reservations_grid.inc.php): how many
 * reservations for the outlet/date already open in the day view arrived after ?since= (unix
 * timestamp)? Counts confirmed and waitlisted alike, never cancelled ones. Answers JSON.
 */
include('../../config/config.general.php');
include('../classes/connect.db.php');
include('../classes/database.class.php');
include('../classes/local.class.php');
include('../classes/business.class.php');
translateSite(substr($_SESSION['language'],0,2),'../');
include('../classes/db_queries.db.php');
include('../../config/config.inc.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$since = isset($_GET['since']) ? (int)$_GET['since'] : 0;
if ($since <= 0 || empty($_SESSION['outletID']) || empty($_SESSION['selectedDate'])) {
	echo json_encode(array('ok' => false, 'count' => 0));
	exit;
}
$sinceSql = date('Y-m-d H:i:s', $since);
$result = query("SELECT COUNT(*) FROM `$dbTables->reservations`
	WHERE reservation_outlet_id = '%d' AND reservation_date = '%s' AND reservation_hidden = 0
	AND reservation_timestamp > '%s'", (int)$_SESSION['outletID'], $_SESSION['selectedDate'], $sinceSql);
$count = (int)getResult($result);
echo json_encode(array('ok' => true, 'count' => $count, 'now' => time()));
