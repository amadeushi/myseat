/* Sideways rows (categories, the dough and ingredient tabs of the pizza configurator, favorites): scroll them on a desktop by pressing the
   mouse button and dragging, like a finger does on a phone. Touch and keyboard keep their own scrolling. A click that ends a drag does not
   press the button under the pointer. Rows are found by their selector when the mouse goes down, so rows built later work as well. */
(function () {
	'use strict';
	var SEL = '[data-dragscroll], .shop-cats, .pz-teig, .pz-tabs, .acc-favs-list', drag = null, swallow = false;
	function row(t) { return t && t.closest ? t.closest(SEL) : null; }
	function scrollable(el) { return el.scrollWidth > el.clientWidth + 1; }
	// the hand shows only on a row that has something to scroll to
	document.addEventListener('pointerover', function (ev) { if (ev.pointerType !== 'mouse') { return; } var el = row(ev.target); if (el) { el.style.cursor = scrollable(el) ? 'grab' : ''; } });
	document.addEventListener('pointerdown', function (ev) {
		if (ev.pointerType !== 'mouse' || ev.button !== 0) { return; }
		var el = row(ev.target); if (!el || !scrollable(el)) { return; }
		drag = { el: el, x: ev.clientX, left: el.scrollLeft, moved: false, id: ev.pointerId };
	});
	document.addEventListener('pointermove', function (ev) {
		if (!drag || ev.pointerId !== drag.id) { return; }
		var dx = ev.clientX - drag.x;
		if (!drag.moved) {
			if (Math.abs(dx) < 5) { return; } // a click with a tiny shake stays a click
			drag.moved = true; drag.el.classList.add('is-dragging');
			try { drag.el.setPointerCapture(ev.pointerId); } catch (e) {}
		}
		drag.el.scrollLeft = drag.left - dx; ev.preventDefault();
	});
	function end(ev) {
		if (!drag || (ev && ev.pointerId !== drag.id)) { return; }
		if (drag.moved) { swallow = true; setTimeout(function () { swallow = false; }, 0); }
		drag.el.classList.remove('is-dragging');
		try { drag.el.releasePointerCapture(drag.id); } catch (e) {}
		drag = null;
	}
	document.addEventListener('pointerup', end); document.addEventListener('pointercancel', end);
	document.addEventListener('click', function (ev) { if (swallow) { ev.stopPropagation(); ev.preventDefault(); swallow = false; } }, true);
	// no ghost picture of a link or an image while dragging
	document.addEventListener('dragstart', function (ev) { if (row(ev.target)) { ev.preventDefault(); } });
})();
