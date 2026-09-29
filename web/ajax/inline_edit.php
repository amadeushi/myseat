<?php
require_once __DIR__.'/../includes/require_login.inc.php';
/* Connection to Database */
// ** set configuration
include('../../config/config.general.php');
// ** database functions
include('../classes/database.class.php');
// ** connect to database
include('../classes/connect.db.php');
// ** all database queries
include('../classes/db_queries.db.php');
require_once('../classes/business.class.php');

if (!current_user_can('Reservation-Edit')) {
	http_response_code(403);
	exit;
}

// only column ever targeted by the live .inlineedit UI (the free-text table cell,
// see tableplan.class.php tp_table_cell()) - never trust the column name from $_POST directly,
// query()'s %s does not quote/escape SQL identifiers
$allowed_fields = array('reservation_table');

if ($_POST['id']) {
	// prevent dangerous input
	secureSuperGlobals();

	/* Get POST data */
	$submitted_id = $_POST['id'];
	$value = $_POST['value'];
	$exid = explode("-", $submitted_id);
	$field = $exid[0];
	$id = (int)$exid[1];

	if (!in_array($field, $allowed_fields, true) || $id <= 0) {
		http_response_code(400);
		exit;
	}

	/* Submit POST data */
	$sql = querySQL('inline_edit');

	/* Submit POST data */
	echo $value;
}
?>