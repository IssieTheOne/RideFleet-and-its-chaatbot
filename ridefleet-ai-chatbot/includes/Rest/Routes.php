<?php
/**
 * Public REST routes for the chatbot widget.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Rest;

use RideFleetAIChatbot\Services\ConversationEngine;
use RideFleetAIChatbot\Support\Options;
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
			'ridefleet-chatbot/v1',
			'/message',
			[
				'methods' => 'POST',
				'callback' => [self::class, 'message'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet-chatbot/v1',
			'/settings/public',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'public_settings'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'ridefleet-chatbot/v1',
			'/places',
			[
				'methods' => 'GET',
				'callback' => [self::class, 'places'],
				'permission_callback' => '__return_true',
			]
		);
	}

	public static function message(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request)) {
			return new WP_REST_Response(['message' => __('Too many chat requests. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		$session_key = sanitize_key((string) ($request->get_param('session_id') ?: wp_generate_uuid4()));
		$message = sanitize_textarea_field((string) $request->get_param('message'));

		if ('' === trim($message)) {
			return new WP_REST_Response(['message' => __('Message is required.', 'ridefleet-ai-chatbot')], 400);
		}

		$engine = new ConversationEngine();
		return new WP_REST_Response($engine->handle($session_key, $message));
	}

	public static function public_settings(): WP_REST_Response {
		$theme = Options::get('chatbot_ui_theme', []);
		return new WP_REST_Response(
			[
				'theme' => is_array($theme) ? $theme : [],
				'configured' => [
					'openrouter' => str_starts_with((string) Options::get('openrouter_key', ''), 'sk-or-v1-'),
					'coreApi' => (bool) Options::get('core_plugin_api_url', ''),
				],
			]
		);
	}

	public static function places(WP_REST_Request $request): WP_REST_Response {
		if (!self::rate_limit($request)) {
			return new WP_REST_Response(['message' => __('Too many place searches. Please wait a moment.', 'ridefleet-ai-chatbot')], 429);
		}

		$input = sanitize_text_field((string) $request->get_param('input'));
		$client = new \RideFleetAIChatbot\Services\CoreApiClient();
		return new WP_REST_Response($client->search_core_places($input));
	}

	private static function rate_limit(WP_REST_Request $request): bool {
		$ip = sanitize_key(str_replace(['.', ':'], '-', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')));
		$key = 'rfac_rate_' . md5($ip . '|' . (string) $request->get_route());
		$count = (int) get_transient($key);

		if ($count >= 30) {
			return false;
		}

		set_transient($key, $count + 1, MINUTE_IN_SECONDS);
		return true;
	}
}
