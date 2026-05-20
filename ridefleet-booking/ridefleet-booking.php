<?php
/**
 * Plugin Name: RideFleet Booking
 * Plugin URI: https://example.com/ridefleet-booking
 * Description: Chauffeur, taxi, shuttle, and private transfer booking system with Google Maps, WooCommerce checkout support, customer history, and fleet management.
 * Version: 1.0.35
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: RideFleet
 * Text Domain: ridefleet-booking
 * Domain Path: /languages
 *
 * @package RideFleetBooking
 */

if (!defined('ABSPATH')) {
	exit;
}

define('RFB_VERSION', '1.0.35');
define('RFB_PLUGIN_FILE', __FILE__);
define('RFB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RFB_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RFB_PLUGIN_DIR . 'includes/Autoloader.php';

RideFleetBooking\Autoloader::register();

register_activation_hook(__FILE__, ['RideFleetBooking\Core\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['RideFleetBooking\Core\Installer', 'deactivate']);

add_action('plugins_loaded', static function (): void {
	RideFleetBooking\Plugin::instance()->boot();
});
