<?php
/**
 * Public REST routes for the chatbot widget.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Rest;

use RideFleetAIChatbot\Services\ConversationEngine;
use RideFleetAIChatbot\Services\CoreApiClient;
use RideFleetAIChatbot\Support\DiagnosticLogger;
use RideFleetAIChatbot\Support\Logger;
use RideFleetAIChatbot\Support\Options;
use RideFleetAIChatbot\Support\SessionToken;
use RideFleetAIChatbot\Support\UsageMeter;
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
		register_rest_route('ridefleet-chatbot/v1', '/message', [
			'methods' => 'POST',
			'callback' => [self::class, 'message'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('ridefleet-chatbot/v1', '/settings/public', [
			'methods' => 'GET',
			'callback' => [self::class, 'public_settings'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('ridefleet-chatbot/v1', '/places', [
			'methods' => 'GET',
			'callback' => [self::class, 'places'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('ridefleet-chatbot/v1', '/place-details', [
			'methods' => 'GET',
			'callback' => [self::class, 'place_details'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('ridefleet-chatbot/v1', '/session/new', [
			'methods' => 'POST',
			'callback' => [self::class, 'new_session'],
			'permission_callback' => '__return_true',
		]);

		register_rest_route('ridefleet-chatbot/v1', '/coupon/validate', [
			'methods' => 'POST',
			'callback' => [self::class, 'validate_coupon'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function new_session(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request, 10)) {
			return new WP_REST_Response(['message' => __('Too many requests.', 'ridefleet-ai-chatbot')], 429);
		}

		$key = wp_generate_uuid4();
		return new WP_REST_Response([
			'session_id' => SessionToken::sign($key),
		]);
	}

	public static function message(WP_REST_Request $request): WP_REST_Response {
		$raw_token = (string) ($request->get_param('session_id') ?: '');
		$session_key = SessionToken::verify($raw_token);
		if ('' === $session_key) {
			// Issue a new signed session — covers first-ever request and tampered tokens.
			$session_key = wp_generate_uuid4();
		}

		$client_message_id = sanitize_key((string) $request->get_param('client_message_id'));
		$turn_cache_key = '';
		if ('' !== $client_message_id) {
			$turn_cache_key = self::turn_cache_key($session_key, $client_message_id);
			$cached_turn = get_transient($turn_cache_key);
			if (is_array($cached_turn)) {
				DiagnosticLogger::log(null, $session_key, 'turn_replay', 'rest', 'Duplicate client_message_id replayed from cache.', [
					'client_message_id' => $client_message_id,
					'state' => $cached_turn['state'] ?? '',
				]);
				return new WP_REST_Response($cached_turn);
			}
		}

		if (!self::rate_limit($request)) {
			return new WP_REST_Response(['message' => __('Too many chat requests. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		if (UsageMeter::ip_over_limit()) {
			Logger::warning('ratelimit', 'Per-IP daily cap reached', ['ip_hash' => UsageMeter::ip_hash()]);
			return new WP_REST_Response([
				'message' => __('You have reached the daily message limit for this connection. Please try again tomorrow or contact us directly.', 'ridefleet-ai-chatbot'),
			], 429);
		}

		$message = sanitize_textarea_field((string) $request->get_param('message'));
		$client_locale = sanitize_text_field((string) $request->get_param('client_locale'));
		$metadata_raw = $request->get_param('metadata');
		$metadata = is_array($metadata_raw) ? self::sanitize_metadata($metadata_raw) : [];

		// Structured contact prefill — sent by the returning-customer shortcut instead of
		// injecting saved contact data as normal chat text.
		$prefill_raw     = $request->get_param('prefill_contact');
		$prefill_contact = is_array($prefill_raw) ? [
			'name'  => sanitize_text_field((string) ($prefill_raw['name']  ?? '')),
			'phone' => sanitize_text_field((string) ($prefill_raw['phone'] ?? '')),
		] : [];

		if ('' === $client_locale) {
			$header = (string) $request->get_header('accept-language');
			if ('' !== $header) {
				$client_locale = sanitize_text_field(strtok($header, ','));
			}
		}

		if ('' === trim($message)) {
			return new WP_REST_Response(['message' => __('Message is required.', 'ridefleet-ai-chatbot')], 400);
		}

		try {
			$engine = new ConversationEngine();
			$response = $engine->handle($session_key, $message, $client_locale, $prefill_contact, $metadata);
			// Replace any echoed session_id with the signed token.
			$response['session_id'] = SessionToken::sign((string) ($response['session_id'] ?? $session_key));
			if ('' !== $turn_cache_key) {
				set_transient($turn_cache_key, $response, HOUR_IN_SECONDS);
			}
			return new WP_REST_Response($response);
		} catch (\Throwable $e) {
			Logger::critical('engine', 'Unhandled exception in ConversationEngine', [
				'exception' => $e->getMessage(),
				'file' => $e->getFile() . ':' . $e->getLine(),
			]);
			return new WP_REST_Response([
				'message' => __('Something went wrong on our side. Please try again in a moment.', 'ridefleet-ai-chatbot'),
				'session_id' => SessionToken::sign($session_key),
			], 500);
		}
	}

	public static function public_settings(): WP_REST_Response {
		$theme = Options::get('chatbot_ui_theme', []);
		return new WP_REST_Response([
			'theme' => is_array($theme) ? $theme : [],
			'configured' => [
				'openrouter' => str_starts_with((string) Options::get('openrouter_key', ''), 'sk-or-v1-'),
				'coreApi' => (bool) Options::get('core_plugin_api_url', ''),
			],
		]);
	}

	public static function places(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request, 60)) {
			return new WP_REST_Response(['message' => __('Too many place searches. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		$input = sanitize_text_field((string) $request->get_param('input'));
		$session_key = SessionToken::verify((string) ($request->get_param('session_id') ?: ''));

		// Optional geographic bias: frontend passes pickup coords when searching for dropoff.
		$bias_lat = is_numeric($request->get_param('lat')) ? (float) $request->get_param('lat') : null;
		$bias_lng = is_numeric($request->get_param('lng')) ? (float) $request->get_param('lng') : null;

		DiagnosticLogger::log(null, $session_key, 'api_request', 'core_places', 'Place search requested.', [
			'input' => $input,
			'bias_lat' => $bias_lat,
			'bias_lng' => $bias_lng,
		]);
		$client = new CoreApiClient();
		$result = $client->search_core_places($input, $session_key, $bias_lat, $bias_lng);
		DiagnosticLogger::log(null, $session_key, 'api_response', 'core_places', 'Place search response received.', [
			'success' => !empty($result['success']),
			'prediction_count' => count((array) ($result['predictions'] ?? [])),
			'message' => (string) ($result['message'] ?? ''),
		]);
		return new WP_REST_Response($result);
	}

	public static function place_details(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request, 90)) {
			return new WP_REST_Response(['message' => __('Too many place detail requests. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		$place_id = sanitize_text_field((string) $request->get_param('place_id'));
		$session_key = SessionToken::verify((string) ($request->get_param('session_id') ?: ''));
		DiagnosticLogger::log(null, $session_key, 'api_request', 'core_place_details', 'Place details requested.', [
			'place_id' => $place_id,
		]);

		$client = new CoreApiClient();
		$result = $client->resolve_core_place($place_id, $session_key);
		DiagnosticLogger::log(null, $session_key, 'api_response', 'core_place_details', 'Place details response received.', [
			'success' => !empty($result['success']),
			'has_coordinates' => isset($result['place']['lat'], $result['place']['lng']),
			'message' => (string) ($result['message'] ?? ''),
		]);
		return new WP_REST_Response($result, empty($result['success']) ? 400 : 200);
	}

	public static function validate_coupon(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request, 20)) {
			return new WP_REST_Response(['message' => __('Too many requests.', 'ridefleet-ai-chatbot')], 429);
		}

		$code = sanitize_text_field((string) $request->get_param('code'));
		$subtotal = (float) $request->get_param('subtotal');

		if ('' === $code) {
			return new WP_REST_Response(['valid' => false, 'message' => __('No coupon code provided.', 'ridefleet-ai-chatbot')], 400);
		}

		$client = new CoreApiClient();
		$result = $client->validate_coupon($code, $subtotal);
		return new WP_REST_Response($result);
	}

	private static function rate_limit(WP_REST_Request $request, int $limit = 30): bool {
		// Localhost callers (dev environment, E2E test suite) are exempt from rate limiting.
		$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
		if (in_array($remote, ['127.0.0.1', '::1'], true)) {
			return true;
		}

		$ip  = sanitize_key(str_replace(['.', ':'], '-', $remote));
		$key = 'rfac_rate_' . md5($ip . '|' . (string) $request->get_route());
		$count = (int) get_transient($key);

		if ($count >= $limit) {
			return false;
		}

		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
		return true;
	}

	private static function turn_cache_key(string $session_key, string $client_message_id): string {
		return 'rfac_turn_' . md5($session_key . '|' . $client_message_id);
	}

	private static function sanitize_metadata(array $metadata): array {
		$clean = [];
		$source = sanitize_key((string) ($metadata['source'] ?? ''));
		if ('' !== $source) {
			$clean['source'] = $source;
		}

		if (isset($metadata['place']) && is_array($metadata['place'])) {
			$place = $metadata['place'];
			$clean['place'] = [
				'place_id' => sanitize_text_field((string) ($place['place_id'] ?? '')),
				'description' => sanitize_text_field((string) ($place['description'] ?? '')),
				'main_text' => sanitize_text_field((string) ($place['main_text'] ?? '')),
				'secondary_text' => sanitize_text_field((string) ($place['secondary_text'] ?? '')),
				'types' => array_values(array_filter(array_map('sanitize_key', (array) ($place['types'] ?? [])))),
			];
			if (isset($place['lat'], $place['lng']) && is_numeric($place['lat']) && is_numeric($place['lng'])) {
				$clean['place']['lat'] = (float) $place['lat'];
				$clean['place']['lng'] = (float) $place['lng'];
			}
		}

		return $clean;
	}
}
