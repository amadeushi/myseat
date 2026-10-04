/* Print the slip of an order (web/bon.php) without leaving the monitor: it is loaded in a hidden frame that prints itself.
   MonitorPrint.slip(id, full): full = delivery slip with guest data, otherwise the kitchen slip. In Chrome started with
   --kiosk-printing this prints straight to the default printer without a dialog. */
window.MonitorPrint = (function () {
	'use strict';
	var frame = null;
	function browserPrint(id, full) {
		if (frame) { frame.remove(); }
		frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true'); frame.tabIndex = -1;
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
		frame.onload = function () { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) {} };
		frame.src = 'bon.php?id=' + encodeURIComponent(id) + (full ? '&full=1' : '');
		document.body.appendChild(frame);
	}
	// with a token (the kitchen monitor) the slip goes to the receipt printer of the kitchen Raspberry Pi when its print agent is running,
	// otherwise, and for every other screen, it is printed through the browser
	function slip(id, full, token) {
		if (!token) { browserPrint(id, full); return; }
		var fd = new FormData(); fd.append('op', 'print_job'); fd.append('token', token); fd.append('id', id); if (full) { fd.append('full', '1'); }
		fetch('ajax/shop_orders.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
			.then(function (r) { if (!r || !r.queued) { browserPrint(id, full); } }).catch(function () { browserPrint(id, full); });
	}
	return { slip: slip };
})();
