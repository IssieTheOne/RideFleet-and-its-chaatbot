<?php
/**
 * REST API routes.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Rest;

use RideFleetBooking\Booking\BookingRepository;
use RideFleetBooking\Booking\AvailabilityService;
use RideFleetBooking\Booking\CoreBookingPricingEngine;
use RideFleetBooking\Booking\NotificationService;
use RideFleetBooking\Booking\QuoteCalculator;
use RideFleetBooking\Booking\RouteCache;
use RideFleetBooking\Integrations\WooCommerce;
use RideFleetBooking\Support\Options;
use RideFleetBooking\Support\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
	exit;
}

final class Routes {
	public static function register_hooks(): void {
		add_action('rest_api_init', [self::class, 'register']);
	}

	public static function register(): void {
		register_rest_route(
			'ridefleet/v1',
			'/settings/public',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'public_settings'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/quote',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'quote'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/vehicles',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'vehicles'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/extras',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'extras'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/bookings',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'create_booking'],
				'permission_callback' => [self::class, 'can_create_booking'],
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/route-cache',
			[
				[
					'methods' => 'GET',
					'callback' => [self::class, 'route_cache_get'],
					'permission_callback' => '__return_true',
				],
				[
					'methods' => 'POST',
					'callback' => [self::class, 'route_cache_put'],
					'permission_callback' => [self::class, 'can_create_booking'],
				],
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/dispatcher/quote',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'dispatcher_quote'],
				'permission_callback' => [self::class, 'can_dispatcher_access'],
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/dispatcher/bookings',
			[
				[
					'methods' => 'GET',
					'callback' => [self::class, 'dispatcher_find_bookings'],
					'permission_callback' => [self::class, 'can_dispatcher_access'],
				],
				[
					'methods' => 'POST',
					'callback' => [self::class, 'dispatcher_create_booking'],
					'permission_callback' => [self::class, 'can_dispatcher_access'],
				],
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/dispatcher/bookings/(?P<booking>[A-Za-z0-9-]+)',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'dispatcher_get_booking'],
				'permission_callback' => [self::class, 'can_dispatcher_access'],
			]
		);

		register_rest_route(
			'ridefleet/v1',
			'/dispatcher/bookings/(?P<booking>[A-Za-z0-9-]+)/change-request',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'dispatcher_change_request'],
				'permission_callback' => [self::class, 'can_dispatcher_access'],
			]
		);

		register_rest_route(
			'taxi-booking/v1',
			'/calculate-price',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'chatbot_calculate_price'],
				'permission_callback' => [self::class, 'can_chatbot_access'],
			]
		);

		register_rest_route(
			'taxi-booking/v1',
			'/create-booking',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'chatbot_create_booking'],
				'permission_callback' => [self::class, 'can_chatbot_access'],
			]
		);

		register_rest_route(
			'taxi-booking/v1',
			'/place-search',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'chatbot_place_search'],
				'permission_callback' => [self::class, 'can_chatbot_access'],
			]
		);

		register_rest_route(
			'taxi-booking/v1',
			'/place-details',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'chatbot_place_details'],
				'permission_callback' => [self::class, 'can_chatbot_access'],
			]
		);

		register_rest_route(
			'taxi-booking/v1',
			'/validate-coupon',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'chatbot_validate_coupon'],
				'permission_callback' => [self::class, 'can_chatbot_access'],
			]
		);
	}

	public static function public_settings(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'currency' => Options::get('currency', 'USD'),
				'distanceUnit' => Options::get('distance_unit', 'km'),
				'googleMapsMapId' => Options::get('google_maps_map_id', ''),
				'googleMapsConfigured' => (bool) Options::get('google_maps_api_key', ''),
				'woocommerceCheckoutEnabled' => 'yes' === Options::get('woocommerce_checkout_enabled', 'no'),
				'serviceAreaMap' => self::public_service_area_map(),
			]
		);
	}

	private static function public_service_area_map(): array {
		global $wpdb;

		$settings = CoreBookingPricingEngine::settings();
		$service_area = $settings['global_service_area'] ?? [];
		$type = (string) ($service_area['type'] ?? 'radius');
		$data = self::decode_public_map_data($service_area['data'] ?? '');
		$map = [
			'enabled' => !empty($service_area['enabled']),
			'type' => $type,
			'center' => null,
			'radiusKm' => null,
			'polygon' => [],
			'zones' => [],
		];

		if (!empty($service_area['enabled']) && 'radius' === $type && is_array($data)) {
			$center = $data['center'] ?? $data;
			$radius = (float) ($data['radius_km'] ?? $data['radiusKm'] ?? $data['radius'] ?? 0);
			if (isset($center['lat'], $center['lng']) && $radius > 0) {
				$map['center'] = ['lat' => (float) $center['lat'], 'lng' => (float) $center['lng']];
				$map['radiusKm'] = $radius;
			}
		}

		if (!empty($service_area['enabled']) && 'polygon' === $type && is_array($data)) {
			$map['polygon'] = self::public_polygon_points($data);
			if ($map['polygon']) {
				$map['center'] = self::public_points_center($map['polygon']);
			}
		}

		$table = $wpdb->prefix . 'rfb_geofence_zones';
		$table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
		if ($table_exists === $table) {
			$zones = $wpdb->get_results("SELECT name, center_lat, center_lng, radius_km FROM {$table} WHERE is_active = 1 ORDER BY id ASC LIMIT 20", ARRAY_A);
			foreach ((array) $zones as $zone) {
				if (!is_numeric($zone['center_lat'] ?? null) || !is_numeric($zone['center_lng'] ?? null)) {
					continue;
				}
				$map['zones'][] = [
					'name' => sanitize_text_field((string) ($zone['name'] ?? '')),
					'center' => ['lat' => (float) $zone['center_lat'], 'lng' => (float) $zone['center_lng']],
					'radiusKm' => max(0.1, (float) ($zone['radius_km'] ?? 1)),
				];
			}
		}

		if (!$map['center'] && $map['zones']) {
			$map['center'] = $map['zones'][0]['center'];
		}

		return $map;
	}

	private static function decode_public_map_data($value): array {
		if (is_array($value)) {
			return $value;
		}
		if (!is_string($value) || '' === trim($value)) {
			return [];
		}

		$decoded = json_decode(wp_unslash($value), true);
		return is_array($decoded) ? $decoded : [];
	}

	private static function public_polygon_points(array $data): array {
		$points = $data['points'] ?? $data['polygon'] ?? $data;
		if (isset($data['coordinates'][0]) && is_array($data['coordinates'][0])) {
			$points = $data['coordinates'][0];
		}

		$normalized = [];
		foreach ((array) $points as $point) {
			if (is_array($point) && isset($point['lat'], $point['lng'])) {
				$normalized[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
				continue;
			}
			if (is_array($point) && isset($point[0], $point[1])) {
				$normalized[] = ['lat' => (float) $point[1], 'lng' => (float) $point[0]];
			}
		}

		return $normalized;
	}

	private static function public_points_center(array $points): ?array {
		if (!$points) {
			return null;
		}

		$lat = 0.0;
		$lng = 0.0;
		foreach ($points as $point) {
			$lat += (float) $point['lat'];
			$lng += (float) $point['lng'];
		}

		return ['lat' => $lat / count($points), 'lng' => $lng / count($points)];
	}

	public static function quote(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'quote', 60, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many quote requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$distance = max(0, (float) $request->get_param('distance'));
		$duration_minutes = max(0, (float) $request->get_param('durationMinutes'));
		$extras = self::normalize_extras($request->get_param('extras'));
		$vehicle_id = absint($request->get_param('vehicleId'));
		$route_id = absint($request->get_param('routeId'));
		$service_type = sanitize_text_field((string) ($request->get_param('serviceType') ?: 'standard'));

		if (!$route_id) {
			$route_id = self::auto_detect_route($request->get_param('pickup_lat'), $request->get_param('pickup_lng'), $request->get_param('dropoff_lat'), $request->get_param('dropoff_lng'));
		}
		$area_status = CoreBookingPricingEngine::service_area_status_from_request(
			[
				'pickup_address' => sanitize_textarea_field((string) $request->get_param('pickupAddress')),
				'dropoff_address' => sanitize_textarea_field((string) $request->get_param('dropoffAddress')),
				'pickup_lat' => sanitize_text_field((string) $request->get_param('pickup_lat')),
				'pickup_lng' => sanitize_text_field((string) $request->get_param('pickup_lng')),
				'dropoff_lat' => sanitize_text_field((string) $request->get_param('dropoff_lat')),
				'dropoff_lng' => sanitize_text_field((string) $request->get_param('dropoff_lng')),
			]
		);
		if ('outside' === ($area_status['status'] ?? '')) {
			$settings = CoreBookingPricingEngine::settings();
			$service_area = $settings['global_service_area'] ?? [];
			return new WP_REST_Response(
				[
					'success' => false,
					'blocked' => true,
					'code' => 'outside_service_area',
					'message' => sanitize_textarea_field((string) (($service_area['error_message'] ?? '') ?: __('This ride is outside our service area.', 'ridefleet-booking'))),
					'total' => null,
					'currency' => Options::get('currency', 'USD'),
					'serviceAreaStatus' => $area_status,
				]
			);
		}
		$quote = QuoteCalculator::calculate($distance, $duration_minutes, $extras, $vehicle_id, (string) $request->get_param('couponCode'), self::pickup_from_request($request), $route_id, $service_type);
		if ('approval_required' === ($area_status['status'] ?? '')) {
			$quote['requiresApproval'] = true;
			$quote['approvalMessage'] = __('One side of this trip is outside the normal service area. You can send the request, but dispatch must approve it and may adjust the final fare.', 'ridefleet-booking');
			$quote['serviceAreaStatus'] = $area_status;
		}
		self::record_quote_event($request, $distance, (int) round($duration_minutes * 60), (float) $quote['total']);

		return new WP_REST_Response($quote);
	}

	public static function vehicles(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'vehicles', 120, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$passengers = max(1, absint($request->get_param('passengers') ?: 1));
		$luggage = max(0, absint($request->get_param('luggage') ?: 0));
		$query = new \WP_Query(
			[
				'post_type' => 'rfb_vehicle',
				'post_status' => 'publish',
				'posts_per_page' => 50,
				'orderby' => 'menu_order title',
				'order' => 'ASC',
			]
		);

		$vehicles = [];
		foreach ($query->posts as $post) {
			$capacity = absint(get_post_meta($post->ID, 'rfb_passenger_capacity', true) ?: 4);
			$bag_capacity = absint(get_post_meta($post->ID, 'rfb_luggage_capacity', true) ?: 2);

			if ($capacity < $passengers || $bag_capacity < $luggage) {
				continue;
			}

			$vehicles[] = [
				'id' => $post->ID,
				'name' => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
				'description' => wp_strip_all_tags((string) $post->post_content),
				'image' => get_the_post_thumbnail_url($post, 'medium') ?: '',
				'passengers' => $capacity,
				'luggage' => $bag_capacity,
				'priceAdjustment' => (float) get_post_meta($post->ID, 'rfb_price_adjustment', true),
			];
		}

		return new WP_REST_Response($vehicles);
	}

	public static function extras(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'extras', 120, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$query = new \WP_Query(
			[
				'post_type' => 'rfb_extra',
				'post_status' => 'publish',
				'posts_per_page' => 50,
				'orderby' => 'menu_order title',
				'order' => 'ASC',
			]
		);

		$extras = [];
		foreach ($query->posts as $post) {
			$extras[] = [
				'id' => $post->ID,
				'name' => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
				'description' => wp_strip_all_tags((string) $post->post_content),
				'price' => (float) get_post_meta($post->ID, 'rfb_price', true),
				'maxQuantity' => absint(get_post_meta($post->ID, 'rfb_max_quantity', true) ?: 1),
			];
		}

		return new WP_REST_Response($extras);
	}

	public static function create_booking(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'booking', 10, 10 * MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many booking attempts. Please wait and try again.', 'ridefleet-booking')], 429);
		}

		$payload = self::booking_payload($request);
		$validation = self::validate_booking_payload($payload);
		if ($validation) {
			return $validation;
		}

		$pickup_at = self::pickup_from_payload($payload);
		$boundary = self::boundary_validation_from_payload($payload);
		if ($boundary) {
			return $boundary;
		}
		$service_area_status = CoreBookingPricingEngine::service_area_status_from_request(
			[
				'pickup_address' => $payload['pickupAddress'],
				'dropoff_address' => $payload['dropoffAddress'],
				'pickup_lat' => $payload['pickup_lat'] ?? '',
				'pickup_lng' => $payload['pickup_lng'] ?? '',
				'dropoff_lat' => $payload['dropoff_lat'] ?? '',
				'dropoff_lng' => $payload['dropoff_lng'] ?? '',
			]
		);

		$requires_manual_dispatch = empty($payload['vehicleId']);
		// Only check vehicle availability when a specific vehicle was requested.
		if (!$requires_manual_dispatch && !AvailabilityService::is_available($pickup_at, (int) $payload['vehicleId'])) {
			return new WP_REST_Response(['message' => __('This pickup time is not available.', 'ridefleet-booking')], 409);
		}

		if ($requires_manual_dispatch) {
			$dispatch_note = __('⚠️ No vehicle matched the passenger/luggage count — dispatch must assign a vehicle for this booking.', 'ridefleet-booking');
			$payload['note'] = trim($dispatch_note . ($payload['note'] ? "\n" . $payload['note'] : ''));
		}

		$route_id_booking = (int) $payload['routeId'];
		if (!$route_id_booking) {
			$route_id_booking = self::auto_detect_route($payload['pickup_lat'] ?? '', $payload['pickup_lng'] ?? '', $payload['dropoff_lat'] ?? '', $payload['dropoff_lng'] ?? '');
		}
		$quote = QuoteCalculator::calculate((float) $payload['distance'], (float) $payload['durationMinutes'], $payload['extras'], (int) $payload['vehicleId'], (string) $payload['couponCode'], $pickup_at, $route_id_booking, $payload['serviceType']);
		$booking = BookingRepository::create($payload, $quote);
		if ($requires_manual_dispatch) {
			BookingRepository::add_meta((int) $booking['id'], '_requires_manual_dispatch', '1');
		}
		if ('approval_required' === ($service_area_status['status'] ?? '')) {
			BookingRepository::add_meta((int) $booking['id'], '_approval_required', '1');
			BookingRepository::add_meta((int) $booking['id'], '_service_area_status', $service_area_status);
		}
		NotificationService::booking_created((int) $booking['id']);
		$checkout_url = WooCommerce::checkout_url((int) $booking['id']);

		return new WP_REST_Response(
			[
				'booking' => $booking,
				'quote' => $quote,
				'checkoutUrl' => $checkout_url,
			],
			201
		);
	}

	public static function can_create_booking(WP_REST_Request $request): bool {
		$nonce = (string) $request->get_header('X-WP-Nonce');

		return (bool) wp_verify_nonce($nonce, 'wp_rest');
	}

	public static function can_chatbot_access(WP_REST_Request $request): bool {
		$settings = CoreBookingPricingEngine::settings();
		$expected = (string) ($settings['chatbot_api_key'] ?? '');
		if ('' === $expected) {
			return true;
		}

		$provided = (string) $request->get_header('X-RideFleet-Chatbot-Key');
		return hash_equals($expected, $provided);
	}

	public static function can_dispatcher_access(WP_REST_Request $request): bool {
		$options = get_option('rfb_settings', []);
		$options = is_array($options) ? $options : [];
		if ('yes' !== ($options['dispatcher_api_enabled'] ?? 'no')) {
			return false;
		}

		$expected = trim((string) ($options['dispatcher_api_key'] ?? ''));
		if ('' === $expected) {
			return false;
		}

		$authorization = trim((string) $request->get_header('Authorization'));
		$provided = (string) $request->get_header('X-RideFleet-Dispatcher-Key');
		if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
			$provided = trim((string) $matches[1]);
		}

		return '' !== $provided && hash_equals($expected, $provided);
	}

	public static function dispatcher_quote(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'dispatcher_quote', 80, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many dispatcher quote requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$payload = self::dispatcher_payload($request);
		$location_params = self::dispatcher_location_params($payload);
		$core_quote = CoreBookingPricingEngine::quote_from_request($location_params);
		if (empty($core_quote['success'])) {
			return new WP_REST_Response($core_quote, 400);
		}

		$vehicle_check = self::dispatcher_vehicle_for_payload($payload);
		if ($vehicle_check['error']) {
			return new WP_REST_Response($vehicle_check['response'], 409);
		}

		$vehicle_id = (int) $vehicle_check['vehicle_id'];
		$extras = self::normalize_extras($payload['extras']);
		$addons = self::chatbot_addons_total($vehicle_id, $extras);
		$base_price = (float) $core_quote['final_price'];
		$final_price = round($base_price + $addons['vehicle_adjustment'] + $addons['extras_total'], 2);

		$response = [
			'success' => true,
			'quote_id' => wp_generate_uuid4(),
			'currency' => $core_quote['currency'] ?? Options::get('currency', 'USD'),
			'final_price' => $final_price,
			'base_price' => round($base_price, 2),
			'addons' => $addons,
			'pricing_source' => $core_quote['pricing_source'] ?? '',
			'zone_name' => $core_quote['zone_name'] ?? '',
			'distance_km' => (float) ($core_quote['distance_km'] ?? 0),
			'duration_minutes' => (int) ($core_quote['duration_minutes'] ?? 0),
			'requires_approval' => !empty($core_quote['requires_approval']),
			'approval_message' => !empty($core_quote['requires_approval']) ? __('This trip can be requested, but dispatch should review it because part of the route is outside the normal operating area.', 'ridefleet-booking') : '',
			'vehicle' => self::dispatcher_vehicle_summary($vehicle_id),
			'compatible_vehicles' => self::compatible_vehicles((int) $payload['passengers'], (int) $payload['luggage']),
			'notice' => __('This is a ride quote. The dispatcher can create the booking after the caller confirms.', 'ridefleet-booking'),
		];

		return new WP_REST_Response($response);
	}

	public static function dispatcher_create_booking(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'dispatcher_booking', 30, 10 * MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many dispatcher booking attempts. Please wait and try again.', 'ridefleet-booking')], 429);
		}

		$payload = self::dispatcher_payload($request);
		$validation = self::validate_dispatcher_payload($payload);
		if ($validation) {
			return $validation;
		}

		$vehicle_check = self::dispatcher_vehicle_for_payload($payload);
		if ($vehicle_check['error']) {
			return new WP_REST_Response($vehicle_check['response'], 409);
		}
		$payload['vehicleId'] = (int) $vehicle_check['vehicle_id'];

		$core_quote = CoreBookingPricingEngine::quote_from_request(self::dispatcher_location_params($payload));
		if (empty($core_quote['success'])) {
			return new WP_REST_Response($core_quote, 400);
		}

		$addons = self::chatbot_addons_total((int) $payload['vehicleId'], $payload['extras']);
		$final_price = round((float) $core_quote['final_price'] + $addons['vehicle_adjustment'] + $addons['extras_total'], 2);
		if ($payload['verifiedFinalPrice'] > 0 && abs($payload['verifiedFinalPrice'] - $final_price) > 0.01) {
			return new WP_REST_Response(
				[
					'success' => false,
					'code' => 'fare_changed',
					'message' => __('The fare changed. Reconfirm the current quote with the caller before creating the booking.', 'ridefleet-booking'),
					'verified_price' => $final_price,
					'currency' => $core_quote['currency'] ?? Options::get('currency', 'USD'),
				],
				409
			);
		}

		if (!AvailabilityService::is_available(self::pickup_from_payload($payload), (int) $payload['vehicleId'])) {
			return new WP_REST_Response(['message' => __('This pickup time is not available.', 'ridefleet-booking')], 409);
		}

		$booking_payload = $payload;
		$booking_payload['source'] = 'virtual_dispatcher';
		$booking_payload['serviceType'] = 'phone_dispatch';
		$booking_payload['distance'] = (float) ($core_quote['distance_km'] ?? 0);
		$booking_payload['durationMinutes'] = (float) ($core_quote['duration_minutes'] ?? 0);
		$booking_payload['note'] = trim($payload['note'] . "\n" . __('Created by virtual dispatcher API.', 'ridefleet-booking'));

		$booking_quote = [
			'currency' => $core_quote['currency'] ?? Options::get('currency', 'USD'),
			'distanceUnit' => Options::get('distance_unit', 'km'),
			'subtotal' => $final_price,
			'taxTotal' => 0.0,
			'discountTotal' => 0.0,
			'total' => $final_price,
			'coupon' => ['valid' => false],
			'serviceType' => 'phone_dispatch',
			'breakdown' => [
				'pricingSource' => $core_quote['pricing_source'] ?? '',
				'zoneName' => $core_quote['zone_name'] ?? '',
				'addons' => $addons,
				'createdBy' => 'virtual_dispatcher_api',
			],
		];

		$booking = BookingRepository::create($booking_payload, $booking_quote);
		$needs_approval = !empty($core_quote['requires_approval']);
		BookingRepository::update_status((int) $booking['id'], $needs_approval ? 'pending_payment' : 'confirmed', 'unpaid');
		BookingRepository::add_meta((int) $booking['id'], '_dispatcher_api_event', ['type' => 'created', 'created_at' => current_time('mysql')]);
		if ($needs_approval) {
			BookingRepository::add_meta((int) $booking['id'], '_approval_required', '1');
			BookingRepository::add_meta((int) $booking['id'], '_service_area_status', ['status' => 'approval_required']);
		}
		NotificationService::booking_created((int) $booking['id']);

		$stored = BookingRepository::find((int) $booking['id']);
		return new WP_REST_Response(
			[
				'success' => true,
				'booking' => $stored ? self::booking_response($stored) : $booking,
				'message' => __('Booking created from virtual dispatcher API.', 'ridefleet-booking'),
			],
			201
		);
	}

	public static function dispatcher_get_booking(WP_REST_Request $request): WP_REST_Response {
		$booking = self::booking_by_number(sanitize_text_field((string) $request->get_param('booking')));
		if (!$booking) {
			return new WP_REST_Response(['message' => __('Booking not found.', 'ridefleet-booking')], 404);
		}

		return new WP_REST_Response(['success' => true, 'booking' => self::booking_response($booking)]);
	}

	public static function dispatcher_find_bookings(WP_REST_Request $request): WP_REST_Response {
		global $wpdb;

		$phone = sanitize_text_field((string) $request->get_param('phone'));
		$booking_number = sanitize_text_field((string) $request->get_param('booking'));
		if ($booking_number) {
			$booking = self::booking_by_number($booking_number);
			return new WP_REST_Response(['success' => (bool) $booking, 'bookings' => $booking ? [self::booking_response($booking)] : []], $booking ? 200 : 404);
		}
		if ('' === $phone) {
			return new WP_REST_Response(['message' => __('Provide a booking number or phone number.', 'ridefleet-booking')], 400);
		}

		$bookings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.* FROM {$wpdb->prefix}rfb_bookings b INNER JOIN {$wpdb->prefix}rfb_customers c ON c.id = b.customer_id WHERE c.phone = %s ORDER BY b.created_at DESC LIMIT 10",
				$phone
			)
		);

		return new WP_REST_Response(['success' => true, 'bookings' => array_map([self::class, 'booking_response'], $bookings ?: [])]);
	}

	public static function dispatcher_change_request(WP_REST_Request $request): WP_REST_Response {
		$booking = self::booking_by_number(sanitize_text_field((string) $request->get_param('booking')));
		if (!$booking) {
			return new WP_REST_Response(['message' => __('Booking not found.', 'ridefleet-booking')], 404);
		}

		$changes = [
			'pickupAddress' => sanitize_textarea_field((string) $request->get_param('pickupAddress')),
			'dropoffAddress' => sanitize_textarea_field((string) $request->get_param('dropoffAddress')),
			'pickupTime' => sanitize_text_field((string) $request->get_param('pickupTime')),
			'passengers' => $request->get_param('passengers') ? max(1, absint($request->get_param('passengers'))) : null,
			'luggage' => $request->get_param('luggage') ? max(0, absint($request->get_param('luggage'))) : null,
			'note' => sanitize_textarea_field((string) $request->get_param('note')),
		];
		$changes = array_filter($changes, static fn($value): bool => null !== $value && '' !== $value);
		if (!$changes) {
			return new WP_REST_Response(['message' => __('Describe what should change before submitting the request.', 'ridefleet-booking')], 400);
		}

		$request_payload = [
			'status' => 'pending_admin_review',
			'requested_at' => current_time('mysql'),
			'requested_by' => 'virtual_dispatcher_api',
			'changes' => $changes,
		];
		BookingRepository::add_meta((int) $booking->id, '_change_request', $request_payload);
		BookingRepository::add_meta((int) $booking->id, '_approval_required', '1');

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __('Change request submitted for admin approval. The original booking remains active until approved.', 'ridefleet-booking'),
				'booking' => self::booking_response($booking),
				'change_request' => $request_payload,
			],
			201
		);
	}

	public static function route_cache_get(WP_REST_Request $request): WP_REST_Response {
		$origin = sanitize_text_field((string) $request->get_param('origin'));
		$destination = sanitize_text_field((string) $request->get_param('destination'));

		if (!$origin || !$destination) {
			return new WP_REST_Response(['cached' => false], 400);
		}

		$row = RouteCache::get($origin, $destination);
		if (!$row) {
			return new WP_REST_Response(['cached' => false]);
		}

		return new WP_REST_Response(
			[
				'cached' => true,
				'distance' => (float) $row->distance_value,
				'distanceUnit' => $row->distance_unit,
				'durationMinutes' => round(((int) $row->duration_seconds) / 60),
				'polyline' => $row->overview_polyline,
			]
		);
	}

	public static function route_cache_put(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'route_cache', 40, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many route cache writes. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		RouteCache::put(
			sanitize_text_field((string) $request->get_param('origin')),
			sanitize_text_field((string) $request->get_param('destination')),
			max(0, (float) $request->get_param('distance')),
			max(0, (int) $request->get_param('durationSeconds')),
			sanitize_text_field((string) $request->get_param('polyline'))
		);

		return new WP_REST_Response(['stored' => true], 201);
	}

	public static function chatbot_calculate_price(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'chatbot_price', 60, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many price requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$result = CoreBookingPricingEngine::quote_from_request(self::chatbot_location_params($request));
		return new WP_REST_Response($result, empty($result['success']) ? 400 : 200);
	}

	public static function chatbot_create_booking(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'chatbot_booking', 10, 10 * MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many booking attempts. Please wait and try again.', 'ridefleet-booking')], 429);
		}

		// Idempotency: same client + same payload returns the existing booking.
		$idempotency_key = sanitize_text_field((string) ($request->get_header('Idempotency-Key') ?: $request->get_param('idempotency_key')));
		if ('' !== $idempotency_key) {
			$payload_hash = md5(wp_json_encode([
				$idempotency_key,
				(string) $request->get_param('pickup_address'),
				(string) $request->get_param('dropoff_address'),
				(string) $request->get_param('customer_phone'),
				(string) $request->get_param('pickup_time'),
				(float) $request->get_param('final_price'),
			]));
			$cache_key = 'rfb_idem_' . $payload_hash;
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				return new WP_REST_Response($cached, 200);
			}
		}

		$payload = [
			'pickup_address' => sanitize_textarea_field((string) $request->get_param('pickup_address')),
			'dropoff_address' => sanitize_textarea_field((string) $request->get_param('dropoff_address')),
			'customer_name' => sanitize_text_field((string) $request->get_param('customer_name')),
			'customer_phone' => sanitize_text_field((string) $request->get_param('customer_phone')),
			'pickup_time' => sanitize_text_field((string) $request->get_param('pickup_time')),
			'final_price' => max(0, (float) $request->get_param('final_price')),
			'passengers' => max(1, absint($request->get_param('passengers') ?: 1)),
			'luggage' => max(0, absint($request->get_param('luggage') ?: 0)),
			'vehicle_id' => absint($request->get_param('vehicle_id')),
			'vehicle_name' => sanitize_text_field((string) $request->get_param('vehicle_name')),
			'extras' => self::normalize_extras($request->get_param('extras')),
			'coupon_code' => strtoupper(sanitize_text_field((string) $request->get_param('coupon_code'))),
		];

		foreach (['pickup_address', 'dropoff_address', 'customer_name', 'customer_phone', 'pickup_time'] as $key) {
			if ('' === trim((string) $payload[$key])) {
				return new WP_REST_Response(['message' => sprintf(__('%s is required.', 'ridefleet-booking'), $key)], 400);
			}
		}

		$pickup_timestamp = strtotime($payload['pickup_time']);
		if (!$pickup_timestamp) {
			return new WP_REST_Response(['message' => __('Enter a valid pickup time.', 'ridefleet-booking')], 400);
		}

		$requires_manual_dispatch = (bool) absint($request->get_param('requires_manual_dispatch'));
		if ($requires_manual_dispatch) {
			// Dispatch_pending_quote path — no geocoding available, skip pricing entirely.
			// Dispatch will call the customer and confirm fare before pickup.
			$name_parts = preg_split('/\s+/', trim($payload['customer_name']), 2);
			$booking_payload = [
				'serviceType' => 'chatbot',
				'source' => 'chatbot',
				'transferType' => 'one_way',
				'pickupAddress' => $payload['pickup_address'],
				'dropoffAddress' => $payload['dropoff_address'],
				'waypoints' => [],
				'pickupDate' => wp_date('Y-m-d', $pickup_timestamp),
				'pickupTime' => wp_date('H:i', $pickup_timestamp),
				'distance' => 0,
				'durationMinutes' => 0,
				'passengers' => $payload['passengers'],
				'luggage' => $payload['luggage'],
				'vehicleId' => 0,
				'routeId' => 0,
				'extras' => [],
				'couponCode' => '',
				'customerFirstName' => $name_parts[0] ?? $payload['customer_name'],
				'customerLastName' => $name_parts[1] ?? '',
				'customerEmail' => '',
				'customerPhone' => $payload['customer_phone'],
				'status' => 'pending_dispatch',
				'paymentStatus' => 'fare_pending',
				'note' => __('⚠️ Manually dispatched booking — fare and vehicle to be confirmed by dispatch before pickup.', 'ridefleet-booking'),
			];
			$booking_quote = [
				'currency' => Options::get('currency', 'USD'),
				'distanceUnit' => Options::get('distance_unit', 'km'),
				'subtotal' => 0.0,
				'taxTotal' => 0.0,
				'discountTotal' => 0.0,
				'total' => 0.0,
				'coupon' => ['valid' => false],
				'serviceType' => 'chatbot',
				'breakdown' => ['pricingSource' => 'manual_dispatch', 'zoneName' => '', 'addons' => []],
			];
			$booking = BookingRepository::create($booking_payload, $booking_quote);
			BookingRepository::add_meta((int) $booking['id'], '_requires_manual_dispatch', '1');
			NotificationService::booking_created((int) $booking['id']);
			$response_body = [
				'success' => true,
				'booking_id' => $booking['bookingNumber'],
				'internal_id' => (int) $booking['id'],
				'status' => $booking['status'],
			];
			if (isset($cache_key)) {
				set_transient($cache_key, $response_body, HOUR_IN_SECONDS);
			}
			return new WP_REST_Response($response_body, 201);
		}

		$quote_params = array_merge(self::chatbot_location_params($request), $payload);
		$quote = CoreBookingPricingEngine::quote_from_request($quote_params);
		if (empty($quote['success'])) {
			return new WP_REST_Response($quote, 400);
		}
		$base_price = (float) $quote['final_price'];
		$addons = self::chatbot_addons_total($payload['vehicle_id'], $payload['extras']);
		$quote['base_price'] = round($base_price, 2);
		$quote['final_price'] = round($base_price + $addons['vehicle_adjustment'] + $addons['extras_total'], 2);
		$quote['addons'] = $addons;

		if (abs((float) $quote['final_price'] - (float) $payload['final_price']) > 0.01) {
			return new WP_REST_Response(
				[
					'success' => false,
					'error' => true,
					'message' => __('The submitted fare no longer matches the verified fare. Please recalculate before booking.', 'ridefleet-booking'),
					'verified_price' => (float) $quote['final_price'],
					'currency' => $quote['currency'],
				],
				409
			);
		}

		$name_parts = preg_split('/\s+/', trim($payload['customer_name']), 2);
		$booking_payload = [
			'serviceType' => 'chatbot',
			'source' => 'chatbot',
			'transferType' => 'one_way',
			'pickupAddress' => $payload['pickup_address'],
			'dropoffAddress' => $payload['dropoff_address'],
			'waypoints' => [],
			'pickupDate' => wp_date('Y-m-d', $pickup_timestamp),
			'pickupTime' => wp_date('H:i', $pickup_timestamp),
			'distance' => max(0, (float) ($quote['distance_km'] ?? 0)),
			'durationMinutes' => max(0, (float) ($quote['duration_minutes'] ?? 0)),
			'passengers' => $payload['passengers'],
			'luggage' => $payload['luggage'],
			'vehicleId' => $payload['vehicle_id'],
			'routeId' => 0,
			'extras' => $payload['extras'],
			'couponCode' => '' !== $payload['coupon_code'] && CouponService::mark_used($payload['coupon_code']) ? $payload['coupon_code'] : '',
			'customerFirstName' => $name_parts[0] ?? $payload['customer_name'],
			'customerLastName' => $name_parts[1] ?? '',
			'customerEmail' => '',
			'customerPhone' => $payload['customer_phone'],
			'note' => trim(sprintf(__('Created by external AI chatbot via write-only booking endpoint. Vehicle: %s.', 'ridefleet-booking'), $payload['vehicle_name'])),
		];
		$booking_quote = [
			'currency' => $quote['currency'],
			'distanceUnit' => Options::get('distance_unit', 'km'),
			'subtotal' => (float) $quote['final_price'],
			'taxTotal' => 0.0,
			'discountTotal' => 0.0,
			'total' => (float) $quote['final_price'],
			'coupon' => ['valid' => false],
			'serviceType' => 'chatbot',
			'breakdown' => [
				'pricingSource' => $quote['pricing_source'] ?? '',
				'zoneName' => $quote['zone_name'] ?? '',
				'addons' => $quote['addons'] ?? [],
			],
		];

		$booking = BookingRepository::create($booking_payload, $booking_quote);
		$service_area_status = CoreBookingPricingEngine::service_area_status_from_request(
			[
				'pickup_address' => $booking_payload['pickupAddress'],
				'dropoff_address' => $booking_payload['dropoffAddress'],
			]
		);
		if ('approval_required' === ($service_area_status['status'] ?? '')) {
			BookingRepository::add_meta((int) $booking['id'], '_approval_required', '1');
			BookingRepository::add_meta((int) $booking['id'], '_service_area_status', $service_area_status);
		}
		NotificationService::booking_created((int) $booking['id']);

		$response_body = [
			'success' => true,
			'booking_id' => $booking['bookingNumber'],
			'internal_id' => (int) $booking['id'],
			'status' => $booking['status'],
		];

		if (isset($cache_key)) {
			set_transient($cache_key, $response_body, HOUR_IN_SECONDS);
		}

		return new WP_REST_Response($response_body, 201);
	}

	public static function chatbot_validate_coupon(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'chatbot_coupon', 30, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['valid' => false, 'message' => __('Too many requests.', 'ridefleet-booking')], 429);
		}

		$code = sanitize_text_field((string) $request->get_param('code'));
		$subtotal = max(0, (float) $request->get_param('subtotal'));

		$result = CouponService::discount($code, $subtotal);
		return new WP_REST_Response([
			'valid' => !empty($result['valid']),
			'code' => (string) ($result['code'] ?? ''),
			'amount' => (float) ($result['amount'] ?? 0),
			'message' => (string) ($result['message'] ?? ''),
		]);
	}

	public static function chatbot_place_search(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'chatbot_places', 90, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many place searches. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$input = sanitize_text_field((string) $request->get_param('input'));
		if (strlen($input) < 3) {
			return new WP_REST_Response(['success' => false, 'predictions' => [], 'message' => __('Type at least three characters.', 'ridefleet-booking')], 400);
		}

		$key = Options::get('google_maps_api_key', '');
		if (!$key) {
			return new WP_REST_Response(['success' => false, 'predictions' => [], 'message' => __('Google Maps is not configured in RideFleet.', 'ridefleet-booking')], 400);
		}

		// ── Geographic bias ──────────────────────────────────────────────────────────────
		// Priority 1: caller-supplied coordinates (frontend passes pickup coords for dropoff)
		// Priority 2: service area center from RideFleet settings (home region fallback)
		$bias_lat = is_numeric($request->get_param('lat')) ? (float) $request->get_param('lat') : null;
		$bias_lng = is_numeric($request->get_param('lng')) ? (float) $request->get_param('lng') : null;
		$bias_radius_m = 50000; // 50 km soft bias — not a hard filter

		if (null === $bias_lat || null === $bias_lng) {
			// Fall back to the global service area centre configured in RideFleet → Settings.
			$svc_raw = Options::get('core_rules', []);
			$svc_area = is_array($svc_raw) ? ($svc_raw['global_service_area'] ?? []) : [];
			if (!empty($svc_area['enabled']) && !empty($svc_area['data'])) {
				$svc_data = json_decode((string) $svc_area['data'], true);
				$centre_lat = isset($svc_data['center']['lat']) ? (float) $svc_data['center']['lat'] : null;
				$centre_lng = isset($svc_data['center']['lng']) ? (float) $svc_data['center']['lng'] : null;
				if (null !== $centre_lat && null !== $centre_lng) {
					$bias_lat = $centre_lat;
					$bias_lng = $centre_lng;
					// Convert km radius → metres for the API; clamp to 50 km max for Places bias.
					$r_km = max(5, min(200, (float) ($svc_data['radius'] ?? 50)));
					$bias_radius_m = (int) ($r_km * 1000);
				}
			}
		}

		// Cache key includes location context to avoid serving results biased for a different
		// region to a user in a different area.
		$loc_context = (null !== $bias_lat) ? '|' . round($bias_lat, 2) . ',' . round($bias_lng, 2) : '';
		$cache_key = 'rfb_chatbot_places_' . md5(strtolower($input) . $loc_context);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return new WP_REST_Response($cached + ['cached' => true]);
		}

		$query_args = [
			'input' => $input,
			'key'   => $key,
			'types' => 'geocode|establishment',
		];
		// Add soft geographic bias when we have coordinates.
		// Using location+radius (not strictbounds) so users can still search outside the area.
		if (null !== $bias_lat && null !== $bias_lng) {
			$query_args['location'] = $bias_lat . ',' . $bias_lng;
			$query_args['radius']   = $bias_radius_m;
		}

		$response = wp_remote_get(
			add_query_arg(
				$query_args,
				'https://maps.googleapis.com/maps/api/place/autocomplete/json'
			),
			['timeout' => 5]
		);

		if (is_wp_error($response)) {
			return new WP_REST_Response(['success' => false, 'predictions' => [], 'message' => $response->get_error_message()], 500);
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$predictions = [];
		foreach ((array) ($body['predictions'] ?? []) as $prediction) {
			$predictions[] = [
				'place_id' => sanitize_text_field((string) ($prediction['place_id'] ?? '')),
				'description' => sanitize_text_field((string) ($prediction['description'] ?? '')),
				'main_text' => sanitize_text_field((string) ($prediction['structured_formatting']['main_text'] ?? '')),
				'secondary_text' => sanitize_text_field((string) ($prediction['structured_formatting']['secondary_text'] ?? '')),
				'types' => array_values(array_map('sanitize_text_field', (array) ($prediction['types'] ?? []))),
			];
		}

		$result = [
			'success' => true,
			'predictions' => array_values(array_filter($predictions, static fn(array $item): bool => '' !== $item['description'])),
		];
		set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
		return new WP_REST_Response($result);
	}

	public static function chatbot_place_details(WP_REST_Request $request): WP_REST_Response {
		if (!RateLimiter::check($request, 'chatbot_place_details', 120, MINUTE_IN_SECONDS)) {
			return new WP_REST_Response(['message' => __('Too many place detail requests. Please wait a moment and try again.', 'ridefleet-booking')], 429);
		}

		$place_id = sanitize_text_field((string) $request->get_param('place_id'));
		if ('' === $place_id) {
			return new WP_REST_Response(['success' => false, 'message' => __('Place ID is required.', 'ridefleet-booking')], 400);
		}

		$key = Options::get('google_maps_api_key', '');
		if (!$key) {
			return new WP_REST_Response(['success' => false, 'message' => __('Google Maps is not configured in RideFleet.', 'ridefleet-booking')], 400);
		}

		$cache_key = 'rfb_chatbot_place_details_' . md5($place_id);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return new WP_REST_Response($cached + ['cached' => true]);
		}

		$response = wp_remote_get(
			add_query_arg(
				[
					'place_id' => $place_id,
					'fields' => 'place_id,formatted_address,name,geometry,types',
					'key' => $key,
				],
				'https://maps.googleapis.com/maps/api/place/details/json'
			),
			['timeout' => 5]
		);

		if (is_wp_error($response)) {
			return new WP_REST_Response(['success' => false, 'message' => $response->get_error_message()], 500);
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$result = is_array($body['result'] ?? null) ? $body['result'] : [];
		$location = is_array($result['geometry']['location'] ?? null) ? $result['geometry']['location'] : [];
		if (!isset($location['lat'], $location['lng'])) {
			return new WP_REST_Response(['success' => false, 'message' => __('Place details did not include coordinates.', 'ridefleet-booking')], 404);
		}

		$place = [
			'place_id' => sanitize_text_field((string) ($result['place_id'] ?? $place_id)),
			'description' => sanitize_text_field((string) ($result['formatted_address'] ?? $result['name'] ?? '')),
			'main_text' => sanitize_text_field((string) ($result['name'] ?? '')),
			'secondary_text' => sanitize_text_field((string) ($result['formatted_address'] ?? '')),
			'types' => array_values(array_map('sanitize_text_field', (array) ($result['types'] ?? []))),
			'lat' => (float) $location['lat'],
			'lng' => (float) $location['lng'],
		];
		$response_body = ['success' => true, 'place' => $place];
		set_transient($cache_key, $response_body, DAY_IN_SECONDS);
		return new WP_REST_Response($response_body);
	}

	private static function record_quote_event(WP_REST_Request $request, float $distance, int $duration_seconds, float $total): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_quote_events';

		$wpdb->insert(
			$table,
			[
				'session_id' => sanitize_text_field((string) ($request->get_param('sessionId') ?: wp_generate_uuid4())),
				'service_type' => sanitize_text_field((string) ($request->get_param('serviceType') ?: 'distance')),
				'pickup_address' => sanitize_textarea_field((string) $request->get_param('pickupAddress')),
				'dropoff_address' => sanitize_textarea_field((string) $request->get_param('dropoffAddress')),
				'distance_value' => $distance,
				'duration_seconds' => $duration_seconds,
				'quoted_total' => round($total, 2),
				'currency' => Options::get('currency', 'USD'),
				'created_at' => current_time('mysql'),
			],
			['%s', '%s', '%s', '%s', '%f', '%d', '%f', '%s', '%s']
		);
	}

	private static function boundary_validation_from_request(WP_REST_Request $request): ?WP_REST_Response {
		$params = [
			'pickup_address' => sanitize_textarea_field((string) $request->get_param('pickupAddress')),
			'dropoff_address' => sanitize_textarea_field((string) $request->get_param('dropoffAddress')),
			'pickup_lat' => sanitize_text_field((string) $request->get_param('pickup_lat')),
			'pickup_lng' => sanitize_text_field((string) $request->get_param('pickup_lng')),
			'dropoff_lat' => sanitize_text_field((string) $request->get_param('dropoff_lat')),
			'dropoff_lng' => sanitize_text_field((string) $request->get_param('dropoff_lng')),
		];

		return self::boundary_validation($params);
	}

	private static function boundary_validation_from_payload(array $payload): ?WP_REST_Response {
		$params = [
			'pickup_address' => sanitize_textarea_field((string) ($payload['pickupAddress'] ?? '')),
			'dropoff_address' => sanitize_textarea_field((string) ($payload['dropoffAddress'] ?? '')),
			'pickup_lat' => sanitize_text_field((string) ($payload['pickup_lat'] ?? '')),
			'pickup_lng' => sanitize_text_field((string) ($payload['pickup_lng'] ?? '')),
			'dropoff_lat' => sanitize_text_field((string) ($payload['dropoff_lat'] ?? '')),
			'dropoff_lng' => sanitize_text_field((string) ($payload['dropoff_lng'] ?? '')),
		];

		return self::boundary_validation($params);
	}

	private static function boundary_validation(array $params): ?WP_REST_Response {
		if ('' === trim((string) $params['pickup_address']) || '' === trim((string) $params['dropoff_address'])) {
			return null;
		}

		$result = CoreBookingPricingEngine::service_area_status_from_request($params);
		if ('outside' === ($result['status'] ?? '')) {
			$settings = CoreBookingPricingEngine::settings();
			$service_area = $settings['global_service_area'] ?? [];
			return new WP_REST_Response(
				[
					'success' => false,
					'error' => true,
					'code' => 'outside_service_area',
					'message' => sanitize_textarea_field((string) (($service_area['error_message'] ?? '') ?: __('This ride is outside our service area.', 'ridefleet-booking'))),
					'final_price' => null,
					'currency' => Options::get('currency', 'USD'),
					'serviceAreaStatus' => $result,
				],
				400
			);
		}

		return null;
	}

	private static function chatbot_location_params(WP_REST_Request $request): array {
		$params = [];
		foreach (['pickup_address', 'dropoff_address', 'pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $key) {
			$value = $request->get_param($key);
			if (null !== $value) {
				$params[$key] = is_scalar($value) ? sanitize_text_field((string) $value) : '';
			}
		}

		return $params;
	}

	private static function normalize_extras(mixed $extras): array {
		if (!is_array($extras)) {
			return [];
		}

		$normalized = [];
		foreach ($extras as $extra) {
			if (!is_array($extra)) {
				continue;
			}

			$normalized[] = [
				'id' => absint($extra['id'] ?? 0),
				'quantity' => max(1, absint($extra['quantity'] ?? 1)),
			];
		}

		return $normalized;
	}

	private static function chatbot_addons_total(int $vehicle_id, array $extras): array {
		$vehicle_adjustment = 0.0;
		if ($vehicle_id && 'rfb_vehicle' === get_post_type($vehicle_id)) {
			$vehicle_adjustment = (float) get_post_meta($vehicle_id, 'rfb_price_adjustment', true);
		}

		$extras_total = 0.0;
		foreach ($extras as $extra) {
			$id = absint($extra['id'] ?? 0);
			$quantity = max(1, absint($extra['quantity'] ?? 1));
			if ($id && 'rfb_extra' === get_post_type($id)) {
				$extras_total += ((float) get_post_meta($id, 'rfb_price', true)) * $quantity;
			}
		}

		return [
			'vehicle_adjustment' => round($vehicle_adjustment, 2),
			'extras_total' => round($extras_total, 2),
		];
	}

	private static function booking_payload(WP_REST_Request $request): array {
		return [
			'serviceType' => sanitize_text_field((string) ($request->get_param('serviceType') ?: 'standard')),
			'transferType' => sanitize_text_field((string) ($request->get_param('transferType') ?: 'one_way')),
			'pickupAddress' => sanitize_textarea_field((string) $request->get_param('pickupAddress')),
			'dropoffAddress' => sanitize_textarea_field((string) $request->get_param('dropoffAddress')),
			'pickup_lat' => sanitize_text_field((string) $request->get_param('pickup_lat')),
			'pickup_lng' => sanitize_text_field((string) $request->get_param('pickup_lng')),
			'dropoff_lat' => sanitize_text_field((string) $request->get_param('dropoff_lat')),
			'dropoff_lng' => sanitize_text_field((string) $request->get_param('dropoff_lng')),
			'waypoints' => [],
			'pickupDate' => sanitize_text_field((string) $request->get_param('pickupDate')),
			'pickupTime' => sanitize_text_field((string) $request->get_param('pickupTime')),
			'distance' => max(0, (float) $request->get_param('distance')),
			'durationMinutes' => max(0, (float) $request->get_param('durationMinutes')),
			'passengers' => max(1, absint($request->get_param('passengers') ?: 1)),
			'luggage' => max(0, absint($request->get_param('luggage') ?: 0)),
			'vehicleId' => absint($request->get_param('vehicleId')),
			'routeId' => absint($request->get_param('routeId')),
			'extras' => self::normalize_extras($request->get_param('extras')),
			'couponCode' => strtoupper(sanitize_text_field((string) $request->get_param('couponCode'))),
			'customerFirstName' => sanitize_text_field((string) $request->get_param('customerFirstName')),
			'customerLastName' => sanitize_text_field((string) $request->get_param('customerLastName')),
			'customerEmail' => sanitize_email((string) $request->get_param('customerEmail')),
			'customerPhone' => sanitize_text_field((string) $request->get_param('customerPhone')),
			'note' => sanitize_textarea_field((string) $request->get_param('note')),
		];
	}

	private static function validate_booking_payload(array $payload): ?WP_REST_Response {
		$required = [
			'pickupAddress' => __('Pickup address is required.', 'ridefleet-booking'),
			'dropoffAddress' => __('Drop-off address is required.', 'ridefleet-booking'),
			'pickupDate' => __('Pickup date is required.', 'ridefleet-booking'),
			'pickupTime' => __('Pickup time is required.', 'ridefleet-booking'),
			'customerFirstName' => __('First name is required.', 'ridefleet-booking'),
			'customerLastName' => __('Last name is required.', 'ridefleet-booking'),
			'customerEmail' => __('Email is required.', 'ridefleet-booking'),
			'customerPhone' => __('Phone is required.', 'ridefleet-booking'),
		];

		foreach ($required as $key => $message) {
			if ('' === trim((string) ($payload[$key] ?? ''))) {
				return new WP_REST_Response(['message' => $message], 400);
			}
		}

		if (!is_email((string) $payload['customerEmail'])) {
			return new WP_REST_Response(['message' => __('Enter a valid email address.', 'ridefleet-booking')], 400);
		}

		// vehicleId=0 is allowed — dispatch will assign the vehicle manually

		if ((float) $payload['distance'] <= 0 || (float) $payload['durationMinutes'] <= 0) {
			return new WP_REST_Response(['message' => __('Calculate a route before reserving.', 'ridefleet-booking')], 400);
		}

		if (!self::pickup_from_payload($payload)) {
			return new WP_REST_Response(['message' => __('Enter a valid pickup date and time.', 'ridefleet-booking')], 400);
		}

		return null;
	}

	private static function pickup_from_request(WP_REST_Request $request): string {
		return self::pickup_from_payload(
			[
				'pickupDate' => sanitize_text_field((string) $request->get_param('pickupDate')),
				'pickupTime' => sanitize_text_field((string) $request->get_param('pickupTime')),
			]
		);
	}

	private static function pickup_from_payload(array $payload): string {
		$date = sanitize_text_field($payload['pickupDate'] ?? '');
		$time = sanitize_text_field($payload['pickupTime'] ?? '');
		$timestamp = ($date && $time) ? strtotime($date . ' ' . $time) : false;

		return $timestamp ? wp_date('Y-m-d H:i:s', $timestamp) : '';
	}

	private static function dispatcher_payload(WP_REST_Request $request): array {
		$pickup_time = sanitize_text_field((string) $request->get_param('pickupTime'));
		$pickup_date = sanitize_text_field((string) $request->get_param('pickupDate'));
		$pickup_clock = sanitize_text_field((string) $request->get_param('pickupClock'));
		if ($pickup_time && (!$pickup_date || !$pickup_clock)) {
			$timestamp = strtotime($pickup_time);
			if ($timestamp) {
				$pickup_date = wp_date('Y-m-d', $timestamp);
				$pickup_clock = wp_date('H:i', $timestamp);
			}
		}

		$name = sanitize_text_field((string) ($request->get_param('customerName') ?: $request->get_param('customer_name')));
		$first_name = sanitize_text_field((string) ($request->get_param('customerFirstName') ?: $request->get_param('customer_first_name')));
		$last_name = sanitize_text_field((string) ($request->get_param('customerLastName') ?: $request->get_param('customer_last_name')));
		if (!$first_name && $name) {
			$parts = preg_split('/\s+/', trim($name), 2);
			$first_name = $parts[0] ?? $name;
			$last_name = $parts[1] ?? '';
		}
		$duration_minutes = $request->get_param('durationMinutes');
		if (null === $duration_minutes || '' === $duration_minutes) {
			$duration_minutes = $request->get_param('duration_minutes');
		}
		$vehicle_id = $request->get_param('vehicleId');
		if (null === $vehicle_id || '' === $vehicle_id) {
			$vehicle_id = $request->get_param('vehicle_id');
		}
		$verified_final_price = $request->get_param('verifiedFinalPrice');
		if (null === $verified_final_price || '' === $verified_final_price) {
			$verified_final_price = $request->get_param('final_price');
		}

		return [
			'serviceType' => 'phone_dispatch',
			'transferType' => sanitize_text_field((string) ($request->get_param('transferType') ?: 'one_way')),
			'pickupAddress' => sanitize_textarea_field((string) ($request->get_param('pickupAddress') ?: $request->get_param('pickup_address'))),
			'dropoffAddress' => sanitize_textarea_field((string) ($request->get_param('dropoffAddress') ?: $request->get_param('dropoff_address'))),
			'pickup_lat' => is_numeric($request->get_param('pickup_lat')) ? (float) $request->get_param('pickup_lat') : null,
			'pickup_lng' => is_numeric($request->get_param('pickup_lng')) ? (float) $request->get_param('pickup_lng') : null,
			'dropoff_lat' => is_numeric($request->get_param('dropoff_lat')) ? (float) $request->get_param('dropoff_lat') : null,
			'dropoff_lng' => is_numeric($request->get_param('dropoff_lng')) ? (float) $request->get_param('dropoff_lng') : null,
			'waypoints' => [],
			'pickupDate' => $pickup_date,
			'pickupTime' => $pickup_clock,
			'distance' => max(0, (float) ($request->get_param('distance') ?: 0)),
			'durationMinutes' => max(0, (float) ($duration_minutes ?: 0)),
			'passengers' => max(1, absint($request->get_param('passengers') ?: 1)),
			'luggage' => max(0, absint($request->get_param('luggage') ?: 0)),
			'vehicleId' => absint($vehicle_id),
			'routeId' => 0,
			'extras' => self::normalize_extras($request->get_param('extras')),
			'couponCode' => '',
			'customerFirstName' => $first_name,
			'customerLastName' => $last_name,
			'customerEmail' => sanitize_email((string) ($request->get_param('customerEmail') ?: $request->get_param('customer_email'))),
			'customerPhone' => sanitize_text_field((string) ($request->get_param('customerPhone') ?: $request->get_param('customer_phone'))),
			'note' => sanitize_textarea_field((string) $request->get_param('note')),
			'verifiedFinalPrice' => max(0, (float) ($verified_final_price ?: 0)),
		];
	}

	private static function dispatcher_location_params(array $payload): array {
		$params = [
			'pickup_address' => $payload['pickupAddress'] ?? '',
			'dropoff_address' => $payload['dropoffAddress'] ?? '',
		];
		foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $key) {
			if (isset($payload[$key]) && is_numeric($payload[$key])) {
				$params[$key] = (float) $payload[$key];
			}
		}

		return $params;
	}

	private static function validate_dispatcher_payload(array $payload): ?WP_REST_Response {
		foreach (['pickupAddress', 'dropoffAddress', 'pickupDate', 'pickupTime', 'customerFirstName', 'customerPhone'] as $key) {
			if ('' === trim((string) ($payload[$key] ?? ''))) {
				return new WP_REST_Response(['message' => sprintf(__('%s is required.', 'ridefleet-booking'), $key)], 400);
			}
		}

		if (!self::pickup_from_payload($payload)) {
			return new WP_REST_Response(['message' => __('Enter a valid pickup date and time.', 'ridefleet-booking')], 400);
		}

		return null;
	}

	private static function dispatcher_vehicle_for_payload(array $payload): array {
		$passengers = max(1, (int) ($payload['passengers'] ?? 1));
		$luggage = max(0, (int) ($payload['luggage'] ?? 0));
		$compatible = self::compatible_vehicles($passengers, $luggage);
		if (!$compatible) {
			return [
				'error' => true,
				'vehicle_id' => 0,
				'response' => [
					'success' => false,
					'code' => 'no_vehicle_available',
					'message' => __('No configured vehicle can handle this passenger/luggage count. The dispatcher should contact the business owner before accepting the ride.', 'ridefleet-booking'),
					'passengers' => $passengers,
					'luggage' => $luggage,
				],
			];
		}

		$vehicle_id = absint($payload['vehicleId'] ?? 0);
		if ($vehicle_id) {
			foreach ($compatible as $vehicle) {
				if ((int) $vehicle['id'] === $vehicle_id) {
					return ['error' => false, 'vehicle_id' => $vehicle_id, 'response' => []];
				}
			}

			return [
				'error' => true,
				'vehicle_id' => 0,
				'response' => [
					'success' => false,
					'code' => 'selected_vehicle_not_compatible',
					'message' => __('The selected vehicle cannot handle this passenger/luggage count.', 'ridefleet-booking'),
					'compatible_vehicles' => $compatible,
				],
			];
		}

		return ['error' => false, 'vehicle_id' => (int) $compatible[0]['id'], 'response' => []];
	}

	private static function compatible_vehicles(int $passengers, int $luggage): array {
		$query = new \WP_Query(
			[
				'post_type' => 'rfb_vehicle',
				'post_status' => 'publish',
				'posts_per_page' => 50,
				'orderby' => 'menu_order title',
				'order' => 'ASC',
			]
		);

		$vehicles = [];
		foreach ($query->posts as $post) {
			$capacity = absint(get_post_meta($post->ID, 'rfb_passenger_capacity', true) ?: 4);
			$bag_capacity = absint(get_post_meta($post->ID, 'rfb_luggage_capacity', true) ?: 2);
			if ($capacity < $passengers || $bag_capacity < $luggage) {
				continue;
			}

			$vehicles[] = [
				'id' => (int) $post->ID,
				'name' => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
				'passengers' => $capacity,
				'luggage' => $bag_capacity,
				'priceAdjustment' => (float) get_post_meta($post->ID, 'rfb_price_adjustment', true),
			];
		}

		return $vehicles;
	}

	private static function dispatcher_vehicle_summary(int $vehicle_id): ?array {
		if (!$vehicle_id || 'rfb_vehicle' !== get_post_type($vehicle_id)) {
			return null;
		}

		return [
			'id' => $vehicle_id,
			'name' => html_entity_decode(get_the_title($vehicle_id), ENT_QUOTES, get_bloginfo('charset')),
			'passengers' => absint(get_post_meta($vehicle_id, 'rfb_passenger_capacity', true) ?: 4),
			'luggage' => absint(get_post_meta($vehicle_id, 'rfb_luggage_capacity', true) ?: 2),
		];
	}

	private static function auto_detect_route(mixed $pickup_lat, mixed $pickup_lng, mixed $dropoff_lat, mixed $dropoff_lng): int {
		if (!is_numeric($pickup_lat) || !is_numeric($pickup_lng) || !is_numeric($dropoff_lat) || !is_numeric($dropoff_lng)) {
			return 0;
		}

		return QuoteCalculator::find_route_by_coords(
			['lat' => (float) $pickup_lat, 'lng' => (float) $pickup_lng],
			['lat' => (float) $dropoff_lat, 'lng' => (float) $dropoff_lng]
		);
	}

	private static function booking_by_number(string $booking_number): ?object {
		global $wpdb;
		if ('' === trim($booking_number)) {
			return null;
		}

		$booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_bookings WHERE booking_number = %s LIMIT 1", $booking_number));
		return $booking ?: null;
	}

	private static function booking_response(object $booking): array {
		$customer = !empty($booking->customer_id) ? BookingRepository::customer((int) $booking->customer_id) : null;

		return [
			'id' => (int) $booking->id,
			'booking_number' => (string) $booking->booking_number,
			'status' => (string) $booking->status,
			'payment_status' => (string) $booking->payment_status,
			'source' => (string) $booking->source,
			'pickup_address' => (string) $booking->pickup_address,
			'dropoff_address' => (string) $booking->dropoff_address,
			'pickup_at' => (string) $booking->pickup_at,
			'distance' => (float) $booking->distance_value,
			'distance_unit' => (string) $booking->distance_unit,
			'duration_minutes' => (int) round(((int) $booking->duration_seconds) / 60),
			'passengers' => (int) $booking->passengers,
			'luggage' => (int) $booking->luggage,
			'vehicle_id' => (int) $booking->vehicle_id,
			'total' => (float) $booking->total,
			'currency' => (string) $booking->currency,
			'customer' => $customer ? [
				'name' => trim((string) $customer->first_name . ' ' . (string) $customer->last_name),
				'email' => (string) $customer->email,
				'phone' => (string) $customer->phone,
			] : null,
			'created_at' => (string) $booking->created_at,
			'updated_at' => (string) $booking->updated_at,
		];
	}
}
