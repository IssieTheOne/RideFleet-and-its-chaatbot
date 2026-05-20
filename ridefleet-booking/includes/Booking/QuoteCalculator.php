<?php
/**
 * Quote calculation service.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class QuoteCalculator {
	public static function calculate(float $distance, float $duration_minutes, array $extras = [], int $vehicle_id = 0, string $coupon_code = '', string $pickup_at = '', int $route_id = 0, string $service_type = 'standard'): array {
		$fare = Options::get('fare', []);

		$base = (float) ($fare['base_fare'] ?? 0);
		$minimum = (float) ($fare['minimum_fare'] ?? 0);
		$per_distance = (float) ($fare['per_distance'] ?? 0);
		$per_minute = (float) ($fare['per_minute'] ?? 0);
		$hourly_rate = (float) ($fare['hourly_rate'] ?? 0);
		$return_trip_multiplier = (float) ($fare['return_trip_multiplier'] ?? 1.0);

		$distance_total = $distance * $per_distance;
		$duration_total = $duration_minutes * $per_minute;
		$extras_total = self::extras_total($extras);
		$vehicle_adjustment = self::vehicle_adjustment($vehicle_id);
		$fixed_route_price = self::fixed_route_price($route_id);
		
		// Service type pricing logic
		if ($service_type === 'hourly' && $hourly_rate > 0) {
			$duration_hours = max(1, ceil($duration_minutes / 60));
			$service_total = $base + ($duration_hours * $hourly_rate) + $extras_total + $vehicle_adjustment;
		} elseif ($service_type === 'return_trip' && $fixed_route_price > 0) {
			$service_total = $fixed_route_price * $return_trip_multiplier + $extras_total + $vehicle_adjustment;
		} elseif ($fixed_route_price > 0) {
			$service_total = $fixed_route_price + $extras_total + $vehicle_adjustment;
		} else {
			$service_total = $base + $distance_total + $duration_total + $extras_total + $vehicle_adjustment;
		}
		
		$raw_subtotal = $service_total;
		$subtotal = max($minimum, $raw_subtotal);
		$rules = PricingRules::adjustments($subtotal, ['pickupAt' => $pickup_at]);
		$subtotal_with_rules = $subtotal + (float) $rules['total'];
		$coupon = CouponService::discount($coupon_code, $subtotal_with_rules);
		$discount = $coupon['valid'] ? (float) $coupon['amount'] : 0.0;
		$total = max(0, $subtotal_with_rules - $discount);

		return [
			'currency' => Options::get('currency', 'USD'),
			'distanceUnit' => Options::get('distance_unit', 'km'),
			'subtotal' => round($subtotal_with_rules, 2),
			'taxTotal' => 0.00,
			'discountTotal' => round($discount, 2),
			'total' => round($total, 2),
			'coupon' => $coupon,
			'serviceType' => $service_type,
			'breakdown' => [
				'baseFare' => round($base, 2),
				'distance' => round($distance_total, 2),
				'duration' => round($duration_total, 2),
				'hourlyServices' => $service_type === 'hourly' ? round($hourly_rate * max(1, ceil($duration_minutes / 60)), 2) : 0.0,
				'extras' => round($extras_total, 2),
				'vehicleAdjustment' => round($vehicle_adjustment, 2),
				'fixedRoutePrice' => round($fixed_route_price, 2),
				'returnTripMultiplier' => $service_type === 'return_trip' ? $return_trip_multiplier : 1.0,
				'pricingRules' => $rules['applied'],
				'minimumFareApplied' => $raw_subtotal < $minimum,
			],
		];
	}

	private static function extras_total(array $extras): float {
		$total = 0.0;

		foreach ($extras as $extra) {
			$id = isset($extra['id']) ? absint($extra['id']) : 0;
			$quantity = isset($extra['quantity']) ? max(1, absint($extra['quantity'])) : 1;

			if (!$id || 'rfb_extra' !== get_post_type($id)) {
				continue;
			}

			$total += ((float) get_post_meta($id, 'rfb_price', true)) * $quantity;
		}

		return $total;
	}

	private static function vehicle_adjustment(int $vehicle_id): float {
		if (!$vehicle_id || 'rfb_vehicle' !== get_post_type($vehicle_id)) {
			return 0.0;
		}

		return (float) get_post_meta($vehicle_id, 'rfb_price_adjustment', true);
	}

	private static function fixed_route_price(int $route_id): float {
		if (!$route_id || 'rfb_route' !== get_post_type($route_id)) {
			return 0.0;
		}

		return (float) get_post_meta($route_id, 'rfb_fixed_price', true);
	}

	public static function find_route_by_coords(array $pickup, array $dropoff): int {
		$routes = get_posts([
			'post_type'   => 'rfb_route',
			'post_status' => 'publish',
			'numberposts' => 200,
			'fields'      => 'ids',
		]);

		foreach ($routes as $route_id) {
			$fixed_price = (float) get_post_meta($route_id, 'rfb_fixed_price', true);
			if ($fixed_price <= 0) {
				continue;
			}

			$origin_lat = (float) get_post_meta($route_id, 'rfb_origin_lat', true);
			$origin_lng = (float) get_post_meta($route_id, 'rfb_origin_lng', true);
			$dest_lat   = (float) get_post_meta($route_id, 'rfb_destination_lat', true);
			$dest_lng   = (float) get_post_meta($route_id, 'rfb_destination_lng', true);

			if (!$origin_lat || !$origin_lng || !$dest_lat || !$dest_lng) {
				continue;
			}

			$radius = max(0.5, (float) (get_post_meta($route_id, 'rfb_match_radius', true) ?: 15));

			$origin_dist = self::haversine_km($pickup, ['lat' => $origin_lat, 'lng' => $origin_lng]);
			$dest_dist   = self::haversine_km($dropoff, ['lat' => $dest_lat, 'lng' => $dest_lng]);

			if ($origin_dist <= $radius && $dest_dist <= $radius) {
				return (int) $route_id;
			}
		}

		return 0;
	}

	private static function haversine_km(array $a, array $b): float {
		$r    = 6371.0088;
		$lat1 = deg2rad((float) $a['lat']);
		$lat2 = deg2rad((float) $b['lat']);
		$dlat = $lat2 - $lat1;
		$dlng = deg2rad((float) $b['lng'] - (float) $a['lng']);
		$h    = sin($dlat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dlng / 2) ** 2;

		return 2 * $r * asin(min(1.0, sqrt($h)));
	}
}
