/* global UPServAdminMain, console, UPServAdminMain_l10n */
jQuery(document).ready(function ($) {

	function htmlDecode(input) {
		var doc = new DOMParser().parseFromString(input, "text/html");
		return doc.documentElement.textContent;
	}

	if (-1 !== location.href.indexOf('action=')) {
		var hrefParts = window.location.href.split('?');

		hrefParts[1] = hrefParts[1].replace('action=', 'action_done=');

		history.pushState(null, '', hrefParts.join('?') );
	}

	/** Modal */
	$('body').on('click', '.upserv-modal-open-handle', function (e) {
		var modal = $('#' + $(this).data('modal_id'));

		e.preventDefault();
		modal.trigger('open', [$(this)]);
	});

	$('body').on('click', '.upserv-modal-close-handle', function (e) {
		var modal = $('#' + $(this).data('modal_id'));

		e.preventDefault();
		modal.trigger('close', [$(this)]);
	});

	$('body').on('click', '.upserv-modal-close', function (e) {
		var modal = $(this).closest('.upserv-modal');

		e.preventDefault();
		modal.trigger('close', [$(this)]);
	});

	$('body').on('open', '.upserv-modal', function(handler) {
		var modal = $(this);

		modal.data('handler', handler);
		$(document).trigger('upserv-modal-open', [handler]);
		modal.removeClass('hidden');
		$('body').addClass('upserv-modal-open');
	});

	$('body').on('close', '.upserv-modal', function(handler) {
		var modal = $(this);

		modal.data('handler', null);
		$(document).trigger('upserv-modal-close', [handler]);
		modal.addClass('hidden');
		$('body').removeClass('upserv-modal-open');
	});
	/** End Modal */

	$('input[type="password"].secret').on('focus', function () {
		$(this).attr('type', 'text');
	});

	$('input[type="password"].secret').on('blur', function () {
		$(this).attr('type', 'password');
	});

	$('.ajax-trigger').on('click', function(e) {
		e.preventDefault();

		var button = $(this),
			type   = button.data('type'),
			data   = {
				type: type,
				nonce: $('#upserv_plugin_options_handler_nonce').val(),
				action: 'upserv_' + button.data('action'),
				data: button.data('selector') ? $(button.data('selector')).get().reduce(function (obj, el) {

					if (el.type === 'checkbox' || el.type === 'radio') {
						obj[el.id] = el.checked;
					} else {
						obj[el.id] = el.value;
					}

					return obj;
				}, {}) : {}
			};

		button.attr('disabled', 'disabled');

		$.ajax({
			url: UPServAdminMain.ajax_url,
			data: data,
			type: 'POST',
			success: function(response) {

				if (!response.success) {
					var message = '';

					/* jshint ignore:start */
					$.each(response.data, function(idx, value) {
						message += htmlDecode(value.message) + "\n";
					});
					/* jshint ignore:end */

					window.alert(message);
				} else if (response.data) {
					var message = '';

					/* jshint ignore:start */
					$.each(response.data, function (idx, value) {

						if ('btnVal' !== idx) {
							message += htmlDecode(value) + "\n";
						}
					});
					/* jshint ignore:end */

					if (message.length) {
						window.alert(message);
					}
				}

				button.removeAttr('disabled');

				if (response.data && response.data.btnVal) {
					button.val(response.data.btnVal);
				}
			},
			error: function (jqXHR, textStatus) {
				UPServAdminMain.debug && console.log(textStatus);
			}
		});

	});
});