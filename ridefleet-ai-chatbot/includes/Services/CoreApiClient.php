<?php
/**
 * Read/write-limited client for the core RideFleet Booking API.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Support\Options;
use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

final class CoreApiClient {
	public function get_core_trip_price(string $pickup_address, string $dropoff_address, array $details = []): array {
		if ($this->can_use_local_core()) {
			$quote = \RideFleetBooking\Booking\CoreBookingPricingEngine::quote_from_request(
				[
					'pickup_address' => $pickup_address,
					'dropoff_address' => $dropoff_address,
				]
			);
			return $this->with_addons($quote, $details);
		}

		$url = $this->endpoint('/wp-json/taxi-booking/v1/calculate-price');
		$url = add_query_arg(
			[
				'pickup_address' => $pickup_address,
				'dropoff_address' => $dropoff_address,
			],
			$url
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 35,
				'redirection' => 2,
				'headers' => $this->headers(),
			]
		);

		return $this->with_addons($this->decode_response($response), $details);
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
		];

		$payload = [];
		foreach ($allowed as $key) {
			if (array_key_exists($key, $booking_data)) {
				$payload[$key] = $booking_data[$key];
			}
		}

		if ($this->can_use_local_core() && class_exists('\RideFleetBooking\Booking\BookingRepository') && class_exists('\RideFleetBooking\Booking\NotificationService')) {
			return $this->create_local_core_booking($payload);
		}

		$response = wp_remote_post(
			$this->endpoint('/wp-json/taxi-booking/v1/create-booking'),
			[
				'timeout' => 20,
				'redirection' => 2,
				'headers' => array_merge($this->headers(), ['Content-Type' => 'application/json; charset=utf-8']),
				'body' => wp_json_encode($payload),
			]
		);

		return $this->decode_response($response);
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

		$quote = \RideFleetBooking\Booking\CoreBookingPricingEngine::quote_from_request(
			[
				'pickup_address' => (string) $payload['pickup_address'],
				'dropoff_address' => (string) $payload['dropoff_address'],
			]
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
			'couponCode' => '',
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

		return [
			'success' => true,
			'status' => 201,
			'booking_id' => $booking['bookingNumber'],
			'internal_id' => (int) $booking['id'],
			'bookingId' => $booking['bookingNumber'],
			'id' => $booking['bookingNumber'],
			'booking' => $booking,
		];
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

	public function search_core_places(string $input): array {
		$input = trim($input);
		if (strlen($input) < 3) {
			return [
				'success' => false,
				'predictions' => [],
				'message' => __('Please type a more specific place or address.', 'ridefleet-ai-chatbot'),
			];
		}

		$response = wp_remote_get(
			add_query_arg(['input' => $input], $this->endpoint('/wp-json/taxi-booking/v1/place-search')),
			[
				'timeout' => 12,
				'redirection' => 2,
				'headers' => $this->headers(),
			]
		);

		return $this->decode_response($response);
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
