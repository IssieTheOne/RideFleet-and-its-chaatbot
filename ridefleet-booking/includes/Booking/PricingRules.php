<?php
/**
 * Pricing rule engine.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Booking;

if (!defined('ABSPATH')) {
	exit;
}

final class PricingRules {
	public static function adjustments(float $subtotal, array $context): array {
		global $wpdb;

		$rules = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rfb_pricing_rules WHERE is_active = 1 ORDER BY priority ASC, id ASC");
		$total = 0.0;
		$applied = [];

		foreach ($rules as $rule) {
			if (!self::matches($rule, $context)) {
				continue;
			}

			$amount = 'percent' === $rule->adjustment_type ? $subtotal * ((float) $rule->amount / 100) : (float) $rule->amount;
			$total += $amount;
			$applied[] = [
				'label' => $rule->label,
				'type' => $rule->adjustment_type,
				'amount' => round($amount, 2),
			];
		}

		return ['total' => round($total, 2), 'applied' => $applied];
	}

	private static function matches(object $rule, array $context): bool {
		if ('time_window' !== $rule->rule_type) {
			return false;
		}

		$pickup = $context['pickupAt'] ?? '';
		$timestamp = $pickup ? strtotime($pickup) : current_time('timestamp');
		$time = wp_date('H:i', $timestamp);
		$weekday = wp_date('N', $timestamp);
		$weekdays = array_filter(array_map('trim', explode(',', (string) $rule->weekdays)));

		if ($weekdays && !in_array($weekday, $weekdays, true)) {
			return false;
		}

		$start = $rule->start_time ?: '00:00';
		$end = $rule->end_time ?: '23:59';

		if ($start <= $end) {
			return $time >= $start && $time <= $end;
		}

		return $time >= $start || $time <= $end;
	}
}
