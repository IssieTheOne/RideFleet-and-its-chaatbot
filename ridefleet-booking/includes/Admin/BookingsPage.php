<?php
/**
 * Bookings admin page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Booking\BookingRepository;
use RideFleetBooking\Booking\CoreBookingPricingEngine;
use RideFleetBooking\Booking\QuoteCalculator;
use RideFleetBooking\Integrations\WooCommerce;

if (!defined('ABSPATH')) {
	exit;
}

final class BookingsPage {
	public static function render(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_bookings';
		if (!empty($_POST['rfb_booking_status_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_booking_status_nonce'])), 'rfb_update_booking_status')) {
			$booking_id = absint($_POST['booking_id'] ?? 0);
			$wpdb->update(
				$table,
				[
					'status' => sanitize_text_field($_POST['status'] ?? 'pending_payment'),
					'payment_status' => sanitize_text_field($_POST['payment_status'] ?? 'unpaid'),
					'driver_id' => absint($_POST['driver_id'] ?? 0) ?: null,
					'updated_at' => current_time('mysql'),
				],
				['id' => $booking_id],
				['%s', '%s', '%d', '%s'],
				['%d']
			);
			self::add_note($booking_id, __('Status, payment, or driver updated.', 'ridefleet-booking'));
		}

		if (!empty($_POST['rfb_booking_note_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_booking_note_nonce'])), 'rfb_add_booking_note')) {
			self::add_note(absint($_POST['booking_id'] ?? 0), sanitize_textarea_field($_POST['note'] ?? ''));
		}

		if (!empty($_POST['rfb_invoice_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_invoice_nonce'])), 'rfb_save_invoice_details')) {
			$booking_id = absint($_POST['booking_id'] ?? 0);
			if ($booking_id) {
				$invoice = [
					'enabled' => !empty($_POST['invoice_enabled']) ? 'yes' : 'no',
					'name' => sanitize_text_field((string) ($_POST['invoice_name'] ?? '')),
					'company' => sanitize_text_field((string) ($_POST['invoice_company'] ?? '')),
					'vat_number' => sanitize_text_field((string) ($_POST['invoice_vat_number'] ?? '')),
					'email' => sanitize_email((string) ($_POST['invoice_email'] ?? '')),
					'address' => sanitize_textarea_field((string) ($_POST['invoice_address'] ?? '')),
					'notes' => sanitize_textarea_field((string) ($_POST['invoice_notes'] ?? '')),
					'updated_at' => current_time('mysql'),
					'updated_by' => get_current_user_id(),
				];
				self::save_meta($booking_id, '_invoice_details', $invoice);
				self::add_note($booking_id, __('Invoice details saved for this booking.', 'ridefleet-booking'));
			}
		}

		if (!empty($_POST['rfb_booking_pay_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_booking_pay_nonce'])), 'rfb_booking_pay')) {
			$booking_id = absint($_POST['booking_id'] ?? 0);
			if ($booking_id) {
				$checkout_url = WooCommerce::admin_payment_url($booking_id);
				if (!$checkout_url) {
					$checkout_url = WooCommerce::checkout_url($booking_id);
				}
				if ($checkout_url) {
					self::add_note($booking_id, __('Payment checkout opened by admin.', 'ridefleet-booking'));
					wp_safe_redirect($checkout_url);
					exit;
				}
				self::add_note($booking_id, __('Payment link failed. Check WooCommerce settings.', 'ridefleet-booking'));
				wp_safe_redirect(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . $booking_id . '&rfb_pay_error=1'));
				exit;
			}
		}

		if (!empty($_POST['rfb_manual_booking_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_manual_booking_nonce'])), 'rfb_create_manual_booking')) {
			$payload = self::manual_payload();
			$pickup_at = trim(($payload['pickupDate'] ?? '') . ' ' . ($payload['pickupTime'] ?? ''));
			$quote = QuoteCalculator::calculate((float) $payload['distance'], (float) $payload['durationMinutes'], $payload['extras'], (int) $payload['vehicleId'], '', $pickup_at, 0);
			$core_quote = CoreBookingPricingEngine::quote_from_request(['pickup_address' => $payload['pickupAddress'], 'dropoff_address' => $payload['dropoffAddress']]);
			if (!empty($core_quote['success']) && isset($core_quote['final_price'])) {
				$addons = (float) ($quote['breakdown']['extras'] ?? 0) + (float) ($quote['breakdown']['vehicleAdjustment'] ?? 0);
				$quote['subtotal'] = round((float) $core_quote['final_price'] + $addons, 2);
				$quote['total'] = round((float) $core_quote['final_price'] + $addons, 2);
				$quote['breakdown']['pricingSource'] = $core_quote['pricing_source'] ?? 'core';
				$quote['breakdown']['zoneName'] = $core_quote['zone_name'] ?? '';
				$quote['breakdown']['coreFlatRateOverride'] = (float) $core_quote['final_price'];
			}
			$booking = BookingRepository::create($payload, $quote);
			self::add_note((int) $booking['id'], __('Manual booking created by admin.', 'ridefleet-booking'));
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . (int) $booking['id']));
			exit;
		}

		if (!empty($_GET['new'])) {
			self::render_manual_form();
			return;
		}

		if (!empty($_GET['booking_id'])) {
			self::render_detail(absint($_GET['booking_id']));
			return;
		}

		$where = [];
		$params = [];
		if (!empty($_GET['status'])) {
			$where[] = 'status = %s';
			$params[] = sanitize_text_field($_GET['status']);
		}
		if (!empty($_GET['payment_status'])) {
			$where[] = 'payment_status = %s';
			$params[] = sanitize_text_field($_GET['payment_status']);
		}
		if (!empty($_GET['date'])) {
			$where[] = 'DATE(pickup_at) = %s';
			$params[] = sanitize_text_field($_GET['date']);
		}
		if (!empty($_GET['s'])) {
			$where[] = '(booking_number LIKE %s OR pickup_address LIKE %s OR dropoff_address LIKE %s)';
			$search = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET['s']))) . '%';
			array_push($params, $search, $search, $search);
		}
		$per_page = max(10, min(100, absint($_GET['per_page'] ?? 10)));
		$page_num = max(1, absint($_GET['paged'] ?? 1));
		$offset = ($page_num - 1) * $per_page;
		$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$count_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
		$total_items = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
		$sql = "SELECT * FROM {$table}{$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge($params, [$per_page, $offset]);
		$bookings = $wpdb->get_results($wpdb->prepare($sql, $query_params));

		$status_counts = [];
		$counts_rows = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status");
		foreach ((array) $counts_rows as $row) {
			$status_counts[$row->status] = (int) $row->cnt;
		}
		$total_all = array_sum($status_counts);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Dispatch', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Bookings', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Track all ride reservations. Filter by status, payment, date, or route and open any booking for full dispatch details.', 'ridefleet-booking'); ?></p>
				</div>
				<div class="rfb-hero-actions">
					<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-calendar')); ?>"><?php esc_html_e('Open Calendar', 'ridefleet-booking'); ?></a>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&new=1')); ?>"><?php esc_html_e('New Booking', 'ridefleet-booking'); ?></a>
				</div>
			</div>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Total Bookings', 'ridefleet-booking'), number_format_i18n($total_all), __('All reservations across all statuses.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Confirmed', 'ridefleet-booking'), number_format_i18n($status_counts['confirmed'] ?? 0), __('Rides confirmed and ready for dispatch.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Pending', 'ridefleet-booking'), number_format_i18n(($status_counts['pending_payment'] ?? 0) + ($status_counts['pending_dispatch'] ?? 0)), __('Awaiting payment or dispatch confirmation.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Completed', 'ridefleet-booking'), number_format_i18n($status_counts['completed'] ?? 0), __('Successfully finished rides.', 'ridefleet-booking')); ?>
			</div>
						<form method="get" class="rfb-panel rfb-filter-bar rfb-filter-bar-labeled">
				<input type="hidden" name="page" value="ridefleet-bookings">
				<label>
					<span><?php esc_html_e('Status', 'ridefleet-booking'); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e('All statuses', 'ridefleet-booking'); ?></option>
						<?php foreach (['pending_payment', 'pending_dispatch', 'confirmed', 'completed', 'cancelled', 'refunded', 'failed'] as $status) : ?>
						<option value="<?php echo esc_attr($status); ?>" <?php selected($_GET['status'] ?? '', $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e('Payment', 'ridefleet-booking'); ?></span>
					<select name="payment_status">
						<option value=""><?php esc_html_e('All payments', 'ridefleet-booking'); ?></option>
						<?php foreach (['unpaid', 'paid', 'partially_paid', 'failed', 'refunded'] as $status) : ?>
						<option value="<?php echo esc_attr($status); ?>" <?php selected($_GET['payment_status'] ?? '', $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e('Date', 'ridefleet-booking'); ?></span>
					<input type="date" name="date" value="<?php echo esc_attr($_GET['date'] ?? ''); ?>">
				</label>
				<label class="rfb-filter-grow">
					<span><?php esc_html_e('Search', 'ridefleet-booking'); ?></span>
					<input type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" placeholder="<?php esc_attr_e('Booking number or address...', 'ridefleet-booking'); ?>">
				</label>
				<label>
					<span><?php esc_html_e('Per page', 'ridefleet-booking'); ?></span>
					<select name="per_page">
						<?php foreach ([10, 25, 50, 100] as $size) : ?>
						<option value="<?php echo esc_attr((string) $size); ?>" <?php selected($per_page, $size); ?>><?php echo esc_html(sprintf(__('%d / page', 'ridefleet-booking'), $size)); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="rfb-filter-bar-actions">
					<?php submit_button(__('Filter', 'ridefleet-booking'), 'primary', '', false); ?>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings')); ?>"><?php esc_html_e('Reset', 'ridefleet-booking'); ?></a>
				</div>
			</form>
			<div class="rfb-panel rfb-table-panel">
				<table class="widefat rfb-bookings-table">
					<thead>
						<tr>
							<th><?php esc_html_e('Booking', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Destination', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Status', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Payment', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Total', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Created', 'ridefleet-booking'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if (!$bookings) : ?>
							<tr><td colspan="7"><?php esc_html_e('No bookings yet. Use the frontend form or seed demo data from the dashboard.', 'ridefleet-booking'); ?></td></tr>
						<?php else : ?>
							<?php foreach ($bookings as $booking) : ?>
								<tr>
									<td><a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . (int) $booking->id)); ?>"><?php echo esc_html($booking->booking_number ?: '#' . $booking->id); ?></a></td>
									<td>
										<strong><?php echo esc_html($booking->pickup_at ?: '-'); ?></strong>
										<span><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::maps_url($booking->pickup_address, $booking->dropoff_address)); ?>"><?php echo esc_html(wp_trim_words($booking->pickup_address, 8)); ?></a></span>
									</td>
									<td><a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::maps_url($booking->pickup_address, $booking->dropoff_address)); ?>"><?php echo esc_html(wp_trim_words($booking->dropoff_address, 8)); ?></a></td>
									<td><?php self::badge($booking->status); ?></td>
									<td><?php self::badge($booking->payment_status); ?></td>
									<td><strong><?php echo esc_html($booking->currency . ' ' . number_format_i18n((float) $booking->total, 2)); ?></strong></td>
									<td><?php echo esc_html($booking->created_at); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php self::pagination($total_items, $per_page, $page_num); ?>
		</div>
		<?php
	}

	private static function render_manual_form(): void {
		$vehicles = get_posts(['post_type' => 'rfb_vehicle', 'post_status' => 'publish', 'numberposts' => 100]);
		$extras = get_posts(['post_type' => 'rfb_extra', 'post_status' => 'publish', 'numberposts' => 100]);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-page-title">
				<div><p class="rfb-kicker"><?php esc_html_e('Manual Dispatch', 'ridefleet-booking'); ?></p><h1><?php esc_html_e('Create Booking', 'ridefleet-booking'); ?></h1></div>
				<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings')); ?>"><?php esc_html_e('Back to bookings', 'ridefleet-booking'); ?></a>
			</div>
			<form method="post" class="rfb-panel rfb-manual-booking" data-rfb-route-builder>
				<?php wp_nonce_field('rfb_create_manual_booking', 'rfb_manual_booking_nonce'); ?>
				<div class="rfb-settings-grid">
					<section class="rfb-manual-step">
						<p class="rfb-step-kicker"><?php esc_html_e('Step 1', 'ridefleet-booking'); ?></p>
						<h2><?php esc_html_e('Route', 'ridefleet-booking'); ?></h2>
						<label><span><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></span><input type="text" name="pickupAddress" data-rfb-place-search data-rfb-target-lat="manual_pickup_lat" data-rfb-target-lng="manual_pickup_lng" required></label>
						<input type="hidden" name="manual_pickup_lat"><input type="hidden" name="manual_pickup_lng">
						<label><span><?php esc_html_e('Destination', 'ridefleet-booking'); ?></span><input type="text" name="dropoffAddress" data-rfb-place-search data-rfb-target-lat="manual_dropoff_lat" data-rfb-target-lng="manual_dropoff_lng" required></label>
						<input type="hidden" name="manual_dropoff_lat"><input type="hidden" name="manual_dropoff_lng">
						<label><span><?php esc_html_e('Date', 'ridefleet-booking'); ?></span><input type="date" name="pickupDate" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required></label>
						<label><span><?php esc_html_e('Time', 'ridefleet-booking'); ?></span><input type="time" name="pickupTime" value="<?php echo esc_attr(wp_date('H:i')); ?>" required></label>
					</section>
					<section class="rfb-manual-step">
						<p class="rfb-step-kicker"><?php esc_html_e('Step 3', 'ridefleet-booking'); ?></p>
						<h2><?php esc_html_e('Customer', 'ridefleet-booking'); ?></h2>
						<!-- Customer search typeahead -->
						<div class="rfb-customer-search-wrap" data-rfb-customer-search>
							<label>
								<span><?php esc_html_e('Search existing customer', 'ridefleet-booking'); ?></span>
								<input type="search" placeholder="<?php esc_attr_e('Type name, email or phone…', 'ridefleet-booking'); ?>" autocomplete="off" class="rfb-customer-search-input">
							</label>
							<ul class="rfb-customer-search-dropdown" hidden></ul>
							<p class="rfb-customer-search-hint" style="margin:4px 0 12px;font-size:12px;color:#94a3b8;">
								<?php esc_html_e('Select a customer to pre-fill the fields below, or enter a new one manually.', 'ridefleet-booking'); ?>
							</p>
						</div>
						<label><span><?php esc_html_e('First name', 'ridefleet-booking'); ?></span><input type="text" name="customerFirstName" required></label>
						<label><span><?php esc_html_e('Last name', 'ridefleet-booking'); ?></span><input type="text" name="customerLastName" required></label>
						<label><span><?php esc_html_e('Email', 'ridefleet-booking'); ?></span><input type="email" name="customerEmail"></label>
						<label><span><?php esc_html_e('Phone', 'ridefleet-booking'); ?></span><input type="text" name="customerPhone" required></label>
					</section>
					<section class="rfb-manual-step">
						<p class="rfb-step-kicker"><?php esc_html_e('Step 2', 'ridefleet-booking'); ?></p>
						<h2><?php esc_html_e('Quote Preview', 'ridefleet-booking'); ?></h2>
						<label><span><?php esc_html_e('Distance', 'ridefleet-booking'); ?></span><input type="number" name="distance" min="0" step="0.1" required></label>
						<label><span><?php esc_html_e('Minutes', 'ridefleet-booking'); ?></span><input type="number" name="durationMinutes" min="0" step="1" required></label>
						<label><span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span><input type="number" name="passengers" min="1" value="1"></label>
						<label><span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span><input type="number" name="luggage" min="0" value="0"></label>
						<div class="rfb-route-preview">
							<div class="rfb-admin-map" data-rfb-route-map></div>
							<!-- Live flat-rate preview -->
							<div class="rfb-price-preview" data-rfb-price-preview hidden>
								<div class="rfb-price-preview-amount" data-rfb-price-amount>—</div>
								<div class="rfb-price-preview-label" data-rfb-price-label><?php esc_html_e('Estimated price', 'ridefleet-booking'); ?></div>
							</div>
							<p style="margin-top:8px;"><?php esc_html_e('Google fills distance & minutes automatically. Price preview shows once both addresses are set. Core flat-rate rules always apply on submit.', 'ridefleet-booking'); ?></p>
						</div>
					</section>
					<section class="rfb-manual-step">
						<p class="rfb-step-kicker"><?php esc_html_e('Step 4', 'ridefleet-booking'); ?></p>
						<h2><?php esc_html_e('Vehicle, Extras and Notes', 'ridefleet-booking'); ?></h2>
						<label><span><?php esc_html_e('Vehicle', 'ridefleet-booking'); ?></span><select name="vehicleId"><?php foreach ($vehicles as $vehicle) : ?><option value="<?php echo esc_attr((string) $vehicle->ID); ?>"><?php echo esc_html(get_the_title($vehicle)); ?></option><?php endforeach; ?></select></label>
						<?php if ($extras) : ?>
							<div class="rfb-extra-checklist"><strong><?php esc_html_e('Extras', 'ridefleet-booking'); ?></strong><?php foreach ($extras as $extra) : ?><label><input type="checkbox" name="extras[]" value="<?php echo esc_attr((string) $extra->ID); ?>"> <?php echo esc_html(get_the_title($extra)); ?> <small><?php echo esc_html(get_post_meta($extra->ID, 'rfb_price', true)); ?></small></label><?php endforeach; ?></div>
						<?php endif; ?>
						<label><span><?php esc_html_e('Internal note', 'ridefleet-booking'); ?></span><textarea name="note" rows="5"></textarea></label>
					</section>
				</div>
				<?php submit_button(__('Create Booking', 'ridefleet-booking')); ?>
			</form>
		</div>
		<?php
	}

	private static function manual_payload(): array {
		return [
			'serviceType' => 'distance',
			'transferType' => 'one_way',
			'pickupAddress' => sanitize_textarea_field($_POST['pickupAddress'] ?? ''),
			'dropoffAddress' => sanitize_textarea_field($_POST['dropoffAddress'] ?? ''),
			'pickupDate' => sanitize_text_field($_POST['pickupDate'] ?? ''),
			'pickupTime' => sanitize_text_field($_POST['pickupTime'] ?? ''),
			'distance' => max(0, (float) ($_POST['distance'] ?? 0)),
			'durationMinutes' => max(0, (float) ($_POST['durationMinutes'] ?? 0)),
			'passengers' => max(1, absint($_POST['passengers'] ?? 1)),
			'luggage' => max(0, absint($_POST['luggage'] ?? 0)),
			'vehicleId' => absint($_POST['vehicleId'] ?? 0),
			'routeId' => 0,
			'extras' => array_map(static fn($id): array => ['id' => absint($id), 'quantity' => 1], (array) ($_POST['extras'] ?? [])),
			'couponCode' => '',
			'customerFirstName' => sanitize_text_field($_POST['customerFirstName'] ?? ''),
			'customerLastName' => sanitize_text_field($_POST['customerLastName'] ?? ''),
			'customerEmail' => sanitize_email($_POST['customerEmail'] ?? ''),
			'customerPhone' => sanitize_text_field($_POST['customerPhone'] ?? ''),
			'note' => sanitize_textarea_field($_POST['note'] ?? ''),
		];
	}

	private static function render_detail(int $booking_id): void {
		global $wpdb;

		$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_bookings WHERE id = %d", $booking_id));
		if (!$booking) {
			echo '<div class="wrap"><h1>' . esc_html__('Booking not found', 'ridefleet-booking') . '</h1></div>';
			return;
		}

		$customer = $booking->customer_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_customers WHERE id = %d", (int) $booking->customer_id)) : null;
		$drivers = get_posts(['post_type' => 'rfb_driver', 'post_status' => 'publish', 'numberposts' => 100]);
		$notes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_booking_meta WHERE booking_id = %d AND meta_key = '_internal_note' ORDER BY id DESC LIMIT 25", $booking_id));
		$change_requests = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_booking_meta WHERE booking_id = %d AND meta_key = '_change_request' ORDER BY id DESC LIMIT 10", $booking_id));
		$invoice = self::get_meta($booking_id, '_invoice_details', []);
		$settings = get_option('rfb_settings', []);
		$can_pay = is_array($settings) && 'yes' === ($settings['woocommerce_checkout_enabled'] ?? 'no');
		?>
		<div class="wrap rfb-admin rfb-booking-detail-page">
			<?php if (!empty($_GET['rfb_pay_error'])) : ?>
				<div class="notice notice-error"><p><?php esc_html_e('Could not generate payment checkout link. Verify WooCommerce is active and checkout is enabled.', 'ridefleet-booking'); ?></p></div>
			<?php endif; ?>
			<div class="rfb-booking-hero">
				<div class="rfb-booking-hero-header">
					<div>
						<p class="rfb-kicker"><?php esc_html_e('Booking Detail', 'ridefleet-booking'); ?></p>
						<h1><?php echo esc_html($booking->booking_number); ?></h1>
						<div class="rfb-booking-hero-badges">
							<?php self::badge($booking->status); ?>
							<?php self::badge($booking->payment_status); ?>
						</div>
					</div>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings')); ?>"><?php esc_html_e('Back to bookings', 'ridefleet-booking'); ?></a>
				</div>
				<div class="rfb-booking-hero-data">
					<div>
						<span><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></span>
						<strong><?php echo esc_html($booking->pickup_at ?: __('Not scheduled', 'ridefleet-booking')); ?></strong>
						<p><?php echo esc_html($booking->pickup_address); ?></p>
					</div>
					<div>
						<span><?php esc_html_e('Destination', 'ridefleet-booking'); ?></span>
						<strong><?php echo esc_html(wp_trim_words((string) $booking->dropoff_address, 8)); ?></strong>
						<p><?php echo esc_html($booking->distance_value . ' ' . $booking->distance_unit . ' - ' . round(((int) $booking->duration_seconds) / 60) . ' min'); ?></p>
					</div>
					<div>
						<span><?php esc_html_e('Total', 'ridefleet-booking'); ?></span>
						<strong><?php echo esc_html($booking->currency . ' ' . number_format_i18n((float) $booking->total, 2)); ?></strong>
					</div>
					<div class="rfb-booking-hero-actions">
						<?php if ($can_pay && in_array($booking->payment_status, ['unpaid', 'failed', 'partially_paid'], true)) : ?>
							<form method="post" class="rfb-pay-form">
								<?php wp_nonce_field('rfb_booking_pay', 'rfb_booking_pay_nonce'); ?>
								<input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $booking->id); ?>">
								<?php submit_button(__('Pay Now', 'ridefleet-booking'), 'primary', '', false); ?>
							</form>
						<?php endif; ?>
						<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::maps_url($booking->pickup_address, $booking->dropoff_address)); ?>"><?php esc_html_e('Open in Maps', 'ridefleet-booking'); ?></a>
					</div>
				</div>
			</div>
			<div class="rfb-workflow">
				<div class="<?php echo esc_attr(in_array($booking->status, ['pending_payment', 'pending_dispatch', 'confirmed', 'completed'], true) ? 'is-active' : ''); ?>">
					<strong>1</strong><span><?php esc_html_e('Request received', 'ridefleet-booking'); ?></span>
				</div>
				<div class="<?php echo esc_attr(in_array($booking->status, ['confirmed', 'completed'], true) ? 'is-active' : ''); ?>">
					<strong>2</strong><span><?php esc_html_e('Confirm ride', 'ridefleet-booking'); ?></span>
				</div>
				<div class="<?php echo esc_attr($booking->driver_id ? 'is-active' : ''); ?>">
					<strong>3</strong><span><?php esc_html_e('Assign driver', 'ridefleet-booking'); ?></span>
				</div>
				<div class="<?php echo esc_attr('completed' === $booking->status ? 'is-active' : ''); ?>">
					<strong>4</strong><span><?php esc_html_e('Complete &amp; settle', 'ridefleet-booking'); ?></span>
				</div>
			</div>
			<div class="rfb-booking-detail-grid">

				<section class="rfb-panel rfb-detail-card rfb-detail-card-route">
					<h2><?php esc_html_e('Ride Details', 'ridefleet-booking'); ?></h2>
					<dl class="rfb-detail-list">
						<dt><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></dt>
						<dd><?php echo esc_html($booking->pickup_address); ?></dd>
						<dt><?php esc_html_e('Drop-off', 'ridefleet-booking'); ?></dt>
						<dd><?php echo esc_html($booking->dropoff_address); ?></dd>
						<dt><?php esc_html_e('Pickup time', 'ridefleet-booking'); ?></dt>
						<dd><?php echo esc_html($booking->pickup_at ?: __('Not scheduled', 'ridefleet-booking')); ?></dd>
						<dt><?php esc_html_e('Distance', 'ridefleet-booking'); ?></dt>
						<dd><?php echo esc_html($booking->distance_value . ' ' . $booking->distance_unit . ' - ' . round(((int) $booking->duration_seconds) / 60) . ' min'); ?></dd>
						<dt><?php esc_html_e('Total', 'ridefleet-booking'); ?></dt>
						<dd><strong><?php echo esc_html($booking->currency . ' ' . number_format_i18n((float) $booking->total, 2)); ?></strong></dd>
						<?php if ($booking->woocommerce_order_id) : ?>
							<dt><?php esc_html_e('WooCommerce', 'ridefleet-booking'); ?></dt>
							<dd><a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $booking->woocommerce_order_id . '&action=edit')); ?>">#<?php echo esc_html((string) $booking->woocommerce_order_id); ?></a></dd>
						<?php endif; ?>
					</dl>
					<div class="rfb-card-footer">
						<a class="button rfb-card-footer-btn" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::maps_url($booking->pickup_address, $booking->dropoff_address)); ?>"><?php esc_html_e('Open route in Google Maps', 'ridefleet-booking'); ?></a>
					</div>
				</section>

				<section class="rfb-panel rfb-detail-card rfb-detail-card-dispatch">
					<h2><?php esc_html_e('Dispatch', 'ridefleet-booking'); ?></h2>
					<form method="post" class="rfb-dispatch-form">
						<?php wp_nonce_field('rfb_update_booking_status', 'rfb_booking_status_nonce'); ?>
						<input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $booking->id); ?>">
						<div class="rfb-dispatch-fields">
							<label><span><?php esc_html_e('Booking Status', 'ridefleet-booking'); ?></span><select name="status">
								<?php foreach (['pending_payment', 'pending_dispatch', 'confirmed', 'completed', 'cancelled', 'refunded', 'failed'] as $status) : ?>
									<option value="<?php echo esc_attr($status); ?>" <?php selected($booking->status, $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
								<?php endforeach; ?>
							</select></label>
							<label><span><?php esc_html_e('Payment Status', 'ridefleet-booking'); ?></span><select name="payment_status">
								<?php foreach (['unpaid', 'paid', 'partially_paid', 'failed', 'refunded'] as $status) : ?>
									<option value="<?php echo esc_attr($status); ?>" <?php selected($booking->payment_status, $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
								<?php endforeach; ?>
							</select></label>
						</div>
						<label><span><?php esc_html_e('Driver', 'ridefleet-booking'); ?></span><select name="driver_id">
							<option value="0"><?php esc_html_e('Unassigned', 'ridefleet-booking'); ?></option>
							<?php foreach ($drivers as $driver) : ?>
								<option value="<?php echo esc_attr((string) $driver->ID); ?>" <?php selected((int) $booking->driver_id, (int) $driver->ID); ?>><?php echo esc_html(get_the_title($driver)); ?></option>
							<?php endforeach; ?>
						</select></label>
						<div class="rfb-card-footer">
							<button type="submit" class="button button-primary rfb-card-footer-btn"><?php esc_html_e('Update Booking', 'ridefleet-booking'); ?></button>
						</div>
					</form>
				</section>

				<section class="rfb-panel rfb-detail-card rfb-detail-card-customer">
					<h2><?php esc_html_e('Customer', 'ridefleet-booking'); ?></h2>
					<?php if ($customer) : ?>
						<div class="rfb-customer-body">
							<div class="rfb-customer-avatar"><?php echo esc_html(strtoupper(mb_substr(trim($customer->first_name ?: $customer->email), 0, 1))); ?></div>
							<div class="rfb-customer-meta">
								<strong><?php echo esc_html(trim($customer->first_name . ' ' . $customer->last_name)); ?></strong>
								<a href="mailto:<?php echo esc_attr($customer->email); ?>"><?php echo esc_html($customer->email); ?></a>
								<?php if ($customer->phone) : ?>
									<a href="tel:<?php echo esc_attr($customer->phone); ?>"><?php echo esc_html($customer->phone); ?></a>
								<?php endif; ?>
								<span class="rfb-customer-bookings"><?php echo esc_html(sprintf(_n('%d booking', '%d bookings', (int) $customer->total_bookings, 'ridefleet-booking'), (int) $customer->total_bookings)); ?></span>
							</div>
						</div>
						<div class="rfb-card-footer">
							<a class="button rfb-card-footer-btn" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-customers&customer_id=' . (int) $customer->id)); ?>"><?php esc_html_e('View customer profile', 'ridefleet-booking'); ?></a>
						</div>
					<?php else : ?>
						<p class="rfb-muted-note"><?php esc_html_e('No customer record linked to this booking.', 'ridefleet-booking'); ?></p>
					<?php endif; ?>
				</section>

				<section class="rfb-panel rfb-detail-card rfb-detail-card-notes">
					<h2><?php esc_html_e('Internal Notes', 'ridefleet-booking'); ?></h2>
					<div class="rfb-notes-layout">
						<form method="post" class="rfb-notes-form">
							<?php wp_nonce_field('rfb_add_booking_note', 'rfb_booking_note_nonce'); ?>
							<input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $booking->id); ?>">
							<textarea name="note" rows="3" placeholder="<?php esc_attr_e('Type your note here...', 'ridefleet-booking'); ?>"></textarea>
							<button type="submit" class="button button-secondary rfb-notes-submit"><?php esc_html_e('Add Note', 'ridefleet-booking'); ?></button>
						</form>
						<div class="rfb-notes-history">
							<?php if (!$notes) : ?>
								<p class="rfb-muted-note"><?php esc_html_e('No notes yet. Add one to keep track of anything about this booking.', 'ridefleet-booking'); ?></p>
							<?php else : ?>
								<div class="rfb-timeline">
									<?php foreach ($notes as $note) : ?>
										<?php $item = self::parse_note((string) $note->meta_value, (int) $note->id); ?>
										<div><strong><?php echo esc_html($item['label']); ?></strong><span><?php echo esc_html($item['message']); ?></span></div>
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</section>

				<section class="rfb-panel rfb-detail-card rfb-invoice-panel">
					<details <?php echo ('yes' === ($invoice['enabled'] ?? '')) ? 'open' : ''; ?>>
						<summary>
							<div class="rfb-invoice-summary-text">
								<strong><?php esc_html_e('Tax / VAT Invoice', 'ridefleet-booking'); ?></strong>
								<span><?php esc_html_e('Click to expand and fill in billing details.', 'ridefleet-booking'); ?></span>
							</div>
							<em class="<?php echo ('yes' === ($invoice['enabled'] ?? '')) ? 'is-requested' : ''; ?>"><?php echo esc_html(('yes' === ($invoice['enabled'] ?? '')) ? __('Requested', 'ridefleet-booking') : __('Not needed', 'ridefleet-booking')); ?></em>
						</summary>
						<form method="post" class="rfb-invoice-form">
							<?php wp_nonce_field('rfb_save_invoice_details', 'rfb_invoice_nonce'); ?>
							<input type="hidden" name="booking_id" value="<?php echo esc_attr((string) $booking->id); ?>">
							<div class="rfb-invoice-fields">
								<label><span><?php esc_html_e('Bill To / Name', 'ridefleet-booking'); ?></span><input type="text" name="invoice_name" value="<?php echo esc_attr((string) ($invoice['name'] ?? '')); ?>"></label>
								<label><span><?php esc_html_e('Company', 'ridefleet-booking'); ?></span><input type="text" name="invoice_company" value="<?php echo esc_attr((string) ($invoice['company'] ?? '')); ?>"></label>
								<label><span><?php esc_html_e('Tax / VAT Number', 'ridefleet-booking'); ?></span><input type="text" name="invoice_vat_number" value="<?php echo esc_attr((string) ($invoice['vat_number'] ?? '')); ?>"></label>
								<label><span><?php esc_html_e('Invoice Email', 'ridefleet-booking'); ?></span><input type="email" name="invoice_email" value="<?php echo esc_attr((string) ($invoice['email'] ?? ($customer->email ?? ''))); ?>"></label>
							</div>
							<label><span><?php esc_html_e('Billing Address', 'ridefleet-booking'); ?></span><textarea name="invoice_address" rows="3"><?php echo esc_textarea((string) ($invoice['address'] ?? '')); ?></textarea></label>
							<label><span><?php esc_html_e('Notes', 'ridefleet-booking'); ?></span><textarea name="invoice_notes" rows="2"><?php echo esc_textarea((string) ($invoice['notes'] ?? '')); ?></textarea></label>
							<div class="rfb-card-footer">
								<button type="submit" class="button rfb-card-footer-btn"><?php esc_html_e('Save Invoice Details', 'ridefleet-booking'); ?></button>
							</div>
						</form>
					</details>
				</section>

				<?php if ($change_requests) : ?>
					<section class="rfb-panel rfb-detail-card rfb-change-request-panel">
						<h2><?php esc_html_e('Change Requests', 'ridefleet-booking'); ?></h2>
						<p><?php esc_html_e('These requests need admin approval before the original booking is changed.', 'ridefleet-booking'); ?></p>
						<div class="rfb-timeline">
							<?php foreach ($change_requests as $request_row) : ?>
								<?php $change_request = json_decode((string) $request_row->meta_value, true); ?>
								<?php if (!is_array($change_request)) { continue; } ?>
								<div>
									<strong><?php echo esc_html(($change_request['requested_at'] ?? '') . ' / ' . ($change_request['requested_by'] ?? __('Unknown requester', 'ridefleet-booking'))); ?></strong>
									<?php foreach ((array) ($change_request['changes'] ?? []) as $field => $value) : ?>
										<span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $field)) . ': ' . (is_scalar($value) ? (string) $value : wp_json_encode($value))); ?></span>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

			</div>
		</div>
		<?php
	}

	private static function badge(string $status): void {
		$class = sanitize_html_class('rfb-badge-' . $status);
		echo '<span class="rfb-badge ' . esc_attr($class) . '">' . esc_html(ucwords(str_replace('_', ' ', $status))) . '</span>';
	}

	private static function maps_url(string $origin, string $destination): string {
		return add_query_arg(
			[
				'api' => '1',
				'origin' => $origin,
				'destination' => $destination,
				'travelmode' => 'driving',
			],
			'https://www.google.com/maps/dir/'
		);
	}

	private static function add_note(int $booking_id, string $note): void {
		if (!$booking_id || '' === trim($note)) {
			return;
		}

		$current_user = wp_get_current_user();
		$display_name = $current_user && $current_user->exists() ? $current_user->display_name : __('System', 'ridefleet-booking');
		$user_id = $current_user && $current_user->exists() ? (int) $current_user->ID : 0;

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'rfb_booking_meta',
			[
				'booking_id' => $booking_id,
				'meta_key' => '_internal_note',
				'meta_value' => wp_json_encode(
					[
						'ts' => current_time('mysql'),
						'user_id' => $user_id,
						'user_name' => $display_name,
						'message' => $note,
					]
				),
			],
			['%d', '%s', '%s']
		);
	}

	private static function save_meta(int $booking_id, string $key, mixed $value): void {
		global $wpdb;

		$wpdb->delete($wpdb->prefix . 'rfb_booking_meta', ['booking_id' => $booking_id, 'meta_key' => $key], ['%d', '%s']);
		$wpdb->insert(
			$wpdb->prefix . 'rfb_booking_meta',
			[
				'booking_id' => $booking_id,
				'meta_key' => $key,
				'meta_value' => is_scalar($value) ? (string) $value : wp_json_encode($value),
			],
			['%d', '%s', '%s']
		);
	}

	private static function get_meta(int $booking_id, string $key, mixed $default = null): mixed {
		global $wpdb;

		$raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}rfb_booking_meta WHERE booking_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1", $booking_id, $key));
		if (null === $raw) {
			return $default;
		}

		$decoded = json_decode((string) $raw, true);
		return is_array($decoded) ? $decoded : $raw;
	}

	private static function parse_note(string $raw, int $fallback_id): array {
		$decoded = json_decode($raw, true);
		if (is_array($decoded) && !empty($decoded['message'])) {
			$ts = sanitize_text_field((string) ($decoded['ts'] ?? ''));
			$user = sanitize_text_field((string) ($decoded['user_name'] ?? __('Unknown', 'ridefleet-booking')));
			$label = trim($ts . ' / ' . $user);
			return [
				'label' => $label ?: ('#' . $fallback_id),
				'message' => sanitize_textarea_field((string) $decoded['message']),
			];
		}

		return [
			'label' => '#' . $fallback_id,
			'message' => sanitize_textarea_field($raw),
		];
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	public static function customer_search_ajax(): void {
		check_ajax_referer('rfb_admin_ajax', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Forbidden', 403);
		}
		global $wpdb;
		$q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
		if (strlen($q) < 2) {
			wp_send_json_success([]);
		}
		$like = '%' . $wpdb->esc_like($q) . '%';
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT id, first_name, last_name, email, phone FROM {$wpdb->prefix}rfb_customers
			 WHERE (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)
			 ORDER BY id DESC LIMIT 10",
			$like, $like, $like, $like
		));
		$results = [];
		foreach ((array) $rows as $row) {
			$results[] = [
				'id'         => (int) $row->id,
				'label'      => trim($row->first_name . ' ' . $row->last_name),
				'email'      => (string) $row->email,
				'phone'      => (string) $row->phone,
				'first_name' => (string) $row->first_name,
				'last_name'  => (string) $row->last_name,
			];
		}
		wp_send_json_success($results);
	}

	public static function quote_preview_ajax(): void {
		check_ajax_referer('rfb_admin_ajax', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error('Forbidden', 403);
		}
		$pickup    = sanitize_textarea_field(wp_unslash($_POST['pickup'] ?? ''));
		$dropoff   = sanitize_textarea_field(wp_unslash($_POST['dropoff'] ?? ''));
		$distance  = max(0, (float) ($_POST['distance'] ?? 0));
		$duration  = max(0, (float) ($_POST['duration'] ?? 0));
		$pickup_at = sanitize_text_field(wp_unslash($_POST['pickup_at'] ?? ''));

		if ('' === $pickup || '' === $dropoff) {
			wp_send_json_error('Missing addresses');
		}

		// Try core flat-rate rules first (route-based pricing)
		$core = \RideFleetBooking\Booking\CoreBookingPricingEngine::quote_from_request([
			'pickup_address'  => $pickup,
			'dropoff_address' => $dropoff,
		]);

		if (!empty($core['success']) && isset($core['final_price'])) {
			wp_send_json_success([
				'price'          => (float) $core['final_price'],
				'currency'       => (string) \RideFleetBooking\Support\Options::get('currency', 'USD'),
				'pricing_source' => (string) ($core['pricing_source'] ?? 'flat_rate'),
				'zone_name'      => (string) ($core['zone_name'] ?? ''),
			]);
		}

		// Fallback: distance-based meter estimate
		if ($distance > 0) {
			$quote = \RideFleetBooking\Booking\QuoteCalculator::calculate($distance, $duration, [], 0, '', $pickup_at, 0);
			wp_send_json_success([
				'price'          => (float) ($quote['total'] ?? 0),
				'currency'       => (string) \RideFleetBooking\Support\Options::get('currency', 'USD'),
				'pricing_source' => 'meter',
				'zone_name'      => '',
			]);
		}

		wp_send_json_error('Could not calculate price. Fill in distance first.');
	}

	private static function pagination(int $total_items, int $per_page, int $page_num): void {
		$total_pages = max(1, (int) ceil($total_items / $per_page));
		if ($total_pages <= 1) {
			return;
		}
		$base_args = $_GET;
		unset($base_args['paged']);
		?>
		<div class="rfb-pagination">
			<span><?php echo esc_html(sprintf(__('%1$d bookings - Page %2$d of %3$d', 'ridefleet-booking'), $total_items, $page_num, $total_pages)); ?></span>
			<div>
				<?php if ($page_num > 1) : ?>
					<a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $page_num - 1]), admin_url('admin.php'))); ?>"><?php esc_html_e('Previous', 'ridefleet-booking'); ?></a>
				<?php endif; ?>
				<?php if ($page_num < $total_pages) : ?>
					<a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $page_num + 1]), admin_url('admin.php'))); ?>"><?php esc_html_e('Next', 'ridefleet-booking'); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
