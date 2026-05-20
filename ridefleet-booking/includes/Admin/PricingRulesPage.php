<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class PricingRulesPage {
	public static function render(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_pricing_rules';

		if (!empty($_POST['rfb_pricing_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_pricing_nonce'])), 'rfb_save_pricing_rule')) {
			$wpdb->insert(
				$table,
				[
					'label' => sanitize_text_field($_POST['label'] ?? ''),
					'rule_type' => 'time_window',
					'adjustment_type' => in_array(($_POST['adjustment_type'] ?? 'percent'), ['fixed', 'percent'], true) ? sanitize_text_field($_POST['adjustment_type']) : 'percent',
					'amount' => (float) ($_POST['amount'] ?? 0),
					'start_time' => sanitize_text_field($_POST['start_time'] ?? '22:00'),
					'end_time' => sanitize_text_field($_POST['end_time'] ?? '06:00'),
					'weekdays' => implode(',', array_map('sanitize_text_field', (array) ($_POST['weekdays'] ?? []))),
					'priority' => absint($_POST['priority'] ?? 10),
					'is_active' => !empty($_POST['is_active']) ? 1 : 0,
					'created_at' => current_time('mysql'),
					'updated_at' => current_time('mysql'),
				]
			);
		}

		$rules = $wpdb->get_results("SELECT * FROM {$table} ORDER BY priority ASC, id DESC LIMIT 100");
		$active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Operational', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Pricing Rules', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Set time-window surcharges for nights, weekends, and rush hours. Lower priority numbers run first.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('pricing'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Rules', 'ridefleet-booking'), number_format_i18n(count($rules)), __('Total pricing rules configured.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Active', 'ridefleet-booking'), number_format_i18n($active), __('Rules currently affecting quotes.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Rule Type', 'ridefleet-booking'), __('Time window', 'ridefleet-booking'), __('Useful for night, weekend, or rush-hour surcharges.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Priority', 'ridefleet-booking'), __('Low first', 'ridefleet-booking'), __('Lower priority numbers run earlier.', 'ridefleet-booking')); ?>
			</div>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php esc_html_e('Add Time Rule', 'ridefleet-booking'); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_pricing_rule', 'rfb_pricing_nonce'); ?>
						<label><span><?php esc_html_e('Label', 'ridefleet-booking'); ?></span><input name="label" type="text" value="<?php esc_attr_e('Night surcharge', 'ridefleet-booking'); ?>" required></label>
						<label><span><?php esc_html_e('Adjustment Type', 'ridefleet-booking'); ?></span><select name="adjustment_type"><option value="percent"><?php esc_html_e('Percent', 'ridefleet-booking'); ?></option><option value="fixed"><?php esc_html_e('Fixed', 'ridefleet-booking'); ?></option></select></label>
						<label><span><?php esc_html_e('Amount', 'ridefleet-booking'); ?></span><input name="amount" type="number" step="0.01" min="0" value="20" required></label>
						<label><span><?php esc_html_e('Start Time', 'ridefleet-booking'); ?></span><input name="start_time" type="time" value="22:00" required></label>
						<label><span><?php esc_html_e('End Time', 'ridefleet-booking'); ?></span><input name="end_time" type="time" value="06:00" required></label>
						<label><span><?php esc_html_e('Priority', 'ridefleet-booking'); ?></span><input name="priority" type="number" value="10"></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" checked><span><?php esc_html_e('Active', 'ridefleet-booking'); ?></span></label>
						<?php submit_button(__('Create Rule', 'ridefleet-booking')); ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Rules', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e('Label', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Window', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Adjustment', 'ridefleet-booking'); ?></th></tr></thead><tbody>
					<?php if (!$rules) : ?><tr><td colspan="3"><?php esc_html_e('No pricing rules yet.', 'ridefleet-booking'); ?></td></tr><?php endif; ?>
					<?php foreach ($rules as $rule) : ?>
						<tr><td><?php echo esc_html($rule->label); ?></td><td><?php echo esc_html($rule->start_time . ' - ' . $rule->end_time); ?></td><td><?php echo esc_html($rule->amount . ' ' . $rule->adjustment_type); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				</section>
			</div>
		</div>
		<?php
	}
}
