<?php
session_start();
header('Cache-Control: no-store');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
include(__DIR__.'/../config/config.general.php');
include(__DIR__.'/../web/classes/mysql_compat.php');
include(__DIR__.'/../web/classes/connect.db.php');
include(__DIR__.'/../web/classes/database.class.php');
include(__DIR__.'/../web/classes/db_queries.db.php');
include(__DIR__.'/../web/classes/business.class.php');
if (empty($_SESSION['valid_user']) || !current_user_can('Settings-General')) {
	http_response_code(403);
	echo 'Bitte zuerst in mySeat mit Berechtigung für die allgemeinen Einstellungen anmelden.';
	exit;
}
require_once(__DIR__.'/sipgate_diagnostics.php');
$data = sipgate_diagnostic_read();
$messages = array(
	'request_received' => 'Die Anfrage hat PHP erreicht. Die Verarbeitung wurde noch nicht abgeschlossen.',
	'url_key_invalid' => 'Der Schlüssel in der Webhook-URL fehlt oder stimmt nicht. Übernimm die vollständige URL aus den Lieferservice-Einstellungen.',
	'url_key_valid' => 'Der URL-Schlüssel ist gültig. Die weitere Verarbeitung wurde noch nicht abgeschlossen.',
	'request_body_unreadable' => 'Der Anfrageinhalt konnte nicht gelesen werden.',
	'processed' => 'Der URL-Schlüssel ist gültig und die Anfrage wurde erfolgreich verarbeitet.'
);
$escape = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sipgate-Diagnose</title>
<style>body{font:17px/1.6 system-ui,sans-serif;max-width:720px;margin:60px auto;padding:0 24px;color:#17202a}section{padding:24px;background:#f3f5f7;border-radius:16px}button{font:inherit;padding:10px 18px;cursor:pointer}</style>
<h1>Sipgate-Diagnose</h1>
<p>Rufe deine sipgate-Nummer an und aktualisiere anschließend diese Seite.</p>
<section>
<?php if ($data): ?>
<p><strong>Letzte Anfrage (UTC):</strong> <?php echo $escape($data['time']); ?></p>
<p><?php echo $escape(isset($messages[$data['state']]) ? $messages[$data['state']] : 'Unbekannter Zustand'); ?></p>
<p><small>Diagnosecode: <?php echo $escape($data['state']); ?></small></p>
<?php else: ?>
<p>Noch keine gespeicherte Anfrage. Falls sipgate nach einem neuen Anruf weiterhin 403 meldet, prüfe, ob die aktualisierten Dateien hochgeladen wurden. Auch eine Sperre vor PHP oder ein nicht beschreibbarer temporärer Ordner ist möglich.</p>
<?php endif; ?>
</section>
<p><button onclick="location.reload()">Aktualisieren</button></p>
<p><small>Es werden keine Rufnummern, Passwörter oder URL-Schlüssel gespeichert. Die Diagnose zeigt nur die letzte Anfrage; nicht verifizierte Anfragen können diesen Eintrag ebenfalls aktualisieren.</small></p>
</html>
