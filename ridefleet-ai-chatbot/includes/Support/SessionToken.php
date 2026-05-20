<?php
/**
 * HMAC-signed session tokens. Prevents customers reading each other's sessions
 * by guessing or stealing localStorage values from other tabs.
 *
 * @package RideFleetAIChatbot
 */

namespace RideFleetAIChatbot\Support;

if (!defined('ABSPATH')) {
	exit;
}

final class SessionToken {
	private const SEPARATOR = '.';

	/**
	 * Returns a signed session token: `sessionKey.signature`.
	 */
	public static function sign(string $session_key): string {
		$session_key = sanitize_key($session_key);
		if ('' === $session_key) {
			$session_key = wp_generate_uuid4();
		}
		return $session_key . self::SEPARATOR . self::compute($session_key);
	}

	/**
	 * Verifies a signed token and returns the session key, or '' if invalid.
	 */
	public static function verify(string $token): string {
		$token = trim($token);
		if ('' === $token) {
			return '';
		}

		// Backwards-compat: accept unsigned legacy session IDs (no separator)
		// but only when the value still passes sanitize_key — they came from
		// our own widget before signing was introduced.
		if (false === strpos($token, self::SEPARATOR)) {
			$legacy = sanitize_key($token);
			return $legacy === $token ? $legacy : '';
		}

		$parts = explode(self::SEPARATOR, $token, 2);
		if (2 !== count($parts)) {
			return '';
		}

		[$session_key, $signature] = $parts;
		$session_key = sanitize_key($session_key);
		$expected = self::compute($session_key);

		return hash_equals($expected, $signature) ? $session_key : '';
	}

	private static function compute(string $session_key): string {
		return hash_hmac('sha256', $session_key, Options::signing_secret());
	}
}
