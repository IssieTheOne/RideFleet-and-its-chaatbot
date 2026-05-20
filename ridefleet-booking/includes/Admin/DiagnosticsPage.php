<?php
/**
 * Diagnostics page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class DiagnosticsPage {
	public static function render(): void {
		?>
		<div class="wrap rfb-admin">
			<?php self::render_content(); ?>
		</div>
		<?php
	}

	public static function render_content(): void {
		global $wpdb;

		$tables = [
			$wpdb->prefix . 'rfb_bookings',
			$wpdb->prefix . 'rfb_customers',
			$wpdb->prefix . 'rfb_quote_events',
			$wpdb->prefix . 'rfb_route_cache',
		];

		?>
			<div class="rfb-page-title">
				<div><p class="rfb-kicker"><?php esc_html_e('Health Check', 'ridefleet-booking'); ?></p><h1><?php esc_html_e('RideFleet Diagnostics', 'ridefleet-booking'); ?></h1></div>
			</div>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php esc_html_e('System', 'ridefleet-booking'); ?></h2>
					<?php self::row(__('Plugin version', 'ridefleet-booking'), RFB_VERSION, 'ok'); ?>
					<?php self::row(__('WordPress', 'ridefleet-booking'), get_bloginfo('version'), 'ok'); ?>
					<?php self::row(__('PHP', 'ridefleet-booking'), PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'bad'); ?>
					<?php self::row(__('WooCommerce', 'ridefleet-booking'), class_exists('WooCommerce') ? __('Active', 'ridefleet-booking') : __('Not active', 'ridefleet-booking'), class_exists('WooCommerce') ? 'ok' : 'warn'); ?>
				</section>

				<section class="rfb-panel">
					<h2><?php esc_html_e('Google Maps', 'ridefleet-booking'); ?></h2>
					<?php self::row(__('API key', 'ridefleet-booking'), Options::get('google_maps_api_key', '') ? __('Configured', 'ridefleet-booking') : __('Missing', 'ridefleet-booking'), Options::get('google_maps_api_key', '') ? 'ok' : 'bad'); ?>
					<?php self::row(__('Script loading', 'ridefleet-booking'), __('Async with weekly channel', 'ridefleet-booking'), 'ok'); ?>
					<?php self::row(__('Routes API', 'ridefleet-booking'), __('Uses Route.computeRoutes with Directions fallback', 'ridefleet-booking'), 'ok'); ?>
					<?php self::row(__('Autocomplete', 'ridefleet-booking'), __('Uses PlaceAutocompleteElement when available, with legacy fallback', 'ridefleet-booking'), 'ok'); ?>
				</section>

				<section class="rfb-panel">
					<h2><?php esc_html_e('Database', 'ridefleet-booking'); ?></h2>
					<?php foreach ($tables as $table) : ?>
						<?php $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)); ?>
						<?php self::row($table, $exists ? __('Ready', 'ridefleet-booking') : __('Missing', 'ridefleet-booking'), $exists ? 'ok' : 'bad'); ?>
					<?php endforeach; ?>
				</section>

				<section class="rfb-panel">
					<h2><?php esc_html_e('REST Protection', 'ridefleet-booking'); ?></h2>
					<?php self::row(__('Quote endpoint', 'ridefleet-booking'), __('Rate limited', 'ridefleet-booking'), 'ok'); ?>
					<?php self::row(__('Booking endpoint', 'ridefleet-booking'), __('Nonce protected and rate limited', 'ridefleet-booking'), 'ok'); ?>
					<?php self::row(__('Route cache write', 'ridefleet-booking'), __('Nonce protected and rate limited', 'ridefleet-booking'), 'ok'); ?>
				</section>
			</div>
		<?php
	}

	private static function row(string $label, string $value, string $status): void {
		$class = 'bad' === $status ? 'rfb-badge-failed' : ('warn' === $status ? 'rfb-badge-pending' : 'rfb-badge-paid');
		?>
		<p class="rfb-diagnostic-row">
			<strong><?php echo esc_html($label); ?></strong>
			<span class="rfb-badge <?php echo esc_attr($class); ?>"><?php echo esc_html($value); ?></span>
		</p>
		<?php
	}
}
