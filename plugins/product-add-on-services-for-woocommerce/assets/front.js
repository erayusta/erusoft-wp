/* Product Add-On Services: live total on the product page. The server recalculates everything. */
(function ($) {
	'use strict';
	var fmt = window.eruAos || { decimals: 2, decSep: ',', thouSep: '.', symbol: '', format: '%2$s %1$s' };

	function money(n) {
		var neg = n < 0, parts = Math.abs(n).toFixed(fmt.decimals).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, fmt.thouSep);
		var num = parts.join(fmt.decSep);
		return (neg ? '-' : '') + fmt.format.replace('%1$s', fmt.symbol).replace('%2$s', num);
	}
	function round(n) {
		var f = Math.pow(10, fmt.decimals);
		return Math.round(n * f) / f;
	}

	$('.eru-aos').each(function () {
		var $box = $(this), cfg = $box.data('config') || {}, base = cfg.base, regular = cfg.regular;
		var $form = $box.closest('form.cart');

		// Shortcode placement outside the form: tie the inputs to the product's form.
		if (!$form.length) {
			$form = $('form.cart').filter(function () {
				return $(this).find('[name="add-to-cart"][value="' + cfg.product + '"]').length > 0;
			}).first();
			if ($form.length) {
				var id = $form.attr('id') || 'eru-aos-form-' + cfg.product;
				$form.attr('id', id);
				$box.find('input').attr('form', id);
			}
		}

		function amountOf(opt) {
			if (opt.type !== 'percent') {
				return opt.amount;
			}
			var pb = cfg.percentBase === 'regular' && regular ? regular : base;
			return pb === null || pb === undefined ? null : round(pb * opt.amount / 100);
		}

		function update() {
			var qty = parseFloat($form.find('input.qty').val()) || 1, unit = 0, line = 0, known = base !== null && base !== undefined;
			$box.find('input[data-key]').each(function () {
				var opt = cfg.options[$(this).data('key')], amt;
				if (!opt) {
					return;
				}
				amt = amountOf(opt);
				if (opt.type === 'percent') {
					$(this).closest('.eru-aos-option').find('.eru-aos-option__amount').text(amt === null ? '' : '(' + money(amt) + ')');
				}
				if (!this.checked || amt === null) {
					return;
				}
				if (opt.charge === 'per_line') {
					line += amt;
				} else {
					unit += amt;
				}
			});
			var $total = $box.find('.eru-aos-total');
			if (!cfg.live || !known) {
				$total.prop('hidden', true);
				return;
			}
			$total.find('.eru-aos-total__amount').text(money(round((base + unit) * qty + line)));
			$total.prop('hidden', false);
		}

		$box.on('change', 'input', update);
		$form.on('change input', 'input.qty', update);
		$form.on('found_variation', function (e, v) {
			base = v.display_price;
			regular = v.display_regular_price || v.display_price;
			update();
		});
		$form.on('reset_data', function () {
			base = null;
			regular = null;
			update();
		});
		update();
	});
})(jQuery);
