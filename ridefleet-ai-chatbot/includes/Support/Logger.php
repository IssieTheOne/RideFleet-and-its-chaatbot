<?php
/**
 * Structured error logger that writes to wp_rfac_error_log and falls back to
 * PHP error_log if the DB write fails.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class Logger {
	public const LEVEL_INFO = 'info';
	public const LEVEL_WARNING = 'warning';
	public const LEVEL_ERROR = 'error';
	public const LEVEL_CRITICAL = 'critical';

	public static function info(string $source, string $message, array $context = [], ?int $session_id = null): void {
		self::log(self::LEVEL_INFO, $source, $message, $context, $session_id);
	}

	public static function warning(string $source, string $message, array $context = [], ?int $session_id = null): void {
		self::log(self::LEVEL_WARNING, $source, $message, $context, $session_id);
	}

	public static function error(string $source, string $message, array $context = [], ?int $session_id = null): void {
		self::log(self::LEVEL_ERROR, $source, $message, $context, $session_id);
	}

	public static function critical(string $source, string $message, array $context = [], ?int $session_id = null): void {
		self::log(self::LEVEL_CRITICAL, $source, $message, $context, $session_id);
	}

	public static function log(string $level, string $source, string $message, array $context = [], ?int $session_id = null): void {
		global $wpdb;

		$row = [
			'session_id' => $session_id,
			'level' => sanitize_key($level),
			'source' => sanitize_text_field(substr($source, 0, 60)),
			'message' => sanitize_textarea_field(substr($message, 0, 2000)),
			'context' => wp_json_encode(self::sanitize_context($context)),
			'created_at' => current_time('mysql'),
		];

		$table = $wpdb->prefix . 'rfac_error_log';
		$inserted = $wpdb->insert($table, $row, ['%d', '%s', '%s', '%s', '%s', '%s']);

		if (false === $inserted) {
			error_log(sprintf('[ridefleet-ai-chatbot %s] %s: %s', $level, $source, $message));
		}
	}

	public static function purge(int $days = 30): int {
		global $wpdb;
		$cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
		return (int) $wpdb->query(
			$wpdb->prepare("DELETE FROM {$wpdb->prefix}rfac_error_log WHERE created_at < %s", $cutoff)
		);
	}

	private static function sanitize_context(array $context): array {
		$redacted = [];
		foreach ($context as $key => $value) {
			$key_str = (string) $key;
			if (preg_match('/key|secret|token|password|authorization/i', $key_str)) {
				$redacted[$key_str] = '[redacted]';
				continue;
			}
			if (is_scalar($value) || null === $value) {
				$redacted[$key_str] = $value;
			} elseif (is_array($value)) {
				$redacted[$key_str] = self::sanitize_context($value);
			} else {
				$redacted[$key_str] = gettype($value);
			}
		}
		return $redacted;
	}
}
