<?php
/*
 * Retries SMS that the gateway could not take yet (it accepts 10 new jobs per minute and can be down
 * for a moment). Meant to run every minute as a webcron job, same key as the other crons:
 *     https://your-domain/web/cron/sms_flush.php?key=<$settings['feedbackCronKey']>
 * With &health=1 it only tests the connection and the key against the gateway (sends nothing).
 */

require __DIR__.'/../../config/config.general.php';

$cron_key = !empty($settings['feedbackCronKey']) ? $settings['feedbackCronKey'] : 'CHANGE-ME';
$is_cli = (php_sapi_name() === 'cli');
if (!$is_cli && (!isset($_GET['key']) || $_GET['key'] !== $cron_key || $cron_key === 'CHANGE-ME')) {
	http_response_code(403);
	exit('Forbidden');
}

require __DIR__.'/../classes/mysql_compat.php';
require __DIR__.'/../classes/connect.db.php';
require __DIR__.'/../classes/sms.class.php';
header('Content-Type: text/plain; charset=utf-8');

if (!sms_enabled()) { exit("SMS is off (set smsEnabled and smsApiKey in config.general.php).\n"); }
if (!empty($_GET['health'])) {
	$h = sms_health();
	exit($h['ok'] ? 'Gateway ok'.($h['project'] ? ' (project '.$h['project'].')' : '')."\n" : 'Gateway problem: '.$h['error']."\n");
}
$tried = sms_flush(8);
$s = sms_stats();
echo 'mySeat SMS flush: '.$tried.' tried, '.$s['queued'].' waiting, '.$s['accepted_today'].' accepted today, '.$s['failed_week']." failed in 7 days.\n";
