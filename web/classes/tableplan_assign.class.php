<?php
/*
 * Table plan (Tischplan): area closures, day view of reservations, table assignment and
 * automatic assignment. Builds on tableplan.class.php. None of this changes how bookings are
 * accepted - assignments only describe where a reservation sits.
 *
 * "Active" reservations are the ones the counter based availability also counts:
 * not hidden (cancelled), not on the waiting list, not departed (DEP) or no-show (NSW).
 */
require_once __DIR__ . '/tableplan.class.php';

// most tables that may be pushed together for one reservation by the automatic assignment
const TP_MAX_GROUP = 6;

function tp_res_table() {
	global $dbTables;
	return '`'.$dbTables->reservations.'`';
}

function tp_outlets_table() {
	global $dbTables;
	return '`'.$dbTables->outlets.'`';
}

function tp_is_date($s) {
	if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
		return false;
	}
	return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function tp_min($time) {
	$p = explode(':', (string)$time);
	return ((int)$p[0]) * 60 + (isset($p[1]) ? (int)$p[1] : 0);
}

// average stay of the outlet in minutes ("2:00" -> 120), 120 when unknown
function tp_duration_minutes($outlet_id) {
	$r = tp_rows("SELECT `avg_duration` FROM ".tp_outlets_table()." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
	$m = $r ? tp_min($r[0]['avg_duration']) : 0;
	return $m > 0 ? $m : 120;
}

// opening hours of the outlet on a date as [open, close] in minutes; the daily override wins
function tp_day_hours($outlet_id, $date) {
	$r = tp_rows("SELECT * FROM ".tp_outlets_table()." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
	if (!$r) { return array(0, 24 * 60, 0, 0); }
	$o = $r[0];
	$w = date('w', strtotime($date));
	$custom = isset($o[$w.'_open_time']) && $o[$w.'_open_time'] !== '00:00:00' && $o[$w.'_open_time'] !== '';
	$open  = tp_min($custom ? $o[$w.'_open_time']  : $o['outlet_open_time']);
	$close = tp_min($custom ? $o[$w.'_close_time'] : $o['outlet_close_time']);
	$bo = (isset($o[$w.'_open_break'])  && $o[$w.'_open_break']  !== '00:00:00') ? $o[$w.'_open_break']  : (isset($o['outlet_open_break'])  ? $o['outlet_open_break']  : '00:00:00');
	$bc = (isset($o[$w.'_close_break']) && $o[$w.'_close_break'] !== '00:00:00') ? $o[$w.'_close_break'] : (isset($o['outlet_close_break']) ? $o['outlet_close_break'] : '00:00:00');
	return array($open, $close, tp_min($bo), tp_min($bc));
}

// minute of the day as used for comparing reservations: times before the opening belong to the
// night after midnight (outlet open 14:30 - 00:00, booking at 00:00 = 24:00)
function tp_ctx_min($ctx, $time) {
	$m = tp_min($time);
	return ($ctx['wrap'] !== null && $m < $ctx['wrap']) ? $m + 1440 : $m;
}

/* ---------------------------------------------------------------- area closures */

function tp_ensure_closure_schema() {
	static $done = false;
	if ($done) { return; }
	tp_ensure_schema();
	mysqli_query(tp_db(), "CREATE TABLE IF NOT EXISTS ".tp_t('area_closures')." (
		`closure_id` INT NOT NULL AUTO_INCREMENT,
		`area_id` INT NOT NULL,
		`date_from` DATE NOT NULL,
		`date_to` DATE NULL,
		`yearly` TINYINT NOT NULL DEFAULT 0,
		`note` VARCHAR(80) NOT NULL DEFAULT '',
		PRIMARY KEY (`closure_id`),
		KEY `area_id` (`area_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	$done = true;
}

function tp_list_closures($outlet_id) {
	tp_ensure_closure_schema();
	return tp_rows("SELECT c.`closure_id`,c.`area_id`,c.`date_from`,c.`date_to`,c.`yearly`,c.`note`
		FROM ".tp_t('area_closures')." c JOIN ".tp_t('areas')." a ON a.`area_id` = c.`area_id`
		WHERE a.`outlet_id` = ? ORDER BY c.`date_from`,c.`closure_id`", 'i', array((int)$outlet_id));
}

// does the closure cover the date? yearly closures repeat every year on the same month/day window
function tp_closure_hits($c, $date) {
	if ($date < $c['date_from']) {
		return false;
	}
	if ($c['date_to'] === null || $c['date_to'] === '') {
		return true;
	}
	if (!(int)$c['yearly']) {
		return $date <= $c['date_to'];
	}
	$md = substr($date, 5);
	$f  = substr($c['date_from'], 5);
	$t  = substr($c['date_to'], 5);
	return $f <= $t ? ($md >= $f && $md <= $t) : ($md >= $f || $md <= $t);
}

// ids of the areas that are closed on a date
function tp_closed_area_ids($outlet_id, $date) {
	$out = array();
	foreach (tp_list_closures($outlet_id) as $c) {
		if (tp_closure_hits($c, $date)) {
			$out[(int)$c['area_id']] = true;
		}
	}
	return array_keys($out);
}

// returns the saved closure row or false
function tp_save_closure($outlet_id, $d) {
	tp_ensure_closure_schema();
	$area_id = isset($d['area_id']) ? (int)$d['area_id'] : 0;
	$from = isset($d['date_from']) ? (string)$d['date_from'] : '';
	$to   = isset($d['date_to']) ? (string)$d['date_to'] : '';
	if (!tp_owns_area($outlet_id, $area_id) || !tp_is_date($from)) {
		return false;
	}
	if ($to !== '' && (!tp_is_date($to) || $to < $from)) {
		return false;
	}
	$yearly = (!empty($d['yearly']) && $to !== '') ? 1 : 0;
	$note = mb_substr(trim(isset($d['note']) ? (string)$d['note'] : ''), 0, 80);
	$st = tp_exec("INSERT INTO ".tp_t('area_closures')." (`area_id`,`date_from`,`date_to`,`yearly`,`note`) VALUES (?,?,NULLIF(?,''),?,?)",
		'issis', array($area_id, $from, $to, $yearly, $note));
	if (!$st) { return false; }
	$id = mysqli_stmt_insert_id($st);
	mysqli_stmt_close($st);
	$rows = tp_rows("SELECT `closure_id`,`area_id`,`date_from`,`date_to`,`yearly`,`note` FROM ".tp_t('area_closures')." WHERE `closure_id` = ?", 'i', array($id));
	return $rows ? $rows[0] : false;
}

function tp_delete_closure($outlet_id, $closure_id) {
	tp_ensure_closure_schema();
	$own = tp_rows("SELECT c.`closure_id` FROM ".tp_t('area_closures')." c JOIN ".tp_t('areas')." a ON a.`area_id` = c.`area_id`
		WHERE c.`closure_id` = ? AND a.`outlet_id` = ?", 'ii', array((int)$closure_id, (int)$outlet_id));
	if (!$own) { return false; }
	$st = tp_exec("DELETE FROM ".tp_t('area_closures')." WHERE `closure_id` = ?", 'i', array((int)$closure_id));
	if ($st) { mysqli_stmt_close($st); }
	return true;
}

/* ---------------------------------------------------------------- day context */

// everything needed to judge assignments of one day:
//   res      reservation_id => row (time, min, pax, name, notes, status, number, tables[])
//   tables   table_id => row
//   closed   area_id => true
//   occ      table_id => list of reservation ids sitting there
//   dur      stay in minutes
function tp_day_context($outlet_id, $date) {
	tp_ensure_closure_schema();
	$rows = tp_rows("SELECT `reservation_id`,`reservation_time`,`reservation_pax`,`reservation_guest_name`,`reservation_notes`,`reservation_status`,`reservation_bookingnumber`
		FROM ".tp_res_table()."
		WHERE `reservation_outlet_id` = ? AND `reservation_date` = ?
		AND IFNULL(`reservation_hidden`,0) = 0 AND IFNULL(`reservation_wait`,0) = 0
		AND (`reservation_status` IS NULL OR `reservation_status` NOT IN ('DEP','NSW'))
		ORDER BY `reservation_time`,`reservation_id`", 'is', array((int)$outlet_id, $date));
	$hours = tp_day_hours($outlet_id, $date);
	$ctx = array('date' => $date, 'res' => array(), 'tables' => array(), 'closed' => array(), 'occ' => array(), 'dur' => tp_duration_minutes($outlet_id),
		'wrap' => ($hours[1] <= $hours[0]) ? $hours[0] : null);
	foreach (tp_list_tables($outlet_id) as $t) {
		$ctx['tables'][(int)$t['table_id']] = $t;
	}
	foreach (tp_closed_area_ids($outlet_id, $date) as $a) {
		$ctx['closed'][$a] = true;
	}
	foreach ($rows as $r) {
		$id = (int)$r['reservation_id'];
		$ctx['res'][$id] = array(
			'id'     => $id,
			'time'   => substr((string)$r['reservation_time'], 0, 5),
			'min'    => tp_ctx_min($ctx, $r['reservation_time']),
			'pax'    => (int)$r['reservation_pax'],
			'name'   => html_entity_decode((string)$r['reservation_guest_name'], ENT_QUOTES, 'UTF-8'),
			'notes'  => html_entity_decode((string)$r['reservation_notes'], ENT_QUOTES, 'UTF-8'),
			'status' => (string)$r['reservation_status'],
			'number' => (string)$r['reservation_bookingnumber'],
			'tables' => array(),
			'forced' => false,
		);
	}
	if ($ctx['res']) {
		$ids = implode(',', array_map('intval', array_keys($ctx['res'])));
		foreach (tp_rows("SELECT `reservation_id`,`table_id`,`forced` FROM ".tp_t('reservation_tables')." WHERE `reservation_id` IN ($ids)") as $a) {
			$rid = (int)$a['reservation_id']; $tid = (int)$a['table_id'];
			if (!isset($ctx['tables'][$tid])) { continue; }
			$ctx['res'][$rid]['tables'][] = $tid;
			if ((int)$a['forced']) { $ctx['res'][$rid]['forced'] = true; }
			$ctx['occ'][$tid][] = $rid;
		}
	}
	return $ctx;
}

// do two reservations of the same day overlap in time at one table?
function tp_overlaps($min_a, $min_b, $dur) {
	return abs($min_a - $min_b) < $dur;
}

/*
 * Judge putting a reservation (pax, minute of day) on a set of tables.
 * Returns array('errors' => [...], 'warnings' => [...]); errors can not be overridden.
 */
function tp_judge($ctx, $res_id, $pax, $min, $table_ids) {
	$errors = array(); $warnings = array();
	if (!$table_ids) {
		return array('errors' => $errors, 'warnings' => $warnings);
	}
	$seats = 0;
	foreach ($table_ids as $tid) {
		$t = $ctx['tables'][$tid];
		$seats += (int)$t['seats'];
		if (isset($ctx['closed'][(int)$t['area_id']])) {
			$errors[] = 'Tisch '.$t['table_name'].' liegt in einem Bereich, der an diesem Tag gesperrt ist';
		}
		if (isset($ctx['occ'][$tid])) {
			foreach ($ctx['occ'][$tid] as $other) {
				if ($other === $res_id || !isset($ctx['res'][$other])) { continue; }
				if (tp_overlaps($min, $ctx['res'][$other]['min'], $ctx['dur'])) {
					$warnings[] = 'Tisch '.$t['table_name'].' ist um '.$ctx['res'][$other]['time'].' Uhr bereits für '.$ctx['res'][$other]['name'].' vergeben';
				}
			}
		}
	}
	if ($seats < $pax) {
		$warnings[] = 'Kapazität: '.$seats.' Sitzplätze für '.$pax.' Personen';
	}
	return array('errors' => $errors, 'warnings' => $warnings);
}

// the day as sent to the browser: reservations with their tables and current conflicts
function tp_day_payload($outlet_id, $date) {
	$ctx = tp_day_context($outlet_id, $date);
	$list = array();
	foreach ($ctx['res'] as $r) {
		$j = tp_judge($ctx, $r['id'], $r['pax'], $r['min'], $r['tables']);
		$r['conflicts'] = array_merge($j['errors'], $j['warnings']);
		$list[] = $r;
	}
	// opening hours of the day in minutes (closing after midnight counts past 24:00), for the time strips
	$hours = tp_day_hours($outlet_id, $date);
	$open = $hours[0];
	$close = ($hours[1] <= $hours[0]) ? $hours[1] + 1440 : $hours[1];
	return array('date' => $date, 'dur' => $ctx['dur'], 'open' => $open, 'close' => $close, 'closed' => array_keys($ctx['closed']), 'reservations' => $list);
}

/* ---------------------------------------------------------------- assignment */

function tp_reservation_row($outlet_id, $res_id) {
	$r = tp_rows("SELECT `reservation_id`,`reservation_date`,`reservation_time`,`reservation_pax` FROM ".tp_res_table()."
		WHERE `reservation_id` = ? AND `reservation_outlet_id` = ?", 'ii', array((int)$res_id, (int)$outlet_id));
	return $r ? $r[0] : false;
}

function tp_write_assignment($res_id, $table_ids, $forced) {
	$st = tp_exec("DELETE FROM ".tp_t('reservation_tables')." WHERE `reservation_id` = ?", 'i', array((int)$res_id));
	if ($st) { mysqli_stmt_close($st); }
	foreach ($table_ids as $tid) {
		$st = tp_exec("INSERT INTO ".tp_t('reservation_tables')." (`reservation_id`,`table_id`,`forced`) VALUES (?,?,?)", 'iii', array((int)$res_id, (int)$tid, $forced ? 1 : 0));
		if ($st) { mysqli_stmt_close($st); }
	}
}

/*
 * Set the tables of a reservation (empty list = remove the assignment).
 * Returns array('ok' => true, 'date' => ...) or array('ok' => false, 'error' => ..., 'needs_confirm' => bool, 'warnings' => [...])
 */
function tp_assign($outlet_id, $res_id, $table_ids, $confirm) {
	$row = tp_reservation_row($outlet_id, $res_id);
	if (!$row) {
		return array('ok' => false, 'error' => 'Reservierung nicht gefunden');
	}
	$date = $row['reservation_date'];
	$ctx = tp_day_context($outlet_id, $date);
	$ids = array();
	foreach ((array)$table_ids as $t) {
		$t = (int)$t;
		if (!isset($ctx['tables'][$t])) {
			return array('ok' => false, 'error' => 'Unbekannter Tisch');
		}
		$ids[$t] = $t;
	}
	$ids = array_values($ids);
	$j = tp_judge($ctx, (int)$res_id, (int)$row['reservation_pax'], tp_ctx_min($ctx, $row['reservation_time']), $ids);
	if ($j['errors']) {
		return array('ok' => false, 'error' => implode('. ', $j['errors']));
	}
	if ($j['warnings'] && !$confirm) {
		return array('ok' => false, 'needs_confirm' => true, 'warnings' => $j['warnings']);
	}
	tp_write_assignment($res_id, $ids, (bool)$j['warnings']);
	return array('ok' => true, 'date' => $date);
}

function tp_list_links_all($table_ids) {
	if (!$table_ids) { return array(); }
	$ids = implode(',', array_map('intval', $table_ids));
	return tp_rows("SELECT `table_a`,`table_b` FROM ".tp_t('table_links')." WHERE `table_a` IN ($ids) AND `table_b` IN ($ids)");
}

// tables that could take a reservation, best fit first; null when nothing fits
function tp_find_tables($ctx, $res_id, $pax, $min) {
	$free = array();
	foreach ($ctx['tables'] as $tid => $t) {
		if (!(int)$t['active'] || isset($ctx['closed'][(int)$t['area_id']])) { continue; }
		$busy = false;
		if (isset($ctx['occ'][$tid])) {
			foreach ($ctx['occ'][$tid] as $o) {
				if ($o !== $res_id && isset($ctx['res'][$o]) && tp_overlaps($min, $ctx['res'][$o]['min'], $ctx['dur'])) { $busy = true; break; }
			}
		}
		if (!$busy) { $free[$tid] = $t; }
	}
	// a single table: the smallest one that is big enough
	$best = null;
	foreach ($free as $tid => $t) {
		$s = (int)$t['seats'];
		if ($s >= $pax && ($best === null || $s < $best[0])) { $best = array($s, $tid); }
	}
	if ($best) { return array($best[1]); }

	// tables that may be pushed together: every connected group of up to TP_MAX_GROUP free tables
	// (a chain A-B-C-D-E counts as connected, whatever order it was linked in), least waste first
	$adj = array();
	foreach (tp_list_links_all(array_keys($free)) as $l) {
		$a = (int)$l['table_a']; $b = (int)$l['table_b'];
		$adj[$a][$b] = true; $adj[$b][$a] = true;
	}
	$bestSet = null; $bestScore = null; $budget = 200000;
	// ESU: lists each connected group exactly once, starting from its lowest table id v
	$extend = function ($sub, $seats, $ext, $v) use (&$extend, &$bestSet, &$bestScore, &$budget, $adj, $free, $pax) {
		if ($budget-- <= 0) { return; }
		if ($seats >= $pax) {
			$score = array($seats - $pax, count($sub));
			if ($bestScore === null || $score < $bestScore) { $bestScore = $score; $bestSet = $sub; }
			return;   // more tables would only add waste
		}
		if (count($sub) >= TP_MAX_GROUP) { return; }
		while ($ext) {
			$w = array_pop($ext);
			$next = $ext;
			if (isset($adj[$w])) {
				foreach ($adj[$w] as $u => $_) {
					if ($u <= $v || in_array($u, $sub, true) || in_array($u, $next, true) || $u === $w) { continue; }
					// exclusive neighbour: not already next to a table of the group
					$touches = false;
					foreach ($sub as $s) { if (isset($adj[$s][$u])) { $touches = true; break; } }
					if (!$touches) { $next[] = $u; }
				}
			}
			$extend(array_merge($sub, array($w)), $seats + (int)$free[$w]['seats'], $next, $v);
		}
	};
	$ids = array_keys($adj);
	sort($ids);
	foreach ($ids as $v) {
		$ext = array();
		foreach ($adj[$v] as $u => $_) { if ($u > $v) { $ext[] = $u; } }
		$extend(array($v), (int)$free[$v]['seats'], $ext, $v);
	}
	return $bestSet;
}

// automatic assignment of one reservation; returns the table ids or null (already assigned / nothing fits)
function tp_auto_assign($outlet_id, $res_id) {
	$row = tp_reservation_row($outlet_id, $res_id);
	if (!$row || !$row['reservation_date']) { return null; }
	$ctx = tp_day_context($outlet_id, $row['reservation_date']);
	if (!isset($ctx['res'][(int)$res_id]) || $ctx['res'][(int)$res_id]['tables'] || !$ctx['tables']) { return null; }
	$found = tp_find_tables($ctx, (int)$res_id, (int)$row['reservation_pax'], tp_ctx_min($ctx, $row['reservation_time']));
	if (!$found) { return null; }
	tp_write_assignment($res_id, $found, false);
	return $found;
}

// automatic assignment of all unassigned reservations of a day, larger parties first
function tp_auto_assign_day($outlet_id, $date) {
	$ctx = tp_day_context($outlet_id, $date);
	$todo = array_filter($ctx['res'], function ($r) { return !$r['tables']; });
	uasort($todo, function ($a, $b) {
		return ($b['pax'] <=> $a['pax']) ?: ($a['min'] <=> $b['min']);
	});
	$done = 0; $open = 0;
	foreach ($todo as $r) {
		$found = $ctx['tables'] ? tp_find_tables($ctx, $r['id'], $r['pax'], $r['min']) : null;
		if (!$found) { $open++; continue; }
		tp_write_assignment($r['id'], $found, false);
		foreach ($found as $tid) { $ctx['occ'][$tid][] = $r['id']; }
		$ctx['res'][$r['id']]['tables'] = $found;
		$done++;
	}
	return array('assigned' => $done, 'open' => $open);
}

// called after a booking was stored; must never break the booking itself
function tp_hook_after_booking($res_id) {
	try {
		if (!$res_id || !isset($GLOBALS['__mysql_compat_link'])) { return; }
		tp_ensure_schema();
		if (tp_get_setting('auto_assign', '1') !== '1') { return; }
		$r = tp_rows("SELECT `reservation_outlet_id`,`reservation_wait` FROM ".tp_res_table()." WHERE `reservation_id` = ?", 'i', array((int)$res_id));
		if (!$r || (int)$r[0]['reservation_wait']) { return; }
		tp_auto_assign((int)$r[0]['reservation_outlet_id'], (int)$res_id);
	} catch (Throwable $e) {
		// assignment is optional
	}
}

/* ---------------------------------------------------------------- online availability by tables */

// 'counter' (old logic: seats/tables counters) or 'tables' (table plan)
function tp_availability_mode() {
	tp_ensure_schema();
	return tp_get_setting('availability_mode', 'counter') === 'tables' ? 'tables' : 'counter';
}

function tp_active_table_count($outlet_id) {
	$r = tp_rows("SELECT COUNT(*) AS n FROM ".tp_t('tables')." WHERE `outlet_id` = ? AND `active` = 1", 'i', array((int)$outlet_id));
	return $r ? (int)$r[0]['n'] : 0;
}

// the day with reservations that have no table yet placed on tables in memory only (nothing is
// written), so that they block their tables for guests booking online right now
function tp_virtual_ctx($outlet_id, $date) {
	$ctx = tp_day_context($outlet_id, $date);
	$todo = array_filter($ctx['res'], function ($r) { return !$r['tables']; });
	uasort($todo, function ($a, $b) {
		return ($b['pax'] <=> $a['pax']) ?: ($a['min'] <=> $b['min']);
	});
	foreach ($todo as $r) {
		$found = tp_find_tables($ctx, $r['id'], $r['pax'], $r['min']);
		if (!$found) { continue; }
		foreach ($found as $tid) { $ctx['occ'][$tid][] = $r['id']; }
		$ctx['res'][$r['id']]['tables'] = $found;
	}
	return $ctx;
}

/*
 * Would a new online booking of $pax guests at $time (HH:MM) on $date find tables?
 * Returns true/false, or null when the table plan does not decide (mode 'counter', no tables,
 * or any error) - callers then keep using the counter logic.
 */
function tp_online_fits($outlet_id, $date, $pax, $time) {
	static $cache = array();
	try {
		if (tp_availability_mode() !== 'tables') { return null; }
		$key = (int)$outlet_id.'|'.$date;
		if (!isset($cache[$key])) {
			$cache[$key] = tp_virtual_ctx($outlet_id, $date);
		}
		$ctx = $cache[$key];
		if (!$ctx['tables'] || tp_active_table_count($outlet_id) < 1) { return null; }
		return tp_find_tables($ctx, 0, (int)$pax, tp_ctx_min($ctx, $time)) !== null;
	} catch (Throwable $e) {
		return null;
	}
}

// the bookable time slots of a day ("HH:MM" strings), following the rules of the online form
function tp_day_slots($outlet_id, $date, $interval) {
	list($open, $close, $bo, $bc) = tp_day_hours($outlet_id, $date);
	global $settings;
	$last = isset($settings['lastBookingMinutes']) ? max(0, (int)$settings['lastBookingMinutes']) : 60;
	if ($close <= $open) { $close += 1440; }
	$end = $close - $last;
	$interval = max(5, (int)$interval);
	$from = $open;
	if ($date === date('Y-m-d')) {
		$now = (int)date('G') * 60 + (int)date('i');
		$from = max($from, (int)ceil($now / $interval) * $interval);
	}
	$out = array();
	for ($m = $open; $m <= $end; $m += $interval) {
		if ($m < $from) { continue; }
		$mm = $m % 1440;
		if ($mm <= $bo || $mm >= $bc) {
			$out[] = sprintf('%02d:%02d', intdiv($mm, 60), $mm % 60);
		}
	}
	return $out;
}

// what the table plan would offer online for a party size: list of [time, fits]
function tp_online_preview($outlet_id, $date, $pax, $interval) {
	$ctx = tp_virtual_ctx($outlet_id, $date);
	$out = array();
	foreach (tp_day_slots($outlet_id, $date, $interval) as $t) {
		$out[] = array('time' => $t, 'fits' => $ctx['tables'] ? (tp_find_tables($ctx, 0, (int)$pax, tp_ctx_min($ctx, $t)) !== null) : false);
	}
	return $out;
}

// limits of the old counter logic next to what the table plan offers, for the settings box
function tp_counter_info($outlet_id) {
	$r = tp_rows("SELECT `outlet_max_capacity`,`outlet_max_tables` FROM ".tp_outlets_table()." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
	$s = tp_rows("SELECT COUNT(*) AS n, IFNULL(SUM(`seats`),0) AS seats FROM ".tp_t('tables')." WHERE `outlet_id` = ? AND `active` = 1", 'i', array((int)$outlet_id));
	return array(
		'maxCapacity' => $r ? (int)$r[0]['outlet_max_capacity'] : 0,
		'maxTables'   => $r ? (int)$r[0]['outlet_max_tables'] : 0,
		'planTables'  => $s ? (int)$s[0]['n'] : 0,
		'planSeats'   => $s ? (int)$s[0]['seats'] : 0,
	);
}

// why guests can not book online on a date at all (null = day is bookable): closed weekday,
// day off of the daily settings, or the online block
function tp_online_day_block_reason($outlet_id, $date) {
	global $dbTables;
	$o = tp_rows("SELECT `outlet_closeday` FROM ".tp_outlets_table()." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
	$closed = $o ? array_filter(explode(',', (string)$o[0]['outlet_closeday']), 'strlen') : array();
	$off = in_array((string)date('w', strtotime($date)), $closed, true);
	$m = tp_rows("SELECT `outlet_child_dayoff` FROM `".$dbTables->maitre."` WHERE `maitre_outlet_id` = ? AND `maitre_date` = ?", 'is', array((int)$outlet_id, $date));
	if ($m && $m[0]['outlet_child_dayoff'] === 'ON') { $off = true; }
	if ($m && $m[0]['outlet_child_dayoff'] === 'OFF') { $off = false; }
	if ($off) { return 'An diesem Tag ist geschlossen (Ruhetag).'; }
	if (function_exists('ob_is_blocked') && ob_is_blocked($outlet_id, $date)) { return 'Online-Reservierungen sind für diesen Tag gesperrt.'; }
	return null;
}

/*
 * The tables of an outlet as offered in the reservation form for a date, time and party size:
 * state 'free', 'busy' (taken within the stay of another reservation) or 'closed' (area closed
 * that day), plus the automatic suggestion. $exclude_res is the reservation being edited.
 */
function tp_table_options($outlet_id, $date, $time, $pax, $exclude_res = 0) {
	$ctx = tp_day_context($outlet_id, $date);
	$min = ($time !== '') ? tp_ctx_min($ctx, $time) : null;
	$areas = tp_list_areas($outlet_id);
	$order = array();
	foreach ($areas as $i => $a) { $order[(int)$a['area_id']] = $i; }
	$out = array();
	foreach ($ctx['tables'] as $tid => $t) {
		if (!(int)$t['active']) { continue; }
		$state = 'free'; $by = '';
		if (isset($ctx['closed'][(int)$t['area_id']])) {
			$state = 'closed';
		} elseif ($min !== null && isset($ctx['occ'][$tid])) {
			foreach ($ctx['occ'][$tid] as $rid) {
				if ($rid === (int)$exclude_res || !isset($ctx['res'][$rid])) { continue; }
				if (tp_overlaps($min, $ctx['res'][$rid]['min'], $ctx['dur'])) {
					$state = 'busy'; $by = $ctx['res'][$rid]['time'].' '.$ctx['res'][$rid]['name']; break;
				}
			}
		}
		$out[] = array('table_id' => (int)$tid, 'name' => $t['table_name'], 'seats' => (int)$t['seats'],
			'area_id' => (int)$t['area_id'], 'state' => $state, 'by' => $by);
	}
	usort($out, function ($a, $b) use ($order) {
		$oa = isset($order[$a['area_id']]) ? $order[$a['area_id']] : 99;
		$ob = isset($order[$b['area_id']]) ? $order[$b['area_id']] : 99;
		return ($oa <=> $ob) ?: strnatcasecmp($a['name'], $b['name']);
	});
	$auto = array();
	if ($min !== null && (int)$pax > 0 && $out) {
		$v = tp_virtual_ctx($outlet_id, $date);
		$found = tp_find_tables($v, (int)$exclude_res, (int)$pax, tp_ctx_min($v, $time));
		if ($found) { $auto = array_values($found); }
	}
	return array('areas' => array_map(function ($a) { return array('area_id' => (int)$a['area_id'], 'area_name' => $a['area_name']); }, $areas),
		'tables' => $out, 'auto' => $auto, 'dur' => $ctx['dur']);
}
