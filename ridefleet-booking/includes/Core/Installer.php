<?php
/**
 * Activation and database setup.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class Installer {
	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		PostTypes::register();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function maybe_update(): void {
		if (get_option('rfb_db_version') === RFB_VERSION) {
			return;
		}

		self::create_tables();
		self::merge_new_options();
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$bookings = $wpdb->prefix . 'rfb_bookings';
		$customers = $wpdb->prefix . 'rfb_customers';
		$booking_meta = $wpdb->prefix . 'rfb_booking_meta';
		$quote_events = $wpdb->prefix . 'rfb_quote_events';
		$coupons = $wpdb->prefix . 'rfb_coupons';
		$availability = $wpdb->prefix . 'rfb_availability_rules';
		$pricing_rules = $wpdb->prefix . 'rfb_pricing_rules';
		$route_cache = $wpdb->prefix . 'rfb_route_cache';
		$geofence_zones = $wpdb->prefix . 'rfb_geofence_zones';

		dbDelta(
			"CREATE TABLE {$customers} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				wp_user_id bigint(20) unsigned NULL,
				first_name varchar(100) NOT NULL DEFAULT '',
				last_name varchar(100) NOT NULL DEFAULT '',
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(60) NOT NULL DEFAULT '',
				total_bookings int unsigned NOT NULL DEFAULT 0,
				total_spend decimal(12,2) NOT NULL DEFAULT 0.00,
				last_booking_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY phone (phone),
				KEY wp_user_id (wp_user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$bookings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				booking_number varchar(40) NOT NULL DEFAULT '',
				customer_id bigint(20) unsigned NULL,
				wp_user_id bigint(20) unsigned NULL,
				status varchar(40) NOT NULL DEFAULT 'draft',
				payment_status varchar(40) NOT NULL DEFAULT 'unpaid',
				service_type varchar(40) NOT NULL DEFAULT 'distance',
				transfer_type varchar(40) NOT NULL DEFAULT 'one_way',
				pickup_address text NULL,
				dropoff_address text NULL,
				waypoints longtext NULL,
				pickup_at datetime NULL,
				return_at datetime NULL,
				distance_value decimal(12,3) NULL,
				distance_unit varchar(10) NOT NULL DEFAULT 'km',
				duration_seconds int unsigned NULL,
				passengers smallint unsigned NOT NULL DEFAULT 1,
				luggage smallint unsigned NOT NULL DEFAULT 0,
				vehicle_id bigint(20) unsigned NULL,
				driver_id bigint(20) unsigned NULL,
				subtotal decimal(12,2) NOT NULL DEFAULT 0.00,
				tax_total decimal(12,2) NOT NULL DEFAULT 0.00,
				discount_total decimal(12,2) NOT NULL DEFAULT 0.00,
				total decimal(12,2) NOT NULL DEFAULT 0.00,
				currency varchar(10) NOT NULL DEFAULT 'USD',
				woocommerce_order_id bigint(20) unsigned NULL,
				source varchar(40) NOT NULL DEFAULT 'frontend',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY booking_number (booking_number),
				KEY customer_id (customer_id),
				KEY status (status),
				KEY pickup_at (pickup_at),
				KEY vehicle_id (vehicle_id),
				KEY woocommerce_order_id (woocommerce_order_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$booking_meta} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				booking_id bigint(20) unsigned NOT NULL,
				meta_key varchar(191) NOT NULL,
				meta_value longtext NULL,
				PRIMARY KEY  (id),
				KEY booking_id (booking_id),
				KEY meta_key (meta_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$quote_events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id varchar(100) NOT NULL DEFAULT '',
				customer_id bigint(20) unsigned NULL,
				service_type varchar(40) NOT NULL DEFAULT 'distance',
				pickup_address text NULL,
				dropoff_address text NULL,
				distance_value decimal(12,3) NULL,
				duration_seconds int unsigned NULL,
				quoted_total decimal(12,2) NOT NULL DEFAULT 0.00,
				currency varchar(10) NOT NULL DEFAULT 'USD',
				converted_to_booking_id bigint(20) unsigned NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY customer_id (customer_id),
				KEY created_at (created_at),
				KEY converted_to_booking_id (converted_to_booking_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$coupons} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(60) NOT NULL DEFAULT '',
				description text NULL,
				discount_type varchar(20) NOT NULL DEFAULT 'fixed',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				usage_limit int unsigned NULL,
				used_count int unsigned NOT NULL DEFAULT 0,
				starts_at datetime NULL,
				ends_at datetime NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY code (code),
				KEY is_active (is_active)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$availability} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(160) NOT NULL DEFAULT '',
				rule_type varchar(40) NOT NULL DEFAULT 'blackout',
				starts_at datetime NOT NULL,
				ends_at datetime NOT NULL,
				vehicle_id bigint(20) unsigned NULL,
				driver_id bigint(20) unsigned NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY starts_at (starts_at),
				KEY ends_at (ends_at),
				KEY vehicle_id (vehicle_id),
				KEY driver_id (driver_id),
				KEY is_active (is_active)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$pricing_rules} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(160) NOT NULL DEFAULT '',
				rule_type varchar(40) NOT NULL DEFAULT 'time_window',
				adjustment_type varchar(20) NOT NULL DEFAULT 'percent',
				amount decimal(12,2) NOT NULL DEFAULT 0.00,
				start_time varchar(5) NOT NULL DEFAULT '00:00',
				end_time varchar(5) NOT NULL DEFAULT '23:59',
				weekdays varchar(30) NOT NULL DEFAULT '',
				priority int NOT NULL DEFAULT 10,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY is_active (is_active),
				KEY priority (priority)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$route_cache} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				route_hash varchar(64) NOT NULL DEFAULT '',
				origin text NOT NULL,
				destination text NOT NULL,
				distance_value decimal(12,3) NULL,
				distance_unit varchar(10) NOT NULL DEFAULT 'km',
				duration_seconds int unsigned NULL,
				overview_polyline longtext NULL,
				raw_response longtext NULL,
				expires_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY route_hash (route_hash),
				KEY expires_at (expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$geofence_zones} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(160) NOT NULL DEFAULT '',
				description text NULL,
				center_lat decimal(10,6) NOT NULL,
				center_lng decimal(10,6) NOT NULL,
				radius_km decimal(8,2) NOT NULL DEFAULT 15.00,
				boundary_type varchar(30) NOT NULL DEFAULT 'radius',
				boundary_data longtext NULL,
				pricing_multiplier decimal(5,2) NOT NULL DEFAULT 1.00,
				enforcement_type varchar(30) NOT NULL DEFAULT 'soft_approval',
				is_active tinyint(1) NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY is_active (is_active),
				KEY enforcement_type (enforcement_type),
				KEY center_lat (center_lat),
				KEY center_lng (center_lng)
			) {$charset};"
		);

		update_option('rfb_db_version', RFB_VERSION);
	}

	private static function seed_options(): void {
		if (false !== get_option('rfb_settings')) {
			return;
		}

		add_option(
			'rfb_settings',
			[
				'currency' => 'USD',
				'distance_unit' => 'km',
				'google_maps_api_key' => '',
				'google_maps_map_id' => '',
				'woocommerce_checkout_enabled' => 'no',
				'dispatcher_api_enabled' => 'no',
				'dispatcher_api_key' => '',
				'enable_extras' => 'yes',
				'admin_email' => get_option('admin_email'),
				'review_link' => '',
				'email_templates' => [
					'booking_subject' => 'Your booking request {booking_number}',
					'booking_body' => "A booking request was created.\n\nBooking: {booking_number}\nPickup: {pickup_address}\nDrop-off: {dropoff_address}\nTotal: {currency} {total}",
				],
				'form_colors' => [
					'accent' => '#0f766e',
					'accent_dark' => '#0b5f59',
					'panel' => '#ffffff',
					'ink' => '#17202a',
				],
				'fare' => [
					'base_fare' => '25.00',
					'minimum_fare' => '35.00',
					'per_distance' => '2.00',
					'per_minute' => '0.00',
					'hourly_rate' => '75.00',
					'return_trip_multiplier' => '1.80',
				],
				'core_booking_rules' => self::core_booking_rules_defaults(),
			],
			'',
			false
		);
	}

	private static function merge_new_options(): void {
		$options = get_option('rfb_settings', []);
		if (!is_array($options)) {
			$options = [];
		}

		$defaults = [
			'enable_extras' => 'yes',
			'dispatcher_api_enabled' => 'no',
			'dispatcher_api_key' => '',
			'admin_email' => get_option('admin_email'),
			'review_link' => '',
			'email_templates' => [
				'booking_subject' => 'Your booking request {booking_number}',
				'booking_body' => "A booking request was created.\n\nBooking: {booking_number}\nPickup: {pickup_address}\nDrop-off: {dropoff_address}\nTotal: {currency} {total}",
			],
			'form_colors' => [
				'accent' => '#0f766e',
				'accent_dark' => '#0b5f59',
				'panel' => '#ffffff',
				'ink' => '#17202a',
			],
			'core_booking_rules' => self::core_booking_rules_defaults(),
		];

		update_option('rfb_settings', array_replace_recursive($defaults, $options), false);
	}

	public static function core_booking_rules_defaults(): array {
		return [
			'global_service_area' => [
				'enabled' => false,
				'type' => 'radius',
				'data' => '',
				'error_message' => 'This ride is outside our service area.',
			],
			'flat_rates' => [],
			'priority_rule' => 'flat_rate_only',
			'abuse_protection_enabled' => false,
			'abuse_protection_type' => 'force_flat_rate',
			'buffer_zone_enabled' => false,
			'buffer_zone_distance' => 500,
			'buffer_zone_fee_type' => 'flat_plus_fee',
			'buffer_zone_penalty_value' => 10,
			'chatbot_api_key' => '',
		];
	}
}
