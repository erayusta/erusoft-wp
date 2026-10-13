(function () {
	'use strict';
	var cfg = window.ERU_SPS;
	if (!cfg) { return; }

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text) { e.textContent = text; }
		return e;
	}

	function attach(input) {
		if (input.dataset.eruSps) { return; }
		input.dataset.eruSps = '1';
		input.setAttribute('autocomplete', 'off');
		var box = el('div', 'eru-sps-box');
		box.hidden = true;
		box.setAttribute('role', 'listbox');
		var host = input.closest('form') || input.parentNode;
		if (getComputedStyle(host).position === 'static') { host.style.position = 'relative'; }
		host.appendChild(box);
		var timer = null, ctrl = null, active = -1;

		function items() { return box.querySelectorAll('a.eru-sps-item'); }
		function close() { box.hidden = true; active = -1; }
		function mark(i) {
			var list = items();
			if (!list.length) { return; }
			active = (i + list.length) % list.length;
			list.forEach(function (a, n) { a.classList.toggle('is-active', n === active); });
			list[active].scrollIntoView({ block: 'nearest' });
		}

		function render(d) {
			box.textContent = '';
			var any = false;
			(d.products || []).forEach(function (p) {
				any = true;
				var a = el('a', 'eru-sps-item');
				a.href = p.url;
				a.setAttribute('role', 'option');
				if (p.image) { var im = el('img'); im.src = p.image; im.alt = ''; im.loading = 'lazy'; a.appendChild(im); }
				var t = el('span', 'eru-sps-text');
				t.appendChild(el('span', 'eru-sps-title', p.title));
				if (p.sku) { t.appendChild(el('span', 'eru-sps-sku', cfg.i18n.sku + ': ' + p.sku)); }
				a.appendChild(t);
				if (p.price) { var pr = el('span', 'eru-sps-price'); pr.innerHTML = p.price; a.appendChild(pr); }
				box.appendChild(a);
			});
			if ((d.categories || []).length) {
				any = true;
				box.appendChild(el('div', 'eru-sps-head', cfg.i18n.cats));
				d.categories.forEach(function (c) {
					var a = el('a', 'eru-sps-item eru-sps-cat', c.name + ' (' + c.count + ')');
					a.href = c.url;
					box.appendChild(a);
				});
			}
			if (!any) { box.appendChild(el('div', 'eru-sps-empty', cfg.i18n.none)); }
			else if (d.all_url) {
				var all = el('a', 'eru-sps-item eru-sps-all', cfg.i18n.all);
				all.href = d.all_url;
				box.appendChild(all);
			}
			box.hidden = false;
			active = -1;
		}

		function run() {
			var q = input.value.trim();
			if (q.length < cfg.min) { close(); return; }
			if (ctrl) { ctrl.abort(); }
			ctrl = window.AbortController ? new AbortController() : null;
			fetch(cfg.url + (cfg.url.indexOf('?') > -1 ? '&' : '?') + 'q=' + encodeURIComponent(q), ctrl ? { signal: ctrl.signal } : {})
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (d) { if (d && d.products) { render(d); } else { close(); } })
				.catch(function () {});
		}

		input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 220); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { close(); }
			else if (e.key === 'ArrowDown') { e.preventDefault(); mark(active + 1); }
			else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active - 1); }
			else if (e.key === 'Enter' && active > -1) { e.preventDefault(); items()[active].click(); }
		});
		document.addEventListener('click', function (e) { if (!host.contains(e.target)) { close(); } });
	}

	function init() {
		var sel = cfg.selector ? cfg.selector + ', .eru-sps-input' : '.eru-sps-input';
		try { document.querySelectorAll(sel).forEach(attach); } catch (e) { document.querySelectorAll('.eru-sps-input').forEach(attach); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
