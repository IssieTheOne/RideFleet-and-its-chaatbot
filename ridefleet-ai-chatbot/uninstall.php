<?php
/**
 * Uninstall handler — removes plugin tables, options, and scheduled events.
 *
 * Triggered by WordPress when the user deletes the plugin from the Plugins screen.
 *
 * @package RideFleetAIChatbot
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

$tables = [
	$wpdb->prefix . 'rfac_chat_sessions',
	$wpdb->prefix . 'rfac_chat_messages',
	$wpdb->prefix . 'rfac_booking_events',
	$wpdb->prefix . 'rfac_change_requests',
];

foreach ($tables as $table) {
	$wpdb->query("DROP TABLE IF EXISTS {$table}");
}

delete_option('rfac_settings');
delete_option('rfac_db_version');

wp_clear_scheduled_hook('rfac_session_cleanup');

// Remove any leftover transients used for rate limits and reply caching.
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rfac_%' OR option_name LIKE '_transient_timeout_rfac_%'");
