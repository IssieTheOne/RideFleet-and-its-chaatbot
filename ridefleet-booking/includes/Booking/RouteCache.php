<?php
/**
 * Server-side route cache.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class RouteCache {
	public static function key(string $origin, string $destination, string $unit = ''): string {
		$unit = $unit ?: (string) Options::get('distance_unit', 'km');

		return hash('sha256', strtolower(trim($origin)) . '|' . strtolower(trim($destination)) . '|' . $unit);
	}

	public static function get(string $origin, string $destination): ?object {
		global $wpdb;

		$key = self::key($origin, $destination);
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_route_cache WHERE route_hash = %s LIMIT 1", $key));

		if (!$row) {
			return null;
		}

		if ($row->expires_at && strtotime($row->expires_at) < current_time('timestamp')) {
			$wpdb->delete($wpdb->prefix . 'rfb_route_cache', ['id' => (int) $row->id], ['%d']);
			return null;
		}

		return $row;
	}

	public static function put(string $origin, string $destination, float $distance, int $duration_seconds, string $polyline = ''): void {
		global $wpdb;

		$now = current_time('mysql');
		$table = $wpdb->prefix . 'rfb_route_cache';
		$key = self::key($origin, $destination);
		$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE route_hash = %s", $key));
		$data = [
			'route_hash' => $key,
			'origin' => sanitize_text_field($origin),
			'destination' => sanitize_text_field($destination),
			'distance_value' => $distance,
			'distance_unit' => Options::get('distance_unit', 'km'),
			'duration_seconds' => $duration_seconds,
			'overview_polyline' => $polyline,
			'expires_at' => wp_date('Y-m-d H:i:s', strtotime('+30 days')),
			'updated_at' => $now,
		];

		if ($existing) {
			$wpdb->update($table, $data, ['id' => (int) $existing]);
			return;
		}

		$data['created_at'] = $now;
		$wpdb->insert($table, $data);
	}
}
