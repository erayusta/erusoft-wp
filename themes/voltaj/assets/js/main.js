/**
 * Voltaj theme: catalogue panel (dropdown on wide screens, drawer below 1024 px), shop filter drawer,
 * quote/contact forms, copy buttons, recently viewed (localStorage), mobile buy bar and the add-to-cart toast.
 * No dependencies; jQuery is only listened to when WooCommerce loads it.
 */
(function () {
	'use strict';

	var cfg = window.voltajTheme || {};
	var i18n = cfg.i18n || {};
	var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
	var wide = window.matchMedia('(min-width: 1024px)');

	function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function visible(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }

	function trapTab(container, e) {
		var items = $all(FOCUSABLE, container).filter(visible);
		if (!items.length) { return; }
		var first = items[0];
		var last = items[items.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	}

	/* ------------------------------------------------------------------
	 * Catalogue panel
	 * ---------------------------------------------------------------- */
	var catalog = document.querySelector('[data-vj-catalog]');
	var catToggle = document.querySelector('[data-vj-catalog-toggle]');
	var catBackdrop = document.querySelector('[data-vj-catalog-backdrop]');
	var catClose = document.querySelector('[data-vj-catalog-close]');

	if (catalog && catToggle) {
		var hideTimer = null;
		var hoverTimer = null;
		var roots = function () { return $all('.vj-cat-root__btn, .vj-cat-root__link', catalog); };
		var rootButtons = function () { return $all('.vj-cat-root__btn', catalog); };

		var isOpen = function () { return catToggle.getAttribute('aria-expanded') === 'true'; };

		var setRoot = function (btn, open) {
			if (!btn) { return; }
			if (wide.matches && open) {
				rootButtons().forEach(function (b) { if (b !== btn) { b.setAttribute('aria-expanded', 'false'); } });
			}
			btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		};

		var openCatalog = function () {
			clearTimeout(hideTimer);
			catalog.hidden = false;
			catalog.setAttribute('aria-modal', wide.matches ? 'false' : 'true');
			if (catBackdrop) { catBackdrop.hidden = false; }
			catToggle.setAttribute('aria-expanded', 'true');
			if (wide.matches) {
				var active = catalog.querySelector('.vj-cat-root__btn[aria-expanded="true"]');
				if (!active) { setRoot(rootButtons()[0], true); }
			} else {
				document.body.classList.add('vj-locked');
			}
			requestAnimationFrame(function () {
				catalog.classList.add('is-open');
				if (catBackdrop) { catBackdrop.classList.add('is-on'); }
			});
			var first = wide.matches ? roots()[0] : catClose;
			setTimeout(function () { if (first) { first.focus(); } }, 40);
		};

		var closeCatalog = function (returnFocus) {
			if (!isOpen()) { return; }
			catToggle.setAttribute('aria-expanded', 'false');
			catalog.classList.remove('is-open');
			if (catBackdrop) { catBackdrop.classList.remove('is-on'); }
			document.body.classList.remove('vj-locked');
			hideTimer = setTimeout(function () {
				catalog.hidden = true;
				if (catBackdrop) { catBackdrop.hidden = true; }
			}, wide.matches ? 0 : 260);
			if (returnFocus !== false) { catToggle.focus(); }
		};

		catToggle.addEventListener('click', function () { if (isOpen()) { closeCatalog(); } else { openCatalog(); } });
		if (catClose) { catClose.addEventListener('click', function () { closeCatalog(); }); }
		if (catBackdrop) { catBackdrop.addEventListener('click', function () { closeCatalog(); }); }

		rootButtons().forEach(function (btn) {
			btn.addEventListener('click', function () {
				var open = btn.getAttribute('aria-expanded') === 'true';
				// On wide screens a root stays selected (one pane is always shown); on small screens it toggles.
				setRoot(btn, wide.matches ? true : !open);
			});
			btn.addEventListener('mouseenter', function () {
				if (!wide.matches) { return; }
				clearTimeout(hoverTimer);
				hoverTimer = setTimeout(function () { setRoot(btn, true); }, 90);
			});
			btn.addEventListener('mouseleave', function () { clearTimeout(hoverTimer); });
		});

		catalog.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { e.preventDefault(); closeCatalog(); return; }
			if (e.key === 'Tab' && !wide.matches) { trapTab(catalog, e); return; }
			var list = roots();
			var idx = list.indexOf(document.activeElement);
			if (idx > -1) {
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					var next = list[(idx + (e.key === 'ArrowDown' ? 1 : -1) + list.length) % list.length];
					next.focus();
					if (wide.matches && next.classList.contains('vj-cat-root__btn')) { setRoot(next, true); }
				} else if (e.key === 'Home' || e.key === 'End') {
					e.preventDefault();
					list[e.key === 'Home' ? 0 : list.length - 1].focus();
				} else if (e.key === 'ArrowRight' && document.activeElement.classList.contains('vj-cat-root__btn')) {
					e.preventDefault();
					setRoot(document.activeElement, true);
					var pane = document.getElementById(document.activeElement.getAttribute('aria-controls'));
					var link = pane ? pane.querySelector('a') : null;
					if (link) { link.focus(); }
				}
			} else if (e.key === 'ArrowLeft' && e.target.closest && e.target.closest('.vj-cat-pane')) {
				e.preventDefault();
				var owner = e.target.closest('.vj-cat-root');
				var ownerBtn = owner ? owner.querySelector('.vj-cat-root__btn') : null;
				if (ownerBtn) { ownerBtn.focus(); }
			}
		});

		// Wide screens: close when focus or a click leaves the header.
		var header = document.getElementById('site-header');
		document.addEventListener('click', function (e) {
			if (isOpen() && wide.matches && header && !header.contains(e.target)) { closeCatalog(false); }
		});
		if (header) {
			header.addEventListener('focusout', function (e) {
				if (isOpen() && wide.matches && e.relatedTarget && !header.contains(e.relatedTarget)) { closeCatalog(false); }
			});
		}
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && isOpen() && !catalog.contains(document.activeElement)) { closeCatalog(); }
		});
		var onChange = function () { if (isOpen()) { closeCatalog(false); } };
		if (wide.addEventListener) { wide.addEventListener('change', onChange); } else if (wide.addListener) { wide.addListener(onChange); }
	}

	/* ------------------------------------------------------------------
	 * Shop filter drawer (a column on wide screens)
	 * ---------------------------------------------------------------- */
	var filters = document.querySelector('[data-vj-filters]');
	var filterToggles = $all('[data-vj-filters-toggle]');
	var filterBackdrop = document.querySelector('[data-vj-filters-backdrop]');
	var filterClose = document.querySelector('[data-vj-filters-close]');
	if (filters && filterToggles.length) {
		var lastFilterToggle = null;
		var filtersOpen = function () { return filters.classList.contains('is-open'); };
		var openFilters = function (trigger) {
			lastFilterToggle = trigger;
			if (filterBackdrop) { filterBackdrop.hidden = false; }
			filters.setAttribute('role', 'dialog');
			filters.setAttribute('aria-modal', 'true');
			requestAnimationFrame(function () {
				filters.classList.add('is-open');
				if (filterBackdrop) { filterBackdrop.classList.add('is-on'); }
			});
			document.body.classList.add('vj-locked');
			filterToggles.forEach(function (t) { t.setAttribute('aria-expanded', 'true'); });
			setTimeout(function () { if (filterClose) { filterClose.focus(); } }, 40);
		};
		var closeFilters = function (returnFocus) {
			if (!filtersOpen()) { return; }
			filters.classList.remove('is-open');
			filters.removeAttribute('role');
			filters.removeAttribute('aria-modal');
			if (filterBackdrop) { filterBackdrop.classList.remove('is-on'); setTimeout(function () { filterBackdrop.hidden = true; }, 260); }
			document.body.classList.remove('vj-locked');
			filterToggles.forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
			if (returnFocus !== false && lastFilterToggle) { lastFilterToggle.focus(); }
		};
		filterToggles.forEach(function (t) { t.addEventListener('click', function () { if (filtersOpen()) { closeFilters(); } else { openFilters(t); } }); });
		if (filterClose) { filterClose.addEventListener('click', function () { closeFilters(); }); }
		if (filterBackdrop) { filterBackdrop.addEventListener('click', function () { closeFilters(); }); }
		filters.addEventListener('keydown', function (e) {
			if (!filtersOpen()) { return; }
			if (e.key === 'Escape') { e.preventDefault(); closeFilters(); }
			else if (e.key === 'Tab') { trapTab(filters, e); }
		});
		var onWide = function () { if (wide.matches) { closeFilters(false); } };
		if (wide.addEventListener) { wide.addEventListener('change', onWide); } else if (wide.addListener) { wide.addListener(onWide); }
	}

	/* ------------------------------------------------------------------
	 * Quote / contact forms: submit with fetch, fall back to the normal post
	 * ---------------------------------------------------------------- */
	$all('[data-vj-form]').forEach(function (form) {
		if (!window.fetch || !window.FormData || !cfg.formAjax) { return; }
		var status = form.querySelector('.vj-form__status');
		var submit = form.querySelector('button[type="submit"]');
		form.addEventListener('submit', function (e) {
			if (!form.checkValidity()) {
				e.preventDefault();
				var invalid = form.querySelector(':invalid');
				if (invalid) { invalid.focus(); }
				form.reportValidity && form.reportValidity();
				return;
			}
			e.preventDefault();
			form.classList.add('is-busy');
			if (submit) { submit.setAttribute('aria-busy', 'true'); }
			if (status) { status.className = 'vj-form__status'; status.textContent = i18n.sending || ''; }
			fetch(cfg.formAjax, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
				.then(function (r) {
					// The request reached the server: never re-submit (it may already have sent the e-mail).
					return r.json().catch(function () { return null; });
				}, function () {
					// Network error only: fall back to the normal post.
					form.submit();
					return undefined;
				})
				.then(function (res) {
					if (res === undefined) { return; }
					var ok = !!(res && res.success);
					var msg = res && res.data && res.data.message ? res.data.message : (i18n.formFailed || '');
					if (status) {
						status.className = 'vj-form__status ' + (ok ? 'is-success' : 'is-error');
						status.textContent = msg;
					}
					if (ok) { form.reset(); }
				})
				.then(function () {
					form.classList.remove('is-busy');
					if (submit) { submit.removeAttribute('aria-busy'); }
				});
		});
	});

	/* ------------------------------------------------------------------
	 * Copy buttons (bank details on the order-received page)
	 * ---------------------------------------------------------------- */
	function copyText(text) {
		if (navigator.clipboard && window.isSecureContext) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.setAttribute('readonly', '');
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild(ta);
			ta.select();
			try { document.execCommand('copy') ? resolve() : reject(); } catch (err) { reject(err); }
			ta.remove();
		});
	}
	$all('[data-vj-copy]').forEach(function (btn) {
		var label = btn.querySelector('.vj-copy__text');
		var original = label ? label.textContent : '';
		var live = btn.closest('section') ? btn.closest('section').querySelector('.vj-copy-status') : null;
		btn.addEventListener('click', function () {
			copyText(btn.getAttribute('data-vj-copy')).then(function () {
				btn.classList.add('is-done');
				if (label) { label.textContent = i18n.copied || original; }
				if (live) { live.textContent = i18n.copied || ''; }
				setTimeout(function () {
					btn.classList.remove('is-done');
					if (label) { label.textContent = original; }
				}, 2200);
			}, function () {
				if (live) { live.textContent = i18n.copyFailed || ''; }
			});
		});
	});

	/* ------------------------------------------------------------------
	 * Recently viewed (stored only in this browser)
	 * ---------------------------------------------------------------- */
	var recent = document.querySelector('[data-vj-recent]');
	if (recent) {
		try {
			var key = cfg.recentKey || 'voltaj_recent';
			var limit = cfg.recentLimit || 8;
			var current = JSON.parse(recent.getAttribute('data-vj-recent') || 'null');
			var stored = JSON.parse(window.localStorage.getItem(key) || '[]');
			if (!Array.isArray(stored)) { stored = []; }
			stored = stored.filter(function (x) { return x && typeof x.url === 'string' && (!current || x.id !== current.id); });
			var others = stored.slice(0, limit);
			if (current && current.id) {
				window.localStorage.setItem(key, JSON.stringify([current].concat(stored).slice(0, limit)));
			}
			var list = recent.querySelector('.vj-recent__list');
			var origin = window.location.origin;
			others.forEach(function (item) {
				var url;
				try { url = new URL(item.url, origin); } catch (err) { return; }
				if (url.origin !== origin) { return; }
				var li = document.createElement('li');
				li.className = 'vj-recent__item';
				var a = document.createElement('a');
				a.href = url.href;
				if (item.image) {
					var img = document.createElement('img');
					img.src = item.image;
					img.alt = '';
					img.loading = 'lazy';
					img.width = 64;
					img.height = 64;
					a.appendChild(img);
				}
				var text = document.createElement('span');
				text.className = 'vj-recent__text';
				[['vj-recent__name', item.title], ['vj-recent__sku', item.sku], ['vj-recent__price', item.price]].forEach(function (part) {
					if (!part[1]) { return; }
					var s = document.createElement('span');
					s.className = part[0];
					s.textContent = String(part[1]);
					text.appendChild(s);
				});
				a.appendChild(text);
				li.appendChild(a);
				list.appendChild(li);
			});
			if (list.children.length) { recent.hidden = false; }
		} catch (err) { /* storage unavailable: nothing to show */ }
	}

	/* ------------------------------------------------------------------
	 * Mobile buy bar: shown while the buy box is off screen
	 * ---------------------------------------------------------------- */
	var buybar = document.querySelector('[data-vj-buybar]');
	var buybox = document.getElementById('vj-buybox');
	if (buybar && buybox) {
		var mobile = window.matchMedia('(max-width: 899px)');
		var head = document.getElementById('site-header');
		var ticking = false;
		// Shown once the buy box has scrolled up behind the sticky header; hidden while it is on screen or below.
		var sync = function () {
			ticking = false;
			var limit = head ? head.getBoundingClientRect().bottom : 0;
			var show = mobile.matches && buybox.getBoundingClientRect().bottom < limit;
			if (buybar.hidden === show) {
				buybar.hidden = !show;
				document.body.classList.toggle('has-buybar', show);
			}
		};
		window.addEventListener('scroll', function () {
			if (!ticking) { ticking = true; window.requestAnimationFrame(sync); }
		}, { passive: true });
		window.addEventListener('resize', sync);
		sync();
		if (mobile.addEventListener) { mobile.addEventListener('change', sync); }
		var buyBtn = buybar.querySelector('[data-vj-buy]');
		if (buyBtn) {
			buyBtn.addEventListener('click', function () {
				var direct = buybox.querySelector('form.cart:not(.variations_form) .single_add_to_cart_button:not(.disabled)');
				if (direct) { direct.click(); return; }
				buybox.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' });
				var target = buybox.querySelector('select, .single_add_to_cart_button, a.vj-btn');
				if (target) { setTimeout(function () { target.focus({ preventScroll: true }); }, 300); }
			});
		}
	}

	/* ------------------------------------------------------------------
	 * Toast after AJAX add to cart (WooCommerce fires a jQuery event)
	 * ---------------------------------------------------------------- */
	if (window.jQuery && cfg.cartUrl) {
		var toastTimer = null;
		window.jQuery(document.body).on('added_to_cart', function (event, fragments, hash, button) {
			var old = document.querySelector('.vj-toast');
			if (old) { old.remove(); }
			var toast = document.createElement('div');
			toast.className = 'vj-toast';
			toast.setAttribute('role', 'status');
			var text = document.createElement('span');
			var name = '';
			var card = button && button[0] ? button[0].closest('li.product') : null;
			var title = card ? card.querySelector('.woocommerce-loop-product__title') : null;
			if (title) { name = title.textContent.trim(); }
			text.textContent = (i18n.added || '') + (name ? ': ' + name : '');
			var link = document.createElement('a');
			link.href = cfg.cartUrl;
			link.textContent = i18n.viewCart || '';
			var close = document.createElement('button');
			close.type = 'button';
			close.setAttribute('aria-label', i18n.close || '');
			close.textContent = '×';
			close.addEventListener('click', function () { toast.remove(); });
			toast.appendChild(text);
			toast.appendChild(link);
			toast.appendChild(close);
			document.body.appendChild(toast);
			requestAnimationFrame(function () { toast.classList.add('is-on'); });
			clearTimeout(toastTimer);
			toastTimer = setTimeout(function () { toast.classList.remove('is-on'); setTimeout(function () { toast.remove(); }, 250); }, 4500);
		});
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
			Array.prototype.forEach.call(document.querySelectorAll('.vj-cart-link'), function (link) {
				var badge = link.querySelector('.vj-cart-count');
				var sr = link.querySelector('.screen-reader-text');
				if (badge) {
					badge.textContent = String(total);
					badge.classList.toggle('is-empty', total === 0);
				}
				if (sr) { sr.textContent = sr.textContent.replace(/\d+/, String(total)); }
			});
		});
	}
}());
