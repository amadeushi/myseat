<!-- Begin reservation table data -->
<?php
// Highlighter for reservations that just came in (today's view only, never the cancelled list): a
// reservation less than an hour old gets a "res-new" row so staff notice it needs attention. The
// live sound/banner watcher below (once per page, on the confirmed pass) plays only once. $q is not
// set at all when this include runs from the dashboard, so it must not be required to exist.
$resHighlightToday = (!isset($q) || $q != 3) && isset($_SESSION['selectedDate']) && $_SESSION['selectedDate'] === $today_date;
?>
<?php if ($resHighlightToday && $_SESSION['wait'] == 0): ?>
<div class="res-sound-mount" id="res-sound-mount"></div>
<div class="alert_warning res-alert" id="res-new-alert" hidden role="status" aria-live="polite">
	<p><span id="res-new-alert-text"></span>
		<button type="button" class="res-alert-x" id="res-new-alert-dismiss" aria-label="Schließen">&times;</button>
	</p>
</div>
<script src="js/monitor_sound.js"></script>
<?php endif; ?>
<div id="res-grid-table">
<?php include('reservations_table.inc.php'); ?>
</div>
<script>
// table sign: the page of the sign prints itself in a hidden frame (Chrome with --kiosk-printing: straight to the receipt printer)
(function () {
	if (window.resBonReady) { return; } window.resBonReady = true;
	document.addEventListener('click', function (ev) {
		var a = ev.target.closest ? ev.target.closest('a.resbon, a.resbon-day') : null; if (!a) { return; }
		ev.preventDefault();
		var old = document.getElementById('resbon-frame'); if (old) { old.parentNode.removeChild(old); }
		var f = document.createElement('iframe'); f.id = 'resbon-frame'; f.setAttribute('aria-hidden', 'true'); f.tabIndex = -1;
		f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
		f.onload = function () { try { var d = f.contentDocument; if (d && !d.querySelector('.slip')) { alert((d.body.textContent || 'Drucken nicht möglich.').trim()); } } catch (e) {} };
		f.src = a.getAttribute('data-url');
		document.body.appendChild(f);
	});
})();
</script>
<?php if ($resHighlightToday && $_SESSION['wait'] == 0): ?>
<script>
// new-reservation highlighter: fades the "res-new" row exactly an hour after it was created, and
// watches for reservations arriving while this page stays open. A genuinely new confirmed reservation
// is fetched already rendered (ajax/reservations_new_rows.php, same markup as the full table) and
// inserted at the top of the table right away - no click, no page reload. Waitlisted arrivals still
// ring the bell and show the banner (ajax/reservations_since.php counts both) but are not inserted
// here, since they belong to the separate waitlist table further down the page; they appear on the
// next real refresh, same as before this feature existed. "pending" = not yet dismissed.
(function () {
	if (window.__resNewWatch) { return; } window.__resNewWatch = true;
	document.querySelectorAll('tr.res-new[data-created]').forEach(function (tr) {
		var ms = (parseInt(tr.dataset.created, 10) + 3600) * 1000 - Date.now();
		if (ms > 0) { setTimeout(function () { tr.classList.remove('res-new'); }, ms); } else { tr.classList.remove('res-new'); }
	});
	var since = Math.floor(Date.now() / 1000), pending = 0, hideTimer = null;
	var alertBox = document.getElementById('res-new-alert'), alertText = document.getElementById('res-new-alert-text');
	var tbody = document.getElementById('res-tbody');
	var mount = document.getElementById('res-sound-mount');
	var sound = (window.MonitorSound && mount) ? MonitorSound.create({ key: 'reservations', mount: mount, pending: function () { return pending; } }) : null;

	function showBanner(text) {
		alertText.textContent = text;
		alertBox.hidden = false;
		if (hideTimer) { clearTimeout(hideTimer); }
		hideTimer = setTimeout(dismiss, 8000);
	}
	function dismiss() {
		pending = 0; alertBox.hidden = true; if (sound) { sound.ack(); }
		if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
	}
	document.getElementById('res-new-alert-dismiss').addEventListener('click', dismiss);

	// the new row's own icons/buttons need the same one-time setup the page does for everything else
	// on load (web/js/custom.js runs only once, at document ready) - scoped to the fresh <tr>s only,
	// copied verbatim from custom.js, so existing rows keep exactly the bindings they already have
	function activate($rows) {
		if (!window.jQuery || !$rows.length) { return; }
		var $ = window.jQuery;
		$rows.find('.tipsy').tipsy();
		$rows.find('.tipsyold').tipsy({ gravity: 'w' });
		if (!$.fn.fancybox) { return; }
		$rows.find('.delbtn').fancybox({
			'titleShow': false, 'modal': true,
			onStart: function (selectedArray, selectedIndex) {
				window.del_id = selectedArray[selectedIndex].id;
				window.del_rep = selectedArray[selectedIndex].name;
				window.del_row = $('#' + window.del_id).parents('tr:first');
				if (window.del_rep == 0) { $('#button_al').css('display', 'none'); }
			}
		});
		$rows.find('a#detlbuttontrigger').fancybox({ 'hideOnContentClick': true });
		$rows.find('.alwbtn').click(function () {
			var id = $(this).attr('name');
			$.ajax({ url: 'ajax/modify_entry.php', data: 'action=ALW&cellid=' + id, type: 'post', cache: false, dataType: 'html', success: function () { location.reload(); } });
		});
		$rows.find('.status_dbox').change(function () {
			var statusId = $(this).attr('id').substring(5, 30), selected = $(this).val();
			$.ajax({ type: 'POST', url: 'ajax/modify_status.php', data: 'value=' + selected + '&id=' + statusId, success: function () { location.reload(); } });
		});
	}

	function poll() {
		fetch('ajax/reservations_since.php?since=' + since, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r || !r.ok || r.count <= 0) { return; }
			var newSince = r.now || since;
			fetch('ajax/reservations_new_rows.php?since=' + since, { credentials: 'same-origin' }).then(function (resp) { return resp.text(); }).then(function (html) {
				since = newSince;
				pending += r.count;
				var inserted = 0;
				if (html && tbody) {
					var tmp = document.createElement('tbody');
					tmp.innerHTML = html;
					var rows = Array.prototype.slice.call(tmp.querySelectorAll('tr'));
					inserted = rows.length;
					rows.forEach(function (tr) { tbody.insertBefore(tr, tbody.firstChild.nextSibling || null); });
					if (window.jQuery && rows.length) { activate(window.jQuery(rows)); }
				}
				var text = pending === 1 ? 'Eine neue Reservierung ist eingegangen.' : pending + ' neue Reservierungen sind eingegangen.';
				if (inserted < pending) { text += ' (Warteliste: bitte aktualisieren.)'; }
				showBanner(text);
				if (sound) { sound.notify(); }
			}).catch(function () { since = newSince; });
		}).catch(function () {});
	}
	setInterval(poll, 20000);
})();
</script>
<?php endif; ?>
<!-- End reservation table data -->