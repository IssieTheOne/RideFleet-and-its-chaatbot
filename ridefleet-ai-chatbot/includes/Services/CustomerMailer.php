<?php
/**
 * Sends booking confirmation emails to customers.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

if (!defined('ABSPATH')) {
	exit;
}

final class CustomerMailer {

	/**
	 * Sends a booking confirmation to the customer's email address.
	 * Returns false silently if no email is available.
	 */
	public static function send_booking_confirmation(array $session, string $booking_ref): bool {
		$data  = $session['collected_data'] ?? [];
		$email = sanitize_email((string) ($data['customer_email'] ?? ''));
		if (!$email || !is_email($email)) {
			return false;
		}

		$site_name   = get_bloginfo('name');
		$name        = sanitize_text_field((string) ($data['customer_name']  ?? ''));
		$pickup      = sanitize_text_field((string) ($data['pickup_address']  ?? ''));
		$dropoff     = sanitize_text_field((string) ($data['dropoff_address'] ?? ''));
		$pickup_time = sanitize_text_field((string) ($data['pickup_time']     ?? ''));
		$passengers  = max(1, (int) ($data['passengers'] ?? 1));
		$luggage     = max(0, (int) ($data['luggage']    ?? 0));
		$lang        = (string) ($data['language'] ?? 'en');
		$quote       = is_array($session['last_quote'] ?? null) ? $session['last_quote'] : [];
		$currency    = (string) ($quote['currency'] ?? 'USD');
		$price       = isset($quote['final_price']) ? sprintf('%s %.2f', $currency, (float) $quote['final_price']) : '';
		$asap        = !empty($data['pickup_asap']);
		$cancel_url  = '';
		if (class_exists('\\RideFleetBooking\\Admin\\CancellationPageGenerator')) {
			$cancel_url = \RideFleetBooking\Admin\CancellationPageGenerator::page_url();
		}

		// Format pickup time
		if ($asap) {
			$formatted_time = __('As soon as possible', 'ridefleet-ai-chatbot');
		} elseif ($pickup_time) {
			$ts             = strtotime($pickup_time);
			$formatted_time = $ts ? (string) wp_date('l, F j Y \a\t g:i A', $ts) : $pickup_time;
		} else {
			$formatted_time = '—';
		}

		$subject = $booking_ref
			? sprintf(__('[%1$s] Booking confirmed — %2$s', 'ridefleet-ai-chatbot'), $site_name, $booking_ref)
			: sprintf(__('[%s] Your ride request is received', 'ridefleet-ai-chatbot'), $site_name);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
		];

		return wp_mail($email, $subject, self::html_body([
			'site_name'   => $site_name,
			'name'        => $name,
			'booking_ref' => $booking_ref,
			'pickup'      => $pickup,
			'dropoff'     => $dropoff,
			'time'        => $formatted_time,
			'passengers'  => $passengers,
			'luggage'     => $luggage,
			'price'       => $price,
			'cancel_url'  => $cancel_url,
		]), $headers);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// HTML template
	// ─────────────────────────────────────────────────────────────────────────

	private static function html_body(array $d): string {
		$primary   = '#0f766e';
		$greeting  = $d['name']
			? sprintf(__('Hi %s,', 'ridefleet-ai-chatbot'), esc_html($d['name']))
			: __('Hi there,', 'ridefleet-ai-chatbot');
		$ref_block = $d['booking_ref']
			? '<div style="display:inline-block;background:#f0fdf9;border:1px solid #a7f3d0;border-radius:8px;padding:10px 22px;margin:16px 0;font-size:20px;font-weight:700;color:' . $primary . ';letter-spacing:2px;">' . esc_html($d['booking_ref']) . '</div>'
			: '';
		$price_row = $d['price']
			? '<tr><td style="padding:7px 0;color:#6b7280;width:140px;">' . esc_html__('Fare', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;font-weight:600;">' . esc_html($d['price']) . '</td></tr>'
			: '';
		$cancel    = $d['cancel_url']
			? '<p style="margin-top:24px;font-size:13px;color:#6b7280;">' . sprintf(
				/* translators: %1$s = URL, %2$s = colour */
				__('Need to cancel? Visit our <a href="%1$s" style="color:%2$s;">cancellation page</a>.', 'ridefleet-ai-chatbot'),
				esc_url($d['cancel_url']),
				$primary
			) . '</p>'
			: '';

		return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>' . esc_html($d['site_name']) . '</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:36px 16px;">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.07);">
  <!-- Header -->
  <tr><td style="background:' . $primary . ';padding:28px 32px;">
    <h1 style="margin:0;color:#fff;font-size:22px;font-weight:700;">' . esc_html($d['site_name']) . '</h1>
    <p style="margin:4px 0 0;color:#a7f3d0;font-size:13px;">' . esc_html__('Booking Confirmation', 'ridefleet-ai-chatbot') . '</p>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:32px;">
    <p style="margin:0 0 6px;font-size:16px;color:#111;">' . $greeting . '</p>
    <p style="margin:0 0 20px;color:#374151;font-size:14px;">' . esc_html__('Your ride request has been received. Here is a summary:', 'ridefleet-ai-chatbot') . '</p>
    ' . $ref_block . '
    <table width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;font-size:14px;">
      <tr><td style="padding:7px 0;color:#6b7280;width:140px;">' . esc_html__('From', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;font-weight:600;">' . esc_html($d['pickup']) . '</td></tr>
      <tr><td style="padding:7px 0;color:#6b7280;">' . esc_html__('To', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;font-weight:600;">' . esc_html($d['dropoff']) . '</td></tr>
      <tr><td style="padding:7px 0;color:#6b7280;">' . esc_html__('Pick-up time', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;font-weight:600;">' . esc_html($d['time']) . '</td></tr>
      <tr><td style="padding:7px 0;color:#6b7280;">' . esc_html__('Passengers', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;">' . (int) $d['passengers'] . '</td></tr>
      <tr><td style="padding:7px 0;color:#6b7280;">' . esc_html__('Luggage', 'ridefleet-ai-chatbot') . '</td><td style="padding:7px 0;">' . (int) $d['luggage'] . '</td></tr>
      ' . $price_row . '
    </table>
    <p style="margin-top:24px;font-size:14px;color:#374151;">' . esc_html__('Our dispatch team will confirm your booking shortly. We will be in touch if any details need adjusting.', 'ridefleet-ai-chatbot') . '</p>
    ' . $cancel . '
  </td></tr>
  <!-- Footer -->
  <tr><td style="background:#f8fafc;padding:16px 32px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af;">
    ' . esc_html($d['site_name']) . ' &bull; <a href="' . esc_url(get_bloginfo('url')) . '" style="color:#9ca3af;">' . esc_html(get_bloginfo('url')) . '</a>
  </td></tr>
</table>
</td></tr></table>
</body></html>';
	}
}
