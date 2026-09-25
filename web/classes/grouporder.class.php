<?php
/*
 * Group pre-order (n8n workflow "Gruppenbestellung"): creates a group for a reservation so the guest,
 * as organizer, receives the participant and organizer links by mail. mySeat talks to the machine
 * interface of the webhook: every request carries the header X-Api-Key ($settings['groupOrderApiKey'])
 * and n8n answers JSON ({"ok":true,...} or {"ok":false,"error":"..."}).
 *   create        group_name, organizer_name, organizer_email, pickup_at, deadline, reservation_id,
 *                 reservation_number; n8n never creates a second group for the same reservation_id
 *   update_pickup reservation_id, pickup_at, deadline: moves the group when the reservation moves
 * go_group_orders remembers which reservation has a group and the links n8n returned.
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
		`created_by` VARCHAR(255) NOT NULL DEFAULT '',
		`group_token` VARCHAR(40) NULL,
		`participant_url` VARCHAR(255) NULL,
		`organizer_url` VARCHAR(255) NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	// tables from v1.1.0 have no link columns yet
	$have = array();
	$res = mysqli_query(fb_db(), "SHOW COLUMNS FROM ".fb_t('tp_group_orders'));
	while ($res && ($c = mysqli_fetch_assoc($res))) { $have[$c['Field']] = true; }
	foreach (array('group_token' => 'VARCHAR(40) NULL', 'participant_url' => 'VARCHAR(255) NULL', 'organizer_url' => 'VARCHAR(255) NULL') as $col => $def) {
		if (!isset($have[$col])) { mysqli_query(fb_db(), "ALTER TABLE ".fb_t('tp_group_orders')." ADD `".$col."` ".$def); }
	}
	$done = true;
}

function go_webhook_url() {
	global $settings;
	return !empty($settings['groupOrderUrl']) ? $settings['groupOrderUrl'] : 'https://n8n.amds.at/webhook/gruppenbestellung';
}

function go_api_key() {
	global $settings;
	return !empty($settings['groupOrderApiKey']) ? (string)$settings['groupOrderApiKey'] : '';
}

function go_find($reservation_id) {
	go_ensure_schema();
	$rows = fb_rows("SELECT * FROM ".fb_t('tp_group_orders')." WHERE reservation_id = ".(int)$reservation_id." LIMIT 1");
	return $rows ? $rows[0] : null;
}

/*
 * One request to the machine interface. $fields: form fields including 'action'. Returns the decoded
 * answer (always with 'ok'); a transport problem or an answer that is not JSON becomes
 * array('ok' => false, 'error' => ..., 'transport' => true).
 */
function go_api($fields) {
	$key = go_api_key();
	if ($key === '') { return array('ok' => false, 'error' => 'In config.general.php fehlt $settings[\'groupOrderApiKey\'].', 'transport' => true); }
	$ch = curl_init(go_webhook_url());
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => http_build_query($fields),
		CURLOPT_HTTPHEADER => array('X-Api-Key: '.$key, 'Accept: application/json'),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 25,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_FOLLOWLOCATION => false,
	));
	$body = curl_exec($ch);
	$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$err = curl_error($ch);
	$data = ($body !== false) ? json_decode((string)$body, true) : null;
	if (!is_array($data) || !isset($data['ok'])) {
		error_log('mySeat group order '.(isset($fields['action']) ? $fields['action'] : '').': HTTP '.$http.' '.$err.' '.substr(strip_tags((string)$body), 0, 200));
		return array('ok' => false, 'error' => 'n8n hat nicht wie erwartet geantwortet (HTTP '.$http.').', 'transport' => true);
	}
	return $data;
}

// 'Y-m-d H:i' -> 'Y-m-d\TH:i' as the webhook expects it ('' stays '')
function go_stamp($local) {
	return $local === '' ? '' : str_replace(' ', 'T', substr($local, 0, 16));
}

/*
 * Creates the group for reservation row $r (reservation_id, reservation_bookingnumber).
 * $pickup / $deadline are 'Y-m-d H:i' ($deadline may be '').
 */
function go_create($r, $name, $email, $pickup, $deadline) {
	return go_api(array(
		'action' => 'create',
		'group_name' => $name,
		'organizer_name' => $name,
		'organizer_email' => $email,
		'pickup_at' => go_stamp($pickup),
		'deadline' => go_stamp($deadline),
		'reservation_id' => (string)(int)$r['reservation_id'],
		'reservation_number' => isset($r['reservation_bookingnumber']) ? (string)$r['reservation_bookingnumber'] : '',
	));
}

/*
 * After a reservation was saved: when it has a group and its date or time changed, move the group to
 * the new visit time. The order deadline keeps its distance to the visit. Never breaks the save; a
 * failure only goes to the error log.
 */
function go_sync_pickup($reservation_id) {
	global $dbTables;
	if (go_api_key() === '') { return; }
	$go = go_find($reservation_id);
	if (!$go || empty($go['group_token'])) { return; }
	$rows = fb_rows("SELECT reservation_date, reservation_time FROM `".$dbTables->reservations."` WHERE reservation_id = ? LIMIT 1", 'i', array((int)$reservation_id));
	if (!$rows) { return; }
	$new_pickup = substr($rows[0]['reservation_date'], 0, 10).' '.substr($rows[0]['reservation_time'], 0, 5);
	$old_pickup = substr($go['pickup_at'], 0, 16);
	if ($new_pickup === $old_pickup) { return; }
	// the deadline moves by the same number of days and keeps its time of day; it always stays before the visit
	$new_deadline = '';
	if (!empty($go['deadline'])) {
		$days = (int)round((strtotime(substr($new_pickup, 0, 10)) - strtotime(substr($old_pickup, 0, 10))) / 86400);
		$new_deadline = date('Y-m-d H:i', strtotime(substr($go['deadline'], 0, 16).' '.($days >= 0 ? '+' : '').$days.' days'));
		if ($new_deadline >= $new_pickup) { $new_deadline = date('Y-m-d H:i', strtotime($new_pickup) - 3600); }
	}
	$res = go_api(array(
		'action' => 'update_pickup',
		'reservation_id' => (string)(int)$reservation_id,
		'pickup_at' => go_stamp($new_pickup),
		'deadline' => go_stamp($new_deadline),
	));
	if (empty($res['ok'])) {
		error_log('mySeat group order: moving the group of reservation '.(int)$reservation_id.' failed: '.(isset($res['error']) ? $res['error'] : ''));
		return;
	}
	$dl = ($new_deadline === '') ? null : $new_deadline;
	$st = fb_exec("UPDATE ".fb_t('tp_group_orders')." SET pickup_at = ?, deadline = ? WHERE reservation_id = ?", 'ssi', array($new_pickup, $dl, (int)$reservation_id));
	if ($st) { mysqli_stmt_close($st); }
}
