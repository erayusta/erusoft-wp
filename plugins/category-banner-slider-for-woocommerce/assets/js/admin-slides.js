/* Category Banner Slider — admin repeater: ekle/çıkar/sırala + WP Media seçimi */
(function ($) {
    'use strict';

    function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    // Satırların POST indekslerini DOM sırasına göre yeniden numaralandırır.
    function reindex($container) {
        var field = $container.data('field');
        var re = new RegExp('^' + escapeRe(field) + '\\[[^\\]]*\\]');
        $container.find('.eru-cbs-slide-row').each(function (i) {
            $(this).find('[name]').each(function () {
                var name = $(this).attr('name');
                $(this).attr('name', name.replace(re, field + '[' + i + ']'));
            });
        });
    }

    $(document).on('click', '.eru-cbs-add-slide', function (e) {
        e.preventDefault();
        var $container = $(this).closest('.eru-cbs-slides');
        var tpl   = $container.find('.eru-cbs-row-template').html();
        var count = $container.find('.eru-cbs-slide-row').length;
        var html  = tpl.replace(/__INDEX__/g, count);
        $container.find('.eru-cbs-slides__list').append(html);
        reindex($container);
    });

    $(document).on('click', '.eru-cbs-remove-slide', function (e) {
        e.preventDefault();
        var $container = $(this).closest('.eru-cbs-slides');
        $(this).closest('.eru-cbs-slide-row').remove();
        reindex($container);
    });

    $(document).on('click', '.eru-cbs-select-image', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.eru-cbs-image-field');
        var frame = wp.media({ title: 'Görsel Seç', multiple: false, library: { type: 'image' } });
        frame.on('select', function () {
            var att   = frame.state().get('selection').first().toJSON();
            var thumb = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url;
            $field.find('.eru-cbs-image-id').val(att.id);
            $field.find('.eru-cbs-image-preview').addClass('has-image').html('<img src="' + thumb + '" alt="">');
            $field.find('.eru-cbs-clear-image').show();
        });
        frame.open();
    });

    $(document).on('click', '.eru-cbs-clear-image', function (e) {
        e.preventDefault();
        var $field = $(this).closest('.eru-cbs-image-field');
        $field.find('.eru-cbs-image-id').val('');
        $field.find('.eru-cbs-image-preview').removeClass('has-image').empty();
        $(this).hide();
    });

    $(function () {
        $('.eru-cbs-slides__list').sortable({
            handle: '.eru-cbs-drag',
            items: '> .eru-cbs-slide-row',
            update: function () { reindex($(this).closest('.eru-cbs-slides')); }
        });
    });
})(jQuery);
