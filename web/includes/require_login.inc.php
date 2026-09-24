<?php
// Guard for AJAX endpoints that only the logged-in backend may call: web/main_page.php sets
// $_SESSION['valid_user'] after a real login, everyone else gets 403 and no data.
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['valid_user'])) {
	http_response_code(403);
	exit;
}
