<?php
/**
 * Read/write-limited client for the core RideFleet Booking API.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\DiagnosticLogger;
use RideFleetAIChatbot\Support\Options;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

final class CoreApiClient {
	public function get_core_trip_price(string $pickup_address, string $dropoff_address, array $details = []): array {
		$session_id = absint($details['_diagnostic_session_id'] ?? 0);
		$session_key = sanitize_key((string) ($details['_diagnostic_session_key'] ?? ''));
		DiagnosticLogger::log($session_id, $session_key, 'api_request', 'core_price', 'Requesting trip price from RideFleet core.', [
			'pickup_address' => $pickup_address,
			'dropoff_address' => $dropoff_address,
			'has_coordinates' => $this->has_route_coordinates($details),
			'local_core' => $this->can_use_local_core(),
		]);

		$quote_params = $this->quote_params($pickup_address, $dropoff_address, $details);
		if ($this->can_use_local_core()) {
			$quote = \RideFleetBooking\Booking\CoreBookingPricingEngine::quote_from_request($quote_params);
			$result = $this->with_addons($quote, $details);
			DiagnosticLogger::log($session_id, $session_key, 'api_response', 'core_price', 'Local trip price response received.', [
				'success' => !empty($result['success']),
				'final_price' => $result['final_price'] ?? null,
				'currency' => $result['currency'] ?? null,
				'message' => $result['message'] ?? '',
			]);
			return $result;
		}

		$url = $this->endpoint('/wp-json/taxi-booking/v1/calculate-price');
		$url = add_query_arg($quote_params, $url);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 35,
				'redirection' => 2,
				'headers' => $this->headers(),
			]
		);

		$result = $this->with_addons($this->decode_response($response), $details);
		DiagnosticLogger::log($session_id, $session_key, 'api_response', 'core_price', 'Remote trip price response received.', [
			'success' => !empty($result['success']),
			'status' => $result['status'] ?? null,
			'final_price' => $result['final_price'] ?? null,
			'currency' => $result['currency'] ?? null,
			'message' => $result['message'] ?? '',
		]);
		return $result;
	}

	public function get_vehicles(int $passengers = 1, int $luggage = 0): array {
		if ($this->can_use_local_core()) {
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
					'description' => wp_strip_all_tags((string) $post->post_content),
					'passengers' => $capacity,
					'luggage' => $bag_capacity,
					'priceAdjustment' => (float) get_post_meta($post->ID, 'rfb_price_adjustment', true),
				];
			}
			return ['success' => true, 'vehicles' => $vehicles];
		}

		$response = wp_remote_get(
			add_query_arg(['passengers' => max(1, $passengers), 'luggage' => max(0, $luggage)], $this->endpoint('/wp-json/ridefleet/v1/vehicles')),
			['timeout' => 12, 'redirection' => 2, 'headers' => $this->headers()]
		);
		if (is_wp_error($response)) {
			return ['success' => false, 'vehicles' => []];
		}
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		return ['success' => is_array($body), 'vehicles' => array_values(is_array($body) ? $body : [])];
	}

	public function get_extras(): array {
		if ($this->can_use_local_core()) {
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
					'id' => (int) $post->ID,
					'name' => html_entity_decode(get_the_title($post), ENT_QUOTES, get_bloginfo('charset')),
					'description' => wp_strip_all_tags((string) $post->post_content),
					'price' => (float) get_post_meta($post->ID, 'rfb_price', true),
					'maxQuantity' => absint(get_post_meta($post->ID, 'rfb_max_quantity', true) ?: 1),
				];
			}
			return ['success' => true, 'extras' => $extras];
		}

		$response = wp_remote_get(
			$this->endpoint('/wp-json/ridefleet/v1/extras'),
			['timeout' => 12, 'redirection' => 2, 'headers' => $this->headers()]
		);
		if (is_wp_error($response)) {
			return ['success' => false, 'extras' => []];
		}
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		return ['success' => is_array($body), 'extras' => array_values(is_array($body) ? $body : [])];
	}

	public function submit_core_booking(array $booking_data): array {
		$session_id = absint($booking_data['diagnostic_session_id'] ?? 0);
		$session_key = sanitize_key((string) ($booking_data['diagnostic_session_key'] ?? ''));
		$allowed = [
			'pickup_address',
			'dropoff_address',
			'customer_name',
			'customer_phone',
			'pickup_time',
			'final_price',
			'currency',
			'passengers',
			'luggage',
			'vehicle_id',
			'vehicle_name',
			'extras',
			'coupon_code',
			'requires_manual_dispatch',
			'pickup_lat',
			'pickup_lng',
			'dropoff_lat',
			'dropoff_lng',
			'idempotency_key',
		];

		$payload = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $booking_data)) {
				$payload[$key] = $booking_data[$key];
			}
		}

		DiagnosticLogger::log($session_id, $session_key, 'api_request', 'core_booking', 'Submitting booking to RideFleet core.', [
			'payload' => $payload,
			'local_core' => $this->can_use_local_core(),
		]);

		if ($this->can_use_local_core() && class_exists('\RideFleetBooking\Booking\BookingRepository') && class_exists('\RideFleetBooking\Booking\NotificationService')) {
			$result = $this->create_local_core_booking($payload);
			DiagnosticLogger::log($session_id, $session_key, 'api_response', 'core_booking', 'Local booking response received.', [
				'success' => !empty($result['success']),
				'booking_id' => $result['booking_id'] ?? '',
				'status' => $result['status'] ?? '',
				'manual_dispatch' => !empty($result['manual_dispatch']),
				'message' => $result['message'] ?? '',
			]);
			return $result;
		}

		$headers = array_merge($this->headers(), ['Content-Type' => 'application/json; charset=utf-8']);
		if (!empty($payload['idempotency_key'])) {
			$headers['Idempotency-Key'] = sanitize_text_field((string) $payload['idempotency_key']);
		}

		$response = wp_remote_post(
			$this->endpoint('/wp-json/taxi-booking/v1/create-booking'),
			[
				'timeout' => 20,
				'redirection' => 2,
				'headers' => $headers,
				'body' => wp_json_encode($payload),
			]
		);

		$result = $this->decode_response($response);
		DiagnosticLogger::log($session_id, $session_key, 'api_response', 'core_booking', 'Remote booking response received.', [
			'success' => !empty($result['success']),
			'booking_id' => $result['booking_id'] ?? '',
			'status' => $result['status'] ?? '',
			'message' => $result['message'] ?? '',
		]);
		return $result;
	}

	private function create_local_core_booking(array $payload): array {
		foreach (['pickup_address', 'dropoff_address', 'customer_name', 'customer_phone', 'pickup_time'] as $key) {
			if ('' === trim((string) ($payload[$key] ?? ''))) {
				return [
					'success' => false,
					'error' => true,
					'message' => sprintf(__('%s is required.', 'ridefleet-ai-chatbot'), $key),
				];
			}
		}

		$pickup_timestamp = strtotime((string) $payload['pickup_time']);
		if (!$pickup_timestamp) {
			return [
				'success' => false,
				'error' => true,
				'message' => __('Enter a valid pickup time.', 'ridefleet-ai-chatbot'),
			];
		}

		$idempotency_key = sanitize_text_field((string) ($payload['idempotency_key'] ?? ''));
		$cache_key = '';
		if ('' !== $idempotency_key) {
			$cache_key = 'rfac_local_booking_' . md5(wp_json_encode([
				$idempotency_key,
				(string) $payload['pickup_address'],
				(string) $payload['dropoff_address'],
				(string) $payload['customer_phone'],
				(string) $payload['pickup_time'],
				(float) ($payload['final_price'] ?? 0),
			]));
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		if (!empty($payload['requires_manual_dispatch'])) {
			return $this->create_local_manual_dispatch_booking($payload, $pickup_timestamp, $cache_key);
		}

		$quote = \RideFleetBooking\Booking\CoreBookingPricingEngine::quote_from_request(
			$this->quote_params((string) $payload['pickup_address'], (string) $payload['dropoff_address'], $payload)
		);
		if (empty($quote['success'])) {
			return $quote;
		}

		$quote = $this->with_addons($quote, $payload);
		if (abs((float) ($quote['final_price'] ?? 0) - (float) ($payload['final_price'] ?? 0)) > 0.01) {
			return [
				'success' => false,
				'error' => true,
				'status' => 409,
				'message' => __('The submitted fare no longer matches the verified fare. Please recalculate before booking.', 'ridefleet-ai-chatbot'),
				'verified_price' => (float) ($quote['final_price'] ?? 0),
				'currency' => (string) ($quote['currency'] ?? 'USD'),
			];
		}

		$name_parts = preg_split('/\s+/', trim((string) $payload['customer_name']), 2);
		$name_parts = is_array($name_parts) ? $name_parts : [(string) $payload['customer_name'], ''];
		$extras = $this->normalize_extras_for_booking($payload['extras'] ?? []);
		$booking_payload = [
			'serviceType' => 'chatbot',
			'source' => 'chatbot',
			'transferType' => 'one_way',
			'pickupAddress' => (string) $payload['pickup_address'],
			'dropoffAddress' => (string) $payload['dropoff_address'],
			'waypoints' => [],
			'pickupDate' => wp_date('Y-m-d', $pickup_timestamp),
			'pickupTime' => wp_date('H:i', $pickup_timestamp),
			'distance' => max(0, (float) ($quote['distance_km'] ?? 0)),
			'durationMinutes' => max(0, (float) ($quote['duration_minutes'] ?? 0)),
			'passengers' => max(1, absint($payload['passengers'] ?? 1)),
			'luggage' => max(0, absint($payload['luggage'] ?? 0)),
			'vehicleId' => absint($payload['vehicle_id'] ?? 0),
			'routeId' => 0,
			'extras' => $extras,
			'couponCode' => sanitize_text_field((string) ($payload['coupon_code'] ?? '')),
			'customerFirstName' => $name_parts[0] ?? (string) $payload['customer_name'],
			'customerLastName' => $name_parts[1] ?? '',
			'customerEmail' => '',
			'customerPhone' => (string) $payload['customer_phone'],
			'note' => trim(sprintf(__('Created by RideFleet AI Chatbot through the local write-only booking path. Vehicle: %s.', 'ridefleet-ai-chatbot'), (string) ($payload['vehicle_name'] ?? ''))),
		];
		$booking_quote = [
			'currency' => (string) ($quote['currency'] ?? 'USD'),
			'distanceUnit' => class_exists('\RideFleetBooking\Support\Options') ? \RideFleetBooking\Support\Options::get('distance_unit', 'km') : 'km',
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

		$booking = \RideFleetBooking\Booking\BookingRepository::create($booking_payload, $booking_quote);
		\RideFleetBooking\Booking\NotificationService::booking_created((int) $booking['id']);

		$result = [
			'success' => true,
			'status' => 201,
			'booking_id' => $booking['bookingNumber'],
			'internal_id' => (int) $booking['id'],
			'bookingId' => $booking['bookingNumber'],
			'id' => $booking['bookingNumber'],
			'booking_status' => $booking['status'] ?? 'pending_payment',
			'booking' => $booking,
		];
		if ('' !== $cache_key) {
			set_transient($cache_key, $result, HOUR_IN_SECONDS);
		}
		return $result;
	}

	private function create_local_manual_dispatch_booking(array $payload, int $pickup_timestamp, string $cache_key = ''): array {
		$name_parts = preg_split('/\s+/', trim((string) $payload['customer_name']), 2);
		$name_parts = is_array($name_parts) ? $name_parts : [(string) $payload['customer_name'], ''];

		$booking_payload = [
			'serviceType' => 'chatbot',
			'source' => 'chatbot',
			'transferType' => 'one_way',
			'pickupAddress' => (string) $payload['pickup_address'],
			'dropoffAddress' => (string) $payload['dropoff_address'],
			'waypoints' => [],
			'pickupDate' => wp_date('Y-m-d', $pickup_timestamp),
			'pickupTime' => wp_date('H:i', $pickup_timestamp),
			'distance' => 0,
			'durationMinutes' => 0,
			'passengers' => max(1, absint($payload['passengers'] ?? 1)),
			'luggage' => max(0, absint($payload['luggage'] ?? 0)),
			'vehicleId' => 0,
			'routeId' => 0,
			'extras' => [],
			'couponCode' => '',
			'customerFirstName' => $name_parts[0] ?? (string) $payload['customer_name'],
			'customerLastName' => $name_parts[1] ?? '',
			'customerEmail' => '',
			'customerPhone' => (string) $payload['customer_phone'],
			'status' => 'pending_dispatch',
			'paymentStatus' => 'fare_pending',
			'note' => __('Manually dispatched chatbot booking. Fare and vehicle must be confirmed by dispatch before pickup.', 'ridefleet-ai-chatbot'),
		];
		$booking_quote = [
			'currency' => class_exists('\RideFleetBooking\Support\Options') ? \RideFleetBooking\Support\Options::get('currency', 'USD') : 'USD',
			'distanceUnit' => class_exists('\RideFleetBooking\Support\Options') ? \RideFleetBooking\Support\Options::get('distance_unit', 'km') : 'km',
			'subtotal' => 0.0,
			'taxTotal' => 0.0,
			'discountTotal' => 0.0,
			'total' => 0.0,
			'coupon' => ['valid' => false],
			'serviceType' => 'chatbot',
			'breakdown' => ['pricingSource' => 'manual_dispatch', 'zoneName' => '', 'addons' => []],
		];

		$booking = \RideFleetBooking\Booking\BookingRepository::create($booking_payload, $booking_quote);
		\RideFleetBooking\Booking\BookingRepository::add_meta((int) $booking['id'], '_requires_manual_dispatch', '1');
		\RideFleetBooking\Booking\NotificationService::booking_created((int) $booking['id']);

		$result = [
			'success' => true,
			'status' => 201,
			'booking_id' => $booking['bookingNumber'],
			'internal_id' => (int) $booking['id'],
			'bookingId' => $booking['bookingNumber'],
			'id' => $booking['bookingNumber'],
			'booking_status' => $booking['status'] ?? 'pending_dispatch',
			'booking' => $booking,
			'manual_dispatch' => true,
		];
		if ('' !== $cache_key) {
			set_transient($cache_key, $result, HOUR_IN_SECONDS);
		}
		return $result;
	}

	private function with_addons(array $quote, array $details): array {
		if (empty($quote['success'])) {
			return $quote;
		}

		$base = (float) ($quote['final_price'] ?? $quote['price'] ?? $quote['total'] ?? 0);
		$vehicle_adjustment = 0.0;
		$vehicle_id = absint($details['vehicle_id'] ?? $details['vehicleId'] ?? 0);
		if ($vehicle_id && 'rfb_vehicle' === get_post_type($vehicle_id)) {
			$vehicle_adjustment = (float) get_post_meta($vehicle_id, 'rfb_price_adjustment', true);
		}

		$extras_total = 0.0;
		foreach ($this->normalize_extras_for_booking($details['extras'] ?? []) as $extra) {
			$id = absint($extra['id'] ?? 0);
			$quantity = max(1, absint($extra['quantity'] ?? 1));
			if ($id && 'rfb_extra' === get_post_type($id)) {
				$extras_total += ((float) get_post_meta($id, 'rfb_price', true)) * $quantity;
			}
		}

		$quote['base_price'] = round($base, 2);
		$quote['final_price'] = round($base + $vehicle_adjustment + $extras_total, 2);
		$quote['addons'] = [
			'vehicle_adjustment' => round($vehicle_adjustment, 2),
			'extras_total' => round($extras_total, 2),
		];
		return $quote;
	}

	private function normalize_extras_for_booking(mixed $extras): array {
		if (!is_array($extras)) {
			return [];
		}

		$normalized = [];
		foreach ($extras as $extra) {
			if (!is_array($extra)) {
				continue;
			}
			$id = absint($extra['id'] ?? 0);
			if (!$id) {
				continue;
			}
			$normalized[] = [
				'id' => $id,
				'quantity' => max(1, absint($extra['quantity'] ?? 1)),
			];
		}
		return $normalized;
	}

	public function validate_coupon(string $code, float $subtotal = 0): array {
		$code = sanitize_text_field($code);
		if ('' === $code) {
			return ['valid' => false, 'message' => __('No coupon code.', 'ridefleet-ai-chatbot')];
		}

		if ($this->can_use_local_core() && class_exists('\RideFleetBooking\Booking\CouponService')) {
			$result = \RideFleetBooking\Booking\CouponService::discount($code, max(0.0, $subtotal));
			return [
				'valid' => !empty($result['valid']),
				'code' => (string) ($result['code'] ?? $code),
				'amount' => (float) ($result['amount'] ?? 0),
				'message' => (string) ($result['message'] ?? ''),
			];
		}

		$response = wp_remote_post(
			$this->endpoint('/wp-json/taxi-booking/v1/validate-coupon'),
			[
				'timeout' => 8,
				'redirection' => 2,
				'headers' => array_merge($this->headers(), ['Content-Type' => 'application/json; charset=utf-8']),
				'body' => wp_json_encode(['code' => $code, 'subtotal' => $subtotal]),
			]
		);

		if (is_wp_error($response)) {
			return ['valid' => false, 'message' => $response->get_error_message()];
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($body)) {
			return ['valid' => false, 'message' => __('Coupon service unreachable.', 'ridefleet-ai-chatbot')];
		}

		return [
			'valid' => !empty($body['valid']),
			'code' => (string) ($body['code'] ?? $code),
			'amount' => (float) ($body['amount'] ?? 0),
			'message' => (string) ($body['message'] ?? ''),
		];
	}

	/**
	 * @param float|null $bias_lat  Latitude to bias results toward (pickup coords for dropoff search, or null for home region).
	 * @param float|null $bias_lng  Longitude to bias results toward.
	 */
	public function search_core_places(string $input, string $session_key = '', ?float $bias_lat = null, ?float $bias_lng = null): array {
		$input = trim($input);
		if (strlen($input) < 3) {
			return [
				'success' => false,
				'predictions' => [],
				'message' => __('Please type a more specific place or address.', 'ridefleet-ai-chatbot'),
			];
		}

		// Cache key includes location context to prevent cross-region poisoning.
		$loc_context = (null !== $bias_lat && null !== $bias_lng) ? '|' . round($bias_lat, 2) . ',' . round($bias_lng, 2) : '';
		$cache_key = 'rfac_places_' . md5(strtolower($input) . $loc_context);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached + ['cached' => true];
		}

		$query_args = ['input' => $input];
		if (null !== $bias_lat && null !== $bias_lng) {
			$query_args['lat'] = $bias_lat;
			$query_args['lng'] = $bias_lng;
		}

		$response = wp_remote_get(
			add_query_arg($query_args, $this->endpoint('/wp-json/taxi-booking/v1/place-search')),
			[
				'timeout' => 6,
				'redirection' => 2,
				'headers' => $this->headers(),
			]
		);

		$result = $this->decode_response($response);
		if (!empty($result['success'])) {
			set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);
		}
		return $result;
	}

	public function resolve_core_place(string $place_id, string $session_key = ''): array {
		$place_id = sanitize_text_field($place_id);
		if ('' === $place_id) {
			return ['success' => false, 'message' => __('Place ID is required.', 'ridefleet-ai-chatbot')];
		}

		$cache_key = 'rfac_place_details_' . md5($place_id);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached + ['cached' => true];
		}

		$response = wp_remote_get(
			add_query_arg(['place_id' => $place_id], $this->endpoint('/wp-json/taxi-booking/v1/place-details')),
			[
				'timeout' => 6,
				'redirection' => 2,
				'headers' => $this->headers(),
			]
		);

		$result = $this->decode_response($response);
		if (!empty($result['success'])) {
			set_transient($cache_key, $result, DAY_IN_SECONDS);
		}
		return $result;
	}

	private function quote_params(string $pickup_address, string $dropoff_address, array $details): array {
		$params = [
			'pickup_address' => $pickup_address,
			'dropoff_address' => $dropoff_address,
		];
		foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $key) {
			if (isset($details[$key]) && is_numeric($details[$key])) {
				$params[$key] = (string) $details[$key];
			}
		}
		return $params;
	}

	private function has_route_coordinates(array $details): bool {
		foreach (['pickup_lat', 'pickup_lng', 'dropoff_lat', 'dropoff_lng'] as $key) {
			if (!isset($details[$key]) || !is_numeric($details[$key])) {
				return false;
			}
		}
		return true;
	}

	private function endpoint(string $path): string {
		$base = esc_url_raw((string) Options::get('core_plugin_api_url', home_url()));
		$base = $base ?: home_url();
		return untrailingslashit($base) . $path;
	}

	private function can_use_local_core(): bool {
		$configured = untrailingslashit((string) Options::get('core_plugin_api_url', home_url()));
		$local = untrailingslashit(home_url());

		return class_exists('\RideFleetBooking\Booking\CoreBookingPricingEngine') && strtolower($configured) === strtolower($local);
	}

	private function headers(): array {
		$headers = [
			'Accept' => 'application/json',
			'X-RideFleet-Client' => 'ridefleet-ai-chatbot/' . RFAC_VERSION,
		];
		$key = (string) Options::get('core_plugin_api_key', '');
		if ('' !== $key) {
			$headers['X-RideFleet-Chatbot-Key'] = $key;
		}

		return $headers;
	}

	private function decode_response(array|WP_Error $response): array {
		if (is_wp_error($response)) {
			return [
				'success' => false,
				'error' => true,
				'message' => $response->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		if (!is_array($body)) {
			$body = [];
		}

		if ($status < 200 || $status >= 300) {
			return array_merge(
				[
					'success' => false,
					'error' => true,
					'status' => $status,
					'message' => __('The core booking service rejected the request.', 'ridefleet-ai-chatbot'),
				],
				$body
			);
		}

		return array_merge(['success' => true, 'status' => $status], $body);
	}
}
