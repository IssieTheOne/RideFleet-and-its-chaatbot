<?php
/**
 * Admin menu and pages.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Core\DemoData;
use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class Admin {
	public static function register_hooks(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [SettingsPage::class, 'register_settings']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		add_action('add_meta_boxes', [MetaBoxes::class, 'register']);
		add_action('save_post_rfb_vehicle', [MetaBoxes::class, 'save_vehicle']);
		add_action('save_post_rfb_driver', [MetaBoxes::class, 'save_driver']);
		add_action('save_post_rfb_extra', [MetaBoxes::class, 'save_extra']);
		add_action('save_post_rfb_route', [MetaBoxes::class, 'save_route']);
		add_filter('manage_rfb_vehicle_posts_columns', [MetaBoxes::class, 'vehicle_columns']);
		add_action('manage_rfb_vehicle_posts_custom_column', [MetaBoxes::class, 'vehicle_column'], 10, 2);
		add_filter('manage_rfb_driver_posts_columns', [MetaBoxes::class, 'driver_columns']);
		add_action('manage_rfb_driver_posts_custom_column', [MetaBoxes::class, 'driver_column'], 10, 2);
		add_filter('manage_rfb_extra_posts_columns', [MetaBoxes::class, 'extra_columns']);
		add_action('manage_rfb_extra_posts_custom_column', [MetaBoxes::class, 'extra_column'], 10, 2);
		add_action('admin_post_rfb_seed_demo_data', [self::class, 'seed_demo_data']);
		add_action('admin_post_rfb_clear_demo_data', [self::class, 'clear_demo_data']);
		add_action('admin_post_rfb_generate_route_pages', [RoutePagesPage::class, 'generate']);
		add_action('admin_post_rfb_clear_route_cache', [RouteCachePage::class, 'clear']);
		add_action('admin_post_rfb_send_test_email', [SettingsPage::class, 'send_test_email']);
		add_action('admin_post_rfb_save_core_rules', [CoreRulesPage::class, 'save']);
		add_action('wp_ajax_rfb_customer_search', [BookingsPage::class, 'customer_search_ajax']);
		add_action('wp_ajax_rfb_quote_preview', [BookingsPage::class, 'quote_preview_ajax']);
		add_action('wp_ajax_rfb_create_cancellation_page', [CancellationPageGenerator::class, 'ajax_create']);
		add_filter('use_block_editor_for_post_type', [self::class, 'use_classic_editor'], 10, 2);
		add_filter('parent_file', [self::class, 'parent_file']);
		add_filter('submenu_file', [self::class, 'submenu_file']);
		add_action('all_admin_notices', [OperationalPage::class, 'maybe_render_admin_tabs']);
	}

	public static function use_classic_editor(bool $use_block_editor, string $post_type): bool {
		if (in_array($post_type, ['rfb_vehicle', 'rfb_driver', 'rfb_extra', 'rfb_route', 'rfb_location', 'rfb_form'], true)) {
			return false;
		}

		return $use_block_editor;
	}

	public static function register_menu(): void {
		add_menu_page(
			__('RideFleet Booking', 'ridefleet-booking'),
			__('RideFleet', 'ridefleet-booking'),
			'manage_options',
			'ridefleet-booking',
			[DashboardPage::class, 'render'],
			'dashicons-location-alt',
			56
		);

		add_submenu_page('ridefleet-booking', __('Dashboard', 'ridefleet-booking'), __('Dashboard', 'ridefleet-booking'), 'manage_options', 'ridefleet-booking', [DashboardPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Bookings', 'ridefleet-booking'), __('Bookings', 'ridefleet-booking'), 'manage_options', 'ridefleet-bookings', [BookingsPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Calendar', 'ridefleet-booking'), __('Calendar', 'ridefleet-booking'), 'manage_options', 'ridefleet-calendar', [CalendarPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Customers', 'ridefleet-booking'), __('Customers', 'ridefleet-booking'), 'manage_options', 'ridefleet-customers', [CustomersPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Operational', 'ridefleet-booking'), __('Operational', 'ridefleet-booking'), 'manage_options', 'ridefleet-operational', [OperationalPage::class, 'render']);
		add_submenu_page(null, __('Vehicles', 'ridefleet-booking'), __('Vehicles', 'ridefleet-booking'), 'manage_options', 'ridefleet-vehicles', [VehiclesPage::class, 'render']);
		add_submenu_page(null, __('Drivers', 'ridefleet-booking'), __('Drivers', 'ridefleet-booking'), 'manage_options', 'ridefleet-drivers', [DriversPage::class, 'render']);
		add_submenu_page(null, __('Extras', 'ridefleet-booking'), __('Extras', 'ridefleet-booking'), 'manage_options', 'ridefleet-extras', [ExtrasPage::class, 'render']);
		add_submenu_page(null, __('Coupons', 'ridefleet-booking'), __('Coupons', 'ridefleet-booking'), 'manage_options', 'ridefleet-coupons', [CouponsPage::class, 'render']);
		add_submenu_page(null, __('Pricing Rules', 'ridefleet-booking'), __('Pricing Rules', 'ridefleet-booking'), 'manage_options', 'ridefleet-pricing-rules', [PricingRulesPage::class, 'render']);
		add_submenu_page(null, __('Availability', 'ridefleet-booking'), __('Availability', 'ridefleet-booking'), 'manage_options', 'ridefleet-availability', [AvailabilityPage::class, 'render']);
		add_submenu_page(null, __('Routes & SEO', 'ridefleet-booking'), __('Routes & SEO', 'ridefleet-booking'), 'manage_options', 'ridefleet-route-pages', [RoutePagesPage::class, 'render']);
		add_submenu_page(null, __('Service Areas', 'ridefleet-booking'), __('Service Areas', 'ridefleet-booking'), 'manage_options', 'ridefleet-geofences', [GeofenceZonesPage::class, 'render']);
		add_submenu_page(null, __('Core Booking & Geofencing Rules', 'ridefleet-booking'), __('Core Rules', 'ridefleet-booking'), 'manage_options', 'ridefleet-core-rules', [CoreRulesPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Flight Tracker', 'ridefleet-booking'), __('✈ Flights', 'ridefleet-booking'), 'manage_options', 'ridefleet-flights', [FlightTrackerPage::class, 'render']);
		add_submenu_page('ridefleet-booking', __('Settings', 'ridefleet-booking'), __('Settings', 'ridefleet-booking'), 'manage_options', 'ridefleet-settings', [SettingsPage::class, 'render']);
	}

	public static function parent_file(string $parent_file): string {
		$screen = get_current_screen();
		$page = sanitize_key($_GET['page'] ?? '');
		if (($screen && in_array($screen->post_type, ['rfb_vehicle', 'rfb_driver', 'rfb_extra', 'rfb_route'], true)) || in_array($page, ['ridefleet-vehicles', 'ridefleet-drivers', 'ridefleet-extras', 'ridefleet-coupons', 'ridefleet-pricing-rules', 'ridefleet-availability', 'ridefleet-route-pages', 'ridefleet-geofences', 'ridefleet-core-rules'], true)) {
			return 'ridefleet-booking';
		}

		return $parent_file;
	}

	public static function submenu_file(?string $submenu_file): string {
		$screen = get_current_screen();
		$page = sanitize_key($_GET['page'] ?? '');

		// CPT screens and hidden operational pages → highlight Operational
		if (($screen && in_array($screen->post_type, ['rfb_vehicle', 'rfb_driver', 'rfb_extra', 'rfb_route'], true)) || in_array($page, ['ridefleet-vehicles', 'ridefleet-drivers', 'ridefleet-extras', 'ridefleet-coupons', 'ridefleet-pricing-rules', 'ridefleet-availability', 'ridefleet-route-pages', 'ridefleet-geofences', 'ridefleet-core-rules'], true)) {
			return 'ridefleet-operational';
		}

		// Visible submenu pages → return their own slug so WordPress highlights them correctly
		if (in_array($page, ['ridefleet-booking', 'ridefleet-bookings', 'ridefleet-calendar', 'ridefleet-customers', 'ridefleet-operational', 'ridefleet-flights', 'ridefleet-settings'], true)) {
			return $page;
		}

		return $submenu_file ?? '';
	}

	public static function enqueue(string $hook): void {
		$screen = get_current_screen();
		$is_rfb_screen = $screen && in_array($screen->post_type, ['rfb_vehicle', 'rfb_extra', 'rfb_driver', 'rfb_location', 'rfb_route', 'rfb_form'], true);

		if (false === strpos($hook, 'ridefleet') && !$is_rfb_screen) {
			return;
		}

		// Global styles — always loaded on every RideFleet screen.
		wp_enqueue_style('rfb-admin', RFB_PLUGIN_URL . 'assets/admin/admin.css', [], RFB_VERSION);

		// Page-specific styles — loaded only on the relevant screen.
		$page = sanitize_key($_GET['page'] ?? '');

		$page_css = [
			'ridefleet-booking'       => 'admin-dashboard',
			'ridefleet-bookings'      => 'admin-bookings',
			'ridefleet-calendar'      => 'admin-calendar',
			'ridefleet-customers'     => 'admin-customers',
			'ridefleet-operational'   => 'admin-operational',
			'ridefleet-vehicles'      => 'admin-bookings',
			'ridefleet-drivers'       => 'admin-bookings',
			'ridefleet-extras'        => 'admin-bookings',
			'ridefleet-coupons'       => 'admin-bookings',
			'ridefleet-pricing-rules' => 'admin-pricing',
			'ridefleet-availability'  => 'admin-availability',
			'ridefleet-route-pages'   => 'admin-geo',
			'ridefleet-geofences'     => 'admin-geo',
			'ridefleet-core-rules'    => 'admin-pricing',
			'ridefleet-settings'      => 'admin-settings',
			'ridefleet-flights'       => 'admin-flights',
		];

		if (isset($page_css[$page])) {
			$file = $page_css[$page];
			wp_enqueue_style(
				'rfb-' . $file,
				RFB_PLUGIN_URL . 'assets/admin/' . $file . '.css',
				['rfb-admin'],
				RFB_VERSION
			);
		}

		// Availability also needs calendar day styles.
		if ('ridefleet-availability' === $page) {
			wp_enqueue_style('rfb-admin-calendar', RFB_PLUGIN_URL . 'assets/admin/admin-calendar.css', ['rfb-admin'], RFB_VERSION);
		}

		// Post type edit/list screens.
		if ($is_rfb_screen) {
			wp_enqueue_style('rfb-admin-post-types', RFB_PLUGIN_URL . 'assets/admin/admin-post-types.css', ['rfb-admin'], RFB_VERSION);
		}

		wp_enqueue_script('rfb-admin', RFB_PLUGIN_URL . 'assets/admin/admin.js', [], RFB_VERSION, true);
		$options = Options::all();
		wp_localize_script(
			'rfb-admin',
			'RideFleetAdmin',
			[
				'screen'               => $hook,
				'dispatcherApiEnabled' => 'yes' === ($options['dispatcher_api_enabled'] ?? 'no'),
				'dispatcherApiHasKey'  => !empty($options['dispatcher_api_key']),
				'dispatcherBaseUrl'    => rest_url('ridefleet/v1/dispatcher'),
				'ajaxUrl'              => admin_url('admin-ajax.php'),
				'nonce'                => wp_create_nonce('rfb_admin_ajax'),
			]
		);
		$key = Options::get('google_maps_api_key', '');
		if ($key) {
			wp_enqueue_script('google-maps', add_query_arg(['key' => $key, 'libraries' => 'places,routes', 'loading' => 'async', 'v' => 'weekly'], 'https://maps.googleapis.com/maps/api/js'), [], null, true);
		}
	}

	public static function seed_demo_data(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to seed demo data.', 'ridefleet-booking'));
		}

		check_admin_referer('rfb_seed_demo_data');
		DemoData::seed();
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-booking&rfb_seeded=1'));
		exit;
	}

	public static function clear_demo_data(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to clear demo data.', 'ridefleet-booking'));
		}

		check_admin_referer('rfb_clear_demo_data');
		DemoData::clear();
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-settings&tab=setup&rfb_demo_cleared=1'));
		exit;
	}
}
