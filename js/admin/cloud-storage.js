jQuery(document).ready(function ($) {

	$('#upserv_use_cloud_storage').on('change', function (e) {

		if ($(this).prop('checked')) {
			$('.hide-if-no-cloud-storage').removeClass('hidden');
		} else {
			$('.hide-if-no-cloud-storage').addClass('hidden');
		}
	});

});