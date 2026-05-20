<?php
/**
 * Setup checklist.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class SetupPage {
	public static function render(): void {
		?>
		<div class="wrap rfb-admin">
			<?php self::render_content(); ?>
		</div>
		<?php
	}

	public static function render_content(): void {
		$checks = [
			__('Google Maps API key', 'ridefleet-booking') => (bool) Options::get('google_maps_api_key', ''),
			__('At least one vehicle', 'ridefleet-booking') => (bool) wp_count_posts('rfb_vehicle')->publish,
			__('At least one booking form page', 'ridefleet-booking') => self::has_shortcode_page(),
			__('WooCommerce active when checkout is enabled', 'ridefleet-booking') => 'yes' !== Options::get('woocommerce_checkout_enabled', 'no') || class_exists('WooCommerce'),
			__('Notification email configured', 'ridefleet-booking') => (bool) Options::get('admin_email', get_option('admin_email')),
		];
		?>
		<?php if (!empty($_GET['rfb_demo_cleared'])) : ?>
			<div class="notice notice-success"><p><?php esc_html_e('Demo data cleared.', 'ridefleet-booking'); ?></p></div>
		<?php endif; ?>
		<div class="rfb-page-title">
			<div><p class="rfb-kicker"><?php esc_html_e('Launch Checklist', 'ridefleet-booking'); ?></p><h1><?php esc_html_e('RideFleet Setup', 'ridefleet-booking'); ?></h1></div>
		</div>
			<div class="rfb-ops-grid">
				<section class="rfb-panel">
					<h2><?php esc_html_e('Readiness', 'ridefleet-booking'); ?></h2>
					<div class="rfb-setup-list">
						<?php foreach ($checks as $label => $done) : ?>
							<div class="<?php echo esc_attr($done ? 'is-done' : ''); ?>"><strong><?php echo esc_html($done ? 'Done' : 'Todo'); ?></strong><span><?php echo esc_html($label); ?></span></div>
						<?php endforeach; ?>
					</div>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Demo Tools', 'ridefleet-booking'); ?></h2>
					<p><?php esc_html_e('Use demo data while testing, then clear it before handing the site to a client.', 'ridefleet-booking'); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="rfb_seed_demo_data">
						<?php wp_nonce_field('rfb_seed_demo_data'); ?>
						<?php submit_button(__('Seed Demo Data', 'ridefleet-booking'), 'secondary', 'submit', false); ?>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rfb-danger-action">
						<input type="hidden" name="action" value="rfb_clear_demo_data">
						<?php wp_nonce_field('rfb_clear_demo_data'); ?>
						<?php submit_button(__('Clear Demo Data', 'ridefleet-booking'), 'delete', 'submit', false); ?>
					</form>
				</section>
			</div>
		<?php
	}

	private static function has_shortcode_page(): bool {
		$pages = get_posts(['post_type' => 'page', 'post_status' => ['publish', 'draft'], 'numberposts' => 50, 's' => '[ridefleet_booking_form']);
		foreach ($pages as $page) {
			if (str_contains((string) $page->post_content, '[ridefleet_booking_form')) {
				return true;
			}
		}

		return false;
	}
}
