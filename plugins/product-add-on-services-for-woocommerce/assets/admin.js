/* Product Add-On Services: group edit screen (option rows, scope fields). */
(function ($) {
	'use strict';
	var $body = $('.eru-aos-options tbody'), tpl = $('#tmpl-eru-aos-row').html() || '', n = Date.now();

	$('.eru-aos-add').on('click', function () {
		$body.append(tpl.replace(/__i__/g, 'n' + (n++)));
		$body.find('tr:last input[type=text]:first').trigger('focus');
	});
	$body.on('click', '.eru-aos-remove', function () {
		$(this).closest('tr').remove();
	});
	if ($.fn.sortable) {
		$body.sortable({ handle: '.eru-aos-drag', axis: 'y' });
	}

	function scope() {
		var v = $('#eru_aos_scope').val();
		$('.eru-aos-scope').each(function () {
			$(this).toggle($(this).data('scope') === v);
		});
	}
	function type() {
		$('.eru-aos-multi-only').toggle($('input[name="eru_aos[type]"]:checked').val() === 'multi');
	}
	$('#eru_aos_scope').on('change', scope);
	$('input[name="eru_aos[type]"]').on('change', type);
	scope();
	type();
})(jQuery);
