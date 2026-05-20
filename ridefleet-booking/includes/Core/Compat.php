<?php
/**
 * Browser compatibility helpers for the public booking flow.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Core;

if (!defined('ABSPATH')) {
	exit;
}

final class Compat {
	public static function register_hooks(): void {
		add_action('wp_head', [self::class, 'print_random_uuid_polyfill'], 0);
	}

	public static function print_random_uuid_polyfill(): void {
		if (is_admin()) {
			return;
		}
		?>
		<script>
			(function () {
				if (!window.crypto) {
					window.crypto = {};
				}

				if (window.crypto.randomUUID) {
					return;
				}

				window.crypto.randomUUID = function () {
					var bytes = new Uint8Array(16);
					if (window.crypto.getRandomValues) {
						window.crypto.getRandomValues(bytes);
					} else {
						for (var index = 0; index < bytes.length; index += 1) {
							bytes[index] = Math.floor(Math.random() * 256);
						}
					}
					bytes[6] = (bytes[6] & 15) | 64;
					bytes[8] = (bytes[8] & 63) | 128;
					return Array.prototype.map.call(bytes, function (byte) {
						return ('0' + byte.toString(16)).slice(-2);
					}).join('').replace(/^(.{8})(.{4})(.{4})(.{4})(.{12})$/, '$1-$2-$3-$4-$5');
				};
			}());
		</script>
		<?php
	}
}
