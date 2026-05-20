<?php
/**
 * Admin email notifications for new change requests and fare negotiations.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Admin;

use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class Notifier {
	public static function on_new_change_request(int $request_id, array $session, string $request_text): void {
		if (!self::enabled()) {
			return;
		}

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$name = (string) ($data['customer_name'] ?? __('unknown', 'ridefleet-ai-chatbot'));
		$phone = (string) ($data['customer_phone'] ?? '');
		$booking_id = (string) ($data['last_booking_id'] ?? '');

		$subject = sprintf(
			/* translators: %1$s site name, %2$d request id */
			__('[%1$s] New booking change request #%2$d', 'ridefleet-ai-chatbot'),
			(string) get_bloginfo('name'),
			$request_id
		);

		$lines = [
			__('A customer submitted a booking change request through the chatbot.', 'ridefleet-ai-chatbot'),
			'',
			sprintf(__('Customer: %s', 'ridefleet-ai-chatbot'), $name),
			sprintf(__('Phone: %s', 'ridefleet-ai-chatbot'), $phone ?: '—'),
			sprintf(__('Booking: %s', 'ridefleet-ai-chatbot'), $booking_id ?: '—'),
			'',
			__('Requested change:', 'ridefleet-ai-chatbot'),
			$request_text,
			'',
			__('Review and respond in the admin panel:', 'ridefleet-ai-chatbot'),
			admin_url('admin.php?page=ridefleet-ai-chatbot-changes'),
		];

		self::send(implode("\n", $lines), $subject);
	}

	public static function on_new_price_negotiation(int $request_id, array $session): void {
		if (!self::enabled()) {
			return;
		}

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$quote = is_array($session['last_quote'] ?? null) ? $session['last_quote'] : [];
		$name = (string) ($data['customer_name'] ?? __('unknown', 'ridefleet-ai-chatbot'));
		$phone = (string) ($data['customer_phone'] ?? '');
		$pickup = (string) ($data['pickup_address'] ?? '—');
		$dropoff = (string) ($data['dropoff_address'] ?? '—');
		$original = (float) ($quote['final_price'] ?? 0);
		$proposed = (float) ($data['proposed_price'] ?? 0);
		$currency = (string) ($quote['currency'] ?? 'USD');
		$discount = $original > 0 ? round((($original - $proposed) / $original) * 100, 1) : 0;

		$subject = sprintf(
			/* translators: %1$s site name, %2$d request id */
			__('[%1$s] New fare negotiation request #%2$d', 'ridefleet-ai-chatbot'),
			(string) get_bloginfo('name'),
			$request_id
		);

		$lines = [
			__('A customer has proposed a lower fare through the chatbot.', 'ridefleet-ai-chatbot'),
			'',
			sprintf(__('Customer: %s', 'ridefleet-ai-chatbot'), $name),
			sprintf(__('Phone: %s', 'ridefleet-ai-chatbot'), $phone ?: '—'),
			sprintf(__('Route: %1$s → %2$s', 'ridefleet-ai-chatbot'), $pickup, $dropoff),
			sprintf(__('Original fare: %1$s %2$.2f', 'ridefleet-ai-chatbot'), $currency, $original),
			sprintf(__('Proposed fare: %1$s %2$.2f (%3$.1f%% lower)', 'ridefleet-ai-chatbot'), $currency, $proposed, $discount),
			'',
			__('Approve, reject, or counteroffer in the admin panel:', 'ridefleet-ai-chatbot'),
			admin_url('admin.php?page=ridefleet-ai-chatbot-changes'),
		];

		self::send(implode("\n", $lines), $subject);
	}

	private static function enabled(): bool {
		return !empty(Options::get('notifications_enabled', 1));
	}

	private static function send(string $body, string $subject): void {
		$to = sanitize_email((string) Options::get('notification_email', ''));
		if ('' === $to || !is_email($to)) {
			$to = (string) get_option('admin_email');
		}

		if ('' === $to) {
			return;
		}

		wp_mail($to, $subject, $body);
	}
}
