<?php
// =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
// Compatibility shim for the legacy ext/mysql API
// (removed in PHP 7.0), implemented on top of mysqli.
// =-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=

if (!defined('MYSQL_ASSOC')) {
	define('MYSQL_ASSOC', MYSQLI_ASSOC);
	define('MYSQL_NUM', MYSQLI_NUM);
	define('MYSQL_BOTH', MYSQLI_BOTH);
}

// preserve legacy warning/return-false behaviour instead of mysqli's
// default exception-throwing error mode (PHP >= 8.1)
mysqli_report(MYSQLI_REPORT_OFF);

if (!function_exists('mysql_connect')) {

	$GLOBALS['__mysql_compat_link'] = null;

	function mysql_connect($host = null, $username = null, $password = null) {
		$port = null;
		if ($host !== null && strpos($host, ':') !== false) {
			list($hostOnly, $maybePort) = explode(':', $host, 2);
			if (ctype_digit($maybePort)) {
				$host = $hostOnly;
				$port = (int) $maybePort;
			}
		}
		$link = @mysqli_connect($host, $username, $password, '', $port);
		if ($link) {
			$GLOBALS['__mysql_compat_link'] = $link;
		}
		return $link;
	}

	function mysql_close($link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_close($link) : false;
	}

	function mysql_select_db($dbname, $link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_select_db($link, $dbname) : false;
	}

	function mysql_query($query, $link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_query($link, $query) : false;
	}

	function mysql_error($link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_error($link) : mysqli_connect_error();
	}

	function mysql_real_escape_string($str, $link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_real_escape_string($link, $str) : addslashes($str);
	}

	function mysql_fetch_assoc($result) {
		return $result ? mysqli_fetch_assoc($result) : false;
	}

	function mysql_fetch_array($result, $resulttype = MYSQL_BOTH) {
		return $result ? mysqli_fetch_array($result, $resulttype) : false;
	}

	function mysql_fetch_row($result) {
		return $result ? mysqli_fetch_row($result) : false;
	}

	function mysql_affected_rows($link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_affected_rows($link) : false;
	}

	function mysql_insert_id($link = null) {
		$link = $link ?: $GLOBALS['__mysql_compat_link'];
		return $link ? mysqli_insert_id($link) : false;
	}

	function mysql_num_rows($result) {
		return $result ? mysqli_num_rows($result) : false;
	}

	function mysql_num_fields($result) {
		return $result ? mysqli_num_fields($result) : false;
	}

	function mysql_field_name($result, $index) {
		if (!$result) return false;
		$finfo = mysqli_fetch_field_direct($result, $index);
		return $finfo ? $finfo->name : false;
	}

	function mysql_result($result, $row, $field = 0) {
		if (!$result) return false;
		if (!mysqli_data_seek($result, $row)) return false;
		$r = mysqli_fetch_array($result, MYSQL_BOTH);
		return $r[$field] ?? false;
	}
}

if (!function_exists('get_magic_quotes_gpc')) {
	// always disabled since PHP 5.4, removed entirely in PHP 8.0
	function get_magic_quotes_gpc() {
		return false;
	}
}
