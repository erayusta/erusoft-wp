/* Drag-and-drop ordering for the FAQ list table. */
jQuery(function ($) {
	var $body = $('#the-list');
	if (!$body.length || !window.eruFaqSort) { return; }
	$('<p class="description"></p>').text(eruFaqSort.hint).insertBefore('.wp-list-table');
	$body.sortable({
		items: 'tr',
		axis: 'y',
		cursor: 'move',
		update: function () {
			var ids = $body.find('tr').map(function () {
				return this.id.replace('post-', '');
			}).get();
			$.post(eruFaqSort.ajax, { action: 'eru_faq_sort', nonce: eruFaqSort.nonce, ids: ids });
		}
	});
});
