<?php
/**
 * Lightweight PSR-4 style autoloader.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking;

if (!defined('ABSPATH')) {
	exit;
}

final class Autoloader {
	public static function register(): void {
		spl_autoload_register([self::class, 'autoload']);
	}

	public static function autoload(string $class): void {
		$prefix = __NAMESPACE__ . '\\';

		if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
			return;
		}

		$relative = substr($class, strlen($prefix));
		$path     = RFB_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';

		if (is_readable($path)) {
			require_once $path;
		}
	}
}
