<?php
/*
 * Table plan (Tischplan): data access.
 *
 * Own tables (created on first use, prefix from config):
 *   tp_tables              the tables of an outlet incl. their place on the plan
 *   tp_table_links         pairs of tables that may be pushed together for larger parties
 *   tp_reservation_tables  which table(s) a reservation sits at (one reservation, many tables)
 *   tp_settings            small key/value store (e.g. table based online availability on/off)
 *
 * All queries use prepared statements on the mysqli link that mysql_compat.php keeps.
 */

// logical size of the plan canvas; the browser scales it to the available width
const TP_CANVAS_W = 1200;
const TP_CANVAS_H = 700;

function tp_db() {
	return $GLOBALS['__mysql_compat_link'];
}

function tp_t($name) {
	global $settings;
	$prefix = isset($settings['dbTablePrefix']) ? $settings['dbTablePrefix'] : '';
	return '`'.$prefix.'tp_'.$name.'`';
}

// run a prepared statement; returns the statement (already executed) or false
function tp_exec($sql, $types = '', $params = array()) {
	$db = tp_db();
	$st = mysqli_prepare($db, $sql);
	if (!$st) {
		return false;
	}
	if ($types !== '') {
		mysqli_stmt_bind_param($st, $types, ...$params);
	}
	if (!mysqli_stmt_execute($st)) {
		mysqli_stmt_close($st);
		return false;
	}
	return $st;
}

// run a SELECT and return all rows as associative arrays
function tp_rows($sql, $types = '', $params = array()) {
	$st = tp_exec($sql, $types, $params);
	if (!$st) {
		return array();
	}
	$res = mysqli_stmt_get_result($st);
	$rows = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : array();
	mysqli_stmt_close($st);
	return $rows;
}

