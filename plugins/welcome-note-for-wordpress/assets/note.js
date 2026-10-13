/* Dismiss: logged-in users persist it in user meta, guests via localStorage. */
(function () {
	'use strict';
	if (!window.eruWn) { return; }
	var store = 'eru_wn_dismissed';
	function guestDone() {
		try { return window.localStorage.getItem(store) === eruWn.key; } catch (e) { return false; }
	}
	document.querySelectorAll('.eru-wn').forEach(function (box) {
		if (box.classList.contains('eru-wn--front') && box.classList.contains('eru-wn--top') && document.body) {
			document.body.insertBefore(box, document.body.firstChild);
		}
		if (!eruWn.logged && box.classList.contains('eru-wn--front') && guestDone()) {
			box.remove();
		}
	});
	document.addEventListener('click', function (e) {
		var btn = e.target.closest ? e.target.closest('.eru-wn__close') : null;
		if (!btn) { return; }
		var box = btn.closest('.eru-wn');
		if (!box) { return; }
		box.remove();
		if (eruWn.logged) {
			var body = new URLSearchParams({ action: 'eru_wn_dismiss', nonce: eruWn.nonce, key: eruWn.key });
			fetch(eruWn.ajax, { method: 'POST', credentials: 'same-origin', body: body });
		} else {
			try { window.localStorage.setItem(store, eruWn.key); } catch (err) { /* ignore */ }
		}
	});
})();
