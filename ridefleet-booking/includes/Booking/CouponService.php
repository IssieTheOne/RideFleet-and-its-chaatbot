<?php
/**
 * Coupon calculations and usage tracking.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class CouponService {
	public static function discount(string $code, float $subtotal): array {
		$coupon = self::find($code);

		if (!$coupon) {
			return ['code' => '', 'amount' => 0.0, 'valid' => false, 'message' => __('Coupon not found.', 'ridefleet-booking')];
		}

		$now = current_time('mysql');
		if (!$coupon->is_active || ($coupon->starts_at && $coupon->starts_at > $now) || ($coupon->ends_at && $coupon->ends_at < $now)) {
			return ['code' => $coupon->code, 'amount' => 0.0, 'valid' => false, 'message' => __('Coupon is not active.', 'ridefleet-booking')];
		}

		if (null !== $coupon->usage_limit && '' !== $coupon->usage_limit && (int) $coupon->used_count >= (int) $coupon->usage_limit) {
			return ['code' => $coupon->code, 'amount' => 0.0, 'valid' => false, 'message' => __('Coupon usage limit reached.', 'ridefleet-booking')];
		}

		$amount = 'percent' === $coupon->discount_type ? $subtotal * ((float) $coupon->amount / 100) : (float) $coupon->amount;
		$amount = min($subtotal, max(0, $amount));

		return ['code' => $coupon->code, 'amount' => round($amount, 2), 'valid' => true, 'message' => __('Coupon applied.', 'ridefleet-booking')];
	}

	/**
	 * Atomically increment used_count while enforcing the usage limit. Returns
	 * true when the coupon was successfully marked used. Returns false if the
	 * coupon does not exist, is inactive, or another concurrent booking just
	 * exhausted the limit. Callers should treat false as "coupon could not be
	 * applied right now" and refund/adjust accordingly.
	 */
	public static function mark_used(string $code): bool {
		$code = strtoupper(sanitize_text_field($code));
		if ('' === $code) {
			return false;
		}

		global $wpdb;
		$now = current_time('mysql');
		$updated = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}rfb_coupons
				    SET used_count = used_count + 1,
				        updated_at = %s
				 WHERE code = %s
				   AND is_active = 1
				   AND (starts_at IS NULL OR starts_at = '' OR starts_at <= %s)
				   AND (ends_at   IS NULL OR ends_at   = '' OR ends_at   >= %s)
				   AND (usage_limit IS NULL OR usage_limit = '' OR used_count < usage_limit)
				 LIMIT 1",
				$now,
				$code,
				$now,
				$now
			)
		);

		return $updated > 0;
	}

	private static function find(string $code): ?object {
		$code = strtoupper(sanitize_text_field($code));
		if (!$code) {
			return null;
		}

		global $wpdb;
		$coupon = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_coupons WHERE code = %s LIMIT 1", $code));

		return $coupon ?: null;
	}
}
