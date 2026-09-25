<?php
// Settings > Angebotszeiten: time ranges that are highlighted in the booking widget
require_once __DIR__.'/../classes/offers.class.php';
$of_outlet = (int)$_SESSION['outletID'];
$of_list = offers_all($of_outlet);
$of_labels = offers_weekday_labels();
$of_emoji = array('🍝', '🥐', '☕', '🥂', '🍹', '🍷', '🍕', '🎉', '🎄', '🍰');
$of_e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// one form, used for "new" ($o = null) and for editing an offer
$of_form = function ($o) use ($of_labels, $of_emoji, $of_e, $of_outlet, $token) {
	$k = $o ? (int)$o['offer_id'] : 0;
	$days = $o ? explode(',', $o['weekdays']) : array('1', '2', '3', '4', '5');
	$all = $o ? (int)$o['all_day'] : 0;
	$range = $o && $o['date_from'] !== null;
	ob_start(); ?>
	<form class="offer-form" data-id="<?php echo $k; ?>" enctype="multipart/form-data" hidden>
		<input type="hidden" name="id" value="<?php echo $k; ?>"/>
		<input type="hidden" name="outlet_id" value="<?php echo $of_outlet; ?>"/>
		<input type="hidden" name="token" value="<?php echo $of_e($token); ?>"/>

		<div class="offer-row">
			<div class="offer-days" role="group" aria-label="Wochentage">
				<?php foreach ($of_labels as $n => $lab): ?>
				<label class="offer-day"><input type="checkbox" name="weekdays[]" value="<?php echo $n; ?>"<?php echo in_array((string)$n, $days, true) ? ' checked' : ''; ?>/><span><?php echo $lab; ?></span></label>
				<?php endforeach; ?>
			</div>
			<div class="offer-times">
				<label>von <input type="time" name="time_from" step="900" value="<?php echo $o ? $of_e(substr($o['time_from'], 0, 5)) : '12:00'; ?>"<?php echo $all ? ' disabled' : ''; ?>/></label>
				<label>bis <input type="time" name="time_to" step="900" value="<?php echo $o ? $of_e(substr($o['time_to'], 0, 5)) : '14:45'; ?>"<?php echo $all ? ' disabled' : ''; ?>/></label>
				<label class="offer-check"><input type="checkbox" name="all_day" value="1"<?php echo $all ? ' checked' : ''; ?>/> ganztägig</label>
			</div>
		</div>

		<div class="offer-row offer-range">
			<label class="offer-check"><input type="checkbox" name="use_range" value="1"<?php echo $range ? ' checked' : ''; ?>/> Nur in diesem Zeitraum</label>
			<input type="date" name="date_from" value="<?php echo $range ? $of_e($o['date_from']) : ''; ?>"<?php echo $range ? '' : ' disabled'; ?>/>
			<span aria-hidden="true">bis</span>
			<input type="date" name="date_to" value="<?php echo $range ? $of_e($o['date_to']) : ''; ?>"<?php echo $range ? '' : ' disabled'; ?>/>
		</div>

		<label class="offer-label" for="offer-title-<?php echo $k; ?>">Besonderer Text für diese Zeiten</label>
		<input type="text" id="offer-title-<?php echo $k; ?>" name="title" maxlength="120" placeholder="z. B. 🍝 Mittagstisch" value="<?php echo $o ? $of_e($o['title']) : ''; ?>"/>
		<div class="offer-emoji" aria-label="Emoji einfügen">
			<?php foreach ($of_emoji as $em): ?><button type="button" class="offer-emoji-btn" data-emoji="<?php echo $em; ?>"><?php echo $em; ?></button><?php endforeach; ?>
		</div>

		<label class="offer-label" for="offer-desc-<?php echo $k; ?>">Angebotstext</label>
		<small class="offer-help">Erscheint in einem Fenster, wenn der Gast im Widget auf "Mehr erfahren" klickt. Links müssen mit https:// oder http:// beginnen.</small>
		<textarea id="offer-desc-<?php echo $k; ?>" name="description" rows="4" maxlength="3000"><?php echo $o ? $of_e($o['description']) : ''; ?></textarea>

		<div class="offer-image">
			<?php if ($o && $o['image']): ?>
			<img src="../uploads/offers/<?php echo $of_e($o['image']); ?>" alt="" class="offer-thumb"/>
			<label class="offer-check"><input type="checkbox" name="remove_image" value="1"/> Bild entfernen</label>
			<?php endif; ?>
			<label class="offer-file button_dark">Bild <?php echo ($o && $o['image']) ? 'ersetzen' : 'hinzufügen'; ?><input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" hidden/></label>
			<span class="offer-filename" aria-live="polite"></span>
		</div>

		<label class="offer-check"><input type="checkbox" name="active" value="1"<?php echo (!$o || (int)$o['active']) ? ' checked' : ''; ?>/> Angebot ist aktiv</label>

		<p class="offer-actions">
			<button type="submit" class="button_dark">Speichern</button>
			<button type="button" class="offer-cancel">Abbrechen</button>
			<?php if ($o): ?><button type="button" class="offer-delete">Löschen</button><?php endif; ?>
			<span class="detail-status offer-status" role="status" aria-live="polite"></span>
		</p>
	</form>
	<?php return ob_get_clean();
};
?>
<div class="offers-page" data-endpoint="ajax/save_offer.php">
	<p class="offers-intro">Informiere deine Gäste über zeitlich begrenzte Angebote, zum Beispiel Mittagstisch, Frühstück oder Happy Hour. Das Angebot erscheint zu den festgelegten Zeiten im Reservierungswidget: die passenden Uhrzeiten werden hervorgehoben, und ein Klick auf "Mehr erfahren" zeigt Text und Bild.</p>

	<?php if (!$of_list): ?>
	<p class="offers-empty">Noch keine Angebotszeiten angelegt.</p>
	<?php endif; ?>

	<?php foreach ($of_list as $o):
		$dn = array(); foreach ($of_labels as $n => $lab) { if (in_array((string)$n, explode(',', $o['weekdays']), true)) { $dn[] = $lab; } } ?>
	<div class="offer-item<?php echo (int)$o['active'] ? '' : ' is-inactive'; ?>">
		<button type="button" class="offer-summary" aria-expanded="false">
			<span><?php echo $of_e(implode(' ', $dn)); ?></span><span class="offer-sep" aria-hidden="true">|</span>
			<span><?php echo $of_e(offers_time_text($o)); ?></span><span class="offer-sep" aria-hidden="true">|</span>
			<span class="offer-summary-title"><?php echo $of_e($o['title']); ?></span>
			<?php if ($o['date_from'] !== null): ?><span class="offer-badge"><?php echo date('d.m.Y', strtotime($o['date_from'])).' – '.date('d.m.Y', strtotime($o['date_to'])); ?></span><?php endif; ?>
			<?php if (!(int)$o['active']): ?><span class="offer-badge">inaktiv</span><?php endif; ?>
		</button>
		<?php echo $of_form($o); ?>
	</div>
	<?php endforeach; ?>

	<div class="offer-item offer-new">
		<button type="button" class="offer-summary offer-add" aria-expanded="false">+ Angebotszeit hinzufügen</button>
		<?php echo $of_form(null); ?>
	</div>
