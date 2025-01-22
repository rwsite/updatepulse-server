/* global UPServAdminMain, console, UPServAdminMain_l10n */
jQuery(document).ready(function ($) {
	var primeLocked = false;

	$('#upserv_prime_package_slug').on('input', function () {

		if (0 < $(this).val().length && !primeLocked && 0 < $('#upserv_vcs_select').val().length) {
			$('#upserv_prime_package_trigger').removeAttr('disabled');
		} else if (!primeLocked) {
			$('#upserv_prime_package_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_vcs_select').on('change', function () {

		if (0 < $(this).val().length && !primeLocked && 0 < $('#upserv_prime_package_slug').val().length) {
			$('#upserv_prime_package_trigger').removeAttr('disabled');
		} else if (!primeLocked) {
			$('#upserv_prime_package_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_prime_package_trigger').on('click', function(e) {
		e.preventDefault();

		var button = $(this),
			data   = {
				slug: $('#upserv_prime_package_slug').val(),
				vcs_key: $('#upserv_vcs_select').val(),
				nonce: $('#upserv_plugin_options_handler_nonce').val(),
				action: 'upserv_prime_package_from_remote'
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