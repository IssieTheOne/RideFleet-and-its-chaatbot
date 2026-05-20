<?php

namespace RideFleetBooking\Admin;

/**
 * Admin dashboard shell.
 *
 * @package RideFleetBooking
 */

if (!defined('ABSPATH')) {
exit;
}

final class DashboardPage {
public static function render(): void {
global $wpdb;

$bookings_table = $wpdb->prefix . 'rfb_bookings';
$customers_table = $wpdb->prefix . 'rfb_customers';
$quote_events_table = $wpdb->prefix . 'rfb_quote_events';

$booking_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table}");
$customer_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$customers_table}");
$quote_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$quote_events_table}");
$revenue = (float) $wpdb->get_var("SELECT COALESCE(SUM(total), 0) FROM {$bookings_table} WHERE payment_status IN ('paid', 'partially_paid')");
$today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$bookings_table} WHERE DATE(pickup_at) = %s", wp_date('Y-m-d')));
$upcoming = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE pickup_at >= NOW() AND status NOT IN ('cancelled', 'failed', 'refunded')");
$pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$bookings_table} WHERE status = 'pending_payment'");
$approval_queue = (int) $wpdb->get_var("SELECT COUNT(DISTINCT b.id) FROM {$bookings_table} b INNER JOIN {$wpdb->prefix}rfb_booking_meta m ON m.booking_id = b.id AND m.meta_key = '_approval_required' AND m.meta_value = '1' WHERE b.status IN ('pending_payment','confirmed')");
$avg_quote = (float) $wpdb->get_var("SELECT COALESCE(AVG(quoted_total), 0) FROM {$quote_events_table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$conversion = $quote_count > 0 ? round(($booking_count / $quote_count) * 100, 1) : 0;
$recent_bookings = $wpdb->get_results("SELECT * FROM {$bookings_table} ORDER BY created_at DESC LIMIT 6");
$top_routes = $wpdb->get_results("SELECT pickup_address, dropoff_address, COUNT(*) AS rides, COALESCE(SUM(total), 0) AS revenue FROM {$bookings_table} GROUP BY pickup_address, dropoff_address ORDER BY rides DESC LIMIT 5");

// Chart data
$revenue_chart_data = self::get_revenue_chart_data();
$status_chart_data = self::get_status_chart_data();
$vehicle_chart_data = self::get_vehicle_utilization_data();
$top_customers = self::get_top_customers();

?>
<div class="wrap rfb-admin">
<?php if (!empty($_GET['rfb_seeded'])) : ?>
<div class="notice notice-success"><p><?php esc_html_e('Demo vehicles, extras, coupon, pricing rule, and booking were created.', 'ridefleet-booking'); ?></p></div>
<?php endif; ?>
<div class="rfb-hero">
<div>
<p class="rfb-kicker"><?php esc_html_e('RideFleet Booking', 'ridefleet-booking'); ?></p>
<h1><?php esc_html_e('Operations Dashboard', 'ridefleet-booking'); ?></h1>
<p><?php esc_html_e('Monitor bookings, recurring clients, quote activity, revenue, and fleet readiness from one place.', 'ridefleet-booking'); ?></p>
</div>
</div>

<div class="rfb-stat-grid">
<?php self::stat(__('Bookings', 'ridefleet-booking'), number_format_i18n($booking_count), __('Total reservations captured by RideFleet.', 'ridefleet-booking')); ?>
<?php self::stat(__('Customers', 'ridefleet-booking'), number_format_i18n($customer_count), __('Client database for repeat riders and spend history.', 'ridefleet-booking')); ?>
<?php self::stat(__('Quote Requests', 'ridefleet-booking'), number_format_i18n($quote_count), __('Analytics-ready quote events before checkout.', 'ridefleet-booking')); ?>
<?php self::stat(__('Paid Revenue', 'ridefleet-booking'), esc_html(number_format_i18n($revenue, 2)), __('Revenue from paid or partially paid bookings.', 'ridefleet-booking')); ?>
</div>

<div class="rfb-ops-grid">
<div class="rfb-panel rfb-dispatch-card">
<h2><?php esc_html_e('Dispatch Pulse', 'ridefleet-booking'); ?></h2>
<div class="rfb-mini-stats">
<?php self::mini(__('Today', 'ridefleet-booking'), $today); ?>
<?php self::mini(__('Upcoming', 'ridefleet-booking'), $upcoming); ?>
<?php self::mini(__('Pending', 'ridefleet-booking'), $pending); ?>
<?php self::mini(__('Approval Queue', 'ridefleet-booking'), $approval_queue); ?>
<?php self::mini(__('Conversion', 'ridefleet-booking'), $conversion . '%'); ?>
</div>
<div class="rfb-progress">
<span style="width: <?php echo esc_attr((string) min(100, $conversion)); ?>%"></span>
</div>
<p><?php esc_html_e('Quote-to-booking conversion will become more useful once real traffic starts hitting the form.', 'ridefleet-booking'); ?></p>
</div>

<div class="rfb-panel">
<h2><?php esc_html_e('Operational Health', 'ridefleet-booking'); ?></h2>
<div class="rfb-mini-stats">
<?php self::mini(__('Avg Quote 30d', 'ridefleet-booking'), number_format_i18n($avg_quote, 2)); ?>
<?php self::mini(__('Paid Revenue', 'ridefleet-booking'), number_format_i18n($revenue, 2)); ?>
<?php self::mini(__('Open Bookings', 'ridefleet-booking'), $upcoming); ?>
<?php self::mini(__('Customers', 'ridefleet-booking'), $customer_count); ?>
</div>
<p><?php esc_html_e('This is your day-to-day operator snapshot. Use Bookings for actions and Calendar for planning.', 'ridefleet-booking'); ?></p>
</div>
</div>

<div class="rfb-charts-grid">
<div class="rfb-panel">
<h2><?php esc_html_e('Revenue Trend (Last 30 Days)', 'ridefleet-booking'); ?></h2>
<canvas id="rfb-revenue-chart" height="80"></canvas>
</div>

<div class="rfb-panel">
<h2><?php esc_html_e('Booking Status Distribution', 'ridefleet-booking'); ?></h2>
<canvas id="rfb-status-chart" height="80"></canvas>
</div>

<div class="rfb-panel">
<h2><?php esc_html_e('Vehicle Utilization', 'ridefleet-booking'); ?></h2>
<canvas id="rfb-vehicle-chart" height="80"></canvas>
</div>

<div class="rfb-panel">
<h2><?php esc_html_e('Top Customers by Spend', 'ridefleet-booking'); ?></h2>
<canvas id="rfb-customers-chart" height="80"></canvas>
</div>
</div>

<div class="rfb-ops-grid">
<div class="rfb-panel">
<h2><?php esc_html_e('Recent Bookings', 'ridefleet-booking'); ?></h2>
<div class="rfb-activity-list">
<?php if (!$recent_bookings) : ?>
<p><?php esc_html_e('No bookings yet. Seed demo data or submit the frontend form.', 'ridefleet-booking'); ?></p>
<?php else : ?>
<?php foreach ($recent_bookings as $booking) : ?>
<a href="<?php echo esc_url(admin_url('admin.php?page=ridefleet-bookings&booking_id=' . (int) $booking->id)); ?>">
<strong><?php echo esc_html($booking->booking_number); ?></strong>
<span><?php echo esc_html($booking->pickup_at ?: $booking->created_at); ?></span>
<em><?php echo esc_html($booking->currency . ' ' . number_format_i18n((float) $booking->total, 2)); ?></em>
</a>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>

<div class="rfb-panel">
<h2><?php esc_html_e('Top Routes', 'ridefleet-booking'); ?></h2>
<div class="rfb-route-list">
<?php if (!$top_routes) : ?>
<p><?php esc_html_e('Routes appear here after bookings are created.', 'ridefleet-booking'); ?></p>
<?php else : ?>
<?php foreach ($top_routes as $route) : ?>
<div>
<strong><?php echo esc_html(wp_trim_words($route->pickup_address, 5) . ' -> ' . wp_trim_words($route->dropoff_address, 5)); ?></strong>
<span><?php echo esc_html(sprintf(__('%1$d rides - %2$s revenue', 'ridefleet-booking'), (int) $route->rides, number_format_i18n((float) $route->revenue, 2))); ?></span>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</div>

<script>
(function() {
if (typeof Chart === 'undefined') {
var script = document.createElement('script');
script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
document.head.appendChild(script);
script.onload = rfb_init_charts;
} else {
rfb_init_charts();
}

function rfb_init_charts() {
setTimeout(function() {
if (window.Chart) {
Chart.defaults.color = '#344054';
Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
Chart.defaults.font.size = 12;
}
var revenueCtx = document.getElementById('rfb-revenue-chart');
if (revenueCtx) {
new Chart(revenueCtx, {
type: 'line',
data: <?php echo wp_json_encode($revenue_chart_data); ?>,
options: { responsive: true, maintainAspectRatio: true, aspectRatio: 2.4, devicePixelRatio: window.devicePixelRatio || 2, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
}

var statusCtx = document.getElementById('rfb-status-chart');
if (statusCtx) {
new Chart(statusCtx, {
type: 'doughnut',
data: <?php echo wp_json_encode($status_chart_data); ?>,
options: { responsive: true, maintainAspectRatio: true, aspectRatio: 2.4, devicePixelRatio: window.devicePixelRatio || 2, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 14 } } } }
});
}

var vehicleCtx = document.getElementById('rfb-vehicle-chart');
if (vehicleCtx) {
new Chart(vehicleCtx, {
type: 'bar',
data: <?php echo wp_json_encode($vehicle_chart_data); ?>,
options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, aspectRatio: 2.4, devicePixelRatio: window.devicePixelRatio || 2, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 } } } }
});
}

var customersCtx = document.getElementById('rfb-customers-chart');
if (customersCtx) {
new Chart(customersCtx, {
type: 'bar',
data: <?php echo wp_json_encode($top_customers); ?>,
options: { indexAxis: 'y', responsive: true, maintainAspectRatio: true, aspectRatio: 2.4, devicePixelRatio: window.devicePixelRatio || 2, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
});
}
}, 100);
}
})();
</script>
</div>
<?php
}

