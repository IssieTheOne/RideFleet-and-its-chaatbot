<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class RouteCachePage {
	public static function render(): void {
		?>
		<div class="wrap rfb-admin">
			<?php self::render_content(); ?>
		</div>
		<?php
	}

	public static function render_content(): void {
		global $wpdb;
		$rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}rfb_route_cache ORDER BY updated_at DESC LIMIT 100");
		?>
			<div class="rfb-page-title">
				<div><p class="rfb-kicker"><?php esc_html_e('Google Cost Control', 'ridefleet-booking'); ?></p><h1><?php esc_html_e('Route Cache', 'ridefleet-booking'); ?></h1></div>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="rfb_clear_route_cache"><?php wp_nonce_field('rfb_clear_route_cache'); ?><?php submit_button(__('Clear Cache', 'ridefleet-booking'), 'secondary', 'submit', false); ?></form>
			</div>
			<div class="rfb-panel rfb-table-panel"><table class="widefat rfb-bookings-table"><thead><tr><th><?php esc_html_e('Route', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Distance', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Duration', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Updated', 'ridefleet-booking'); ?></th></tr></thead><tbody>
				<?php if (!$rows) : ?><tr><td colspan="4"><?php esc_html_e('No cached routes yet. Route pages should read from this table before calling Google.', 'ridefleet-booking'); ?></td></tr><?php endif; ?>
				<?php foreach ($rows as $row) : ?><tr><td><?php echo esc_html(wp_trim_words($row->origin, 5) . ' -> ' . wp_trim_words($row->destination, 5)); ?></td><td><?php echo esc_html($row->distance_value . ' ' . $row->distance_unit); ?></td><td><?php echo esc_html(round(((int) $row->duration_seconds) / 60) . ' min'); ?></td><td><?php echo esc_html($row->updated_at); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		<?php
	}

	public static function clear(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('Not allowed.', 'ridefleet-booking'));
		}
		check_admin_referer('rfb_clear_route_cache');
		global $wpdb;
		$wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rfb_route_cache");
		wp_safe_redirect(admin_url('admin.php?page=ridefleet-route-pages&tab=cache&cleared=1'));
		exit;
	}
}
