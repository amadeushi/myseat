/* Status page, after a delivery: "Alles dabei?". The guest calls the driver (or the restaurant), or reports what is missing (tick list of his order and the usual forgotten things),
   chooses redelivery or credit and may write a short note. The state comes from api.php (issue_state), the report goes to issue_create. While the staff has not decided the box asks again
   every 10 seconds, so the answer appears without reloading. When the chat is on (state.chat) a small chat sits under the answer: the guest writes to the dispatch, a message of the dispatch or an automatic
   answer appears within a few seconds (polling issue_chat every 4 seconds while the page is visible). No library, no tracking. */
(function () {
	'use strict';
	var box = document.getElementById('st-issue'); if (!box) { return; }
	var TOKEN = document.body.dataset.token, ORDER = box.dataset.order, state = null, timer = null, openForm = false;
	try { state = JSON.parse(box.dataset.state || 'null'); } catch (e) { state = null; }
	if (!state || !state.show) { return; }

	function el(tag, attrs, kids) {
		var e = document.createElement(tag), k;
		for (k in (attrs || {})) { if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'class') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); } }
		(kids || []).forEach(function (c) { if (c) { e.appendChild(c); } });
		return e;
	}
	function post(data) {
		return fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(Object.assign({ op: 'issue_create', token: TOKEN, order: ORDER }, data)) }).then(function (r) { return r.json(); });
	}
	function load() {
		return fetch('api.php?op=issue_state&order=' + encodeURIComponent(ORDER), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
	}
	function tel(p) { return 'tel:' + String(p).replace(/[^\d+]/g, ''); }

	function callButton(call) {
		if (!call) { return null; }
		var label = call.driver ? (call.who ? call.who + ' anrufen' : 'Fahrer anrufen') : 'Restaurant anrufen';
		return el('a', { class: 'cart-go is-quiet', href: tel(call.phone) }, [el('span', { text: label })]);
	}

	function drawForm(s) {
		var form = el('form', { class: 'st-issue-form', novalidate: 'novalidate' });
		var legend1 = el('legend', { text: 'Was fehlt?' }), set1 = el('fieldset', { class: 'st-issue-set' }, [legend1]);
		s.choices.forEach(function (c, i) {
			var id = 'st-issue-c' + i;
			set1.appendChild(el('div', { class: 'st-issue-row' }, [el('input', { type: 'checkbox', id: id, name: 'item', value: c.key }), el('label', { for: id, text: c.label })]));
		});
		var legend2 = el('legend', { text: 'Was wäre dir lieber?' }), set2 = el('fieldset', { class: 'st-issue-set' }, [legend2]);
		[['credit', 'Eine Gutschrift reicht'], ['redeliver', 'Bitte nachliefern']].forEach(function (w, i) {
			var id = 'st-issue-w' + i, inp = el('input', { type: 'radio', id: id, name: 'wish', value: w[0] }); if (i === 0) { inp.checked = true; }
			set2.appendChild(el('div', { class: 'st-issue-row' }, [inp, el('label', { for: id, text: w[1] })]));
		});
		var noteLabel = el('label', { for: 'st-issue-note', class: 'st-issue-label', text: 'Kurze Nachricht (wenn du magst)' });
		var note = el('textarea', { id: 'st-issue-note', name: 'note', rows: '2', maxlength: '300', autocomplete: 'off' });
		var msg = el('p', { class: 'st-issue-msg', role: 'status', 'aria-live': 'polite' });
		var go = el('button', { type: 'submit', class: 'cart-go', text: 'Meldung senden' });
		[set1, set2, noteLabel, note, go, msg].forEach(function (n) { form.appendChild(n); });
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var items = [].slice.call(form.querySelectorAll('input[name=item]:checked')).map(function (i) { return i.value; });
			if (!items.length) { msg.textContent = 'Bitte wähle aus, was fehlt.'; var f = form.querySelector('input[name=item]'); if (f) { f.focus(); } return; }
			var wish = form.querySelector('input[name=wish]:checked').value;
			go.disabled = true; msg.textContent = 'Einen Moment ...';
			post({ items: items, wish: wish, note: note.value }).then(function (r) {
				if (r.ok) { state = r; openForm = false; draw(true); poll(); return; }
				go.disabled = false; msg.textContent = r.error || 'Das hat nicht geklappt. Bitte ruf uns kurz an.';
			}).catch(function () { go.disabled = false; msg.textContent = 'Keine Verbindung. Deine Auswahl bleibt stehen, bitte versuche es noch einmal.'; });
		});
		return form;
	}

	function draw(focusResult) {
		var s = state, issue = s.issue;
		box.textContent = '';
		if (issue) {
			var t = el('p', { class: 'st-issue-text', text: issue.text, tabindex: '-1', role: 'status' });
			box.appendChild(el('h2', { text: 'Deine Meldung' }));
			box.appendChild(el('ul', { class: 'st-issue-items' }, issue.items.map(function (x) { return el('li', { text: x }); })));
			box.appendChild(t);
			if (issue.code) { box.appendChild(el('p', { class: 'st-issue-code' }, [el('span', { text: 'Dein Code: ' }), el('strong', { text: issue.code })])); }
			var cb = callButton(s.call); if (cb && issue.status !== 'credit') { box.appendChild(cb); }
			if (focusResult) { t.focus(); }
			return;
		}
		box.appendChild(el('h2', { text: 'Alles dabei?' }));
		if (!s.open) {
			box.appendChild(el('p', { class: 'st-issue-text', text: 'Das Meldefenster ist zu. Ruf uns bitte kurz an, wenn etwas fehlt.' }));
			var c0 = callButton(s.call); if (c0) { box.appendChild(c0); }
			return;
		}
		box.appendChild(el('p', { class: 'st-issue-text', text: 'Falls etwas fehlt, sag es uns. Das geht hier bis ' + s.until + ' Uhr.' }));
		var actions = el('div', { class: 'st-issue-actions' });
		var cb2 = callButton(s.call); if (cb2) { actions.appendChild(cb2); }
		var open = el('button', { type: 'button', class: 'cart-go', 'aria-expanded': openForm ? 'true' : 'false', 'aria-controls': 'st-issue-form-wrap' }, [el('span', { text: 'Es fehlt etwas' })]);
		actions.appendChild(open);
		box.appendChild(actions);
		if (s.call && s.call.driver) { box.appendChild(el('p', { class: 'st-issue-hint', text: 'Nicht erreicht? Dann melde es hier, wir kümmern uns.' })); }
		var wrap = el('div', { id: 'st-issue-form-wrap' }); wrap.hidden = !openForm;
		wrap.appendChild(drawForm(s));
		box.appendChild(wrap);
		open.addEventListener('click', function () {
			openForm = wrap.hidden; wrap.hidden = !openForm; open.setAttribute('aria-expanded', openForm ? 'true' : 'false');
			if (openForm) { var f = wrap.querySelector('input'); if (f) { f.focus(); } }
		});
	}

	// while nobody has decided, ask again every 10 seconds (at most for an hour)
	var polls = 0;
	function poll() {
		clearTimeout(timer);
		if (!state || !state.issue || ['new', 'approval'].indexOf(state.issue.status) < 0 || ++polls > 360) { return; }
		timer = setTimeout(function () {
			load().then(function (r) {
				if (r && r.ok && r.show && r.issue && state.issue && r.issue.status !== state.issue.status) { state = r; draw(true); }
				poll();
			}).catch(poll);
		}, 10000);
	}

	// ---- chat with the dispatch
	var chat = { box: null, log: null, presence: null, input: null, send: null, msg: null, last: 0, seen: {}, n: 0, timer: null, polls: 0 };
	function chatBuild() {
		chat.log = el('div', { class: 'st-chat-log', role: 'log', 'aria-live': 'polite', 'aria-label': 'Nachrichten' });
		chat.presence = el('p', { class: 'st-chat-presence' });
		chat.input = el('input', { type: 'text', id: 'st-chat-in', maxlength: '400', autocomplete: 'off', 'aria-label': 'Deine Nachricht', placeholder: 'Nachricht schreiben' });
		chat.send = el('button', { type: 'submit', class: 'cart-go', text: 'Senden' });
		chat.msg = el('p', { class: 'st-issue-msg', role: 'status', 'aria-live': 'polite' });
		var form = el('form', { class: 'st-chat-form', novalidate: 'novalidate' }, [chat.input, chat.send]);
		chat.form = form;
		chat.box = el('div', { class: 'st-chat' }, [el('h3', { text: 'Schreib uns' }), chat.presence, chat.log, form, chat.msg]);
		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			var text = chat.input.value.trim(); if (!text) { return; }
			chat.send.disabled = true; chat.msg.textContent = '';
			fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify({ op: 'issue_msg', token: TOKEN, order: ORDER, text: text, since: chat.last }) })
				.then(function (r) { return r.json(); }).then(function (r) {
					chat.send.disabled = false;
					if (r.ok) { chat.input.value = ''; chatApply(r.chat); chat.polls = 0; chatPoll(); } else { chat.msg.textContent = r.error || 'Das hat nicht geklappt. Bitte ruf uns kurz an.'; }
					chat.input.focus();
				}).catch(function () { chat.send.disabled = false; chat.msg.textContent = 'Keine Verbindung. Dein Text bleibt stehen, bitte versuche es noch einmal.'; });
		});
	}
	function chatApply(c) {
		if (!c) { return; }
		(c.msgs || []).forEach(function (m) {
			if (chat.seen[m.id]) { return; }
			chat.seen[m.id] = true; chat.last = Math.max(chat.last, m.id);
			var who = m.side === 'guest' ? 'Du' : (m.side === 'ai' ? 'Automatische Antwort' : 'Disposition');
			chat.log.appendChild(el('p', { class: 'st-chat-msg is-' + m.side }, [el('span', { class: 'st-chat-who', text: who + ' · ' + m.at }), el('span', { class: 'st-chat-text', text: m.text })]));
			chat.log.scrollTop = chat.log.scrollHeight;
		});
		chat.form.hidden = !c.on;
		chat.presence.textContent = !c.on ? 'Der Chat ist beendet. Ruf uns bei Bedarf kurz an.' : (c.online ? 'Gerade ist jemand da und antwortet.' : 'Wir antworten, sobald jemand frei ist. Verlässt du die Seite, schicken wir dir eine SMS.');
		chat.on = c.on;
	}
	function chatMount() {
		if (!state.issue || !state.chat) { return; }
		if (!chat.box) { chatBuild(); chatApply(state.chat); chatPoll(); }
		box.appendChild(chat.box);
	}
	function chatPoll() {
		clearTimeout(chat.timer);
		if (!chat.box || chat.polls > 900 || chat.on === false) { return; }
		chat.timer = setTimeout(function () {
			if (document.hidden) { chatPoll(); return; }
			chat.polls++;
			fetch('api.php?op=issue_chat&order=' + encodeURIComponent(ORDER) + '&since=' + chat.last, { credentials: 'same-origin', cache: 'no-store' })
				.then(function (r) { return r.json(); }).then(function (r) { if (r && r.ok) { chatApply(r.chat); } chatPoll(); }).catch(chatPoll);
		}, 4000);
	}
	var drawBase = draw;
	draw = function (f) { drawBase(f); chatMount(); };

	draw(false); poll();
}());