private static function get_revenue_chart_data(): array {
global $wpdb;
$bookings_table = $wpdb->prefix . 'rfb_bookings';

$data = $wpdb->get_results(
"SELECT DATE(created_at) as date, COALESCE(SUM(total), 0) as revenue 
 FROM {$bookings_table} 
 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
 GROUP BY DATE(created_at)
 ORDER BY date ASC"
);

$labels = [];
$amounts = [];
foreach ($data as $row) {
$labels[] = wp_date('M d', strtotime($row->date));
$amounts[] = (float) $row->revenue;
}

return [
'labels' => $labels,
'datasets' => [[
'label' => __('Revenue', 'ridefleet-booking'),
'data' => $amounts,
'borderColor' => '#0f766e',
'backgroundColor' => 'rgba(15, 118, 110, 0.1)',
'fill' => true,
'tension' => 0.4,
]],
];
}

private static function get_status_chart_data(): array {
global $wpdb;
$bookings_table = $wpdb->prefix . 'rfb_bookings';

$data = $wpdb->get_results(
"SELECT status, COUNT(*) as count FROM {$bookings_table} GROUP BY status"
);

$colors = [
'pending_payment' => '#f59e0b',
'confirmed' => '#3b82f6',
'completed' => '#10b981',
'cancelled' => '#ef4444',
'failed' => '#dc2626',
'refunded' => '#8b5cf6',
];

$labels = [];
$counts = [];
$bgColors = [];

foreach ($data as $row) {
$labels[] = ucfirst(str_replace('_', ' ', $row->status));
$counts[] = (int) $row->count;
$bgColors[] = $colors[$row->status] ?? '#6b7280';
}

return [
'labels' => $labels,
'datasets' => [[
'data' => $counts,
'backgroundColor' => $bgColors,
]],
];
}

