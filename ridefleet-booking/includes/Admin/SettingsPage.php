<?php
/**
 * Settings page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class SettingsPage {
	public static function register_settings(): void {
		register_setting(
			'rfb_settings_group',
			'rfb_settings',
			[
				'type' => 'array',
				'sanitize_callback' => [self::class, 'sanitize'],
				'default' => [],
			]
		);
	}

	public static function sanitize(mixed $input): array {
		$input = is_array($input) ? $input : [];
		$existing = get_option('rfb_settings', []);
		$existing = is_array($existing) ? $existing : [];
		$fare = isset($input['fare']) && is_array($input['fare']) ? $input['fare'] : [];
		$dispatcher_api_key = sanitize_text_field($input['dispatcher_api_key'] ?? '');
		if (!empty($input['dispatcher_api_enabled']) && '' === $dispatcher_api_key) {
			$dispatcher_api_key = 'rfb_live_dispatcher_' . wp_generate_password(32, false, false);
			add_settings_error('rfb_settings', 'rfb_dispatcher_key_generated', __('Virtual Dispatcher API key generated. Copy it from the settings panel and keep it private.', 'ridefleet-booking'), 'success');
		}

		$sanitized = [
			'currency' => sanitize_text_field($input['currency'] ?? 'USD'),
			'distance_unit' => in_array(($input['distance_unit'] ?? 'km'), ['km', 'mi'], true) ? $input['distance_unit'] : 'km',
			'google_maps_api_key' => sanitize_text_field($input['google_maps_api_key'] ?? ''),
			'google_maps_map_id' => sanitize_text_field($input['google_maps_map_id'] ?? ''),
			'woocommerce_checkout_enabled' => !empty($input['woocommerce_checkout_enabled']) ? 'yes' : 'no',
			'dispatcher_api_enabled' => !empty($input['dispatcher_api_enabled']) ? 'yes' : 'no',
			'dispatcher_api_key' => $dispatcher_api_key,
			'enable_extras' => !empty($input['enable_extras']) ? 'yes' : 'no',
			'admin_email' => sanitize_email($input['admin_email'] ?? get_option('admin_email')),
			'review_link' => esc_url_raw($input['review_link'] ?? ''),
			'email_templates' => [
				'booking_subject' => sanitize_text_field($input['email_templates']['booking_subject'] ?? 'Your booking request {booking_number}'),
				'booking_body' => sanitize_textarea_field($input['email_templates']['booking_body'] ?? ''),
				'review_subject' => sanitize_text_field($input['email_templates']['review_subject'] ?? 'How was your ride with RideFleet? {booking_number}'),
				'review_body' => sanitize_textarea_field($input['email_templates']['review_body'] ?? ''),
			],
			'form_colors' => [
				'accent' => sanitize_hex_color($input['form_colors']['accent'] ?? '#0f766e') ?: '#0f766e',
				'accent_dark' => sanitize_hex_color($input['form_colors']['accent_dark'] ?? '#0b5f59') ?: '#0b5f59',
				'panel' => sanitize_hex_color($input['form_colors']['panel'] ?? '#ffffff') ?: '#ffffff',
				'ink' => sanitize_hex_color($input['form_colors']['ink'] ?? '#17202a') ?: '#17202a',
			],
			'fare' => [
				'base_fare' => self::money($fare['base_fare'] ?? '0'),
				'minimum_fare' => self::money($fare['minimum_fare'] ?? '0'),
				'per_distance' => self::money($fare['per_distance'] ?? '0'),
				'per_minute' => self::money($fare['per_minute'] ?? '0'),
				'hourly_rate' => self::money($fare['hourly_rate'] ?? '0'),
				'return_trip_multiplier' => self::decimal($fare['return_trip_multiplier'] ?? '1'),
			],
		];

		return array_replace($existing, $sanitized);
	}

	public static function render(): void {
		$tab = sanitize_key($_GET['tab'] ?? 'settings');
		if (!in_array($tab, ['settings', 'setup', 'diagnostics'], true)) {
			$tab = 'settings';
		}

		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Configuration', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('RideFleet Settings', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Configure pricing defaults, maps integration, checkout, branding, and API access from one place.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<nav class="rfb-tabs">
				<a class="<?php echo esc_attr('settings' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-settings')); ?>"><?php esc_html_e('Settings', 'ridefleet-booking'); ?></a>
				<a class="<?php echo esc_attr('setup' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-settings&tab=setup')); ?>"><?php esc_html_e('Setup', 'ridefleet-booking'); ?></a>
				<a class="<?php echo esc_attr('diagnostics' === $tab ? 'is-active' : ''); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-settings&tab=diagnostics')); ?>"><?php esc_html_e('Diagnostics', 'ridefleet-booking'); ?></a>
			</nav>
			<?php
			if ('setup' === $tab) {
				SetupPage::render_content();
				echo '</div>';
				return;
			}
			if ('diagnostics' === $tab) {
				DiagnosticsPage::render_content();
				echo '</div>';
				return;
			}
			?>
		<?php
		$options = get_option('rfb_settings', []);
		$options = is_array($options) ? $options : [];
		$generated_dispatcher_key = self::ensure_dispatcher_key($options);
		$fare = $options['fare'] ?? [];
		$woo_active = class_exists('WooCommerce');
		$woo_enabled = 'yes' === ($options['woocommerce_checkout_enabled'] ?? 'no');
		$dispatcher_enabled = 'yes' === ($options['dispatcher_api_enabled'] ?? 'no');
		$dispatcher_key = (string) ($options['dispatcher_api_key'] ?? '');
		$dispatcher_base = rest_url('ridefleet/v1/dispatcher');
		$colors = $options['form_colors'] ?? [];
		$templates = $options['email_templates'] ?? [];
		?>
			<?php settings_errors('rfb_settings'); ?>
			<?php if (!empty($_GET['test_email'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test email sent.', 'ridefleet-booking'); ?></p></div><?php endif; ?>
			<?php if ($generated_dispatcher_key) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Dispatcher API key generated. Copy it from the API section below and keep it private.', 'ridefleet-booking'); ?></p></div><?php endif; ?>

			<div class="rfb-settings-status-bar">
				<span class="rfb-settings-status-label"><?php esc_html_e('System status', 'ridefleet-booking'); ?></span>
				<span class="rfb-badge <?php echo esc_attr($woo_active ? 'rfb-badge-confirmed' : 'rfb-badge-cancelled'); ?>">
					<span class="dashicons <?php echo esc_attr($woo_active ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
					<?php echo esc_html($woo_active ? __('WooCommerce active', 'ridefleet-booking') : __('WooCommerce inactive', 'ridefleet-booking')); ?>
				</span>
				<span class="rfb-badge <?php echo esc_attr(!empty($options['google_maps_api_key']) ? 'rfb-badge-confirmed' : 'rfb-badge-pending'); ?>">
					<span class="dashicons <?php echo esc_attr(!empty($options['google_maps_api_key']) ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
					<?php echo esc_html(!empty($options['google_maps_api_key']) ? __('Maps configured', 'ridefleet-booking') : __('Maps key missing', 'ridefleet-booking')); ?>
				</span>
				<span class="rfb-badge <?php echo esc_attr($woo_enabled ? 'rfb-badge-confirmed' : 'rfb-badge-pending'); ?>">
					<span class="dashicons <?php echo esc_attr($woo_enabled ? 'dashicons-yes-alt' : 'dashicons-minus'); ?>"></span>
					<?php echo esc_html($woo_enabled ? __('Checkout enabled', 'ridefleet-booking') : __('Checkout off', 'ridefleet-booking')); ?>
				</span>
				<span class="rfb-badge <?php echo esc_attr($dispatcher_enabled && $dispatcher_key ? 'rfb-badge-confirmed' : 'rfb-badge-pending'); ?>">
					<span class="dashicons <?php echo esc_attr($dispatcher_enabled && $dispatcher_key ? 'dashicons-yes-alt' : 'dashicons-minus'); ?>"></span>
					<?php echo esc_html($dispatcher_enabled && $dispatcher_key ? __('Dispatcher API on', 'ridefleet-booking') : __('Dispatcher API off', 'ridefleet-booking')); ?>
				</span>
			</div>

			<form method="post" action="options.php" class="rfb-settings-form">
				<?php settings_fields('rfb_settings_group'); ?>
				<div class="rfb-settings-stack">

					<section class="rfb-panel rfb-settings-section">
						<div class="rfb-settings-section-head">
							<span class="rfb-settings-section-icon dashicons dashicons-chart-line"></span>
							<div>
								<h2><?php esc_html_e('Business & Fare Defaults', 'ridefleet-booking'); ?></h2>
								<p><?php esc_html_e('Base quote behavior — currency, distance unit, and all fare components.', 'ridefleet-booking'); ?></p>
							</div>
						</div>
						<div class="rfb-settings-section-divider"><span><?php esc_html_e('Business defaults', 'ridefleet-booking'); ?></span></div>
						<div class="rfb-field-grid">
							<?php self::text('currency', __('Currency code', 'ridefleet-booking'), $options['currency'] ?? 'USD', 'USD'); ?>
							<label>
								<span><?php esc_html_e('Distance unit', 'ridefleet-booking'); ?></span>
								<select name="rfb_settings[distance_unit]">
									<option value="km" <?php selected($options['distance_unit'] ?? 'km', 'km'); ?>><?php esc_html_e('Kilometers (km)', 'ridefleet-booking'); ?></option>
									<option value="mi" <?php selected($options['distance_unit'] ?? 'km', 'mi'); ?>><?php esc_html_e('Miles (mi)', 'ridefleet-booking'); ?></option>
								</select>
							</label>
						</div>
						<div class="rfb-settings-section-divider"><span><?php esc_html_e('Fare components', 'ridefleet-booking'); ?></span></div>
						<div class="rfb-settings-fare-grid">
							<?php self::fare('base_fare', __('Base fare', 'ridefleet-booking'), $fare['base_fare'] ?? '25.00', __('Flat fee on every ride', 'ridefleet-booking')); ?>
							<?php self::fare('minimum_fare', __('Minimum fare', 'ridefleet-booking'), $fare['minimum_fare'] ?? '35.00', __('No quote goes below this', 'ridefleet-booking')); ?>
							<?php self::fare('per_distance', __('Per km / mi', 'ridefleet-booking'), $fare['per_distance'] ?? '2.00', __('Distance multiplier', 'ridefleet-booking')); ?>
							<?php self::fare('per_minute', __('Per minute', 'ridefleet-booking'), $fare['per_minute'] ?? '0.00', __('Time multiplier', 'ridefleet-booking')); ?>
							<?php self::fare('hourly_rate', __('Hourly rate', 'ridefleet-booking'), $fare['hourly_rate'] ?? '75.00', __('For hourly bookings', 'ridefleet-booking')); ?>
							<?php self::fare('return_trip_multiplier', __('Return trip ×', 'ridefleet-booking'), $fare['return_trip_multiplier'] ?? '1.80', __('Applied to fixed-price returns', 'ridefleet-booking')); ?>
						</div>
					</section>

					<section class="rfb-panel rfb-settings-section">
						<div class="rfb-settings-section-head">
							<span class="rfb-settings-section-icon dashicons dashicons-location-alt"></span>
							<div>
								<h2><?php esc_html_e('Maps & Checkout', 'ridefleet-booking'); ?></h2>
								<p><?php esc_html_e('Google Maps integration and WooCommerce payment flow.', 'ridefleet-booking'); ?></p>
							</div>
						</div>
						<div class="rfb-settings-section-divider"><span><?php esc_html_e('Google Maps', 'ridefleet-booking'); ?></span></div>
						<label>
							<span><?php esc_html_e('API Key', 'ridefleet-booking'); ?></span>
							<input type="text" name="rfb_settings[google_maps_api_key]" value="<?php echo esc_attr($options['google_maps_api_key'] ?? ''); ?>" placeholder="AIza...">
							<small><?php esc_html_e('Enable: Places API, Directions API, Maps JavaScript API.', 'ridefleet-booking'); ?></small>
						</label>
						<label>
							<span><?php esc_html_e('Cloud Map ID', 'ridefleet-booking'); ?></span>
							<input type="text" name="rfb_settings[google_maps_map_id]" value="<?php echo esc_attr($options['google_maps_map_id'] ?? ''); ?>" placeholder="<?php esc_attr_e('Optional — unlocks custom styling', 'ridefleet-booking'); ?>">
							<small><?php esc_html_e('Create in Google Cloud Console → Google Maps Platform → Map Management.', 'ridefleet-booking'); ?></small>
						</label>
						<div class="rfb-settings-section-divider"><span><?php esc_html_e('Checkout', 'ridefleet-booking'); ?></span></div>
						<label class="rfb-checkbox">
							<input type="checkbox" name="rfb_settings[woocommerce_checkout_enabled]" value="1" <?php checked($options['woocommerce_checkout_enabled'] ?? 'no', 'yes'); ?>>
							<span><?php esc_html_e('Route bookings through WooCommerce checkout', 'ridefleet-booking'); ?></span>
						</label>
						<div class="rfb-settings-checklist">
							<div class="<?php echo esc_attr($woo_active ? 'is-ok' : 'is-warn'); ?>">
								<span class="dashicons <?php echo esc_attr($woo_active ? 'dashicons-yes-alt' : 'dashicons-warning'); ?>"></span>
								<?php echo esc_html($woo_active ? __('WooCommerce is active.', 'ridefleet-booking') : __('WooCommerce not found — install and activate it to use checkout.', 'ridefleet-booking')); ?>
							</div>
							<div class="<?php echo esc_attr((int) get_option('rfb_woocommerce_product_id', 0) ? 'is-ok' : 'is-info'); ?>">
								<span class="dashicons <?php echo esc_attr((int) get_option('rfb_woocommerce_product_id', 0) ? 'dashicons-yes-alt' : 'dashicons-info-outline'); ?>"></span>
								<?php echo esc_html((int) get_option('rfb_woocommerce_product_id', 0) ? __('Hidden booking product exists.', 'ridefleet-booking') : __('Hidden product will be auto-created on first checkout.', 'ridefleet-booking')); ?>
							</div>
							<div class="is-info">
								<span class="dashicons dashicons-info-outline"></span>
								<?php esc_html_e('When disabled, bookings are stored directly — no WooCommerce order is created.', 'ridefleet-booking'); ?>
							</div>
						</div>
					</section>

					<section class="rfb-panel rfb-settings-section rfb-settings-section--wide">
						<div class="rfb-settings-section-head">
							<span class="rfb-settings-section-icon dashicons dashicons-admin-network"></span>
							<div>
								<h2><?php esc_html_e('Virtual Dispatcher API', 'ridefleet-booking'); ?></h2>
								<p><?php esc_html_e('Machine access for phone dispatchers. Can quote rides, create bookings, look up reservations, and request edits — cannot touch settings, geofences, vehicles, or pricing.', 'ridefleet-booking'); ?></p>
							</div>
							<span class="rfb-badge <?php echo esc_attr($dispatcher_enabled && $dispatcher_key ? 'rfb-badge-confirmed' : 'rfb-badge-pending'); ?> rfb-settings-api-badge">
								<?php echo esc_html($dispatcher_enabled && $dispatcher_key ? __('Enabled', 'ridefleet-booking') : __('Disabled', 'ridefleet-booking')); ?>
							</span>
						</div>
						<div class="rfb-settings-api-grid">
							<div class="rfb-settings-api-endpoints">
								<p class="rfb-eyebrow"><?php esc_html_e('Endpoints', 'ridefleet-booking'); ?></p>
								<div class="rfb-settings-endpoint-row">
									<span class="rfb-settings-method">POST</span>
									<code><?php echo esc_html($dispatcher_base . '/quote'); ?></code>
								</div>
								<div class="rfb-settings-endpoint-row">
									<span class="rfb-settings-method">POST</span>
									<code><?php echo esc_html($dispatcher_base . '/bookings'); ?></code>
								</div>
								<div class="rfb-settings-endpoint-row">
									<span class="rfb-settings-method">GET</span>
									<code><?php echo esc_html($dispatcher_base . '/bookings'); ?></code>
								</div>
								<p class="rfb-settings-api-note"><?php esc_html_e('Authenticate with: Authorization: Bearer YOUR_KEY', 'ridefleet-booking'); ?></p>
							</div>
							<div class="rfb-settings-api-config">
								<label class="rfb-checkbox" style="margin-bottom:14px">
									<input type="checkbox" name="rfb_settings[dispatcher_api_enabled]" value="1" <?php checked($options['dispatcher_api_enabled'] ?? 'no', 'yes'); ?>>
									<span><?php esc_html_e('Enable virtual dispatcher REST API', 'ridefleet-booking'); ?></span>
								</label>
								<label>
									<span><?php esc_html_e('API Key', 'ridefleet-booking'); ?></span>
									<input type="text" name="rfb_settings[dispatcher_api_key]" value="<?php echo esc_attr($dispatcher_key); ?>" placeholder="rfb_live_dispatcher_...">
									<small><?php esc_html_e('Clear this field while enabled, then save — a new key will be generated automatically.', 'ridefleet-booking'); ?></small>
								</label>
							</div>
						</div>
					</section>

					<section class="rfb-panel rfb-settings-section">
						<div class="rfb-settings-section-head">
							<span class="rfb-settings-section-icon dashicons dashicons-admin-appearance"></span>
							<div>
								<h2><?php esc_html_e('Branding', 'ridefleet-booking'); ?></h2>
								<p><?php esc_html_e('Booking form colours and optional services at checkout.', 'ridefleet-booking'); ?></p>
							</div>
						</div>
						<label class="rfb-checkbox" style="margin-bottom:16px">
							<input type="checkbox" name="rfb_settings[enable_extras]" value="1" <?php checked($options['enable_extras'] ?? 'yes', 'yes'); ?>>
							<span><?php esc_html_e('Show optional extras (child seats, add-ons) on the booking form', 'ridefleet-booking'); ?></span>
						</label>
						<div class="rfb-settings-color-grid">
							<?php self::color('accent', __('Accent', 'ridefleet-booking'), $colors['accent'] ?? '#0f766e'); ?>
							<?php self::color('accent_dark', __('Accent hover', 'ridefleet-booking'), $colors['accent_dark'] ?? '#0b5f59'); ?>
							<?php self::color('panel', __('Panel background', 'ridefleet-booking'), $colors['panel'] ?? '#ffffff'); ?>
							<?php self::color('ink', __('Text', 'ridefleet-booking'), $colors['ink'] ?? '#17202a'); ?>
						</div>
					</section>

					<section class="rfb-panel rfb-settings-section">
						<div class="rfb-settings-section-head">
							<span class="rfb-settings-section-icon dashicons dashicons-email-alt"></span>
							<div>
								<h2><?php esc_html_e('Email Operations', 'ridefleet-booking'); ?></h2>
								<p><?php esc_html_e('Notification address, review links, and message templates.', 'ridefleet-booking'); ?></p>
							</div>
						</div>

						<div class="rfb-settings-subsection">
							<p class="rfb-settings-subsection-head"><?php esc_html_e('Routing', 'ridefleet-booking'); ?></p>
							<div class="rfb-field-grid">
								<?php self::text('admin_email', __('Notification email', 'ridefleet-booking'), $options['admin_email'] ?? get_option('admin_email'), 'dispatch@example.com'); ?>
								<?php self::text('review_link', __('Google Review link', 'ridefleet-booking'), $options['review_link'] ?? '', 'https://g.page/r/...'); ?>
							</div>
						</div>

						<div class="rfb-settings-subsection">
							<p class="rfb-settings-subsection-head"><?php esc_html_e('Templates', 'ridefleet-booking'); ?></p>

							<details class="rfb-settings-template-card" open>
								<summary class="rfb-settings-template-card-head">
									<span class="dashicons dashicons-email-alt"></span>
									<?php esc_html_e('Booking notification', 'ridefleet-booking'); ?>
									<span class="rfb-settings-template-chevron dashicons dashicons-arrow-down-alt2"></span>
								</summary>
								<div class="rfb-settings-template-body">
									<label>
										<span><?php esc_html_e('Subject', 'ridefleet-booking'); ?></span>
										<input type="text" name="rfb_settings[email_templates][booking_subject]" value="<?php echo esc_attr($templates['booking_subject'] ?? 'Your booking request {booking_number}'); ?>">
									</label>
									<label>
										<span><?php esc_html_e('Body', 'ridefleet-booking'); ?></span>
										<textarea name="rfb_settings[email_templates][booking_body]" rows="6"><?php echo esc_textarea(!empty($templates['booking_body']) ? $templates['booking_body'] : "A booking request was created.\n\nBooking: {booking_number}\nPickup: {pickup_address}\nDrop-off: {dropoff_address}\nTotal: {currency} {total}"); ?></textarea>
									</label>
								</div>
							</details>

							<details class="rfb-settings-template-card" open>
								<summary class="rfb-settings-template-card-head">
									<span class="dashicons dashicons-awards"></span>
									<?php esc_html_e('Review request', 'ridefleet-booking'); ?>
									<span class="rfb-settings-template-chevron dashicons dashicons-arrow-down-alt2"></span>
								</summary>
								<div class="rfb-settings-template-body">
									<label>
										<span><?php esc_html_e('Subject', 'ridefleet-booking'); ?></span>
										<input type="text" name="rfb_settings[email_templates][review_subject]" value="<?php echo esc_attr($templates['review_subject'] ?? 'How was your ride with RideFleet? {booking_number}'); ?>">
									</label>
									<label>
										<span><?php esc_html_e('Body', 'ridefleet-booking'); ?></span>
										<textarea name="rfb_settings[email_templates][review_body]" rows="6"><?php echo esc_textarea(!empty($templates['review_body']) ? $templates['review_body'] : "Thank you for choosing RideFleet!\n\nWe'd like to hear about your experience:\n\nBooking: {booking_number}\nRoute: {pickup_address} -> {dropoff_address}\n\nPlease let us know how we did and if you'd recommend us to others.\n\nBest regards,\nRideFleet"); ?></textarea>
									</label>
								</div>
							</details>
						</div>

						<div class="rfb-settings-token-row">
							<span class="rfb-eyebrow"><?php esc_html_e('Available tokens', 'ridefleet-booking'); ?></span>
							<?php foreach (['{booking_number}', '{pickup_address}', '{dropoff_address}', '{currency}', '{total}'] as $token) : ?>
								<code><?php echo esc_html($token); ?></code>
							<?php endforeach; ?>
						</div>
						<div class="rfb-settings-inline-actions" style="margin-top:14px">
							<a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rfb_send_test_email'), 'rfb_send_test_email')); ?>">
								<span class="dashicons dashicons-email-alt" style="margin-right:4px;margin-top:3px"></span>
								<?php esc_html_e('Send Test Email', 'ridefleet-booking'); ?>
							</a>
							<span class="rfb-settings-test-email-hint"><?php esc_html_e('Sends to the notification email above.', 'ridefleet-booking'); ?></span>
						</div>
					</section>

				</div>
				<div class="rfb-settings-save-row">
					<?php submit_button(__('Save Settings', 'ridefleet-booking'), 'primary large', 'submit', false); ?>
				</div>
			</form>
		</div>
		<?php
	}

	public static function send_test_email(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'ridefleet-booking'));
		}

		check_admin_referer('rfb_send_test_email');
		$options = get_option('rfb_settings', []);
		$to = is_array($options) ? sanitize_email($options['admin_email'] ?? get_option('admin_email')) : get_option('admin_email');
		wp_mail($to, __('RideFleet test email', 'ridefleet-booking'), __('RideFleet email delivery is working if you received this message.', 'ridefleet-booking'));
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-settings&test_email=1'));
		exit;
	}

	private static function ensure_dispatcher_key(array &$options): bool {
		if ('yes' !== ($options['dispatcher_api_enabled'] ?? 'no') || !empty($options['dispatcher_api_key'])) {
			return false;
		}

		$options['dispatcher_api_key'] = 'rfb_live_dispatcher_' . wp_generate_password(32, false, false);
		update_option('rfb_settings', $options, false);
		return true;
	}

	private static function text(string $key, string $label, string $value, string $placeholder = ''): void {
		?>
		<label>
			<span><?php echo esc_html($label); ?></span>
			<input type="text" name="rfb_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" placeholder="<?php echo esc_attr($placeholder); ?>">
		</label>
		<?php
	}

	private static function fare(string $key, string $label, string $value, string $hint = ''): void {
		?>
		<label>
			<span><?php echo esc_html($label); ?></span>
			<input type="number" step="0.01" min="0" name="rfb_settings[fare][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>">
			<?php if ($hint) : ?><small><?php echo esc_html($hint); ?></small><?php endif; ?>
		</label>
		<?php
	}

	private static function color(string $key, string $label, string $value): void {
		?>
		<div class="rfb-color-field">
			<span><?php echo esc_html($label); ?></span>
			<div class="rfb-color-field-input">
				<input type="color" name="rfb_settings[form_colors][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($value); ?>" data-rfb-color-swatch>
				<code><?php echo esc_html($value); ?></code>
			</div>
		</div>
		<?php
	}

	private static function money(string $value): string {
		return number_format((float) $value, 2, '.', '');
	}

	private static function decimal(string $value): string {
		return (string) max(0, (float) $value);
	}
}
