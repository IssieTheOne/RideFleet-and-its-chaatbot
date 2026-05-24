<?php
namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class CalendarPage {
	public static function render(): void {
		global $wpdb;

		$requested_view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'month';
		$view = in_array($requested_view, ['month', 'week'], true) ? $requested_view : 'month';
		$start_input = isset($_GET['start']) ? sanitize_text_field(wp_unslash($_GET['start'])) : ('week' === $view ? wp_date('Y-m-d') : wp_date('Y-m'));
		$status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
		$start = preg_match('/^\d{4}-\d{2}$/', $start_input) ? $start_input . '-01' : $start_input;
		if ('week' === $view) {
			$start = wp_date('Y-m-d', strtotime('monday this week', strtotime($start)));
			$end = wp_date('Y-m-d', strtotime('+6 days', strtotime($start)));
		} else {
			$end = wp_date('Y-m-t', strtotime($start));
		}
		$where = "pickup_at BETWEEN %s AND %s";
		$params = [$start . ' 00:00:00', $end . ' 23:59:59'];
		if ($status_filter) {
			$where .= ' AND status = %s';
			$params[] = $status_filter;
		}
		$bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_bookings WHERE {$where} ORDER BY pickup_at ASC", $params));

		$by_day = [];
		$status_counts = [];
		foreach ($bookings as $booking) {
			$day = substr((string) $booking->pickup_at, 0, 10);
			$by_day[$day][] = $booking;
			$status_counts[$booking->status] = ($status_counts[$booking->status] ?? 0) + 1;
		}
		$prev = 'week' === $view ? wp_date('Y-m-d', strtotime('-1 week', strtotime($start))) : wp_date('Y-m', strtotime('-1 month', strtotime($start)));
		$next = 'week' === $view ? wp_date('Y-m-d', strtotime('+1 week', strtotime($start))) : wp_date('Y-m', strtotime('+1 month', strtotime($start)));

		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Schedule', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Ride Calendar', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Navigate rides by month or week. Filter by booking status to focus on what needs attention.', 'ridefleet-booking'); ?></p>
				</div>
				<div class="rfb-hero-actions">
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-calendar&view=' . $view . '&start=' . $prev)); ?>"><?php esc_html_e('Previous', 'ridefleet-booking'); ?></a>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-calendar&view=' . $view . '&start=' . $next)); ?>"><?php esc_html_e('Next', 'ridefleet-booking'); ?></a>
					<a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings')); ?>"><?php esc_html_e('Bookings', 'ridefleet-booking'); ?></a>
				</div>
			</div>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('This month', 'ridefleet-booking'), number_format_i18n(count($bookings)), __('Scheduled rides in the selected period.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Confirmed', 'ridefleet-booking'), number_format_i18n((int) ($status_counts['confirmed'] ?? 0)), __('Rides ready for dispatch.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Pending', 'ridefleet-booking'), number_format_i18n((int) (($status_counts['pending_payment'] ?? 0) + ($status_counts['pending_dispatch'] ?? 0))), __('Bookings awaiting payment or dispatch confirmation.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Completed', 'ridefleet-booking'), number_format_i18n((int) ($status_counts['completed'] ?? 0)), __('Finished rides.', 'ridefleet-booking')); ?>
			</div>
						<form method="get" class="rfb-panel rfb-filter-bar rfb-filter-bar-labeled">
				<input type="hidden" name="page" value="ridefleet-calendar">
				<label>
					<span><?php esc_html_e('View', 'ridefleet-booking'); ?></span>
					<select name="view">
						<option value="month" <?php selected($view, 'month'); ?>><?php esc_html_e('Month', 'ridefleet-booking'); ?></option>
						<option value="week" <?php selected($view, 'week'); ?>><?php esc_html_e('Week', 'ridefleet-booking'); ?></option>
					</select>
				</label>
				<label>
					<span><?php esc_html_e('Start', 'ridefleet-booking'); ?></span>
					<input type="<?php echo esc_attr('week' === $view ? 'date' : 'month'); ?>" name="start" value="<?php echo esc_attr('week' === $view ? $start : substr($start, 0, 7)); ?>">
				</label>
				<label>
					<span><?php esc_html_e('Status', 'ridefleet-booking'); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e('All statuses', 'ridefleet-booking'); ?></option>
						<?php foreach (['pending_payment', 'pending_dispatch', 'confirmed', 'completed', 'cancelled', 'refunded', 'failed'] as $status) : ?>
						<option value="<?php echo esc_attr($status); ?>" <?php selected($status_filter, $status); ?>><?php echo esc_html(ucwords(str_replace('_', ' ', $status))); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="rfb-filter-bar-actions">
					<?php submit_button(__('Apply', 'ridefleet-booking'), 'primary', '', false); ?>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-calendar')); ?>"><?php esc_html_e('Reset', 'ridefleet-booking'); ?></a>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&new=1')); ?>"><?php esc_html_e('Manual booking', 'ridefleet-booking'); ?></a>
				</div>
			</form>
			<div class="rfb-calendar-weekdays">
				<?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday) : ?>
					<span><?php echo esc_html($weekday); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="rfb-calendar-grid">
				<?php
				$cursor = strtotime($start);
				$last = strtotime($end);
				$offset = (int) wp_date('N', $cursor) - 1;
				for ($blank = 0; $blank < $offset; $blank++) :
					echo '<div class="rfb-calendar-day rfb-calendar-empty"></div>';
				endfor;
				while ($cursor <= $last) :
					$day = wp_date('Y-m-d', $cursor);
					$day_bookings = $by_day[$day] ?? [];
					?>
					<div class="rfb-calendar-day <?php echo esc_attr($day_bookings ? 'has-bookings' : ''); ?>">
						<strong><span><?php echo esc_html(wp_date('D', $cursor)); ?></span><?php echo esc_html(wp_date('j', $cursor)); ?></strong>
						<?php foreach ($day_bookings as $booking) : ?>
							<a class="rfb-calendar-booking <?php echo esc_attr('is-' . sanitize_html_class($booking->status)); ?>" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . (int) $booking->id)); ?>">
								<b><?php echo esc_html(wp_date('H:i', strtotime($booking->pickup_at))); ?> · <?php echo esc_html($booking->booking_number); ?></b>
								<span><?php echo esc_html(wp_trim_words($booking->pickup_address, 4) . ' -> ' . wp_trim_words($booking->dropoff_address, 4)); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
					<?php
					$cursor = strtotime('+1 day', $cursor);
				endwhile;
				?>
			</div>
		</div>
		<?php
	}
}
