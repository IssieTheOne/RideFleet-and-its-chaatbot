<?php
/**
 * Frontend shortcodes.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Frontend;

use RideFleetBooking\Integrations\GoogleMaps;
use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class Shortcodes {
	public static function register_hooks(): void {
		add_shortcode('ridefleet_booking_form', [self::class, 'booking_form']);
	}

	public static function booking_form(array $atts = []): string {
		$atts = shortcode_atts(
			[
				'id' => 0,
				'origin' => '',
				'destination' => '',
				'route_id' => 0,
			],
			$atts,
			'ridefleet_booking_form'
		);
		self::enqueue_assets((int) $atts['id']);

		ob_start();
		$colors = Options::get('form_colors', []);
		$style = sprintf(
			'--rfb-accent:%s;--rfb-accent-dark:%s;--rfb-panel:%s;--rfb-ink:%s;',
			esc_attr($colors['accent'] ?? '#0f766e'),
			esc_attr($colors['accent_dark'] ?? '#0b5f59'),
			esc_attr($colors['panel'] ?? '#ffffff'),
			esc_attr($colors['ink'] ?? '#17202a')
		);
		$extras_enabled = 'yes' === Options::get('enable_extras', 'yes');
		?>
		<div class="rfb-booking-form rfb-ride-app" style="<?php echo esc_attr($style); ?>" data-form-id="<?php echo esc_attr((int) $atts['id']); ?>" data-route-id="<?php echo esc_attr((int) $atts['route_id']); ?>" data-extras-enabled="<?php echo esc_attr($extras_enabled ? 'yes' : 'no'); ?>">
			<aside class="rfb-ride-panel">
				<div class="rfb-brand-row">
					<strong><?php esc_html_e('RideFleet', 'ridefleet-booking'); ?></strong>
					<span><?php esc_html_e('Ride booking', 'ridefleet-booking'); ?></span>
				</div>
				<div class="rfb-stepper" data-rfb-stepper>
					<button type="button" class="rfb-step-back" data-rfb-global-back aria-label="<?php esc_attr_e('Back', 'ridefleet-booking'); ?>" hidden>&larr;</button>
					<span class="is-active" data-rfb-step-dot="route">1</span>
					<span data-rfb-step-dot="time">2</span>
					<span data-rfb-step-dot="ride">3</span>
					<span data-rfb-step-dot="details">4</span>
					<span data-rfb-step-dot="review">5</span>
				</div>

				<section class="rfb-flow-step is-active" data-rfb-flow-step="route">
					<h2><?php esc_html_e('Where are you going?', 'ridefleet-booking'); ?></h2>
					<div class="rfb-route-box">
						<label class="rfb-location-field">
							<span class="rfb-dot rfb-dot-pickup"></span>
							<input type="text" data-rfb-field="pickupAddress" autocomplete="off" value="<?php echo esc_attr((string) $atts['origin']); ?>" placeholder="<?php esc_attr_e('Pickup address or place', 'ridefleet-booking'); ?>" required>
						</label>
						<label class="rfb-location-field">
							<span class="rfb-dot rfb-dot-dropoff"></span>
							<input type="text" data-rfb-field="dropoffAddress" autocomplete="off" value="<?php echo esc_attr((string) $atts['destination']); ?>" placeholder="<?php esc_attr_e('Drop-off address or place', 'ridefleet-booking'); ?>" required>
						</label>
					</div>

					<div class="rfb-route-summary" data-rfb-route-summary><?php esc_html_e('Choose pickup and drop-off from the dropdown list to preview the route.', 'ridefleet-booking'); ?></div>
					<div class="rfb-manual-route" data-rfb-manual-route hidden>
						<div class="rfb-pill-grid">
							<label>
								<span><?php esc_html_e('Distance', 'ridefleet-booking'); ?></span>
								<input type="number" min="0" step="0.1" data-rfb-field="manualDistance">
							</label>
							<label>
								<span><?php esc_html_e('Minutes', 'ridefleet-booking'); ?></span>
								<input type="number" min="0" step="1" data-rfb-field="manualDuration">
							</label>
						</div>
					</div>

					<button type="button" class="rfb-search-button" data-rfb-search><?php esc_html_e('Search rides', 'ridefleet-booking'); ?></button>
				</section>

				<section class="rfb-flow-step" data-rfb-flow-step="time">
					<h2><?php esc_html_e('When should we pick you up?', 'ridefleet-booking'); ?></h2>
					<div class="rfb-date-strip" data-rfb-date-strip></div>
					<div class="rfb-pill-grid">
						<label>
							<span><?php esc_html_e('Date', 'ridefleet-booking'); ?></span>
							<input type="date" data-rfb-field="pickupDate" required>
						</label>
						<label>
							<span><?php esc_html_e('Time', 'ridefleet-booking'); ?></span>
							<input type="time" data-rfb-field="pickupTime" required>
						</label>
					</div>
					<div class="rfb-date-modal" data-rfb-date-modal hidden>
						<div class="rfb-date-modal-panel">
							<div class="rfb-date-modal-head">
								<button type="button" data-rfb-calendar-prev aria-label="<?php esc_attr_e('Previous month', 'ridefleet-booking'); ?>">&lsaquo;</button>
								<strong data-rfb-calendar-title></strong>
								<button type="button" data-rfb-calendar-next aria-label="<?php esc_attr_e('Next month', 'ridefleet-booking'); ?>">&rsaquo;</button>
							</div>
							<div class="rfb-calendar-months" data-rfb-calendar-months></div>
							<div class="rfb-calendar-foot">
								<span><?php esc_html_e('Reserve up to 90 days ahead.', 'ridefleet-booking'); ?></span>
								<button type="button" data-rfb-calendar-close><?php esc_html_e('Done', 'ridefleet-booking'); ?></button>
							</div>
						</div>
					</div>
					<div class="rfb-time-grid" data-rfb-time-grid></div>
					<button type="button" class="rfb-search-button" data-rfb-next="ride"><?php esc_html_e('Continue', 'ridefleet-booking'); ?></button>
				</section>

				<section class="rfb-flow-step" data-rfb-flow-step="ride">
					<h3><?php esc_html_e('Passengers and luggage', 'ridefleet-booking'); ?></h3>
					<div class="rfb-pill-grid">
						<label>
							<span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span>
							<input type="number" min="1" value="1" data-rfb-field="passengers">
						</label>
						<label>
							<span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span>
							<input type="number" min="0" value="0" data-rfb-field="luggage">
						</label>
					</div>
					<div class="rfb-options rfb-vehicle-selection" data-rfb-vehicles hidden></div>

					<?php if ($extras_enabled) : ?>
						<h3><?php esc_html_e('Extras', 'ridefleet-booking'); ?></h3>
						<div class="rfb-options" data-rfb-extras></div>
					<?php endif; ?>
					<button type="button" class="rfb-search-button" data-rfb-next="details"><?php esc_html_e('Continue', 'ridefleet-booking'); ?></button>
				</section>

				<section class="rfb-flow-step" data-rfb-flow-step="details">
					<h3><?php esc_html_e('Details', 'ridefleet-booking'); ?></h3>
					<div class="rfb-pill-grid">
						<label><span><?php esc_html_e('First name', 'ridefleet-booking'); ?></span><input type="text" data-rfb-field="customerFirstName" required></label>
						<label><span><?php esc_html_e('Last name', 'ridefleet-booking'); ?></span><input type="text" data-rfb-field="customerLastName" required></label>
					</div>
					<label class="rfb-line-field"><span><?php esc_html_e('Email', 'ridefleet-booking'); ?></span><input type="email" data-rfb-field="customerEmail" required></label>
					<label class="rfb-line-field"><span><?php esc_html_e('Phone', 'ridefleet-booking'); ?></span><div class="rfb-phone-row"><select data-rfb-field="customerPhonePrefix" aria-label="<?php esc_attr_e('Country calling code', 'ridefleet-booking'); ?>"><option value="+1">US/CA +1</option><option value="+32">BE +32</option><option value="+31">NL +31</option><option value="+33">FR +33</option><option value="+44">UK +44</option><option value="+49">DE +49</option><option value="+212">MA +212</option></select><input type="tel" inputmode="numeric" pattern="[0-9 ]*" data-rfb-field="customerPhone" required></div></label>
					<details class="rfb-coupon-toggle">
						<summary><?php esc_html_e('Add promo code', 'ridefleet-booking'); ?></summary>
						<div class="rfb-coupon-row">
							<input type="text" data-rfb-field="couponCode" aria-label="<?php esc_attr_e('Promo code', 'ridefleet-booking'); ?>">
							<button type="button" data-rfb-apply-coupon><?php esc_html_e('Apply', 'ridefleet-booking'); ?></button>
						</div>
					</details>
					<label class="rfb-line-field"><span><?php esc_html_e('Notes', 'ridefleet-booking'); ?></span><textarea data-rfb-field="note" rows="2" maxlength="500"></textarea></label>
				</section>

				<section class="rfb-flow-step" data-rfb-flow-step="review">
					<h3><?php esc_html_e('Review your ride', 'ridefleet-booking'); ?></h3>
					<div class="rfb-review-card">
						<div class="rfb-review-route">
							<div><span><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></span><strong data-rfb-review="pickup">--</strong></div>
							<div><span><?php esc_html_e('Drop-off', 'ridefleet-booking'); ?></span><strong data-rfb-review="dropoff">--</strong></div>
						</div>
						<div class="rfb-review-facts">
							<div><span><?php esc_html_e('When', 'ridefleet-booking'); ?></span><strong data-rfb-review="when">--</strong></div>
							<div><span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span><strong data-rfb-review="passengers">--</strong></div>
							<div><span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span><strong data-rfb-review="luggage">--</strong></div>
							<div><span><?php esc_html_e('Extras', 'ridefleet-booking'); ?></span><strong data-rfb-review="extras">--</strong></div>
							<div><span><?php esc_html_e('Customer', 'ridefleet-booking'); ?></span><strong data-rfb-review="customer">--</strong></div>
							<div><span><?php esc_html_e('Phone', 'ridefleet-booking'); ?></span><strong data-rfb-review="phone">--</strong></div>
						</div>
					</div>
					<div class="rfb-review-total">
						<span><?php esc_html_e('Estimated total', 'ridefleet-booking'); ?></span>
						<strong data-rfb-review="total">--</strong>
					</div>
					<button type="button" class="rfb-final-submit" data-rfb-submit><?php esc_html_e('Confirm and reserve', 'ridefleet-booking'); ?></button>
				</section>

				<div class="rfb-checkout-bar">
					<div>
						<span><?php esc_html_e('Estimated total', 'ridefleet-booking'); ?></span>
						<strong data-rfb-total>--</strong>
						<div class="rfb-trip-facts">
							<div><span><?php esc_html_e('Distance', 'ridefleet-booking'); ?></span><b data-rfb-distance>--</b></div>
							<div><span><?php esc_html_e('Duration', 'ridefleet-booking'); ?></span><b data-rfb-duration>--</b></div>
						</div>
					</div>
					<button type="button" data-rfb-next="review"><?php esc_html_e('Review ride', 'ridefleet-booking'); ?></button>
				</div>
				<div class="rfb-message" data-rfb-message></div>
				<div class="rfb-confirmation" data-rfb-confirmation hidden>
					<strong><?php esc_html_e('Ride request received', 'ridefleet-booking'); ?></strong>
					<p data-rfb-confirmation-text></p>
				</div>
			</aside>

			<div class="rfb-map-shell">
				<div class="rfb-map" data-rfb-map></div>
				<div class="rfb-floating-route" data-rfb-floating-route></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	private static function enqueue_assets(int $form_id): void {
		wp_enqueue_style('rfb-frontend', RFB_PLUGIN_URL . 'assets/frontend/booking-form.css', [], RFB_VERSION);
		wp_enqueue_script('rfb-frontend', RFB_PLUGIN_URL . 'assets/frontend/booking-form.js', [], RFB_VERSION, true);
		GoogleMaps::enqueue_script();
		wp_localize_script(
			'rfb-frontend',
			'RideFleetBooking',
			[
				'restUrl' => esc_url_raw(rest_url('ridefleet/v1')),
				'nonce' => wp_create_nonce('wp_rest'),
				'formId' => $form_id,
			]
		);
	}
}
