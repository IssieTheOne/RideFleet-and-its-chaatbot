<?php
/**
 * Conversation-level diagnostic logger.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class DiagnosticLogger {
	public static function log(?int $session_id, string $session_key, string $event_type, string $source, string $summary, array $context = []): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfac_diagnostic_events';
		if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
			return;
		}

		$wpdb->insert(
			$table,
			[
				'session_id' => $session_id ?: null,
				'session_key' => sanitize_key($session_key),
				'event_type' => sanitize_key($event_type),
				'source' => sanitize_key($source),
				'summary' => sanitize_textarea_field(substr($summary, 0, 2000)),
				'context' => wp_json_encode(self::sanitize_context($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'created_at' => current_time('mysql'),
			],
			['%d', '%s', '%s', '%s', '%s', '%s', '%s']
		);
	}

	public static function purge(int $days): int {
		global $wpdb;
		$days = max(1, $days);
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		return (int) $wpdb->query(
			$wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_diagnostic_events WHERE created_at < %s", $cutoff)
		);
	}

	private static function sanitize_context(array $context): array {
		$clean = [];
		foreach ($context as $key => $value) {
			$key = (string) $key;
			if (preg_match('/authorization|api[_-]?key|secret|password|token|nonce/i', $key)) {
				$clean[$key] = '[redacted]';
				continue;
			}

			if (is_string($value)) {
				$clean[$key] = sanitize_textarea_field(substr($value, 0, 4000));
			} elseif (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
				$clean[$key] = $value;
			} elseif (is_array($value)) {
				$clean[$key] = self::sanitize_context($value);
			} else {
				$clean[$key] = gettype($value);
			}
		}
		return $clean;
	}
}
