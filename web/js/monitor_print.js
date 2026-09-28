/* Print the slip of an order (web/bon.php) without leaving the monitor: it is loaded in a hidden frame that prints itself.
   MonitorPrint.slip(id, full): full = delivery slip with guest data, otherwise the kitchen slip. In Chrome started with
   --kiosk-printing this prints straight to the default printer without a dialog. */
window.MonitorPrint = (function () {
	'use strict';
	var frame = null;
	function slip(id, full) {
		if (frame) { frame.remove(); }
		frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true'); frame.tabIndex = -1;
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
		frame.onload = function () { try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) {} };
		frame.src = 'bon.php?id=' + encodeURIComponent(id) + (full ? '&full=1' : '');
		document.body.appendChild(frame);
	}
	return { slip: slip };
})();
