<?php
/**
 * Settings repository.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Support;

use RideFleetAIChatbot\Core\Installer;

if (!defined('ABSPATH')) {
	exit;
}

final class Options {
	public static function all(): array {
		$options = get_option('rfac_settings', []);
		return is_array($options) ? array_replace_recursive(Installer::defaults(), $options) : Installer::defaults();
	}

	public static function get(string $key, mixed $default = null): mixed {
		$options = self::all();
		return $options[$key] ?? $default;
	}

	public static function update(array $input): void {
		$current = self::all();
		$allowed = [
			'openrouter_key',
			'selected_model',
			'company_bio',
			'core_plugin_api_url',
			'core_plugin_api_key',
			'dispatch_contact_number',
			'max_price_negotiation_discount',
			'data_retention_days',
			'notification_email',
			'notifications_enabled',
			'ai_daily_token_budget',
			'ai_per_ip_daily_cap',
			'session_signing_secret',
			'update_endpoint',
			'license_key',
			'chatbot_ui_theme',
			'faq_items',
		];

		$next = $current;
		foreach ($allowed as $key) {
			if (array_key_exists($key, $input)) {
				$next[$key] = $input[$key];
			}
		}

		update_option('rfac_settings', array_replace_recursive(Installer::defaults(), $next), false);
	}

	/**
	 * Lazily generated HMAC signing secret. Persists once created so existing signatures stay valid.
	 */
	public static function signing_secret(): string {
		$secret = (string) self::get('session_signing_secret', '');
		if ('' === $secret) {
			$secret = wp_generate_password(48, true, true);
			self::update(['session_signing_secret' => $secret]);
		}
		return $secret;
	}
}
