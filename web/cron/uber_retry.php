<?php
/*
 * Reads failed Uber Eats receipts again (see shop_uber_retry() in web/classes/shop_uber.class.php): an outage of the reader (OpenAI, n8n) is over after a few minutes, the receipt of
 * that time is read then and becomes an order if it is still fresh (45 minutes). Call it every 2 minutes: php /path/to/web/cron/uber_retry.php, or as a webcron with the key of
 * $settings['feedbackCronKey'] (the same as for the other crons): https://your-domain/web/cron/uber_retry.php?key=<key>
 * The open pages "Bestellungen" and "Küche" do the same while they are open, the cron is for the times when nobody has them open.
 */
require __DIR__.'/../../config/config.general.php';
$key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $key || $key === 'CHANGE-ME')) { http_response_code(403); exit('Forbidden'); }
require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require __DIR__.'/../classes/database.class.php';
require __DIR__.'/../classes/db_queries.db.php';
require __DIR__.'/../classes/business.class.php';
require_once __DIR__.'/../classes/shop.class.php';
require_once __DIR__.'/../classes/shop_uber.class.php';
date_default_timezone_set('Europe/Berlin');
@set_time_limit(150);
$n = shop_uber_retry(2);
$msg = 'mySeat uber retry: '.$n.' receipt(s) read again.';
if ($is_cli) { echo $msg."\n"; } else { header('Content-Type: text/plain'); echo $msg; }
