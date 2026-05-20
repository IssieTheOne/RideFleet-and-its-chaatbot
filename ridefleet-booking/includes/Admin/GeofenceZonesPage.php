<?php
/**
 * Geofence zones management for service areas.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class GeofenceZonesPage {
	public static function render(): void {
		global $wpdb;
		self::ensure_boundary_columns();

		$action = sanitize_text_field($_GET['action'] ?? '');
		$zone_id = absint($_GET['zone_id'] ?? 0);

		if (!empty($_POST['rfb_geofence_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rfb_geofence_nonce'])), 'rfb_geofence')) {
			self::handle_zone_save();
		}

		if (!empty($_GET['delete']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'rfb_delete_zone')) {
			self::handle_zone_delete(absint($_GET['delete']));
		}

		$zones = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rfb_geofence_zones ORDER BY name ASC");
		$total_zones = count($zones);
		$soft_zones = count(array_filter($zones, fn($z) => ((string) ($z->enforcement_type ?? 'soft_approval')) !== 'hard_block'));
		$hard_zones = $total_zones - $soft_zones;
		$polygon_zones = count(array_filter($zones, fn($z) => ((string) ($z->boundary_type ?? 'radius')) === 'polygon'));

		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Operations', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Service Areas & Geofences', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Define where you operate with radius or polygon boundaries. Control whether out-of-area requests are soft-blocked or hard-rejected.', 'ridefleet-booking'); ?></p>
				</div>
				<?php if ($action !== 'edit') : ?>
					<div class="rfb-hero-actions">
						<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-geofences&action=add')); ?>"><?php esc_html_e('Add Zone', 'ridefleet-booking'); ?></a>
					</div>
				<?php endif; ?>
			</div>
			<?php OperationalPage::tabs('areas'); ?>
			<?php if ($action !== 'edit' && $action !== 'add') : ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Zones', 'ridefleet-booking'), number_format_i18n($total_zones), __('Service areas defined.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Soft approval', 'ridefleet-booking'), number_format_i18n($soft_zones), __('Out-of-area requests queued for approval.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Hard block', 'ridefleet-booking'), number_format_i18n($hard_zones), __('Out-of-area requests strictly rejected.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Polygon zones', 'ridefleet-booking'), number_format_i18n($polygon_zones), __('Custom drawn boundaries.', 'ridefleet-booking')); ?>
			</div>
			<?php endif; ?>

			<?php if ($action === 'edit' || $action === 'add') : ?>
				<?php self::render_edit_form($zone_id); ?>
			<?php else : ?>
				<div class="rfb-panel">
					<h2><?php esc_html_e('How service areas work', 'ridefleet-booking'); ?></h2>
					<p><?php esc_html_e('Use zones to define where the taxi company operates. Choose a simple radius, or draw a polygon when the service boundary follows real streets, towns, or custom coverage lines.', 'ridefleet-booking'); ?></p>
				</div>
				<?php self::render_zones_list($zones); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_edit_form(int $zone_id): void {
		global $wpdb;

		$zone = null;
		$name = '';
		$description = '';
		$center_lat = '44.4759';
		$center_lng = '-73.2121';
		$radius_km = '15';
		$boundary_type = 'radius';
		$boundary_data = '';
		$pricing_multiplier = '1.0';
		$enforcement_type = 'soft_approval';

		if ($zone_id) {
			$zone = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_geofence_zones WHERE id = %d", $zone_id));
			if ($zone) {
				$name = $zone->name;
				$description = $zone->description;
				$center_lat = $zone->center_lat;
				$center_lng = $zone->center_lng;
				$radius_km = $zone->radius_km;
				$boundary_type = (string) ($zone->boundary_type ?? 'radius');
				$boundary_data = (string) ($zone->boundary_data ?? '');
				$pricing_multiplier = $zone->pricing_multiplier;
				$enforcement_type = (string) ($zone->enforcement_type ?: 'soft_approval');
			}
		}

		wp_nonce_field('rfb_geofence', 'rfb_geofence_nonce');
		?>
		<form method="post" class="rfb-form">
			<?php wp_nonce_field('rfb_geofence', 'rfb_geofence_nonce'); ?>
			<input type="hidden" name="zone_id" value="<?php echo esc_attr($zone_id); ?>">

			<div class="rfb-panel">
				<label>
					<span><?php esc_html_e('Zone Name', 'ridefleet-booking'); ?></span>
					<input type="text" name="name" value="<?php echo esc_attr($name); ?>" required>
				</label>
				<label>
					<span><?php esc_html_e('Description', 'ridefleet-booking'); ?></span>
					<textarea name="description" rows="3"><?php echo esc_textarea($description); ?></textarea>
				</label>
			</div>

			<div class="rfb-panel rfb-service-area-editor">
				<h2><?php esc_html_e('Location', 'ridefleet-booking'); ?></h2>
				<label>
					<span><?php esc_html_e('Search place or service city', 'ridefleet-booking'); ?></span>
					<input type="text" data-rfb-place-search data-rfb-target-lat="center_lat" data-rfb-target-lng="center_lng" placeholder="<?php esc_attr_e('Airport, hotel, station, service city...', 'ridefleet-booking'); ?>">
					<small><?php esc_html_e('Pick a real place and RideFleet will fill the center coordinates for you.', 'ridefleet-booking'); ?></small>
				</label>
				<label>
					<span><?php esc_html_e('Boundary Shape', 'ridefleet-booking'); ?></span>
					<select name="boundary_type">
						<option value="radius" <?php selected($boundary_type, 'radius'); ?>><?php esc_html_e('Radius around center', 'ridefleet-booking'); ?></option>
						<option value="polygon" <?php selected($boundary_type, 'polygon'); ?>><?php esc_html_e('Drawn polygon', 'ridefleet-booking'); ?></option>
					</select>
					<small><?php esc_html_e('Use polygon when the service area is not a perfect circle.', 'ridefleet-booking'); ?></small>
				</label>
				<label>
					<span><?php esc_html_e('Center Latitude', 'ridefleet-booking'); ?></span>
					<input type="number" name="center_lat" value="<?php echo esc_attr($center_lat); ?>" step="0.0001" required>
				</label>
				<label>
					<span><?php esc_html_e('Center Longitude', 'ridefleet-booking'); ?></span>
					<input type="number" name="center_lng" value="<?php echo esc_attr($center_lng); ?>" step="0.0001" required>
				</label>
				<label>
					<span><?php esc_html_e('Radius (km)', 'ridefleet-booking'); ?></span>
					<input type="number" name="radius_km" value="<?php echo esc_attr($radius_km); ?>" min="0.1" step="0.1" required>
				</label>
				<div class="rfb-map-preview">
					<iframe loading="lazy" src="<?php echo esc_url(self::map_embed_url((string) $center_lat, (string) $center_lng)); ?>"></iframe>
					<a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::map_link((string) $center_lat, (string) $center_lng)); ?>"><?php esc_html_e('Open center in Google Maps', 'ridefleet-booking'); ?></a>
				</div>
				<div class="rfb-map-drawing" data-rfb-polygon-helper>
					<h3><?php esc_html_e('Polygon Helper', 'ridefleet-booking'); ?></h3>
					<p><?php esc_html_e('Click points on the map to draw the exact service area. When Boundary Shape is set to Drawn polygon, this saved polygon is used for service-area checks.', 'ridefleet-booking'); ?></p>
					<div class="rfb-admin-map" data-rfb-click-map data-lat="<?php echo esc_attr($center_lat); ?>" data-lng="<?php echo esc_attr($center_lng); ?>"></div>
					<textarea rows="5" name="boundary_data" data-rfb-polygon-output placeholder="<?php esc_attr_e('Polygon JSON appears here after clicking map points.', 'ridefleet-booking'); ?>"><?php echo esc_textarea($boundary_data); ?></textarea>
					<button type="button" class="button" data-rfb-clear-polygon><?php esc_html_e('Clear polygon', 'ridefleet-booking'); ?></button>
				</div>
			</div>

			<div class="rfb-panel">
				<h2><?php esc_html_e('Pricing', 'ridefleet-booking'); ?></h2>
				<label>
					<span><?php esc_html_e('Price Multiplier', 'ridefleet-booking'); ?></span>
					<input type="number" name="pricing_multiplier" value="<?php echo esc_attr($pricing_multiplier); ?>" step="0.01" min="0.5" max="3" required>
					<small><?php esc_html_e('1.0 = standard price, 1.5 = 50% markup', 'ridefleet-booking'); ?></small>
				</label>
				<label>
					<span><?php esc_html_e('Boundary Behavior', 'ridefleet-booking'); ?></span>
					<select name="enforcement_type">
						<option value="soft_approval" <?php selected($enforcement_type, 'soft_approval'); ?>><?php esc_html_e('Soft Approval Zone', 'ridefleet-booking'); ?></option>
						<option value="hard_block" <?php selected($enforcement_type, 'hard_block'); ?>><?php esc_html_e('Hard Block Zone', 'ridefleet-booking'); ?></option>
					</select>
					<small><?php esc_html_e('Soft zones allow one-side-out requests as approval-needed. Hard zones strictly block one-side-out requests.', 'ridefleet-booking'); ?></small>
				</label>
			</div>

			<?php submit_button($zone_id ? __('Update Zone', 'ridefleet-booking') : __('Create Zone', 'ridefleet-booking')); ?>
		</form>
		<?php
	}

	private static function render_zones_list(array $zones): void {
		if (!$zones) {
			echo '<div class="notice notice-info"><p>' . esc_html__('No geofence zones yet. Create one to manage service areas.', 'ridefleet-booking') . '</p></div>';
			return;
		}

		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e('Zone Name', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Description', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Radius', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Shape', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Behavior', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Price Multiplier', 'ridefleet-booking'); ?></th>
					<th><?php esc_html_e('Actions', 'ridefleet-booking'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($zones as $zone) : ?>
					<tr>
						<td><?php echo esc_html($zone->name); ?></td>
						<td><?php echo esc_html(wp_trim_words($zone->description, 10)); ?></td>
						<td><?php echo esc_html($zone->radius_km); ?> km</td>
						<td><?php echo esc_html('polygon' === (string) ($zone->boundary_type ?? 'radius') ? __('Polygon', 'ridefleet-booking') : __('Radius', 'ridefleet-booking')); ?></td>
						<td><?php echo esc_html('hard_block' === (string) $zone->enforcement_type ? __('Hard Block', 'ridefleet-booking') : __('Soft Approval', 'ridefleet-booking')); ?></td>
						<td><?php echo esc_html(number_format($zone->pricing_multiplier, 2)); ?>x</td>
						<td>
							<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-geofences&action=edit&zone_id=' . (int) $zone->id)); ?>"><?php esc_html_e('Edit', 'ridefleet-booking'); ?></a>
							<a target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(self::map_link((string) $zone->center_lat, (string) $zone->center_lng)); ?>"><?php esc_html_e('Map', 'ridefleet-booking'); ?></a>
							<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=ridefleet-geofences&delete=' . (int) $zone->id), 'rfb_delete_zone')); ?>" onclick="return confirm('<?php esc_attr_e('Delete this zone?', 'ridefleet-booking'); ?>');"><?php esc_html_e('Delete', 'ridefleet-booking'); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function handle_zone_save(): void {
		global $wpdb;

		$zone_id = absint($_POST['zone_id'] ?? 0);
		$name = sanitize_text_field($_POST['name'] ?? '');
		$description = sanitize_textarea_field($_POST['description'] ?? '');
		$center_lat = (float) ($_POST['center_lat'] ?? 0);
		$center_lng = (float) ($_POST['center_lng'] ?? 0);
		$radius_km = max(0.1, (float) ($_POST['radius_km'] ?? 1));
		$boundary_type = in_array(sanitize_key((string) ($_POST['boundary_type'] ?? 'radius')), ['radius', 'polygon'], true) ? sanitize_key((string) ($_POST['boundary_type'] ?? 'radius')) : 'radius';
		$boundary_data = sanitize_textarea_field(wp_unslash($_POST['boundary_data'] ?? ''));
		$pricing_multiplier = max(0.5, min(3, (float) ($_POST['pricing_multiplier'] ?? 1)));
		$enforcement_type = in_array(sanitize_key((string) ($_POST['enforcement_type'] ?? 'soft_approval')), ['soft_approval', 'hard_block'], true) ? sanitize_key((string) ($_POST['enforcement_type'] ?? 'soft_approval')) : 'soft_approval';

		if (!$name) {
			wp_die(esc_html__('Zone name is required.', 'ridefleet-booking'));
		}

		$table = $wpdb->prefix . 'rfb_geofence_zones';

		if ($zone_id) {
			$wpdb->update(
				$table,
				['name' => $name, 'description' => $description, 'center_lat' => $center_lat, 'center_lng' => $center_lng, 'radius_km' => $radius_km, 'boundary_type' => $boundary_type, 'boundary_data' => $boundary_data, 'pricing_multiplier' => $pricing_multiplier, 'enforcement_type' => $enforcement_type, 'updated_at' => current_time('mysql')],
				['id' => $zone_id],
				['%s', '%s', '%f', '%f', '%f', '%s', '%s', '%f', '%s', '%s'],
				['%d']
			);
		} else {
			$wpdb->insert(
				$table,
				['name' => $name, 'description' => $description, 'center_lat' => $center_lat, 'center_lng' => $center_lng, 'radius_km' => $radius_km, 'boundary_type' => $boundary_type, 'boundary_data' => $boundary_data, 'pricing_multiplier' => $pricing_multiplier, 'enforcement_type' => $enforcement_type, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')],
				['%s', '%s', '%f', '%f', '%f', '%s', '%s', '%f', '%s', '%s', '%s']
			);
		}

		wp_redirect(admin_url('admin.php?page=ridefleet-geofences'));
		exit;
	}

	private static function handle_zone_delete(int $zone_id): void {
		global $wpdb;
		$wpdb->delete($wpdb->prefix . 'rfb_geofence_zones', ['id' => $zone_id], ['%d']);
		wp_redirect(admin_url('admin.php?page=ridefleet-geofences'));
		exit;
	}

	private static function ensure_boundary_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'rfb_geofence_zones';
		$boundary_type = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'boundary_type'));
		if (!$boundary_type) {
			$wpdb->query("ALTER TABLE {$table} ADD boundary_type varchar(30) NOT NULL DEFAULT 'radius' AFTER radius_km");
		}
		$boundary_data = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'boundary_data'));
		if (!$boundary_data) {
			$wpdb->query("ALTER TABLE {$table} ADD boundary_data longtext NULL AFTER boundary_type");
		}
	}

	private static function map_link(string $lat, string $lng): string {
		return add_query_arg(['api' => '1', 'query' => $lat . ',' . $lng], 'https://www.google.com/maps/search/');
	}

	private static function map_embed_url(string $lat, string $lng): string {
		$key = Options::get('google_maps_api_key', '');
		if ($key) {
			return add_query_arg(['key' => $key, 'q' => $lat . ',' . $lng, 'zoom' => 10], 'https://www.google.com/maps/embed/v1/place');
		}

		return add_query_arg(['q' => $lat . ',' . $lng, 'output' => 'embed'], 'https://maps.google.com/maps');
	}
}
