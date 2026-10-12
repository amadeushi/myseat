<?php
/*
 * Sends the next mails of a running newsletter mailing and of the newsletter automations (see web/classes/shop_newsletter_mail.class.php): 30 mails per run, so call it every minute:
 * php /path/to/web/cron/send_newsletter.php, or as a webcron with the key of $settings['feedbackCronKey'] (the same as for the other crons):
 * https://your-domain/web/cron/send_newsletter.php?key=<key>
 * Without a cron job a running mailing still moves on while the page "Newsletter" of the backend is open (10 mails a minute).
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
date_default_timezone_set('Europe/Berlin');
$n = shop_nlm_run(SHOP_NLM_PER_RUN, 40);
$a = shop_nla_run(SHOP_NLM_PER_RUN, 20);   // the automations (welcome, reminders, win-back, sunset); all of them are off until switched on in the backend
$msg = 'mySeat newsletter cron: '.$n.' sent, '.$a.' automatic.';
if ($is_cli) { echo $msg."\n"; } else { header('Content-Type: text/plain'); echo $msg; }
