<?php
/*
 * Sends the feedback mails after orders that are due (see web/classes/shop_feedback.class.php). Normally the kitchen monitor's polling does this by itself every ten
 * minutes; this script is for a cron job where that is wanted: php /path/to/web/cron/send_order_feedback.php, or as a webcron with the key of
 * $settings['feedbackCronKey'] (the same as for the reservation feedback): https://your-domain/web/cron/send_order_feedback.php?key=<key>
 */
require __DIR__.'/../../config/config.general.php';
$key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $key || $key === 'CHANGE-ME')) { http_response_code(403); exit('Forbidden'); }
require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require_once __DIR__.'/../classes/shop_feedback.class.php';
date_default_timezone_set('Europe/Berlin');
$n = shop_fb_run(20);
$msg = 'mySeat order feedback cron: '.$n.' sent.';
if ($is_cli) { echo $msg."\n"; } else { header('Content-Type: text/plain'); echo $msg; }
