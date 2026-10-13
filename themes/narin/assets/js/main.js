/**
 * Narin theme: header, drawer and search panel, keyboard-friendly sub-menus, shop filter drawer,
 * quantity steppers, mobile buy bars and the "recently viewed" strip.
 */
(function () {
	'use strict';

	var cfg = window.narinTheme || {};
	var i18n = cfg.i18n || {};
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

	/**
	 * A modal panel (drawer, filter drawer): opens with a backdrop, traps focus, closes on Escape,
	 * backdrop click or the close button, and returns focus to the toggle.
	 */
	function modalPanel(panel, toggles, backdrop, closeBtn, opts) {
		if (!panel) { return null; }
		opts = opts || {};
		var lastToggle = null;
		var timer = null;

		function isOpen() { return panel.classList.contains('is-open'); }

		function open(trigger) {
			lastToggle = trigger || toggles[0] || null;
			clearTimeout(timer);
			panel.hidden = false;
			if (backdrop) { backdrop.hidden = false; }
			if (opts.useHidden === false) { panel.removeAttribute('hidden'); }
			requestAnimationFrame(function () {
				panel.classList.add('is-open');
				if (backdrop) { backdrop.classList.add('is-on'); }
			});
			document.body.classList.add('narin-locked');
			toggles.forEach(function (t) { t.setAttribute('aria-expanded', 'true'); });
			var first = closeBtn || panel.querySelector(FOCUSABLE);
			setTimeout(function () { if (first) { first.focus(); } }, 30);
		}

		function close(returnFocus) {
			if (!isOpen()) { return; }
			panel.classList.remove('is-open');
			if (backdrop) { backdrop.classList.remove('is-on'); }
			document.body.classList.remove('narin-locked');
			toggles.forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
			timer = setTimeout(function () {
				if (opts.keepInFlow) { return; }
				panel.hidden = true;
				if (backdrop) { backdrop.hidden = true; }
			}, 260);
			if (opts.keepInFlow && backdrop) { setTimeout(function () { backdrop.hidden = true; }, 260); }
			if (returnFocus !== false && lastToggle) { lastToggle.focus(); }
		}

		toggles.forEach(function (t) {
			t.addEventListener('click', function () { if (isOpen()) { close(); } else { open(t); } });
		});
		if (closeBtn) { closeBtn.addEventListener('click', function () { close(); }); }
		if (backdrop) { backdrop.addEventListener('click', function () { close(); }); }

		panel.addEventListener('keydown', function (e) {
			if (!isOpen()) { return; }
			if (e.key === 'Escape') { e.preventDefault(); close(); return; }
			if (e.key !== 'Tab') { return; }
			var items = Array.prototype.filter.call(panel.querySelectorAll(FOCUSABLE), visible);
			if (!items.length) { return; }
			var first = items[0];
			var last = items[items.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		});

		return { open: open, close: close, isOpen: isOpen };
	}

	/* ---- Mobile drawer ---- */
	var drawer = document.getElementById('narin-drawer');
	var drawerApi = modalPanel(
		drawer,
		Array.prototype.slice.call(document.querySelectorAll('.menu-toggle')),
		document.querySelector('.drawer-backdrop'),
		drawer ? drawer.querySelector('.drawer-close') : null
	);
	if (drawer && drawerApi) {
		drawer.querySelectorAll('a[href*="#"]').forEach(function (a) {
			a.addEventListener('click', function () { drawerApi.close(false); });
		});
		window.addEventListener('resize', function () {
			if (window.matchMedia('(min-width: 1024px)').matches && drawerApi.isOpen()) { drawerApi.close(false); }
		});
	}

	/* ---- Shop filter drawer (a sidebar on wide screens, a drawer below 1024 px) ---- */
	var filters = document.getElementById('narin-filters');
	if (filters) {
		var filterApi = modalPanel(
			filters,
			Array.prototype.slice.call(document.querySelectorAll('.filter-toggle')),
			document.querySelector('.filter-backdrop'),
			filters.querySelector('.filter-close'),
			{ keepInFlow: true }
		);
		window.addEventListener('resize', function () {
			if (window.matchMedia('(min-width: 1024px)').matches && filterApi.isOpen()) { filterApi.close(false); }
		});
	}

	/* ---- Search panel ---- */
	var search = document.getElementById('narin-search');
	var searchToggle = document.querySelector('.search-toggle');
	if (search && searchToggle) {
		var setSearch = function (open, returnFocus) {
			search.hidden = !open;
			searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			if (open) {
				var input = search.querySelector('input[type="search"]');
				if (input) { input.focus(); }
			} else if (returnFocus) {
				searchToggle.focus();
			}
		};
		searchToggle.addEventListener('click', function () { setSearch(search.hidden, false); });
		var searchClose = search.querySelector('.search-close');
		if (searchClose) { searchClose.addEventListener('click', function () { setSearch(false, true); }); }
		search.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setSearch(false, true); } });
	}

	/* ---- Header shadow ---- */
	var header = document.getElementById('site-header');
	if (header) {
		var onScroll = function () { header.classList.toggle('is-scrolled', window.scrollY > 8); };
		onScroll();
		window.addEventListener('scroll', onScroll, { passive: true });
	}

	/* ---- Sub-menus: disclosure buttons for keyboard and touch (hover still works on desktop) ---- */
	document.querySelectorAll('.main-navigation, .drawer__nav').forEach(function (nav, navIndex) {
		nav.querySelectorAll('.menu-item-has-children').forEach(function (li, n) {
			var link = li.querySelector(':scope > a');
			var sub = li.querySelector(':scope > ul');
			if (!link || !sub) { return; }
			if (!sub.id) { sub.id = 'narin-sub-' + navIndex + '-' + n; }
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'submenu-toggle';
			btn.setAttribute('aria-expanded', 'false');
			btn.setAttribute('aria-controls', sub.id);
			btn.setAttribute('aria-label', (i18n.openSubmenu || '') + ': ' + link.textContent.trim());
			btn.innerHTML = '<svg class="narin-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="6 9 12 15 18 9"/></svg>';
			link.insertAdjacentElement('afterend', btn);
			btn.addEventListener('click', function () {
				var open = !li.classList.contains('is-open');
				nav.querySelectorAll('li.is-open').forEach(function (other) {
					if (other !== li && !other.contains(li)) {
						other.classList.remove('is-open');
						var b = other.querySelector(':scope > .submenu-toggle');
						if (b) { b.setAttribute('aria-expanded', 'false'); }
					}
				});
				li.classList.toggle('is-open', open);
				li.classList.remove('is-closed');
				btn.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			li.addEventListener('focusout', function (e) {
				if (nav.classList.contains('drawer__nav')) { return; }
				if (e.relatedTarget && li.contains(e.relatedTarget)) { return; }
				li.classList.remove('is-open');
				btn.setAttribute('aria-expanded', 'false');
			});
			li.addEventListener('mouseleave', function () { li.classList.remove('is-closed'); });
		});
		if (nav.classList.contains('main-navigation')) {
			nav.addEventListener('keydown', function (e) {
				if (e.key !== 'Escape') { return; }
				var li = document.activeElement && document.activeElement.closest ? document.activeElement.closest('li.is-open, li.menu-item-has-children') : null;
				if (!li) { return; }
				var b = li.querySelector(':scope > .submenu-toggle');
				li.classList.remove('is-open');
				li.classList.add('is-closed');
				if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
			});
			document.addEventListener('click', function (e) {
				if (nav.contains(e.target)) { return; }
				nav.querySelectorAll('li.is-open').forEach(function (li) {
					li.classList.remove('is-open');
					var b = li.querySelector(':scope > .submenu-toggle');
					if (b) { b.setAttribute('aria-expanded', 'false'); }
				});
			});
		}
	});

	/* ---- Quantity steppers around WooCommerce's number inputs ---- */
	function enhanceQuantities(scope) {
		(scope || document).querySelectorAll('.quantity input.qty[type="number"]').forEach(function (input) {
			if (input.dataset.narinStepper) { return; }
			input.dataset.narinStepper = '1';
			var wrap = input.closest('.quantity');
			wrap.classList.add('narin-stepper');
			function make(dir, label, text) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'narin-stepper__btn';
				b.setAttribute('aria-label', label);
				b.textContent = text;
				b.addEventListener('click', function () {
					var step = parseFloat(input.step) || 1;
					var min = input.min !== '' ? parseFloat(input.min) : 0;
					var max = input.max !== '' ? parseFloat(input.max) : Infinity;
					var val = parseFloat(input.value) || 0;
					var next = Math.min(max, Math.max(min, val + dir * step));
					if (next === val) { return; }
					input.value = next;
					input.dispatchEvent(new Event('input', { bubbles: true }));
					input.dispatchEvent(new Event('change', { bubbles: true }));
					if (window.jQuery) { window.jQuery(input).trigger('change'); }
				});
				return b;
			}
			input.insertAdjacentElement('beforebegin', make(-1, i18n.decrease || '-', '−'));
			input.insertAdjacentElement('afterend', make(1, i18n.increase || '+', '+'));
		});
	}
	enhanceQuantities();

	/* Cart: submit the update shortly after a stepper change (the update button stays for keyboard users). */
	var cartTimer = null;
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.woocommerce-cart-form .narin-stepper__btn') : null;
		if (!btn) { return; }
		clearTimeout(cartTimer);
		cartTimer = setTimeout(function () {
			var update = document.querySelector('.woocommerce-cart-form [name="update_cart"]');
			if (update) { update.disabled = false; update.click(); }
		}, 700);
	});

	/* Totals in the mobile bars follow WooCommerce's AJAX updates. */
	function syncTotals() {
		var src = document.querySelector('.cart_totals .order-total .amount, #order_review .order-total .amount');
		document.querySelectorAll('[data-narin-total]').forEach(function (el) {
			if (src) { el.textContent = src.textContent; }
		});
		enhanceQuantities();
	}
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_cart_totals updated_wc_div updated_checkout', syncTotals);
	}

	/* Checkout mobile bar: proxies the real place-order button so validation and payment JS run as usual. */
	document.querySelectorAll('[data-narin-place-order]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			var real = document.getElementById('place_order');
			if (real) { real.click(); }
		});
	});

	/* ---- Product page: mobile buy bar ---- */
	var buyBar = document.querySelector('[data-narin-buybar]');
	var cartForm = document.querySelector('.single-product form.cart');
	if (buyBar && cartForm && 'IntersectionObserver' in window) {
		var submit = cartForm.querySelector('[type="submit"]');
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var past = !entry.isIntersecting && entry.boundingClientRect.top < 0;
				buyBar.hidden = !past;
				document.body.classList.toggle('has-mobile-bar', past);
			});
		});
		io.observe(cartForm);
		buyBar.querySelector('[data-narin-buy]').addEventListener('click', function () {
			if (submit && !submit.disabled && !submit.classList.contains('disabled') && cartForm.classList.contains('cart') && !cartForm.classList.contains('variations_form')) {
				submit.click();
				return;
			}
			cartForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
			var field = cartForm.querySelector('select, input:not([type="hidden"])');
			if (field) { setTimeout(function () { field.focus({ preventScroll: true }); }, 400); }
		});
	}

	/* ---- Recently viewed (stored only in this browser) ---- */
	var recent = document.querySelector('[data-narin-recent]');
	if (recent) {
		var KEY = cfg.recentKey || 'narin_recent';
		var LIMIT = cfg.recentLimit || 8;
		var current = null;
		try { current = JSON.parse(recent.getAttribute('data-narin-recent')); } catch (e) { current = null; }
		var list = [];
		try { list = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { list = []; }
		if (!Array.isArray(list)) { list = []; }
		list = list.filter(function (x) {
			return x && typeof x.id === 'number' && typeof x.url === 'string' && /^https?:\/\//.test(x.url) && x.url.indexOf(location.origin) === 0;
		});
		var others = list.filter(function (x) { return !current || x.id !== current.id; }).slice(0, 4);
		if (current && current.id) {
			list = [current].concat(list.filter(function (x) { return x.id !== current.id; })).slice(0, LIMIT);
			try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) {}
		}
		if (others.length) {
			var ul = recent.querySelector('.narin-recent__list');
			others.forEach(function (item) {
				var li = document.createElement('li');
				var a = document.createElement('a');
				a.href = item.url;
				a.className = 'narin-recent__item';
				if (item.image && /^https?:\/\//.test(item.image)) {
					var img = document.createElement('img');
					img.src = item.image;
					img.alt = '';
					img.loading = 'lazy';
					a.appendChild(img);
				}
				var title = document.createElement('span');
				title.className = 'narin-recent__name';
				title.textContent = String(item.title || '');
				a.appendChild(title);
				if (item.price) {
					var price = document.createElement('span');
					price.className = 'narin-recent__price';
					price.textContent = String(item.price);
					a.appendChild(price);
				}
				li.appendChild(a);
				ul.appendChild(li);
			});
			recent.hidden = false;
		}
	}

	/*
	 * Cart page: the cart fragments script is not loaded there, so keep the header count in step after the
	 * cart form updates itself (quantity change, item removed).
	 */
	if (window.jQuery && document.querySelector('.woocommerce-cart-form')) {
		window.jQuery(document.body).on('updated_wc_div', function () {
			var total = 0;
			Array.prototype.forEach.call(document.querySelectorAll('.woocommerce-cart-form input[name$="[qty]"]'), function (input) {
				total += Math.max(0, parseInt(input.value, 10) || 0);
			});
			Array.prototype.forEach.call(document.querySelectorAll('.cart-link'), function (link) {
				var badge = link.querySelector('.narin-cart-count');
				var sr = link.querySelector('.screen-reader-text');
				if (badge) {
					badge.textContent = String(total);
				}
				if (sr) { sr.textContent = sr.textContent.replace(/\d+/, String(total)); }
			});
		});
	}
})();
