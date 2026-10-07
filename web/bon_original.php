<?php
/*
 * The original receipt of an order from Lieferando (the PDF the tablet printed) or Uber Eats (the picture the Pi made of the print job), to print it again in the browser of the
 * dispatch (web/disposition.php). A PDF is shown by the viewer of the browser (it has its own print button); the picture comes on a page that prints itself with ?print=1.
 * Needs a backend login (Reservation-Edit), like web/bon.php.
 */
session_start();
include('../config/config.general.php');
include('classes/mysql_compat.php');
include('classes/connect.db.php');
include('classes/database.class.php');
include('classes/db_queries.db.php');
include('classes/business.class.php');
require_once('classes/shop.class.php');
date_default_timezone_set('Europe/Berlin');

require_once('classes/session_restore.php'); myseat_restore_session();
if (empty($_SESSION['valid_user']) || !current_user_can('Reservation-Edit')) { http_response_code(403); echo 'Keine Berechtigung.'; exit; }
shop_ensure_schema();
$id = (int)(isset($_GET['id']) ? $_GET['id'] : 0);
$o = $id ? shop_order($id) : null;
if (!$o || !in_array($o['source'], array('lieferando', 'uber_eats'), true)) { http_response_code(404); echo 'Bestellung nicht gefunden.'; exit; }
$doc = shop_doc_get($id);
if (!$doc) { http_response_code(404); echo 'Zu dieser Bestellung ist kein Originalbon gespeichert.'; exit; }
header('Cache-Control: private, no-store');
$name = ($o['source'] === 'lieferando' ? 'Lieferando' : 'Uber-Eats').'-Bon-'.(int)$o['day_no'];
if ($doc['kind'] === 'pdf') {
	header('Content-Type: application/pdf');
	header('Content-Disposition: inline; filename="'.$name.'.pdf"');
	header('Content-Length: '.strlen($doc['data']));
	echo $doc['data'];
	exit;
}
$src = 'data:image/png;base64,'.base64_encode($doc['data']);
$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="robots" content="noindex,nofollow"/>
	<title><?php echo $h($name); ?></title>
	<style>
		body { margin: 0; padding: 16px; background: #f4f1ea; font-family: Arial, Helvetica, sans-serif; color: #1c1a18; }
		.bar { max-width: 420px; margin: 0 auto 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; }
		button { min-height: 44px; padding: 0 20px; border: 0; border-radius: 10px; background: #c9a259; color: #1a1408; font: inherit; font-weight: 700; cursor: pointer; }
		img { display: block; width: 100%; max-width: 420px; margin: 0 auto; background: #fff; box-shadow: 0 2px 12px rgba(0, 0, 0, 0.2); }
		@page { margin: 8mm; }
		@media print { body { padding: 0; background: #fff; } .bar { display: none; } img { width: 80mm; max-width: none; margin: 0; box-shadow: none; } }
	</style>
</head>
<body>
	<div class="bar"><span><?php echo $h($name); ?></span><button type="button" onclick="window.print()">Drucken</button></div>
	<img id="bon" src="<?php echo $src; ?>" alt="Originalbon"/>
<?php if (!empty($_GET['print'])): ?>
	<script>
		var img = document.getElementById('bon');
		function go() { setTimeout(function () { window.print(); }, 150); }
		if (img.complete) { go(); } else { img.addEventListener('load', go); }
	</script>
<?php endif; ?>
</body>
</html>
