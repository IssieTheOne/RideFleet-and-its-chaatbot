<?php
/**
 * Availability admin page — business hours, advance notice, max window, blackout rules.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Booking\AvailabilityService;
use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class AvailabilityPage {

	public static function render(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_availability_rules';

		// ── Save business schedule ────────────────────────────────────────────
		if (
			!empty($_POST['rfb_schedule_nonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_schedule_nonce'])), 'rfb_save_schedule')
		) {
			$raw_hours = is_array($_POST['bh'] ?? null) ? $_POST['bh'] : [];
			$hours     = [];
			foreach (range(0, 6) as $dow) {
				$h          = is_array($raw_hours[$dow] ?? null) ? $raw_hours[$dow] : [];
				$hours[$dow] = [
					'enabled' => !empty($h['enabled']),
					'open'    => sanitize_text_field(wp_unslash((string) ($h['open']  ?? '06:00'))),
					'close'   => sanitize_text_field(wp_unslash((string) ($h['close'] ?? '22:00'))),
				];
			}
			Options::update([
				'availability_settings' => [
					'min_advance_hours'      => max(0, (int) wp_unslash($_POST['min_advance_hours'] ?? 2)),
					'max_booking_days'       => max(1, min(365, (int) wp_unslash($_POST['max_booking_days'] ?? 90))),
					'business_hours_enabled' => !empty($_POST['business_hours_enabled']),
					'business_hours'         => $hours,
				],
			]);
			add_settings_error('rfb_avail', 'saved', __('Schedule saved.', 'ridefleet-booking'), 'updated');
		}

		// ── Add blackout rule ─────────────────────────────────────────────────
		if (
			!empty($_POST['rfb_availability_nonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_availability_nonce'])), 'rfb_save_availability')
		) {
			$starts = sanitize_text_field(wp_unslash($_POST['starts_at'] ?? ''));
			$ends   = sanitize_text_field(wp_unslash($_POST['ends_at']   ?? ''));
			if ($starts && strlen($starts) === 10) { $starts .= ' 00:00:00'; }
			if ($ends   && strlen($ends)   === 10) { $ends   .= ' 23:59:59'; }
			$wpdb->insert(
				$table,
				[
					'label'      => sanitize_text_field(wp_unslash($_POST['label']      ?? '')),
					'rule_type'  => 'blackout',
					'starts_at'  => $starts,
					'ends_at'    => $ends,
					'vehicle_id' => absint(wp_unslash($_POST['vehicle_id'] ?? 0)) ?: null,
					'is_active'  => !empty($_POST['is_active']) ? 1 : 0,
					'created_at' => current_time('mysql'),
					'updated_at' => current_time('mysql'),
				],
				['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s']
			);
			add_settings_error('rfb_avail', 'blackout_added', __('Blackout rule added.', 'ridefleet-booking'), 'updated');
		}

		// ── Delete a blackout rule ────────────────────────────────────────────
		if (
			!empty($_GET['delete_rule'])
			&& !empty($_GET['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_rule')
		) {
			$wpdb->delete($table, ['id' => absint($_GET['delete_rule'])], ['%d']);
			add_settings_error('rfb_avail', 'deleted', __('Rule deleted.', 'ridefleet-booking'), 'updated');
		}

		$rules    = $wpdb->get_results("SELECT * FROM {$table} ORDER BY starts_at DESC LIMIT 200");
		$active   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
		$vehicles = get_posts(['post_type' => 'rfb_vehicle', 'post_status' => 'publish', 'numberposts' => 100]);
		$settings = AvailabilityService::get_settings();
		$bh       = $settings['business_hours'];
		$days     = [
			0 => __('Sunday',    'ridefleet-booking'),
			1 => __('Monday',    'ridefleet-booking'),
			2 => __('Tuesday',   'ridefleet-booking'),
			3 => __('Wednesday', 'ridefleet-booking'),
			4 => __('Thursday',  'ridefleet-booking'),
			5 => __('Friday',    'ridefleet-booking'),
			6 => __('Saturday',  'ridefleet-booking'),
		];

		settings_errors('rfb_avail');
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Operational', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Availability', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Define when bookings are accepted. The chatbot calendar and booking form follow these settings automatically — configure once, applies everywhere.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('availability'); ?>

			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Min. Notice', 'ridefleet-booking'), $settings['min_advance_hours'] . 'h', __('Advance booking required.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Window', 'ridefleet-booking'), $settings['max_booking_days'] . ' ' . __('days', 'ridefleet-booking'), __('Max future booking range.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Blackouts', 'ridefleet-booking'), number_format_i18n($active), __('Active blocked periods.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Hours', 'ridefleet-booking'), $settings['business_hours_enabled'] ? __('Enforced', 'ridefleet-booking') : __('Off', 'ridefleet-booking'), __('Business hours guard.', 'ridefleet-booking')); ?>
			</div>

			<div class="rfb-settings-grid">

				<!-- ── Business Schedule ─────────────────────────────── -->
				<section class="rfb-panel" style="grid-column:1/-1;">
					<h2><?php esc_html_e('Business Schedule', 'ridefleet-booking'); ?></h2>
					<p style="color:#6b7280;margin-top:0;max-width:620px;">
						<?php esc_html_e('These settings are the single source of truth for booking availability across the entire platform. The AI chatbot and the main booking form both read from here — no duplicate configuration needed.', 'ridefleet-booking'); ?>
					</p>
					<form method="post">
						<?php wp_nonce_field('rfb_save_schedule', 'rfb_schedule_nonce'); ?>

						<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px 28px;max-width:540px;margin-bottom:24px;">
							<label>
								<span><?php esc_html_e('Minimum advance notice (hours)', 'ridefleet-booking'); ?></span>
								<input type="number" name="min_advance_hours" value="<?php echo esc_attr((string) $settings['min_advance_hours']); ?>" min="0" max="168" step="1">
								<small><?php esc_html_e('0 = accept immediately. Prevents last-second bookings.', 'ridefleet-booking'); ?></small>
							</label>
							<label>
								<span><?php esc_html_e('Maximum booking window (days)', 'ridefleet-booking'); ?></span>
								<input type="number" name="max_booking_days" value="<?php echo esc_attr((string) $settings['max_booking_days']); ?>" min="1" max="365" step="1">
								<small><?php esc_html_e('How far ahead customers can pre-book. Default: 90 days.', 'ridefleet-booking'); ?></small>
							</label>
						</div>

						<label class="rfb-checkbox" style="margin-bottom:20px;display:flex;align-items:center;gap:8px;">
							<input type="checkbox" name="business_hours_enabled" value="1" <?php checked($settings['business_hours_enabled']); ?>>
							<span><?php esc_html_e('Enforce business hours — disable time slots outside opening hours', 'ridefleet-booking'); ?></span>
						</label>

						<table class="widefat" style="max-width:620px;margin-bottom:24px;">
							<thead>
								<tr>
									<th style="width:130px;"><?php esc_html_e('Day', 'ridefleet-booking'); ?></th>
									<th style="width:60px;"><?php esc_html_e('Open', 'ridefleet-booking'); ?></th>
									<th><?php esc_html_e('Opens at', 'ridefleet-booking'); ?></th>
									<th><?php esc_html_e('Closes at', 'ridefleet-booking'); ?></th>
								</tr>
							</thead>
							<tbody>
							<?php foreach (range(0, 6) as $dow) :
								$d = $bh[$dow];
							?>
								<tr>
									<td><strong><?php echo esc_html($days[$dow]); ?></strong></td>
									<td>
										<label class="rfb-checkbox">
											<input type="checkbox" name="bh[<?php echo (int) $dow; ?>][enabled]" value="1" <?php checked($d['enabled']); ?>>
											<span></span>
										</label>
									</td>
									<td><input type="time" name="bh[<?php echo (int) $dow; ?>][open]"  value="<?php echo esc_attr($d['open']); ?>" style="width:110px;"></td>
									<td><input type="time" name="bh[<?php echo (int) $dow; ?>][close]" value="<?php echo esc_attr($d['close']); ?>" style="width:110px;"></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>

						<?php submit_button(__('Save Schedule', 'ridefleet-booking'), 'primary', 'submit', false); ?>
					</form>
				</section>

				<!-- ── Add Blackout ──────────────────────────────────── -->
				<section class="rfb-panel">
					<h2><?php esc_html_e('Block Dates / Periods', 'ridefleet-booking'); ?></h2>
					<p style="color:#6b7280;margin-top:0;"><?php esc_html_e('Block specific date ranges for holidays, maintenance, or events. Leave vehicle empty to block all bookings globally.', 'ridefleet-booking'); ?></p>
					<form method="post">
						<?php wp_nonce_field('rfb_save_availability', 'rfb_availability_nonce'); ?>
						<label>
							<span><?php esc_html_e('Label', 'ridefleet-booking'); ?></span>
							<input name="label" type="text" required placeholder="<?php esc_attr_e('e.g. Christmas holiday', 'ridefleet-booking'); ?>">
						</label>
						<label>
							<span><?php esc_html_e('From', 'ridefleet-booking'); ?></span>
							<input name="starts_at" type="date" required>
						</label>
						<label>
							<span><?php esc_html_e('Until (inclusive)', 'ridefleet-booking'); ?></span>
							<input name="ends_at" type="date" required>
						</label>
						<label>
							<span><?php esc_html_e('Vehicle (optional)', 'ridefleet-booking'); ?></span>
							<select name="vehicle_id">
								<option value=""><?php esc_html_e('All vehicles (global)', 'ridefleet-booking'); ?></option>
								<?php foreach ($vehicles as $vehicle) : ?>
									<option value="<?php echo esc_attr((string) $vehicle->ID); ?>"><?php echo esc_html(get_the_title($vehicle)); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="rfb-checkbox">
							<input name="is_active" type="checkbox" value="1" checked>
							<span><?php esc_html_e('Active immediately', 'ridefleet-booking'); ?></span>
						</label>
						<?php submit_button(__('Add Blackout', 'ridefleet-booking'), 'secondary', 'submit', false); ?>
					</form>
				</section>

				<!-- ── Blackout List ─────────────────────────────────── -->
				<section class="rfb-panel">
					<h2><?php esc_html_e('Blocked Periods', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e('Label', 'ridefleet-booking'); ?></th>
								<th><?php esc_html_e('From', 'ridefleet-booking'); ?></th>
								<th><?php esc_html_e('Until', 'ridefleet-booking'); ?></th>
								<th><?php esc_html_e('Scope', 'ridefleet-booking'); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
						<?php if (!$rules) : ?>
							<tr><td colspan="5" style="color:#9ca3af;"><?php esc_html_e('No blocked periods configured yet.', 'ridefleet-booking'); ?></td></tr>
						<?php endif; ?>
						<?php foreach ($rules as $rule) :
							$del_url = wp_nonce_url(
								add_query_arg(['delete_rule' => $rule->id]),
								'rfb_delete_rule'
							);
							$scope = $rule->vehicle_id
								? get_the_title((int) $rule->vehicle_id)
								: __('All vehicles', 'ridefleet-booking');
						?>
							<tr>
								<td>
									<?php echo esc_html($rule->label); ?>
									<?php if (!$rule->is_active) : ?>
										<em style="color:#9ca3af;font-size:11px;"> (<?php esc_html_e('inactive', 'ridefleet-booking'); ?>)</em>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html(substr($rule->starts_at, 0, 10)); ?></td>
								<td><?php echo esc_html(substr($rule->ends_at,   0, 10)); ?></td>
								<td><?php echo esc_html($scope); ?></td>
								<td>
									<a href="<?php echo esc_url($del_url); ?>"
									   onclick="return confirm('<?php esc_attr_e('Delete this blackout rule?', 'ridefleet-booking'); ?>')"
									   style="color:#dc2626;">
										<?php esc_html_e('Delete', 'ridefleet-booking'); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</section>

			</div>
		</div>
		<?php
	}
}
