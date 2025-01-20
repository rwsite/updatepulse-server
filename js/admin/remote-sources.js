jQuery(document).ready(function ($) {
    var form = $('.form-container.package-source');
    var inputElements = form.find('input[type="checkbox"], input[type="text"], input[type="password"], select');
    var inputTextElements = form.find('input[type="text"], input[type="password"]');
    var data = JSON.parse($('#upserv_repositories').val());
    var init = function () {
        $.each(data, function (id, itemData) {
            addItem(id, itemData);
        });

        if (Object.keys(data).length > 0) {
            var firstItem = $('.repositories .item:not(.template)').first();

            firstItem.addClass('selected');
            firstItem.trigger('click');
        }
    };
    var addItem = function (id, data) {
        var item = $('.repositories .item.template').clone();

        item.removeClass('template upserv-modal-open-handle');
        item.data('modal_id', null);
        item.removeAttr('data-modal_id');
        item.find('.placeholder').remove();
        item.find('.url span').text(data.url);
        item.find('.branch span.branch-name').text(data.branch);
        item.find('.hidden').removeClass('hidden');
        item.attr('id', id);
        $('.repositories').prepend(item);
    };
    var updateData = function (elem) {
        var key = $('.repositories .item.selected').attr('id');
        var prop = elem.data('prop');

        if (!key || !prop) {
            return;
        }

        var value = null;

        if (elem.is('input[type="checkbox"]')) {
            value = elem.prop('checked') ? '1' : '0';
        } else {
            value = elem.val();
        }

        data[key][prop] = value;
    };

    inputElements.on('change', function (e) {
        updateData($(this));
    });

    inputTextElements.on('keyup', function (e) {
        updateData($(this));
    });

    $('.repositories').on('click', '.item', function (e) {
        var elem = $(this);

        $('.repositories .item').removeClass('selected');
        elem.addClass('selected');

        if (elem.hasClass('upserv-modal-open-handle')) {
            form.addClass('hidden');
        } else {
            form.removeClass('hidden');
        }

        inputElements.trigger('change');
    });

    $('#upserv_remote_repository_use_webhooks').on('change', function (e) {

        if ($(this).prop('checked')) {
            $('.check-frequency').addClass('hidden');
            $('.webhooks').removeClass('hidden');
        } else {
            $('.webhooks').addClass('hidden');
            $('.check-frequency').removeClass('hidden');
        }
    });

    init();
});