<?php
/**
 * Main plugin container.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking;

use RideFleetBooking\Admin\Admin;
use RideFleetBooking\Core\Compat;
use RideFleetBooking\Core\Installer;
use RideFleetBooking\Core\PostTypes;
use RideFleetBooking\Frontend\Shortcodes;
use RideFleetBooking\Integrations\GoogleMaps;
use RideFleetBooking\Integrations\WooCommerce;
use RideFleetBooking\Rest\Routes;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {
	private static ?self $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ($this->booted) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain('ridefleet-booking', false, dirname(plugin_basename(RFB_PLUGIN_FILE)) . '/languages');

		Installer::maybe_update();

		Compat::register_hooks();
		PostTypes::register_hooks();
		Admin::register_hooks();
		Shortcodes::register_hooks();
		Routes::register_hooks();
		GoogleMaps::register_hooks();
		WooCommerce::register_hooks();
	}
}
