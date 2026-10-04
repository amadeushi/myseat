<?php
/*
 * Staff screens that stay open for days (kitchen monitor, dispatch screen) must not drop to the login page whenever the PHP session is gone: after a
 * restart of the browser (session cookies do not survive it) or when the server has cleaned the session up. The backend (main_page.php) brings the
 * session back from the "stay logged in" cookie; this does the same for those screens. Call myseat_restore_session() before checking
 * $_SESSION['valid_user'].
 *
 * (The token of the pages, shop_admin_token, is the same in every session of the same user, see myseat_admin_token() in shop.class.php: a page
 * that was opened before the session was lost can still send its changes afterwards.)
 */
function myseat_restore_session() {
	if (!empty($_SESSION['valid_user'])) { return true; }
	global $settings;
	if (empty($_COOKIE['ckPLC']) || !is_array($settings)) { return false; }
	require_once __DIR__.'/../../PLC/plc.class.php';
	$dbAccess = array('dbHost' => $settings['dbHost'], 'dbName' => $settings['dbName'], 'dbUser' => $settings['dbUser'], 'dbPass' => $settings['dbPass'], 'dbPort' => $settings['dbPort'], 'dbTablePrefix' => $settings['dbTablePrefix']);
	$user = new flexibleAccess('', $dbAccess);
	if (!$user->autologin()) { return false; }
	// the same fields main_page.php sets for a logged-in user
	$_SESSION['u_id'] = $user->userData[$user->tbFields['userID']];
	$_SESSION['u_name'] = $user->userData[$user->tbFields['login']];
	$_SESSION['u_email'] = $user->userData[$user->tbFields['email']];
	$_SESSION['role'] = $user->userData['role'];
	$_SESSION['realname'] = $user->userData['realname'];
	$_SESSION['autofill'] = $user->userData['autofill'];
	$_SESSION['property'] = $user->userData['property_id'];
	$_SESSION['propertyID'] = $user->userData['property_id'];
	$_SESSION['u_time'] = date('Y-m-d H:i:s', time());
	$_SESSION['u_lang'] = isset($user->userData['lang_id']) ? $user->userData['lang_id'] : '';
	$_SESSION['valid_user'] = true;
	return true;
}
