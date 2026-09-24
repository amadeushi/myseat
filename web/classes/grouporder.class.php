<?php
/*
 * Group pre-order (n8n workflow "Gruppenbestellung"): creates a group for a reservation so the guest,
 * as organizer, receives the participant and organizer links by mail. The n8n webhook is the same one
 * its own form posts to (action=create, group_name, organizer_email, pickup_at, optional deadline).
 * go_group_orders remembers which reservation already has a group, so it is never created twice.
 */
require_once __DIR__.'/feedback.class.php';

function go_ensure_schema() {
	static $done = false;
	if ($done) { return; }
	mysqli_query(fb_db(), "CREATE TABLE IF NOT EXISTS ".fb_t('tp_group_orders')." (
		`reservation_id` INT NOT NULL PRIMARY KEY,
		`organizer_email` VARCHAR(255) NOT NULL,
		`pickup_at` DATETIME NOT NULL,
		`deadline` DATETIME NULL,
		`created_at` DATETIME NOT NULL,
		`created_by` VARCHAR(255) NOT NULL DEFAULT ''
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

function go_webhook_url() {
	global $settings;
	return !empty($settings['groupOrderUrl']) ? $settings['groupOrderUrl'] : 'https://n8n.amds.at/webhook/gruppenbestellung';
}

function go_find($reservation_id) {
	go_ensure_schema();
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_group_orders')." WHERE reservation_id = ".(int)$reservation_id." LIMIT 1");
	return $rows ? $rows[0] : null;
}

/*
 * Posts the create request. $pickup / $deadline are 'Y-m-d H:i' strings ($deadline may be ''). Returns
 * array('ok' => bool, 'http' => int, 'body' => string, 'error' => string).
 */
function go_post($group_name, $organizer_email, $pickup, $deadline) {
	$fields = array(
		'action' => 'create',
		'group_name' => $group_name,
		'organizer_email' => $organizer_email,
		'pickup_at' => str_replace(' ', 'T', $pickup),
	);
	if ($deadline !== '') { $fields['deadline'] = str_replace(' ', 'T', $deadline); }
	$ch = curl_init(go_webhook_url());
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($fields),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 25,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_FOLLOWLOCATION => false,
	));
	$body = curl_exec($ch);
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err = curl_error($ch);
	curl_close($ch);
	// n8n answers with a page; its success page says "Gruppe erstellt", anything else counts as not created
	$created = ($body !== false && $http >= 200 && $http < 300 && strpos((string)$body, 'Gruppe erstellt') !== false);
	return array('ok' => $created, 'http' => $http, 'body' => (string)$body, 'error' => $err);
}
