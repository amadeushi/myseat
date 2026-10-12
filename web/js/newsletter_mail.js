/* Newsletter, tab "Mailings" (p=14): the list of mailings and the block editor. The mail itself is made by the server from the blocks (ajax/newsletter.php, mail_*):
   what the editor shows is the same look, the final html never comes from the browser. Autosave for drafts, a preview as the guest gets it, a test mail, the start. */
(function () {
	'use strict';
	var page = document.getElementById('nl-page'), root = document.getElementById('nlm-root'); if (!page || !root) { return; }
	var mailRoot = root, autoRoot = document.getElementById('nla-root'), fromAuto = false;
	var TOKEN = page.dataset.token, live = document.getElementById('nlm-live');
	var cur = null, saveTimer = null, saving = false, dirty = false, version = 0, lastField = null, pollTimer = null, recipients = 0, problems = [], fromMail = '';
	var STATUS = { auto: 'Vorlage', draft: 'Entwurf', sending: 'Läuft', done: 'Versendet', cancelled: 'Abgebrochen' };
	var BLOCKS = [['h', 'Überschrift'], ['p', 'Text'], ['img', 'Bild'], ['btn', 'Button'], ['box', 'Box'], ['hr', 'Trenner'], ['logo', 'Logo']];
	var ICON = {
		up: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
		down: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7"/></svg>',
		del: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
		bold: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z"/></svg>',
		italic: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 5h8M6 19h8M14 5l-4 14"/></svg>',
		link: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>',
		list: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/></svg>',
		eye: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
		back: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>'
	};

	function el(tag, a, kids) {
		var e = document.createElement(tag), k;
		for (k in (a || {})) {
			if (k === 'text') { e.textContent = a[k]; }
			else if (k === 'html') { e.innerHTML = a[k]; }
			else if (k === 'class') { e.className = a[k]; }
			else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), a[k]); }
			else if (a[k] === true) { e.setAttribute(k, ''); }
			else if (a[k] !== false && a[k] !== null && a[k] !== undefined) { e.setAttribute(k, a[k]); }
		}
		(kids || []).forEach(function (c) { if (c !== null && c !== undefined) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return e;
	}
	function say(t) { live.textContent = t || ''; }
	function get(op, q) { return fetch('ajax/newsletter.php?op=' + op + (q || ''), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
	function post(op, data, file) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN);
		for (var k in (data || {})) { fd.append(k, data[k]); }
		if (file) { fd.append('image', file); }
		return fetch('ajax/newsletter.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function fail(e) { say(typeof e === 'string' ? e : 'Keine Verbindung.'); }
	// two taps for what cannot be undone: the first arms the button for four seconds
	function armed(btn, armedText, fn) {
		btn.addEventListener('click', function () {
			if (!btn.dataset.armed) { var t = btn.textContent; btn.dataset.armed = '1'; btn.textContent = armedText; setTimeout(function () { btn.dataset.armed = ''; btn.textContent = t; }, 4000); return; }
			btn.dataset.armed = ''; fn();
		});
	}

	/* ---- tabs ---- */
	var TABS = { subs: [document.getElementById('nl-tab-subs'), document.getElementById('nl-panel-subs')], mail: [document.getElementById('nl-tab-mail'), document.getElementById('nl-panel-mail')], auto: [document.getElementById('nl-tab-auto'), document.getElementById('nl-panel-auto')] };
	function tab(which, focus) {
		Object.keys(TABS).forEach(function (k) { var on = k === which; TABS[k][0].setAttribute('aria-selected', String(on)); TABS[k][0].tabIndex = on ? 0 : -1; TABS[k][1].hidden = !on; });
		if (focus) { TABS[which][0].focus(); }
		try { sessionStorage.setItem('nlTab', which); } catch (e) { /* the tab is only a convenience */ }
		stopPoll();
		if (which === 'mail') { root = mailRoot; fromAuto = false; if (!cur || cur.kind) { cur = null; showList(); } }
		if (which === 'auto') { root = autoRoot; fromAuto = true; if (!cur || !cur.kind) { cur = null; showAutos(); } }
	}
	Object.keys(TABS).forEach(function (k, i, all) {
		TABS[k][0].addEventListener('click', function () { tab(k); });
		TABS[k][0].addEventListener('keydown', function (ev) {
			var j = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : ev.key === 'Home' ? 0 : ev.key === 'End' ? all.length - 1 : -1;
			if (j >= 0) { ev.preventDefault(); tab(all[(j + all.length) % all.length], true); }
		});
	});

	/* ---- list of mailings ---- */
	function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
	function chip(status) { return el('span', { class: 'nlm-chip is-' + status, text: STATUS[status] || status }); }
	function showList() {
		stopPoll(); cur = null; root = mailRoot; fromAuto = false; root.textContent = ''; root.appendChild(el('p', { class: 'sh-help', text: 'Hier schreibst du Mails an alle, die Neuigkeiten bestätigt haben. Jedes Mailing wird in Ruhe in kleinen Paketen verschickt.' }));
		var box = el('div', { class: 'nlm-listbox' }); root.appendChild(box);
		get('mail_list').then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.'); return; }
			recipients = r.recipients; fromMail = r.from;
			var head = el('div', { class: 'nlm-listhead' }, [
				el('p', { class: 'nlm-count' }, [el('b', { text: String(r.recipients) }), ' ' + (r.recipients === 1 ? 'Gast hat' : 'Gäste haben') + ' Neuigkeiten bestätigt']),
				el('button', { type: 'button', class: 'sh-btn is-primary', text: 'Neues Mailing', onclick: function () { post('mail_new', { name: 'Neues Mailing' }).then(function (x) { if (x.ok) { open(x.id); } else { fail(x.error); } }).catch(fail); } })
			]);
			box.appendChild(head);
			if (!r.from) { box.appendChild(el('p', { class: 'sh-warn', text: 'Der Mailversand ist noch nicht eingerichtet. Ohne Absenderadresse kann nichts verschickt werden.' })); }
			if (!r.mails.length) { box.appendChild(el('p', { class: 'sh-help', text: 'Noch kein Mailing. Mit "Neues Mailing" legst du das erste an.' })); return; }
			var tb = el('tbody');
			r.mails.forEach(function (m) {
				var s = m.stats, sent = m.status === 'draft' ? 'noch nicht gestartet' : (s.sent + ' von ' + s.total + ' gesendet' + (s.failed ? ', ' + s.failed + ' fehlgeschlagen' : ''));
				var dup = el('button', { type: 'button', class: 'sh-btn', text: 'Duplizieren', onclick: function () { post('mail_new', { name: m.name, copy: m.id }).then(function (x) { if (x.ok) { open(x.id); } else { fail(x.error); } }).catch(fail); } });
				var del = el('button', { type: 'button', class: 'sh-btn nl-erase', text: 'Löschen', disabled: m.status === 'sending' });
				armed(del, 'Wirklich löschen?', function () { post('mail_delete', { id: m.id }).then(function (x) { if (x.ok) { showList(); } else { fail(x.error); } }).catch(fail); });
				tb.appendChild(el('tr', {}, [
					el('td', {}, [el('a', { href: '#', class: 'nlm-name', text: m.name || 'Ohne Namen', onclick: function (ev) { ev.preventDefault(); open(m.id); } }), m.subject ? el('small', { text: m.subject }) : null]),
					el('td', {}, [chip(m.status)]), el('td', { text: sent }), el('td', { text: m.finished || m.started || m.created }),
					el('td', { class: 'nl-act' }, [dup, del])
				]));
			});
			box.appendChild(el('div', { class: 'nl-table-wrap' }, [el('table', { class: 'nl-table nlm-table' }, [
				el('thead', {}, [el('tr', {}, [el('th', { scope: 'col', text: 'Mailing' }), el('th', { scope: 'col', text: 'Stand' }), el('th', { scope: 'col', text: 'Versand' }), el('th', { scope: 'col', text: 'Datum' }), el('th', { scope: 'col' }, [el('span', { class: 'sh-vh', text: 'Aktionen' })])])]), tb])]));
			if (r.mails.some(function (m) { return m.status === 'sending'; })) { pollTimer = setInterval(function () { if (!cur && !TABS.mail[1].hidden) { showList(); } }, 10000); }
		}).catch(fail);
	}

	/* ---- editor ---- */
	function open(id, auto) {
		stopPoll(); say(''); fromAuto = !!auto; root = auto ? autoRoot : mailRoot;
		get('mail_get', '&id=' + id).then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.'); if (fromAuto) { showAutos(); } else { showList(); } return; }
			cur = r.mail; cur.base = r.base || ''; recipients = r.recipients; problems = r.mail.problems || []; dirty = false; version = 0; lastField = null; draw();
			if (cur.status === 'sending') { pollTimer = setInterval(refreshStats, 8000); }
		}).catch(fail);
	}
	function refreshStats() {
		if (!cur) { stopPoll(); return; }
		get('mail_get', '&id=' + cur.id).then(function (r) {
			if (!r.ok || !cur || cur.id !== r.mail.id) { return; }
			var was = cur.status; cur.stats = r.mail.stats; cur.status = r.mail.status;
			if (was !== cur.status) { stopPoll(); draw(); } else { drawStats(); }
		}).catch(function () {});
	}
	function setSaveState(t) { var s = document.getElementById('nlm-save'); if (s) { s.textContent = t; } }
	function touch() {
		if (cur.status !== 'draft' && cur.status !== 'auto') { return; }
		dirty = true; version++; setSaveState('Änderungen noch nicht gespeichert'); clearTimeout(saveTimer); saveTimer = setTimeout(save, 1200);
	}
	function save() {
		clearTimeout(saveTimer);
		if (!cur || (cur.status !== 'draft' && cur.status !== 'auto') || !dirty) { return Promise.resolve(true); }
		if (saving) { saveTimer = setTimeout(save, 400); return Promise.resolve(false); }
		saving = true; var v = version; setSaveState('Speichert ...');
		return post('mail_save', { id: cur.id, name: cur.name, subject: cur.subject, preheader: cur.preheader, blocks: JSON.stringify(cur.blocks) }).then(function (r) {
			saving = false;
			if (!r.ok) { setSaveState(r.error || 'Speichern hat nicht geklappt.'); return false; }
			if (v === version) { dirty = false; var d = new Date(); setSaveState('Gespeichert um ' + ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2)); } else { saveTimer = setTimeout(save, 300); }
			problems = r.problems || []; drawSend(); return true;
		}).catch(function () { saving = false; setSaveState('Keine Verbindung. Die Änderungen sind noch nicht gespeichert.'); return false; });
	}
	window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

	function field(label, value, onInput, o) {
		o = o || {};
		var id = 'nlm-f' + Math.random().toString(36).slice(2, 8);
		var inp = el('input', { type: 'text', id: id, value: value || '', maxlength: o.max || 200, placeholder: o.ph || '', autocomplete: 'off', spellcheck: 'true' });
		inp.addEventListener('input', function () { onInput(inp.value); touch(); });
		inp.addEventListener('focus', function () { lastField = inp; });
		return el('div', { class: 'nlm-field' }, [el('label', { for: id, text: label }), inp]);
	}
	function insertAtCaret(text) {
		var f = lastField; if (!f || !document.body.contains(f)) { say('Klicke zuerst in ein Textfeld, dann in den Platzhalter.'); return; }
		f.focus();
		if (f.tagName === 'INPUT') { f.setRangeText(text, f.selectionStart, f.selectionEnd, 'end'); f.dispatchEvent(new Event('input', { bubbles: true })); }
		else { document.execCommand('insertText', false, text); }
	}

	function toolBtn(label, icon, fn, disabled) { return el('button', { type: 'button', class: 'nlm-tool', 'aria-label': label, title: label, html: icon, disabled: !!disabled, onclick: fn }); }
	function moveBlock(i, d) {
		var j = i + d; if (j < 0 || j >= cur.blocks.length) { return; }
		var b = cur.blocks.splice(i, 1)[0]; cur.blocks.splice(j, 0, b); touch(); drawPaper(j); say('Block verschoben, jetzt Position ' + (j + 1) + ' von ' + cur.blocks.length + '.');
	}
	function removeBlock(i) { cur.blocks.splice(i, 1); touch(); drawPaper(Math.min(i, cur.blocks.length - 1)); say('Block entfernt.'); }

	function richEditor(b) {
		var ed = el('div', { class: 'nlm-rich', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'Text', spellcheck: 'true' });
		ed.innerHTML = b.html || '';
		var range = null;
		function cmd(c, v) { ed.focus(); document.execCommand(c, false, v || null); b.html = ed.innerHTML; touch(); }
		var urlIn = el('input', { type: 'url', class: 'nlm-url', placeholder: 'https://...', 'aria-label': 'Adresse des Links' }), linkRow = el('div', { class: 'nlm-linkrow', hidden: true }, [urlIn,
			el('button', { type: 'button', class: 'sh-btn', text: 'Link setzen', onclick: function () { linkRow.hidden = true; ed.focus(); if (range) { var s = getSelection(); s.removeAllRanges(); s.addRange(range); } if (urlIn.value.trim()) { cmd('createLink', urlIn.value.trim()); } } }),
			el('button', { type: 'button', class: 'sh-btn', text: 'Link entfernen', onclick: function () { linkRow.hidden = true; ed.focus(); if (range) { var s = getSelection(); s.removeAllRanges(); s.addRange(range); } cmd('unlink'); } })]);
		var keep = function (ev) { ev.preventDefault(); };
		var bar = el('div', { class: 'nlm-rbar', role: 'toolbar', 'aria-label': 'Textformat' }, [
			toolBtn('Fett', ICON.bold, function () { cmd('bold'); }), toolBtn('Kursiv', ICON.italic, function () { cmd('italic'); }), toolBtn('Liste', ICON.list, function () { cmd('insertUnorderedList'); }),
			toolBtn('Link', ICON.link, function () { var s = getSelection(); range = s.rangeCount ? s.getRangeAt(0).cloneRange() : null; linkRow.hidden = !linkRow.hidden; if (!linkRow.hidden) { urlIn.focus(); } })
		]);
		Array.prototype.forEach.call(bar.children, function (c) { c.addEventListener('mousedown', keep); });
		ed.addEventListener('input', function () { b.html = ed.innerHTML; touch(); });
		ed.addEventListener('focus', function () { lastField = ed; });
		ed.addEventListener('paste', function (ev) { ev.preventDefault(); var t = (ev.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t); });
		return el('div', {}, [bar, linkRow, ed]);
	}

	function blockBody(b) {
		if (b.t === 'h') {
			var h = el('input', { type: 'text', class: 'nlm-h', value: b.text || '', maxlength: 160, 'aria-label': 'Überschrift', placeholder: 'Überschrift', autocomplete: 'off' });
			h.addEventListener('input', function () { b.text = h.value; touch(); }); h.addEventListener('focus', function () { lastField = h; });
			return h;
		}
		if (b.t === 'p') { return richEditor(b); }
		if (b.t === 'img') {
			var holder = el('div', { class: 'nlm-imgwrap' });
			function pic() { holder.textContent = ''; if (b.src) { holder.appendChild(el('img', { src: b.src, alt: b.alt || '' })); } else { holder.appendChild(el('p', { class: 'nlm-imgempty', text: 'Noch kein Bild' })); } }
			pic();
			var file = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/gif,image/webp', class: 'sh-vh', 'aria-label': 'Bild hochladen' });
			file.addEventListener('change', function () {
				if (!file.files[0]) { return; } say('Bild wird hochgeladen ...');
				post('mail_upload', {}, file.files[0]).then(function (r) { if (!r.ok) { say(r.error || 'Das Bild konnte nicht hochgeladen werden.'); return; } b.src = r.url; touch(); say('Bild hochgeladen.'); drawPaper(cur.blocks.indexOf(b)); }).catch(fail);
			});
			return el('div', {}, [holder, el('div', { class: 'nlm-imgbar' }, [el('label', { class: 'sh-btn', text: 'Bild hochladen', onclick: function () { file.click(); } }), file]),
				field('Bildadresse (oder Bild hochladen)', b.src, function (v) { b.src = v; pic(); }, { max: 500, ph: 'https://...' }),
				field('Bildbeschreibung (für Leser ohne Bild)', b.alt, function (v) { b.alt = v; }, { max: 120 }), field('Link beim Antippen (optional)', b.link, function (v) { b.link = v; }, { max: 500, ph: 'https://...' })]);
		}
		if (b.t === 'btn') { return el('div', {}, [el('span', { class: 'nlm-btnprev', text: b.text || 'Button' }), field('Text auf dem Button', b.text, function (v) { b.text = v; }, { max: 40 }), field('Link', b.url, function (v) { b.url = v; }, { max: 500, ph: 'https://...' })]); }
		if (b.t === 'box') { return el('div', { class: 'nlm-boxfields' }, [field('Kleiner Titel', b.label, function (v) { b.label = v; }, { max: 60, ph: 'Dein Gutschein' }), field('Große Zeile', b.value, function (v) { b.value = v; }, { max: 40, ph: '5 EUR' }), field('Hinweis darunter', b.note, function (v) { b.note = v; }, { max: 120, ph: 'gültig bis ...' })]); }
		if (b.t === 'logo') { return el('div', {}, [el('img', { class: 'nlm-logoprev', src: (cur.base || '') + 'mail/logo-mail.png', alt: 'Amadeus', width: '110' }), el('p', { class: 'sh-help', text: 'Das Logo, klein und links. Es führt zum Shop. Am besten ganz oben.' })]); }
		return el('hr', { class: 'nlm-hr' });
	}
	function drawPaper(focusIdx) {
		var paper = document.getElementById('nlm-paper'); if (!paper) { return; }
		paper.textContent = '';
		if (!cur.blocks.length) { paper.appendChild(el('p', { class: 'nlm-emptyp', text: 'Das Mailing ist noch leer. Füge rechts einen Block hinzu.' })); }
		cur.blocks.forEach(function (b, i) {
			var name = (BLOCKS.filter(function (x) { return x[0] === b.t; })[0] || ['', b.t])[1];
			var box = el('section', { class: 'nlm-block', tabindex: '-1', 'aria-label': name + ', Block ' + (i + 1) + ' von ' + cur.blocks.length }, [
				el('div', { class: 'nlm-bhead' }, [el('span', { class: 'nlm-btype', text: name }), el('span', { class: 'nlm-btools' }, [
					toolBtn('Nach oben', ICON.up, function () { moveBlock(i, -1); }, i === 0), toolBtn('Nach unten', ICON.down, function () { moveBlock(i, 1); }, i === cur.blocks.length - 1), toolBtn('Block entfernen', ICON.del, function () { removeBlock(i); })])]),
				blockBody(b)]);
			paper.appendChild(box);
		});
		if (typeof focusIdx === 'number' && focusIdx >= 0 && paper.children[focusIdx]) { paper.children[focusIdx].focus(); }
	}
	function addBlock(t) {
		var b = { t: t };
		if (t === 'h') { b.text = ''; } else if (t === 'p') { b.html = ''; } else if (t === 'img') { b.src = ''; b.alt = ''; b.link = ''; } else if (t === 'btn') { b.text = ''; b.url = ''; } else if (t === 'box') { b.label = ''; b.value = ''; b.note = ''; }
		var at = cur.blocks.length, a = document.activeElement && document.activeElement.closest ? document.activeElement.closest('.nlm-block') : null;
		if (a) { at = Array.prototype.indexOf.call(a.parentNode.children, a) + 1; }
		cur.blocks.splice(at, 0, b); touch(); drawPaper(at); say('Block hinzugefügt an Position ' + (at + 1) + '.');
		var f = document.getElementById('nlm-paper').children[at]; var first = f && f.querySelector('input:not([type=file]), .nlm-rich'); if (first) { first.focus(); }
	}

	function preview() {
		save().then(function () {
			post('mail_preview', { subject: cur.subject, preheader: cur.preheader, blocks: JSON.stringify(cur.blocks) }).then(function (r) {
				if (!r.ok) { say(r.error || 'Das hat nicht geklappt.'); return; }
				var dlg = document.getElementById('nlm-dlg'); if (dlg) { dlg.remove(); }
				var frame = el('iframe', { class: 'nlm-frame', title: 'Vorschau der Mail', sandbox: '' }); frame.srcdoc = r.html;
				var wide = el('button', { type: 'button', class: 'sh-btn', 'aria-pressed': 'true', text: 'Computer' }), narrow = el('button', { type: 'button', class: 'sh-btn', 'aria-pressed': 'false', text: 'Handy' });
				wide.addEventListener('click', function () { frame.style.width = ''; wide.setAttribute('aria-pressed', 'true'); narrow.setAttribute('aria-pressed', 'false'); });
				narrow.addEventListener('click', function () { frame.style.width = '375px'; narrow.setAttribute('aria-pressed', 'true'); wide.setAttribute('aria-pressed', 'false'); });
				dlg = el('dialog', { id: 'nlm-dlg', class: 'nlm-dlg', 'aria-label': 'Vorschau' }, [
					el('div', { class: 'nlm-dlghead' }, [el('div', {}, [el('strong', { text: 'Vorschau' }), el('p', { class: 'nlm-subj', text: 'Betreff: ' + (r.subject || '(fehlt)') })]), el('span', { class: 'nlm-dlgbtns' }, [wide, narrow, el('button', { type: 'button', class: 'sh-btn is-primary', text: 'Schließen', onclick: function () { dlg.close(); } })])]),
					el('div', { class: 'nlm-frames' }, [frame]), el('p', { class: 'sh-help', text: 'So sieht Max Mustermann die Mail. Beim Versand steht der Name des jeweiligen Gastes darin.' })]);
				dlg.addEventListener('close', function () { dlg.remove(); });
				document.body.appendChild(dlg); dlg.showModal();
			}).catch(fail);
		});
	}

	function drawStats() {
		var s = cur.stats, box = document.getElementById('nlm-stats'); if (!box) { return; }
		var done = s.sent + s.failed + s.skipped, pct = s.total ? Math.round(done * 100 / s.total) : 0;
		box.textContent = '';
		box.appendChild(el('div', { class: 'nlm-bar', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(s.total), 'aria-valuenow': String(done), 'aria-label': 'Fortschritt des Versands' }, [el('span', { style: 'width:' + pct + '%' })]));
		box.appendChild(el('p', { class: 'nlm-statline', text: s.sent + ' von ' + s.total + ' gesendet' + (s.pending + s.sending ? ', ' + (s.pending + s.sending) + ' warten noch' : '') + (s.failed ? ', ' + s.failed + ' fehlgeschlagen' : '') + (s.skipped ? ', ' + s.skipped + ' übersprungen (inzwischen abgemeldet oder abgebrochen)' : '') }));
	}
	function drawSend() {
		var box = document.getElementById('nlm-send'); if (!box || cur.status !== 'draft') { return; }
		box.textContent = '';
		box.appendChild(el('p', { class: 'nlm-recip' }, [el('b', { text: String(recipients) }), ' ' + (recipients === 1 ? 'Gast bekommt' : 'Gäste bekommen') + ' dieses Mailing.']));
		if (recipients > 500) { box.appendChild(el('p', { class: 'sh-help', text: 'Über 500 Empfänger: der eigene Server schafft das, aber es dauert. Für größere Listen ist ein Mailversanddienst die bessere Wahl.' })); }
		if (problems.length) { box.appendChild(el('ul', { class: 'nlm-problems' }, problems.map(function (p) { return el('li', { text: p }); }))); }
		var go = el('button', { type: 'button', class: 'sh-btn is-primary', text: 'Versand starten', disabled: problems.length > 0 || recipients === 0 });
		armed(go, 'Ja, jetzt an ' + recipients + (recipients === 1 ? ' Gast' : ' Gäste') + ' senden', function () {
			go.disabled = true; save().then(function () { return post('mail_start', { id: cur.id }); }).then(function (r) { if (!r.ok) { go.disabled = false; say(r.error || 'Das hat nicht geklappt.'); return; } say('Der Versand läuft.'); open(cur.id); }).catch(function () { go.disabled = false; fail(); });
		});
		box.appendChild(go);
		box.appendChild(el('p', { class: 'sh-help', text: 'Danach lässt sich der Inhalt nicht mehr ändern. Wer sich bis dahin abmeldet, bekommt nichts.' }));
	}

	function draw() {
		stopPollIfNot(); root.textContent = '';
		var draft = cur.status === 'draft' || cur.status === 'auto', isAuto = cur.status === 'auto';
		var name = el('input', { type: 'text', class: 'nlm-name-in', value: cur.name, maxlength: 120, 'aria-label': 'Name des Mailings (nur für dich)', disabled: !draft || isAuto });
		name.addEventListener('input', function () { cur.name = name.value; touch(); });
		var top = el('div', { class: 'nlm-top' }, [
			el('button', { type: 'button', class: 'sh-btn nlm-backbtn', html: ICON.back + '<span>' + (isAuto ? 'Alle Automatiken' : 'Alle Mailings') + '</span>', onclick: function () { save().then(function () { if (isAuto) { cur = null; showAutos(); } else { showList(); } }); } }), name, chip(cur.status),
			el('span', { class: 'detail-status', id: 'nlm-save', text: draft ? 'Gespeichert' : '' }),
			el('button', { type: 'button', class: 'sh-btn nlm-eyebtn', html: ICON.eye + '<span>Vorschau</span>', onclick: preview })]);
		root.appendChild(top);
		var main = el('div', { class: 'nlm-main' }), side = el('aside', { class: 'nlm-side' });
		if (draft) {
			main.appendChild(el('div', { class: 'nlm-meta' }, [field('Betreff', cur.subject, function (v) { cur.subject = v; }, { max: 200, ph: 'z. B. Neu: unser Herbstmenü' }), field('Vorschautext (steht im Posteingang neben dem Betreff)', cur.preheader, function (v) { cur.preheader = v; }, { max: 200 })]));
			main.appendChild(el('div', { class: 'nlm-paper', id: 'nlm-paper' }));
			var add = el('div', { class: 'nlm-grid' }); BLOCKS.forEach(function (b) { add.appendChild(el('button', { type: 'button', class: 'sh-btn', text: b[1], onclick: function () { addBlock(b[0]); } })); });
			side.appendChild(el('section', { class: 'nlm-card' }, [el('h3', { text: 'Block hinzufügen' }), add, el('p', { class: 'sh-help', text: 'Der Block kommt hinter den Block, in dem du gerade bist, sonst ans Ende.' })]));
			var VARS = { gutschein: 'Gutschein-Code', gutschein_wert: 'Gutschein-Wert', gutschein_bis: 'Gutschein gültig bis', gutschein_ab: 'Gutschein ab Warenwert', stempel_stand: 'Stempel: Stand', stempel_fehlen: 'Stempel: es fehlen', stempel_ziel: 'Stempel: Ziel', stempel_bis: 'Stempel gültig bis', halten: 'Link: weiter Mails' };
			var ph = el('div', { class: 'nlm-grid' }); [['Vorname', '{vorname|Gast}'], ['Ganzer Name', '{name}'], ['Abmelde-Link', '{abmelden}']].concat((cur.vars || []).map(function (v) { return [VARS[v] || v, '{' + v + '}']; })).forEach(function (p) { var b = el('button', { type: 'button', class: 'sh-btn', text: p[0], onclick: function () { insertAtCaret(p[1]); } }); b.addEventListener('mousedown', function (ev) { ev.preventDefault(); }); ph.appendChild(b); });
			side.appendChild(el('section', { class: 'nlm-card' }, [el('h3', { text: 'Platzhalter' }), ph, el('p', { class: 'sh-help', text: 'Fügt an der Stelle im Text den Namen des Gastes ein. "Gast" steht da, wenn wir keinen Namen haben. Der Abmelde-Link steht ohnehin in jeder Mail unten.' })]));
			var to = el('input', { type: 'email', id: 'nlm-to', placeholder: 'deine@adresse.de', autocomplete: 'email' }); try { to.value = localStorage.getItem('nlTestTo') || ''; } catch (e) { /* optional */ }
			var tb = el('button', { type: 'button', class: 'sh-btn', text: 'Testmail senden', onclick: function () { if (!to.value.trim()) { to.focus(); say('Bitte gib eine Adresse für die Testmail an.'); return; } try { localStorage.setItem('nlTestTo', to.value.trim()); } catch (e) { /* optional */ } tb.disabled = true; say('Testmail wird gesendet ...'); save().then(function () { return post('mail_test', { id: cur.id, to: to.value.trim() }); }).then(function (r) { tb.disabled = false; say(r.ok ? 'Testmail ist unterwegs an ' + to.value.trim() + '.' : (r.error || 'Das hat nicht geklappt.')); }).catch(function () { tb.disabled = false; fail(); }); } });
			side.appendChild(el('section', { class: 'nlm-card' }, [el('h3', { text: 'Testmail' }), el('label', { class: 'sh-vh', for: 'nlm-to', text: 'Adresse für die Testmail' }), to, tb, el('p', { class: 'sh-help', text: 'Schau sie dir im Mailprogramm und auf dem Handy an, bevor du startest.' })]));
			if (isAuto) { side.appendChild(el('section', { class: 'nlm-card' }, [el('h3', { text: 'Diese Automatik' }), el('p', { class: 'sh-help', text: 'Das ist die Mail, die die Automatik verschickt. Änderungen gelten sofort für alle folgenden Mails. Die Platzhalter mit dem Gutschein füllt das System für jeden Gast einzeln.' })])); }
			else { side.appendChild(el('section', { class: 'nlm-card' }, [el('h3', { text: 'Versand' }), el('div', { id: 'nlm-send' })])); }
		} else {
			var st = el('section', { class: 'nlm-card nlm-wide' }, [el('h3', { text: cur.status === 'sending' ? 'Der Versand läuft' : (cur.status === 'done' ? 'Versendet' : 'Abgebrochen') }), el('div', { id: 'nlm-stats' })]);
			if (cur.status === 'sending') {
				st.appendChild(el('p', { class: 'sh-help', text: 'Die Mails gehen in kleinen Paketen raus. Solange diese Seite offen ist, sind es 10 pro Minute; mit dem Cron-Job web/cron/send_newsletter.php 30 pro Minute, auch bei geschlossener Seite.' }));
				var cb = el('button', { type: 'button', class: 'sh-btn nl-erase', text: 'Versand abbrechen' }); armed(cb, 'Wirklich abbrechen?', function () { post('mail_cancel', { id: cur.id }).then(function (r) { if (r.ok) { open(cur.id); } else { fail(); } }).catch(fail); });
				st.appendChild(cb);
			}
			var dup = el('button', { type: 'button', class: 'sh-btn', text: 'Als neues Mailing duplizieren', onclick: function () { post('mail_new', { name: cur.name, copy: cur.id }).then(function (x) { if (x.ok) { open(x.id); } else { fail(x.error); } }).catch(fail); } });
			st.appendChild(el('p', {}, [dup]));
			main.appendChild(el('div', { class: 'nlm-meta' }, [el('p', {}, [el('strong', { text: 'Betreff: ' }), cur.subject || '(ohne)'])]));
			main.appendChild(st);
			main.appendChild(el('p', { class: 'sh-help', text: 'Der Inhalt ist nach dem Start gesperrt. Über "Vorschau" siehst du die Mail, mit "Duplizieren" machst du eine neue Fassung daraus.' }));
		}
		root.appendChild(el('div', { class: 'nlm-cols' }, [main, side]));
		if (draft) { drawPaper(); if (!isAuto) { drawSend(); } } else { drawStats(); }
	}
	function stopPollIfNot() { if (cur && cur.status !== 'sending') { stopPoll(); } }

	/* ---- automations ---- */
	var TOPIC = { news: 'Neuigkeiten', stamps: 'Stempel-Erinnerungen' };
	function showAutos() {
		stopPoll(); cur = null; root = autoRoot; fromAuto = true; root.textContent = '';
		root.appendChild(el('p', { class: 'sh-help', text: 'Automatiken schicken Mails von selbst, wenn bei einem Gast etwas passiert. Alle sind aus, bis du sie einschaltest. Ein Gast bekommt höchstens eine automatische Mail in ' + 7 + ' Tagen (die Begrüßungsmail ausgenommen), und nur, wenn er der Sparte per Mail zugestimmt hat. Sie laufen über den Cron-Job oder, solange diese Seite offen ist.' }));
		var box = el('div', { class: 'nla-list' }); root.appendChild(box);
		get('auto_list').then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.'); return; }
			if (!r.from) { box.appendChild(el('p', { class: 'sh-warn', text: 'Der Mailversand ist noch nicht eingerichtet. Ohne Absenderadresse kann nichts verschickt werden.' })); }
			r.autos.forEach(function (a) { box.appendChild(autoCard(a)); });
		}).catch(fail);
	}
	function autoCard(a) {
		var vals = {}, timer = null;
		var state = el('span', { class: 'nlm-chip ' + (a.on ? 'is-done' : ''), text: a.on ? 'An' : 'Aus' });
		var head = el('div', { class: 'nla-head' }, [el('h3', { text: a.title }), state, el('span', { class: 'nla-topic', text: 'Sparte: ' + TOPIC[a.topic] })]);
		var inputs = el('div', { class: 'nla-fields' });
		a.fields.forEach(function (f) {
			var eur = f[2] === 'eur', id = 'nla-' + a.kind + '-' + f[0], v = eur ? (a.cfg[f[0]] / 100) : a.cfg[f[0]];
			var inp = el('input', { type: 'number', id: id, value: String(v), min: String(f[3]), max: String(f[4]), step: '1', inputmode: 'numeric' });
			inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(saveCfg, 700); });
			vals[f[0]] = function () { var n = parseInt(inp.value, 10); return isNaN(n) ? 0 : (eur ? n * 100 : n); };
			inputs.appendChild(el('div', { class: 'nlm-field' }, [el('label', { for: id, text: f[1] }), el('span', { class: 'nla-unit' }, [inp, el('span', { text: eur ? 'EUR' : f[2] })])]));
		});
		function params() { var o = {}; for (var k in vals) { o[k] = vals[k](); } return JSON.stringify(o); }
		function saveCfg(on) { return post('auto_save', { kind: a.kind, on: (on === undefined ? a.on : on) ? 1 : 0, params: params() }).then(function (r) { if (!r.ok) { say(r.error || 'Das hat nicht geklappt.'); return null; } say(a.title + ': gespeichert.'); return r; }); }
		var sw = el('button', { type: 'button', class: 'sh-btn' + (a.on ? '' : ' is-primary'), text: a.on ? 'Ausschalten' : 'Einschalten' });
		sw.addEventListener('click', function () {
			if (!a.on && !sw.dataset.armed) { sw.dataset.armed = '1'; sw.textContent = 'Wirklich einschalten? Ab dann gehen Mails raus'; setTimeout(function () { sw.dataset.armed = ''; if (!a.on) { sw.textContent = 'Einschalten'; } }, 6000); return; }
			sw.dataset.armed = ''; var turn = !a.on; saveCfg(turn).then(function (r) { if (r) { showAutos(); say(a.title + (turn ? ' ist eingeschaltet.' : ' ist ausgeschaltet.')); } });
		});
		var edit = el('button', { type: 'button', class: 'sh-btn', text: 'Mail bearbeiten', onclick: function () { open(a.template, true); } });
		var stat = el('p', { class: 'nla-stat', text: a.n30 + (a.n30 === 1 ? ' Mail' : ' Mails') + ' in den letzten 30 Tagen' + (a.bad30 ? ', ' + a.bad30 + ' nicht zugestellt' : '') });
		return el('section', { class: 'nlm-card nla-card' }, [head, el('p', { class: 'nla-when', text: a.when }), el('p', { class: 'sh-help', text: a.desc }), inputs, el('div', { class: 'nla-actions' }, [sw, edit]), stat]);
	}

	var want = 'subs'; try { want = ({ mail: 1, auto: 1 })[sessionStorage.getItem('nlTab')] ? sessionStorage.getItem('nlTab') : 'subs'; } catch (e) { /* optional */ }
	if (want !== 'subs') { tab(want); }
})();
