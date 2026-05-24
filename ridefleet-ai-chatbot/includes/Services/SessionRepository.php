<?php
/**
 * Chat session persistence.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Services;

use RideFleetAIChatbot\Admin\Notifier;
use RideFleetAIChatbot\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class SessionRepository {
	public static function cleanup_old_sessions(): void {
		global $wpdb;

		$days = max(7, (int) Options::get('data_retention_days', 90));
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

		$sessions_table = $wpdb->prefix . 'rfac_chat_sessions';
		$messages_table = $wpdb->prefix . 'rfac_chat_messages';
		$diagnostics_table = $wpdb->prefix . 'rfac_diagnostic_events';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE m FROM {$messages_table} m
				 INNER JOIN {$sessions_table} s ON m.session_id = s.id
				 WHERE s.updated_at < %s",
				$cutoff
			)
		);
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $diagnostics_table)) === $diagnostics_table) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE d FROM {$diagnostics_table} d
					 INNER JOIN {$sessions_table} s ON d.session_id = s.id
					 WHERE s.updated_at < %s",
					$cutoff
				)
			);
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$sessions_table} WHERE updated_at < %s",
				$cutoff
			)
		);
	}

	public function get_or_create(string $session_key): array {
		global $wpdb;

		$session_key = sanitize_key($session_key ?: wp_generate_uuid4());
		$table = $wpdb->prefix . 'rfac_chat_sessions';
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE session_key = %s", $session_key), ARRAY_A);

		if (!$row) {
			$now = current_time('mysql');
			$wpdb->insert(
				$table,
				[
					'session_key' => $session_key,
					'state' => 'greeting',
					'collected_data' => wp_json_encode([]),
					'last_quote' => wp_json_encode([]),
					'created_at' => $now,
					'updated_at' => $now,
				],
				['%s', '%s', '%s', '%s', '%s', '%s']
			);
			$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $wpdb->insert_id), ARRAY_A);
		}

		return $this->normalize($row ?: []);
	}

	public function update(array $session): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'rfac_chat_sessions',
			[
				'state' => sanitize_key((string) $session['state']),
				'collected_data' => wp_json_encode($session['collected_data']),
				'last_quote' => wp_json_encode($session['last_quote']),
				'updated_at' => current_time('mysql'),
			],
			['id' => (int) $session['id']],
			['%s', '%s', '%s', '%s'],
			['%d']
		);
	}

	public function add_message(int $session_id, string $role, string $message, string $admin_summary = '', array $meta = []): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'rfac_chat_messages',
			[
				'session_id' => $session_id,
				'role' => in_array($role, ['user', 'assistant'], true) ? $role : 'user',
				'message' => sanitize_textarea_field($message),
				'admin_summary' => sanitize_textarea_field($admin_summary ?: $message),
				'detected_language' => sanitize_key((string) ($meta['detected_language'] ?? '')),
				'intent' => sanitize_key((string) ($meta['intent'] ?? '')),
				'extracted_fields' => wp_json_encode(is_array($meta['extracted_fields'] ?? null) ? $meta['extracted_fields'] : []),
				'created_at' => current_time('mysql'),
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
		);
	}

	public function recent_messages(int $session_id, int $limit = 12): array {
		global $wpdb;

		$table = $wpdb->prefix . 'rfac_chat_messages';
		$rows = $wpdb->get_results($wpdb->prepare("SELECT role, message FROM {$table} WHERE session_id = %d ORDER BY id DESC LIMIT %d", $session_id, $limit), ARRAY_A);
		$rows = array_reverse(is_array($rows) ? $rows : []);

		return array_map(
			static fn(array $row): array => [
				'role' => (string) $row['role'],
				'content' => (string) $row['message'],
			],
			$rows
		);
	}

	public function log_booking(?int $session_id, array $payload, array $result): void {
		global $wpdb;

		$status = 'failed';
		if (!empty($result['success'])) {
			$status = (!empty($payload['requires_manual_dispatch']) || !empty($result['manual_dispatch'])) ? 'pending_dispatch' : 'confirmed';
			if (!empty($result['booking_status']) && is_string($result['booking_status'])) {
				$status = sanitize_key($result['booking_status']);
			} elseif (!empty($result['status']) && is_string($result['status']) && !is_numeric($result['status'])) {
				$status = sanitize_key($result['status']);
			}
		}

		$wpdb->insert(
			$wpdb->prefix . 'rfac_booking_events',
			[
				'session_id' => $session_id,
				'core_booking_id' => sanitize_text_field((string) ($result['booking_id'] ?? $result['bookingId'] ?? $result['id'] ?? '')),
				'pickup_address' => sanitize_textarea_field((string) ($payload['pickup_address'] ?? '')),
				'dropoff_address' => sanitize_textarea_field((string) ($payload['dropoff_address'] ?? '')),
				'customer_name' => sanitize_text_field((string) ($payload['customer_name'] ?? '')),
				'customer_phone' => sanitize_text_field((string) ($payload['customer_phone'] ?? '')),
				'pickup_time' => sanitize_text_field((string) ($payload['pickup_time'] ?? '')),
				'verified_price' => round((float) ($payload['final_price'] ?? 0), 2),
				'currency' => sanitize_text_field((string) ($payload['currency'] ?? 'USD')),
				'status' => $status,
				'created_at' => current_time('mysql'),
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s']
		);
	}

	public function log_change_request(?int $session_id, array $session, string $request_text): int {
		global $wpdb;

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$now = current_time('mysql');
		$wpdb->insert(
			$wpdb->prefix . 'rfac_change_requests',
			[
				'session_id' => $session_id,
				'core_booking_id' => sanitize_text_field((string) ($data['last_booking_id'] ?? '')),
				'customer_name' => sanitize_text_field((string) ($data['customer_name'] ?? '')),
				'customer_phone' => sanitize_text_field((string) ($data['customer_phone'] ?? '')),
				'request_type' => 'booking_change',
				'original_price' => null,
				'proposed_price' => null,
				'currency' => sanitize_text_field((string) ($session['last_quote']['currency'] ?? 'USD')),
				'discount_percent' => null,
				'request_text' => sanitize_textarea_field($request_text),
				'status' => 'pending',
				'admin_note' => '',
				'created_at' => $now,
				'updated_at' => $now,
			],
			['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s']
		);

		$request_id = (int) $wpdb->insert_id;
		if ($request_id > 0) {
			Notifier::on_new_change_request($request_id, $session, $request_text);
		}

		return $request_id;
	}

	public function log_price_negotiation_request(?int $session_id, array $session): int {
		global $wpdb;

		$data = is_array($session['collected_data'] ?? null) ? $session['collected_data'] : [];
		$quote = is_array($session['last_quote'] ?? null) ? $session['last_quote'] : [];
		$original = round((float) ($quote['final_price'] ?? 0), 2);
		$proposed = round((float) ($data['proposed_price'] ?? 0), 2);
		$discount = $original > 0 ? round((($original - $proposed) / $original) * 100, 2) : 0.0;
		$currency = sanitize_text_field((string) ($quote['currency'] ?? 'USD'));
		$now = current_time('mysql');
		$text = sprintf(
			'Price approval request: %s to %s. Original fare: %s %.2f. Proposed fare: %s %.2f. Discount: %.2f%%. Pickup time: %s.',
			(string) ($data['pickup_address'] ?? ''),
			(string) ($data['dropoff_address'] ?? ''),
			$currency,
			$original,
			$currency,
			$proposed,
			$discount,
			(string) ($data['pickup_time'] ?? '')
		);

		$wpdb->insert(
			$wpdb->prefix . 'rfac_change_requests',
			[
				'session_id' => $session_id,
				'core_booking_id' => '',
				'customer_name' => sanitize_text_field((string) ($data['customer_name'] ?? '')),
				'customer_phone' => sanitize_text_field((string) ($data['customer_phone'] ?? '')),
				'request_type' => 'price_negotiation',
				'original_price' => $original,
				'proposed_price' => $proposed,
				'currency' => $currency,
				'discount_percent' => $discount,
				'request_text' => sanitize_textarea_field($text),
				'status' => 'pending',
				'admin_note' => '',
				'created_at' => $now,
				'updated_at' => $now,
			],
			['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%f', '%s', '%s', '%s', '%s', '%s']
		);

		$request_id = (int) $wpdb->insert_id;
		if ($request_id > 0) {
			Notifier::on_new_price_negotiation($request_id, $session);
		}

		return $request_id;
	}

	public function latest_request_for_session(int $session_id): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rfac_change_requests WHERE session_id = %d ORDER BY id DESC LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		return is_array($row) ? $row : [];
	}

	public function latest_request_by_booking(string $booking_id): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rfac_change_requests WHERE core_booking_id = %s ORDER BY id DESC LIMIT 1",
				sanitize_text_field($booking_id)
			),
			ARRAY_A
		);

		return is_array($row) ? $row : [];
	}

	private function normalize(array $row): array {
		$data = json_decode((string) ($row['collected_data'] ?? '{}'), true);
		$quote = json_decode((string) ($row['last_quote'] ?? '{}'), true);

		return [
			'id' => (int) ($row['id'] ?? 0),
			'session_key' => (string) ($row['session_key'] ?? ''),
			'state' => sanitize_key((string) ($row['state'] ?? 'greeting')),
			'collected_data' => is_array($data) ? $data : [],
			'last_quote' => is_array($quote) ? $quote : [],
		];
	}
}
