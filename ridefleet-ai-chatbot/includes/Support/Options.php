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
			'chatbot_ui_theme',
		];

		$next = $current;
		foreach ($allowed as $key) {
			if (array_key_exists($key, $input)) {
				$next[$key] = $input[$key];
			}
		}

		update_option('rfac_settings', array_replace_recursive(Installer::defaults(), $next), false);
	}
}
