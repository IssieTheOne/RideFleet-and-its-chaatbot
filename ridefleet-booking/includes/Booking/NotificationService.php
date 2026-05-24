<?php
/**
 * Booking emails — HTML templates with full booking details.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class NotificationService {

	// ── Public API ─────────────────────────────────────────────────────────────

	public static function booking_created(int $booking_id): void {
		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			return;
		}

		$customer         = BookingRepository::customer((int) $booking->customer_id);
		// Use the dedicated dispatcher/notification email from settings; fall back to WP site admin email.
		$dispatcher_email = trim((string) Options::get('notification_email', ''));
		if ('' === $dispatcher_email) {
			$dispatcher_email = (string) get_option('admin_email', '');
		}
		$tokens = self::build_token_map($booking, $customer);

		$subject = self::render_template(
			(string) Options::get('email_templates.booking_subject', 'Booking confirmed — {booking_number}'),
			$tokens
		);

		$body_template = (string) Options::get('email_templates.booking_body', '');
		$message = '' !== $body_template
			? self::render_template($body_template, $tokens)
			: self::default_booking_html($tokens);

		$headers = self::html_headers();

		// Dispatcher copy — always sent, richer subject so it stands out in inbox.
		if ($dispatcher_email) {
			$source_label = sanitize_text_field((string) ($booking->source ?? 'unknown'));
			wp_mail(
				$dispatcher_email,
				'🚕 New Booking [' . strtoupper($source_label) . '] — ' . $tokens['{booking_number}'],
				$message,
				$headers
			);
		}
		// Customer copy — only when email is on file.
		if ($customer && !empty($customer->email)) {
			wp_mail($customer->email, $subject, $message, $headers);
		}
	}

	public static function booking_completed(int $booking_id): void {
		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			return;
		}

		$customer = BookingRepository::customer((int) $booking->customer_id);
		if (!$customer || !$customer->email) {
			return;
		}

		$tokens  = self::build_token_map($booking, $customer);
		$subject = self::render_template(
			(string) Options::get('email_templates.review_subject', 'How was your ride? — {booking_number}'),
			$tokens
		);

		$body_template = (string) Options::get('email_templates.review_body', '');
		$message = '' !== $body_template
			? self::render_template($body_template, $tokens)
			: self::default_review_html($tokens);

		wp_mail($customer->email, $subject, $message, self::html_headers());
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/** @return array<string,string> */
	private static function build_token_map(object $booking, ?object $customer): array {
		$currency      = (string) $booking->currency;
		$total         = number_format((float) $booking->total, 2);
		$pickup_ts     = $booking->pickup_at ? strtotime((string) $booking->pickup_at) : 0;
		$pickup_date   = $pickup_ts ? date_i18n('l, F j, Y', $pickup_ts) : '—';
		$pickup_time   = $pickup_ts ? date_i18n('g:i A', $pickup_ts) : '—';
		$customer_name = $customer
			? trim((string) $customer->first_name . ' ' . (string) $customer->last_name)
			: '—';
		$extras_text   = self::get_extras_text((int) $booking->id, $currency);
		$route_url     = self::google_maps_url(
			(string) $booking->pickup_address,
			(string) $booking->dropoff_address
		);
		$review_link   = (string) Options::get('review_link', '');

		return [
			'{booking_number}'  => (string) $booking->booking_number,
			'{pickup_address}'  => (string) $booking->pickup_address,
			'{dropoff_address}' => (string) $booking->dropoff_address,
			'{currency}'        => $currency,
			'{total}'           => $currency . ' ' . $total,
			'{customer_name}'   => $customer_name,
			'{pickup_date}'     => $pickup_date,
			'{pickup_time}'     => $pickup_time,
			'{extras}'          => $extras_text,
			'{route_url}'       => esc_url($route_url),
			'{review_link}'     => esc_url($review_link),
			'{company_name}'    => (string) Options::get('company_name', get_bloginfo('name')),
			'{company_phone}'   => (string) Options::get('company_phone', ''),
			'{company_email}'   => (string) Options::get('admin_email', get_option('admin_email')),
		];
	}

	/** @param array<string,string> $tokens */
	private static function render_template(string $template, array $tokens): string {
		return strtr($template, $tokens);
	}

	/** @return string[] */
	private static function html_headers(): array {
		return [
			'Content-Type: text/html; charset=UTF-8',
			'MIME-Version: 1.0',
		];
	}

	private static function get_extras_text(int $booking_id, string $currency): string {
		global $wpdb;
		$raw = $wpdb->get_var($wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->prefix}rfb_booking_meta WHERE booking_id = %d AND meta_key = '_selected_extras' ORDER BY id DESC LIMIT 1",
			$booking_id
		));
		if (!$raw) {
			return 'None';
		}
		$extras = json_decode((string) $raw, true);
		if (!is_array($extras) || empty($extras)) {
			return 'None';
		}
		$lines = [];
		foreach ($extras as $extra) {
			if (is_array($extra)) {
				$name  = (string) ($extra['name'] ?? '');
				$price = isset($extra['price']) ? (float) $extra['price'] : null;
				if ($name) {
					$lines[] = $price !== null && $price > 0
						? $name . ' (' . $currency . ' ' . number_format($price, 2) . ')'
						: $name;
				}
			} elseif (is_numeric($extra)) {
				// Stored as post ID
				$post = get_post((int) $extra);
				if ($post) {
					$price = (float) get_post_meta((int) $extra, 'rfb_price', true);
					$lines[] = $price > 0
						? get_the_title($post) . ' (' . $currency . ' ' . number_format($price, 2) . ')'
						: get_the_title($post);
				}
			} elseif (is_string($extra) && '' !== $extra) {
				$lines[] = $extra;
			}
		}
		return $lines ? implode(', ', $lines) : 'None';
	}

	private static function google_maps_url(string $origin, string $destination): string {
		return 'https://www.google.com/maps/dir/' . urlencode($origin) . '/' . urlencode($destination);
	}

	private static function google_cal_url(array $tokens): string {
		$pickup_date = $tokens['{pickup_date}'] ?? '';
		$pickup_ts   = $pickup_date ? strtotime($pickup_date) : 0;
		if (!$pickup_ts) {
			return '#';
		}
		$end_ts = $pickup_ts + 3600;
		$dtstart = gmdate('Ymd\THis\Z', $pickup_ts);
		$dtend   = gmdate('Ymd\THis\Z', $end_ts);
		$title   = urlencode('Transfer: ' . ($tokens['{pickup_address}'] ?? '') . ' → ' . ($tokens['{dropoff_address}'] ?? ''));
		$details = urlencode('Booking ' . ($tokens['{booking_number}'] ?? '') . '. Booked via ' . ($tokens['{company_name}'] ?? ''));
		return "https://calendar.google.com/calendar/r/eventedit?text={$title}&dates={$dtstart}/{$dtend}&details={$details}";
	}

	private static function apple_cal_url(array $tokens): string {
		$pickup_date = $tokens['{pickup_date}'] ?? '';
		$pickup_ts   = $pickup_date ? strtotime($pickup_date) : 0;
		if (!$pickup_ts) {
			return '#';
		}
		$end_ts  = $pickup_ts + 3600;
		$dtstart = gmdate('Ymd\THis\Z', $pickup_ts);
		$dtend   = gmdate('Ymd\THis\Z', $end_ts);
		$uid     = sanitize_key($tokens['{booking_number}'] ?? '') . '@' . sanitize_key(get_bloginfo('name'));
		$summary = 'Transfer: ' . ($tokens['{pickup_address}'] ?? '') . ' → ' . ($tokens['{dropoff_address}'] ?? '');
		$ics     = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART:{$dtstart}\r\nDTEND:{$dtend}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\nEND:VCALENDAR";
		// Return a data: URI that email clients can open inline
		return 'data:text/calendar;charset=utf8,' . rawurlencode($ics);
	}

	// ── HTML Email Templates ────────────────────────────────────────────────────

	/** @param array<string,string> $tokens */
	private static function default_booking_html(array $tokens): string {
		$company      = esc_html($tokens['{company_name}']);
		$booking_num  = esc_html($tokens['{booking_number}']);
		$customer     = esc_html($tokens['{customer_name}']);
		$pickup_addr  = esc_html($tokens['{pickup_address}']);
		$dropoff_addr = esc_html($tokens['{dropoff_address}']);
		$pickup_date  = esc_html($tokens['{pickup_date}']);
		$pickup_time  = esc_html($tokens['{pickup_time}']);
		$total        = esc_html($tokens['{total}']);
		$extras       = esc_html($tokens['{extras}']);
		$route_url    = esc_url($tokens['{route_url}']);
		$gcal_url     = esc_url(self::google_cal_url($tokens));
		$acal_url     = esc_url(self::apple_cal_url($tokens));
		$company_phone = esc_html($tokens['{company_phone}']);
		$company_email = esc_html($tokens['{company_email}']);

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Booking Confirmed — {$booking_num}</title>
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
  .wrap { max-width:600px; margin:32px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,.08); }
  .header { background:linear-gradient(135deg,#0f766e 0%,#115e59 100%); padding:36px 40px; text-align:center; }
  .header-icon { font-size:40px; margin-bottom:8px; }
  .header h1 { margin:0; color:#fff; font-size:24px; font-weight:700; letter-spacing:-.3px; }
  .header p { margin:6px 0 0; color:rgba(255,255,255,.75); font-size:14px; }
  .body { padding:36px 40px; }
  .greeting { font-size:16px; color:#1e293b; margin-bottom:24px; }
  .booking-ref { display:inline-block; background:#f0fdfa; border:1.5px solid #99f6e4; color:#0f766e; font-size:13px; font-weight:700; letter-spacing:.06em; padding:6px 16px; border-radius:20px; margin-bottom:28px; }
  .details-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:24px; }
  .details-row { display:flex; align-items:flex-start; padding:14px 18px; border-bottom:1px solid #e2e8f0; gap:14px; }
  .details-row:last-child { border-bottom:none; }
  .details-icon { font-size:18px; flex-shrink:0; margin-top:1px; }
  .details-label { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:3px; }
  .details-value { font-size:14px; color:#1e293b; font-weight:500; line-height:1.4; }
  .route-divider { display:flex; align-items:center; margin:0 0 24px; gap:12px; }
  .route-divider-line { flex:1; height:1px; background:#e2e8f0; }
  .route-divider-label { font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; }
  .total-row { background:#f0fdfa; border:1.5px solid #99f6e4; border-radius:10px; padding:16px 18px; display:flex; justify-content:space-between; align-items:center; margin-bottom:28px; }
  .total-label { font-size:13px; color:#0f766e; font-weight:600; }
  .total-amount { font-size:22px; color:#0f766e; font-weight:800; }
  .action-buttons { margin-bottom:28px; }
  .action-buttons p { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin:0 0 10px; }
  .btn-row { display:flex; gap:10px; flex-wrap:wrap; }
  .btn { display:inline-flex; align-items:center; gap:7px; padding:10px 18px; border-radius:22px; font-size:13px; font-weight:600; text-decoration:none; }
  .btn-maps { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
  .btn-gcal { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; }
  .btn-acal { background:#f8f8f8; color:#1c1c1e; border:1px solid #d1d1d6; }
  .footer { background:#f8fafc; border-top:1px solid #e2e8f0; padding:24px 40px; text-align:center; }
  .footer p { margin:0; font-size:12px; color:#94a3b8; line-height:1.7; }
  .footer a { color:#0f766e; text-decoration:none; }
  @media(max-width:640px) {
    .body, .header, .footer { padding:24px 20px; }
    .details-row { flex-direction:column; gap:4px; }
    .btn-row { flex-direction:column; }
    .btn { justify-content:center; }
  }
</style>
</head>
<body>
<div class="wrap">
  <!-- Header -->
  <div class="header">
    <div class="header-icon">🚖</div>
    <h1>Booking Confirmed!</h1>
    <p>Your ride has been successfully booked.</p>
  </div>

  <!-- Body -->
  <div class="body">
    <p class="greeting">Hi <strong>{$customer}</strong>,<br>
    Thank you for choosing <strong>{$company}</strong>. Your booking is confirmed and we look forward to driving you.</p>

    <div class="booking-ref">Booking #{$booking_num}</div>

    <!-- Trip details -->
    <div class="details-card">
      <div class="details-row">
        <div class="details-icon">📍</div>
        <div>
          <div class="details-label">Pickup</div>
          <div class="details-value">{$pickup_addr}</div>
        </div>
      </div>
      <div class="details-row">
        <div class="details-icon">🏁</div>
        <div>
          <div class="details-label">Drop-off</div>
          <div class="details-value">{$dropoff_addr}</div>
        </div>
      </div>
      <div class="details-row">
        <div class="details-icon">📅</div>
        <div>
          <div class="details-label">Date &amp; Time</div>
          <div class="details-value">{$pickup_date} at <strong>{$pickup_time}</strong></div>
        </div>
      </div>
      <div class="details-row">
        <div class="details-icon">✨</div>
        <div>
          <div class="details-label">Extras</div>
          <div class="details-value">{$extras}</div>
        </div>
      </div>
    </div>

    <!-- Total -->
    <div class="total-row">
      <span class="total-label">Total Amount</span>
      <span class="total-amount">{$total}</span>
    </div>

    <!-- Action buttons -->
    <div class="action-buttons">
      <p>Helpful links</p>
      <div class="btn-row">
        <a href="{$route_url}" class="btn btn-maps" target="_blank" rel="noopener">
          🗺️ View Route on Maps
        </a>
        <a href="{$gcal_url}" class="btn btn-gcal" target="_blank" rel="noopener">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="4" width="18" height="17" rx="2" stroke="#166534" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="#166534" stroke-width="2" stroke-linecap="round"/><path d="M9.5 15.5l2 2 4-4" stroke="#166534" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          Add to Google Calendar
        </a>
        <a href="{$acal_url}" class="btn btn-acal" target="_blank" rel="noopener">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 4C8 4 5 7 5 11c0 3.87 2.54 7.16 6.5 8.82a1 1 0 00.74.02C16.14 18.19 19 15.03 19 11c0-4-3-7-7-7z" stroke="#1c1c1e" stroke-width="2"/><path d="M12 4V2M12 22v-1.18" stroke="#1c1c1e" stroke-width="2" stroke-linecap="round"/></svg>
          Add to Apple Calendar
        </a>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <p>
      Questions? Contact us at
      <a href="mailto:{$company_email}">{$company_email}</a>
      {$company_phone}
      <br>
      <strong>{$company}</strong> · Powered by RideFleet
    </p>
  </div>
</div>
</body>
</html>
HTML;
	}

	/** @param array<string,string> $tokens */
	private static function default_review_html(array $tokens): string {
		$company      = esc_html($tokens['{company_name}']);
		$booking_num  = esc_html($tokens['{booking_number}']);
		$customer     = esc_html($tokens['{customer_name}']);
		$pickup_addr  = esc_html($tokens['{pickup_address}']);
		$dropoff_addr = esc_html($tokens['{dropoff_address}']);
		$pickup_date  = esc_html($tokens['{pickup_date}']);
		$review_link  = esc_url($tokens['{review_link}']);
		$company_email = esc_html($tokens['{company_email}']);
		$has_review   = '' !== $review_link && '#' !== $review_link;

		$review_btn = $has_review
			? '<a href="' . $review_link . '" class="btn-review" target="_blank" rel="noopener">⭐ Leave a Google Review</a>'
			: '<p style="color:#94a3b8;font-size:13px;">(Review link coming soon — thank you for your patience!)</p>';

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>How was your ride? — {$booking_num}</title>
<style>
  body { margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; }
  .wrap { max-width:600px; margin:32px auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,.08); }
  .header { background:linear-gradient(135deg,#7c3aed 0%,#5b21b6 100%); padding:36px 40px; text-align:center; }
  .header-icon { font-size:44px; margin-bottom:8px; }
  .header h1 { margin:0; color:#fff; font-size:24px; font-weight:700; }
  .header p { margin:6px 0 0; color:rgba(255,255,255,.75); font-size:14px; }
  .body { padding:36px 40px; }
  .greeting { font-size:16px; color:#1e293b; margin-bottom:24px; line-height:1.6; }
  .stars { font-size:32px; letter-spacing:4px; text-align:center; margin:0 0 24px; }
  .trip-summary { background:#faf5ff; border:1px solid #e9d5ff; border-radius:10px; padding:18px; margin-bottom:28px; }
  .trip-summary h3 { margin:0 0 12px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#7c3aed; }
  .trip-row { display:flex; gap:10px; align-items:flex-start; margin-bottom:8px; }
  .trip-row:last-child { margin-bottom:0; }
  .trip-icon { font-size:15px; flex-shrink:0; }
  .trip-text { font-size:13px; color:#1e293b; line-height:1.4; }
  .cta-section { text-align:center; margin-bottom:28px; }
  .cta-section p { font-size:15px; color:#374151; margin-bottom:16px; line-height:1.6; }
  .btn-review { display:inline-block; background:linear-gradient(135deg,#f59e0b,#d97706); color:#fff; font-size:15px; font-weight:700; padding:14px 32px; border-radius:28px; text-decoration:none; box-shadow:0 4px 14px rgba(245,158,11,.35); }
  .divider { border:none; border-top:1px solid #e2e8f0; margin:28px 0; }
  .testimonial { font-size:13px; color:#64748b; font-style:italic; text-align:center; line-height:1.7; }
  .footer { background:#f8fafc; border-top:1px solid #e2e8f0; padding:24px 40px; text-align:center; }
  .footer p { margin:0; font-size:12px; color:#94a3b8; line-height:1.7; }
  .footer a { color:#7c3aed; text-decoration:none; }
  @media(max-width:640px){
    .body, .header, .footer { padding:24px 20px; }
  }
</style>
</head>
<body>
<div class="wrap">
  <!-- Header -->
  <div class="header">
    <div class="header-icon">⭐</div>
    <h1>How was your ride?</h1>
    <p>We'd love to hear about your experience.</p>
  </div>

  <!-- Body -->
  <div class="body">
    <p class="greeting">Hi <strong>{$customer}</strong>,<br>
    Thank you for choosing <strong>{$company}</strong> for your recent transfer. We hope everything went smoothly and you had a comfortable journey!</p>

    <div class="stars">⭐⭐⭐⭐⭐</div>

    <!-- Trip summary -->
    <div class="trip-summary">
      <h3>Your Recent Trip</h3>
      <div class="trip-row">
        <span class="trip-icon">🗓️</span>
        <span class="trip-text">{$pickup_date} · Booking <strong>#{$booking_num}</strong></span>
      </div>
      <div class="trip-row">
        <span class="trip-icon">📍</span>
        <span class="trip-text">{$pickup_addr}</span>
      </div>
      <div class="trip-row">
        <span class="trip-icon">🏁</span>
        <span class="trip-text">{$dropoff_addr}</span>
      </div>
    </div>

    <!-- CTA -->
    <div class="cta-section">
      <p>If you enjoyed your ride, a quick Google review means the world to us and helps other travellers find us.</p>
      {$review_btn}
    </div>

    <hr class="divider">
    <p class="testimonial">"Your review takes only 60 seconds and helps us keep delivering great service to every passenger."</p>
  </div>

  <!-- Footer -->
  <div class="footer">
    <p>
      Questions or feedback? Reply to this email or contact us at
      <a href="mailto:{$company_email}">{$company_email}</a><br>
      <strong>{$company}</strong> · Thank you for riding with us.
    </p>
  </div>
</div>
</body>
</html>
HTML;
	}
}
