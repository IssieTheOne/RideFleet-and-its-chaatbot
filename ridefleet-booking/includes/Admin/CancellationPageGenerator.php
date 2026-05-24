<?php
/**
 * Creates / regenerates the WP cancellation policy page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class CancellationPageGenerator {

	/**
	 * AJAX handler — creates or regenerates the cancellation policy page.
	 * Hooked to wp_ajax_rfb_create_cancellation_page.
	 */
	public static function ajax_create(): void {
		check_ajax_referer('rfb_admin_ajax', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('Permission denied.', 'ridefleet-booking')], 403);
		}

		$page_id     = absint(Options::get('cancellation_page_id', 0));
		$existing    = $page_id ? get_post($page_id) : null;
		$content     = self::build_content();
		$title       = __('Cancellation Policy', 'ridefleet-booking');

		if ($existing && 'publish' === $existing->post_status) {
			// Regenerate — update content only.
			wp_update_post([
				'ID'           => $existing->ID,
				'post_content' => $content,
				'post_title'   => $title,
			]);
			$new_id = $existing->ID;
		} else {
			$new_id = wp_insert_post([
				'post_title'   => $title,
				'post_name'    => 'cancellation-policy',
				'post_content' => $content,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			], true);

			if (is_wp_error($new_id)) {
				wp_send_json_error(['message' => $new_id->get_error_message()]);
			}
		}

		Options::update(['cancellation_page_id' => $new_id]);

		wp_send_json_success([
			'page_id'  => $new_id,
			'page_url' => get_permalink($new_id),
			'edit_url' => get_edit_post_link($new_id, 'raw'),
			'message'  => __('Cancellation policy page created / updated.', 'ridefleet-booking'),
		]);
	}

	/**
	 * Returns the permalink of the cancellation page if it exists and is published.
	 */
	public static function page_url(): string {
		if (!\RideFleetBooking\Support\Options::get('cancellation_policy_enabled', false)) {
			return '';
		}
		$page_id = absint(\RideFleetBooking\Support\Options::get('cancellation_page_id', 0));
		if (!$page_id) {
			return '';
		}
		$permalink = get_permalink($page_id);
		return is_string($permalink) ? $permalink : '';
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	private static function build_content(): string {
		$tokens = [
			'{company_name}'       => esc_html(Options::get('company_name', get_bloginfo('name'))),
			'{company_phone}'      => esc_html(Options::get('company_phone', '')),
			'{company_email}'      => esc_html(Options::get('notification_email', get_option('admin_email', ''))),
			'{company_address}'    => esc_html(Options::get('company_address', '')),
			'{cancellation_hours}' => absint(Options::get('cancellation_hours', 24)),
		];

		$template = self::template();
		foreach ($tokens as $token => $value) {
			$template = str_replace($token, $value, $template);
		}
		return $template;
	}

	/**
	 * Predefined legal content template.
	 * Tokens: {company_name}, {company_phone}, {company_email}, {company_address}, {cancellation_hours}
	 */
	private static function template(): string {
		return <<<'HTML'
<!-- wp:paragraph -->
<p><strong>{company_name}</strong> ("Company", "we", "us", "our") provides taxi and private transport services. By completing a booking through our website, app, or any representative, you confirm that you have read and accepted the following cancellation and no-show terms.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">1. Free Cancellation Window</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>You may cancel your booking free of charge up to <strong>{cancellation_hours} hours</strong> before the scheduled pickup time. To cancel, contact us using the details at the bottom of this page and quote your booking reference number.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">2. Late Cancellation</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Cancellations received less than <strong>{cancellation_hours} hours</strong> before the scheduled pickup time may be subject to a cancellation fee. The fee covers driver allocation costs and is communicated by dispatch at the time of cancellation.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">3. No-Show Policy</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>A booking is considered a no-show if the passenger does not appear at the designated pickup location within <strong>15 minutes</strong> of the scheduled pickup time without prior notice. In such cases, the full fare may be charged and the driver will be released.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">4. Modifications</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Changes to pickup time, pickup location, drop-off location, or passenger count are subject to availability and may affect the quoted fare. All modifications must be requested at least <strong>{cancellation_hours} hours</strong> before pickup. Dispatch will confirm whether the change can be accommodated and provide an updated price where applicable.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">5. Force Majeure</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We reserve the right to cancel any booking without penalty in the event of circumstances beyond our reasonable control, including but not limited to severe weather, road closures, civil disturbances, or vehicle breakdown. In such cases you will be notified as early as possible and offered an alternative or full refund of any pre-paid amount.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">6. How to Cancel or Modify</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>To cancel or modify your booking please contact us directly:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Phone / WhatsApp:</strong> {company_phone}</li>
<li><strong>Email:</strong> {company_email}</li>
<li><strong>Address:</strong> {company_address}</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Please have your <strong>booking reference number</strong> (e.g. RFB-20260522-XXXXXX) ready when you contact us.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2 class="wp-block-heading">7. Policy Updates</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>We reserve the right to update these terms at any time. The version displayed on this page at the time of booking applies. Continued use of our services constitutes acceptance of the current policy.</p>
<!-- /wp:paragraph -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:paragraph -->
<p><em>Last updated by {company_name}. For questions contact {company_email}.</em></p>
<!-- /wp:paragraph -->
HTML;
	}
}
