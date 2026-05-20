<?php
/**
 * Uninstall handler — removes plugin tables, options, custom post types meta,
 * and scheduled events.
 *
 * Triggered by WordPress when the user deletes the plugin from the Plugins
 * screen. Mirrors what Installer::create_tables() creates.
 *
 * @package RideFleetBooking
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$tables = [
	$wpdb->prefix . 'rfb_customers',
	$wpdb->prefix . 'rfb_bookings',
	$wpdb->prefix . 'rfb_booking_meta',
	$wpdb->prefix . 'rfb_quote_events',
	$wpdb->prefix . 'rfb_coupons',
	$wpdb->prefix . 'rfb_availability_rules',
	$wpdb->prefix . 'rfb_pricing_rules',
	$wpdb->prefix . 'rfb_route_cache',
	$wpdb->prefix . 'rfb_geofence_zones',
];

foreach ($tables as $table) {
	$wpdb->query("DROP TABLE IF EXISTS {$table}");
}

// Remove custom post types created by the booking plugin (vehicles, extras, routes).
$post_types = ['rfb_vehicle', 'rfb_extra', 'rfb_route'];
$post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (" . implode(',', array_fill(0, count($post_types), '%s')) . ')',
		...$post_types
	)
);

foreach ((array) $post_ids as $post_id) {
	wp_delete_post((int) $post_id, true);
}

delete_option('rfb_settings');
delete_option('rfb_db_version');
delete_option('core_booking_rules');

// Clean up any leftover transients (route cache, rate limits, idempotency).
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rfb_%' OR option_name LIKE '_transient_timeout_rfb_%'");
