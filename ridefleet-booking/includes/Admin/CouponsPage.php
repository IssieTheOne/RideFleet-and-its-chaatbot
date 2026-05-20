<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class CouponsPage {
	public static function render(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_coupons';
		$editing = null;

		if (!empty($_GET['delete']) && !empty($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_coupon')) {
			$wpdb->delete($table, ['id' => absint($_GET['delete'])], ['%d']);
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-coupons&deleted=1'));
			exit;
		}

		if (!empty($_POST['rfb_coupon_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_coupon_nonce'])), 'rfb_save_coupon')) {
			$data = [
				'code' => strtoupper(sanitize_text_field($_POST['code'] ?? '')),
				'description' => sanitize_textarea_field($_POST['description'] ?? ''),
				'discount_type' => in_array(($_POST['discount_type'] ?? 'fixed'), ['fixed', 'percent'], true) ? sanitize_text_field($_POST['discount_type']) : 'fixed',
				'amount' => (float) ($_POST['amount'] ?? 0),
				'usage_limit' => '' === ($_POST['usage_limit'] ?? '') ? null : absint($_POST['usage_limit']),
				'starts_at' => sanitize_text_field($_POST['starts_at'] ?? '') ?: null,
				'ends_at' => sanitize_text_field($_POST['ends_at'] ?? '') ?: null,
				'is_active' => !empty($_POST['is_active']) ? 1 : 0,
				'updated_at' => current_time('mysql'),
			];
			$id = absint($_POST['coupon_id'] ?? 0);
			if ($id) {
				$wpdb->update($table, $data, ['id' => $id]);
			} else {
				$data['created_at'] = current_time('mysql');
				$wpdb->insert($table, $data);
			}
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-coupons&saved=1'));
			exit;
		}

		if (!empty($_GET['edit'])) {
			$editing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($_GET['edit'])));
		}

		$coupons = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 100");
		$active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
		$used = (int) $wpdb->get_var("SELECT COALESCE(SUM(used_count), 0) FROM {$table}");
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Promotions', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Coupons', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Create and manage promotional codes with fixed or percentage discounts, usage limits, and expiry dates.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('coupons'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Total coupons', 'ridefleet-booking'), number_format_i18n(count($coupons)), __('Campaigns created.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Active', 'ridefleet-booking'), number_format_i18n($active), __('Currently usable codes.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Total uses', 'ridefleet-booking'), number_format_i18n($used), __('How often coupons were applied.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Tip', 'ridefleet-booking'), __('WELCOME10', 'ridefleet-booking'), __('Use simple memorable codes for repeat riders.', 'ridefleet-booking')); ?>
			</div>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php echo esc_html($editing ? __('Edit Coupon', 'ridefleet-booking') : __('Add Coupon', 'ridefleet-booking')); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_coupon', 'rfb_coupon_nonce'); ?>
						<input type="hidden" name="coupon_id" value="<?php echo esc_attr((string) ($editing->id ?? 0)); ?>">
						<label><span><?php esc_html_e('Code', 'ridefleet-booking'); ?></span><input name="code" type="text" value="<?php echo esc_attr($editing->code ?? ''); ?>" required></label>
						<label><span><?php esc_html_e('Description', 'ridefleet-booking'); ?></span><input name="description" type="text" value="<?php echo esc_attr($editing->description ?? ''); ?>"></label>
						<label><span><?php esc_html_e('Discount Type', 'ridefleet-booking'); ?></span><select name="discount_type"><option value="fixed" <?php selected($editing->discount_type ?? '', 'fixed'); ?>><?php esc_html_e('Fixed', 'ridefleet-booking'); ?></option><option value="percent" <?php selected($editing->discount_type ?? '', 'percent'); ?>><?php esc_html_e('Percent', 'ridefleet-booking'); ?></option></select></label>
						<label><span><?php esc_html_e('Amount', 'ridefleet-booking'); ?></span><input name="amount" type="number" step="0.01" min="0" value="<?php echo esc_attr((string) ($editing->amount ?? '')); ?>" required></label>
						<label><span><?php esc_html_e('Usage Limit', 'ridefleet-booking'); ?></span><input name="usage_limit" type="number" min="0" value="<?php echo esc_attr((string) ($editing->usage_limit ?? '')); ?>"></label>
						<label><span><?php esc_html_e('Starts At', 'ridefleet-booking'); ?></span><input name="starts_at" type="datetime-local" value="<?php echo esc_attr(self::datetime_local($editing->starts_at ?? '')); ?>"></label>
						<label><span><?php esc_html_e('Ends At', 'ridefleet-booking'); ?></span><input name="ends_at" type="datetime-local" value="<?php echo esc_attr(self::datetime_local($editing->ends_at ?? '')); ?>"></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" <?php checked((int) ($editing->is_active ?? 1), 1); ?>><span><?php esc_html_e('Active', 'ridefleet-booking'); ?></span></label>
						<?php submit_button($editing ? __('Update Coupon', 'ridefleet-booking') : __('Create Coupon', 'ridefleet-booking')); ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Existing Coupons', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped"><thead><tr><th><?php esc_html_e('Code', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Discount', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Used', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th></tr></thead><tbody>
					<?php foreach ($coupons as $coupon) : ?>
						<tr>
							<td><strong><?php echo esc_html($coupon->code); ?></strong><br><?php echo esc_html($coupon->is_active ? __('Active', 'ridefleet-booking') : __('Inactive', 'ridefleet-booking')); ?></td>
							<td><?php echo esc_html($coupon->amount . ' ' . $coupon->discount_type); ?></td>
							<td><?php echo esc_html((string) $coupon->used_count); ?></td>
							<td>
								<a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-coupons&edit=' . (int) $coupon->id)); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
								<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-coupons&delete=' . (int) $coupon->id), 'rfb_delete_coupon')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this coupon?', 'ridefleet-booking')); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				</section>
			</div>
		</div>
		<?php
	}

	private static function datetime_local(?string $value): string {
		if (!$value) {
			return '';
		}
		$timestamp = strtotime($value);
		return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp) : '';
	}
}
