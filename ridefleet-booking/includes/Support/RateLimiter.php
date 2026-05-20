<?php
/**
 * Lightweight REST rate limiting.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Support;

use WP_REST_Request;

if (!defined('ABSPATH')) {
	exit;
}

final class RateLimiter {
	public static function check(WP_REST_Request $request, string $bucket, int $limit, int $window_seconds): bool {
		$identity = self::identity($request);
		$key = 'rfb_rate_' . md5($bucket . '|' . $identity);
		$count = (int) get_transient($key);

		if ($count >= $limit) {
			return false;
		}

		set_transient($key, $count + 1, $window_seconds);

		return true;
	}

	private static function identity(WP_REST_Request $request): string {
		if (is_user_logged_in()) {
			return 'user:' . get_current_user_id();
		}

		$forwarded = (string) $request->get_header('X-Forwarded-For');
		$ip = $forwarded ? trim(explode(',', $forwarded)[0]) : (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
		$session = sanitize_text_field((string) $request->get_param('sessionId'));

		return wp_hash($ip . '|' . $session);
	}
}
