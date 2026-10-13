/* Batched WebP regeneration: one request per small batch, progress bar. */
(function () {
	'use strict';
	var btn = document.getElementById('eru-webp-start');
	if (!btn || !window.eruWebp) { return; }
	var bar = document.getElementById('eru-webp-bar');
	var status = document.getElementById('eru-webp-status');
	var wrap = bar.parentNode;
	var totals = { created: 0, exists: 0, skipped: 0, error: 0 };
	var processed = 0;

	function step(after, force) {
		var body = new URLSearchParams({ action: 'eru_webp_bulk', nonce: eruWebp.nonce, after: after, force: force ? '1' : '' });
		fetch(eruWebp.ajax, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res.success) { throw new Error('x'); }
				var d = res.data;
				processed += d.done;
				Object.keys(totals).forEach(function (k) { totals[k] += d.stats[k] || 0; });
				var pct = d.total ? Math.min(100, Math.round(processed / d.total * 100)) : 100;
				bar.style.width = pct + '%';
				status.textContent = eruWebp.i18n.running + ' ' + processed + ' / ' + d.total + ' (+' + totals.created + ')';
				if (d.end) {
					bar.style.width = '100%';
					status.textContent = eruWebp.i18n.done + ' ' + processed + ' / ' + d.total + ' — +' + totals.created + ' / =' + totals.exists + ' / ~' + totals.skipped + ' / !' + totals.error;
					btn.disabled = false;
				} else {
					step(d.last, force);
				}
			})
			.catch(function () {
				status.textContent = eruWebp.i18n.failed;
				btn.disabled = false;
			});
	}

	btn.addEventListener('click', function () {
		btn.disabled = true;
		wrap.hidden = false;
		bar.style.width = '0';
		processed = 0;
		totals = { created: 0, exists: 0, skipped: 0, error: 0 };
		step(0, document.getElementById('eru-webp-force').checked);
	});
})();
