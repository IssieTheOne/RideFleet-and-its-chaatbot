<?php
/**
 * Core booking and geofencing rules admin page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Booking\CoreBookingPricingEngine;
use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class CoreRulesPage {
	public static function save(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to edit RideFleet core rules.', 'ridefleet-booking'));
		}

		check_admin_referer('rfb_save_core_rules');

		$settings = [
			'global_service_area' => [
				'enabled' => !empty($_POST['global_service_area_enabled']),
				'type' => self::choice($_POST['global_service_area_type'] ?? 'radius', ['radius', 'states', 'polygon'], 'radius'),
				'data' => sanitize_textarea_field(wp_unslash($_POST['global_service_area_data'] ?? '')),
				'error_message' => sanitize_textarea_field(wp_unslash($_POST['global_service_area_error_message'] ?? '')),
			],
			'flat_rates' => self::flat_rates_from_post(),
			'priority_rule' => self::choice($_POST['priority_rule'] ?? 'flat_rate_only', ['flat_rate_only', 'cheapest', 'expensive'], 'flat_rate_only'),
			'abuse_protection_enabled' => !empty($_POST['abuse_protection_enabled']),
			'abuse_protection_type' => self::choice($_POST['abuse_protection_type'] ?? 'force_flat_rate', ['force_flat_rate', 'distance_plus_penalty'], 'force_flat_rate'),
			'buffer_zone_enabled' => !empty($_POST['buffer_zone_enabled']),
			'buffer_zone_distance' => max(0, (float) ($_POST['buffer_zone_distance'] ?? 0)),
			'buffer_zone_fee_type' => self::choice($_POST['buffer_zone_fee_type'] ?? 'flat_plus_fee', ['flat_plus_meter', 'flat_plus_fee'], 'flat_plus_fee'),
			'buffer_zone_penalty_value' => max(0, (float) ($_POST['buffer_zone_penalty_value'] ?? 0)),
			'chatbot_api_key' => sanitize_text_field(wp_unslash($_POST['chatbot_api_key'] ?? '')),
		];

		Options::update(['core_booking_rules' => $settings]);
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-core-rules&updated=1'));
		exit;
	}

	public static function render(): void {
		$settings = CoreBookingPricingEngine::settings();
		$service_area = $settings['global_service_area'];
		$flat_rates = is_array($settings['flat_rates']) ? $settings['flat_rates'] : [];
		?>
		<div class="wrap rfb-admin rfb-core-rules">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Operational', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Core Booking & Geofencing Rules', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Configure the global service area, flat rate zones, buffer zone penalties, and chatbot API access.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<?php OperationalPage::tabs('core'); ?>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Flat rate zones', 'ridefleet-booking'), number_format_i18n(count($flat_rates)), __('Fixed fares for specific routes.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Service area', 'ridefleet-booking'), !empty($service_area['enabled']) ? __('Enabled', 'ridefleet-booking') : __('Disabled', 'ridefleet-booking'), __('Strict pickup/drop-off boundary validation.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Priority rule', 'ridefleet-booking'), ucfirst(str_replace('_', ' ', $settings['priority_rule'])), __('How overlapping zones resolve.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Buffer zone', 'ridefleet-booking'), !empty($settings['buffer_zone_enabled']) ? __('Enabled', 'ridefleet-booking') : __('Disabled', 'ridefleet-booking'), __('Edge-of-zone penalty pricing.', 'ridefleet-booking')); ?>
			</div>
			<?php if (!empty($_GET['updated'])) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e('Core booking rules saved.', 'ridefleet-booking'); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rfb-core-rules-grid">
				<input type="hidden" name="action" value="rfb_save_core_rules">
				<?php wp_nonce_field('rfb_save_core_rules'); ?>

				<section class="rfb-card rfb-card-wide">
					<p class="rfb-eyebrow"><?php esc_html_e('Step 1', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Global Service Area', 'ridefleet-booking'); ?></h2>
					<label class="rfb-toggle-row">
						<input type="checkbox" name="global_service_area_enabled" value="1" <?php checked(!empty($service_area['enabled'])); ?>>
						<span><?php esc_html_e('Enable strict pickup and drop-off boundary validation', 'ridefleet-booking'); ?></span>
					</label>
					<div class="rfb-field-grid">
						<label>
							<span><?php esc_html_e('Boundary Format', 'ridefleet-booking'); ?></span>
							<select name="global_service_area_type">
								<option value="radius" <?php selected($service_area['type'], 'radius'); ?>><?php esc_html_e('Radius from Center Point', 'ridefleet-booking'); ?></option>
								<option value="states" <?php selected($service_area['type'], 'states'); ?>><?php esc_html_e('Specific State/Country Names', 'ridefleet-booking'); ?></option>
								<option value="polygon" <?php selected($service_area['type'], 'polygon'); ?>><?php esc_html_e('Custom Polygon GPS Boundaries', 'ridefleet-booking'); ?></option>
							</select>
						</label>
						<label>
							<span><?php esc_html_e('Localized Error Message', 'ridefleet-booking'); ?></span>
							<input type="text" name="global_service_area_error_message" value="<?php echo esc_attr((string) $service_area['error_message']); ?>">
						</label>
					</div>
					<label>
						<span><?php esc_html_e('Boundary Data', 'ridefleet-booking'); ?></span>
						<textarea name="global_service_area_data" rows="5" placeholder='{"center":{"lat":44.4759,"lng":-73.2121},"radius":25}'><?php echo esc_textarea((string) $service_area['data']); ?></textarea>
					</label>
				</section>

				<section class="rfb-card rfb-card-wide">
					<p class="rfb-eyebrow"><?php esc_html_e('Steps 2-4', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Flat Rate Zone Management', 'ridefleet-booking'); ?></h2>
					<p><?php esc_html_e('Create fixed fares for common trips. Search the pickup hub and the destination area, set the boundary, then enter the flat fare.', 'ridefleet-booking'); ?></p>
					<div class="rfb-flat-rate-actions">
						<button type="button" class="button" data-rfb-add-flat-rate><?php esc_html_e('Add Zone', 'ridefleet-booking'); ?></button>
						<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-route-pages')); ?>"><?php esc_html_e('Manage Route Pages', 'ridefleet-booking'); ?></a>
					</div>
					<div class="rfb-flat-rate-table" data-rfb-flat-rate-table data-next-index="<?php echo esc_attr((string) max(1, count($flat_rates) + 1)); ?>">
						<div class="rfb-flat-rate-head">
							<span><?php esc_html_e('Name', 'ridefleet-booking'); ?></span>
							<span><?php esc_html_e('Hub Search', 'ridefleet-booking'); ?></span>
							<span><?php esc_html_e('Zone Type', 'ridefleet-booking'); ?></span>
							<span><?php esc_html_e('Destination Zone', 'ridefleet-booking'); ?></span>
							<span><?php esc_html_e('Price', 'ridefleet-booking'); ?></span>
						</div>
						<?php for ($i = 0; $i < max(1, count($flat_rates) + 1); $i++) : $rate = $flat_rates[$i] ?? []; ?>
							<?php self::flat_rate_row($i, $rate); ?>
						<?php endfor; ?>
					</div>
					<template data-rfb-flat-rate-template>
						<?php self::flat_rate_row('__INDEX__', []); ?>
					</template>
				</section>

				<section class="rfb-card">
					<p class="rfb-eyebrow"><?php esc_html_e('Step 3', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Priority Resolution', 'ridefleet-booking'); ?></h2>
					<label>
						<span><?php esc_html_e('When a flat zone matches', 'ridefleet-booking'); ?></span>
						<select name="priority_rule">
							<option value="flat_rate_only" <?php selected($settings['priority_rule'], 'flat_rate_only'); ?>><?php esc_html_e('Flat Rate Only', 'ridefleet-booking'); ?></option>
							<option value="cheapest" <?php selected($settings['priority_rule'], 'cheapest'); ?>><?php esc_html_e('Return Cheapest', 'ridefleet-booking'); ?></option>
							<option value="expensive" <?php selected($settings['priority_rule'], 'expensive'); ?>><?php esc_html_e('Return Most Expensive', 'ridefleet-booking'); ?></option>
						</select>
					</label>
				</section>

				<section class="rfb-card">
					<p class="rfb-eyebrow"><?php esc_html_e('Steps 4 & 6', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Buffer and Abuse Protection', 'ridefleet-booking'); ?></h2>
					<label class="rfb-toggle-row"><input type="checkbox" name="buffer_zone_enabled" value="1" <?php checked(!empty($settings['buffer_zone_enabled'])); ?>><span><?php esc_html_e('Enable buffer zone pricing', 'ridefleet-booking'); ?></span></label>
					<label>
						<span><?php esc_html_e('Buffer Distance in Meters', 'ridefleet-booking'); ?></span>
						<input type="number" step="0.01" min="0" name="buffer_zone_distance" value="<?php echo esc_attr((string) $settings['buffer_zone_distance']); ?>">
					</label>
					<label>
						<span><?php esc_html_e('Buffer Fee Type', 'ridefleet-booking'); ?></span>
						<select name="buffer_zone_fee_type">
							<option value="flat_plus_fee" <?php selected($settings['buffer_zone_fee_type'], 'flat_plus_fee'); ?>><?php esc_html_e('Flat Rate + Fixed Fee', 'ridefleet-booking'); ?></option>
							<option value="flat_plus_meter" <?php selected($settings['buffer_zone_fee_type'], 'flat_plus_meter'); ?>><?php esc_html_e('Flat Rate + Per-KM Modifier', 'ridefleet-booking'); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e('Penalty Value', 'ridefleet-booking'); ?></span>
						<input type="number" step="0.01" min="0" name="buffer_zone_penalty_value" value="<?php echo esc_attr((string) $settings['buffer_zone_penalty_value']); ?>">
					</label>
					<label class="rfb-toggle-row"><input type="checkbox" name="abuse_protection_enabled" value="1" <?php checked(!empty($settings['abuse_protection_enabled'])); ?>><span><?php esc_html_e('Prevent edge-of-zone underpricing', 'ridefleet-booking'); ?></span></label>
					<label>
						<span><?php esc_html_e('Abuse Protection Action', 'ridefleet-booking'); ?></span>
						<select name="abuse_protection_type">
							<option value="force_flat_rate" <?php selected($settings['abuse_protection_type'], 'force_flat_rate'); ?>><?php esc_html_e('Force Adjacent Flat Rate', 'ridefleet-booking'); ?></option>
							<option value="distance_plus_penalty" <?php selected($settings['abuse_protection_type'], 'distance_plus_penalty'); ?>><?php esc_html_e('Distance + Penalty', 'ridefleet-booking'); ?></option>
						</select>
					</label>
				</section>

				<section class="rfb-card rfb-card-wide">
					<p class="rfb-eyebrow"><?php esc_html_e('Chatbot API Contract', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Read-Only Pricing, Write-Only Reservations', 'ridefleet-booking'); ?></h2>
					<label>
						<span><?php esc_html_e('Shared Chatbot API Key', 'ridefleet-booking'); ?></span>
						<input type="password" name="chatbot_api_key" value="<?php echo esc_attr((string) ($settings['chatbot_api_key'] ?? '')); ?>" autocomplete="off" placeholder="<?php esc_attr_e('Optional but recommended', 'ridefleet-booking'); ?>">
					</label>
					<div class="rfb-endpoint-list">
						<code>GET /wp-json/taxi-booking/v1/calculate-price</code>
						<code>POST /wp-json/taxi-booking/v1/create-booking</code>
					</div>
				</section>

				<p class="submit rfb-card-wide">
					<button type="submit" class="button button-primary"><?php esc_html_e('Save Core Rules', 'ridefleet-booking'); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	private static function flat_rate_row(int|string $i, array $rate): void {
		$index = (string) $i;
		$hub_lat = (string) ($rate['hub_coordinates']['lat'] ?? '');
		$hub_lng = (string) ($rate['hub_coordinates']['lng'] ?? '');
		$zone_data = is_scalar($rate['zone_data'] ?? '') ? (string) ($rate['zone_data'] ?? '') : wp_json_encode($rate['zone_data'] ?? []);
		?>
		<div class="rfb-flat-rate-row">
			<input type="hidden" name="flat_rates[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr((string) ($rate['id'] ?? (is_numeric($i) ? ((int) $i + 1) : ''))); ?>">
			<label>
				<span><?php esc_html_e('Route name', 'ridefleet-booking'); ?></span>
				<input type="text" name="flat_rates[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr((string) ($rate['name'] ?? '')); ?>" placeholder="<?php esc_attr_e('Airport to city center', 'ridefleet-booking'); ?>">
			</label>
			<div class="rfb-mini-grid rfb-hub-tools">
				<label>
					<span><?php esc_html_e('Hub place', 'ridefleet-booking'); ?></span>
					<input type="text" data-rfb-place-search data-rfb-target-lat="flat_rates[<?php echo esc_attr($index); ?>][hub_lat]" data-rfb-target-lng="flat_rates[<?php echo esc_attr($index); ?>][hub_lng]" placeholder="<?php esc_attr_e('Search airport, station, hotel...', 'ridefleet-booking'); ?>">
				</label>
				<input type="number" step="0.000001" name="flat_rates[<?php echo esc_attr($index); ?>][hub_lat]" value="<?php echo esc_attr($hub_lat); ?>" placeholder="<?php esc_attr_e('Latitude', 'ridefleet-booking'); ?>">
				<input type="number" step="0.000001" name="flat_rates[<?php echo esc_attr($index); ?>][hub_lng]" value="<?php echo esc_attr($hub_lng); ?>" placeholder="<?php esc_attr_e('Longitude', 'ridefleet-booking'); ?>">
			</div>
			<label>
				<span><?php esc_html_e('Zone type', 'ridefleet-booking'); ?></span>
				<select name="flat_rates[<?php echo esc_attr($index); ?>][zone_type]">
					<option value="radius" <?php selected($rate['zone_type'] ?? 'radius', 'radius'); ?>><?php esc_html_e('Radius', 'ridefleet-booking'); ?></option>
					<option value="zipcodes" <?php selected($rate['zone_type'] ?? '', 'zipcodes'); ?>><?php esc_html_e('Zip Codes', 'ridefleet-booking'); ?></option>
					<option value="polygon" <?php selected($rate['zone_type'] ?? '', 'polygon'); ?>><?php esc_html_e('Polygon', 'ridefleet-booking'); ?></option>
				</select>
			</label>
			<div class="rfb-zone-tools">
				<input type="text" data-rfb-place-search data-rfb-target-zone="flat_rates[<?php echo esc_attr($index); ?>][zone_data]" data-rfb-default-radius="5" placeholder="<?php esc_attr_e('Search destination zone center', 'ridefleet-booking'); ?>">
				<textarea rows="3" name="flat_rates[<?php echo esc_attr($index); ?>][zone_data]" placeholder='{"center":{"lat":40.7128,"lng":-74.0060},"radius":5}'><?php echo esc_textarea($zone_data); ?></textarea>
			</div>
			<label>
				<span><?php esc_html_e('Flat price', 'ridefleet-booking'); ?></span>
				<input type="number" step="0.01" min="0" name="flat_rates[<?php echo esc_attr($index); ?>][price]" value="<?php echo esc_attr((string) ($rate['price'] ?? '')); ?>" placeholder="45.00">
			</label>
			<button type="button" class="button button-link-delete" data-rfb-remove-flat-rate><?php esc_html_e('Remove', 'ridefleet-booking'); ?></button>
		</div>
		<?php
	}

	private static function flat_rates_from_post(): array {
		$rows = $_POST['flat_rates'] ?? [];
		if (!is_array($rows)) {
			return [];
		}

		$flat_rates = [];
		foreach ($rows as $row) {
			if (!is_array($row) || empty($row['name'])) {
				continue;
			}

			$flat_rates[] = [
				'id' => absint($row['id'] ?? 0),
				'name' => sanitize_text_field(wp_unslash($row['name'])),
				'hub_coordinates' => [
					'lat' => (float) ($row['hub_lat'] ?? 0),
					'lng' => (float) ($row['hub_lng'] ?? 0),
				],
				'zone_type' => self::choice($row['zone_type'] ?? 'radius', ['radius', 'zipcodes', 'polygon'], 'radius'),
				'zone_data' => sanitize_textarea_field(wp_unslash($row['zone_data'] ?? '')),
				'price' => max(0, (float) ($row['price'] ?? 0)),
			];
		}

		return $flat_rates;
	}

	private static function choice(mixed $value, array $allowed, string $fallback): string {
		$value = sanitize_key((string) $value);
		return in_array($value, $allowed, true) ? $value : $fallback;
	}
}
