<?php
/**
 * Availability checks.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class AvailabilityService {
	public static function is_available(string $pickup_at, int $vehicle_id = 0): bool {
		if (!$pickup_at) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'rfb_availability_rules';
		$rule = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE is_active = 1 AND rule_type = 'blackout' AND starts_at <= %s AND ends_at >= %s AND (vehicle_id IS NULL OR vehicle_id = 0 OR vehicle_id = %d) LIMIT 1",
				$pickup_at,
				$pickup_at,
				$vehicle_id
			)
		);

		return !$rule;
	}
}
