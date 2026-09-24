<?php
require_once __DIR__ . '/../classes/mysql_compat.php';
session_start();
require_once __DIR__.'/../includes/require_login.inc.php';
$_SESSION['language'] = ($_SESSION['language']) ? $_SESSION['language'] : 'en';

// Check for a unique username
// ** set configuration
    include('../../config/config.general.php');
// ** database functions
    include('../classes/database.class.php');
// ** connect to database
    include('../classes/connect.db.php');
// ** all database queries
    include('../classes/db_queries.db.php');
// ** localization functions
    include('../classes/local.class.php');
// ** set configuration
    include('../../config/config.inc.php');
// translate to selected language
    translateSite(substr($_SESSION['language'],0,2),'../');

// prevent dangerous input
secureSuperGlobals();

if(isSet($_POST['username'])){
    $value = $_POST['username'];

    $sql_check = querySQL('check_username');

    if(mysql_num_rows($sql_check)){
        echo '<span style="color: red;">'. _already_user_1 .' <span class="bold">'.$value.'</span> '. _already_user_2 .'</span>';
    }else{
        echo "OK";
    }
}
?>