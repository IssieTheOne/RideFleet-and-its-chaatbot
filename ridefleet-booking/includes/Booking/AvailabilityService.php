<?php
/**
 * Availability checks — business hours, advance notice, booking window, blackout rules.
 *
 * This is the single source of truth for all scheduling constraints.
 * The chatbot plugin reads from here; it never duplicates configuration.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class AvailabilityService {

	// ─────────────────────────────────────────────────────────────────────────
	// Main availability gate
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Full availability check. Returns false if the slot is blocked by any constraint.
	 *
	 * @param string $pickup_at  MySQL datetime string (YYYY-MM-DD HH:MM:SS).
	 * @param int    $vehicle_id Optional vehicle ID; 0 = no vehicle filter.
	 */
	public static function is_available(string $pickup_at, int $vehicle_id = 0): bool {
		if (!$pickup_at) {
			return false;
		}

		if (!self::respects_advance_notice($pickup_at)) {
			return false;
		}

		if (!self::within_booking_window($pickup_at)) {
			return false;
		}

		if (!self::within_business_hours($pickup_at)) {
			return false;
		}

		// Blackout rule check (original logic, preserved).
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_availability_rules';
		$rule  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE is_active = 1
				   AND rule_type = 'blackout'
				   AND starts_at <= %s
				   AND ends_at   >= %s
				   AND (vehicle_id IS NULL OR vehicle_id = 0 OR vehicle_id = %d)
				 LIMIT 1",
				$pickup_at,
				$pickup_at,
				$vehicle_id
			)
		);

		return !$rule;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Settings helpers (used by chatbot calendar, admin UI, etc.)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Returns all scheduling settings merged with defaults.
	 * Shape matches what the chatbot widget expects.
	 */
	public static function get_settings(): array {
		$raw     = Options::get('availability_settings', []);
		$stored  = is_array($raw) ? $raw : [];

		$defaults = self::default_business_hours();
		$stored_h = is_array($stored['business_hours'] ?? null) ? $stored['business_hours'] : [];

		$hours = $defaults;
		foreach ($stored_h as $dow => $h) {
			$dow = (int) $dow;
			if (isset($hours[$dow]) && is_array($h)) {
				$hours[$dow] = [
					'enabled' => !empty($h['enabled']),
					'open'    => sanitize_text_field((string) ($h['open']  ?? $defaults[$dow]['open'])),
					'close'   => sanitize_text_field((string) ($h['close'] ?? $defaults[$dow]['close'])),
				];
			}
		}

		return [
			'min_advance_hours'      => max(0, (int) ($stored['min_advance_hours'] ?? 2)),
			'max_booking_days'       => max(1, (int) ($stored['max_booking_days']   ?? 90)),
			'business_hours_enabled' => !empty($stored['business_hours_enabled']),
			'business_hours'         => $hours,
		];
	}

	/**
	 * Returns upcoming global blackout ranges for calendar display.
	 * Each item: { start: 'YYYY-MM-DD', end: 'YYYY-MM-DD', label: string }
	 */
	public static function blocked_date_ranges(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_availability_rules';
		$rows  = $wpdb->get_results(
			"SELECT label, starts_at, ends_at
			 FROM {$table}
			 WHERE is_active = 1
			   AND rule_type = 'blackout'
			   AND (vehicle_id IS NULL OR vehicle_id = 0)
			   AND ends_at >= NOW()
			 ORDER BY starts_at ASC
			 LIMIT 200"
		);

		$ranges = [];
		foreach ($rows as $row) {
			$ranges[] = [
				'start' => substr((string) $row->starts_at, 0, 10),
				'end'   => substr((string) $row->ends_at,   0, 10),
				'label' => sanitize_text_field((string) $row->label),
			];
		}
		return $ranges;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Individual constraint checks (public for unit-testing)
	// ─────────────────────────────────────────────────────────────────────────

	public static function respects_advance_notice(string $pickup_at): bool {
		$settings = self::get_settings();
		$hours    = (int) $settings['min_advance_hours'];
		if ($hours <= 0) {
			return true;
		}
		$ts = strtotime($pickup_at);
		return $ts && $ts >= (time() + $hours * 3600);
	}

	public static function within_booking_window(string $pickup_at): bool {
		$settings = self::get_settings();
		$max_days = (int) $settings['max_booking_days'];
		$ts       = strtotime($pickup_at);
		return $ts && $ts <= (time() + $max_days * 86400);
	}

	public static function within_business_hours(string $pickup_at): bool {
		$settings = self::get_settings();
		if (empty($settings['business_hours_enabled'])) {
			return true;
		}
		$ts = strtotime($pickup_at);
		if (!$ts) {
			return false;
		}
		$dow   = (int) wp_date('w', $ts); // 0 = Sunday
		$hours = $settings['business_hours'][$dow] ?? null;
		if (!$hours || empty($hours['enabled'])) {
			return false;
		}
		$time_str = (string) wp_date('H:i', $ts);
		return $time_str >= $hours['open'] && $time_str <= $hours['close'];
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Defaults
	// ─────────────────────────────────────────────────────────────────────────

	public static function default_business_hours(): array {
		return [
			0 => ['enabled' => false, 'open' => '00:00', 'close' => '23:59'], // Sunday
			1 => ['enabled' => true,  'open' => '06:00', 'close' => '22:00'], // Monday
			2 => ['enabled' => true,  'open' => '06:00', 'close' => '22:00'], // Tuesday
			3 => ['enabled' => true,  'open' => '06:00', 'close' => '22:00'], // Wednesday
			4 => ['enabled' => true,  'open' => '06:00', 'close' => '22:00'], // Thursday
			5 => ['enabled' => true,  'open' => '06:00', 'close' => '22:00'], // Friday
			6 => ['enabled' => true,  'open' => '08:00', 'close' => '23:00'], // Saturday
		];
	}
}
