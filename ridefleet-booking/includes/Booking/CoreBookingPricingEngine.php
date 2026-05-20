<?php
/**
 * Headless geofencing, flat-rate, and chatbot-safe pricing engine.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class CoreBookingPricingEngine {
	private const EARTH_RADIUS_KM = 6371.0088;

	public static function process_taxi_booking(array $pickup_coords, array $dropoff_coords, float $standard_distance_price, array $context = []): array {
		$settings = self::settings();
		$standard_distance_price = max(0.0, $standard_distance_price);
		$requires_approval = false;

		$service_area = $settings['global_service_area'];
		$global_service_area_enabled = !empty($service_area['enabled']);
		if (!empty($service_area['enabled'])) {
			$pickup_allowed = self::point_in_service_area($pickup_coords, $service_area, (string) ($context['pickup_address'] ?? ''));
			$dropoff_allowed = self::point_in_service_area($dropoff_coords, $service_area, (string) ($context['dropoff_address'] ?? ''));

			if (!$pickup_allowed && !$dropoff_allowed) {
				return [
					'success' => false,
					'error' => true,
					'code' => 'outside_service_area',
					'message' => sanitize_textarea_field((string) ($service_area['error_message'] ?: __('This ride is outside our service area.', 'ridefleet-booking'))),
					'final_price' => null,
					'currency' => Options::get('currency', 'USD'),
				];
			}
			if (!$pickup_allowed || !$dropoff_allowed) {
				$requires_approval = true;
			}
		}

		if (!$global_service_area_enabled) {
			$operational_geofence = self::active_operational_geofence_result($pickup_coords, $dropoff_coords);
			if ('outside' === $operational_geofence['status']) {
				return [
					'success' => false,
					'error' => true,
					'code' => 'outside_service_area',
					'message' => sanitize_textarea_field((string) ($service_area['error_message'] ?: __('This ride is outside our service area.', 'ridefleet-booking'))),
					'final_price' => null,
					'currency' => Options::get('currency', 'USD'),
				];
			}
			if ('approval_required' === $operational_geofence['status']) {
				$requires_approval = true;
			}
		}

		$flat_match = self::matching_flat_rate($pickup_coords, $dropoff_coords, $settings['flat_rates'], $context);
		if ($flat_match) {
			$flat_price = (float) $flat_match['price'];
			$priority = (string) $settings['priority_rule'];
			$final_price = $flat_price;
			$source = 'flat_rate';

			if ('cheapest' === $priority) {
				$final_price = min($standard_distance_price, $flat_price);
				$source = $final_price === $flat_price ? 'flat_rate_cheapest' : 'standard_cheapest';
			} elseif ('expensive' === $priority) {
				$final_price = max($standard_distance_price, $flat_price);
				$source = $final_price === $flat_price ? 'flat_rate_expensive' : 'standard_expensive';
			}

			return self::success($final_price, $source, $flat_match, $standard_distance_price, $requires_approval);
		}

		$final_price = $standard_distance_price;
		$source = 'standard';
		$adjacent_zone = self::nearest_adjacent_zone($dropoff_coords, $settings['flat_rates'], $context);

		if (!empty($settings['buffer_zone_enabled']) && $adjacent_zone && $adjacent_zone['distance_to_border'] <= (float) $settings['buffer_zone_distance']) {
			$source = 'buffer_zone';
			if ('flat_plus_meter' === $settings['buffer_zone_fee_type']) {
				$units = max(0.0, (float) $adjacent_zone['distance_to_border'] / 1000);
				$final_price = (float) $adjacent_zone['price'] + ($units * (float) $settings['buffer_zone_penalty_value']);
			} else {
				$final_price = (float) $adjacent_zone['price'] + (float) $settings['buffer_zone_penalty_value'];
			}
		}

		if (!empty($settings['abuse_protection_enabled'])) {
			$abuse_zone = $adjacent_zone ?: self::nearest_adjacent_zone($dropoff_coords, $settings['flat_rates'], $context);
			if ($abuse_zone && $final_price < (float) $abuse_zone['price']) {
				if ('distance_plus_penalty' === $settings['abuse_protection_type']) {
					$final_price += (float) $settings['buffer_zone_penalty_value'];
					$source = 'abuse_distance_plus_penalty';
				} else {
					$final_price = (float) $abuse_zone['price'];
					$source = 'abuse_force_flat_rate';
				}
			}
		}

		return self::success($final_price, $source, $adjacent_zone, $standard_distance_price, $requires_approval);
	}

	public static function service_area_status_from_request(array $params): array {
		$pickup_address = sanitize_textarea_field((string) ($params['pickup_address'] ?? $params['pickupAddress'] ?? ''));
		$dropoff_address = sanitize_textarea_field((string) ($params['dropoff_address'] ?? $params['dropoffAddress'] ?? ''));
		$pickup = self::coords_from_params($params, 'pickup', $pickup_address);
		$dropoff = self::coords_from_params($params, 'dropoff', $dropoff_address);

		if (!$pickup || !$dropoff) {
			return ['status' => 'unknown', 'pickup_allowed' => true, 'dropoff_allowed' => true];
		}

		$settings = self::settings();
		$service_area = $settings['global_service_area'];
		if (!empty($service_area['enabled'])) {
			$pickup_allowed = self::point_in_service_area($pickup, $service_area, $pickup_address);
			$dropoff_allowed = self::point_in_service_area($dropoff, $service_area, $dropoff_address);
			if (!$pickup_allowed && !$dropoff_allowed) {
				return ['status' => 'outside', 'pickup_allowed' => false, 'dropoff_allowed' => false];
			}
			if (!$pickup_allowed || !$dropoff_allowed) {
				return ['status' => 'approval_required', 'pickup_allowed' => $pickup_allowed, 'dropoff_allowed' => $dropoff_allowed];
			}

			return ['status' => 'inside', 'pickup_allowed' => true, 'dropoff_allowed' => true];
		}

		return self::active_operational_geofence_result($pickup, $dropoff);
	}

	public static function quote_from_request(array $params): array {
		$pickup_address = sanitize_textarea_field((string) ($params['pickup_address'] ?? $params['pickupAddress'] ?? ''));
		$dropoff_address = sanitize_textarea_field((string) ($params['dropoff_address'] ?? $params['dropoffAddress'] ?? ''));
		$pickup = self::coords_from_params($params, 'pickup', $pickup_address);
		$dropoff = self::coords_from_params($params, 'dropoff', $dropoff_address);

		if (!$pickup || !$dropoff) {
			return [
				'success' => false,
				'error' => true,
				'code' => 'missing_coordinates',
				'message' => __('Provide pickup/drop-off addresses or raw pickup_lat, pickup_lng, dropoff_lat, and dropoff_lng values.', 'ridefleet-booking'),
			];
		}

		$distance = self::distance_km($pickup, $dropoff);
		$standard = self::standard_price($distance);

		$result = self::process_taxi_booking(
			$pickup,
			$dropoff,
			$standard,
			[
				'pickup_address' => $pickup_address,
				'dropoff_address' => $dropoff_address,
				'dropoff_zip' => self::extract_zip($dropoff_address),
			]
		);
		if (!empty($result['success'])) {
			$result['distance_km'] = round($distance, 3);
			$result['duration_minutes'] = max(1, (int) round(($distance / 45) * 60));
		}
		return $result;
	}

	public static function settings(): array {
		$defaults = [
			'global_service_area' => [
				'enabled' => false,
				'type' => 'radius',
				'data' => '',
				'error_message' => __('This ride is outside our service area.', 'ridefleet-booking'),
			],
			'flat_rates' => [],
			'priority_rule' => 'flat_rate_only',
			'abuse_protection_enabled' => false,
			'abuse_protection_type' => 'force_flat_rate',
			'buffer_zone_enabled' => false,
			'buffer_zone_distance' => 500,
			'buffer_zone_fee_type' => 'flat_plus_fee',
			'buffer_zone_penalty_value' => 10,
		];

		$config = Options::get('core_booking_rules', []);
		return array_replace_recursive($defaults, is_array($config) ? $config : []);
	}

	private static function coords_from_params(array $params, string $prefix, string $address): ?array {
		$lat = $params[$prefix . '_lat'] ?? $params[$prefix . 'Lat'] ?? null;
		$lng = $params[$prefix . '_lng'] ?? $params[$prefix . 'Lng'] ?? null;

		if (is_numeric($lat) && is_numeric($lng)) {
			return ['lat' => (float) $lat, 'lng' => (float) $lng];
		}

		return $address ? self::geocode($address) : null;
	}

	private static function geocode(string $address): ?array {
		$cache_key = 'rfb_geocode_' . md5(strtolower(trim($address)));
		$persistent_key = 'rfb_geocode_lkg_' . md5(strtolower(trim($address))); // last-known-good

		$cached = get_transient($cache_key);
		if (is_array($cached) && isset($cached['lat'], $cached['lng'])) {
			return ['lat' => (float) $cached['lat'], 'lng' => (float) $cached['lng']];
		}

		$key = Options::get('google_maps_api_key', '');
		if (!$key) {
			// No key configured — fall back to last-known-good if we have it,
			// otherwise let the caller treat the address as ungeocoded.
			$lkg = get_option($persistent_key);
			return is_array($lkg) && isset($lkg['lat'], $lkg['lng'])
				? ['lat' => (float) $lkg['lat'], 'lng' => (float) $lkg['lng']]
				: null;
		}

		$response = wp_remote_get(
			add_query_arg(
				[
					'address' => $address,
					'key' => $key,
				],
				'https://maps.googleapis.com/maps/api/geocode/json'
			),
			['timeout' => 8]
		);

		if (is_wp_error($response)) {
			// Network failure: graceful degradation to the persisted last-known-good
			// coordinate for this address so quotes can still complete (with a flag).
			$lkg = get_option($persistent_key);
			if (is_array($lkg) && isset($lkg['lat'], $lkg['lng'])) {
				return ['lat' => (float) $lkg['lat'], 'lng' => (float) $lkg['lng'], 'degraded' => true];
			}
			return null;
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$location = $body['results'][0]['geometry']['location'] ?? null;
		if (!is_array($location) || !isset($location['lat'], $location['lng'])) {
			$lkg = get_option($persistent_key);
			return is_array($lkg) && isset($lkg['lat'], $lkg['lng'])
				? ['lat' => (float) $lkg['lat'], 'lng' => (float) $lkg['lng'], 'degraded' => true]
				: null;
		}

		$coords = ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
		set_transient($cache_key, $coords, WEEK_IN_SECONDS);
		// Persist last-known-good for graceful degradation. autoload=false to
		// avoid bloating the options cache for high-traffic sites.
		update_option($persistent_key, $coords, false);

		return $coords;
	}

	private static function point_in_service_area(array $point, array $service_area, string $address): bool {
		$type = (string) ($service_area['type'] ?? 'radius');
		$data = self::decode_mixed($service_area['data'] ?? '');

		if ('states' === $type) {
			$states = is_array($data) ? $data : array_map('trim', explode(',', (string) ($service_area['data'] ?? '')));
			foreach ($states as $state) {
				if ($state && false !== stripos($address, (string) $state)) {
					return true;
				}
			}
			return false;
		}

		if ('polygon' === $type) {
			$polygon = self::polygon_points($data);
			return $polygon ? self::point_in_polygon($point, $polygon) : true;
		}

		$center = $data['center'] ?? $data;
		$radius = (float) ($data['radius'] ?? $data['radius_km'] ?? 0);
		if (!isset($center['lat'], $center['lng']) || $radius <= 0) {
			return true;
		}

		return self::distance_km($point, ['lat' => (float) $center['lat'], 'lng' => (float) $center['lng']]) <= $radius;
	}

	private static function active_operational_geofence_result(array $pickup, array $dropoff): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_geofence_zones';
		$table_exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($table_exists !== $table) {
			return ['status' => 'inside', 'pickup_allowed' => true, 'dropoff_allowed' => true, 'zone' => null];
		}

		$zones = $wpdb->get_results("SELECT * FROM {$table} WHERE is_active = 1 ORDER BY name ASC");
		if (!$zones) {
			return ['status' => 'inside', 'pickup_allowed' => true, 'dropoff_allowed' => true, 'zone' => null];
		}

		$pickup_zone = self::matching_operational_zone($pickup, $zones);
		$dropoff_zone = self::matching_operational_zone($dropoff, $zones);

		if (!$pickup_zone && !$dropoff_zone) {
			return ['status' => 'outside', 'pickup_allowed' => false, 'dropoff_allowed' => false, 'zone' => null];
		}
		$pickup_enforcement = (string) ($pickup_zone->enforcement_type ?? 'soft_approval');
		$dropoff_enforcement = (string) ($dropoff_zone->enforcement_type ?? 'soft_approval');
		$hard_block_mode = 'hard_block' === $pickup_enforcement || 'hard_block' === $dropoff_enforcement;
		if (!$pickup_zone || !$dropoff_zone) {
			if ($hard_block_mode) {
				return ['status' => 'outside', 'pickup_allowed' => (bool) $pickup_zone, 'dropoff_allowed' => (bool) $dropoff_zone, 'zone' => $pickup_zone ?: $dropoff_zone];
			}
			return ['status' => 'approval_required', 'pickup_allowed' => (bool) $pickup_zone, 'dropoff_allowed' => (bool) $dropoff_zone, 'zone' => $pickup_zone ?: $dropoff_zone];
		}
		return ['status' => 'inside', 'pickup_allowed' => true, 'dropoff_allowed' => true, 'zone' => $pickup_zone];
	}

	private static function matching_operational_zone(array $point, array $zones): ?object {
		foreach ($zones as $zone) {
			if ('polygon' === (string) ($zone->boundary_type ?? '')) {
				$polygon = self::polygon_points(self::decode_mixed((string) ($zone->boundary_data ?? '')));
				if ($polygon && self::point_in_polygon($point, $polygon)) {
					return $zone;
				}
				continue;
			}

			$center = ['lat' => (float) $zone->center_lat, 'lng' => (float) $zone->center_lng];
			$radius = max(0.0, (float) $zone->radius_km);
			if ($radius > 0 && self::distance_km($point, $center) <= $radius) {
				return $zone;
			}
		}

		return null;
	}

	private static function matching_flat_rate(array $pickup, array $dropoff, array $flat_rates, array $context): ?array {
		foreach ($flat_rates as $rate) {
			if (!is_array($rate) || empty($rate['name'])) {
				continue;
			}

			$hub = $rate['hub_coordinates'] ?? [];
			if (!isset($hub['lat'], $hub['lng'])) {
				continue;
			}

			$hub_radius = (float) ($rate['hub_radius_km'] ?? 1.5);
			if (self::distance_km($pickup, ['lat' => (float) $hub['lat'], 'lng' => (float) $hub['lng']]) > $hub_radius) {
				continue;
			}

			if (self::point_in_flat_zone($dropoff, $rate, $context)) {
				return $rate;
			}
		}

		return null;
	}

	private static function point_in_flat_zone(array $point, array $rate, array $context): bool {
		$type = (string) ($rate['zone_type'] ?? 'radius');
		$data = self::decode_mixed($rate['zone_data'] ?? []);

		if ('zipcodes' === $type) {
			$zip = (string) ($context['dropoff_zip'] ?? '');
			$zips = is_array($data) ? $data : array_map('trim', explode(',', (string) ($rate['zone_data'] ?? '')));
			return $zip && in_array($zip, array_map('strval', $zips), true);
		}

		if ('polygon' === $type) {
			$polygon = self::polygon_points($data);
			return $polygon ? self::point_in_polygon($point, $polygon) : false;
		}

		$center = $data['center'] ?? $data;
		$radius = (float) ($data['radius'] ?? $data['radius_km'] ?? 0);
		return isset($center['lat'], $center['lng']) && $radius > 0 && self::distance_km($point, ['lat' => (float) $center['lat'], 'lng' => (float) $center['lng']]) <= $radius;
	}

	private static function nearest_adjacent_zone(array $point, array $flat_rates, array $context): ?array {
		$nearest = null;
		foreach ($flat_rates as $rate) {
			if (!is_array($rate)) {
				continue;
			}

			$distance = self::distance_to_zone_border_meters($point, $rate, $context);
			if (null === $distance) {
				continue;
			}

			$rate['distance_to_border'] = $distance;
			if (!$nearest || $distance < (float) $nearest['distance_to_border']) {
				$nearest = $rate;
			}
		}

		return $nearest;
	}

	private static function distance_to_zone_border_meters(array $point, array $rate, array $context): ?float {
		if (self::point_in_flat_zone($point, $rate, $context)) {
			return 0.0;
		}

		$data = self::decode_mixed($rate['zone_data'] ?? []);
		if ('radius' === ($rate['zone_type'] ?? 'radius')) {
			$center = $data['center'] ?? $data;
			$radius = (float) ($data['radius'] ?? $data['radius_km'] ?? 0);
			if (!isset($center['lat'], $center['lng']) || $radius <= 0) {
				return null;
			}

			return max(0.0, (self::distance_km($point, ['lat' => (float) $center['lat'], 'lng' => (float) $center['lng']]) - $radius) * 1000);
		}

		if ('polygon' === ($rate['zone_type'] ?? '')) {
			$polygon = self::polygon_points($data);
			return $polygon ? self::distance_to_polygon_meters($point, $polygon) : null;
		}

		return null;
	}

	private static function standard_price(float $distance_km): float {
		$fare = Options::get('fare', []);
		$base = (float) ($fare['base_fare'] ?? 0);
		$minimum = (float) ($fare['minimum_fare'] ?? 0);
		$per_distance = (float) ($fare['per_distance'] ?? 0);

		return max($minimum, $base + ($distance_km * $per_distance));
	}

	private static function success(float $price, string $source, ?array $zone, float $standard, bool $requires_approval = false): array {
		$response = [
			'success' => true,
			'error' => false,
			'final_price' => round(max(0.0, $price), 2),
			'price' => round(max(0.0, $price), 2),
			'standard_price' => round($standard, 2),
			'zone_classification_name' => $zone['name'] ?? '',
			'zone_name' => $zone['name'] ?? '',
			'pricing_source' => $source,
			'currency' => Options::get('currency', 'USD'),
		];
		if ($requires_approval) {
			$response['requires_approval'] = true;
			$response['approval_message'] = __('One side of this trip is outside the normal service area. The ride can be requested, but dispatch must approve it and may adjust the final fare.', 'ridefleet-booking');
		}

		return $response;
	}

	private static function distance_km(array $a, array $b): float {
		$lat1 = deg2rad((float) $a['lat']);
		$lat2 = deg2rad((float) $b['lat']);
		$dlat = $lat2 - $lat1;
		$dlng = deg2rad((float) $b['lng'] - (float) $a['lng']);
		$h = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;

		return 2 * self::EARTH_RADIUS_KM * asin(min(1, sqrt($h)));
	}

	private static function point_in_polygon(array $point, array $polygon): bool {
		$inside = false;
		$count = count($polygon);
		for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
			$xi = (float) $polygon[$i]['lng'];
			$yi = (float) $polygon[$i]['lat'];
			$xj = (float) $polygon[$j]['lng'];
			$yj = (float) $polygon[$j]['lat'];
			$intersect = (($yi > $point['lat']) !== ($yj > $point['lat'])) && ($point['lng'] < ($xj - $xi) * ($point['lat'] - $yi) / (($yj - $yi) ?: 0.0000001) + $xi);
			if ($intersect) {
				$inside = !$inside;
			}
		}

		return $inside;
	}

	private static function distance_to_polygon_meters(array $point, array $polygon): float {
		$min = PHP_FLOAT_MAX;
		$count = count($polygon);
		for ($i = 0; $i < $count; $i++) {
			$a = $polygon[$i];
			$b = $polygon[($i + 1) % $count];
			$mid = ['lat' => (((float) $a['lat']) + ((float) $b['lat'])) / 2, 'lng' => (((float) $a['lng']) + ((float) $b['lng'])) / 2];
			$min = min($min, self::distance_km($point, $mid) * 1000);
		}

		return $min;
	}

	private static function polygon_points(mixed $data): array {
		if (isset($data['coordinates'][0]) && is_array($data['coordinates'][0])) {
			$data = $data['coordinates'][0];
		}

		$points = [];
		foreach (is_array($data) ? $data : [] as $point) {
			if (isset($point['lat'], $point['lng'])) {
				$points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
			} elseif (is_array($point) && isset($point[0], $point[1])) {
				$points[] = ['lat' => (float) $point[1], 'lng' => (float) $point[0]];
			}
		}

		return $points;
	}

	private static function decode_mixed(mixed $value): mixed {
		if (is_array($value)) {
			return $value;
		}

		$decoded = json_decode((string) $value, true);
		return is_array($decoded) ? $decoded : $value;
	}

	private static function extract_zip(string $address): string {
		return preg_match('/\b\d{5}(?:-\d{4})?\b/', $address, $matches) ? $matches[0] : '';
	}
}
