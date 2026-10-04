<?php
/*
 * The addresses this installation can be reached at, from config/hosts.inc.php:
 *   app   = guests (order page, account, order status, driver page), e.g. https://app.amds.at
 *   admin = backend, monitors and table reservations (cancel and feedback links), e.g. https://reservierung.amds.at
 * An empty value means "the address the current request came in on", which is the right thing while there is only one domain.
 */
function myseat_hosts() {
	static $h = null;
	if ($h === null) {
		$f = __DIR__.'/../../config/hosts.inc.php';
		$c = is_file($f) ? include $f : array();
		$clean = function ($v) { $v = rtrim(trim((string)$v), '/'); return preg_match('#^https://[A-Za-z0-9.\-]+(:\d+)?$#', $v) ? $v : ''; };
		$h = array('app' => $clean(is_array($c) && isset($c['app_url']) ? $c['app_url'] : ''), 'admin' => $clean(is_array($c) && isset($c['admin_url']) ? $c['admin_url'] : ''));
	}
	return $h;
}