</div>
<script>
window.addEventListener('load', function () {
	var page = document.querySelector('.offers-page'); if (!page) { return; }
	function toggle(item, open) {
		var f = item.querySelector('.offer-form'), b = item.querySelector('.offer-summary');
		f.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
	}
	page.querySelectorAll('.offer-summary').forEach(function (b) { b.addEventListener('click', function () { var it = b.closest('.offer-item'); toggle(it, it.querySelector('.offer-form').hidden); }); });
	page.querySelectorAll('.offer-cancel').forEach(function (b) { b.addEventListener('click', function () { toggle(b.closest('.offer-item'), false); }); });
	page.querySelectorAll('.offer-form').forEach(function (f) {
		var all = f.elements.all_day, range = f.elements.use_range;
		function sync() { f.elements.time_from.disabled = f.elements.time_to.disabled = all.checked; f.elements.date_from.disabled = f.elements.date_to.disabled = !range.checked; }
		all.addEventListener('change', sync); range.addEventListener('change', sync);
		f.querySelectorAll('.offer-emoji-btn').forEach(function (b) { b.addEventListener('click', function () {
			var t = f.elements.title, s = t.selectionStart == null ? t.value.length : t.selectionStart, e = t.selectionEnd == null ? s : t.selectionEnd;
			t.value = t.value.slice(0, s) + b.dataset.emoji + ' ' + t.value.slice(e); t.focus(); t.selectionStart = t.selectionEnd = s + b.dataset.emoji.length + 1;
		}); });
		f.elements.image.addEventListener('change', function () { f.querySelector('.offer-filename').textContent = this.files[0] ? this.files[0].name : ''; });
		var status = f.querySelector('.offer-status');
		function send(extra) {
			var fd = new FormData(f);
			if (extra) { fd.append(extra, '1'); }
			status.textContent = 'Wird gespeichert ...'; status.classList.remove('is-error');
			fetch(page.dataset.endpoint, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
				if (!r.ok) { status.textContent = r.error || 'Speichern fehlgeschlagen.'; status.classList.add('is-error'); return; }
				try { sessionStorage.setItem('detailMsg', r.message); } catch (e) {}
				location.reload();
			}).catch(function () { status.textContent = 'Speichern fehlgeschlagen. Bitte versuche es noch einmal.'; status.classList.add('is-error'); });
		}
		f.addEventListener('submit', function (ev) { ev.preventDefault(); send(); });
		var del = f.querySelector('.offer-delete');
		if (del) { del.addEventListener('click', function () { if (del.dataset.armed) { send('delete'); } else { del.dataset.armed = '1'; del.textContent = 'Wirklich löschen?'; setTimeout(function () { del.dataset.armed = ''; del.textContent = 'Löschen'; }, 4000); } }); }
	});
});
</script>
