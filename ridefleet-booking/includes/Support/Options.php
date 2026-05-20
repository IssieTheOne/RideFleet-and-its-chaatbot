<?php
/**
 * Settings access helpers.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class Options {
	public static function all(): array {
		$options = get_option('rfb_settings', []);

		return is_array($options) ? $options : [];
	}

	public static function get(string $key, mixed $default = null): mixed {
		$options = self::all();
		$segments = explode('.', $key);
		$value = $options;

		foreach ($segments as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return $default;
			}

			$value = $value[$segment];
		}

		return $value;
	}

	public static function update(array $new_options): void {
		update_option('rfb_settings', array_replace_recursive(self::all(), $new_options), false);
	}
}
