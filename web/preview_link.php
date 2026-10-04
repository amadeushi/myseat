<?php
/*
 * "Bestellseite ansehen": sends a logged-in member of staff to the preview of the order page on the guest domain. The backend login
 * only exists on this domain, so the link carries a signed token that is valid for two minutes (see shop_preview_token()).
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop.class.php');

if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('Location: '.rtrim(shop_site_url(), '/').'/order/preview.php?t='.rawurlencode(shop_preview_token()));
exit;
