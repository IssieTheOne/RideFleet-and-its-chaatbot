<?php
/**
 * Activation and database setup.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class Installer {
	public const CRON_CLEANUP_HOOK = 'rfac_session_cleanup';

	public static function activate(): void {
		self::create_tables();
		self::seed_options();
		self::schedule_cron();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook(self::CRON_CLEANUP_HOOK);
		flush_rewrite_rules();
	}

	public static function maybe_update(): void {
		if (get_option('rfac_db_version') === RFAC_VERSION && self::diagnostic_table_exists()) {
			self::schedule_cron();
			return;
		}

		self::create_tables();
		self::merge_options();
		self::schedule_cron();
		update_option('rfac_db_version', RFAC_VERSION);
	}

	public static function schedule_cron(): void {
		if (!wp_next_scheduled(self::CRON_CLEANUP_HOOK)) {
			wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_CLEANUP_HOOK);
		}
	}

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$sessions = $wpdb->prefix . 'rfac_chat_sessions';
		$messages = $wpdb->prefix . 'rfac_chat_messages';
		$bookings = $wpdb->prefix . 'rfac_booking_events';
		$change_requests = $wpdb->prefix . 'rfac_change_requests';
		$errors = $wpdb->prefix . 'rfac_error_log';
		$usage = $wpdb->prefix . 'rfac_ai_usage';
		$diagnostics = $wpdb->prefix . 'rfac_diagnostic_events';

		dbDelta(
			"CREATE TABLE {$sessions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_key varchar(100) NOT NULL DEFAULT '',
				state varchar(60) NOT NULL DEFAULT 'greeting',
				collected_data longtext NULL,
				last_quote longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY session_key (session_key),
				KEY state (state),
				KEY updated_at (updated_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$messages} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NOT NULL,
				role varchar(20) NOT NULL DEFAULT 'user',
				message longtext NOT NULL,
				admin_summary text NULL,
				detected_language varchar(10) NOT NULL DEFAULT '',
				intent varchar(40) NOT NULL DEFAULT '',
				extracted_fields longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY role (role),
				KEY intent (intent)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$bookings} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NULL,
				core_booking_id varchar(80) NOT NULL DEFAULT '',
				pickup_address text NULL,
				dropoff_address text NULL,
				customer_name varchar(190) NOT NULL DEFAULT '',
				customer_phone varchar(80) NOT NULL DEFAULT '',
				pickup_time varchar(120) NOT NULL DEFAULT '',
				verified_price decimal(12,2) NOT NULL DEFAULT 0.00,
				currency varchar(10) NOT NULL DEFAULT 'USD',
				status varchar(40) NOT NULL DEFAULT 'submitted',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY core_booking_id (core_booking_id),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$change_requests} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NULL,
				core_booking_id varchar(80) NOT NULL DEFAULT '',
				customer_name varchar(190) NOT NULL DEFAULT '',
				customer_phone varchar(80) NOT NULL DEFAULT '',
				request_type varchar(40) NOT NULL DEFAULT 'booking_change',
				original_price decimal(12,2) NULL,
				proposed_price decimal(12,2) NULL,
				currency varchar(10) NOT NULL DEFAULT 'USD',
				discount_percent decimal(6,2) NULL,
				request_text longtext NOT NULL,
				status varchar(40) NOT NULL DEFAULT 'pending',
				customer_response text NULL,
				counteroffer_price decimal(12,2) NULL,
				resolved_at datetime NULL,
				admin_note text NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY core_booking_id (core_booking_id),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$errors} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NULL,
				level varchar(20) NOT NULL DEFAULT 'error',
				source varchar(60) NOT NULL DEFAULT '',
				message text NOT NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY level (level),
				KEY source (source),
				KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$usage} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				day date NOT NULL,
				ip_hash varchar(64) NOT NULL DEFAULT '',
				tokens_in int unsigned NOT NULL DEFAULT 0,
				tokens_out int unsigned NOT NULL DEFAULT 0,
				requests int unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY day_ip (day, ip_hash),
				KEY day (day)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$diagnostics} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				session_id bigint(20) unsigned NULL,
				session_key varchar(100) NOT NULL DEFAULT '',
				event_type varchar(60) NOT NULL DEFAULT '',
				source varchar(60) NOT NULL DEFAULT '',
				summary text NULL,
				context longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY session_id (session_id),
				KEY session_key (session_key),
				KEY event_type (event_type),
				KEY source (source),
				KEY created_at (created_at)
			) {$charset};"
		);

		update_option('rfac_db_version', RFAC_VERSION);
	}

	private static function diagnostic_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'rfac_diagnostic_events';
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	private static function seed_options(): void {
		if (false !== get_option('rfac_settings')) {
			self::merge_options();
			return;
		}

		add_option('rfac_settings', self::defaults(), '', false);
	}

	private static function merge_options(): void {
		$options = get_option('rfac_settings', []);
		if (!is_array($options)) {
			$options = [];
		}

		update_option('rfac_settings', array_replace_recursive(self::defaults(), $options), false);
	}

	public static function defaults(): array {
		return [
			'openrouter_key' => '',
			'selected_model' => 'openai/gpt-4o-mini',
			'company_bio' => '',
			'core_plugin_api_url' => home_url(),
			'core_plugin_api_key' => '',
			'dispatch_contact_number' => '',
			'max_price_negotiation_discount' => 20,
			'data_retention_days' => 90,
			'notification_email' => '',
			'notifications_enabled' => 1,
			'ai_daily_token_budget' => 250000,
			'ai_per_ip_daily_cap' => 200,
			'session_signing_secret' => '',
			'update_endpoint' => '',
			'license_key' => '',
			'faq_items' => [],
			'dispatch_response_minutes' => 15,
			'popular_destinations' => [],
			'service_area_lat' => '',
			'service_area_lng' => '',
			'service_area_radius_km' => 100,
			'fast_model' => '',
			'quality_model' => '',
			'stripe_secret_key' => '',
			'stripe_payment_link_enabled' => 0,
			'chatbot_ui_theme' => [
				'primary' => '#0f766e',
				'primary_dark' => '#0b5f59',
				'surface' => '#ffffff',
				'text' => '#17202a',
				'muted' => '#64748b',
			],
		];
	}
}
