<?php
/*
 * Mollie tells us that a payment changed (POST id=tr_...). The body is not trusted: the status is asked from Mollie itself
 * with our key (shop_mollie_sync). Always answers 200 so that Mollie does not retry for a payment we do not know.
 */
require __DIR__.'/bootstrap.inc.php';
header('Content-Type: text/plain');
$id = isset($_POST['id']) ? (string)$_POST['id'] : '';
if (preg_match('/^tr_[A-Za-z0-9]{6,40}$/', $id)) {
	$order = fb_row("SELECT * FROM ".fb_t('tp_shop_orders')." WHERE mollie_id = ? LIMIT 1", 's', array($id));
	if ($order) { shop_mollie_sync($order); }
}
echo 'ok';
