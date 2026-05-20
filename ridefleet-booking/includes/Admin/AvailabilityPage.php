<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class AvailabilityPage {
	public static function render(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_availability_rules';

		if (!empty($_POST['rfb_availability_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_availability_nonce'])), 'rfb_save_availability')) {
			$wpdb->insert(
				$table,
				[
					'label' => sanitize_text_field($_POST['label'] ?? ''),
					'rule_type' => 'blackout',
					'starts_at' => sanitize_text_field($_POST['starts_at'] ?? ''),
					'ends_at' => sanitize_text_field($_POST['ends_at'] ?? ''),
					'vehicle_id' => absint($_POST['vehicle_id'] ?? 0) ?: null,
					'is_active' => !empty($_POST['is_active']) ? 1 : 0,
					'created_at' => current_time('mysql'),
					'updated_at' => current_time('mysql'),
				]
			);
		}

		$rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY starts_at DESC LIMIT 100");
		$active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
		$vehicles = get_posts(['post_type' => 'rfb_vehicle', 'post_status' => 'publish', 'numberposts' => 100]);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Operational', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Availability', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Block out time windows when the fleet is unavailable. Apply globally or restrict to a specific vehicle.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('availability'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Rules', 'ridefleet-booking'), number_format_i18n(count($rules)), __('Blackout windows configured.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Active', 'ridefleet-booking'), number_format_i18n($active), __('Currently enforced blocks.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Vehicles', 'ridefleet-booking'), number_format_i18n(count($vehicles)), __('Can be blocked individually.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Scope', 'ridefleet-booking'), __('Global / vehicle', 'ridefleet-booking'), __('Leave vehicle empty to block all online rides.', 'ridefleet-booking')); ?>
			</div>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php esc_html_e('Block Time', 'ridefleet-booking'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_availability', 'rfb_availability_nonce'); ?>
						<label><span><?php esc_html_e('Label', 'ridefleet-booking'); ?></span><input name="label" type="text" required></label>
						<label><span><?php esc_html_e('Starts At', 'ridefleet-booking'); ?></span><input name="starts_at" type="datetime-local" required></label>
						<label><span><?php esc_html_e('Ends At', 'ridefleet-booking'); ?></span><input name="ends_at" type="datetime-local" required></label>
						<label><span><?php esc_html_e('Vehicle optional', 'ridefleet-booking'); ?></span><select name="vehicle_id"><option value=""><?php esc_html_e('All vehicles', 'ridefleet-booking'); ?></option><?php foreach ($vehicles as $vehicle) : ?><option value="<?php echo esc_attr((string) $vehicle->ID); ?>"><?php echo esc_html(get_the_title($vehicle)); ?></option><?php endforeach; ?></select></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" checked><span><?php esc_html_e('Active', 'ridefleet-booking'); ?></span></label>
						<?php submit_button(__('Block Time', 'ridefleet-booking')); ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Blocked Times', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e('Label', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Starts', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Ends', 'ridefleet-booking'); ?></th></tr></thead><tbody>
					<?php if (!$rules) : ?><tr><td colspan="3"><?php esc_html_e('No blocked times yet.', 'ridefleet-booking'); ?></td></tr><?php endif; ?>
					<?php foreach ($rules as $rule) : ?>
						<tr><td><?php echo esc_html($rule->label); ?></td><td><?php echo esc_html($rule->starts_at); ?></td><td><?php echo esc_html($rule->ends_at); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</section>
			</div>
		</div>
		<?php
	}
}
