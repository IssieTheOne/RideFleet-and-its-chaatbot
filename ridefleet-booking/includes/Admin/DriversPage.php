<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class DriversPage {
	public static function render(): void {
		if (!empty($_GET['delete']) && !empty($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_driver')) {
			wp_delete_post(absint($_GET['delete']), true);
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-drivers&deleted=1'));
			exit;
		}

		if (!empty($_POST['rfb_driver_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_driver_nonce'])), 'rfb_save_driver_page')) {
			$id = absint($_POST['driver_id'] ?? 0);
			$status = !empty($_POST['is_active']) ? 'publish' : 'draft';
			$post_data = [
				'post_type'   => 'rfb_driver',
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
				update_post_meta($id, 'rfb_driver_phone', sanitize_text_field($_POST['rfb_driver_phone'] ?? ''));
				update_post_meta($id, 'rfb_driver_email', sanitize_email($_POST['rfb_driver_email'] ?? ''));
				update_post_meta($id, 'rfb_driver_license', sanitize_text_field($_POST['rfb_driver_license'] ?? ''));
				update_post_meta($id, 'rfb_driver_home_base', sanitize_text_field($_POST['rfb_driver_home_base'] ?? ''));
				update_post_meta($id, 'rfb_driver_notes', sanitize_textarea_field($_POST['rfb_driver_notes'] ?? ''));
			}
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-drivers&saved=1'));
			exit;
		}

		$editing = null;
		if (!empty($_GET['edit'])) {
			$editing = get_post(absint($_GET['edit']));
			if ($editing && 'rfb_driver' !== $editing->post_type) {
				$editing = null;
			}
		}

		$drivers = get_posts(['post_type' => 'rfb_driver', 'post_status' => ['publish', 'draft'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
		$counts = wp_count_posts('rfb_driver');
		$active = (int) ($counts->publish ?? 0);
		$draft = (int) ($counts->draft ?? 0);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Fleet', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Drivers', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Driver contact details, home bases, licences, and dispatch notes.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('drivers'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Active drivers', 'ridefleet-booking'), number_format_i18n($active), __('Available for dispatch.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Draft', 'ridefleet-booking'), number_format_i18n($draft), __('Not yet activated.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Total', 'ridefleet-booking'), number_format_i18n($active + $draft), __('All records including drafts.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Tip', 'ridefleet-booking'), '—', __('Set a home base to assist with nearest-driver dispatch.', 'ridefleet-booking')); ?>
			</div>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Driver saved.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Driver deleted.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php echo esc_html($editing ? __('Edit Driver', 'ridefleet-booking') : __('Add Driver', 'ridefleet-booking')); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_driver_page', 'rfb_driver_nonce'); ?>
						<input type="hidden" name="driver_id" value="<?php echo esc_attr((string) ($editing->ID ?? 0)); ?>">
						<label><span><?php esc_html_e('Full Name', 'ridefleet-booking'); ?></span><input name="post_title" type="text" value="<?php echo esc_attr($editing->post_title ?? ''); ?>" required placeholder="<?php esc_attr_e('e.g. John Smith', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Phone', 'ridefleet-booking'); ?></span><input name="rfb_driver_phone" type="tel" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_driver_phone', true) : ''); ?>"></label>
						<label><span><?php esc_html_e('Email', 'ridefleet-booking'); ?></span><input name="rfb_driver_email" type="email" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_driver_email', true) : ''); ?>"></label>
						<label><span><?php esc_html_e('License Number', 'ridefleet-booking'); ?></span><input name="rfb_driver_license" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_driver_license', true) : ''); ?>"></label>
						<label><span><?php esc_html_e('Home Base', 'ridefleet-booking'); ?></span><input name="rfb_driver_home_base" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_driver_home_base', true) : ''); ?>" placeholder="<?php esc_attr_e('e.g. Heathrow Airport', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Dispatch Notes', 'ridefleet-booking'); ?></span><textarea name="rfb_driver_notes" rows="4"><?php echo esc_textarea($editing ? (string) get_post_meta($editing->ID, 'rfb_driver_notes', true) : ''); ?></textarea></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" <?php checked(($editing ? $editing->post_status : 'publish'), 'publish'); ?>><span><?php esc_html_e('Active (published)', 'ridefleet-booking'); ?></span></label>
						<?php submit_button($editing ? __('Update Driver', 'ridefleet-booking') : __('Add Driver', 'ridefleet-booking')); ?>
						<?php if ($editing) : ?>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-drivers')); ?>"><?php esc_html_e('Cancel', 'ridefleet-booking'); ?></a>
						<?php endif; ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Driver Roster', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e('Name', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Phone', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Home Base', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Status', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th>
						</tr></thead>
						<tbody>
						<?php if (!$drivers) : ?>
							<tr><td colspan="5"><?php esc_html_e('No drivers yet. Add your first driver using the form.', 'ridefleet-booking'); ?></td></tr>
						<?php endif; ?>
						<?php foreach ($drivers as $d) :
							$phone = (string) get_post_meta($d->ID, 'rfb_driver_phone', true);
							$base = (string) get_post_meta($d->ID, 'rfb_driver_home_base', true);
							$email = (string) get_post_meta($d->ID, 'rfb_driver_email', true);
						?>
							<tr>
								<td><strong><?php echo esc_html($d->post_title); ?></strong><?php if ($email) : ?><br><span style="color:var(--rfb-muted);font-size:12px"><?php echo esc_html($email); ?></span><?php endif; ?></td>
								<td><?php echo esc_html($phone ?: '—'); ?></td>
								<td><?php echo esc_html($base ?: '—'); ?></td>
								<td><span class="rfb-badge <?php echo 'publish' === $d->post_status ? 'rfb-badge-confirmed' : 'rfb-badge-pending'; ?>"><?php echo esc_html('publish' === $d->post_status ? __('Active', 'ridefleet-booking') : __('Draft', 'ridefleet-booking')); ?></span></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-drivers&edit=' . $d->ID)); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
									<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-drivers&delete=' . $d->ID), 'rfb_delete_driver')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this driver?', 'ridefleet-booking')); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
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
