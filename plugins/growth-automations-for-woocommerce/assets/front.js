(function () {
	'use strict';
	var cfg = window.eruGra || {};

	function post(url, data) {
		var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
		return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body }).then(function (r) { return r.json(); });
	}

	// Checkout: the cart is sent to the server only while the consent box is ticked and the e-mail looks valid.
	var box = document.getElementById('eru_gra_marketing');
	if (box && cfg.checkout) {
		var timer = null;
		var send = function () {
			var email = document.getElementById('billing_email');
			var value = email ? email.value.trim() : '';
			if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) { return; }
			post(cfg.captureUrl, { nonce: cfg.nonce, email: value, consent: box.checked ? '1' : '0' }).catch(function () {});
		};
		box.addEventListener('change', send);
		document.addEventListener('change', function (e) {
			if (e.target && e.target.id === 'billing_email' && box.checked) {
				clearTimeout(timer);
				timer = setTimeout(send, 400);
			}
		});
	}

	// Stock alert form (inputs are not inside a <form>; availability HTML lives in WooCommerce's variations form).
	function submitAlert(wrap) {
		var email = wrap.querySelector('.eru-gra-alert__email');
		var msg = wrap.querySelector('.eru-gra-alert__msg');
		var btn = wrap.querySelector('.eru-gra-alert__btn');
		var hp = wrap.querySelector('.eru-gra-alert__hp');
		if (!email || !email.value.trim()) { if (msg) { msg.textContent = ''; } if (email) { email.focus(); } return; }
		btn.disabled = true;
		post(cfg.alertUrl, {
			nonce: wrap.getAttribute('data-nonce'),
			email: email.value.trim(),
			product_id: wrap.getAttribute('data-product'),
			variation_id: wrap.getAttribute('data-variation'),
			hp: hp ? hp.value : ''
		}).then(function (res) {
			msg.textContent = (res && res.message) || cfg.error;
			msg.classList.toggle('is-ok', !!(res && res.ok));
			if (res && res.ok) { email.value = ''; }
			btn.disabled = false;
		}).catch(function () {
			msg.textContent = cfg.error;
			btn.disabled = false;
		});
	}
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.eru-gra-alert__btn') : null;
		if (btn) { e.preventDefault(); submitAlert(btn.closest('.eru-gra-alert')); }
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target && e.target.classList && e.target.classList.contains('eru-gra-alert__email')) {
			e.preventDefault();
			submitAlert(e.target.closest('.eru-gra-alert'));
		}
	});
})();
