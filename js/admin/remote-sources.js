jQuery(document).ready(function ($) {

    if (!$('#upserv_vcs').length) {
        return;
    }

    var form = $('.form-container.package-source');
    var inputElements = form.find('input[type="checkbox"], input[type="text"], input[type="number"], input[type="password"], select');
    var inputTextElements = form.find('input[type="text"], input[type="number"], input[type="password"]');
    var data = JSON.parse($('#upserv_vcs').val());
    var init = function () {
        $.each(data, function (id) {

            if ($('#' + id).length > 0) {
                return;
            }

            addItem(id);
        });

        if (Object.keys(data).length > 0) {
            $('.vcs .item:not(.template)').first().trigger('click');
        }
    };
    var selectRepository = function (id) {
        var item = $('#' + id);

        if (0 === item.length) {
            return
        }

        $('.vcs .item').removeClass('selected');
        item.addClass('selected');
        disableForm(false);
        form.removeClass('hidden');
        updateForm(id);
        inputElements.trigger('change');
    };
    var addData = function (id, itemData) {

        if (!id) {
            return;
        }

        data[id] = {};

        $.each(inputElements, function () {
            var elem = $(this);
            var prop = elem.data('prop');

            if (!prop) {
                return;
            }

            if (elem.is('input[type="checkbox"]')) {
                data[id][prop] = 0;
            } else if (elem.is('select')) {
                data[id][prop] = elem.find('option[value="daily"]').length > 0 ? 'daily' : elem.find('option').first().val();
            } else if (elem.is('input[type="number"]')) {
                data[id][prop] = 0;
            } else {
                data[id][prop] = '';
            }
        });

        $.each(itemData, function (prop, value) {
            data[id][prop] = value;
        });
    };
    var addItem = function (id) {
        var item = $('.vcs .item.template').clone();
        var itemData = data[id];
        var service = itemData.url.match(/https?:\/\/([^\/]+)\//);
        var name = itemData.url.split('/').filter(function (part) {
            return part.length > 0;
        }).pop();

        item.removeClass('template upserv-modal-open-handle');
        item.data('modal_id', null);
        item.removeAttr('data-modal_id');
        item.find('.placeholder').remove();
        item.find('.url').text(name);
        item.find('.branch-name').text(itemData.branch);
        item.find('.hidden').removeClass('hidden');
        item.attr('id', id);
        item.find('.service span').addClass('hidden');

        if (service && service[1] === 'github.com') {
            item.find('.service .github').removeClass('hidden');
        } else if (service && service[1] === 'gitlab.com') {
            item.find('.service .gitlab').removeClass('hidden');
        } else if (service && service[1] === 'bitbucket.org') {
            item.find('.service .bitbucket').removeClass('hidden');
        } else {
            data[id].self_hosted = true;

            item.find('.service .self-hosted').removeClass('hidden');
        }

        item.insertBefore($('.vcs .item.template'));
    };
    var remove = function (id) {
        delete data[id];
        $('#' + id).remove();
        updateForm();
        disableForm(true);
        form.addClass('hidden');
    };
    var updateForm = function (id) {
        inputElements.each(function () {
            var elem = $(this);
            var prop = elem.data('prop');

            if (!prop) {
                return;
            }

            var value = id && data[id] && data[id][prop] !== undefined ? data[id][prop] : null;

            if (elem.is('input[type="checkbox"]')) {
                elem.prop('checked', ['1', 'true', 'yes', 'on', 1, true].includes(value));
            } else if (elem.is('input[type="number"]')) {
                elem.val(value ? parseInt(value) : 0);
            } else {
                elem.val(value ? value : '');
            }

            $('#upserv_vcs_list').val(id);
        });
    };
    var updateData = function (elem) {
        var id = $('.vcs .item.selected').attr('id');
        var prop = elem.data('prop');

        if (!id || !prop) {
            return;
        }

        var value = null;

        if (elem.is('input[type="checkbox"]')) {
            value = elem.prop('checked') ? '1' : '0';
        } else {
            value = elem.val();
        }

        if (data[id]) {
            data[id][prop] = value;
        }

        var name = data[id].url.split('/').filter(function (part) {
            return part.length > 0;
        }).pop();

        $('#' + id).find('.branch-name').text(data[id].branch);
        $('#' + id).find('.url').text(name);
        updateRepositories();
    };
    var updateRepositories = function () {
        $('#upserv_vcs').val(JSON.stringify(data));
    };
    var disableForm = function (disable) {
        inputElements.prop('disabled', disable);
    };

    inputElements.on('change', function (e) {
        e.stopPropagation();
        updateData($(this));
    });

    inputTextElements.on('keyup', function (e) {
        e.stopPropagation();
        updateData($(this));
    });

    $(document).on('upserv-modal-close', function (e, handler) {

        if ($('.upserv-wrap').find('.form-container.package-source').length > 0) {
            disableForm(false);
        }
    });

    $('.vcs').on('click', '.item', function (e) {
        var elem = $(this);

        if (elem.hasClass('upserv-modal-open-handle')) {
            disableForm(true);
            $('.upserv-modal .error').addClass('hidden');
        } else {
            selectRepository(elem.attr('id'));
        }
    });

    $("#upserv_add_vcs").on('click', function (e) {
        e.preventDefault();
        $('.upserv-modal .error').addClass('hidden');
        $('.vcs .item.selected').removeClass('selected');

        var url = $('#upserv_add_vcs_url').val();
        var branch = $('#upserv_add_vcs_branch').val();

        if (!url || !url.match(/^https?:\/\/[^\/]+\/[^\/]+\/$/)) {
            $('.upserv-modal .error.invalid-url').removeClass('hidden');

            return;
        }

        if (!branch || !isNaN(branch)) {
            $('.upserv-modal .error.invalid-branch').removeClass('hidden');

            return;
        }

        var id = Math.random().toString(36).substring(7);

        addData(id, { url: url, branch: branch });
        addItem(id, data[id]);
        selectRepository(id);
        $(this).closest('.upserv-modal').trigger('close', [$(this)]);
    });

    $('#upserv_remove_vcs').on('click', function (e) {
        e.preventDefault();
        var id = $('.vcs .item.selected').attr('id');

        if (!id) {
            return;
        }

        remove(id);
    });

    $('#upserv_vcs_use_webhooks').on('change', function (e) {

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