private static function get_vehicle_utilization_data(): array {
global $wpdb;
$bookings_table = $wpdb->prefix . 'rfb_bookings';

$data = $wpdb->get_results(
"SELECT 
COALESCE((SELECT post_title FROM {$wpdb->posts} WHERE ID = {$bookings_table}.vehicle_id), 'Not Assigned') as vehicle,
COUNT(*) as rides
 FROM {$bookings_table}
 WHERE vehicle_id IS NOT NULL
 GROUP BY vehicle_id
 ORDER BY rides DESC
 LIMIT 10"
);

$labels = [];
$rides = [];

foreach ($data as $row) {
$labels[] = $row->vehicle;
$rides[] = (int) $row->rides;
}

return [
'labels' => $labels,
'datasets' => [[
'label' => __('Rides', 'ridefleet-booking'),
'data' => $rides,
'backgroundColor' => '#7dd3c7',
]],
];
}

private static function get_top_customers(): array {
global $wpdb;
$customers_table = $wpdb->prefix . 'rfb_customers';

$data = $wpdb->get_results(
"SELECT CONCAT(first_name, ' ', last_name) as name, total_spend as spend
 FROM {$customers_table}
 WHERE total_spend > 0
 ORDER BY total_spend DESC
 LIMIT 10"
);

$labels = [];
$amounts = [];

foreach ($data as $row) {
$labels[] = $row->name ?: __('Anonymous', 'ridefleet-booking');
$amounts[] = (float) $row->spend;
}

return [
'labels' => $labels,
'datasets' => [[
'label' => __('Total Spend', 'ridefleet-booking'),
'data' => $amounts,
'backgroundColor' => '#f5c518',
]],
];
}

public static function stat(string $label, string $value, string $description): void {
?>
<div class="rfb-stat">
<span><?php echo esc_html($label); ?></span>
<strong><?php echo esc_html($value); ?></strong>
<p><?php echo esc_html($description); ?></p>
</div>
<?php
}

public static function mini(string $label, string|int|float $value): void {
?>
<div>
<strong><?php echo esc_html((string) $value); ?></strong>
<span><?php echo esc_html($label); ?></span>
</div>
<?php
}
}
