<?php
/**
 * Booking emails.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class NotificationService {
	public static function booking_created(int $booking_id): void {
		$booking = BookingRepository::find($booking_id);
		if (!$booking) {
			return;
		}

		$admin_email = Options::get('admin_email', get_option('admin_email'));
		$customer = BookingRepository::customer((int) $booking->customer_id);
		$subject = self::render_template((string) Options::get('email_templates.booking_subject', 'Your booking request {booking_number}'), $booking);
		$message = self::render_template((string) Options::get('email_templates.booking_body', self::default_body()), $booking);

		if ($admin_email) {
			wp_mail($admin_email, $subject, $message);
		}

		if ($customer && $customer->email) {
			wp_mail($customer->email, $subject, $message);
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

		$subject = self::render_template((string) Options::get('email_templates.review_subject', 'How was your ride with RideFleet? {booking_number}'), $booking);
		$message = self::render_template((string) Options::get('email_templates.review_body', self::default_review_body()), $booking);

		wp_mail($customer->email, $subject, $message);
	}

	private static function render_template(string $template, object $booking): string {
		return strtr(
			$template,
			[
				'{booking_number}' => (string) $booking->booking_number,
				'{pickup_address}' => (string) $booking->pickup_address,
				'{dropoff_address}' => (string) $booking->dropoff_address,
				'{currency}' => (string) $booking->currency,
				'{total}' => number_format_i18n((float) $booking->total, 2),
			]
		);
	}

	private static function default_body(): string {
		return "A booking request was created.\n\nBooking: {booking_number}\nPickup: {pickup_address}\nDrop-off: {dropoff_address}\nTotal: {currency} {total}";
	}

	private static function default_review_body(): string {
		return "Thank you for choosing RideFleet!\n\nWe'd like to hear about your experience:\n\nBooking: {booking_number}\nRoute: {pickup_address} -> {dropoff_address}\n\nPlease let us know how we did and if you'd recommend us to others.\n\nBest regards,\nRideFleet";
	}
}
