/* global UPServAdminPackage, console, UPServAdminPackage_l10n */
jQuery(document).ready(function ($) {
	var registrationLocked = false;

	$('.upserv-delete-all-packages').on('click', function(e) {
		var r = window.confirm(UPServAdminPackage_l10n.deletePackagesConfirm);

		if (!r) {
			e.preventDefault();
		}
	});

	$('#upserv_register_package_slug').on('input', function () {

		if (0 < $(this).val().length && !registrationLocked && 0 < $('#upserv_vcs_select').val().length) {
			$('#upserv_register_package_trigger').removeAttr('disabled');
		} else if (!registrationLocked) {
			$('#upserv_register_package_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_vcs_select').on('change', function () {

		if (0 < $(this).val().length && !registrationLocked && 0 < $('#upserv_register_package_slug').val().length) {
			$('#upserv_register_package_trigger').removeAttr('disabled');
		} else if (!registrationLocked) {
			$('#upserv_register_package_trigger').attr('disabled', 'disabled');
		}
	});

	$('#upserv_register_package_trigger').on('click', function(e) {
		e.preventDefault();

		var button = $(this),
			data   = {
				slug: $('#upserv_register_package_slug').val(),
				vcs_key: $('#upserv_vcs_select').val(),
				nonce: $('#upserv_plugin_options_handler_nonce').val(),
				action: 'upserv_register_package_from_vcs'
			};

		button.attr('disabled', 'disabled');
		button.next().css('visibility', 'visible');

		registrationLocked = true;

		$.ajax({
			url: UPServAdminPackage.ajax_url,
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

					registrationLocked = false;

					button.removeAttr('disabled');
					button.next().css('visibility', 'hidden');
					window.alert(message);
				} else {
					window.location.reload(true);
				}
			},
			error: function (jqXHR, textStatus) {
				UPServAdminPackage.debug && console.log(textStatus);

				registrationLocked = false;

				window.alert(message);
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
				window.alert(UPServAdminPackage_l10n.invalidFileFormat);

				valid = false;
			}

			if (0 === file.size) {
				window.alert(UPServAdminPackage_l10n.invalidFileSize);

				valid = false;
			}

			if (!regex.test(file.name)) {
				window.alert(UPServAdminPackage_l10n.invalidFileName);

				valid = false;
			}

		} else {
			window.alert(UPServAdminPackage_l10n.invalidFile);

			valid = false;
		}

		if (valid) {
			data.append('action','upserv_manual_package_upload');
			data.append('package', file);
			data.append('nonce', $('#upserv_plugin_options_handler_nonce').val());

			$.ajax({
				url: UPServAdminPackage.ajax_url,
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
					UPServAdminPackage.debug && console.log(textStatus);
				}
			});
		} else {
			button.next().css('visibility', 'hidden');
			button.removeAttr('disabled');
		}
	});
});