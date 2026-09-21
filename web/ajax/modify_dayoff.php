<?php session_start();
/* Connection to Database */
// ** set configuration
include('../../config/config.general.php');
// ** database functions
include('../classes/database.class.php');
// ** connect to database
include('../classes/connect.db.php');
// ** all database queries
include('../classes/db_queries.db.php');

	// only logged in staff with the right to edit the daily outlet settings
	include('../classes/business.class.php');
	if ( empty($_SESSION['valid_user']) || !current_user_can( 'Daily-Outlet-Edit' ) ) {
		http_response_code(403);
		exit;
	}

	// prevent dangerous input
	secureSuperGlobals();
	
	$value = $_POST['value'];
	$id	   = $_POST['id'];
	
	$sql = querySQL('update_maitre_dayoff');
	
	echo $sql;
?>