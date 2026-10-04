/* Menu editor of the delivery service: list on the left (categories with their dishes, or the option groups), form on the right.
   Everything is edited in the form and saved with one button; the server answers with the saved row. */
(function () {
	'use strict';
	var page = document.getElementById('menu-page'), root = document.getElementById('me-root'), note = document.getElementById('me-note');
	var TOKEN = page.dataset.token;
	var D = { categories: [], products: [], groups: [], coupons: [] }, view = 'dishes', sel = null, dirty = false, query = '';

	function $(s, r) { return (r || document).querySelector(s); }
	function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function eur(c) { return (c / 100).toFixed(2).replace('.', ','); }
	function money(c) { return eur(c) + ' €'; }
	function byId(list, id) { return list.filter(function (x) { return x.id === id; })[0]; }
	function copy(o) { return JSON.parse(JSON.stringify(o)); }
	function say(msg, bad) {
		note.textContent = msg || ''; note.className = 'me-note' + (msg ? (bad ? ' is-bad' : ' is-ok') : '');
		clearTimeout(say.t); if (msg) { say.t = setTimeout(function () { note.textContent = ''; note.className = 'me-note'; }, 5000); }
	}
	function api(op, payload) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN);
		Object.keys(payload || {}).forEach(function (k) { fd.append(k, k === 'data' ? JSON.stringify(payload[k]) : payload[k]); });
		return fetch('ajax/shop_menu_admin.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			if (!r.ok) { say(r.error || 'Das hat nicht geklappt.', true); throw new Error('api'); }
			return r;
		}, function () { say('Keine Verbindung. Bitte versuche es noch einmal.', true); throw new Error('net'); });
	}
	function rule(g) {
		if (g.min > 0) { return g.min === g.max ? 'Pflicht: genau ' + g.min : 'Pflicht: mindestens ' + g.min + (g.max > 0 ? ', höchstens ' + g.max : ''); }
		return g.max > 0 ? 'optional, bis zu ' + g.max : 'optional';
	}
	// themed <dialog> instead of native confirm()/prompt() - same fix already made in orders.js this project,
	// for the same reason: a native dialog is invisible/jarring in a full-screen kiosk/tablet backoffice context
	var dlg = $('#me-dlg');
	function openDialog(title, bodyHtml, confirmLabel) {
		return new Promise(function (resolve) {
			$('#me-od-title').textContent = title;
			$('#me-od-body').innerHTML = bodyHtml;
			$('#me-od-confirm').textContent = confirmLabel || 'OK';
			function onClose() {
				dlg.removeEventListener('close', onClose);
				resolve(dlg.returnValue === 'confirm' ? $('#me-od-form') : null);
			}
			dlg.addEventListener('close', onClose);
			if (typeof dlg.showModal === 'function') { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
			$('#me-od-confirm').focus();
		});
	}
	if (dlg) { $('#me-od-cancel').addEventListener('click', function () { dlg.close('cancel'); }); }
	function confirmDialog(title, message, confirmLabel) {
		return openDialog(title, '<p>' + esc(message) + '</p>', confirmLabel).then(function (f) { return !!f; });
	}
	function discardOk() { return !dirty ? Promise.resolve(true) : confirmDialog('Ungespeicherte Änderungen', 'Ungespeicherte Änderungen verwerfen?', 'Verwerfen'); }

	// ---- loading
	function apply(r) { ['categories', 'products', 'groups', 'coupons'].forEach(function (k) { if (r[k]) { D[k] = r[k]; } }); if (r.img_prefix) { D.prefix = r.img_prefix; } }

	// ---- dish pictures: kept on this server (uploaded here, or fetched from the foreign address once)
	var imgReport = '';
	function isLocal(u) { return !!u && !!D.prefix && u.indexOf(D.prefix) === 0; }
	function externalDishes() { return D.products.filter(function (p) { return p.image_url && !isLocal(p.image_url); }); }
	function imgBlock(u) {
		var ext = u && !isLocal(u);
		return '<div class="me-img">' + (u ? '<img class="me-thumb" src="' + esc(u) + '" alt="Bild des Gerichts"/>' : '<span class="me-thumb me-thumb-none">kein Bild</span>') + '<div class="me-img-side">' +
			'<div class="me-img-btns"><button type="button" class="button_dark" data-imgpick>' + (u ? 'Bild ersetzen' : 'Bild hochladen') + '</button>' + (u ? '<button type="button" class="me-mini" data-imgrm>Entfernen</button>' : '') + (ext ? '<button type="button" class="me-mini" data-imgfetch>Auf den Server holen</button>' : '') + '</div>' +
			'<small class="me-help">' + (!u ? 'JPG, PNG oder WebP. Das Bild wird verkleinert auf dem Server gespeichert.' : ext ? 'Fremd verlinkt: fehlt, sobald die andere Seite das Bild ändert oder löscht.' : 'Auf dem Server gespeichert.') + '</small></div></div>' +
			'<input type="file" id="me-file" accept="image/jpeg,image/png,image/webp" hidden/>' +
			'<details class="me-img-url"' + (ext ? ' open' : '') + '><summary>Bildadresse</summary><input id="me-img" maxlength="300" value="' + esc(u) + '" aria-label="Bildadresse"/></details>';
	}
	function refreshImg(u) { $('#me-imgwrap').innerHTML = imgBlock(u); sel.draft.image_url = u; }
	function imgBar() {
		var n = externalDishes().length;
		if (!n && !imgReport) { return ''; }
		return '<div class="me-imgbar" id="me-imgbar" role="status">' + (n ? '<span><strong>' + n + '</strong> Bild' + (n === 1 ? ' ist' : 'er sind') + ' fremd verlinkt.</span><button type="button" class="me-mini" data-imgall>Alle auf den Server holen</button>' : '<span>Alle Bilder liegen auf dem Server.</span>') +
			(imgReport ? '<span class="me-imgrep">' + imgReport + '</span>' : '') + '</div>';
	}
	// one dish after the other, so one broken address does not stop the rest; the answer of each is read without the red message
	function quiet(op, payload) {
		var fd = new FormData(); fd.append('op', op); fd.append('token', TOKEN);
		Object.keys(payload || {}).forEach(function (k) { fd.append(k, payload[k]); });
		return fetch('ajax/shop_menu_admin.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
	}
	function localizeAll() {
		var list = externalDishes(), done = 0, fails = [], bar = $('#me-imgbar');
		function prog() { if (bar) { bar.innerHTML = '<span>Bilder werden geholt: ' + done + ' von ' + list.length + ' ...</span>'; } }
		prog();
		(function next(i) {
			if (i >= list.length) {
				imgReport = esc((list.length - fails.length) + ' geholt') + (fails.length ? ', ' + fails.length + ' fehlgeschlagen: ' + esc(fails.join('; ')) : '.');
				render(); say(fails.length ? 'Nicht alle Bilder konnten geholt werden.' : 'Alle Bilder liegen jetzt auf dem Server.', !!fails.length); return;
			}
			var p = list[i];
			quiet('img_localize', { id: p.id }).then(function (r) {
				if (r.ok) { p.image_url = r.url; if (sel && sel.t === 'p' && sel.id === p.id) { sel.draft.image_url = r.url; } } else { fails.push(p.title + ' (' + (r.error || 'Fehler') + ')'); }
			}, function () { fails.push(p.title + ' (keine Verbindung)'); }).then(function () { done++; prog(); next(i + 1); });
		})(0);
	}
	function load() {
		fetch('ajax/shop_menu_admin.php?op=load', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) {
			if (r.status === 403) { throw new Error('perm'); } return r.json();
		}).then(function (r) { if (!r.ok) { throw new Error('bad'); } apply(r); render(); })
			.catch(function () { root.innerHTML = '<p class="orders-empty">Die Speisekarte konnte nicht geladen werden. Bitte lade die Seite neu.</p>'; });
	}

	// ---- lists
	function dishBadges(p) {
		var b = '';
		if (!p.active) { b += '<span class="me-tag">versteckt</span>'; }
		if (p.groups.length) { b += '<span class="me-tag">' + p.groups.length + ' Gruppe' + (p.groups.length > 1 ? 'n' : '') + '</span>'; }
		return b;
	}
	function priceOf(p) {
		if (p.variations.length) { return 'ab ' + money(Math.min.apply(null, p.variations.map(function (v) { return v.price; }))); }
		return money(p.price);
	}
	function dishList() {
		var h = '<div class="me-tools"><input type="search" id="me-q" placeholder="Suchen ..." aria-label="Gerichte suchen" value="' + esc(query) + '"/><button type="button" class="button_dark" data-newcat>Neue Kategorie</button></div>' + imgBar();
		if (!D.categories.length) { h += '<p class="orders-empty">Noch keine Kategorien. Lege die erste an.</p>'; }
		D.categories.forEach(function (c, ci) {
			var ps = D.products.filter(function (p) { return p.category_id === c.id; });
			h += '<section class="me-cat"><header class="me-cat-head"><button type="button" class="me-cat-name' + (sel && sel.t === 'c' && sel.id === c.id ? ' is-sel' : '') + '" data-cat="' + c.id + '">' + esc(c.name) + '<span class="me-count">' + ps.length + '</span>' + (c.active ? '' : '<span class="me-tag">versteckt</span>') + '</button>' +
				'<span class="me-arrows"><button type="button" class="me-mini" data-catmove="up" data-id="' + c.id + '" aria-label="Kategorie nach oben"' + (ci === 0 ? ' disabled' : '') + '>&uarr;</button><button type="button" class="me-mini" data-catmove="down" data-id="' + c.id + '" aria-label="Kategorie nach unten"' + (ci === D.categories.length - 1 ? ' disabled' : '') + '>&darr;</button></span>' +
				'<button type="button" class="me-mini me-add" data-newdish="' + c.id + '">+ Gericht</button></header><ul class="me-dishes">';
			ps.forEach(function (p) {
				h += '<li data-t="' + esc((p.title + ' ' + c.name).toLowerCase()) + '"><button type="button" class="me-dish' + (sel && sel.t === 'p' && sel.id === p.id ? ' is-sel' : '') + '" data-prod="' + p.id + '"><span class="me-dish-title">' + esc(p.title) + '</span><span class="me-dish-meta">' + dishBadges(p) + '<span class="me-price">' + priceOf(p) + '</span></span></button></li>';
			});
			if (!ps.length) { h += '<li class="me-empty">Noch keine Gerichte</li>'; }
			h += '</ul></section>';
		});
		return h;
	}
	function groupList() {
		var h = '<div class="me-tools"><input type="search" id="me-q" placeholder="Suchen ..." aria-label="Gruppen suchen" value="' + esc(query) + '"/><button type="button" class="button_dark" data-newgroup>Neue Gruppe</button></div><ul class="me-dishes me-groups">';
		D.groups.forEach(function (g) {
			h += '<li data-t="' + esc(g.title.toLowerCase()) + '"><button type="button" class="me-dish' + (sel && sel.t === 'g' && sel.id === g.id ? ' is-sel' : '') + '" data-group="' + g.id + '"><span class="me-dish-title">' + esc(g.title) + '</span><span class="me-dish-meta"><span class="me-tag">' + esc(rule(g)) + '</span><span class="me-tag">' + g.items.length + ' Optionen</span><span class="me-tag' + (g.used ? '' : ' is-warn') + '">' + (g.used ? 'bei ' + g.used + ' Gerichten' : 'ungenutzt') + '</span></span></button></li>';
		});
		if (!D.groups.length) { h += '<li class="me-empty">Noch keine Gruppen</li>'; }
		return h + '</ul>';
	}

	// ---- dish form
	function newDraftDish(catId) { return { id: 0, category_id: catId, title: '', description: '', image_url: '', price: 0, allergens: '', active: 1, variations: [], groups: [] }; }
	function rowTools() { return '<span class="me-arrows"><button type="button" class="me-mini" data-rowup aria-label="Nach oben">&uarr;</button><button type="button" class="me-mini" data-rowdown aria-label="Nach unten">&darr;</button><button type="button" class="me-mini me-x" data-rowrm aria-label="Entfernen">&times;</button></span>'; }
	function varHead() { return '<div class="me-row me-head"><span>Name</span><span>Preis €</span><span title="Faktor für die Preise des Zubehörs">Zubehör ×</span><span></span></div>'; }
	function varRow(v) {
		return '<div class="me-row me-var" data-id="' + (v.id || 0) + '"><input class="v-t" value="' + esc(v.title) + '" placeholder="z. B. Groß 32 cm" aria-label="Name der Variante"/><input class="v-p" inputmode="decimal" value="' + eur(v.price || 0) + '" aria-label="Preis in Euro"/>' +
			'<input class="v-m" inputmode="decimal" value="' + String(v.mult == null ? 1 : v.mult).replace('.', ',') + '" aria-label="Faktor für Zubehörpreise" title="Faktor für die Preise des Zubehörs, z. B. 1,3 bei der größeren Pizza"/>' + rowTools() + '</div>';
	}
	function dishGroupsHtml(d) {
		var h = d.groups.map(function (gid, i) {
			var g = byId(D.groups, gid); if (!g) { return ''; }
			return '<div class="me-assigned"><span class="me-assigned-t"><strong>' + esc(g.title) + '</strong><small>' + esc(rule(g)) + ' · ' + g.items.length + ' Optionen</small></span><span class="me-arrows"><button type="button" class="me-mini" data-gup="' + i + '" aria-label="Nach oben"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button><button type="button" class="me-mini" data-gdown="' + i + '" aria-label="Nach unten"' + (i === d.groups.length - 1 ? ' disabled' : '') + '>&darr;</button><button type="button" class="me-mini me-x" data-gdel="' + i + '" aria-label="Gruppe entfernen">&times;</button></span></div>';
		}).join('');
		var free = D.groups.filter(function (g) { return d.groups.indexOf(g.id) < 0; });
		return h + '<div class="me-addgroup"><select id="me-gsel" aria-label="Gruppe hinzufügen"><option value="">Zubehörgruppe hinzufügen ...</option>' + free.map(function (g) { return '<option value="' + g.id + '">' + esc(g.title) + ' (' + esc(rule(g)) + ')</option>'; }).join('') + '</select><button type="button" class="button_dark" data-gadd>Hinzufügen</button></div>';
	}
	function dishForm() {
		var d = sel.draft, isNew = !d.id;
		return '<form class="me-form" id="me-form" autocomplete="off"><h4>' + (isNew ? 'Neues Gericht' : 'Gericht bearbeiten') + '</h4>' +
			'<label class="me-l" for="me-title">Name</label><input id="me-title" maxlength="160" value="' + esc(d.title) + '" required/>' +
			'<div class="me-two"><div><label class="me-l" for="me-cat">Kategorie</label><select id="me-cat">' + D.categories.map(function (c) { return '<option value="' + c.id + '"' + (c.id === d.category_id ? ' selected' : '') + '>' + esc(c.name) + '</option>'; }).join('') + '</select></div>' +
			'<div><label class="me-l" for="me-price">Preis (€)</label><input id="me-price" inputmode="decimal" value="' + eur(d.price) + '"/><small class="me-help">Bei Varianten gelten deren Preise.</small></div></div>' +
			'<label class="me-l" for="me-desc">Beschreibung</label><textarea id="me-desc" maxlength="800" rows="3">' + esc(d.description) + '</textarea>' +
			'<label class="me-l" for="me-all">Allergene und Zusatzstoffe</label><input id="me-all" maxlength="400" value="' + esc(d.allergens) + '" placeholder="z. B. Weizen, Milch, Eier"/>' +
			'<span class="me-l">Bild</span><div id="me-imgwrap">' + imgBlock(d.image_url) + '</div>' +
			'<label class="offer-check"><input type="checkbox" id="me-active"' + (d.active ? ' checked' : '') + '/> Im Shop sichtbar</label>' +
			'<label class="me-l" for="me-conf">Wunschpizza-Konfigurator</label><select id="me-conf"><option value="0"' + (!d.configurator ? ' selected' : '') + '>Aus (normale Auswahl)</option><option value="1"' + (d.configurator === 1 ? ' selected' : '') + '>Pizza (rund)</option><option value="2"' + (d.configurator === 2 ? ' selected' : '') + '>Flammkuchen (oval, extra dünn, Holzbrett)</option></select>' +
			'<p class="me-help">Der Gast belegt einen rohen Teigling selbst: er tippt Zutaten an, sie verteilen sich gleichmäßig auf der Pizza. Die Zutaten sind die Optionen der Zubehörgruppen unten, ihre Preise gelten wie sonst auch. Beim Flammkuchen sind Tomatensoße und Käse inklusive.</p>' +
			'<h5 class="me-sub">Varianten <small>Größen oder Sorten mit eigenem Preis</small></h5>' +
			(d.variations.length ? '<p class="me-help">"Zubehör ×" ist ein Faktor für die Preise der Zubehörgruppen bei dieser Variante - bei 1,3 kostet ein Extra-Belag 30&nbsp;% mehr als am Grundpreis.</p>' : '') +
			'<div class="me-rows" id="me-vars">' + (d.variations.length ? varHead() : '') + d.variations.map(varRow).join('') + '</div>' +
			'<button type="button" class="me-mini me-add" data-addvar>+ Variante</button>' +
			'<h5 class="me-sub">Zubehörgruppen <small>Auswahl, die der Gast bei diesem Gericht bekommt</small></h5><div id="me-dgroups">' + dishGroupsHtml(d) + '</div>' +
			'<div class="me-actions"><button type="submit" class="button_dark">' + (isNew ? 'Gericht anlegen' : 'Speichern') + '</button>' +
			(isNew ? '' : '<button type="button" class="me-mini" data-prodmove="up">Nach oben</button><button type="button" class="me-mini" data-prodmove="down">Nach unten</button><button type="button" class="offer-delete" data-delprod>Gericht löschen</button>') + '</div></form>';
	}
	function readDish() {
		return { id: sel.draft.id || 0, title: $('#me-title').value, category_id: +$('#me-cat').value, price: $('#me-price').value, description: $('#me-desc').value, allergens: $('#me-all').value,
			image_url: $('#me-img').value, active: $('#me-active').checked ? 1 : 0, configurator: +$('#me-conf').value || 0, groups: sel.draft.groups.slice(),
			variations: $$('.me-var').map(function (r) { return { id: +r.dataset.id || 0, title: $('.v-t', r).value, price: $('.v-p', r).value, mult: $('.v-m', r).value }; }) };
	}

	// ---- category form
	function catForm() {
		var c = sel.draft, isNew = !c.id, n = D.products.filter(function (p) { return p.category_id === c.id; }).length;
		return '<form class="me-form" id="me-form" autocomplete="off"><h4>' + (isNew ? 'Neue Kategorie' : 'Kategorie bearbeiten') + '</h4>' +
			'<label class="me-l" for="me-cname">Name</label><input id="me-cname" maxlength="120" value="' + esc(c.name) + '" required/>' +
			'<label class="me-l" for="me-cdesc">Beschreibung (optional)</label><textarea id="me-cdesc" maxlength="500" rows="2">' + esc(c.description) + '</textarea>' +
			'<label class="offer-check"><input type="checkbox" id="me-cactive"' + (c.active ? ' checked' : '') + '/> Im Shop sichtbar</label>' +
			(isNew ? '<p class="me-help">Danach kannst du mit "+ Gericht" die ersten Gerichte anlegen.</p>' : '<p class="me-help">' + n + ' Gericht' + (n === 1 ? '' : 'e') + ' in dieser Kategorie.</p>') +
			'<div class="me-actions"><button type="submit" class="button_dark">' + (isNew ? 'Kategorie anlegen' : 'Speichern') + '</button>' + (isNew ? '' : '<button type="button" class="offer-delete" data-delcat>Kategorie löschen</button>') + '</div></form>';
	}

	// ---- group form
	// symbols of the pizza configurator (order/pizza.js draws them); "" = the name decides (shop_item_icon() on the server), "none" = not a topping
	var ICONS = { tomato: 'Tomate', spinach: 'Spinat', onion: 'Zwiebel', olive: 'Olive', pepperoni: 'Peperoni', pepper: 'Paprika', corn: 'Mais', broccoli: 'Brokkoli', artichoke: 'Artischocke', pineapple: 'Ananas',
		sundried: 'Getrocknete Tomate', arugula: 'Rucola', caper: 'Kapern', mushroom: 'Champignon', melt: 'Geschmolzener Käse', parmesan: 'Parmesan', gorgonzola: 'Gorgonzola', mozzarella: 'Mozzarella', feta: 'Schafskäse',
		ham: 'Schinken', salami: 'Salami', sucuk: 'Sucuk', chicken: 'Hähnchen', tuna: 'Thunfisch', shrimp: 'Garnele', nugget: 'Nugget', patty: 'Patty', sauce_hollandaise: 'Soße Hollandaise', sauce_sambal: 'Soße Sambal',
		sauce_creme: 'Soße Crème fraîche', sauce_bbq: 'Soße BBQ', sauce_korean: 'Soße Korean BBQ', sauce_garlic: 'Soße Knoblauch', sauce_curry: 'Soße Curry', sauce_other: 'Soße (andere)', dip: 'Dip (Beilage)' };
	function iconSelect(i) {
		var cur = i.icon || '', auto = i.auto ? 'Automatisch: ' + (ICONS[i.auto] || i.auto) : 'Automatisch (kein Belag)';
		return '<select class="i-i" aria-label="Symbol im Pizza-Konfigurator" title="Symbol im Pizza-Konfigurator"><option value=""' + (cur === '' ? ' selected' : '') + '>' + esc(auto) + '</option><option value="none"' + (cur === 'none' ? ' selected' : '') + '>Kein Belag</option>' +
			Object.keys(ICONS).map(function (k) { return '<option value="' + k + '"' + (cur === k ? ' selected' : '') + '>' + esc(ICONS[k]) + '</option>'; }).join('') + '</select>';
	}
	function itemRow(i) {
		return '<div class="me-row me-row5 me-item" data-id="' + (i.id || 0) + '"><input class="i-t" value="' + esc(i.title) + '" placeholder="Name der Option" aria-label="Name der Option"/><input class="i-p" inputmode="decimal" value="' + eur(i.price || 0) + '" aria-label="Aufpreis in Euro"/>' +
			'<input class="i-m" type="number" min="1" max="9" value="' + (i.max || 1) + '" aria-label="Höchstens wie oft wählbar" title="Höchstens wie oft wählbar"/>' + iconSelect(i) + rowTools() + '</div>';
	}
	function groupForm() {
		var g = sel.draft, isNew = !g.id, users = D.products.filter(function (p) { return p.groups.indexOf(g.id) >= 0; });
		return '<form class="me-form" id="me-form" autocomplete="off"><h4>' + (isNew ? 'Neue Zubehörgruppe' : 'Zubehörgruppe bearbeiten') + '</h4>' +
			'<label class="me-l" for="me-gtitle">Name der Gruppe</label><input id="me-gtitle" maxlength="120" value="' + esc(g.title) + '" required placeholder="z. B. Beilagen, Dips, Extra Zutaten"/>' +
			'<div class="me-two"><div><label class="me-l" for="me-gmin">Mindestens wählen</label><input id="me-gmin" type="number" min="0" max="20" value="' + g.min + '"/></div>' +
			'<div><label class="me-l" for="me-gmax">Höchstens wählen (0 = beliebig viele)</label><input id="me-gmax" type="number" min="0" max="50" value="' + g.max + '"/></div></div>' +
			'<p class="me-rule" id="me-rule"></p>' +
			'<h5 class="me-sub">Optionen</h5><p class="me-help">Symbol: Nur für den Pizza-Konfigurator. Bei „Automatisch“ erkennt das System die Zutat am Namen. <a href="handbuch_konfigurator.php#symbol" target="_blank" rel="noopener">Handbuch</a></p><div class="me-rows" id="me-items"><div class="me-row me-row5 me-head"><span>Name</span><span>Aufpreis €</span><span title="Wie oft wählbar">max.</span><span>Symbol</span><span></span></div>' + g.items.map(itemRow).join('') + '</div>' +
			'<div class="me-actions"><button type="button" class="me-mini me-add" data-additem>+ Option</button><button type="button" class="me-mini" data-bulk>Mehrere einfügen</button></div>' +
			'<div class="me-bulk" id="me-bulk" hidden><label class="me-l" for="me-bulk-t">Eine Option pro Zeile, Preis nach einem Semikolon (z. B. Ketchup; 0,50)</label><textarea id="me-bulk-t" rows="5"></textarea><button type="button" class="button_dark" data-bulkok>Übernehmen</button></div>' +
			(isNew ? '' : '<p class="me-help">Verwendet bei: ' + (users.length ? users.map(function (p) { return '<a href="#" data-goprod="' + p.id + '">' + esc(p.title) + '</a>'; }).join(', ') : 'noch keinem Gericht') + '</p>') +
			'<div class="me-actions"><button type="submit" class="button_dark">' + (isNew ? 'Gruppe anlegen' : 'Speichern') + '</button>' + (isNew ? '' : '<button type="button" class="me-mini" data-copygroup>Kopieren</button><button type="button" class="offer-delete" data-delgroup>Gruppe löschen</button>') + '</div></form>';
	}
	function readGroup() {
		return { id: sel.draft.id || 0, title: $('#me-gtitle').value, min: +$('#me-gmin').value || 0, max: +$('#me-gmax').value || 0,
			items: $$('.me-item').map(function (r) { return { id: +r.dataset.id || 0, title: $('.i-t', r).value, price: $('.i-p', r).value, max: +$('.i-m', r).value || 1, icon: $('.i-i', r).value }; }) };
	}
	function showRule() {
		var el = $('#me-rule'); if (!el) { return; }
		var g = { min: +$('#me-gmin').value || 0, max: +$('#me-gmax').value || 0 };
		el.textContent = 'Für den Gast: ' + rule(g) + (g.min > 0 ? ' (Pflichtangabe, ohne sie kann er nicht bestellen)' : '');
	}

	// ---- coupons
	var STATE = { on: ['aktiv', ''], soon: ['noch nicht gültig', ''], expired: ['abgelaufen', ' is-warn'], used: ['aufgebraucht', ' is-warn'], off: ['aus', ' is-warn'] };
	function couponRule(c) { return c.kind === 'percent' ? c.value + ' %' : money(c.value); }
	function couponList() {
		var h = '<div class="me-tools"><input type="search" id="me-q" placeholder="Suchen ..." aria-label="Gutscheine suchen" value="' + esc(query) + '"/><button type="button" class="button_dark" data-newcoupon>Neuer Gutschein</button></div><ul class="me-dishes me-groups">';
		D.coupons.forEach(function (c) {
			var st = STATE[c.state] || STATE.on, uses = c.max_uses ? c.used + ' von ' + c.max_uses + ' eingelöst' : c.used + '× eingelöst';
			h += '<li data-t="' + esc((c.code + ' ' + c.note).toLowerCase()) + '"><button type="button" class="me-dish' + (sel && sel.t === 'k' && sel.id === c.id ? ' is-sel' : '') + '" data-coupon="' + c.id + '"><span class="me-dish-title"><strong>' + esc(c.code) + '</strong> <span class="me-price">' + couponRule(c) + '</span></span><span class="me-dish-meta"><span class="me-tag' + st[1] + '">' + st[0] + '</span><span class="me-tag">' + uses + '</span>' + (c.valid_until ? '<span class="me-tag">bis ' + esc(c.valid_until.replace('T', ' ')) + '</span>' : '') + '</span></button></li>';
		});
		if (!D.coupons.length) { h += '<li class="me-empty">Noch keine Gutscheine</li>'; }
		return h + '</ul>';
	}
	function newCouponDraft() { return { id: 0, code: '', note: '', kind: 'percent', value: 10, max_discount: 0, min_order: 0, applies: 'all', valid_from: '', valid_until: '', max_uses: 0, per_guest: 0, active: 1, used: 0 }; }
	function couponForm() {
		var c = sel.draft, isNew = !c.id, usage = c.max_uses === 1 ? 'once' : (c.max_uses > 1 ? 'n' : 'unl');
		function opt(v, t, cur) { return '<option value="' + v + '"' + (cur === v ? ' selected' : '') + '>' + t + '</option>'; }
		return '<form class="me-form" id="me-form" autocomplete="off"><h4>' + (isNew ? 'Neuer Gutschein' : 'Gutschein bearbeiten') + '</h4>' +
			'<label class="me-l" for="mc-code">Code (den der Gast eingibt)</label><div class="me-addgroup"><input id="mc-code" maxlength="40" value="' + esc(c.code) + '" required placeholder="z. B. WILLKOMMEN10" style="text-transform:uppercase"/><button type="button" class="me-mini" data-gen>Erzeugen</button></div>' +
			'<label class="me-l" for="mc-note">Notiz (nur für euch)</label><input id="mc-note" maxlength="160" value="' + esc(c.note) + '" placeholder="z. B. Flyer Innenstadt, Oktober"/>' +
			'<h5 class="me-sub">Rabatt</h5>' +
			'<div class="me-two"><div><label class="me-l" for="mc-kind">Art des Rabatts</label><select id="mc-kind">' + opt('percent', 'Prozent vom Warenwert', c.kind) + opt('fixed', 'Betrag in Euro', c.kind) + '</select></div>' +
			'<div><label class="me-l" for="mc-value" id="mc-value-l">' + (c.kind === 'percent' ? 'Rabatt in %' : 'Rabatt in €') + '</label><input id="mc-value" inputmode="decimal" value="' + (c.kind === 'percent' ? c.value : eur(c.value)) + '"/></div></div>' +
			'<div class="me-two" id="mc-maxrow"' + (c.kind === 'percent' ? '' : ' hidden') + '><div><label class="me-l" for="mc-max">Höchstrabatt in € (leer = ohne)</label><input id="mc-max" inputmode="decimal" value="' + (c.max_discount ? eur(c.max_discount) : '') + '"/></div><div></div></div>' +
			'<div class="me-two"><div><label class="me-l" for="mc-min">Mindestwarenwert in € (leer = keiner)</label><input id="mc-min" inputmode="decimal" value="' + (c.min_order ? eur(c.min_order) : '') + '"/></div><div></div></div>' +
			'<h5 class="me-sub">Gültigkeit</h5>' +
			'<div class="me-two"><div><label class="me-l" for="mc-applies">Gilt für</label><select id="mc-applies">' + opt('all', 'Lieferung und Abholung', c.applies) + opt('delivery', 'nur Lieferung', c.applies) + opt('pickup', 'nur Abholung', c.applies) + '</select></div><div></div></div>' +
			'<div class="me-two"><div><label class="me-l" for="mc-from">Gültig ab (leer = sofort)</label><input id="mc-from" type="datetime-local" value="' + esc(c.valid_from) + '"/></div>' +
			'<div><label class="me-l" for="mc-until">Gültig bis (leer = unbegrenzt)</label><input id="mc-until" type="datetime-local" value="' + esc(c.valid_until) + '"/></div></div>' +
			'<h5 class="me-sub">Limits</h5>' +
			'<div class="me-two"><div><label class="me-l" for="mc-usage">Einlösungen</label><select id="mc-usage">' + opt('once', 'Einmalig (insgesamt nur eine Einlösung)', usage) + opt('n', 'Mehrmalig, höchstens ...', usage) + opt('unl', 'Mehrmalig, unbegrenzt', usage) + '</select></div>' +
			'<div id="mc-nrow"' + (usage === 'n' ? '' : ' hidden') + '><label class="me-l" for="mc-n">Höchstens so oft</label><input id="mc-n" type="number" min="2" max="1000000" value="' + (c.max_uses > 1 ? c.max_uses : 50) + '"/></div></div>' +
			'<label class="offer-check"><input type="checkbox" id="mc-guest"' + (c.per_guest ? ' checked' : '') + '/> Pro Gast nur einmal (erkannt an Telefonnummer oder E-Mail)</label>' +
			'<label class="offer-check"><input type="checkbox" id="mc-active"' + (c.active ? ' checked' : '') + '/> Aktiv</label>' +
			(isNew ? '' : '<p class="me-help">Bisher eingelöst: ' + c.used + (c.max_uses ? ' von ' + c.max_uses : '') + '. Eine stornierte oder nicht bezahlte Bestellung gibt die Einlösung wieder frei.</p>') +
			'<p class="me-rule" id="mc-sum"></p>' +
			'<div class="me-actions"><button type="submit" class="button_dark">' + (isNew ? 'Gutschein anlegen' : 'Speichern') + '</button>' + (isNew ? '' : '<button type="button" class="offer-delete" data-delcoupon>Gutschein löschen</button>') + '</div></form>';
	}
	function readCoupon() {
		var u = $('#mc-usage').value;
		return { id: sel.draft.id || 0, code: $('#mc-code').value, note: $('#mc-note').value, kind: $('#mc-kind').value, value: $('#mc-value').value, max_discount: $('#mc-max').value, min_order: $('#mc-min').value,
			applies: $('#mc-applies').value, valid_from: $('#mc-from').value, valid_until: $('#mc-until').value, max_uses: u === 'once' ? 1 : (u === 'n' ? (+$('#mc-n').value || 0) : 0),
			per_guest: $('#mc-guest').checked ? 1 : 0, active: $('#mc-active').checked ? 1 : 0 };
	}
	function couponSummary() {
		var el = $('#mc-sum'); if (!el) { return; }
		var c = readCoupon(), v = c.kind === 'percent' ? (c.value || 0) + ' %' : (c.value || 0) + ' €', parts = [v + ' Rabatt auf die Waren (nicht auf Liefergebühr und Trinkgeld)'];
		if (c.min_order) { parts.push('ab ' + c.min_order + ' € Warenwert'); }
		parts.push(c.max_uses === 1 ? 'einmalig' : (c.max_uses ? 'höchstens ' + c.max_uses + '× einlösbar' : 'beliebig oft einlösbar') + (c.per_guest ? ', pro Gast einmal' : ''));
		if (c.valid_until) { parts.push('bis ' + c.valid_until.replace('T', ' ') + ' Uhr'); }
		el.textContent = 'Für den Gast: ' + parts.join(', ') + '.';
	}

	// ---- render
	function render() {
		var editor = !sel ? '<div class="me-placeholder"><p>' + (view === 'dishes' ? 'Wähle links ein Gericht oder eine Kategorie zum Bearbeiten, oder lege etwas Neues an.' : view === 'groups' ? 'Wähle links eine Gruppe oder lege eine neue an.' : 'Wähle links einen Gutschein oder lege einen neuen an.') + '</p></div>' :
			(sel.t === 'p' ? dishForm() : sel.t === 'c' ? catForm() : sel.t === 'k' ? couponForm() : groupForm());
		root.innerHTML = '<div class="me-cols"><aside class="me-list" id="me-list">' + (view === 'dishes' ? dishList() : view === 'groups' ? groupList() : couponList()) + '</aside><section class="me-edit" id="me-edit" tabindex="-1">' + editor + '</section></div>';
		$$('[data-view]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false'); });
		showRule(); couponSummary(); filter(); dirty = false;
	}
	function filter() {
		var q = query.trim().toLowerCase();
		$$('#me-list li[data-t]').forEach(function (li) { li.hidden = q !== '' && li.dataset.t.indexOf(q) < 0; });
		$$('#me-list .me-cat').forEach(function (s) { var any = $$('li[data-t]', s).some(function (li) { return !li.hidden; }); s.hidden = q !== '' && !any; });
	}
	function select(t, id, draft) {
		discardOk().then(function (ok) {
			if (!ok) { return; }
			sel = { t: t, id: id, draft: draft }; render();
			var e = $('#me-edit'); if (e) { e.scrollIntoView({ block: 'nearest' }); var f = $('input,textarea', e); if (f && !id) { f.focus(); } }
		});
	}
	function afterSave(t, id, msg) {
		sel = { t: t, id: id, draft: copy(t === 'p' ? byId(D.products, id) : t === 'c' ? byId(D.categories, id) : t === 'k' ? byId(D.coupons, id) : byId(D.groups, id)) };
		render(); say(msg, false);
	}
	function armed(btn, text, then) {
		if (!btn.dataset.armed) { btn.dataset.armed = '1'; var old = btn.textContent; btn.textContent = text; setTimeout(function () { btn.dataset.armed = ''; btn.textContent = old; }, 4000); return; }
		btn.disabled = true; then();
	}
	function moveRow(row, up) {
		var sib = up ? row.previousElementSibling : row.nextElementSibling;
		if (!sib || sib.classList.contains('me-head')) { return; }
		if (up) { row.parentNode.insertBefore(row, sib); } else { row.parentNode.insertBefore(sib, row); }
		dirty = true;
	}
	function refreshDishGroups() { $('#me-dgroups').innerHTML = dishGroupsHtml(sel.draft); dirty = true; }

	// ---- events
	page.addEventListener('click', function (ev) {
		var t = ev.target, el;
		if ((el = t.closest('[data-view]'))) {
			if (view !== el.dataset.view) { var nextView = el.dataset.view; discardOk().then(function (ok) { if (!ok) { return; } view = nextView; sel = null; query = ''; render(); }); }
			return;
		}
		if (t.closest('[data-imgpick]')) { var fi = $('#me-file'); fi.value = ''; fi.click(); return; }
		if (t.closest('[data-imgrm]')) { refreshImg(''); dirty = true; return; }
		if ((el = t.closest('[data-imgfetch]'))) {
			el.disabled = true; say('Das Bild wird geholt ...', false);
			api('img_fetch', { url: $('#me-img').value }).then(function (r) { dirty = true; refreshImg(r.url); say('Das Bild liegt jetzt auf dem Server. Mit "Speichern" übernehmen.', false); }).catch(function () { el.disabled = false; });
			return;
		}
		if (t.closest('[data-imgall]')) { discardOk().then(function (ok) { if (ok) { localizeAll(); } }); return; }
		if ((el = t.closest('[data-prod]'))) { var p = byId(D.products, +el.dataset.prod); select('p', p.id, copy(p)); return; }
		if ((el = t.closest('[data-cat]'))) { var c = byId(D.categories, +el.dataset.cat); select('c', c.id, copy(c)); return; }
		if ((el = t.closest('[data-group]'))) { var g = byId(D.groups, +el.dataset.group); select('g', g.id, copy(g)); return; }
		if ((el = t.closest('[data-coupon]'))) { var cp = byId(D.coupons, +el.dataset.coupon); select('k', cp.id, copy(cp)); return; }
		if (t.closest('[data-newcoupon]')) { select('k', 0, newCouponDraft()); return; }
		if (t.closest('[data-gen]')) { var ab = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', cd = ''; for (var gi = 0; gi < 8; gi++) { cd += ab.charAt(Math.floor(Math.random() * ab.length)); } $('#mc-code').value = cd; dirty = true; couponSummary(); return; }
		if ((el = t.closest('[data-delcoupon]'))) {
			armed(el, 'Wirklich löschen?', function () { api('coupon_delete', { id: sel.id }).then(function () { var gone = sel.id; D.coupons = D.coupons.filter(function (c) { return c.id !== gone; }); sel = null; render(); say('Gutschein gelöscht.', false); }).catch(function () { el.disabled = false; }); }); return;
		}
		if ((el = t.closest('[data-goprod]'))) {
			ev.preventDefault();
			var goId = +el.dataset.goprod;
			discardOk().then(function (ok) { if (!ok) { return; } view = 'dishes'; var gp = byId(D.products, goId); sel = { t: 'p', id: gp.id, draft: copy(gp) }; render(); });
			return;
		}
		if (t.closest('[data-newcat]')) { select('c', 0, { id: 0, name: '', description: '', active: 1 }); return; }
		if ((el = t.closest('[data-newdish]'))) { select('p', 0, newDraftDish(+el.dataset.newdish)); return; }
		if (t.closest('[data-newgroup]')) { select('g', 0, { id: 0, title: '', min: 0, max: 0, items: [{ id: 0, title: '', price: 0, max: 1 }] }); return; }
		if ((el = t.closest('[data-catmove]'))) {
			var catMoveId = el.dataset.id, catMoveDir = el.dataset.catmove;
			discardOk().then(function (ok) { if (!ok) { return; } api('category_move', { id: catMoveId, dir: catMoveDir }).then(function (r) { apply(r); render(); }).catch(function () {}); });
			return;
		}
		if ((el = t.closest('[data-prodmove]'))) {
			var prodMoveDir = el.dataset.prodmove;
			discardOk().then(function (ok) { if (!ok) { return; } api('product_move', { id: sel.id, dir: prodMoveDir }).then(function (r) { apply(r); var keep = sel; render(); sel = keep; say('Verschoben.', false); }).catch(function () {}); });
			return;
		}
		if (t.closest('[data-addvar]')) {
			var box = $('#me-vars'); if (!$('.me-head', box)) { box.insertAdjacentHTML('afterbegin', varHead()); }
			box.insertAdjacentHTML('beforeend', varRow({ id: 0, title: '', price: 0, mult: 1 })); $('.me-var:last-child .v-t', box).focus(); dirty = true; return;
		}
		if (t.closest('[data-additem]')) { var ib = $('#me-items'); ib.insertAdjacentHTML('beforeend', itemRow({ id: 0, title: '', price: 0, max: 1 })); $('.me-item:last-child .i-t', ib).focus(); dirty = true; return; }
		if (t.closest('[data-rowup]')) { moveRow(t.closest('.me-row'), true); return; }
		if (t.closest('[data-rowdown]')) { moveRow(t.closest('.me-row'), false); return; }
		if (t.closest('[data-rowrm]')) {
			var row = t.closest('.me-row'), par = row.parentNode; row.remove();
			if (par.id === 'me-vars' && !$('.me-var', par)) { var hd = $('.me-head', par); if (hd) { hd.remove(); } }
			dirty = true; return;
		}
		if (t.closest('[data-bulk]')) { var bk = $('#me-bulk'); bk.hidden = !bk.hidden; if (!bk.hidden) { $('#me-bulk-t').focus(); } return; }
		if (t.closest('[data-bulkok]')) {
			var ib2 = $('#me-items');
			$('#me-bulk-t').value.split(/\n/).forEach(function (l) {
				l = l.trim(); if (!l) { return; }
				var parts = l.split(';'), pc = Math.round(parseFloat((parts[1] || '0').replace(',', '.')) * 100);
				ib2.insertAdjacentHTML('beforeend', itemRow({ id: 0, title: parts[0].trim(), price: isNaN(pc) ? 0 : pc, max: 1 }));
			});
			$('#me-bulk-t').value = ''; $('#me-bulk').hidden = true; dirty = true; return;
		}
		if (t.closest('[data-gadd]')) { var v = +$('#me-gsel').value; if (v) { sel.draft.groups.push(v); refreshDishGroups(); } return; }
		if ((el = t.closest('[data-gdel]'))) { sel.draft.groups.splice(+el.dataset.gdel, 1); refreshDishGroups(); return; }
		if ((el = t.closest('[data-gup]'))) { var i = +el.dataset.gup, a = sel.draft.groups; a.splice(i - 1, 0, a.splice(i, 1)[0]); refreshDishGroups(); return; }
		if ((el = t.closest('[data-gdown]'))) { var j = +el.dataset.gdown, b = sel.draft.groups; b.splice(j + 1, 0, b.splice(j, 1)[0]); refreshDishGroups(); return; }
		if ((el = t.closest('[data-delprod]'))) {
			armed(el, 'Wirklich löschen?', function () {
				api('product_delete', { id: sel.id }).then(function (r) { var gone = sel.id; D.products = D.products.filter(function (p) { return p.id !== gone; }); apply(r); sel = null; render(); say('Gericht gelöscht.', false); }).catch(function () { el.disabled = false; });
			}); return;
		}
		if ((el = t.closest('[data-delcat]'))) {
			var catDishCount = D.products.filter(function (p) { return p.category_id === sel.id; }).length;
			armed(el, catDishCount ? 'Löschen? ' + catDishCount + ' Gericht' + (catDishCount === 1 ? '' : 'e') + ' werden mitgelöscht' : 'Wirklich löschen?', function () {
				api('category_delete', { id: sel.id }).then(function () {
					var gone = sel.id; D.categories = D.categories.filter(function (c) { return c.id !== gone; }); D.products = D.products.filter(function (p) { return p.category_id !== gone; });
					sel = null; render(); say('Kategorie gelöscht.', false);
				}).catch(function () { el.disabled = false; });
			}); return;
		}
		if ((el = t.closest('[data-delgroup]'))) {
			var used = byId(D.groups, sel.id).used;
			armed(el, used ? 'Löschen? Wird bei ' + used + ' Gerichten entfernt' : 'Wirklich löschen?', function () {
				api('group_delete', { id: sel.id }).then(function (r) { apply(r); sel = null; render(); say('Gruppe gelöscht.', false); }).catch(function () { el.disabled = false; });
			}); return;
		}
		if (t.closest('[data-copygroup]')) {
			discardOk().then(function (ok) { if (!ok) { return; } api('group_copy', { id: sel.id }).then(function (r) { apply(r); afterSave('g', r.group.id, 'Kopie angelegt. Gib ihr einen eigenen Namen.'); }).catch(function () {}); });
		}
	});
	page.addEventListener('submit', function (ev) {
		if (ev.target.id === 'me-od-form') { return; } // let the <dialog method="dialog"> close itself natively
		ev.preventDefault();
		var btn = $('button[type=submit]', ev.target); btn.disabled = true;
		var done = function () { btn.disabled = false; };
		if (sel.t === 'p') {
			api('product_save', { data: readDish() }).then(function (r) {
				var i = D.products.map(function (p) { return p.id; }).indexOf(r.product.id); if (i < 0) { D.products.push(r.product); } else { D.products[i] = r.product; }
				apply({ groups: r.groups }); afterSave('p', r.product.id, 'Gespeichert.');
			}).catch(done);
		} else if (sel.t === 'k') {
			api('coupon_save', { data: readCoupon() }).then(function (r) {
				var i = D.coupons.map(function (c) { return c.id; }).indexOf(r.coupon.id); if (i < 0) { D.coupons.unshift(r.coupon); } else { D.coupons[i] = r.coupon; }
				afterSave('k', r.coupon.id, 'Gespeichert.');
			}).catch(done);
		} else if (sel.t === 'c') {
			api('category_save', { data: { id: sel.draft.id || 0, name: $('#me-cname').value, description: $('#me-cdesc').value, active: $('#me-cactive').checked ? 1 : 0 } }).then(function (r) {
				var i = D.categories.map(function (c) { return c.id; }).indexOf(r.category.id); if (i < 0) { D.categories.push(r.category); } else { D.categories[i] = r.category; }
				afterSave('c', r.category.id, 'Gespeichert.');
			}).catch(done);
		} else {
			api('group_save', { data: readGroup() }).then(function (r) { apply({ groups: r.groups }); afterSave('g', r.group.id, 'Gespeichert.'); }).catch(done);
		}
	});
	page.addEventListener('change', function (ev) {
		if (ev.target.id === 'me-img') { dirty = true; refreshImg(ev.target.value.trim()); return; }
		if (ev.target.id !== 'me-file' || !ev.target.files.length) { return; }
		var f = ev.target.files[0];
		if (f.size > 20 * 1024 * 1024) { say('Das Bild ist zu groß (höchstens 20 MB).', true); return; }
		say('Das Bild wird hochgeladen ...', false);
		api('img_upload', { file: f }).then(function (r) { dirty = true; refreshImg(r.url); say('Bild hochgeladen. Mit "Speichern" übernehmen.', false); }).catch(function () {});
	});
	page.addEventListener('input', function (ev) {
		if (ev.target.id === 'me-q') { query = ev.target.value; filter(); return; }
		if (ev.target.closest('#me-form')) { dirty = true; if (ev.target.id === 'me-gmin' || ev.target.id === 'me-gmax') { showRule(); } if (/^mc-/.test(ev.target.id)) { couponSummary(); } }
	});
	page.addEventListener('change', function (ev) {
		if (!ev.target.closest('#me-form')) { return; }
		dirty = true;
		if (ev.target.id === 'mc-kind') { var pc = ev.target.value === 'percent'; $('#mc-value-l').textContent = pc ? 'Rabatt in %' : 'Rabatt in €'; $('#mc-maxrow').hidden = !pc; $('#mc-value').value = pc ? '10' : '5,00'; }
		if (ev.target.id === 'mc-usage') { $('#mc-nrow').hidden = ev.target.value !== 'n'; }
		if (/^mc-/.test(ev.target.id)) { couponSummary(); }
	});
	window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });
	load();
})();
