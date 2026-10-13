(function () {
	'use strict';
	var link = document.querySelector('.single-product .eru-cmw-button');
	var form = document.querySelector('form.variations_form');
	if (!link || !form) { return; }
	var baseHref = link.getAttribute('href');

	function chosen() {
		var parts = [];
		form.querySelectorAll('.variations select').forEach(function (sel) {
			if (!sel.value) { return; }
			var label = form.querySelector('label[for="' + sel.id + '"]');
			var opt = sel.options[sel.selectedIndex];
			parts.push((label ? label.textContent.trim() + ': ' : '') + (opt ? opt.textContent.trim() : sel.value));
		});
		return parts.join(', ');
	}
	function update() {
		var c = chosen();
		link.setAttribute('href', c ? baseHref + encodeURIComponent('\n' + c) : baseHref);
	}
	form.addEventListener('change', update);
	if (window.jQuery) { window.jQuery(form).on('reset_data found_variation', update); }
})();
