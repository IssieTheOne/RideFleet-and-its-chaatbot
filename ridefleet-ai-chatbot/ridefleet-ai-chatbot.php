<?php
/**
 * Plugin Name: RideFleet AI Chatbot
 * Plugin URI: https://example.com/ridefleet-ai-chatbot
 * Description: Customer-facing AI booking assistant for RideFleet Booking. Fetches prices and injects confirmed reservations through the core plugin REST API only.
 * Version: 3.4.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * Author: RideFleet
 * Text Domain: ridefleet-ai-chatbot
 * Domain Path: /languages
 *
 * @package RideFleetAIChatbot
 */

if (!defined('ABSPATH')) {
	exit;
}

define('RFAC_VERSION', '3.4.0');
define('RFAC_PLUGIN_FILE', __FILE__);
define('RFAC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RFAC_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RFAC_PLUGIN_DIR . 'includes/Autoloader.php';

RideFleetAIChatbot\Autoloader::register();

register_activation_hook(__FILE__, ['RideFleetAIChatbot\Core\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['RideFleetAIChatbot\Core\Installer', 'deactivate']);

add_action('plugins_loaded', static function (): void {
	RideFleetAIChatbot\Plugin::instance()->boot();
});
