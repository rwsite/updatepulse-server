/* global UPServAdminLicense, console */
jQuery(document).ready(function ($) {
	var editor = wp.codeEditor;
	var initEditor = true;

	$('.upserv-delete-all-licenses').on('click', function (e) {
		var r = window.confirm(UPServAdminLicense_l10n.deleteLicensesConfirm);

		if (!r) {
			e.preventDefault();
		}
	});

	$('#add_license_trigger').on('click', function() {
		showLicensePanel($('#upserv_license_panel'), function() {
			populateLicensePanel();
			$('#upserv_license_action').val('create');
			$('.upserv-edit-license-label').hide();
			$('.upserv-license-show-if-edit').hide();
			$('.upserv-add-license-label').show();
			$('.open-panel').attr('disabled', 'disabled');
			$('.upserv-licenses-table .open-panel').hide();
			$('html, body').animate({
                scrollTop: ($('#upserv_license_panel').offset().top - $('#wpadminbar').height() - 20)
            }, 500);
		});
	});

	$('.upserv-licenses-table .open-panel .edit a').on('click', function(e){
		e.preventDefault();

		var licenseData = JSON.parse($(this).closest('tr').find('input[name="license_data[]"]').val());

		showLicensePanel($('#upserv_license_panel'), function() {
			populateLicensePanel(licenseData);
			$('#upserv_license_action').val('update');
			$('.upserv-edit-license-label').show();
			$('.upserv-license-show-if-edit').show();
			$('.upserv-add-license-label').hide();
			$('.open-panel').attr('disabled', 'disabled');
			$('.upserv-licenses-table .open-panel').hide();
			$('html, body').animate({
                scrollTop: ($('#upserv_license_panel').offset().top - $('#wpadminbar').height() - 20)
            }, 500);
		});
	});

	$('#upserv_license_cancel, .close-panel.reset').on('click', function() {
		$('html, body').animate({
            scrollTop: ($('.upserv-wrap').offset().top - $('#wpadminbar').height() - 20)
        }, 150);
		hideLicensePanel($('#upserv_license_panel'), function() {
			resetLicensePanel();
		});
	});

	if ($.validator) {
		$.validator.methods.licenseDate = function( value, element ) {
			return this.optional( element ) || /[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/.test( value );
		};
		$.validator.methods.slug = function( value, element ) {
			return this.optional( element ) || /[a-z0-9-]*/.test( value );
		};

		$('#upserv_license').validate({
			ignore: '.CodeMirror *',
			errorClass: 'upserv-license-error',
			rules: {
				upserv_license_key: { required: true },
				upserv_license_package_slug: { required: true, slug: true },
				upserv_license_registered_email: { required: true, email: true },
				upserv_license_date_created: { required: true, licenseDate: true },
				upserv_license_date_expiry: { licenseDate: true },
				upserv_license_date_renewed: { licenseDate: true },
				upserv_license_max_allowed_domains: { required: true }
			},
			submitHandler: function (form) {
				var domainElements = $('.upserv-domains-list li:not(.upserv-domain-template) .upserv-domain-value'),
					values = {
						'id': $('#upserv_license_id').html(),
						'license_key': $('#upserv_license_key').val(),
						'max_allowed_domains': $('#upserv_license_max_allowed_domains').val(),
						'allowed_domains': domainElements.map(function() { return $(this).text(); }).get(),
						'status': $('#upserv_license_status').val(),
						'owner_name': $('#upserv_license_owner_name').val(),
						'email': $('#upserv_license_registered_email').val(),
						'company_name': $('#upserv_license_owner_company').val(),
						'txn_id': $('#upserv_license_transaction_id').val(),
						'data': editor.codemirror.getValue(),
						'date_created': $('#upserv_license_date_created').val(),
						'date_renewed': $('#upserv_license_date_renewed').val(),
						'date_expiry': $('#upserv_license_date_expiry').val(),
						'package_slug': $('#upserv_license_package_slug').val(),
						'package_type': $('#upserv_license_package_type').val()
					};

				$('#upserv_license_values').val(JSON.stringify(values));
				$('.no-submit').removeAttr('name');
				form.submit();
			}
		});
	}

	$('#upserv_license_registered_domains').on('click', '.upserv-remove-domain', function(e) {
		e.preventDefault();
		$(this).parent().remove();

		if (1 >= $('.upserv-remove-domain').length) {
			$('.upserv-no-domain').show();
		}
	});

	function populateLicensePanel(licenseData) {

		if (initEditor) {
			editor = editor.initialize($('#upserv_license_data'), UPServAdminLicense.cm_settings);
			initEditor = false;
		}

		if ($.isPlainObject(licenseData)) {
			$('#upserv_license_id').html(licenseData.id);
			$('#upserv_license_key').val(licenseData.license_key);
			$('#upserv_license_date_created').val(licenseData.date_created);
			$('#upserv_license_max_allowed_domains').val(licenseData.max_allowed_domains);
			$('#upserv_license_owner_name').val(licenseData.owner_name);
			$('#upserv_license_registered_email').val(licenseData.email);
			$('#upserv_license_owner_company').val(licenseData.company_name);
			$('#upserv_license_transaction_id').val(licenseData.txn_id);
			$('#upserv_license_package_slug').val(licenseData.package_slug);
			$('#upserv_license_status').val(licenseData.status);
			$('#upserv_license_data').val(licenseData.data ? JSON.stringify(JSON.parse(licenseData.data), null, '\t') : '');
			$('#upserv_license_package_type').val(licenseData.package_type);
			editor.codemirror.setValue($('#upserv_license_data').val());

			if ('0000-00-00' !== licenseData.date_expiry ) {
				$('#upserv_license_date_expiry').val(licenseData.date_expiry);
			}

			if ('0000-00-00' !== licenseData.date_renewed ) {
				$('#upserv_license_date_renewed').val(licenseData.date_renewed);
			}

			if (licenseData.allowed_domains.length > 0) {
				var list = $('.upserv-domains-list'),
					listItem = list.find('li').clone();

				listItem.removeClass('upserv-domain-template');

				$.each(licenseData.allowed_domains, function(idx, elem) {
					var item = listItem.clone();

					item.find('.upserv-domain-value').html(elem);
					list.append(item);
				});

				$('.upserv-no-domain').hide();
				list.show();
			}
		} else {
			$('#upserv_license_key').val($('#upserv_license_key').data('random_key'));
			$('#upserv_license_date_created').val(new Date().toISOString().slice(0, 10));
			$('#upserv_license_max_allowed_domains').val(1);
			editor.codemirror.setValue('{}');
		}
	}

	function resetLicensePanel() {
		$('#upserv_license').trigger('reset');
		$('upserv_license_values').val('');
		$('upserv_license_action').val('');
		$('.open-panel').removeAttr('disabled');
		$('.upserv-licenses-table .open-panel').show();
		$('#upserv_license_id').html('');
		$('.upserv-domains-list li:not(.upserv-domain-template)').remove();
		$('.upserv-no-domain').show();
		$('label.upserv-license-error').hide();
		$('.upserv-license-error').removeClass('upserv-license-error');
		editor.codemirror.setValue('{}');
	}

	function showLicensePanel( panel, callback ) {

		if (!panel.is(':visible')) {
			panel.slideDown(100, function() {
				callback(panel);
				panel.find('.inside').animate({ opacity: '1' }, 150 );
			});
		}
	}

	function hideLicensePanel( panel, callback ) {

		if (panel.is(':visible')) {
			panel.slideUp(100, function() {
				panel.find('.inside').css( { opacity: '0' } );
				callback(panel);
			});
		}
	}
});