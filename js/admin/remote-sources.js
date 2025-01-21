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
    var selectRepository = function (id) {
        var item = $('#' + id);

        if (0 === item.length) {
            return
        }

        $('.repositories .item').removeClass('selected');
        item.addClass('selected');
        form.removeClass('hidden');
        inputElements.trigger('change');
        // updateForm(id);
    };
    var addItem = function (id, data) {

        if ($('#' + id).length > 0) {
            return;
        }

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
    var updateForm = function (id) {
        inputElements.each(function () {
            var elem = $(this);
            var prop = elem.data('prop');

            if (!prop) {
                return;
            }

            var value = data[id] && data[id][prop] ? data[id][prop] : '';

            if (elem.is('input[type="checkbox"]')) {
                elem.prop('checked', ['1', 'true', 'yes', 'on', 1, true].includes(value));
            } else {
                elem.val(value);
            }

            console.log('updateForm', prop, value);
        });
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

        if (!data[key]) {
            data[key] = {};
        }

        data[key][prop] = value;

        console.log('updateData', prop, value);
    };

    inputElements.on('change', function (e) {
        e.stopPropagation();
        updateData($(this));
    });

    inputTextElements.on('keyup', function (e) {
        e.stopPropagation();
        updateData($(this));
    });

    $('.repositories').on('click', '.item', function (e) {
        var elem = $(this);

        if (elem.hasClass('upserv-modal-open-handle')) {
            form.addClass('hidden');
        } else {
            selectRepository(elem.attr('id'));
        }
    });

    $("#upserv_add_remote_repository").on('click', function (e) {
        e.preventDefault();

        var url = $('#upserv_add_remote_repository_url').val();
        var branch = $('#upserv_add_remote_repository_branch').val();
        // make sure the values are not empty
        if (!url || !branch || !isNaN(branch) || !url.match(/^https?:\/\/.+/)) {
            return;
        }

        var id = btoa(url + '|' + branch).replace(/=/g, '');

        addItem(id, { url: url, branch: branch });
        selectRepository(id);

        $(this).closest('.upserv-modal').trigger('close', [$(this)]);
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