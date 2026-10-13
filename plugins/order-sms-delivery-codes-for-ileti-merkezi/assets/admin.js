/* Order SMS & Delivery Codes — order screen delivery-code box + rule text counter. */
(function ($) {
	'use strict';

	var data = window.eruOsms || {};
	var t = data.i18n || {};

	// Status dropdown: delivery statuses cannot be chosen while a code is pending (the real block is server-side).
	if (data.locked && data.locked.length) {
		$.each(data.locked, function (_, slug) {
			var $opt = $('#order_status option[value="' + slug + '"]');
			if ($opt.length && !$opt.is(':selected')) {
				$opt.prop('disabled', true).text($opt.text() + ' — ' + t.waitingCode);
			}
		});
		$('#order_status').trigger('change.select2');
	}

	var $box = $('#eru-osms-delivery');
	var $msg = $box.find('.eru-osms-msg');

	function say(text, kind) {
		$msg.text(text || '').attr('data-kind', kind || '');
	}
	function call(action, extra) {
		return $.post(data.ajax, $.extend({ action: action, order_id: data.orderId, nonce: data.nonce }, extra || {}));
	}
	function failText(xhr) {
		var j = xhr && xhr.responseJSON;
		return (j && j.data && j.data.message) || t.network;
	}

	$box.on('input', '.eru-osms-code', function () {
		this.value = this.value.replace(/\D+/g, '').slice(0, 6);
	});

	$box.on('click', '.eru-osms-verify', function () {
		var $btn = $(this);
		var code = $box.find('.eru-osms-code').val();
		var name = $.trim($box.find('.eru-osms-name').val());
		var rel = $box.find('.eru-osms-relation').val();
		if (code.length !== 6) { say(t.enterCode, 'error'); return; }
		if (!name) { say(t.enterName, 'error'); return; }
		if (!rel) { say(t.pickRel, 'error'); return; }
		$btn.prop('disabled', true);
		say(t.verifying);
		call('eru_osms_verify', { code: code, name: name, relation: rel, note: $box.find('.eru-osms-note').val() })
			.done(function (r) {
				if (r && r.success) { say(r.data.message, 'ok'); window.location.reload(); return; }
				say(r && r.data ? r.data.message : t.failed, 'error');
				$btn.prop('disabled', false);
			})
			.fail(function (xhr) { say(failText(xhr), 'error'); $btn.prop('disabled', false); });
	});

	// Resend: two-click confirmation instead of a browser dialog.
	var confirmTimer = null;
	$box.on('click', '.eru-osms-resend', function () {
		var $btn = $(this);
		if (!$btn.data('confirm')) {
			$btn.data('confirm', 1).data('label', $btn.text()).text(t.confirm);
			confirmTimer = setTimeout(function () { $btn.data('confirm', 0).text($btn.data('label')); }, 4000);
			return;
		}
		clearTimeout(confirmTimer);
		$btn.prop('disabled', true).data('confirm', 0).text($btn.data('label'));
		say(t.sending);
		call('eru_osms_resend')
			.done(function (r) {
				say(r && r.data ? r.data.message : '', r && r.success ? 'ok' : 'error');
				if (r && r.success) { setTimeout(function () { window.location.reload(); }, 1500); } else { $btn.prop('disabled', false); }
			})
			.fail(function (xhr) { say(failText(xhr), 'error'); $btn.prop('disabled', false); });
	});

	// Rule text counter (same rule as Eru_OSMS_Text::segments, estimate).
	var GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
	var GSM_EXT = '^{}\\[~]|€';
	function segments(text) {
		var chars = Array.from(text), unicode = false, gsm = 0;
		chars.forEach(function (c) {
			if (GSM.indexOf(c) !== -1) { gsm++; } else if (GSM_EXT.indexOf(c) !== -1) { gsm += 2; } else { unicode = true; }
		});
		var n = unicode ? chars.length : gsm;
		var seg = n === 0 ? 0 : (unicode ? (n <= 70 ? 1 : Math.ceil(n / 67)) : (n <= 160 ? 1 : Math.ceil(n / 153)));
		return { chars: n, segments: seg, unicode: unicode };
	}
	$('.eru-osms-counted').each(function () {
		var $f = $(this);
		var $c = $f.siblings('.eru-osms-counter');
		function update() {
			var s = segments($f.val().replace(/\{teslimat_kodu\}/g, '000000'));
			$c.text(s.chars + ' ' + t.chars + ' · ' + s.segments + ' ' + t.parts + (s.unicode ? ' · ' + t.unicode : ''))
				.toggleClass('eru-osms-counter--warn', s.segments > 1);
		}
		$f.on('input', update);
		update();
	});
})(jQuery);
