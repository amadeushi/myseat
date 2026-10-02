<?php
// Store only a bounded technical summary outside the public website directory.
function sipgate_diagnostic_path() {
	return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'myseat-sipgate-'.hash('sha256', __DIR__).'.json';
}
function sipgate_diagnostic_write($state) {
	$data = array('time' => gmdate('c'), 'state' => $state);
	$path = sipgate_diagnostic_path();
	$handle = @fopen($path, 'c');
	if (!$handle) { return false; }
	@chmod($path, 0600);
	$ok = false;
	if (flock($handle, LOCK_EX)) {
		$json = json_encode($data);
		$ok = ftruncate($handle, 0) && fwrite($handle, $json) === strlen($json);
		fflush($handle);
		flock($handle, LOCK_UN);
	}
	fclose($handle);
	return $ok;
}
function sipgate_diagnostic_read() {
	$handle = @fopen(sipgate_diagnostic_path(), 'r');
	if (!$handle) { return null; }
	$data = null;
	if (flock($handle, LOCK_SH)) {
		$data = json_decode(stream_get_contents($handle, 4096), true);
		flock($handle, LOCK_UN);
	}
	fclose($handle);
	return is_array($data) ? $data : null;
}
