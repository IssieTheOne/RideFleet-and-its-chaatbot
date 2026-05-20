<?php
/**
 * Simple edit-screen fields for Phase 2 content types.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class MetaBoxes {
	public static function register(): void {
		add_meta_box('rfb_vehicle_details', __('Vehicle Profile', 'ridefleet-booking'), [self::class, 'vehicle_box'], 'rfb_vehicle', 'normal', 'high');
		add_meta_box('rfb_driver_details', __('Driver Profile', 'ridefleet-booking'), [self::class, 'driver_box'], 'rfb_driver', 'normal', 'high');
		add_meta_box('rfb_extra_details', __('Extra Pricing', 'ridefleet-booking'), [self::class, 'extra_box'], 'rfb_extra', 'normal', 'high');
		add_meta_box('rfb_route_details', __('Route Pricing & SEO', 'ridefleet-booking'), [self::class, 'route_box'], 'rfb_route', 'normal');
	}

	public static function vehicle_box(\WP_Post $post): void {
		wp_nonce_field('rfb_save_vehicle', 'rfb_vehicle_nonce');
		echo '<div class="rfb-meta-box">';
		self::number('rfb_passenger_capacity', __('Passengers', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_passenger_capacity', true) ?: '4', 1);
		self::number('rfb_luggage_capacity', __('Luggage', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_luggage_capacity', true) ?: '2', 0);
		self::number('rfb_price_adjustment', __('Price Adjustment', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_price_adjustment', true) ?: '0.00', 0, '0.01');
		self::select('rfb_vehicle_brand', __('Brand', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_vehicle_brand', true), ['Mercedes-Benz', 'BMW', 'Audi', 'Tesla', 'Cadillac', 'Lincoln', 'Chevrolet', 'Ford', 'Toyota', 'Volvo', 'Other']);
		self::text('rfb_vehicle_make_model', __('Model', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_vehicle_make_model', true));
		self::text('rfb_vehicle_plate', __('License Plate', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_vehicle_plate', true));
		self::text('rfb_vehicle_color', __('Color', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_vehicle_color', true));
		echo '</div>';
	}

	public static function driver_box(\WP_Post $post): void {
		wp_nonce_field('rfb_save_driver', 'rfb_driver_nonce');
		echo '<div class="rfb-meta-box rfb-meta-box-wide">';
		self::text('rfb_driver_phone', __('Phone', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_driver_phone', true));
		self::text('rfb_driver_email', __('Email', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_driver_email', true));
		self::text('rfb_driver_license', __('License Number', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_driver_license', true));
		self::text('rfb_driver_home_base', __('Home Base', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_driver_home_base', true));
		self::textarea('rfb_driver_notes', __('Dispatch Notes', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_driver_notes', true));
		echo '</div>';
	}

	public static function extra_box(\WP_Post $post): void {
		wp_nonce_field('rfb_save_extra', 'rfb_extra_nonce');
		echo '<div class="rfb-meta-box">';
		self::number('rfb_price', __('Price', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_price', true) ?: '0.00', 0, '0.01');
		self::number('rfb_max_quantity', __('Max Quantity', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_max_quantity', true) ?: '1', 1);
		self::text('rfb_extra_short_label', __('Short Label', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_extra_short_label', true));
		echo '</div>';
	}

	public static function route_box(\WP_Post $post): void {
		wp_nonce_field('rfb_save_route', 'rfb_route_nonce');
		$origin = (string) get_post_meta($post->ID, 'rfb_origin', true);
		$destination = (string) get_post_meta($post->ID, 'rfb_destination', true);
		$origin_lat = (string) get_post_meta($post->ID, 'rfb_origin_lat', true);
		$origin_lng = (string) get_post_meta($post->ID, 'rfb_origin_lng', true);
		$destination_lat = (string) get_post_meta($post->ID, 'rfb_destination_lat', true);
		$destination_lng = (string) get_post_meta($post->ID, 'rfb_destination_lng', true);
		echo '<div class="rfb-route-builder" data-rfb-route-builder>';
		echo '<div class="rfb-meta-box rfb-meta-box-wide">';
		self::place('rfb_origin', __('Origin', 'ridefleet-booking'), $origin, 'rfb_origin_lat', 'rfb_origin_lng', __('Search pickup hub, airport, station, city, or exact address', 'ridefleet-booking'));
		self::place('rfb_destination', __('Destination', 'ridefleet-booking'), $destination, 'rfb_destination_lat', 'rfb_destination_lng', __('Search drop-off hub, city, hotel, station, or exact address', 'ridefleet-booking'));
		self::hidden('rfb_origin_lat', $origin_lat);
		self::hidden('rfb_origin_lng', $origin_lng);
		self::hidden('rfb_destination_lat', $destination_lat);
		self::hidden('rfb_destination_lng', $destination_lng);
		self::number('rfb_fixed_price', __('Fixed Route Price', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_fixed_price', true) ?: '0.00', 0, '0.01');
		self::number('rfb_match_radius', __('Match Radius (km)', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_match_radius', true) ?: '15', 1, '0.5');
		self::number('rfb_sample_distance', __('Sample Distance', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_sample_distance', true) ?: '0.00', 0, '0.01');
		self::number('rfb_sample_duration', __('Sample Duration Minutes', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_sample_duration', true) ?: '0', 0, '1');
		self::textarea('rfb_faq', __('FAQ Notes', 'ridefleet-booking'), get_post_meta($post->ID, 'rfb_faq', true));
		echo '</div>';
		echo '<div class="rfb-route-preview"><div class="rfb-admin-map" data-rfb-route-map></div><p>' . esc_html__('Choose origin and destination with Google Places to preview the route. Save the route after the map looks right.', 'ridefleet-booking') . '</p></div>';
		echo '</div>';
	}

	public static function save_vehicle(int $post_id): void {
		if (!self::can_save($post_id, 'rfb_vehicle_nonce', 'rfb_save_vehicle')) {
			return;
		}

		update_post_meta($post_id, 'rfb_passenger_capacity', max(1, absint($_POST['rfb_passenger_capacity'] ?? 4)));
		update_post_meta($post_id, 'rfb_luggage_capacity', max(0, absint($_POST['rfb_luggage_capacity'] ?? 2)));
		update_post_meta($post_id, 'rfb_price_adjustment', number_format((float) ($_POST['rfb_price_adjustment'] ?? 0), 2, '.', ''));
		update_post_meta($post_id, 'rfb_vehicle_brand', sanitize_text_field($_POST['rfb_vehicle_brand'] ?? ''));
		update_post_meta($post_id, 'rfb_vehicle_make_model', sanitize_text_field($_POST['rfb_vehicle_make_model'] ?? ''));
		update_post_meta($post_id, 'rfb_vehicle_plate', sanitize_text_field($_POST['rfb_vehicle_plate'] ?? ''));
		update_post_meta($post_id, 'rfb_vehicle_color', sanitize_text_field($_POST['rfb_vehicle_color'] ?? ''));
	}

	public static function save_driver(int $post_id): void {
		if (!self::can_save($post_id, 'rfb_driver_nonce', 'rfb_save_driver')) {
			return;
		}

		update_post_meta($post_id, 'rfb_driver_phone', sanitize_text_field($_POST['rfb_driver_phone'] ?? ''));
		update_post_meta($post_id, 'rfb_driver_email', sanitize_email($_POST['rfb_driver_email'] ?? ''));
		update_post_meta($post_id, 'rfb_driver_license', sanitize_text_field($_POST['rfb_driver_license'] ?? ''));
		update_post_meta($post_id, 'rfb_driver_home_base', sanitize_text_field($_POST['rfb_driver_home_base'] ?? ''));
		update_post_meta($post_id, 'rfb_driver_notes', sanitize_textarea_field($_POST['rfb_driver_notes'] ?? ''));
	}

	public static function save_extra(int $post_id): void {
		if (!self::can_save($post_id, 'rfb_extra_nonce', 'rfb_save_extra')) {
			return;
		}

		update_post_meta($post_id, 'rfb_price', number_format((float) ($_POST['rfb_price'] ?? 0), 2, '.', ''));
		update_post_meta($post_id, 'rfb_max_quantity', max(1, absint($_POST['rfb_max_quantity'] ?? 1)));
		update_post_meta($post_id, 'rfb_extra_short_label', sanitize_text_field($_POST['rfb_extra_short_label'] ?? ''));
	}

	public static function vehicle_columns(array $columns): array {
		$columns['rfb_capacity'] = __('Capacity', 'ridefleet-booking');
		$columns['rfb_plate'] = __('Plate', 'ridefleet-booking');
		$columns['rfb_adjustment'] = __('Price Adj.', 'ridefleet-booking');
		return $columns;
	}

	public static function vehicle_column(string $column, int $post_id): void {
		if ('rfb_capacity' === $column) {
			echo esc_html(get_post_meta($post_id, 'rfb_passenger_capacity', true) . ' pax / ' . get_post_meta($post_id, 'rfb_luggage_capacity', true) . ' bags');
		}
		if ('rfb_plate' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_vehicle_plate', true));
		}
		if ('rfb_adjustment' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_price_adjustment', true));
		}
	}

	public static function driver_columns(array $columns): array {
		$columns['rfb_phone'] = __('Phone', 'ridefleet-booking');
		$columns['rfb_email'] = __('Email', 'ridefleet-booking');
		$columns['rfb_base'] = __('Home Base', 'ridefleet-booking');
		return $columns;
	}

	public static function driver_column(string $column, int $post_id): void {
		if ('rfb_phone' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_driver_phone', true));
		}
		if ('rfb_email' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_driver_email', true));
		}
		if ('rfb_base' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_driver_home_base', true));
		}
	}

	public static function extra_columns(array $columns): array {
		$columns['rfb_price'] = __('Price', 'ridefleet-booking');
		$columns['rfb_quantity'] = __('Max Qty', 'ridefleet-booking');
		return $columns;
	}

	public static function extra_column(string $column, int $post_id): void {
		if ('rfb_price' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_price', true));
		}
		if ('rfb_quantity' === $column) {
			echo esc_html((string) get_post_meta($post_id, 'rfb_max_quantity', true));
		}
	}

	public static function save_route(int $post_id): void {
		if (!self::can_save($post_id, 'rfb_route_nonce', 'rfb_save_route')) {
			return;
		}

		update_post_meta($post_id, 'rfb_origin', sanitize_text_field($_POST['rfb_origin'] ?? ''));
		update_post_meta($post_id, 'rfb_destination', sanitize_text_field($_POST['rfb_destination'] ?? ''));
		update_post_meta($post_id, 'rfb_origin_lat', sanitize_text_field($_POST['rfb_origin_lat'] ?? ''));
		update_post_meta($post_id, 'rfb_origin_lng', sanitize_text_field($_POST['rfb_origin_lng'] ?? ''));
		update_post_meta($post_id, 'rfb_destination_lat', sanitize_text_field($_POST['rfb_destination_lat'] ?? ''));
		update_post_meta($post_id, 'rfb_destination_lng', sanitize_text_field($_POST['rfb_destination_lng'] ?? ''));
		update_post_meta($post_id, 'rfb_fixed_price', number_format((float) ($_POST['rfb_fixed_price'] ?? 0), 2, '.', ''));
		update_post_meta($post_id, 'rfb_match_radius', max(0.5, (float) ($_POST['rfb_match_radius'] ?? 15)));
		update_post_meta($post_id, 'rfb_sample_distance', number_format((float) ($_POST['rfb_sample_distance'] ?? 0), 2, '.', ''));
		update_post_meta($post_id, 'rfb_sample_duration', absint($_POST['rfb_sample_duration'] ?? 0));
		update_post_meta($post_id, 'rfb_faq', sanitize_textarea_field($_POST['rfb_faq'] ?? ''));
	}

	private static function can_save(int $post_id, string $nonce_key, string $action): bool {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return false;
		}

		if (!isset($_POST[$nonce_key]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_key])), $action)) {
			return false;
		}

		return current_user_can('edit_post', $post_id);
	}

	private static function number(string $key, string $label, string $value, int $min, string $step = '1'): void {
		?>
		<p class="rfb-meta-field">
			<label for="<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
			<input class="widefat" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="number" min="<?php echo esc_attr((string) $min); ?>" step="<?php echo esc_attr($step); ?>" value="<?php echo esc_attr($value); ?>">
		</p>
		<?php
	}

	private static function text(string $key, string $label, string $value): void {
		?>
		<p class="rfb-meta-field">
			<label for="<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
			<input class="widefat" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="text" value="<?php echo esc_attr($value); ?>">
		</p>
		<?php
	}

	private static function place(string $key, string $label, string $value, string $lat_key, string $lng_key, string $placeholder): void {
		?>
		<p class="rfb-meta-field">
			<label for="<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
			<input class="widefat" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" type="text" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" data-rfb-place-search data-rfb-target-lat="<?php echo esc_attr($lat_key); ?>" data-rfb-target-lng="<?php echo esc_attr($lng_key); ?>">
		</p>
		<?php
	}

	private static function hidden(string $key, string $value): void {
		?>
		<input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>">
		<?php
	}

	private static function select(string $key, string $label, string $value, array $options): void {
		?>
		<p class="rfb-meta-field">
			<label for="<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
			<select class="widefat" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>">
				<option value=""><?php esc_html_e('Select...', 'ridefleet-booking'); ?></option>
				<?php foreach ($options as $option) : ?>
					<option value="<?php echo esc_attr($option); ?>" <?php selected($value, $option); ?>><?php echo esc_html($option); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	private static function textarea(string $key, string $label, string $value): void {
		?>
		<p class="rfb-meta-field">
			<label for="<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label>
			<textarea class="widefat" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="5"><?php echo esc_textarea($value); ?></textarea>
		</p>
		<?php
	}
}
