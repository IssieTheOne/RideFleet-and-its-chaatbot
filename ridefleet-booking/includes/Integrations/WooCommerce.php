<?php
/**
 * WooCommerce checkout integration seam.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Integrations;

use RideFleetBooking\Booking\BookingRepository;
use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class WooCommerce {
	public static function register_hooks(): void {
		add_action('admin_notices', [self::class, 'maybe_notice']);
		add_action('woocommerce_before_calculate_totals', [self::class, 'price_cart_items']);
		add_action('woocommerce_checkout_create_order_line_item', [self::class, 'add_order_item_meta'], 10, 4);
		add_action('woocommerce_checkout_order_processed', [self::class, 'order_processed'], 10, 3);
		add_action('woocommerce_order_status_changed', [self::class, 'sync_order_status'], 10, 4);
		add_filter('woocommerce_order_item_name', [self::class, 'order_item_name'], 10, 3);
		add_action('wp_enqueue_scripts', [self::class, 'enqueue_ride_checkout_assets']);
		add_filter('gettext', [self::class, 'checkout_text_overrides'], 10, 3);
		add_action('wp', [self::class, 'disable_default_ride_thankyou_sections']);
		add_action('woocommerce_pay_order_before_payment', [self::class, 'render_ride_payment_summary']);
		add_action('woocommerce_before_thankyou', [self::class, 'render_ride_thankyou_summary'], 5);
		add_action('wp_ajax_rfb_request_tax_invoice', [self::class, 'request_tax_invoice']);
		add_action('wp_ajax_nopriv_rfb_request_tax_invoice', [self::class, 'request_tax_invoice']);
		add_filter('body_class', [self::class, 'ride_payment_body_class']);
		add_filter('woocommerce_shop_order_list_table_prepare_items_query_args', [self::class, 'hide_ride_orders_from_wc_admin']);
		add_action('pre_get_posts', [self::class, 'hide_ride_orders_from_legacy_wc_admin']);
	}

	public static function is_enabled(): bool {
		return 'yes' === Options::get('woocommerce_checkout_enabled', 'no');
	}

	public static function is_available(): bool {
		return class_exists('WooCommerce');
	}

	public static function maybe_notice(): void {
		if (!current_user_can('manage_options') || !self::is_enabled() || self::is_available()) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__('RideFleet WooCommerce checkout is enabled, but WooCommerce is not active. Bookings will use the native RideFleet flow until WooCommerce is available.', 'ridefleet-booking')
		);
	}

	public static function checkout_url(int $booking_id): ?string {
		if (!self::is_enabled() || !self::is_available() || !function_exists('WC')) {
			return null;
		}

		$booking = BookingRepository::find($booking_id);
		if (!$booking || !WC()->cart) {
			return null;
		}

		$product_id = self::booking_product_id();
		if (!$product_id) {
			return null;
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			[],
			[
				'rfb_booking_id' => $booking_id,
				'rfb_booking_number' => $booking->booking_number,
				'rfb_booking_total' => (float) $booking->total,
			]
		);

		return wc_get_checkout_url();
	}

	public static function admin_payment_url(int $booking_id): ?string {
		if (!self::is_enabled() || !self::is_available() || !function_exists('wc_create_order')) {
			return null;
		}

		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			return null;
		}

		$product_id = self::booking_product_id();
		if (!$product_id) {
			return null;
		}

		$product = wc_get_product($product_id);
		if (!$product) {
			return null;
		}

		try {
			$order = wc_create_order(
				[
					'customer_id' => 0,
					'created_via' => 'ridefleet_admin_pay',
				]
			);
			self::apply_booking_customer_to_order($order, $booking);

			$item_id = $order->add_product(
				$product,
				1,
				[
					'subtotal' => (float) $booking->total,
					'total' => (float) $booking->total,
				]
			);

			if ($item_id) {
				$item = $order->get_item($item_id);
				if ($item) {
					$item->add_meta_data('_rfb_booking_id', (int) $booking_id, true);
					$item->add_meta_data(__('RideFleet Booking Number', 'ridefleet-booking'), sanitize_text_field((string) $booking->booking_number), true);
					self::add_booking_line_item_meta($item, $booking);
					$item->save();
				}
			}

			$order->calculate_totals(false);
			$order->update_meta_data('_rfb_booking_id', (int) $booking_id);
			$order->update_meta_data('_rfb_payment_flow', 'settle_ride');
			$order->save();

			BookingRepository::attach_order($booking_id, (int) $order->get_id());
			BookingRepository::update_status($booking_id, 'pending_payment', 'unpaid');

			return $order->get_checkout_payment_url();
		} catch (\Throwable $e) {
			return null;
		}
	}

	public static function price_cart_items(\WC_Cart $cart): void {
		if (is_admin() && !defined('DOING_AJAX')) {
			return;
		}

		foreach ($cart->get_cart() as $cart_item) {
			if (!empty($cart_item['rfb_booking_total']) && isset($cart_item['data'])) {
				$cart_item['data']->set_price((float) $cart_item['rfb_booking_total']);
			}
		}
	}

	public static function add_order_item_meta(\WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order): void {
		if (empty($values['rfb_booking_id'])) {
			return;
		}

		$booking_id = (int) $values['rfb_booking_id'];
		$item->add_meta_data('_rfb_booking_id', $booking_id);
		$item->add_meta_data(__('RideFleet Booking Number', 'ridefleet-booking'), sanitize_text_field($values['rfb_booking_number'] ?? ''));
		$booking = BookingRepository::find($booking_id);
		if ($booking) {
			self::add_booking_line_item_meta($item, $booking);
		}
	}

	public static function order_item_name(string $item_name, \WC_Order_Item $item, bool $is_visible): string {
		$booking_id = (int) $item->get_meta('_rfb_booking_id');
		if (!$booking_id) {
			return $item_name;
		}

		$pickup = sanitize_text_field((string) $item->get_meta(__('Pickup', 'ridefleet-booking')));
		$dropoff = sanitize_text_field((string) $item->get_meta(__('Drop-off', 'ridefleet-booking')));
		$title = __('RideFleet Taxi Ride', 'ridefleet-booking');
		if ($pickup && $dropoff) {
			$title = sprintf(__('RideFleet Taxi Ride: %1$s -> %2$s', 'ridefleet-booking'), $pickup, $dropoff);
		}

		return $is_visible ? '<span class="rfb-wc-line-title">' . esc_html($title) . '</span>' : $title;
	}

	public static function enqueue_ride_checkout_assets(): void {
		if (!self::is_ride_payment_screen() && !self::is_ride_thankyou_screen()) {
			return;
		}

		wp_enqueue_style('rfb-woo-ride', RFB_PLUGIN_URL . 'assets/frontend/woocommerce-ride.css', [], RFB_VERSION);
		wp_enqueue_script('rfb-woo-ride', RFB_PLUGIN_URL . 'assets/frontend/woocommerce-ride.js', [], RFB_VERSION, true);
		wp_localize_script(
			'rfb-woo-ride',
			'RideFleetWooRide',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'requestedLabel' => __('Invoice requested', 'ridefleet-booking'),
				'errorMessage' => __('Could not send the invoice request. Please contact dispatch.', 'ridefleet-booking'),
			]
		);
	}

	public static function checkout_text_overrides(string $translated, string $text, string $domain): string {
		if ('woocommerce' !== $domain || (!self::is_ride_payment_screen() && !self::is_ride_thankyou_screen())) {
			return $translated;
		}

		return match ($text) {
			'Product' => __('Ride', 'ridefleet-booking'),
			'Totals' => __('Fare', 'ridefleet-booking'),
			'Order details' => __('Ride details', 'ridefleet-booking'),
			'Your order' => __('Complete your ride payment', 'ridefleet-booking'),
			'You are paying for a guest order. Please continue with payment only if you recognize this order.' => '',
			default => $translated,
		};
	}

	public static function render_ride_payment_summary(): void {
		$context = self::current_ride_payment_context();
		if (!$context) {
			return;
		}

		$booking = $context['booking'];
		$order = $context['order'];
		$customer = self::booking_customer($booking);
		$pickup_at = !empty($booking->pickup_at) ? strtotime((string) $booking->pickup_at) : false;
		?>
		<section class="rfb-payment-summary" aria-label="<?php esc_attr_e('Ride payment summary', 'ridefleet-booking'); ?>">
			<div class="rfb-payment-summary__header">
				<div>
					<p><?php esc_html_e('Ride payment', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Review your taxi ride', 'ridefleet-booking'); ?></h2>
				</div>
				<strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
			</div>
			<div class="rfb-route-card">
				<div class="rfb-route-point">
					<span><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html((string) $booking->pickup_address); ?></strong>
				</div>
				<div class="rfb-route-line" aria-hidden="true"></div>
				<div class="rfb-route-point">
					<span><?php esc_html_e('Drop-off', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html((string) $booking->dropoff_address); ?></strong>
				</div>
			</div>
			<div class="rfb-payment-facts">
				<div><span><?php esc_html_e('Booking', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) $booking->booking_number); ?></strong></div>
				<div><span><?php esc_html_e('Pickup time', 'ridefleet-booking'); ?></span><strong><?php echo esc_html($pickup_at ? wp_date('D, M j, Y H:i', $pickup_at) : __('Not scheduled', 'ridefleet-booking')); ?></strong></div>
				<div><span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) max(1, (int) $booking->passengers)); ?></strong></div>
				<div><span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) max(0, (int) $booking->luggage)); ?></strong></div>
			</div>
		</section>
		<?php
	}

	public static function render_ride_thankyou_summary(int $order_id): void {
		$context = self::ride_order_context($order_id);
		if (!$context) {
			return;
		}

		$booking = $context['booking'];
		$order = $context['order'];
		$customer = self::booking_customer($booking);
		$customer_email = $customer && !empty($customer->email) ? (string) $customer->email : (string) $order->get_billing_email();
		$pickup_at = !empty($booking->pickup_at) ? strtotime((string) $booking->pickup_at) : false;
		$payment_label = $order->is_paid() || in_array($order->get_status(), ['processing', 'completed'], true) ? __('Paid', 'ridefleet-booking') : wc_get_order_status_name($order->get_status());
		$invoice_requested = self::invoice_requested((int) $booking->id);
		?>
		<section class="rfb-payment-summary rfb-payment-summary--complete" aria-label="<?php esc_attr_e('Ride payment confirmation', 'ridefleet-booking'); ?>">
			<div class="rfb-payment-summary__header">
				<div>
					<p><?php esc_html_e('Payment received', 'ridefleet-booking'); ?></p>
					<h2><?php esc_html_e('Your ride is settled', 'ridefleet-booking'); ?></h2>
				</div>
				<strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong>
			</div>
			<div class="rfb-route-card">
				<div class="rfb-route-point">
					<span><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html((string) $booking->pickup_address); ?></strong>
				</div>
				<div class="rfb-route-line" aria-hidden="true"></div>
				<div class="rfb-route-point">
					<span><?php esc_html_e('Drop-off', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html((string) $booking->dropoff_address); ?></strong>
				</div>
			</div>
			<div class="rfb-payment-facts">
				<div><span><?php esc_html_e('Booking', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) $booking->booking_number); ?></strong></div>
				<div><span><?php esc_html_e('Pickup time', 'ridefleet-booking'); ?></span><strong><?php echo esc_html($pickup_at ? wp_date('D, M j, Y H:i', $pickup_at) : __('Not scheduled', 'ridefleet-booking')); ?></strong></div>
				<div><span><?php esc_html_e('Payment', 'ridefleet-booking'); ?></span><strong><?php echo esc_html($payment_label); ?></strong></div>
				<div><span><?php esc_html_e('Method', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) $order->get_payment_method_title()); ?></strong></div>
				<div><span><?php esc_html_e('Passengers', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) max(1, (int) $booking->passengers)); ?></strong></div>
				<div><span><?php esc_html_e('Luggage', 'ridefleet-booking'); ?></span><strong><?php echo esc_html((string) max(0, (int) $booking->luggage)); ?></strong></div>
				<div><span><?php esc_html_e('Subtotal', 'ridefleet-booking'); ?></span><strong><?php echo wp_kses_post(wc_price((float) $order->get_subtotal(), ['currency' => $order->get_currency()])); ?></strong></div>
				<div><span><?php esc_html_e('Total fare', 'ridefleet-booking'); ?></span><strong><?php echo wp_kses_post($order->get_formatted_order_total()); ?></strong></div>
			</div>
			<div class="rfb-receipt-grid">
				<div>
					<span><?php esc_html_e('Customer', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html(self::customer_name($customer)); ?></strong>
					<p><?php echo esc_html($customer_email ?: __('No email recorded', 'ridefleet-booking')); ?></p>
					<?php if (!empty($customer->phone)) : ?><p><?php echo esc_html((string) $customer->phone); ?></p><?php endif; ?>
					<button type="button" class="rfb-tax-invoice-button" data-rfb-invoice-request="<?php echo esc_attr(wp_create_nonce('rfb_request_tax_invoice_' . (int) $booking->id)); ?>" data-rfb-booking-id="<?php echo esc_attr((string) $booking->id); ?>" <?php disabled($invoice_requested); ?>>
						<?php echo esc_html($invoice_requested ? __('Invoice requested', 'ridefleet-booking') : __('Request tax invoice', 'ridefleet-booking')); ?>
					</button>
				</div>
				<div>
					<span><?php esc_html_e('Billing address', 'ridefleet-booking'); ?></span>
					<strong><?php esc_html_e('Not required for this ride receipt', 'ridefleet-booking'); ?></strong>
					<p><?php esc_html_e('A full tax/VAT invoice can collect billing address separately when the customer requests one.', 'ridefleet-booking'); ?></p>
				</div>
				<div class="rfb-receipt-invoice">
					<span><?php esc_html_e('Tax/VAT invoice', 'ridefleet-booking'); ?></span>
					<strong><?php echo esc_html($invoice_requested ? __('Requested', 'ridefleet-booking') : __('Optional', 'ridefleet-booking')); ?></strong>
					<p><?php esc_html_e('Need a formal invoice? Send a request and dispatch will collect the required billing details.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
		</section>
		<?php
	}

	public static function disable_default_ride_thankyou_sections(): void {
		if (!self::is_ride_thankyou_screen()) {
			return;
		}

		remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
		remove_action('woocommerce_order_details_after_order_table', 'woocommerce_order_again_button');
	}

	public static function request_tax_invoice(): void {
		$booking_id = absint($_POST['booking_id'] ?? 0);
		$nonce = sanitize_text_field((string) ($_POST['nonce'] ?? ''));
		if (!$booking_id || !wp_verify_nonce($nonce, 'rfb_request_tax_invoice_' . $booking_id)) {
			wp_send_json_error(['message' => __('Invoice request could not be verified.', 'ridefleet-booking')], 403);
		}

		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			wp_send_json_error(['message' => __('Booking not found.', 'ridefleet-booking')], 404);
		}

		$existing = self::booking_meta($booking_id, '_invoice_details', []);
		$invoice = is_array($existing) ? $existing : [];
		$invoice['enabled'] = 'yes';
		$invoice['requested_from_receipt'] = 'yes';
		$invoice['requested_at'] = current_time('mysql');

		self::save_booking_meta(
			$booking_id,
			'_invoice_details',
			$invoice
		);

		wp_send_json_success(['message' => __('Invoice requested. Dispatch will follow up for billing details.', 'ridefleet-booking')]);
	}

	public static function ride_payment_body_class(array $classes): array {
		if (self::is_ride_payment_screen() || self::is_ride_thankyou_screen()) {
			$classes[] = 'rfb-ride-order-page';
		}

		if (self::is_ride_thankyou_screen()) {
			$classes[] = 'rfb-ride-order-complete';
		}

		return $classes;
	}

	public static function order_processed(int $order_id, array $posted_data, \WC_Order $order): void {
		foreach ($order->get_items() as $item) {
			$booking_id = (int) $item->get_meta('_rfb_booking_id');
			if ($booking_id) {
				BookingRepository::attach_order($booking_id, $order_id);
				$booking = BookingRepository::find($booking_id);
				if ($booking) {
					self::apply_booking_customer_to_order($order, $booking);
					$order->save();
				}
				BookingRepository::update_status($booking_id, 'pending_payment', 'unpaid');
			}
		}
	}

	public static function sync_order_status(int $order_id, string $old_status, string $new_status, \WC_Order $order): void {
		foreach ($order->get_items() as $item) {
			$booking_id = (int) $item->get_meta('_rfb_booking_id');
			if (!$booking_id) {
				continue;
			}

			if (in_array($new_status, ['processing', 'completed'], true)) {
				$booking_status = 'settle_ride' === (string) $order->get_meta('_rfb_payment_flow') ? 'completed' : 'confirmed';
				BookingRepository::update_status($booking_id, $booking_status, 'paid');
			} elseif (in_array($new_status, ['cancelled', 'refunded', 'failed'], true)) {
				BookingRepository::update_status($booking_id, $new_status, 'failed' === $new_status ? 'failed' : 'refunded');
			}
		}
	}

	public static function hide_ride_orders_from_wc_admin(array $query_args): array {
		if (!is_admin() || !empty($_GET['rfb_show_ridefleet_orders'])) {
			return $query_args;
		}

		$query_args['meta_query'] = $query_args['meta_query'] ?? [];
		$query_args['meta_query'][] = [
			'key' => '_rfb_booking_id',
			'compare' => 'NOT EXISTS',
		];

		return $query_args;
	}

	public static function hide_ride_orders_from_legacy_wc_admin(\WP_Query $query): void {
		if (!is_admin() || !$query->is_main_query() || !empty($_GET['rfb_show_ridefleet_orders'])) {
			return;
		}

		$post_type = $query->get('post_type');
		if ('shop_order' !== $post_type) {
			return;
		}

		$meta_query = (array) $query->get('meta_query');
		$meta_query[] = [
			'key' => '_rfb_booking_id',
			'compare' => 'NOT EXISTS',
		];
		$query->set('meta_query', $meta_query);
	}

	private static function booking_product_id(): int {
		$product_id = (int) get_option('rfb_woocommerce_product_id', 0);

		if ($product_id && 'product' === get_post_type($product_id)) {
			return $product_id;
		}

		$product_id = wp_insert_post(
			[
				'post_title' => __('RideFleet Booking', 'ridefleet-booking'),
				'post_type' => 'product',
				'post_status' => 'publish',
				'post_content' => __('Hidden product used for RideFleet booking checkout.', 'ridefleet-booking'),
			]
		);

		if (!$product_id || is_wp_error($product_id)) {
			return 0;
		}

		update_post_meta($product_id, '_virtual', 'yes');
		update_post_meta($product_id, '_sold_individually', 'yes');
		update_post_meta($product_id, '_regular_price', '0');
		update_post_meta($product_id, '_price', '0');
		update_post_meta($product_id, '_visibility', 'hidden');
		wp_set_object_terms($product_id, 'simple', 'product_type');
		update_option('rfb_woocommerce_product_id', $product_id, false);

		return (int) $product_id;
	}

	private static function current_ride_payment_context(): ?array {
		if (!self::is_ride_payment_screen()) {
			return null;
		}

		$order_id = absint(get_query_var('order-pay'));
		return self::ride_order_context($order_id);
	}

	private static function ride_order_context(int $order_id): ?array {
		if (!$order_id || !function_exists('wc_get_order')) {
			return null;
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			return null;
		}

		$booking_id = (int) $order->get_meta('_rfb_booking_id');
		if (!$booking_id) {
			foreach ($order->get_items() as $item) {
				$booking_id = (int) $item->get_meta('_rfb_booking_id');
				if ($booking_id) {
					break;
				}
			}
		}

		if (!$booking_id) {
			return null;
		}

		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			return null;
		}

		return [
			'order' => $order,
			'booking' => $booking,
		];
	}

	private static function is_ride_payment_screen(): bool {
		if (!function_exists('is_checkout') || !is_checkout() || !function_exists('is_wc_endpoint_url') || !is_wc_endpoint_url('order-pay')) {
			return false;
		}

		$order_id = absint(get_query_var('order-pay'));
		if (!$order_id || !function_exists('wc_get_order')) {
			return false;
		}

		$order = wc_get_order($order_id);
		if (!$order) {
			return false;
		}

		if ((int) $order->get_meta('_rfb_booking_id')) {
			return true;
		}

		foreach ($order->get_items() as $item) {
			if ((int) $item->get_meta('_rfb_booking_id')) {
				return true;
			}
		}

		return false;
	}

	private static function is_ride_thankyou_screen(): bool {
		if (!function_exists('is_order_received_page') || !is_order_received_page()) {
			return false;
		}

		$order_id = absint(get_query_var('order-received'));
		return (bool) self::ride_order_context($order_id);
	}

	private static function add_booking_line_item_meta(\WC_Order_Item_Product $item, object $booking): void {
		$item->add_meta_data(__('Pickup', 'ridefleet-booking'), sanitize_text_field((string) $booking->pickup_address), true);
		$item->add_meta_data(__('Drop-off', 'ridefleet-booking'), sanitize_text_field((string) $booking->dropoff_address), true);
		$pickup_time = '';
		if (!empty($booking->pickup_at)) {
			$ts = strtotime((string) $booking->pickup_at);
			$pickup_time = $ts ? wp_date('D, M j, Y H:i', $ts) : sanitize_text_field((string) $booking->pickup_at);
		}
		$item->add_meta_data(__('Pickup Time', 'ridefleet-booking'), $pickup_time, true);
		$item->add_meta_data(__('Passengers', 'ridefleet-booking'), absint($booking->passengers), true);
		$item->add_meta_data(__('Luggage', 'ridefleet-booking'), absint($booking->luggage), true);
	}

	private static function invoice_requested(int $booking_id): bool {
		$details = self::booking_meta($booking_id, '_invoice_details', []);
		return is_array($details) && 'yes' === ($details['enabled'] ?? '');
	}

	private static function booking_meta(int $booking_id, string $key, mixed $default = null): mixed {
		global $wpdb;

		$raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}rfb_booking_meta WHERE booking_id = %d AND meta_key = %s ORDER BY id DESC LIMIT 1", $booking_id, $key));
		if (null === $raw) {
			return $default;
		}

		$decoded = json_decode((string) $raw, true);
		return is_array($decoded) ? $decoded : $raw;
	}

	private static function save_booking_meta(int $booking_id, string $key, mixed $value): void {
		global $wpdb;

		$existing = self::booking_meta($booking_id, $key, []);
		if (is_array($existing) && is_array($value)) {
			$value = array_merge($existing, $value);
		}

		$wpdb->delete($wpdb->prefix . 'rfb_booking_meta', ['booking_id' => $booking_id, 'meta_key' => $key], ['%d', '%s']);
		$wpdb->insert(
			$wpdb->prefix . 'rfb_booking_meta',
			[
				'booking_id' => $booking_id,
				'meta_key' => $key,
				'meta_value' => is_scalar($value) ? (string) $value : wp_json_encode($value),
			],
			['%d', '%s', '%s']
		);
	}

	private static function apply_booking_customer_to_order(\WC_Order $order, object $booking): void {
		$customer = self::booking_customer($booking);
		if (!$customer) {
			return;
		}

		$order->set_billing_first_name(sanitize_text_field((string) ($customer->first_name ?? '')));
		$order->set_billing_last_name(sanitize_text_field((string) ($customer->last_name ?? '')));
		if (!empty($customer->email)) {
			$order->set_billing_email(sanitize_email((string) $customer->email));
		}
		if (!empty($customer->phone)) {
			$order->set_billing_phone(sanitize_text_field((string) $customer->phone));
		}
	}

	private static function booking_customer(object $booking): ?object {
		$customer_id = (int) ($booking->customer_id ?? 0);
		return $customer_id ? BookingRepository::customer($customer_id) : null;
	}

	private static function customer_name(?object $customer): string {
		if (!$customer) {
			return __('RideFleet customer', 'ridefleet-booking');
		}

		$name = trim((string) ($customer->first_name ?? '') . ' ' . (string) ($customer->last_name ?? ''));
		return $name ?: __('RideFleet customer', 'ridefleet-booking');
	}
}
