<?php
// Day note banner shown above the reservation list. Used by the page (includes/messagebox.inc.php)
// and by ajax/save_maitre.php so a saved note can be swapped in without reloading the page.
function maitre_note_html($comment) {
	if (trim((string)$comment) === '') { return ''; }
	return "<div class='alert_tip'><p class='center margin-bottom-10'>".uiIcon('info')." ".$comment."</p></div>";
}
