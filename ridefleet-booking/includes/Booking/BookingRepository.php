<?php
/**
 * Booking persistence service.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class BookingRepository {
	public static function create(array $payload, array $quote): array {
		global $wpdb;

		$customer_id = self::find_or_create_customer($payload);
		$booking_number = self::next_booking_number();
		$now = current_time('mysql');
		$table = $wpdb->prefix . 'rfb_bookings';

		$wpdb->insert(
			$table,
			[
				'booking_number' => $booking_number,
				'customer_id' => $customer_id,
				'wp_user_id' => get_current_user_id() ?: null,
				'status' => 'pending_payment',
				'payment_status' => 'unpaid',
				'service_type' => sanitize_text_field($payload['serviceType'] ?? 'distance'),
				'transfer_type' => sanitize_text_field($payload['transferType'] ?? 'one_way'),
				'pickup_address' => sanitize_textarea_field($payload['pickupAddress'] ?? ''),
				'dropoff_address' => sanitize_textarea_field($payload['dropoffAddress'] ?? ''),
				'waypoints' => wp_json_encode($payload['waypoints'] ?? []),
				'pickup_at' => self::pickup_datetime($payload),
				'distance_value' => max(0, (float) ($payload['distance'] ?? 0)),
				'distance_unit' => Options::get('distance_unit', 'km'),
				'duration_seconds' => max(0, (int) round(((float) ($payload['durationMinutes'] ?? 0)) * 60)),
				'passengers' => max(1, absint($payload['passengers'] ?? 1)),
				'luggage' => max(0, absint($payload['luggage'] ?? 0)),
				'vehicle_id' => absint($payload['vehicleId'] ?? 0) ?: null,
				'subtotal' => (float) ($quote['subtotal'] ?? 0),
				'tax_total' => (float) ($quote['taxTotal'] ?? 0),
				'discount_total' => (float) ($quote['discountTotal'] ?? 0),
				'total' => (float) ($quote['total'] ?? 0),
				'currency' => Options::get('currency', 'USD'),
				'source' => sanitize_text_field($payload['source'] ?? 'frontend'),
				'created_at' => $now,
				'updated_at' => $now,
			],
			['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s']
		);

		$booking_id = (int) $wpdb->insert_id;
		self::save_meta($booking_id, '_customer_note', sanitize_textarea_field($payload['note'] ?? ''));
		self::save_meta($booking_id, '_quote_snapshot', $quote);
		self::save_meta($booking_id, '_selected_extras', $payload['extras'] ?? []);
		self::save_meta($booking_id, '_route_id', absint($payload['routeId'] ?? 0));
		self::save_meta($booking_id, '_coupon_code', sanitize_text_field($payload['couponCode'] ?? ''));
		if (!empty($payload['couponCode']) && !empty($quote['coupon']['valid'])) {
			CouponService::mark_used((string) $payload['couponCode']);
		}
		self::update_customer_totals($customer_id);

		return [
			'id' => $booking_id,
			'bookingNumber' => $booking_number,
			'status' => 'pending_payment',
			'paymentStatus' => 'unpaid',
		];
	}

	public static function find(int $booking_id): ?object {
		global $wpdb;
		$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_bookings WHERE id = %d", $booking_id));

		return $booking ?: null;
	}

	public static function customer(int $customer_id): ?object {
		global $wpdb;
		$customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_customers WHERE id = %d", $customer_id));

		return $customer ?: null;
	}

	public static function update_status(int $booking_id, string $status, string $payment_status = ''): void {
		global $wpdb;
		$data = ['status' => sanitize_text_field($status), 'updated_at' => current_time('mysql')];
		$format = ['%s', '%s'];

		if ($payment_status) {
			$data['payment_status'] = sanitize_text_field($payment_status);
			$format[] = '%s';
		}

		$wpdb->update($wpdb->prefix . 'rfb_bookings', $data, ['id' => $booking_id], $format, ['%d']);
	}

	public static function attach_order(int $booking_id, int $order_id): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'rfb_bookings',
			['woocommerce_order_id' => $order_id, 'updated_at' => current_time('mysql')],
			['id' => $booking_id],
			['%d', '%s'],
			['%d']
		);
	}

	private static function find_or_create_customer(array $payload): int {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_customers';
		$email = sanitize_email($payload['customerEmail'] ?? '');
		$phone = sanitize_text_field($payload['customerPhone'] ?? '');
		$now = current_time('mysql');

		$customer_id = 0;
		if ($email) {
			$customer_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email));
		}

		if (!$customer_id && $phone) {
			$customer_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE phone = %s LIMIT 1", $phone));
		}

		$data = [
			'wp_user_id' => get_current_user_id() ?: null,
			'first_name' => sanitize_text_field($payload['customerFirstName'] ?? ''),
			'last_name' => sanitize_text_field($payload['customerLastName'] ?? ''),
			'email' => $email,
			'phone' => $phone,
			'updated_at' => $now,
		];

		if ($customer_id) {
			$wpdb->update($table, $data, ['id' => $customer_id]);
			return $customer_id;
		}

		$data['created_at'] = $now;
		$wpdb->insert($table, $data);

		return (int) $wpdb->insert_id;
	}

	private static function update_customer_totals(int $customer_id): void {
		if (!$customer_id) {
			return;
		}

		global $wpdb;

		$bookings = $wpdb->prefix . 'rfb_bookings';
		$customers = $wpdb->prefix . 'rfb_customers';
		$stats = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS total_bookings, COALESCE(SUM(total), 0) AS total_spend, MAX(pickup_at) AS last_booking_at FROM {$bookings} WHERE customer_id = %d", $customer_id));

		$wpdb->update(
			$customers,
			[
				'total_bookings' => (int) $stats->total_bookings,
				'total_spend' => (float) $stats->total_spend,
				'last_booking_at' => $stats->last_booking_at,
				'updated_at' => current_time('mysql'),
			],
			['id' => $customer_id],
			['%d', '%f', '%s', '%s'],
			['%d']
		);
	}

	private static function save_meta(int $booking_id, string $key, mixed $value): void {
		global $wpdb;

		if (!$booking_id || '' === $key) {
			return;
		}

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

	public static function add_meta(int $booking_id, string $key, mixed $value): void {
		self::save_meta($booking_id, $key, $value);
	}

	private static function pickup_datetime(array $payload): ?string {
		$date = sanitize_text_field($payload['pickupDate'] ?? '');
		$time = sanitize_text_field($payload['pickupTime'] ?? '');

		if (!$date || !$time) {
			return null;
		}

		$timestamp = strtotime($date . ' ' . $time);

		return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : null;
	}

	private static function next_booking_number(): string {
		return 'RFB-' . gmdate('Ymd') . '-' . strtoupper(wp_generate_password(6, false, false));
	}
}
