(function () {
	'use strict';
	var btn = document.getElementById('eru-sps-reindex');
	var out = document.getElementById('eru-sps-status');
	if (!btn || !window.ERU_SPS_ADMIN) { return; }
	var cfg = window.ERU_SPS_ADMIN;

	function step(offset) {
		var body = new URLSearchParams({ action: 'eru_sps_reindex', nonce: cfg.nonce, offset: String(offset) });
		fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j || !j.success) { throw new Error('x'); }
				out.textContent = cfg.busy + ' ' + j.data.next + ' / ' + j.data.total;
				if (j.data.done) { out.textContent = cfg.done + ' (' + j.data.next + ')'; btn.disabled = false; return; }
				step(j.data.next);
			})
			.catch(function () { out.textContent = cfg.error; btn.disabled = false; });
	}
	btn.addEventListener('click', function () { btn.disabled = true; out.textContent = cfg.busy; step(0); });
})();
