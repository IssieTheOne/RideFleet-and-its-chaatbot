<?php
/**
 * Google Maps integration seam.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Integrations;

use RideFleetBooking\Support\Options;

if (!defined('ABSPATH')) {
	exit;
}

final class GoogleMaps {
	public static function register_hooks(): void {
		add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
	}

	public static function maybe_enqueue(): void {
		if (!is_singular()) {
			return;
		}

		global $post;

		if (!$post || false === strpos((string) $post->post_content, '[ridefleet_booking_form')) {
			return;
		}

		self::enqueue_script();
	}

	public static function enqueue_script(): void {
		$key = Options::get('google_maps_api_key', '');

		if (!$key || wp_script_is('google-maps', 'enqueued') || wp_script_is('google-maps', 'done')) {
			return;
		}

		$query = [
			'key' => $key,
			'libraries' => 'places,routes',
			'loading' => 'async',
			'v' => 'weekly',
		];

		$map_id = Options::get('google_maps_map_id', '');
		if ($map_id) {
			$query['map_ids'] = $map_id;
		}

		wp_enqueue_script('google-maps', add_query_arg($query, 'https://maps.googleapis.com/maps/api/js'), [], null, true);
	}
}
