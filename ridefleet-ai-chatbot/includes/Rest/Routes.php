<?php
/**
 * Public REST routes for the chatbot widget.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Rest;

use RideFleetAIChatbot\Services\ConversationEngine;
use RideFleetAIChatbot\Services\CoreApiClient;
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
		if (!self::rate_limit($request)) {
			return new WP_REST_Response(['message' => __('Too many chat requests. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		if (UsageMeter::ip_over_limit()) {
			Logger::warning('ratelimit', 'Per-IP daily cap reached', ['ip_hash' => UsageMeter::ip_hash()]);
			return new WP_REST_Response([
				'message' => __('You have reached the daily message limit for this connection. Please try again tomorrow or contact us directly.', 'ridefleet-ai-chatbot'),
			], 429);
		}

		$raw_token = (string) ($request->get_param('session_id') ?: '');
		$session_key = SessionToken::verify($raw_token);
		if ('' === $session_key) {
			// Issue a new signed session — covers first-ever request and tampered tokens.
			$session_key = wp_generate_uuid4();
		}

		$message = sanitize_textarea_field((string) $request->get_param('message'));
		$client_locale = sanitize_text_field((string) $request->get_param('client_locale'));

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
			$response = $engine->handle($session_key, $message, $client_locale);
			// Replace any echoed session_id with the signed token.
			$response['session_id'] = SessionToken::sign((string) ($response['session_id'] ?? $session_key));
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
		$client = new CoreApiClient();
		return new WP_REST_Response($client->search_core_places($input));
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
		$ip = sanitize_key(str_replace(['.', ':'], '-', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
		$key = 'rfac_rate_' . md5($ip . '|' . (string) $request->get_route());
		$count = (int) get_transient($key);

		if ($count >= $limit) {
			return false;
		}

		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
		return true;
	}
}
