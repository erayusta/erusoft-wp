(function () {
	'use strict';
	var cfg = window.eruCb || {};

	function post(url, body) {
		return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then(function (r) { return r.json(); });
	}

	// AJAX add-to-cart for suggestion and recommendation cards (WooCommerce's own add_to_cart endpoint).
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.eru-cb-card__add[data-product-id]') : null;
		if (!btn || !cfg.wcAjax) { return; }
		e.preventDefault();
		var label = btn.textContent;
		btn.classList.add('is-busy');
		btn.textContent = cfg.adding;
		post(cfg.wcAjax, 'product_id=' + encodeURIComponent(btn.getAttribute('data-product-id')) + '&quantity=1').then(function (res) {
			if (res && res.error && res.product_url) { window.location.href = res.product_url; return; }
			btn.textContent = cfg.added;
			if (window.jQuery) { window.jQuery(document.body).trigger('added_to_cart', [res.fragments, res.cart_hash, window.jQuery(btn)]); }
			if (cfg.reload === 1) { window.location.reload(); return; }
			btn.classList.remove('is-busy');
		}).catch(function () {
			btn.classList.remove('is-busy');
			btn.textContent = label;
		});
	});

	// Timed coupon: claim button.
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('[data-eru-cb-claim]') : null;
		if (!btn) { return; }
		e.preventDefault();
		var box = btn.closest('.eru-cb-timed');
		var err = box ? box.querySelector('.eru-cb-timed__err') : null;
		btn.disabled = true;
		post(cfg.claimUrl, 'nonce=' + encodeURIComponent(cfg.nonce)).then(function (res) {
			if (res && res.ok) { window.location.reload(); return; }
			if (err) { err.textContent = (res && res.message) || cfg.error; }
			btn.disabled = false;
		}).catch(function () {
			if (err) { err.textContent = cfg.error; }
			btn.disabled = false;
		});
	});

	// Countdown: counts down the server-computed remaining seconds of the real coupon; at zero the page reloads
	// so the server renders the expired state.
	var timed = document.querySelector('.eru-cb-timed.is-active[data-remaining]');
	if (timed) {
		var clock = timed.querySelector('.eru-cb-timed__clock');
		var end = Date.now() + parseInt(timed.getAttribute('data-remaining'), 10) * 1000;
		var tick = function () {
			var left = Math.max(0, Math.round((end - Date.now()) / 1000));
			if (clock) { clock.textContent = String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0'); }
			if (left <= 0) { window.clearInterval(iv); window.location.reload(); }
		};
		var iv = window.setInterval(tick, 1000);
		tick();
	}

	// Newsletter form: AJAX when available, plain POST otherwise.
	document.addEventListener('submit', function (e) {
		var form = e.target.closest ? e.target.closest('form.eru-cb-nl') : null;
		if (!form || !window.FormData) { return; }
		e.preventDefault();
		var msg = form.querySelector('.eru-cb-nl__msg');
		var data = new window.URLSearchParams(new window.FormData(form));
		data.set('action', 'eru_cb_subscribe');
		msg.classList.remove('is-error');
		msg.textContent = '';
		post(cfg.ajax, data.toString()).then(function (res) {
			msg.classList.toggle('is-error', !res.ok);
			msg.textContent = res.message || cfg.error;
			if (res.ok) { form.querySelector('input[type=email]').value = ''; }
		}).catch(function () { msg.classList.add('is-error'); msg.textContent = cfg.error; });
	});
}());
