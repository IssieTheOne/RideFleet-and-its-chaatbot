<?php
/**
 * WordPress content types used by RideFleet.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class PostTypes {
	public static function register_hooks(): void {
		add_action('init', [self::class, 'register']);
	}

	public static function register(): void {
		self::register_vehicle();
		self::register_driver();
		self::register_extra();
		self::register_location();
		self::register_route();
		self::register_form();
	}

	private static function register_vehicle(): void {
		register_post_type(
			'rfb_vehicle',
			[
				'labels' => self::labels(__('Vehicles', 'ridefleet-booking'), __('Vehicle', 'ridefleet-booking'), __('Add Vehicle', 'ridefleet-booking'), __('Edit Vehicle', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'menu_icon' => 'dashicons-car',
				'supports' => ['title', 'editor', 'thumbnail'],
				'show_in_rest' => true,
			]
		);

		register_taxonomy(
			'rfb_vehicle_type',
			'rfb_vehicle',
			[
				'labels' => [
					'name' => __('Vehicle Types', 'ridefleet-booking'),
					'singular_name' => __('Vehicle Type', 'ridefleet-booking'),
					'add_new_item' => __('Add Vehicle Type', 'ridefleet-booking'),
					'edit_item' => __('Edit Vehicle Type', 'ridefleet-booking'),
				],
				'public' => false,
				'show_ui' => true,
				'show_in_rest' => true,
				'hierarchical' => true,
			]
		);
	}

	private static function register_driver(): void {
		register_post_type(
			'rfb_driver',
			[
				'labels' => self::labels(__('Drivers', 'ridefleet-booking'), __('Driver', 'ridefleet-booking'), __('Add Driver', 'ridefleet-booking'), __('Edit Driver', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'menu_icon' => 'dashicons-id',
				'supports' => ['title', 'editor', 'thumbnail'],
				'show_in_rest' => true,
			]
		);
	}

	private static function register_extra(): void {
		register_post_type(
			'rfb_extra',
			[
				'labels' => self::labels(__('Booking Extras', 'ridefleet-booking'), __('Booking Extra', 'ridefleet-booking'), __('Add Extra', 'ridefleet-booking'), __('Edit Extra', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title', 'editor'],
				'show_in_rest' => true,
			]
		);
	}

	private static function register_location(): void {
		register_post_type(
			'rfb_location',
			[
				'labels' => self::labels(__('Locations', 'ridefleet-booking'), __('Location', 'ridefleet-booking'), __('Add Location', 'ridefleet-booking'), __('Edit Location', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title'],
				'show_in_rest' => true,
			]
		);
	}

	private static function register_route(): void {
		register_post_type(
			'rfb_route',
			[
				'labels' => self::labels(__('Routes', 'ridefleet-booking'), __('Route', 'ridefleet-booking'), __('Add Route', 'ridefleet-booking'), __('Edit Route', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title'],
				'show_in_rest' => true,
			]
		);
	}

	private static function register_form(): void {
		register_post_type(
			'rfb_form',
			[
				'labels' => self::labels(__('Booking Forms', 'ridefleet-booking'), __('Booking Form', 'ridefleet-booking'), __('Add Booking Form', 'ridefleet-booking'), __('Edit Booking Form', 'ridefleet-booking')),
				'public' => false,
				'show_ui' => true,
				'show_in_menu' => false,
				'supports' => ['title'],
				'show_in_rest' => true,
			]
		);
	}

	private static function labels(string $plural, string $singular, string $add_new, string $edit): array {
		return [
			'name' => $plural,
			'singular_name' => $singular,
			'add_new' => $add_new,
			'add_new_item' => $add_new,
			'edit_item' => $edit,
			'new_item' => sprintf(__('New %s', 'ridefleet-booking'), $singular),
			'view_item' => sprintf(__('View %s', 'ridefleet-booking'), $singular),
			'search_items' => sprintf(__('Search %s', 'ridefleet-booking'), $plural),
			'not_found' => sprintf(__('No %s found', 'ridefleet-booking'), strtolower($plural)),
			'all_items' => $plural,
			'menu_name' => $plural,
			'name_admin_bar' => $singular,
		];
	}
}
