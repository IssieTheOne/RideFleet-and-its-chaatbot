<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class VehiclesPage {
	private static array $brands = ['Mercedes-Benz', 'BMW', 'Audi', 'Tesla', 'Cadillac', 'Lincoln', 'Chevrolet', 'Ford', 'Toyota', 'Volvo', 'Other'];

	public static function render(): void {
		if (!empty($_GET['delete']) && !empty($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_vehicle')) {
			wp_delete_post(absint($_GET['delete']), true);
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-vehicles&deleted=1'));
			exit;
		}

		if (!empty($_POST['rfb_vehicle_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_vehicle_nonce'])), 'rfb_save_vehicle_page')) {
			$id = absint($_POST['vehicle_id'] ?? 0);
			$status = !empty($_POST['is_active']) ? 'publish' : 'draft';
			$post_data = [
				'post_type'   => 'rfb_vehicle',
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
				update_post_meta($id, 'rfb_passenger_capacity', max(1, absint($_POST['rfb_passenger_capacity'] ?? 4)));
				update_post_meta($id, 'rfb_luggage_capacity', max(0, absint($_POST['rfb_luggage_capacity'] ?? 2)));
				update_post_meta($id, 'rfb_price_adjustment', number_format((float) ($_POST['rfb_price_adjustment'] ?? 0), 2, '.', ''));
				update_post_meta($id, 'rfb_vehicle_brand', sanitize_text_field($_POST['rfb_vehicle_brand'] ?? ''));
				update_post_meta($id, 'rfb_vehicle_make_model', sanitize_text_field($_POST['rfb_vehicle_make_model'] ?? ''));
				update_post_meta($id, 'rfb_vehicle_plate', sanitize_text_field($_POST['rfb_vehicle_plate'] ?? ''));
				update_post_meta($id, 'rfb_vehicle_color', sanitize_text_field($_POST['rfb_vehicle_color'] ?? ''));
			}
			wp_safe_redirect(admin_url('admin.php?page=ridefleet-vehicles&saved=1'));
			exit;
		}

		$editing = null;
		if (!empty($_GET['edit'])) {
			$editing = get_post(absint($_GET['edit']));
			if ($editing && 'rfb_vehicle' !== $editing->post_type) {
				$editing = null;
			}
		}

		$vehicles = get_posts(['post_type' => 'rfb_vehicle', 'post_status' => ['publish', 'draft'], 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC']);
		$counts = wp_count_posts('rfb_vehicle');
		$active = (int) ($counts->publish ?? 0);
		$draft = (int) ($counts->draft ?? 0);
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Fleet', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Vehicles', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Manage your vehicle fleet — capacity, luggage limits, price adjustments, and fleet profiles.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('vehicles'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Active vehicles', 'ridefleet-booking'), number_format_i18n($active), __('Published and available for quotes.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Draft', 'ridefleet-booking'), number_format_i18n($draft), __('Saved but not yet live.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Total', 'ridefleet-booking'), number_format_i18n($active + $draft), __('All records including drafts.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Tip', 'ridefleet-booking'), '—', __('Set passenger capacity and allowed extras per vehicle.', 'ridefleet-booking')); ?>
			</div>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Vehicle saved.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<?php if (!empty($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Vehicle deleted.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<div class="rfb-settings-grid">
				<section class="rfb-panel">
					<h2><?php echo esc_html($editing ? __('Edit Vehicle', 'ridefleet-booking') : __('Add Vehicle', 'ridefleet-booking')); ?></h2>
					<form method="post">
						<?php wp_nonce_field('rfb_save_vehicle_page', 'rfb_vehicle_nonce'); ?>
						<input type="hidden" name="vehicle_id" value="<?php echo esc_attr((string) ($editing->ID ?? 0)); ?>">
						<label><span><?php esc_html_e('Vehicle Name', 'ridefleet-booking'); ?></span><input name="post_title" type="text" value="<?php echo esc_attr($editing->post_title ?? ''); ?>" required placeholder="<?php esc_attr_e('e.g. Business Sedan', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Brand', 'ridefleet-booking'); ?></span>
							<select name="rfb_vehicle_brand">
								<option value=""><?php esc_html_e('Select brand...', 'ridefleet-booking'); ?></option>
								<?php foreach (self::$brands as $brand) : ?>
									<option value="<?php echo esc_attr($brand); ?>" <?php selected((string) get_post_meta($editing->ID ?? 0, 'rfb_vehicle_brand', true), $brand); ?>><?php echo esc_html($brand); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label><span><?php esc_html_e('Make / Model', 'ridefleet-booking'); ?></span><input name="rfb_vehicle_make_model" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_vehicle_make_model', true) : ''); ?>" placeholder="<?php esc_attr_e('e.g. E-Class 2023', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('License Plate', 'ridefleet-booking'); ?></span><input name="rfb_vehicle_plate" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_vehicle_plate', true) : ''); ?>"></label>
						<label><span><?php esc_html_e('Color', 'ridefleet-booking'); ?></span><input name="rfb_vehicle_color" type="text" value="<?php echo esc_attr($editing ? (string) get_post_meta($editing->ID, 'rfb_vehicle_color', true) : ''); ?>" placeholder="<?php esc_attr_e('e.g. Black', 'ridefleet-booking'); ?>"></label>
						<label><span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span><input name="rfb_passenger_capacity" type="number" min="1" value="<?php echo esc_attr($editing ? (string) (get_post_meta($editing->ID, 'rfb_passenger_capacity', true) ?: 4) : '4'); ?>"></label>
						<label><span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span><input name="rfb_luggage_capacity" type="number" min="0" value="<?php echo esc_attr($editing ? (string) (get_post_meta($editing->ID, 'rfb_luggage_capacity', true) ?: 2) : '2'); ?>"></label>
						<label><span><?php esc_html_e('Price Adjustment', 'ridefleet-booking'); ?></span><input name="rfb_price_adjustment" type="number" step="0.01" value="<?php echo esc_attr($editing ? (string) (get_post_meta($editing->ID, 'rfb_price_adjustment', true) ?: '0.00') : '0.00'); ?>"></label>
						<label class="rfb-checkbox"><input name="is_active" type="checkbox" value="1" <?php checked(($editing ? $editing->post_status : 'publish'), 'publish'); ?>><span><?php esc_html_e('Active (published)', 'ridefleet-booking'); ?></span></label>
						<?php submit_button($editing ? __('Update Vehicle', 'ridefleet-booking') : __('Add Vehicle', 'ridefleet-booking')); ?>
						<?php if ($editing) : ?>
							<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-vehicles')); ?>"><?php esc_html_e('Cancel', 'ridefleet-booking'); ?></a>
						<?php endif; ?>
					</form>
				</section>
				<section class="rfb-panel">
					<h2><?php esc_html_e('Fleet', 'ridefleet-booking'); ?></h2>
					<table class="widefat striped">
						<thead><tr>
							<th><?php esc_html_e('Vehicle', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Plate', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Capacity', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Status', 'ridefleet-booking'); ?></th>
							<th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th>
						</tr></thead>
						<tbody>
						<?php if (!$vehicles) : ?>
							<tr><td colspan="5"><?php esc_html_e('No vehicles yet. Add your first vehicle using the form.', 'ridefleet-booking'); ?></td></tr>
						<?php endif; ?>
						<?php foreach ($vehicles as $v) :
							$brand = (string) get_post_meta($v->ID, 'rfb_vehicle_brand', true);
							$model = (string) get_post_meta($v->ID, 'rfb_vehicle_make_model', true);
							$plate = (string) get_post_meta($v->ID, 'rfb_vehicle_plate', true);
							$pax = (string) get_post_meta($v->ID, 'rfb_passenger_capacity', true);
							$bags = (string) get_post_meta($v->ID, 'rfb_luggage_capacity', true);
							$label = implode(' ', array_filter([$brand, $model]));
						?>
							<tr>
								<td><strong><?php echo esc_html($v->post_title); ?></strong><?php if ($label) : ?><br><span style="color:var(--rfb-muted);font-size:12px"><?php echo esc_html($label); ?></span><?php endif; ?></td>
								<td><?php echo esc_html($plate ?: '—'); ?></td>
								<td><?php echo esc_html($pax . ' pax / ' . $bags . ' bags'); ?></td>
								<td><span class="rfb-badge <?php echo 'publish' === $v->post_status ? 'rfb-badge-confirmed' : 'rfb-badge-pending'; ?>"><?php echo esc_html('publish' === $v->post_status ? __('Active', 'ridefleet-booking') : __('Draft', 'ridefleet-booking')); ?></span></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-vehicles&edit=' . $v->ID)); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
									<a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-vehicles&delete=' . $v->ID), 'rfb_delete_vehicle')); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this vehicle?', 'ridefleet-booking')); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
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