function tp_ensure_schema() {
	static $done = false;
	if ($done) {
		return;
	}
	$db = tp_db();
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".tp_t('areas')." (
		`area_id` INT NOT NULL AUTO_INCREMENT,
		`outlet_id` INT NOT NULL,
		`area_name` VARCHAR(40) NOT NULL,
		`sort_order` INT NOT NULL DEFAULT 0,
		PRIMARY KEY (`area_id`),
		KEY `outlet_id` (`outlet_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".tp_t('tables')." (
		`table_id` INT NOT NULL AUTO_INCREMENT,
		`outlet_id` INT NOT NULL,
		`area_id` INT NOT NULL DEFAULT 0,
		`table_name` VARCHAR(40) NOT NULL,
		`seats` INT NOT NULL DEFAULT 2,
		`shape` VARCHAR(10) NOT NULL DEFAULT 'rect',
		`x` INT NOT NULL DEFAULT 20,
		`y` INT NOT NULL DEFAULT 20,
		`w` INT NOT NULL DEFAULT 90,
		`h` INT NOT NULL DEFAULT 90,
		`rot` INT NOT NULL DEFAULT 0,
		`active` TINYINT NOT NULL DEFAULT 1,
		PRIMARY KEY (`table_id`),
		KEY `outlet_id` (`outlet_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".tp_t('table_links')." (
		`link_id` INT NOT NULL AUTO_INCREMENT,
		`outlet_id` INT NOT NULL,
		`table_a` INT NOT NULL,
		`table_b` INT NOT NULL,
		PRIMARY KEY (`link_id`),
		UNIQUE KEY `pair` (`table_a`,`table_b`),
		KEY `outlet_id` (`outlet_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".tp_t('reservation_tables')." (
		`reservation_id` INT NOT NULL,
		`table_id` INT NOT NULL,
		`forced` TINYINT NOT NULL DEFAULT 0,
		PRIMARY KEY (`reservation_id`,`table_id`),
		KEY `table_id` (`table_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	mysqli_query($db, "CREATE TABLE IF NOT EXISTS ".tp_t('settings')." (
		`skey` VARCHAR(40) NOT NULL,
		`sval` VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY (`skey`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
	// v0.2171 development state: tables had a free text "area" column, now they reference tp_areas
	$has_area_id = mysqli_query($db, "SHOW COLUMNS FROM ".tp_t('tables')." LIKE 'area_id'");
	if ($has_area_id && mysqli_num_rows($has_area_id) === 0) {
		mysqli_query($db, "ALTER TABLE ".tp_t('tables')." ADD `area_id` INT NOT NULL DEFAULT 0 AFTER `outlet_id`");
	}
	$has_area = mysqli_query($db, "SHOW COLUMNS FROM ".tp_t('tables')." LIKE 'area'");
	if ($has_area && mysqli_num_rows($has_area) > 0) {
		mysqli_query($db, "ALTER TABLE ".tp_t('tables')." DROP COLUMN `area`");
	}
	$done = true;
}

function tp_get_setting($key, $default = '') {
	$rows = tp_rows("SELECT `sval` FROM ".tp_t('settings')." WHERE `skey` = ?", 's', array($key));
	return $rows ? $rows[0]['sval'] : $default;
}

function tp_set_setting($key, $value) {
	$st = tp_exec("INSERT INTO ".tp_t('settings')." (`skey`,`sval`) VALUES (?,?) ON DUPLICATE KEY UPDATE `sval` = VALUES(`sval`)", 'ss', array($key, (string)$value));
	if ($st) { mysqli_stmt_close($st); return true; }
	return false;
}

const TP_TABLE_COLS = '`table_id`,`area_id`,`table_name`,`seats`,`shape`,`x`,`y`,`w`,`h`,`rot`,`active`';

// areas (floors, terrace, ...) of an outlet in display order; an outlet always has at least one
function tp_list_areas($outlet_id) {
	$outlet_id = (int)$outlet_id;
	$rows = tp_rows("SELECT `area_id`,`area_name`,`sort_order` FROM ".tp_t('areas')." WHERE `outlet_id` = ? ORDER BY `sort_order`,`area_id`", 'i', array($outlet_id));
	if (!$rows) {
		$st = tp_exec("INSERT INTO ".tp_t('areas')." (`outlet_id`,`area_name`,`sort_order`) VALUES (?,?,1)", 'is', array($outlet_id, 'Hauptbereich'));
		if ($st) { mysqli_stmt_close($st); }
		$rows = tp_rows("SELECT `area_id`,`area_name`,`sort_order` FROM ".tp_t('areas')." WHERE `outlet_id` = ? ORDER BY `sort_order`,`area_id`", 'i', array($outlet_id));
	}
	// tables from before areas existed belong to the first area
	if ($rows) {
		$st = tp_exec("UPDATE ".tp_t('tables')." SET `area_id` = ? WHERE `outlet_id` = ? AND `area_id` = 0", 'ii', array((int)$rows[0]['area_id'], $outlet_id));
		if ($st) { mysqli_stmt_close($st); }
	}
	return $rows;
}

function tp_owns_area($outlet_id, $area_id) {
	return (bool)tp_rows("SELECT `area_id` FROM ".tp_t('areas')." WHERE `area_id` = ? AND `outlet_id` = ?", 'ii', array((int)$area_id, (int)$outlet_id));
}

// create (no area_id) or rename an area; returns the saved row or false
function tp_save_area($outlet_id, $d) {
	$name = mb_substr(trim(isset($d['area_name']) ? (string)$d['area_name'] : ''), 0, 40);
	if ($name === '') {
		return false;
	}
	if (!empty($d['area_id'])) {
		$id = (int)$d['area_id'];
		if (!tp_owns_area($outlet_id, $id)) {
			return false;
		}
		$st = tp_exec("UPDATE ".tp_t('areas')." SET `area_name` = ? WHERE `area_id` = ? AND `outlet_id` = ?", 'sii', array($name, $id, (int)$outlet_id));
		if (!$st) { return false; }
		mysqli_stmt_close($st);
	} else {
		$max = tp_rows("SELECT MAX(`sort_order`) AS m FROM ".tp_t('areas')." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
		$order = ($max && $max[0]['m'] !== null) ? (int)$max[0]['m'] + 1 : 1;
		$st = tp_exec("INSERT INTO ".tp_t('areas')." (`outlet_id`,`area_name`,`sort_order`) VALUES (?,?,?)", 'isi', array((int)$outlet_id, $name, $order));
		if (!$st) { return false; }
		$id = mysqli_stmt_insert_id($st);
		mysqli_stmt_close($st);
	}
	$rows = tp_rows("SELECT `area_id`,`area_name`,`sort_order` FROM ".tp_t('areas')." WHERE `area_id` = ?", 'i', array($id));
	return $rows ? $rows[0] : false;
}

// returns true, or 'last' (an outlet keeps at least one area), 'has_tables', false (unknown area)
function tp_delete_area($outlet_id, $area_id) {
	$area_id = (int)$area_id;
	if (!tp_owns_area($outlet_id, $area_id)) {
		return false;
	}
	if (count(tp_list_areas($outlet_id)) < 2) {
		return 'last';
	}
	if (tp_rows("SELECT `table_id` FROM ".tp_t('tables')." WHERE `area_id` = ? LIMIT 1", 'i', array($area_id))) {
		return 'has_tables';
	}
	$st = tp_exec("DELETE FROM ".tp_t('areas')." WHERE `area_id` = ? AND `outlet_id` = ?", 'ii', array($area_id, (int)$outlet_id));
	if ($st) { mysqli_stmt_close($st); }
	return true;
}

// move an area one position to the left (-1) or right (+1); returns the renumbered list
function tp_move_area($outlet_id, $area_id, $dir) {
	$areas = tp_list_areas($outlet_id);
	$ids = array_map(function ($a) { return (int)$a['area_id']; }, $areas);
	$i = array_search((int)$area_id, $ids, true);
	$j = $i === false ? false : $i + ($dir < 0 ? -1 : 1);
	if ($i !== false && $j >= 0 && $j < count($ids)) {
		$t = $ids[$i]; $ids[$i] = $ids[$j]; $ids[$j] = $t;
		foreach ($ids as $pos => $id) {
			$st = tp_exec("UPDATE ".tp_t('areas')." SET `sort_order` = ? WHERE `area_id` = ? AND `outlet_id` = ?", 'iii', array($pos + 1, $id, (int)$outlet_id));
			if ($st) { mysqli_stmt_close($st); }
		}
	}
	return tp_list_areas($outlet_id);
}

function tp_list_tables($outlet_id) {
	return tp_rows("SELECT ".TP_TABLE_COLS." FROM ".tp_t('tables')." WHERE `outlet_id` = ? ORDER BY `area_id`,`table_name`", 'i', array((int)$outlet_id));
}

function tp_list_links($outlet_id) {
	return tp_rows("SELECT `table_a`,`table_b` FROM ".tp_t('table_links')." WHERE `outlet_id` = ?", 'i', array((int)$outlet_id));
}

// keep the geometry of a table inside the canvas and on the grid
function tp_clean_geometry($d) {
	$snap = function ($v) { return (int)(round($v / 10) * 10); };
	$w = max(40, min(400, $snap(isset($d['w']) ? (int)$d['w'] : 90)));
	$h = max(40, min(400, $snap(isset($d['h']) ? (int)$d['h'] : 90)));
	$x = max(0, min(TP_CANVAS_W - $w, $snap(isset($d['x']) ? (int)$d['x'] : 20)));
	$y = max(0, min(TP_CANVAS_H - $h, $snap(isset($d['y']) ? (int)$d['y'] : 20)));
	$rot = isset($d['rot']) ? ((int)$d['rot'] % 360 + 360) % 360 : 0;
	return array($x, $y, $w, $h, $rot);
}

function tp_owns_table($outlet_id, $table_id) {
	$r = tp_rows("SELECT `table_id` FROM ".tp_t('tables')." WHERE `table_id` = ? AND `outlet_id` = ?", 'ii', array((int)$table_id, (int)$outlet_id));
	return (bool)$r;
}

// create (no table_id) or update a table; returns the saved row or false
function tp_save_table($outlet_id, $d) {
	$name  = trim(isset($d['table_name']) ? (string)$d['table_name'] : '');
	$name  = mb_substr($name, 0, 40);
	if ($name === '') {
		return false;
	}
	$seats = max(1, min(99, isset($d['seats']) ? (int)$d['seats'] : 2));
	$area_id = isset($d['area_id']) ? (int)$d['area_id'] : 0;
	if (!tp_owns_area($outlet_id, $area_id)) {
		$areas = tp_list_areas($outlet_id);
		$area_id = (int)$areas[0]['area_id'];
	}
	$shape = (isset($d['shape']) && $d['shape'] === 'round') ? 'round' : 'rect';
	list($x, $y, $w, $h, $rot) = tp_clean_geometry($d);
	$active = (isset($d['active']) && (int)$d['active'] === 0) ? 0 : 1;

	if (!empty($d['table_id'])) {
		$id = (int)$d['table_id'];
		if (!tp_owns_table($outlet_id, $id)) {
			return false;
		}
		$old = tp_rows("SELECT `area_id` FROM ".tp_t('tables')." WHERE `table_id` = ?", 'i', array($id));
		if ($old && (int)$old[0]['area_id'] !== $area_id) {
			// links only make sense inside one area
			$st = tp_exec("DELETE FROM ".tp_t('table_links')." WHERE `table_a` = ? OR `table_b` = ?", 'ii', array($id, $id));
			if ($st) { mysqli_stmt_close($st); }
		}
		$st = tp_exec("UPDATE ".tp_t('tables')." SET `area_id`=?,`table_name`=?,`seats`=?,`shape`=?,`x`=?,`y`=?,`w`=?,`h`=?,`rot`=?,`active`=? WHERE `table_id`=? AND `outlet_id`=?",
			'isisiiiiiiii', array($area_id, $name, $seats, $shape, $x, $y, $w, $h, $rot, $active, $id, (int)$outlet_id));
		if (!$st) { return false; }
		mysqli_stmt_close($st);
	} else {
		$st = tp_exec("INSERT INTO ".tp_t('tables')." (`outlet_id`,`area_id`,`table_name`,`seats`,`shape`,`x`,`y`,`w`,`h`,`rot`,`active`) VALUES (?,?,?,?,?,?,?,?,?,?,?)",
			'iisisiiiiii', array((int)$outlet_id, $area_id, $name, $seats, $shape, $x, $y, $w, $h, $rot, $active));
		if (!$st) { return false; }
		$id = mysqli_stmt_insert_id($st);
		mysqli_stmt_close($st);
	}
	$rows = tp_rows("SELECT ".TP_TABLE_COLS." FROM ".tp_t('tables')." WHERE `table_id` = ?", 'i', array($id));
	return $rows ? $rows[0] : false;
}

function tp_delete_table($outlet_id, $table_id) {
	$table_id = (int)$table_id;
	if (!tp_owns_table($outlet_id, $table_id)) {
		return false;
	}
	foreach (array(
		array("DELETE FROM ".tp_t('table_links')." WHERE `table_a` = ? OR `table_b` = ?", 'ii', array($table_id, $table_id)),
		array("DELETE FROM ".tp_t('reservation_tables')." WHERE `table_id` = ?", 'i', array($table_id)),
		array("DELETE FROM ".tp_t('tables')." WHERE `table_id` = ? AND `outlet_id` = ?", 'ii', array($table_id, (int)$outlet_id)),
	) as $q) {
		$st = tp_exec($q[0], $q[1], $q[2]);
		if ($st) { mysqli_stmt_close($st); }
	}
	return true;
}

// add the link if it does not exist, remove it if it does; returns 'linked' | 'unlinked' | 'other_area' | false
function tp_toggle_link($outlet_id, $a, $b) {
	$a = (int)$a; $b = (int)$b;
	if ($a === $b || !tp_owns_table($outlet_id, $a) || !tp_owns_table($outlet_id, $b)) {
		return false;
	}
	$areas = tp_rows("SELECT DISTINCT `area_id` FROM ".tp_t('tables')." WHERE `table_id` IN (?,?)", 'ii', array($a, $b));
	if (count($areas) !== 1) {
		return 'other_area';
	}
	if ($a > $b) { $t = $a; $a = $b; $b = $t; }
	$exists = tp_rows("SELECT `link_id` FROM ".tp_t('table_links')." WHERE `table_a` = ? AND `table_b` = ?", 'ii', array($a, $b));
	if ($exists) {
		$st = tp_exec("DELETE FROM ".tp_t('table_links')." WHERE `table_a` = ? AND `table_b` = ?", 'ii', array($a, $b));
		if ($st) { mysqli_stmt_close($st); }
		return 'unlinked';
	}
	$st = tp_exec("INSERT INTO ".tp_t('table_links')." (`outlet_id`,`table_a`,`table_b`) VALUES (?,?,?)", 'iii', array((int)$outlet_id, $a, $b));
	if ($st) { mysqli_stmt_close($st); return 'linked'; }
	return false;
}

// names of the tables a reservation sits at according to the plan ("T1 + T2"), '' when none;
// used by the reservation lists, never throws
function tp_assigned_table_names($reservation_id) {
	try {
		static $cache = array();
		$id = (int)$reservation_id;
		if (isset($cache[$id])) { return $cache[$id]; }
		$rows = tp_rows("SELECT t.`table_name` FROM ".tp_t('reservation_tables')." rt JOIN ".tp_t('tables')." t ON t.`table_id` = rt.`table_id`
			WHERE rt.`reservation_id` = ? ORDER BY t.`table_name`", 'i', array($id));
		return $cache[$id] = implode(' + ', array_map(function ($r) { return $r['table_name']; }, $rows));
	} catch (Throwable $e) {
		return '';
	}
}

// the cell content for the "table" column of a reservation list: plan tables (link to the plan of
// the day) or, without an assignment, the free text field that can still be edited inline
function tp_table_cell($reservation_id, $free_text, $date) {
	$names = tp_assigned_table_names($reservation_id);
	if ($names === '') {
		return "<div id='reservation_table-".(int)$reservation_id."' class='inlineedit'>".$free_text."</div>";
	}
	return "<a class='tp-tablelabel' href='main_page.php?p=7&selectedDate=".htmlspecialchars($date)."' title='Tischplan'>".htmlspecialchars($names)."</a>";
}

// ids of the tables a reservation is assigned to (for the edit form), never throws
function tp_assigned_table_ids($reservation_id) {
	try {
		$rows = tp_rows("SELECT `table_id` FROM ".tp_t('reservation_tables')." WHERE `reservation_id` = ?", 'i', array((int)$reservation_id));
		return array_map(function ($r) { return (int)$r['table_id']; }, $rows);
	} catch (Throwable $e) {
		return array();
	}
}
