/* global ERU_ORS, jQuery */
(function ($) {
	'use strict';

	var T = ( typeof ERU_ORS !== 'undefined' && ERU_ORS.i18n ) || {};

	if (typeof ERU_ORS === 'undefined') {
		return;
	}

	$(document).on('click', '[data-eru-ors-action="recalc"]', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $box = $btn.closest('.eru-ors-box');
		var $result = $box.find('.eru-ors-result');
		var orderId = $box.data('orderId');

		var origLabel = $btn.text();
		$btn.prop('disabled', true).text(T.calculating);
		$result.removeClass('error success').text('');

		$.ajax({
			url: ERU_ORS.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'eru_ors_recalc',
				order_id: orderId,
				nonce: ERU_ORS.nonce
			}
		}).done(function (resp) {
			if (resp && resp.success) {
				refreshBox($box, resp.data);
				$result.addClass('success').text(T.updated);
			} else {
				$result.addClass('error').text((resp && resp.data && resp.data.message) || T.error);
			}
		}).fail(function (xhr) {
			var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || T.serverError;
			$result.addClass('error').text(msg);
		}).always(function () {
			$btn.prop('disabled', false).text(origLabel);
		});
	});

	function refreshBox($box, data) {
		var $badge = $box.find('.eru-ors-headline .eru-ors-badge');
		$badge
			.removeClass('eru-ors-low eru-ors-medium eru-ors-high eru-ors-na')
			.addClass('eru-ors-' + data.band)
			.text(data.score + '/100');
		$box.find('.eru-ors-band-label').text(T.risk.replace('%s', data.band_label));

		var $list = $box.find('.eru-ors-signals');
		if (!$list.length) {
			$list = $('<ul class="eru-ors-signals"></ul>').insertBefore($box.find('.eru-ors-at'));
		}
		$list.empty();
		if (data.signals_pretty && data.signals_pretty.length) {
			data.signals_pretty.forEach(function (s) {
				$list.append(
					'<li><span class="eru-ors-signal-label"></span> <span class="eru-ors-signal-weight"></span></li>'
				);
				var $li = $list.find('li').last();
				$li.find('.eru-ors-signal-label').text(s.label);
				$li.find('.eru-ors-signal-weight').text('+' + s.weight);
			});
		} else {
			$list.html($('<li><em></em></li>').find('em').text(T.noSignals).end());
		}

		if (data.calculated_at) {
			var $at = $box.find('.eru-ors-at small');
			if (!$at.length) {
				$box.append('<p class="eru-ors-at"><small></small></p>');
				$at = $box.find('.eru-ors-at small');
			}
			$at.text(T.calculated.replace('%s', data.calculated_at));
		}
	}
})(jQuery);
