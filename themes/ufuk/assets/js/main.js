/**
 * Ufuk theme: colour mode, sticky header, keyboard-friendly menus, reveal-on-scroll, contact form.
 */
(function () {
	'use strict';

	var cfg = window.ufukTheme || { i18n: {} };
	var i18n = cfg.i18n || {};
	var root = document.documentElement;

	/* ---- Colour mode ---- */
	var toggle = document.getElementById('theme-toggle');
	var KEY = cfg.storageKey || 'ufuk_theme';
	function isDark() {
		var current = root.getAttribute('data-theme');
		if (current) { return current === 'dark'; }
		return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
	}
	if (toggle) {
		toggle.setAttribute('aria-pressed', isDark() ? 'true' : 'false');
		toggle.addEventListener('click', function () {
			var next = isDark() ? 'light' : 'dark';
			root.setAttribute('data-theme', next);
			toggle.setAttribute('aria-pressed', next === 'dark' ? 'true' : 'false');
			try { localStorage.setItem(KEY, next); } catch (e) {}
		});
	}

	/* ---- Sticky header shadow ---- */
	var header = document.getElementById('site-header');
	if (header) {
		var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 12); };
		onScroll();
		window.addEventListener('scroll', onScroll, { passive: true });
	}

	/* ---- Primary navigation ---- */
	var nav = document.getElementById('primary-navigation');
	var navToggle = document.getElementById('nav-toggle');

	function closeSubmenus(except) {
		if (!nav) { return; }
		nav.querySelectorAll('li.is-open').forEach(function (li) {
			if (except && (li === except || li.contains(except))) { return; }
			li.classList.remove('is-open');
			var b = li.querySelector(':scope > .submenu-toggle');
			if (b) { b.setAttribute('aria-expanded', 'false'); }
		});
	}

	function setMenu(open, returnFocus) {
		if (!nav || !navToggle) { return; }
		nav.classList.toggle('is-open', open);
		navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (!open) {
			closeSubmenus();
			if (returnFocus) { navToggle.focus(); }
		}
	}

	if (nav) {
		// Disclosure buttons for sub-menus (keyboard and touch); hover still opens them on desktop.
		nav.querySelectorAll('.menu-item-has-children, .page_item_has_children').forEach(function (li, n) {
			var link = li.querySelector(':scope > a');
			var sub = li.querySelector(':scope > ul');
			if (!link || !sub) { return; }
			if (!sub.id) { sub.id = 'ufuk-submenu-' + n; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'submenu-toggle';
			btn.setAttribute('aria-expanded', 'false');
			btn.setAttribute('aria-controls', sub.id);
			btn.setAttribute('aria-label', (i18n.openSubmenu || 'Alt menü') + ': ' + link.textContent.trim());
			btn.innerHTML = '<svg class="ufuk-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>';
			link.insertAdjacentElement('afterend', btn);
			btn.addEventListener('click', function () {
				var open = !li.classList.contains('is-open');
				closeSubmenus(li);
				li.classList.toggle('is-open', open);
				li.classList.remove('is-closed');
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			// Leaving the item with Tab closes its sub-menu.
			li.addEventListener('focusout', function (e) {
				if (e.relatedTarget && li.contains(e.relatedTarget)) { return; }
				if (li.classList.contains('is-open') && window.matchMedia('(min-width: 960px)').matches) {
					li.classList.remove('is-open');
					btn.setAttribute('aria-expanded', 'false');
				}
			});
			li.addEventListener('mouseleave', function () { li.classList.remove('is-closed'); });
		});

		document.addEventListener('keydown', function (e) {
			if (e.key !== 'Escape') { return; }
			var openItem = document.activeElement && document.activeElement.closest ? document.activeElement.closest('#primary-navigation li.is-open, #primary-navigation li:hover') : null;
			var current = nav.querySelector('li.is-open');
			if (openItem || current) {
				var li = openItem || current;
				var btn = li.querySelector(':scope > .submenu-toggle');
				li.classList.remove('is-open');
				li.classList.add('is-closed'); // hide a hovered sub-menu too, until the pointer leaves
				if (btn) { btn.setAttribute('aria-expanded', 'false'); btn.focus(); }
				return;
			}
			if (nav.classList.contains('is-open')) { setMenu(false, true); }
		});

		document.addEventListener('click', function (e) {
			if (!nav.contains(e.target) && (!navToggle || !navToggle.contains(e.target))) {
				closeSubmenus();
				if (nav.classList.contains('is-open')) { setMenu(false, false); }
			}
		});
	}

	if (navToggle && nav) {
		navToggle.addEventListener('click', function () {
			var open = !nav.classList.contains('is-open');
			setMenu(open, false);
			if (open) {
				var first = nav.querySelector('a, button');
				if (first) { first.focus(); }
			}
		});
		// Close the panel when a same-page anchor is followed.
		nav.querySelectorAll('a[href*="#"]').forEach(function (a) {
			a.addEventListener('click', function () { setMenu(false, false); });
		});
		window.addEventListener('resize', function () {
			if (window.matchMedia('(min-width: 960px)').matches && nav.classList.contains('is-open')) { setMenu(false, false); }
		});
	}

	/* ---- Reveal on scroll ---- */
	var revealEls = document.querySelectorAll('.reveal');
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	revealEls.forEach(function (el) {
		var delay = el.getAttribute('data-reveal-delay');
		if (delay) { el.style.setProperty('--reveal-delay', parseInt(delay, 10) + 'ms'); }
	});
	function revealAll() { revealEls.forEach(function (el) { el.classList.add('is-visible'); }); }
	if (!reduce && 'IntersectionObserver' in window && revealEls.length) {
		// Whatever is already on screen is shown at once (no fade), so nothing above the fold waits for the observer.
		var vh = window.innerHeight || document.documentElement.clientHeight;
		var pending = [];
		revealEls.forEach(function (el) {
			if (el.getBoundingClientRect().top < vh) { el.classList.add('is-visible', 'is-instant'); } else { pending.push(el); }
		});
		var fired = false;
		var observer = new IntersectionObserver(function (entries) {
			fired = true;
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('is-visible');
					observer.unobserve(entry.target);
				}
			});
		}, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
		pending.forEach(function (el) { observer.observe(el); });
		// Only now may CSS hide the not-yet-revealed elements; without this class everything stays visible.
		root.classList.add('ufuk-reveal-ready');
		// Safety nets: never leave content hidden if the observer does not report, or when printing.
		if (pending.length) { setTimeout(function () { if (!fired) { revealAll(); } }, 2000); }
		window.addEventListener('beforeprint', revealAll);
	} else {
		revealAll();
	}

	/* ---- Contact form (AJAX; posts normally without JS) ---- */
	document.querySelectorAll('form[data-ufuk-contact]').forEach(function (form) {
		form.addEventListener('submit', function (e) {
			if (!window.fetch || !window.FormData || !cfg.ajaxUrl) { return; }
			if (typeof form.reportValidity === 'function' && !form.reportValidity()) { e.preventDefault(); return; }
			e.preventDefault();
			var status = form.querySelector('.form-note');
			var submit = form.querySelector('button[type="submit"]');
			var data = new FormData(form);
			if (submit) { submit.disabled = true; }
			if (status) { status.className = 'form-note'; status.textContent = i18n.sending || ''; }
			fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function (res) { return res.json(); })
				.then(function (json) {
					var ok = !!(json && json.success);
					if (status) {
						status.className = 'form-note ' + (ok ? 'is-success' : 'is-error');
						status.textContent = (json && json.data && json.data.message) || i18n.error || '';
					}
					if (ok) { form.reset(); }
				})
				.catch(function () {
					if (status) { status.className = 'form-note is-error'; status.textContent = i18n.error || ''; }
				})
				.then(function () { if (submit) { submit.disabled = false; } });
		});
	});
})();
