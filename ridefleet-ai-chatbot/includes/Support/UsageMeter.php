<?php
/**
 * AI usage meter — tracks per-day, per-IP OpenRouter token spend and request
 * counts so admins can cap costs and abusive clients hit a hard ceiling.
 *
 * Also exposes a simple circuit breaker: after N consecutive failures the
 * OpenRouter client short-circuits for a cooldown window so a slow upstream
 * doesn't drag down every chat turn.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class UsageMeter {
	private const CIRCUIT_FAILS_KEY = 'rfac_circuit_fails';
	private const CIRCUIT_OPEN_KEY = 'rfac_circuit_open_until';
	private const CIRCUIT_THRESHOLD = 4;
	private const CIRCUIT_COOLDOWN = 120; // seconds

	public static function ip_hash(): string {
		$raw = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
		return substr(hash('sha256', $raw . '|' . Options::signing_secret()), 0, 32);
	}

	public static function record(int $tokens_in, int $tokens_out): void {
		global $wpdb;
		$day = current_time('Y-m-d');
		$ip_hash = self::ip_hash();
		$table = $wpdb->prefix . 'rfac_ai_usage';
		$now = current_time('mysql');

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, ip_hash, tokens_in, tokens_out, requests, updated_at)
				 VALUES (%s, %s, %d, %d, 1, %s)
				 ON DUPLICATE KEY UPDATE
				   tokens_in = tokens_in + VALUES(tokens_in),
				   tokens_out = tokens_out + VALUES(tokens_out),
				   requests = requests + 1,
				   updated_at = VALUES(updated_at)",
				$day,
				$ip_hash,
				max(0, $tokens_in),
				max(0, $tokens_out),
				$now
			)
		);
	}

	public static function over_budget(): bool {
		$daily_budget = (int) Options::get('ai_daily_token_budget', 250000);
		if ($daily_budget <= 0) {
			return false;
		}

		global $wpdb;
		$day = current_time('Y-m-d');
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(tokens_in + tokens_out), 0)
				 FROM {$wpdb->prefix}rfac_ai_usage WHERE day = %s",
				$day
			)
		);

		return $total >= $daily_budget;
	}

	public static function ip_over_limit(): bool {
		$cap = (int) Options::get('ai_per_ip_daily_cap', 200);
		if ($cap <= 0) {
			return false;
		}

		global $wpdb;
		$day = current_time('Y-m-d');
		$ip_hash = self::ip_hash();
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(requests, 0) FROM {$wpdb->prefix}rfac_ai_usage WHERE day = %s AND ip_hash = %s",
				$day,
				$ip_hash
			)
		);

		return $count >= $cap;
	}

	public static function todays_usage(): array {
		global $wpdb;
		$day = current_time('Y-m-d');
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(tokens_in), 0) AS tokens_in,
				        COALESCE(SUM(tokens_out), 0) AS tokens_out,
				        COALESCE(SUM(requests), 0) AS requests,
				        COUNT(DISTINCT ip_hash) AS unique_ips
				 FROM {$wpdb->prefix}rfac_ai_usage WHERE day = %s",
				$day
			),
			ARRAY_A
		) ?: [];

		return [
			'tokens_in' => (int) ($row['tokens_in'] ?? 0),
			'tokens_out' => (int) ($row['tokens_out'] ?? 0),
			'requests' => (int) ($row['requests'] ?? 0),
			'unique_ips' => (int) ($row['unique_ips'] ?? 0),
		];
	}

	public static function circuit_open(): bool {
		$open_until = (int) get_transient(self::CIRCUIT_OPEN_KEY);
		return $open_until > time();
	}

	public static function circuit_record_failure(): void {
		$fails = (int) get_transient(self::CIRCUIT_FAILS_KEY);
		$fails++;
		set_transient(self::CIRCUIT_FAILS_KEY, $fails, MINUTE_IN_SECONDS * 5);
		if ($fails >= self::CIRCUIT_THRESHOLD) {
			set_transient(self::CIRCUIT_OPEN_KEY, time() + self::CIRCUIT_COOLDOWN, self::CIRCUIT_COOLDOWN);
			delete_transient(self::CIRCUIT_FAILS_KEY);
			Logger::warning('openrouter', 'Circuit breaker opened — too many consecutive failures.');
		}
	}

	public static function circuit_record_success(): void {
		delete_transient(self::CIRCUIT_FAILS_KEY);
		delete_transient(self::CIRCUIT_OPEN_KEY);
	}
}
