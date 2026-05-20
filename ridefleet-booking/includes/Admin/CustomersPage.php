<?php
/**
 * Customer database admin page.
 *
 * @package RideFleetBooking
 */

namespace RideFleetBooking\Admin;

if (!defined('ABSPATH')) {
	exit;
}

final class CustomersPage {
	public static function render(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'rfb_customers';
		if (!empty($_GET['customer_id'])) {
			self::render_detail(absint($_GET['customer_id']));
			return;
		}
		$where = [];
		$params = [];
		if (!empty($_GET['s'])) {
			$search = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET['s']))) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push($params, $search, $search, $search, $search);
		}
		if (!empty($_GET['repeat'])) {
			$where[] = 'total_bookings > 1';
		}
		$min_bookings = absint($_GET['min_bookings'] ?? 0);
		if ($min_bookings > 0) {
			$where[] = 'total_bookings >= %d';
			$params[] = $min_bookings;
		}
		$order = sanitize_key($_GET['order_by'] ?? 'recent');
		$order_sql = match ($order) {
			'spend' => 'total_spend DESC, last_booking_at DESC',
			'bookings' => 'total_bookings DESC, last_booking_at DESC',
			'name' => 'last_name ASC, first_name ASC',
			default => 'last_booking_at DESC, created_at DESC',
		};
		$per_page = max(10, min(100, absint($_GET['per_page'] ?? 10)));
		$page_num = max(1, absint($_GET['paged'] ?? 1));
		$offset = ($page_num - 1) * $per_page;
		$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
		$count_sql = "SELECT COUNT(*) FROM {$table}{$where_sql}";
		$filtered_total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));
		$sql = "SELECT * FROM {$table}{$where_sql} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
		$customers = $wpdb->get_results($wpdb->prepare($sql, array_merge($params, [$per_page, $offset])));
		$total_customers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		$repeat_customers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE total_bookings > 1");
		$total_spend = (float) $wpdb->get_var("SELECT COALESCE(SUM(total_spend), 0) FROM {$table}");

		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('CRM', 'ridefleet-booking'); ?></p>
					<h1><?php esc_html_e('Customers', 'ridefleet-booking'); ?></h1>
					<p><?php esc_html_e('Browse your client database, track repeat riders, and review lifetime spend per customer.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Customers', 'ridefleet-booking'), number_format_i18n($total_customers), __('Matched by email first, then phone number.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Repeat riders', 'ridefleet-booking'), number_format_i18n($repeat_customers), __('Customers with more than one booking.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Lifetime value', 'ridefleet-booking'), number_format_i18n($total_spend, 2), __('Total value across customer profiles.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Matching logic', 'ridefleet-booking'), __('Email / phone', 'ridefleet-booking'), __('New bookings update an existing customer when contact details match.', 'ridefleet-booking')); ?>
			</div>
			<form method="get" class="rfb-panel rfb-filter-bar rfb-filter-bar-labeled">
				<input type="hidden" name="page" value="ridefleet-customers">
				<label><span><?php esc_html_e('Search', 'ridefleet-booking'); ?></span><input type="search" name="s" value="<?php echo esc_attr($_GET['s'] ?? ''); ?>" placeholder="<?php esc_attr_e('Name, email, phone', 'ridefleet-booking'); ?>"></label>
				<label><span><?php esc_html_e('Minimum rides', 'ridefleet-booking'); ?></span><input type="number" min="0" name="min_bookings" value="<?php echo esc_attr((string) $min_bookings); ?>"></label>
				<label><span><?php esc_html_e('Sort by', 'ridefleet-booking'); ?></span><select name="order_by"><option value="recent" <?php selected($order, 'recent'); ?>><?php esc_html_e('Most recent', 'ridefleet-booking'); ?></option><option value="spend" <?php selected($order, 'spend'); ?>><?php esc_html_e('Highest spend', 'ridefleet-booking'); ?></option><option value="bookings" <?php selected($order, 'bookings'); ?>><?php esc_html_e('Most bookings', 'ridefleet-booking'); ?></option><option value="name" <?php selected($order, 'name'); ?>><?php esc_html_e('Name', 'ridefleet-booking'); ?></option></select></label>
				<label class="rfb-inline-check"><input type="checkbox" name="repeat" value="1" <?php checked(!empty($_GET['repeat'])); ?>> <?php esc_html_e('Repeat riders only', 'ridefleet-booking'); ?></label>
				<label><span><?php esc_html_e('Rows', 'ridefleet-booking'); ?></span><select name="per_page">
					<?php foreach ([10, 25, 50, 100] as $size) : ?>
						<option value="<?php echo esc_attr((string) $size); ?>" <?php selected($per_page, $size); ?>><?php echo esc_html(sprintf(__('%d per page', 'ridefleet-booking'), $size)); ?></option>
					<?php endforeach; ?>
				</select></label>
				<div class="rfb-filter-bar-actions">
					<?php submit_button(__('Filter', 'ridefleet-booking'), 'primary', '', false); ?>
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-customers')); ?>"><?php esc_html_e('Reset', 'ridefleet-booking'); ?></a>
				</div>
			</form>
						<div class="rfb-panel rfb-table-panel">
				<table class="widefat rfb-bookings-table rfb-customers-table">
					<thead><tr>
						<th><?php esc_html_e('Customer', 'ridefleet-booking'); ?></th>
						<th><?php esc_html_e('Phone', 'ridefleet-booking'); ?></th>
						<th><?php esc_html_e('Bookings', 'ridefleet-booking'); ?></th>
						<th><?php esc_html_e('Total Spend', 'ridefleet-booking'); ?></th>
						<th><?php esc_html_e('Last Booking', 'ridefleet-booking'); ?></th>
					</tr></thead>
					<tbody>
						<?php if (!$customers) : ?>
							<tr><td colspan="5"><?php esc_html_e('No customers yet. Customers are created automatically when bookings are submitted.', 'ridefleet-booking'); ?></td></tr>
						<?php else : ?>
						<?php foreach ($customers as $customer) : ?>
						<?php $initials = strtoupper(mb_substr(trim($customer->first_name ?: $customer->email), 0, 1)); ?>
						<tr>
							<td><div class="rfb-customer-row">
								<div class="rfb-customer-initials"><?php echo esc_html($initials); ?></div>
								<div class="rfb-customer-row-meta">
								<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-customers&customer_id=' . (int) $customer->id)); ?>"><strong><?php echo esc_html(trim($customer->first_name . ' ' . $customer->last_name)); ?></strong></a>
								<span><?php echo esc_html($customer->email); ?></span>
								</div>
							</div></td>
							<td><?php echo esc_html($customer->phone ?: '—'); ?></td>
							<?php $rides = (int) $customer->total_bookings; ?>
							<td><span class="rfb-badge<?php echo $rides > 1 ? ' rfb-badge-confirmed' : ''; ?>"><?php echo esc_html(sprintf(_n('%d ride', '%d rides', $rides, 'ridefleet-booking'), $rides)); ?></span></td>
							<td><strong><?php echo esc_html(number_format_i18n((float) $customer->total_spend, 2)); ?></strong></td>
							<td><?php echo esc_html($customer->last_booking_at ?: '—'); ?></td>
						</tr>
						<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php self::pagination($filtered_total, $per_page, $page_num); ?>
		</div>
		<?php
	}

	private static function render_detail(int $customer_id): void {
		global $wpdb;
		$customer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_customers WHERE id = %d", $customer_id));
		if (!$customer) {
			echo '<div class="wrap"><h1>' . esc_html__('Customer not found', 'ridefleet-booking') . '</h1></div>';
			return;
		}
		$bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rfb_bookings WHERE customer_id = %d ORDER BY pickup_at DESC LIMIT 50", $customer_id));
		$full_name = trim($customer->first_name . ' ' . $customer->last_name);
		$initials = strtoupper(mb_substr($customer->first_name ?: $customer->email, 0, 1));
		$member_since = $customer->created_at ? wp_date(get_option('date_format'), strtotime($customer->created_at)) : '';
		?>
		<div class="wrap rfb-admin">
			<div class="rfb-hero">
				<div>
					<p class="rfb-kicker"><?php esc_html_e('Customer Profile', 'ridefleet-booking'); ?></p>
					<h1><?php echo esc_html($full_name); ?></h1>
					<p><?php esc_html_e('Customer history, lifetime value, and all linked reservations.', 'ridefleet-booking'); ?></p>
				</div>
				<div class="rfb-hero-actions">
					<a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-customers')); ?>"><?php esc_html_e('Back to customers', 'ridefleet-booking'); ?></a>
				</div>
			</div>
			<div class="rfb-stat-grid">
				<?php DashboardPage::stat(__('Bookings', 'ridefleet-booking'), number_format_i18n((int) $customer->total_bookings), __('Lifetime rides.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Total Spend', 'ridefleet-booking'), number_format_i18n((float) $customer->total_spend, 2), __('Customer lifetime value.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Last Booking', 'ridefleet-booking'), esc_html($customer->last_booking_at ?: '-'), __('Most recent ride.', 'ridefleet-booking')); ?>
				<?php DashboardPage::stat(__('Contact', 'ridefleet-booking'), esc_html($customer->phone ?: '-'), esc_html($customer->email ?: '-')); ?>
			</div>
			<div class="rfb-panel rfb-cid-banner">
				<div class="rfb-cid-avatar"><?php echo esc_html($initials); ?></div>
				<div class="rfb-cid-body">
					<h2><?php echo esc_html($full_name ?: __('Unknown Customer', 'ridefleet-booking')); ?></h2>
					<div class="rfb-cid-contacts">
						<?php if ($customer->email) : ?>
							<a class="rfb-contact-pill" href="mailto:<?php echo esc_attr($customer->email); ?>">
								<span class="dashicons dashicons-email-alt"></span><?php echo esc_html($customer->email); ?>
							</a>
						<?php endif; ?>
						<?php if ($customer->phone) : ?>
							<a class="rfb-contact-pill" href="tel:<?php echo esc_attr($customer->phone); ?>">
								<span class="dashicons dashicons-phone"></span><?php echo esc_html($customer->phone); ?>
							</a>
						<?php endif; ?>
						<?php if ($member_since) : ?>
							<span class="rfb-contact-pill rfb-contact-pill-muted">
								<span class="dashicons dashicons-calendar-alt"></span><?php echo esc_html(sprintf(__('Customer since %s', 'ridefleet-booking'), $member_since)); ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
				<div class="rfb-cid-rule">
					<p class="rfb-eyebrow"><?php esc_html_e('Matching rule', 'ridefleet-booking'); ?></p>
					<p><?php esc_html_e('New bookings are matched by email first, then phone. Matching profiles are updated instead of duplicated.', 'ridefleet-booking'); ?></p>
				</div>
			</div>
			<div class="rfb-panel rfb-table-panel"><table class="widefat rfb-bookings-table"><thead><tr><th><?php esc_html_e('Booking', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Pickup', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Status', 'ridefleet-booking'); ?></th><th><?php esc_html_e('Total', 'ridefleet-booking'); ?></th></tr></thead><tbody>
				<?php foreach ($bookings as $booking) : ?><tr><td><a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . (int) $booking->id)); ?>"><?php echo esc_html($booking->booking_number); ?></a></td><td><?php echo esc_html($booking->pickup_at); ?></td><td><span class="rfb-badge <?php echo esc_attr('rfb-badge-' . sanitize_html_class($booking->status)); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', $booking->status))); ?></span></td><td><?php echo esc_html($booking->currency . ' ' . number_format_i18n((float) $booking->total, 2)); ?></td></tr><?php endforeach; ?>
			</tbody></table></div>
		</div>
		<?php
	}

	private static function pagination(int $total_items, int $per_page, int $page_num): void {
		$total_pages = max(1, (int) ceil($total_items / $per_page));
		if ($total_pages <= 1) {
			return;
		}
		$base_args = $_GET;
		unset($base_args['paged']);
		?>
		<div class="rfb-pagination">
			<span><?php echo esc_html(sprintf(__('%1$d customers - Page %2$d of %3$d', 'ridefleet-booking'), $total_items, $page_num, $total_pages)); ?></span>
			<div>
				<?php if ($page_num > 1) : ?>
					<a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $page_num - 1]), admin_url('admin.php'))); ?>"><?php esc_html_e('Previous', 'ridefleet-booking'); ?></a>
				<?php endif; ?>
				<?php if ($page_num < $total_pages) : ?>
					<a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, ['paged' => $page_num + 1]), admin_url('admin.php'))); ?>"><?php esc_html_e('Next', 'ridefleet-booking'); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
