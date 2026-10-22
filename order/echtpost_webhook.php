<?php
/*
 * EchtPost tells us that a card was sent (event card.sent, set up under Account > Webhooks with this address). The body is not trusted and its exact shape is not relied on:
 * any card id (UUID) in it is looked up in our orders and the real state is asked from EchtPost with our key (gv_post_sync). Always answers 200.
 */
require __DIR__.'/bootstrap.inc.php';
header('Content-Type: text/plain');
$raw = (string)file_get_contents('php://input', false, null, 0, 20000);
if (preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $raw, $m)) {
	foreach (array_slice(array_unique($m[0]), 0, 5) as $id) {
		$o = fb_row("SELECT * FROM ".fb_t('tp_gv_orders')." WHERE ep_card = ? LIMIT 1", 's', array(strtolower($id)));
		if ($o) { gv_post_sync($o, true); }
	}
}
echo 'ok';
