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

	$('.upserv-wrap .wp-list-table .delete a').on('click', function(e) {
		var r = window.confirm(UPServAdminMain_l10n.deleteRecord);

		if (!r) {
			e.preventDefault();
		}
	});

	$('.upserv-delete-all-packages').on('click', function(e) {
		var r = window.confirm(UPServAdminMain_l10n.deletePackagesConfirm);

		if (!r) {
			e.preventDefault();
		}
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
					obj[el.id] = el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value;

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

	var primeLocked = false;

	$('#upserv_prime_package_slug').on('input', function() {

		if (0 < $(this).val().length && !primeLocked) {
			$('#upserv_prime_package_trigger').removeAttr('disabled');
		} else if (!primeLocked) {
			$('#upserv_prime_package_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_prime_package_trigger').on('click', function(e) {
		e.preventDefault();

		var button = $(this),
			data   = {
				slug :   $('#upserv_prime_package_slug').val(),
				nonce :  $('#upserv_plugin_options_handler_nonce').val(),
				action : 'upserv_prime_package_from_remote'
			};

		button.attr('disabled', 'disabled');
		button.next().css('visibility', 'visible');

		primeLocked = true;

		$.ajax({
			url: UPServAdminMain.ajax_url,
			data: data,
			type: 'POST',
			success: function(response) {

				if (!response.success) {
					var message = '';

					/* jshint ignore:start */
					$.each(response.data, function(idx, value) {
						message += value.message + "\n";
					});
					/* jshint ignore:end */

					primeLocked = false;

					button.removeAttr('disabled');
					button.next().css('visibility', 'hidden');
					window.alert(message);
				} else {
					window.location.reload(true);
				}
			},
			error: function (jqXHR, textStatus) {
				UPServAdminMain.debug && console.log(textStatus);

				primeLocked = false;

				button.removeAttr('disabled');
				button.next().css('visibility', 'hidden');
			}
		});

	});

	$('#upserv_manual_package_upload').on('change', function() {
		var fileinput = $(this);

		if (0 < fileinput.prop('files').length) {
			$('#upserv_manual_package_upload_filename').val(fileinput.prop('files')[0].name);
			$('#upserv_manual_package_upload_trigger').removeAttr('disabled');
		} else {
			$('#upserv_manual_package_upload_filename').val('');
			$('#upserv_manual_package_upload_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_manual_package_upload_dropzone').on('drag dragstart dragend dragover dragenter dragleave drop', function(e) {
		e.preventDefault();
		e.stopPropagation();
	}).on('drop', function(e) {
		var fileinput = $('#upserv_manual_package_upload');

		fileinput.prop('files', e.originalEvent.dataTransfer.files);
		fileinput.trigger('change');
	});

	$('.manual-package-upload-trigger').on('click', function(e) {
		e.preventDefault();

		var button           = $(this),
			data             = new FormData(),
			valid            = true,
			file             = $('#upserv_manual_package_upload').prop('files')[0],
			regex            = /^([a-zA-Z0-9\-\_]*)\.zip$/gm,
			validFileFormats = [
				'multipart/x-zip',
				'application/zip',
				'application/zip-compressed',
				'application/x-zip-compressed'
			];

		button.attr('disabled', 'disabled');
		button.next().css('visibility', 'visible');

		if (typeof file !== 'undefined' &&
			typeof file.type !== 'undefined' &&
			typeof file.size !== 'undefined' &&
			typeof file.name !==  'undefined'
		) {

			if ($.inArray(file.type, validFileFormats) === -1) {
				window.alert(UPServAdminMain_l10n.invalidFileFormat);

				valid = false;
			}

			if (0 === file.size) {
				window.alert(UPServAdminMain_l10n.invalidFileSize);

				valid = false;
			}

			if (!regex.test(file.name)) {
				window.alert(UPServAdminMain_l10n.invalidFileName);

				valid = false;
			}

		} else {
			window.alert(UPServAdminMain_l10n.invalidFile);

			valid = false;
		}

		if (valid) {
			data.append('action','upserv_manual_package_upload');
			data.append('package', file);
			data.append('nonce', $('#upserv_plugin_options_handler_nonce').val());

			$.ajax({
				url: UPServAdminMain.ajax_url,
				data: data,
				type: 'POST',
				cache: false,
				contentType: false,
				processData: false,
				success: function(response) {

					if (!response.success) {
						var message = '';

						/* jshint ignore:start */
						$.each(response.data, function(idx, value) {
							message += value.message + "\n";
						});
						/* jshint ignore:end */

						button.removeAttr('disabled');
						window.alert(message);
					} else {
						window.location.reload(true);
					}
				},
				error: function (jqXHR, textStatus) {
					UPServAdminMain.debug && console.log(textStatus);
				}
			});
		} else {
			button.next().css('visibility', 'hidden');
			button.removeAttr('disabled');
		}
	});
});