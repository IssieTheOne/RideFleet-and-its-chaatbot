<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class ExtrasPage {
	public static function render(): void {
		if (!empty($_GET['delete']) && !empty($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_extra')) {
			wp_delete_post(absint($_GET['delete']), true);
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-extras&deleted=1'));
			exit;
		}

		if (!empty($_POST['rfb_extra_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_extra_nonce'])), 'rfb_save_extra_page')) {
			$id = absint($_POST['extra_id'] ?? 0);
			$status = !empty($_POST['is_active']) ? 'publish' : 'draft';
			$post_data = [
				'post_type'   => 'rfb_extra',
				'post_title'  => sanitize_text_field($_POST['post_title'] ?? ''),
				'post_status' => $status,
			];
			if ($id) {
				$post_data['ID'] = $id;
				wp_update_post($post_data);
			} else {
				$id = wp_insert_post($post_data);
			}
			if ($id && !is_wp_error($id)) {
				update_post_meta($id, 'rfb_price', number_format((float) ($_POST['rfb_price'] ?? 0), 2, '.', ''));
				update_post_meta($id, 'rfb_max_quantity', max(1, absint($_POST['rfb_max_quantity'] ?? 1)));
				update_post_meta($id, 'rfb_extra_short_label', sanitize_text_field($_POST['rfb_extra_short_label'] ?? ''));
			}
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-extras&saved=1'));
			exit;
		}

		$editing = null;
		if (!empty($_GET['edit'])) {
			$editing = get_post(absint($_GET['edit']));
			if ($editing && 'rfb_extra' !== $editing->post_type) {
				$editing = null;
			}
		}

		$extras = get_posts(['post_type' => 'rfb_extra', 'post_status' => ['publish', 'draft'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
		$counts = wp_count_posts('rfb_extra');
		$active = (int) ($counts->publish ?? 0);
		$draft = (int) ($counts->draft ?? 0);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Fleet', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Booking Extras', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Child seats, meet and greet, luggage add-ons, and optional services available at checkout.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('extras'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Active extras', 'ridefleet-booking'), number_format_i18n($active), __('Shown to customers at checkout.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Draft', 'ridefleet-booking'), number_format_i18n($draft), __('Hidden from the booking form.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Total', 'ridefleet-booking'), number_format_i18n($active + $draft), __('All records including drafts.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Tip', 'ridefleet-booking'), '—', __('Extras can carry a fixed fee or a per-unit price.', 'ridefleet-booking')); ?>
			</div>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Extra saved.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Extra deleted.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php echo esc_html($editing ? __('Edit Extra', 'ridefleet-booking') : __('Add Extra', 'ridefleet-booking')); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_extra_page', 'rfb_extra_nonce'); ?>
						<input type="hidden" name="extra_id" value="<?php echo esc_attr((string) ($editing->ID ?? 0)); ?>">
						<label><span><?php esc_html_e('Extra Name', 'ridefleet-booking'); ?></span><input name="post_title" type="text" value="<?php echo esc_attr($editing->post_title ?? ''); ?>" required placeholder="<?php esc_attr_e('e.g. Child Seat', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Short Label', 'ridefleet-booking'); ?></span><input name="rfb_extra_short_label" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_extra_short_label', true) : ''); ?>" placeholder="<?php esc_attr_e('e.g. Child seat', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Price', 'ridefleet-booking'); ?></span><input name="rfb_price" type="number" step="0.01" min="0" value="<?php echo esc_attr($editing ? (string) (get_post_meta($editing->ID, 'rfb_price', true) ?: '0.00') : '0.00'); ?>"></label>
						<label><span><?php esc_html_e('Max Quantity', 'ridefleet-booking'); ?></span><input name="rfb_max_quantity" type="number" min="1" value="<?php echo esc_attr($editing ? (string) (get_post_meta($editing->ID, 'rfb_max_quantity', true) ?: 1) : '1'); ?>"></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" <?php checked(($editing ? $editing->post_status : 'publish'), 'publish'); ?>><span><?php esc_html_e('Active (shown at checkout)', 'ridefleet-booking'); ?></span></label>
						<?php submit_button($editing ? __('Update Extra', 'ridefleet-booking') : __('Add Extra', 'ridefleet-booking')); ?>
						<?php if ($editing) : ?>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-extras')); ?>"><?php esc_html_e('Cancel', 'ridefleet-booking'); ?></a>
						<?php endif; ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Existing Extras', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e('Name', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Short Label', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Price', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Max Qty', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Status', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th>
						</tr></thead>
						<tbody>
						<?php if (!$extras) : ?>
							<tr><td colspan="6"><?php esc_html_e('No extras yet. Add your first extra using the form.', 'ridefleet-booking'); ?></td></tr>
						<?php endif; ?>
						<?php foreach ($extras as $e) :
							$price = (string) get_post_meta($e->ID, 'rfb_price', true);
							$qty = (string) get_post_meta($e->ID, 'rfb_max_quantity', true);
							$label = (string) get_post_meta($e->ID, 'rfb_extra_short_label', true);
						?>
							<tr>
								<td><strong><?php echo esc_html($e->post_title); ?></strong></td>
								<td><?php echo esc_html($label ?: '—'); ?></td>
								<td><?php echo esc_html($price ?: '0.00'); ?></td>
								<td><?php echo esc_html($qty ?: '1'); ?></td>
								<td><span class="rfb-badge <?php echo 'publish' === $e->post_status ? 'rfb-badge-confirmed' : 'rfb-badge-pending'; ?>"><?php echo esc_html('publish' === $e->post_status ? __('Active', 'ridefleet-booking') : __('Draft', 'ridefleet-booking')); ?></span></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-extras&edit=' . $e->ID)); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
									<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-extras&delete=' . $e->ID), 'rfb_delete_extra')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this extra?', 'ridefleet-booking')); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
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
