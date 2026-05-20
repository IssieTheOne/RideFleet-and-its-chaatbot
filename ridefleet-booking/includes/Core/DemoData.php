<?php
/**
 * Demo data for quick testing.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Core;

use RideFleetBooking\Booking\BookingRepository;
use RideFleetBooking\Booking\QuoteCalculator;

if (!defined('ABSPATH')) {
	exit;
}

final class DemoData {
	public static function seed(): void {
		$sedan = self::post('rfb_vehicle', 'Executive Sedan', 'Comfortable sedan for airport transfers and private rides.');
		update_post_meta($sedan, 'rfb_passenger_capacity', 3);
		update_post_meta($sedan, 'rfb_luggage_capacity', 2);
		update_post_meta($sedan, 'rfb_price_adjustment', '0.00');

		$suv = self::post('rfb_vehicle', 'Premium SUV', 'Spacious SUV for families, luggage, and ski trips.');
		update_post_meta($suv, 'rfb_passenger_capacity', 6);
		update_post_meta($suv, 'rfb_luggage_capacity', 6);
		update_post_meta($suv, 'rfb_price_adjustment', '25.00');

		self::post('rfb_driver', 'Aziz', 'Demo driver profile.');

		$child_seat = self::post('rfb_extra', 'Child Seat', 'Optional child seat for family rides.');
		update_post_meta($child_seat, 'rfb_price', '10.00');
		update_post_meta($child_seat, 'rfb_max_quantity', 2);

		$meet_greet = self::post('rfb_extra', 'Meet & Greet', 'Driver meets the customer inside the terminal.');
		update_post_meta($meet_greet, 'rfb_price', '20.00');
		update_post_meta($meet_greet, 'rfb_max_quantity', 1);

		self::post('rfb_location', 'Burlington International Airport', '');
		self::post('rfb_location', 'Stowe Mountain Resort', '');
		self::post('rfb_route', 'BTV Airport to Stowe', '');
		self::post('rfb_form', 'Main Booking Form', '');
		self::booking_page();
		self::coupon('WELCOME10', 'Demo 10% welcome discount.', 'percent', 10);
		self::night_rule();
		self::booking($sedan);
	}

	public static function clear(): void {
		global $wpdb;

		foreach (['Executive Sedan', 'Premium SUV'] as $title) {
			$post = get_page_by_title($title, OBJECT, 'rfb_vehicle');
			if ($post) {
				wp_delete_post((int) $post->ID, true);
			}
		}

		foreach (['Aziz'] as $title) {
			$post = get_page_by_title($title, OBJECT, 'rfb_driver');
			if ($post) {
				wp_delete_post((int) $post->ID, true);
			}
		}

		foreach (['Child Seat', 'Meet & Greet'] as $title) {
			$post = get_page_by_title($title, OBJECT, 'rfb_extra');
			if ($post) {
				wp_delete_post((int) $post->ID, true);
			}
		}

		foreach (['Burlington International Airport', 'Stowe Mountain Resort'] as $title) {
			$post = get_page_by_title($title, OBJECT, 'rfb_location');
			if ($post) {
				wp_delete_post((int) $post->ID, true);
			}
		}

		foreach (['BTV Airport to Stowe'] as $title) {
			$post = get_page_by_title($title, OBJECT, 'rfb_route');
			if ($post) {
				wp_delete_post((int) $post->ID, true);
			}
		}

		$page = get_page_by_path('ride-booking');
		if ($page) {
			wp_delete_post((int) $page->ID, true);
		}

		$wpdb->query("DELETE FROM {$wpdb->prefix}rfb_bookings WHERE booking_number LIKE 'RFB-DEMO-%'");
		$wpdb->query("DELETE FROM {$wpdb->prefix}rfb_customers WHERE email = 'demo@example.com'");
		$wpdb->query("DELETE FROM {$wpdb->prefix}rfb_coupons WHERE code = 'WELCOME10'");
		$wpdb->query("DELETE FROM {$wpdb->prefix}rfb_pricing_rules WHERE label = 'Demo Night Surcharge'");
	}

	private static function booking_page(): void {
		$existing = get_page_by_path('ride-booking');
		if ($existing) {
			return;
		}

		wp_insert_post(
			[
				'post_type' => 'page',
				'post_status' => 'publish',
				'post_title' => 'Ride Booking',
				'post_name' => 'ride-booking',
				'post_content' => '[ridefleet_booking_form id="1"]',
			]
		);
	}

	private static function post(string $type, string $title, string $content): int {
		$existing = get_page_by_title($title, OBJECT, $type);
		if ($existing) {
			return (int) $existing->ID;
		}

		return (int) wp_insert_post(
			[
				'post_type' => $type,
				'post_status' => 'publish',
				'post_title' => $title,
				'post_content' => $content,
			]
		);
	}

	private static function coupon(string $code, string $description, string $type, float $amount): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_coupons';
		$exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE code = %s", $code));
		if ($exists) {
			return;
		}

		$wpdb->insert(
			$table,
			[
				'code' => $code,
				'description' => $description,
				'discount_type' => $type,
				'amount' => $amount,
				'is_active' => 1,
				'created_at' => current_time('mysql'),
				'updated_at' => current_time('mysql'),
			]
		);
	}

	private static function night_rule(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_pricing_rules';
		$exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE label = %s", 'Demo Night Surcharge'));
		if ($exists) {
			return;
		}

		$wpdb->insert(
			$table,
			[
				'label' => 'Demo Night Surcharge',
				'rule_type' => 'time_window',
				'adjustment_type' => 'percent',
				'amount' => 20,
				'start_time' => '22:00',
				'end_time' => '06:00',
				'priority' => 10,
				'is_active' => 1,
				'created_at' => current_time('mysql'),
				'updated_at' => current_time('mysql'),
			]
		);
	}

	private static function booking(int $vehicle_id): void {
		global $wpdb;

		$exists = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}rfb_bookings WHERE booking_number LIKE 'RFB-DEMO-%' LIMIT 1");
		if ($exists) {
			return;
		}

		$payload = [
			'serviceType' => 'distance',
			'transferType' => 'one_way',
			'pickupAddress' => 'Burlington International Airport, VT',
			'dropoffAddress' => 'Stowe Mountain Resort, VT',
			'pickupDate' => wp_date('Y-m-d', strtotime('+1 day')),
			'pickupTime' => '10:30',
			'distance' => 56,
			'durationMinutes' => 55,
			'passengers' => 2,
			'luggage' => 2,
			'vehicleId' => $vehicle_id,
			'extras' => [],
			'couponCode' => '',
			'customerFirstName' => 'Demo',
			'customerLastName' => 'Customer',
			'customerEmail' => 'demo@example.com',
			'customerPhone' => '+1 802 555 0100',
			'note' => 'Demo booking created by RideFleet.',
		];

		$pickup_at = $payload['pickupDate'] . ' ' . $payload['pickupTime'];
		$quote = QuoteCalculator::calculate(56, 55, [], $vehicle_id, '', $pickup_at);
		$booking = BookingRepository::create($payload, $quote);
		$wpdb->update($wpdb->prefix . 'rfb_bookings', ['booking_number' => 'RFB-DEMO-' . (int) $booking['id']], ['id' => (int) $booking['id']]);
	}
}
