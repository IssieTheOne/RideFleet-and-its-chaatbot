(function () {
	'use strict';

	function hideThemeOrderSections() {
		document.querySelectorAll(
			'.rfb-payment-summary ~ .avada-order-details,' +
			'.rfb-payment-summary ~ .avada-customer-details,' +
			'.rfb-payment-summary ~ .woocommerce-order-details,' +
			'.rfb-payment-summary ~ .woocommerce-customer-details,' +
			'.woocommerce-order-received .avada-order-details,' +
			'.woocommerce-order-received .avada-customer-details'
		).forEach(function (section) {
			section.setAttribute('hidden', 'hidden');
			section.style.display = 'none';
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', hideThemeOrderSections);
	} else {
		hideThemeOrderSections();
	}

	window.setTimeout(hideThemeOrderSections, 250);
	window.setTimeout(hideThemeOrderSections, 1000);

	if ('MutationObserver' in window) {
		new MutationObserver(hideThemeOrderSections).observe(document.documentElement, {
			childList: true,
			subtree: true
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-rfb-invoice-request]');
		if (!button || button.disabled) {
			return;
		}

		var data = new FormData();
		data.append('action', 'rfb_request_tax_invoice');
		data.append('booking_id', button.getAttribute('data-rfb-booking-id') || '');
		data.append('nonce', button.getAttribute('data-rfb-invoice-request') || '');

		button.disabled = true;
		button.classList.add('is-loading');

		fetch((window.RideFleetWooRide && RideFleetWooRide.ajaxUrl) || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: data
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error('request_failed');
				}
				button.classList.remove('is-loading');
				button.classList.add('is-complete');
				button.textContent = (window.RideFleetWooRide && RideFleetWooRide.requestedLabel) || 'Invoice requested';
			})
			.catch(function () {
				button.disabled = false;
				button.classList.remove('is-loading');
				button.textContent = (window.RideFleetWooRide && RideFleetWooRide.errorMessage) || 'Could not send request';
			});
	});
})();
