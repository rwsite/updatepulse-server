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

	$('#upserv_modal_license_details').on('open', function (e, handler) {
		var info;
		var modal = $(this);

		if (handler.closest('tr').find('input[name="license_data[]"]').val()) {
			info = JSON.parse(handler.closest('tr').find('input[name="license_data[]"]').val());
		}

		if (typeof info !== 'object') {
			info = {};
		}

		if (info.data) {
			info.data = JSON.parse(info.data);
		}

		if (typeof info.data !== 'object') {
			info.data = {};
		}

		modal.find('h2').html(info.license_key + '<br>' + info.package_slug + ' - ' + info.owner_name + ' (' + info.email + ')');
		modal.find('pre').text(JSON.stringify(info, null, 2));
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

		var licenseData;

		if ($(this).closest('tr').find('input[name="license_data[]"]').val()) {
			licenseData = JSON.parse($(this).closest('tr').find('input[name="license_data[]"]').val());
		} else {
			licenseData = {};
		}

		if (typeof licenseData !== 'object') {
			licenseData = {};
		}

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

	function validateForm(event) {
		event.preventDefault();

		const validators = {
			required: value => value.trim() !== '',
			email: value => /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/.test(value.trim()),
			slug: value => /^[a-z0-9-]+$/.test(value.trim()),
			licenseDate: value => /[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])/.test(value.trim()) || value.trim() === ''
		};

		function clearErrors() {
			event.target.querySelectorAll('.upserv-license-error').forEach(el => el.classList.remove('upserv-license-error'));
		}

		function showError(input) {
			input.classList.add('upserv-license-error');
		}

		let isValid = true;

		const fields = {
			licenseKey: { el: document.getElementById('upserv_license_key'), validate: 'required' },
			licensePackageSlug: { el: document.getElementById('upserv_license_package_slug'), validate: 'slug' },
			registeredEmail: { el: document.getElementById('upserv_license_registered_email'), validate: 'email' },
			dateCreated: { el: document.getElementById('upserv_license_date_created'), validate: ['required', 'licenseDate'] },
			dateExpiry: { el: document.getElementById('upserv_license_date_expiry'), validate: 'licenseDate' },
			dateRenewed: { el: document.getElementById('upserv_license_date_renewed'), validate: 'licenseDate' },
			maxAllowedDomains: { el: document.getElementById('upserv_license_max_allowed_domains'), validate: 'required' }
		};

		clearErrors();

		for (const [key, field] of Object.entries(fields)) {
			const value = field.el.value;
			const validations = Array.isArray(field.validate) ? field.validate : [field.validate];

			for (const validation of validations) {
				if (!validators[validation](value)) {
					isValid = false;
					showError(field.el);
					break;
				}
			}
		}

		if (isValid) {
			const values = {
				id: document.getElementById('upserv_license_id').innerHTML,
				license_key: fields.licenseKey.el.value,
				max_allowed_domains: fields.maxAllowedDomains.el.value,
				allowed_domains: Array.from(document.querySelectorAll('.upserv-domains-list li:not(.upserv-domain-template) .upserv-domain-value')).map(el => el.textContent),
				status: document.getElementById('upserv_license_status').value,
				owner_name: document.getElementById('upserv_license_owner_name').value,
				email: fields.registeredEmail.el.value,
				company_name: document.getElementById('upserv_license_owner_company').value,
				txn_id: document.getElementById('upserv_license_transaction_id').value,
				data: editor.codemirror.getValue(),
				date_created: fields.dateCreated.el.value,
				date_renewed: fields.dateRenewed.el.value,
				date_expiry: fields.dateExpiry.el.value,
				package_slug: fields.licensePackageSlug.el.value,
				package_type: document.getElementById('upserv_license_package_type').value
			};

			document.getElementById('upserv_license_values').value = JSON.stringify(values);
			document.querySelectorAll('.no-submit').forEach(el => el.removeAttribute('name'));
			event.target.submit();
		}
	}

	document.getElementById('upserv_license').addEventListener('submit', validateForm);

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

		if (licenseData && licenseData.constructor === Object) {
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
			$('#upserv_license_data').val(typeof licenseData.data === 'string' ? JSON.stringify(JSON.parse(licenseData.data), null, '\t') : '{}');
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